<?php

/**
 * Etapa 3.2B.3 — MOVIMENTAÇÃO de candidatura no Pipeline com autorização por perfil, permissão efetiva, setor da vaga e transição.
 *
 * ESTE SERVIÇO NÃO É CHAMADO POR NENHUM CONTROLLER nesta etapa (`AdminPipelineController::move` segue `requireRole(['admin','rh'])`).
 * É a camada pronta para a 3.2C: o controller deverá apenas resolver o usuário da sessão, validar CSRF/JSON e chamar `mover()`.
 *
 * Condições (todas, nesta ordem; nenhuma é presumida pelo frontend — o ator, o perfil, o escopo, a vaga e as etapas vêm do BANCO):
 *   1. usuário existe e o perfil é conhecido (admin/rh = fluxo global atual, SEM mudança; gestor = fluxo setorial; qualquer outro = negado);
 *   2. permissão INDIVIDUAL EFETIVA `pipeline.movimentar` (Authorization::usuarioTemPermissao → PermissaoPerfilPolicy + atribuição);
 *      leitura (`pipeline.visualizar`) nunca autoriza mover;
 *   3. escopo nominal (CandidaturaAcessoSetorialService::escopoNominal: falha = nenhum, viewer = nenhum) e candidatura cuja VAGA tem
 *      `vagas.setor_id` nos Setores Gerenciados (vaga sem setor = negado). Fora do escopo = "não encontrada" (não revela existência);
 *   4. transição permitida ao Gestor (origem E destino na lista operacional; transições com efeito privilegiado são exclusivas do RH);
 *   5. etapa atual esperada obrigatória (idempotência/concorrência: repetição ou etapa alterada por outro = conflito).
 * As condições 1–4 são REAVALIADAS dentro da transação, depois do lock da candidatura e da vaga (`FOR UPDATE` na junção), antes de qualquer
 * escrita: troca de setor da vaga ou perda do setor gerenciado entre a checagem e a gravação cancelam a operação sem gravar nada.
 * Metadados do Gestor são filtrados por lista branca (nunca `observacoes`/`confirm`/`admission_*`).
 */
final class PipelineMovimentacaoSetorialService
{
    public const PERMISSAO = 'pipeline.movimentar';

    /**
     * Transições OPERACIONAIS do Gestor: origem e destino precisam estar aqui. Decisão conservadora (a ser aprovada pelo negócio): o Gestor
     * alterna apenas entre as etapas que ele conduz. Aprovado, Admissão (efeitos de indicação/admissão), Reprovado (motivo interno e
     * observações reservadas), Banco de Talentos, Nova Inscrição, Triagem RH e Entrevista RH permanecem EXCLUSIVAS do RH.
     */
    public const ETAPAS_OPERACIONAIS_GESTOR = ['entrevista-gestor', 'testes'];

    /** Metadados de etapa que o Gestor pode informar (agendamento/teste). Observações, confirmação e dados de admissão são do RH. */
    private const METADADOS_GESTOR = ['interview_date', 'interview_time', 'interview_location', 'interview_link', 'test_name', 'deadline'];

    /** @var callable */
    private $permissao;
    /** @var callable|null */
    private $aposVerificacaoInicial;
    private RecruitmentPipelineService $pipeline;

    /**
     * @param callable|null $permissao function(int $usuarioId, string $codigo): bool — padrão Authorization::usuarioTemPermissao. Existe para
     *        testar a camada de escopo/transição isoladamente da política que hoje bloqueia o Gestor; os controllers usam o padrão.
     * @param callable|null $aposVerificacaoInicial SOMENTE teste: executado entre a checagem inicial e a transação, para simular alterações
     *        concorrentes (troca de setor da vaga, perda de setor). Nunca fornecido em produção.
     */
    public function __construct(?callable $permissao = null, ?callable $aposVerificacaoInicial = null, ?RecruitmentPipelineService $pipeline = null)
    {
        $this->permissao = $permissao ?? static fn(int $u, string $c): bool => Authorization::usuarioTemPermissao($u, $c);
        $this->aposVerificacaoInicial = $aposVerificacaoInicial;
        $this->pipeline = $pipeline ?? new RecruitmentPipelineService();
    }

    /** O Gestor pode realizar esta transição? (origem e destino operacionais; origem desconhecida/sem etapa = não). */
    public static function transicaoPermitidaAoGestor(?string $slugOrigem, string $slugDestino): bool
    {
        return $slugOrigem !== null
            && in_array($slugOrigem, self::ETAPAS_OPERACIONAIS_GESTOR, true)
            && in_array($slugDestino, self::ETAPAS_OPERACIONAIS_GESTOR, true);
    }

    /**
     * @param array $metadata dados da etapa de destino enviados pelo cliente (filtrados para o Gestor)
     * @param int|null $etapaEsperadaId etapa atual que o usuário viu (obrigatória para Gestor)
     * @return array{ok:bool,error?:string,message?:string,missing_fields?:string[]} error: forbidden | not_found | transition | validation | conflict | exception
     */
    public function mover(int $usuarioId, int $candidaturaId, int $etapaDestinoId, array $metadata = [], ?int $etapaEsperadaId = null): array
    {
        $ator = $this->ator($usuarioId);
        if ($ator === null || $candidaturaId <= 0 || $etapaDestinoId <= 0) {
            return $this->negar('forbidden', 'Operação não autorizada.');
        }

        // Fluxo global atual (Admin/RH): inalterado — mesma chamada que Candidatura::updateStage fazia, sem escopo nem lista de transições.
        if (in_array($ator['role'], ['admin', 'rh'], true)) {
            return $this->pipeline->moveCandidateToStage($candidaturaId, $etapaDestinoId, $usuarioId, $metadata, $etapaEsperadaId !== null, $etapaEsperadaId);
        }
        if ($ator['role'] !== 'gestor') {
            return $this->negar('forbidden', 'Operação não autorizada.');
        }

        // ---- Gestor: verificação inicial (barata; sem escrita) ----
        $negado = $this->verificarGestor($usuarioId, $candidaturaId, $etapaDestinoId, null, null);
        if ($negado !== null) {
            return $negado;
        }
        if ($etapaEsperadaId === null || $etapaEsperadaId < 0) {
            return $this->negar('validation', 'Etapa atual do candidato é obrigatória. Recarregue a página.');
        }
        $metadata = array_intersect_key($metadata, array_flip(self::METADADOS_GESTOR));

        if ($this->aposVerificacaoInicial !== null) {
            ($this->aposVerificacaoInicial)();
        }

        $guarda = function (PDO $pdo, ?string $slugOrigem, string $slugDestino) use ($usuarioId, $candidaturaId, $etapaDestinoId): ?array {
            return $this->verificarGestor($usuarioId, $candidaturaId, $etapaDestinoId, $slugOrigem, $slugDestino, true);
        };
        return $this->pipeline->moveCandidateToStage($candidaturaId, $etapaDestinoId, $usuarioId, $metadata, true, $etapaEsperadaId, $guarda);
    }

    /**
     * Reavaliação completa do Gestor. `$sobLock` = dentro da transação: trava candidatura+vaga (FOR UPDATE na junção) enquanto confere o escopo.
     * Retorna null = autorizado, ou o array de erro.
     */
    private function verificarGestor(int $usuarioId, int $candidaturaId, int $etapaDestinoId, ?string $slugOrigem, ?string $slugDestino, bool $sobLock = false): ?array
    {
        $ator = $this->ator($usuarioId);
        if ($ator === null || $ator['role'] !== 'gestor') {
            return $this->negar('forbidden', 'Operação não autorizada.');
        }
        if (!($this->permissao)($usuarioId, self::PERMISSAO)) {
            return $this->negar('forbidden', 'Operação não autorizada.');
        }
        $escopo = CandidaturaAcessoSetorialService::escopoNominal(['id' => $usuarioId, 'role' => 'gestor']);
        if (!$escopo->enxergaAlgo() || !VagaSetorService::schemaSuportaSetor()) {
            return $this->negar('not_found', 'Candidatura não encontrada.');
        }
        $params = [$candidaturaId];
        $cond = $escopo->sqlSetorId('v.setor_id', $params);
        $stmt = Database::conn()->prepare('SELECT c.id FROM candidaturas c INNER JOIN vagas v ON v.id = c.vaga_id WHERE c.id = ? AND ' . $cond . ' LIMIT 1' . ($sobLock ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        if ($stmt->fetchColumn() === false) {
            return $this->negar('not_found', 'Candidatura não encontrada.'); // inexistente = fora do escopo = vaga sem setor
        }

        if ($slugOrigem === null && $slugDestino === null) {
            // Verificação inicial: resolve as etapas pelo banco (nunca por nome/slug enviado).
            $c = Database::conn()->prepare('SELECT s.slug FROM candidaturas c LEFT JOIN pipeline_stages s ON s.id = c.stage_id WHERE c.id = ?');
            $c->execute([$candidaturaId]);
            $slugOrigem = $c->fetchColumn() ?: null;
            $d = Database::conn()->prepare('SELECT slug FROM pipeline_stages WHERE id = ?');
            $d->execute([$etapaDestinoId]);
            $slugDestino = $d->fetchColumn() ?: null;
            if ($slugDestino === null) {
                return $this->negar('not_found', 'Etapa de destino não encontrada.');
            }
        }
        if (!self::transicaoPermitidaAoGestor($slugOrigem === null ? null : (string)$slugOrigem, (string)$slugDestino)) {
            return $this->negar('transition', 'Esta movimentação é exclusiva do RH.');
        }
        return null;
    }

    /** @return array{id:int,role:string}|null perfil lido do BANCO (nunca da sessão/POST). */
    private function ator(int $usuarioId): ?array
    {
        if ($usuarioId <= 0) {
            return null;
        }
        $stmt = Database::conn()->prepare('SELECT id, role FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'role' => strtolower(trim((string)$r['role']))] : null;
    }

    /** @return array{ok:false,error:string,message:string} */
    private function negar(string $erro, string $mensagem): array
    {
        return ['ok' => false, 'error' => $erro, 'message' => $mensagem];
    }
}
