<?php

/**
 * Agregações do Dashboard da Entrevista de Desligamento. Só LEITURA, só agregados (nunca nome, CPF, token,
 * `metadados_id` nem texto livre das respostas). Duas populações independentes, cada uma com sua competência:
 *
 *   A — desligamentos oficiais: `colaboradores_metadados` (unidade = CONTRATO; nunca COUNT(DISTINCT pessoa),
 *       nunca `colaboradores` legado), competência = `demissao`, filtros pelos campos do próprio contrato.
 *   B — entrevistas: `entrevistas_desligamento` (+ `_fatores`), competência = `snap_demissao` (a data de
 *       desligamento conhecida na geração — NUNCA `respondida_em`), filtros pelos campos `snap_*`.
 *       Indicadores de resposta usam somente `respondida_em IS NOT NULL`.
 *
 * Cada consulta agrega por mês/dimensão no banco (número constante de consultas, sem N+1). Composição de médias,
 * eNPS e "sem base" ficam em DashboardEntrevistaDesligamentoService.
 *
 * Filtros (array): inicio/fim (Y-m-d), unidade (['codigo_empresa','codigo_unidade'] — a unidade só é única
 * dentro da empresa) e codigo_cargo — sempre por CÓDIGO oficial, sempre com parâmetros posicionais.
 */
class DashboardEntrevistaDesligamentoRepository
{
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

    /** Colunas 1–5 de Liderança, Cultura e Integração (nomes fixos do código — nunca input). */
    public static function dimensoes(): array
    {
        $campos = [];
        foreach (['lideranca', 'cultura', 'integracao'] as $secao) {
            $campos = array_merge($campos, array_keys(EntrevistaDesligamentoService::SECOES_ESCALA[$secao]['itens']));
        }
        return $campos;
    }

    private const SQL_CARGO_CATALOGO = "(SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = %s COLLATE utf8mb4_general_ci LIMIT 1)";

    /** @return array{0:string[],1:array} */
    private function condicoes(array $f, string $colData, string $colEmpresa, string $colUnidade, string $colCargo): array
    {
        $where = [$colData . ' BETWEEN ? AND ?'];
        $params = [(string)$f['inicio'], (string)$f['fim']];
        if (!empty($f['unidade'])) {
            $where[] = $colEmpresa . ' = ? AND ' . $colUnidade . ' = ?';
            $params[] = (string)$f['unidade']['codigo_empresa'];
            $params[] = (string)$f['unidade']['codigo_unidade'];
        }
        if (!empty($f['codigo_cargo'])) {
            $where[] = $colCargo . ' = ?';
            $params[] = (string)$f['codigo_cargo'];
        }
        return [$where, $params];
    }

    private function condicoesContrato(array $f): array
    {
        return $this->condicoes($f, 'cm.demissao', 'cm.codigo_empresa', 'cm.codigo_unidade', 'cm.codigo_cargo');
    }

    private function condicoesEntrevista(array $f, bool $soRespondidas = false): array
    {
        [$where, $params] = $this->condicoes($f, 'e.snap_demissao', 'e.snap_codigo_empresa', 'e.snap_codigo_unidade', 'e.snap_codigo_cargo');
        if ($soRespondidas) {
            $where[] = 'e.respondida_em IS NOT NULL';
        }
        return [$where, $params];
    }

    private function todas(string $sql, array $params): array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Agregados de resposta (eNPS/satisfação) reutilizados por mês, unidade e cargo. Só respondidas contam. */
    private function selectRespostas(): string
    {
        return "COUNT(*) AS geradas,
                COALESCE(SUM(e.respondida_em IS NOT NULL), 0) AS respondidas,
                COUNT(CASE WHEN e.respondida_em IS NOT NULL THEN e.experiencia_geral END) AS n_sat,
                COALESCE(SUM(CASE WHEN e.respondida_em IS NOT NULL THEN e.experiencia_geral END), 0) AS soma_sat,
                COUNT(CASE WHEN e.respondida_em IS NOT NULL THEN e.enps END) AS n_enps,
                COALESCE(SUM(e.respondida_em IS NOT NULL AND e.enps >= 9), 0) AS promotores,
                COALESCE(SUM(e.respondida_em IS NOT NULL AND e.enps BETWEEN 7 AND 8), 0) AS neutros,
                COALESCE(SUM(e.respondida_em IS NOT NULL AND e.enps <= 6), 0) AS detratores";
    }

    // ------------------------------------------------------------------ População A (contratos)

    /**
     * Desligamentos por mês da demissão: total, elegíveis (motivo ≠ excluído), elegíveis sem entrevista gerada e
     * base do tempo de permanência (admissão válida e ≤ demissão).
     */
    public function desligamentosPorMes(array $f, string $motivoExcluido): array
    {
        [$where, $params] = $this->condicoesContrato($f);
        $sql = "SELECT DATE_FORMAT(cm.demissao, '%Y-%m') AS mes,
                       COUNT(*) AS desligamentos,
                       COALESCE(SUM(COALESCE(TRIM(cm.motivo_rescisao_codigo), '') <> ?), 0) AS elegiveis,
                       COALESCE(SUM(COALESCE(TRIM(cm.motivo_rescisao_codigo), '') <> ? AND e.id IS NULL), 0) AS sem_entrevista,
                       COALESCE(SUM(CASE WHEN cm.admissao IS NOT NULL AND cm.admissao <= cm.demissao THEN DATEDIFF(cm.demissao, cm.admissao) END), 0) AS perm_dias,
                       COALESCE(SUM(cm.admissao IS NOT NULL AND cm.admissao <= cm.demissao), 0) AS perm_validos
                FROM colaboradores_metadados cm
                LEFT JOIN entrevistas_desligamento e ON e.metadados_id = cm.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY DATE_FORMAT(cm.demissao, '%Y-%m')
                ORDER BY mes";
        return $this->todas($sql, array_merge([$motivoExcluido, $motivoExcluido], $params));
    }

    public function desligamentosPorUnidade(array $f): array
    {
        [$where, $params] = $this->condicoesContrato($f);
        return $this->todas(
            "SELECT cm.codigo_empresa, cm.codigo_unidade, MAX(NULLIF(cm.unidade, '')) AS unidade, MAX(NULLIF(cm.empresa, '')) AS empresa,
                    COUNT(*) AS desligamentos
             FROM colaboradores_metadados cm
             WHERE " . implode(' AND ', $where) . '
             GROUP BY cm.codigo_empresa, cm.codigo_unidade',
            $params
        );
    }

    public function desligamentosPorCargo(array $f): array
    {
        [$where, $params] = $this->condicoesContrato($f);
        return $this->todas(
            'SELECT cm.codigo_cargo,
                    COALESCE(' . sprintf(self::SQL_CARGO_CATALOGO, 'cm.codigo_cargo') . ", MAX(NULLIF(cm.cargo, ''))) AS cargo,
                    COUNT(*) AS desligamentos
             FROM colaboradores_metadados cm
             WHERE " . implode(' AND ', $where) . '
             GROUP BY cm.codigo_cargo',
            $params
        );
    }

    // ------------------------------------------------------------------ População B (entrevistas)

    /** Geradas/respondidas/eNPS/satisfação e somas/contagens das 14 dimensões 1–5, por mês da demissão (snapshot). */
    public function entrevistasPorMes(array $f): array
    {
        [$where, $params] = $this->condicoesEntrevista($f);
        $dims = '';
        foreach (self::dimensoes() as $coluna) {
            $dims .= ", COUNT(CASE WHEN e.respondida_em IS NOT NULL THEN e.{$coluna} END) AS n_{$coluna}"
                . ", COALESCE(SUM(CASE WHEN e.respondida_em IS NOT NULL THEN e.{$coluna} END), 0) AS s_{$coluna}";
        }
        $sql = "SELECT DATE_FORMAT(e.snap_demissao, '%Y-%m') AS mes, " . $this->selectRespostas() . $dims . '
                FROM entrevistas_desligamento e
                WHERE ' . implode(' AND ', $where) . "
                GROUP BY DATE_FORMAT(e.snap_demissao, '%Y-%m')
                ORDER BY mes";
        return $this->todas($sql, $params);
    }

    /** Motivo principal DECLARADO (nunca o oficial) das respondidas, por alternativa. */
    public function motivosDeclarados(array $f): array
    {
        [$where, $params] = $this->condicoesEntrevista($f, true);
        $where[] = 'e.motivo_principal IS NOT NULL';
        return $this->todas(
            'SELECT e.motivo_principal AS codigo, COUNT(*) AS quantidade
             FROM entrevistas_desligamento e WHERE ' . implode(' AND ', $where) . '
             GROUP BY e.motivo_principal',
            $params
        );
    }

    /** Marcações de fatores contribuintes das respondidas (seleção múltipla: uma entrevista conta em vários). */
    public function fatoresContribuintes(array $f): array
    {
        [$where, $params] = $this->condicoesEntrevista($f, true);
        return $this->todas(
            'SELECT fa.fator AS codigo, COUNT(*) AS quantidade
             FROM entrevistas_desligamento_fatores fa
             INNER JOIN entrevistas_desligamento e ON e.id = fa.entrevista_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY fa.fator',
            $params
        );
    }

    public function entrevistasPorUnidade(array $f): array
    {
        [$where, $params] = $this->condicoesEntrevista($f);
        return $this->todas(
            "SELECT e.snap_codigo_empresa AS codigo_empresa, e.snap_codigo_unidade AS codigo_unidade,
                    MAX(NULLIF(e.snap_unidade, '')) AS unidade, MAX(NULLIF(e.snap_empresa, '')) AS empresa, " . $this->selectRespostas() . '
             FROM entrevistas_desligamento e
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY e.snap_codigo_empresa, e.snap_codigo_unidade',
            $params
        );
    }

    public function entrevistasPorCargo(array $f): array
    {
        [$where, $params] = $this->condicoesEntrevista($f);
        return $this->todas(
            'SELECT e.snap_codigo_cargo AS codigo_cargo,
                    COALESCE(' . sprintf(self::SQL_CARGO_CATALOGO, 'e.snap_codigo_cargo') . ", MAX(NULLIF(e.snap_cargo, ''))) AS cargo, "
            . $this->selectRespostas() . '
             FROM entrevistas_desligamento e
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY e.snap_codigo_cargo',
            $params
        );
    }

    // ------------------------------------------------------------------ opções dos filtros

    /** Unidades com desligamento, pela identidade oficial (empresa + unidade). */
    public function opcoesUnidade(): array
    {
        return $this->connection()->query(
            "SELECT codigo_empresa, codigo_unidade, MAX(NULLIF(unidade, '')) AS unidade, MAX(NULLIF(empresa, '')) AS empresa
             FROM colaboradores_metadados
             WHERE demissao IS NOT NULL AND codigo_unidade <> '' AND codigo_empresa <> ''
             GROUP BY codigo_empresa, codigo_unidade
             ORDER BY unidade, empresa"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cargos com desligamento, por `codigo_cargo` (descrição do catálogo oficial; fallback texto do espelho). */
    public function opcoesCargo(): array
    {
        return $this->connection()->query(
            'SELECT cm.codigo_cargo,
                    COALESCE(' . sprintf(self::SQL_CARGO_CATALOGO, 'cm.codigo_cargo') . ", MAX(NULLIF(cm.cargo, '')), cm.codigo_cargo) AS nome
             FROM colaboradores_metadados cm
             WHERE cm.demissao IS NOT NULL AND cm.codigo_cargo IS NOT NULL AND cm.codigo_cargo <> ''
             GROUP BY cm.codigo_cargo
             ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
