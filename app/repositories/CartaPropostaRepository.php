<?php

/**
 * Acesso a dados da Carta Proposta (Fase 1 da formalização da contratação — migration
 * 2026-11-06-cartas-propostas.sql). Cada candidatura pode ter várias versões; nenhuma regra de
 * negócio aqui — só leitura/escrita com as transições de `status` sempre guardadas por WHERE
 * (nunca confia que o chamador verificou o estado antes, mesma disciplina de
 * EntrevistaDesligamentoRepository::responder()/cancelar()).
 */
class CartaPropostaRepository
{
    private static bool $ensured = false;
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }
        self::ensureSchema($this->pdo);
        return $this->pdo;
    }

    public function proximaVersao(int $candidaturaId): int
    {
        $stmt = $this->connection()->prepare('SELECT COALESCE(MAX(versao), 0) + 1 FROM cartas_propostas WHERE candidatura_id = ?');
        $stmt->execute([$candidaturaId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array{candidatura_id:int,versao:int,nome_candidato:string,cargo:string,setor:?string,
     *   empresa_contratante:string,salario_proposto:float,jornada_trabalho:string,escala_horario:?string,
     *   beneficios_texto:?string,local_trabalho:?string,previsao_inicio:?string,validade_proposta:string,
     *   observacoes:?string,gerada_por_usuario_id:int} $dados
     */
    public function criar(array $dados): int
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO cartas_propostas (
                candidatura_id, versao, status, nome_candidato, cargo, setor, empresa_contratante,
                salario_proposto, jornada_trabalho, escala_horario, beneficios_texto, local_trabalho,
                previsao_inicio, validade_proposta, observacoes, gerada_por_usuario_id
            ) VALUES (?, ?, \'rascunho\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dados['candidatura_id'], $dados['versao'], $dados['nome_candidato'], $dados['cargo'],
            $dados['setor'], $dados['empresa_contratante'], $dados['salario_proposto'], $dados['jornada_trabalho'],
            $dados['escala_horario'], $dados['beneficios_texto'], $dados['local_trabalho'],
            $dados['previsao_inicio'], $dados['validade_proposta'], $dados['observacoes'], $dados['gerada_por_usuario_id'],
        ]);
        return (int)$this->connection()->lastInsertId();
    }

    /** Só atualiza enquanto `rascunho` — edição livre, sem gerar versão nova a cada salvamento. */
    public function atualizarRascunho(int $id, array $dados): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET
                nome_candidato = ?, cargo = ?, setor = ?, empresa_contratante = ?, salario_proposto = ?,
                jornada_trabalho = ?, escala_horario = ?, beneficios_texto = ?, local_trabalho = ?,
                previsao_inicio = ?, validade_proposta = ?, observacoes = ?
             WHERE id = ? AND status = 'rascunho'"
        );
        $stmt->execute([
            $dados['nome_candidato'], $dados['cargo'], $dados['setor'], $dados['empresa_contratante'],
            $dados['salario_proposto'], $dados['jornada_trabalho'], $dados['escala_horario'],
            $dados['beneficios_texto'], $dados['local_trabalho'], $dados['previsao_inicio'],
            $dados['validade_proposta'], $dados['observacoes'], $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT cp.*, u1.nome AS gerada_por_nome, u2.nome AS enviada_por_nome
             FROM cartas_propostas cp
             LEFT JOIN usuarios u1 ON u1.id = cp.gerada_por_usuario_id
             LEFT JOIN usuarios u2 ON u2.id = cp.enviada_por_usuario_id
             WHERE cp.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Histórico completo de versões da candidatura, mais recente primeiro. */
    public function listarPorCandidatura(int $candidaturaId): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT cp.*, u1.nome AS gerada_por_nome, u2.nome AS enviada_por_nome
             FROM cartas_propostas cp
             LEFT JOIN usuarios u1 ON u1.id = cp.gerada_por_usuario_id
             LEFT JOIN usuarios u2 ON u2.id = cp.enviada_por_usuario_id
             WHERE cp.candidatura_id = ?
             ORDER BY cp.versao DESC'
        );
        $stmt->execute([$candidaturaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Versão mais recente (qualquer status) — para saber se já existe rascunho/versão ativa antes de criar outra. */
    public function buscarUltimaPorCandidatura(int $candidaturaId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM cartas_propostas WHERE candidatura_id = ? ORDER BY versao DESC LIMIT 1'
        );
        $stmt->execute([$candidaturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Gate do Agendamento do Exame Admissional: existe ALGUMA versão aceita, mesmo que depois
     * tenha sido criada uma versão mais nova (correção pontual pós-aceite nunca desfaz o aceite já
     * ocorrido). Nunca usar buscarUltimaPorCandidatura() para esta checagem. */
    public function existeVersaoAceita(int $candidaturaId): bool
    {
        $stmt = $this->connection()->prepare(
            "SELECT 1 FROM cartas_propostas WHERE candidatura_id = ? AND status = 'aceita' LIMIT 1"
        );
        $stmt->execute([$candidaturaId]);
        return $stmt->fetchColumn() !== false;
    }

    /** rascunho -> gerada: congela o snapshot (nenhuma coluna de dados é alterada aqui, só o PDF e o status). */
    public function marcarGerada(int $id, string $pdfNomeArmazenado, string $agora): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET status = 'gerada', pdf_nome_armazenado = ?, gerada_em = ?
             WHERE id = ? AND status = 'rascunho'"
        );
        $stmt->execute([$pdfNomeArmazenado, $agora, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * "Preparar mensagem" (ainda NÃO é envio confirmado — ver migration): gera o token agora (a
     * mensagem preparada para o RH copiar já precisa trazer o link real) e vincula a Comunicacao
     * criada. Só grava enquanto `gerada` e sem token ainda — preparar de novo (reenviar o mesmo
     * texto) não gera um segundo token.
     */
    public function definirTokenEComunicacao(int $id, string $tokenHash, string $tokenExpiraEm, int $comunicacaoId): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET token_hash = ?, token_expira_em = ?, comunicacao_id = ?
             WHERE id = ? AND status = 'gerada' AND token_hash IS NULL"
        );
        $stmt->execute([$tokenHash, $tokenExpiraEm, $comunicacaoId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * "Confirmar envio" (ação explícita e SEPARADA de preparar a mensagem — nunca automática): só
     * a partir daqui a página pública aceita o token desta versão. Exige que o token já exista
     * (preparado antes) — nunca confirma envio de uma proposta sem link gerado.
     */
    public function confirmarEnvio(int $id, int $usuarioId, string $agora): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET status = 'enviada', enviada_por_usuario_id = ?, enviada_em = ?
             WHERE id = ? AND status = 'gerada' AND token_hash IS NOT NULL"
        );
        $stmt->execute([$usuarioId, $agora, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Página pública: só o necessário (contexto mínimo + estado). Nunca dados de outra candidatura. */
    public function buscarPorTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, candidatura_id, versao, status, token_expira_em, validade_proposta, nome_candidato,
                    cargo, setor, empresa_contratante, salario_proposto, jornada_trabalho, escala_horario,
                    beneficios_texto, local_trabalho, previsao_inicio, observacoes, pdf_nome_armazenado
             FROM cartas_propostas WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * enviada -> aceita|recusada. UPDATE condicional (WHERE status = 'enviada') + rowCount: duas
     * submissões simultâneas (ou um reenvio do mesmo formulário) nunca duplicam a decisão — mesma
     * garantia de EntrevistaDesligamentoRepository::responder().
     */
    public function registrarDecisao(int $id, string $decisao, string $agora, ?string $ip, ?string $userAgent, ?string $motivoRecusa): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET status = ?, decisao_em = ?, decisao_ip = ?, decisao_user_agent = ?, motivo_recusa = ?
             WHERE id = ? AND status = 'enviada'"
        );
        $stmt->execute([$decisao, $agora, $ip, $userAgent, $motivoRecusa, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Uma nova versão foi gerada antes desta ser decidida — invalida o token desta (nunca é apagada). */
    public function marcarSubstituida(int $id): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE cartas_propostas SET status = 'substituida' WHERE id = ? AND status IN ('rascunho','gerada','enviada')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-11-06-cartas-propostas.sql ainda não
     * rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7). Mesmo padrão de
     * CandidaturaDocumento::ensureSchema().
     */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        // Dependência de FK: garante que `comunicacoes` já existe antes de criar esta tabela.
        Comunicacao::ensureSchema();

        $pdo->exec("CREATE TABLE IF NOT EXISTS cartas_propostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidatura_id INT NOT NULL,
            versao INT NOT NULL,
            status ENUM('rascunho','gerada','enviada','aceita','recusada','substituida') NOT NULL DEFAULT 'rascunho',
            nome_candidato VARCHAR(150) NOT NULL,
            cargo VARCHAR(150) NOT NULL,
            setor VARCHAR(150) NULL,
            empresa_contratante VARCHAR(150) NOT NULL,
            salario_proposto DECIMAL(12,2) NOT NULL,
            jornada_trabalho VARCHAR(80) NOT NULL,
            escala_horario VARCHAR(120) NULL,
            beneficios_texto TEXT NULL,
            local_trabalho VARCHAR(150) NULL,
            previsao_inicio DATE NULL,
            validade_proposta DATE NOT NULL,
            observacoes TEXT NULL,
            pdf_nome_armazenado VARCHAR(64) NULL,
            token_hash CHAR(64) NULL,
            token_expira_em DATETIME NULL,
            comunicacao_id INT NULL,
            gerada_em DATETIME NULL,
            gerada_por_usuario_id INT NOT NULL,
            enviada_em DATETIME NULL,
            enviada_por_usuario_id INT NULL,
            decisao_em DATETIME NULL,
            decisao_ip VARCHAR(45) NULL,
            decisao_user_agent VARCHAR(255) NULL,
            motivo_recusa TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cartas_propostas_candidatura_versao (candidatura_id, versao),
            UNIQUE KEY uk_cartas_propostas_token_hash (token_hash),
            KEY idx_cartas_propostas_candidatura (candidatura_id),
            KEY idx_cartas_propostas_status (status),
            CONSTRAINT fk_cartas_propostas_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
            CONSTRAINT fk_cartas_propostas_usuario_gerou FOREIGN KEY (gerada_por_usuario_id) REFERENCES usuarios(id),
            CONSTRAINT fk_cartas_propostas_usuario_enviou FOREIGN KEY (enviada_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_cartas_propostas_comunicacao FOREIGN KEY (comunicacao_id) REFERENCES comunicacoes(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
