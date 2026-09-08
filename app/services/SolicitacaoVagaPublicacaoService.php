<?php
/**
 * Origina automaticamente a Vaga pública a partir de uma Solicitação de Vaga APROVADA e trata a
 * publicação do rascunho pelo RH.
 *
 * Regra de disparo (determinística): quando `solicitacoes_vaga.status_fluxo` chega a `aprovada`
 * (todas as aprovações obrigatórias concluídas — ver SolicitacaoVaga::approve), gera-se a vaga em
 * RASCUNHO (`vagas.ativo = 0`, invisível ao público). O RH revisa os campos editoriais e publica
 * em um clique (`ativo = 1`, `publicada_em = NOW()`), quando a vaga passa a aparecer no site.
 *
 * Idempotência: `vagas.solicitacao_vaga_id` é UNIQUE. Rodar o disparo/gerar duas vezes nunca cria
 * uma segunda vaga — retorna a existente.
 *
 * Conteúdo público: nasce SEMENTE a partir da solicitação (nunca expõe solicitante, aprovações,
 * salário, observações internas — só o que o RH revisa e libera). É editável antes de publicar.
 */
class SolicitacaoVagaPublicacaoService
{
    private const ESCOLARIDADE_LABEL = [
        'fundamental' => 'Ensino fundamental',
        'medio' => 'Ensino médio',
        'tecnico' => 'Ensino técnico',
        'superior_incompleto' => 'Superior incompleto',
        'superior_completo' => 'Superior completo',
        'pos_graduacao' => 'Pós-graduação',
    ];
    private const NIVEL_LABEL = [
        'operacional' => 'Operacional',
        'tecnico' => 'Técnico',
        'analitico' => 'Analítico',
        'estrategico' => 'Estratégico',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    /**
     * Gera (ou retorna) o rascunho da vaga de uma solicitação aprovada. Best-effort quando
     * chamado do fluxo de aprovação — nunca deve derrubar a aprovação.
     *
     * @return array{ok:bool, vaga_id?:int, ja_existia?:bool, error?:string}
     */
    public function gerarRascunho(int $solicitacaoId, ?int $actorUserId = null, ?string $ip = null): array
    {
        $existente = $this->vagaDaSolicitacao($solicitacaoId);
        if ($existente !== null) {
            return ['ok' => true, 'vaga_id' => (int)$existente['id'], 'ja_existia' => true];
        }

        $solicitacao = $this->carregarSolicitacao($solicitacaoId);
        if ($solicitacao === null) {
            return ['ok' => false, 'error' => 'Solicitação de vaga não encontrada.'];
        }
        if ((string)$solicitacao['status_fluxo'] !== SolicitacaoVaga::STATUS_APROVADA) {
            return ['ok' => false, 'error' => 'A vaga só é gerada quando a solicitação está aprovada.'];
        }

        $dados = $this->montarDadosVaga($solicitacao);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO vagas (solicitacao_vaga_id, titulo, descricao, requisitos, area, local, empresa_id, ativo, publicada_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)'
            );
            $stmt->execute([
                $solicitacaoId,
                $dados['titulo'],
                $dados['descricao'],
                $dados['requisitos'],
                $dados['area'],
                $dados['local'],
                $dados['empresa_id'],
            ]);
            $vagaId = (int)$this->pdo->lastInsertId();

            SolicitacaoVaga::registrarEventoAuditoria(
                $solicitacaoId,
                $actorUserId,
                'vaga_rascunho_gerada',
                'vaga_id',
                null,
                (string)$vagaId,
                $ip
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Corrida: outra requisição criou a vaga entre a checagem e o INSERT (UNIQUE barrou).
            $agora = $this->vagaDaSolicitacao($solicitacaoId);
            if ($agora !== null) {
                return ['ok' => true, 'vaga_id' => (int)$agora['id'], 'ja_existia' => true];
            }
            Logger::error('Falha ao gerar rascunho de vaga da solicitação', [
                'solicitacao_id' => $solicitacaoId,
                'erro' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'Falha ao gerar o rascunho da vaga.'];
        }

        return ['ok' => true, 'vaga_id' => $vagaId, 'ja_existia' => false];
    }

    /**
     * Publica um rascunho: `ativo = 1`, `publicada_em` carimbado. Idempotente. Só publica vaga
     * originada de solicitação (o cadastro manual de exceção usa o formulário de vaga normal).
     *
     * @return array{ok:bool, error?:string, ja_publicada?:bool}
     */
    public function publicar(int $vagaId, ?int $actorUserId = null, ?string $ip = null): array
    {
        $stmt = $this->pdo->prepare('SELECT id, solicitacao_vaga_id, ativo FROM vagas WHERE id = ? LIMIT 1');
        $stmt->execute([$vagaId]);
        $vaga = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vaga) {
            return ['ok' => false, 'error' => 'Vaga não encontrada.'];
        }
        if ((int)$vaga['ativo'] === 1) {
            return ['ok' => true, 'ja_publicada' => true];
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE vagas SET ativo = 1, publicada_em = COALESCE(publicada_em, NOW()) WHERE id = ?')
                ->execute([$vagaId]);

            if ($vaga['solicitacao_vaga_id'] !== null) {
                SolicitacaoVaga::registrarEventoAuditoria(
                    (int)$vaga['solicitacao_vaga_id'],
                    $actorUserId,
                    'vaga_publicada',
                    'vaga_id',
                    null,
                    (string)$vagaId,
                    $ip
                );
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('Falha ao publicar vaga', ['vaga_id' => $vagaId, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Falha ao publicar a vaga.'];
        }

        return ['ok' => true];
    }

    private function vagaDaSolicitacao(int $solicitacaoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, ativo, publicada_em FROM vagas WHERE solicitacao_vaga_id = ? LIMIT 1');
        $stmt->execute([$solicitacaoId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function carregarSolicitacao(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, cg.nome AS cargo_nome, se.nome AS setor_nome, se.empresa_id AS setor_empresa_id,
                    e.nome AS empresa_nome
             FROM solicitacoes_vaga s
             INNER JOIN cargos cg ON cg.id = s.cargo_id
             INNER JOIN setores se ON se.id = s.setor_id
             LEFT JOIN empresas e ON e.id = se.empresa_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Mapeamento determinístico Solicitação -> campos da vaga. Tudo aqui é RASCUNHO editável.
     * `descricao` e `requisitos` nascem como semente a partir da solicitação; o RH revisa antes
     * de publicar.
     *
     * @return array{titulo:string, descricao:string, requisitos:string, area:string, local:string, empresa_id:?int}
     */
    private function montarDadosVaga(array $s): array
    {
        $cargoNome = trim((string)$s['cargo_nome']);
        $setorNome = trim((string)$s['setor_nome']);
        $empresaNome = trim((string)($s['empresa_nome'] ?? ''));

        $entregas = trim((string)(Cipher::decrypt($s['entregas_esperadas_encrypted'] ?? null) ?? ''));
        $experiencia = trim((string)(Cipher::decrypt($s['experiencia_necessaria_encrypted'] ?? null) ?? ''));
        $formacao = trim((string)(Cipher::decrypt($s['formacao_academica_encrypted'] ?? null) ?? ''));

        $escolaridade = self::ESCOLARIDADE_LABEL[(string)$s['escolaridade_minima']] ?? (string)$s['escolaridade_minima'];
        $nivel = self::NIVEL_LABEL[(string)$s['nivel_responsabilidade']] ?? (string)$s['nivel_responsabilidade'];

        $requisitos = "Escolaridade mínima: {$escolaridade}.\n";
        $requisitos .= "Nível de responsabilidade: {$nivel}.\n";
        if ($formacao !== '') {
            $requisitos .= "Formação: {$formacao}\n";
        }
        if ($experiencia !== '') {
            $requisitos .= "Experiência: {$experiencia}\n";
        }

        return [
            'titulo' => mb_substr($cargoNome !== '' ? $cargoNome : 'Vaga', 0, 150),
            'descricao' => $entregas !== '' ? $entregas : 'A descrever pelo RH antes da publicação.',
            'requisitos' => trim($requisitos),
            'area' => mb_substr($setorNome, 0, 100),
            'local' => mb_substr($empresaNome !== '' ? $empresaNome : $setorNome, 0, 100),
            'empresa_id' => $s['setor_empresa_id'] !== null ? (int)$s['setor_empresa_id'] : null,
        ];
    }
}
