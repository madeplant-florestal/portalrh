<?php

/**
 * Acesso a dados da Entrevista de Desligamento. Contratos vêm EXCLUSIVAMENTE do espelho oficial
 * `colaboradores_metadados` (unidade = CONTRATO; vínculo por `metadados_id`) e, para o nome do Cargo,
 * do catálogo oficial `cargos` — nunca da tabela legada `colaboradores`. Nunca escreve no METADADOS,
 * nunca seleciona CPF, nascimento nem salário.
 *
 * Sem regra de negócio: elegibilidade, prazos e validação vivem em EntrevistaDesligamentoService (que
 * repassa aqui os critérios já decididos). Tempo/agora sempre entram por parâmetro (nunca NOW()), para o
 * comportamento ser idêntico em produção e nos testes.
 */
class EntrevistaDesligamentoRepository
{
    /** Colunas de resposta gravadas em responder() — whitelist (nome de coluna nunca vem de input). */
    public const COLUNAS_RESPOSTA = [
        'motivo_principal', 'motivo_descricao',
        'exp_remuneracao', 'exp_beneficios', 'exp_condicoes_trabalho', 'exp_comunicacao',
        'exp_desenvolvimento', 'exp_reconhecimento', 'exp_clima',
        'lid_respeito', 'lid_comunicacao', 'lid_abertura', 'lid_desenvolvimento', 'lid_justica',
        'cul_respeito', 'cul_honestidade', 'cul_lealdade', 'cul_etica', 'cul_coragem', 'cul_ousadia',
        'int_compreender', 'int_treinamento', 'int_expectativa',
        'experiencia_geral', 'enps',
        'aberta_continuar', 'aberta_melhorar', 'aberta_mensagem',
    ];

    private const COLUNAS_SNAPSHOT = [
        'snap_nome', 'snap_codigo_empresa', 'snap_empresa', 'snap_codigo_unidade', 'snap_unidade',
        'snap_codigo_cargo', 'snap_cargo', 'snap_admissao', 'snap_demissao',
        'snap_motivo_codigo', 'snap_motivo_descricao',
    ];

    /** Colunas operacionais (sem respostas) usadas nas listagens. */
    private const SELECT_OPERACIONAL = 'e.id, e.metadados_id, e.gerada_em, e.gerada_por_usuario_id, e.regeneracoes, e.expira_em,
        e.cancelada_em, e.respondida_em, e.snap_nome, e.snap_codigo_empresa, e.snap_empresa, e.snap_unidade,
        e.snap_cargo, e.snap_admissao, e.snap_demissao, e.snap_motivo_codigo, e.snap_motivo_descricao,
        cm.demissao AS atual_demissao, cm.motivo_rescisao_codigo AS atual_motivo_codigo,
        u.nome AS gerada_por_nome';

    private const FROM_OPERACIONAL = 'FROM entrevistas_desligamento e
        INNER JOIN colaboradores_metadados cm ON cm.id = e.metadados_id
        LEFT JOIN usuarios u ON u.id = e.gerada_por_usuario_id';

    /** Contrato + Cargo pelo catálogo oficial `cargos` (fallback: texto do espelho). */
    private const SELECT_CONTRATO = "SELECT cm.id AS metadados_id, cm.nome, cm.codigo_empresa, cm.empresa, cm.codigo_unidade, cm.unidade,
            cm.codigo_cargo,
            COALESCE(
              (SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci
                LIMIT 1),
              NULLIF(cm.cargo, '')
            ) AS cargo,
            cm.admissao, cm.demissao, cm.motivo_rescisao_codigo, cm.motivo_rescisao_descricao
        FROM colaboradores_metadados cm";

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
        return $this->pdo;
    }

    // ------------------------------------------------------------------ contratos oficiais

    public function buscarContrato(int $metadadosId): ?array
    {
        $stmt = $this->connection()->prepare(self::SELECT_CONTRATO . ' WHERE cm.id = ? LIMIT 1');
        $stmt->execute([$metadadosId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Contratos elegíveis SEM entrevista, mais recentes primeiro.
     *
     * @param array $criterios hoje (Y-m-d) e motivo_excluido (código oficial fora da população).
     * @param array $filtros   busca (trecho do nome), codigo_empresa, dias (desligados nos últimos N dias).
     */
    public function listarElegiveis(array $criterios, array $filtros, int $limite): array
    {
        [$where, $params] = $this->condicoesElegiveis($criterios, $filtros);
        $sql = self::SELECT_CONTRATO . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY cm.demissao DESC, cm.nome LIMIT ' . max(1, $limite);
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contarElegiveis(array $criterios, array $filtros): int
    {
        [$where, $params] = $this->condicoesElegiveis($criterios, $filtros);
        $stmt = $this->connection()->prepare('SELECT COUNT(*) FROM colaboradores_metadados cm WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function condicoesElegiveis(array $criterios, array $filtros): array
    {
        $where = [
            'cm.demissao IS NOT NULL',
            'cm.demissao <= ?',
            "COALESCE(TRIM(cm.motivo_rescisao_codigo), '') <> ?",
            'NOT EXISTS (SELECT 1 FROM entrevistas_desligamento ed WHERE ed.metadados_id = cm.id)',
        ];
        $params = [(string)$criterios['hoje'], (string)$criterios['motivo_excluido']];

        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'cm.codigo_empresa = ?';
            $params[] = (string)$filtros['codigo_empresa'];
        }
        if (!empty($filtros['dias']) && (int)$filtros['dias'] > 0) {
            $limite = (new DateTimeImmutable((string)$criterios['hoje']))->modify('-' . (int)$filtros['dias'] . ' days');
            $where[] = 'cm.demissao >= ?';
            $params[] = $limite->format('Y-m-d');
        }
        if (!empty($filtros['busca'])) {
            $where[] = "cm.nome LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . $this->escaparLike((string)$filtros['busca']) . '%';
        }
        return [$where, $params];
    }

    /** Empresas com desligamento (filtro da tela), por código oficial. */
    public function opcoesEmpresa(): array
    {
        return $this->connection()->query(
            "SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             WHERE demissao IS NOT NULL AND codigo_empresa <> ''
             GROUP BY codigo_empresa
             ORDER BY empresa"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ entrevistas

    /** Condição SQL da situação (derivada de timestamps) — mesma regra de EntrevistaDesligamentoService::situacao(). */
    private function condicaoSituacao(string $situacao): ?string
    {
        return match ($situacao) {
            'respondida' => 'e.respondida_em IS NOT NULL',
            'cancelada' => 'e.respondida_em IS NULL AND e.cancelada_em IS NOT NULL',
            'expirada' => 'e.respondida_em IS NULL AND e.cancelada_em IS NULL AND e.expira_em <= ?',
            'pendente' => 'e.respondida_em IS NULL AND e.cancelada_em IS NULL AND e.expira_em > ?',
            default => null,
        };
    }

    /**
     * Entrevistas (sem respostas) por situação, com a demissão/motivo OFICIAIS ATUAIS do espelho para o
     * chamador sinalizar divergência em relação ao snapshot.
     */
    public function listarEntrevistas(string $situacao, string $agora, array $filtros, int $limite): array
    {
        $condicao = $this->condicaoSituacao($situacao);
        if ($condicao === null) {
            return [];
        }
        $where = [$condicao];
        $params = str_contains($condicao, '?') ? [$agora] : [];
        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'e.snap_codigo_empresa = ?';
            $params[] = (string)$filtros['codigo_empresa'];
        }
        if (!empty($filtros['busca'])) {
            $where[] = "e.snap_nome LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . $this->escaparLike((string)$filtros['busca']) . '%';
        }
        $sql = 'SELECT ' . self::SELECT_OPERACIONAL . ' ' . self::FROM_OPERACIONAL
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.gerada_em DESC, e.id DESC LIMIT ' . max(1, $limite);
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{geradas:int,pendentes:int,respondidas:int,expiradas:int,canceladas:int} */
    public function contagemPorSituacao(string $agora): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT COUNT(*) AS geradas,
                    COALESCE(SUM(respondida_em IS NULL AND cancelada_em IS NULL AND expira_em > ?), 0) AS pendentes,
                    COALESCE(SUM(respondida_em IS NOT NULL), 0) AS respondidas,
                    COALESCE(SUM(respondida_em IS NULL AND cancelada_em IS NULL AND expira_em <= ?), 0) AS expiradas,
                    COALESCE(SUM(respondida_em IS NULL AND cancelada_em IS NOT NULL), 0) AS canceladas
             FROM entrevistas_desligamento'
        );
        $stmt->execute([$agora, $agora]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'geradas' => (int)($row['geradas'] ?? 0),
            'pendentes' => (int)($row['pendentes'] ?? 0),
            'respondidas' => (int)($row['respondidas'] ?? 0),
            'expiradas' => (int)($row['expiradas'] ?? 0),
            'canceladas' => (int)($row['canceladas'] ?? 0),
        ];
    }

    /** Entrevista completa (com respostas) + demissão/motivo oficiais atuais — só para o resultado individual. */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT e.*, cm.demissao AS atual_demissao, cm.motivo_rescisao_codigo AS atual_motivo_codigo, u.nome AS gerada_por_nome
             ' . self::FROM_OPERACIONAL . ' WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarPorMetadadosId(int $metadadosId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, metadados_id, expira_em, cancelada_em, respondida_em FROM entrevistas_desligamento WHERE metadados_id = ? LIMIT 1'
        );
        $stmt->execute([$metadadosId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Página pública: só o necessário (contexto mínimo + estado). Nunca respostas nem identificadores extras. */
    public function buscarPorHash(string $tokenHash): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, expira_em, cancelada_em, respondida_em, snap_nome, snap_cargo, snap_admissao, snap_demissao
             FROM entrevistas_desligamento WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return string[] */
    public function fatoresDaEntrevista(int $entrevistaId): array
    {
        $stmt = $this->connection()->prepare('SELECT fator FROM entrevistas_desligamento_fatores WHERE entrevista_id = ? ORDER BY fator');
        $stmt->execute([$entrevistaId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array $snapshot chaves = COLUNAS_SNAPSHOT
     * @throws PDOException em violação de UNIQUE (contrato já possui entrevista) — tratada pelo service.
     */
    public function inserir(int $metadadosId, string $tokenHash, string $geradaEm, string $expiraEm, int $usuarioId, array $snapshot): int
    {
        $colunas = ['metadados_id', 'token_hash', 'gerada_em', 'gerada_por_usuario_id', 'expira_em'];
        $valores = [$metadadosId, $tokenHash, $geradaEm, $usuarioId, $expiraEm];
        foreach (self::COLUNAS_SNAPSHOT as $coluna) {
            $colunas[] = $coluna;
            $valores[] = $snapshot[$coluna] ?? null;
        }
        $stmt = $this->connection()->prepare(
            'INSERT INTO entrevistas_desligamento (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')'
        );
        $stmt->execute($valores);
        return (int)$this->connection()->lastInsertId();
    }

    /** Troca o token (invalida o anterior) e renova a expiração. Jamais reabre uma entrevista respondida. */
    public function regenerar(int $id, string $tokenHash, string $geradaEm, string $expiraEm, int $usuarioId): bool
    {
        $stmt = $this->connection()->prepare(
            'UPDATE entrevistas_desligamento
             SET token_hash = ?, gerada_em = ?, expira_em = ?, gerada_por_usuario_id = ?, regeneracoes = regeneracoes + 1,
                 cancelada_em = NULL, cancelada_por_usuario_id = NULL
             WHERE id = ? AND respondida_em IS NULL'
        );
        $stmt->execute([$tokenHash, $geradaEm, $expiraEm, $usuarioId, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Só cancela o que está PENDENTE (não respondida, não cancelada, não expirada). */
    public function cancelar(int $id, string $agora, int $usuarioId): bool
    {
        $stmt = $this->connection()->prepare(
            'UPDATE entrevistas_desligamento SET cancelada_em = ?, cancelada_por_usuario_id = ?
             WHERE id = ? AND respondida_em IS NULL AND cancelada_em IS NULL AND expira_em > ?'
        );
        $stmt->execute([$agora, $usuarioId, $id, $agora]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Conclui a entrevista com UPDATE condicional (`respondida_em IS NULL`, não cancelada, não expirada e
     * mesmo token_hash) e confere o rowCount: duas submissões simultâneas → só uma conclui; regenerar ou
     * cancelar entre o carregamento da página e o envio também impede a gravação. Fatores na mesma transação.
     *
     * @param array    $dados   chaves = COLUNAS_RESPOSTA (ausente = NULL)
     * @param string[] $fatores códigos já validados
     */
    public function responder(int $id, string $tokenHash, string $agora, array $dados, array $fatores): bool
    {
        $pdo = $this->connection();
        $sets = [];
        $params = [];
        foreach (self::COLUNAS_RESPOSTA as $coluna) {
            $sets[] = $coluna . ' = ?';
            $params[] = $dados[$coluna] ?? null;
        }
        $sets[] = 'respondida_em = ?';
        $params[] = $agora;
        array_push($params, $id, $tokenHash, $agora);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE entrevistas_desligamento SET ' . implode(', ', $sets)
                . ' WHERE id = ? AND token_hash = ? AND respondida_em IS NULL AND cancelada_em IS NULL AND expira_em > ?'
            );
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }
            $ins = $pdo->prepare('INSERT INTO entrevistas_desligamento_fatores (entrevista_id, fator) VALUES (?, ?)');
            foreach (array_values(array_unique($fatores)) as $fator) {
                $ins->execute([$id, $fator]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{promotores:int,neutros:int,detratores:int,total:int} sobre as entrevistas RESPONDIDAS. */
    public function distribuicaoEnps(): array
    {
        $row = $this->connection()->query(
            'SELECT COUNT(enps) AS total,
                    COALESCE(SUM(enps >= 9), 0) AS promotores,
                    COALESCE(SUM(enps BETWEEN 7 AND 8), 0) AS neutros,
                    COALESCE(SUM(enps <= 6), 0) AS detratores
             FROM entrevistas_desligamento WHERE respondida_em IS NOT NULL AND enps IS NOT NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'promotores' => (int)($row['promotores'] ?? 0),
            'neutros' => (int)($row['neutros'] ?? 0),
            'detratores' => (int)($row['detratores'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);
    }
}
