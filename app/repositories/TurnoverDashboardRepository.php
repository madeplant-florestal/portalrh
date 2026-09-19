<?php

/**
 * Acesso a dados do Dashboard de Turnover — lê EXCLUSIVAMENTE o espelho oficial
 * `colaboradores_metadados` (unidade = CONTRATO) e o catálogo oficial `cargos` (só para a descrição
 * do Cargo). Nunca usa `colaboradores` legado como fonte, nunca escreve, nunca seleciona CPF,
 * nascimento, nome ou salário — só as colunas dos indicadores. Mesma disciplina de
 * PeopleAnalyticsRepository/RhIndicadoresRepository.
 *
 * Identidade organizacional: `codigo_empresa` e `codigo_cargo` (nunca o texto como chave).
 */
class TurnoverDashboardRepository
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

    /**
     * Todos os contratos (sem filtro de data — o headcount histórico e o comparativo Y-1 × Y precisam
     * do histórico completo de cada contrato) que atendem aos filtros de Empresa/Cargo, ambos por
     * CÓDIGO oficial. Uma única consulta alimenta os seis gráficos.
     *
     * @param array $filtros Chaves: codigo_empresa, codigo_cargo. Ausente/vazio = sem filtro.
     * @return array Cada item: admissao, demissao, motivo_rescisao_codigo, codigo_empresa, empresa,
     *               codigo_cargo, cargo.
     */
    public function buscarContratos(array $filtros = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'codigo_empresa = ?';
            $params[] = $filtros['codigo_empresa'];
        }
        if (!empty($filtros['codigo_cargo'])) {
            $where[] = 'codigo_cargo = ?';
            $params[] = $filtros['codigo_cargo'];
        }

        $sql = 'SELECT admissao, demissao, motivo_rescisao_codigo, codigo_empresa, empresa, codigo_cargo, cargo
                FROM colaboradores_metadados';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Opções de filtro: Empresa por `codigo_empresa` (nome mais recente associado, mesmo critério do
     * `MAX(empresa)` de PeopleAnalyticsRepository::opcoesFiltro()) e Cargo por `codigo_cargo` com a
     * descrição do catálogo oficial `cargos` (fallback: texto do espelho; por último o próprio código).
     */
    public function opcoesFiltro(): array
    {
        $pdo = $this->connection();

        $empresas = $pdo->query(
            "SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             WHERE codigo_empresa IS NOT NULL AND codigo_empresa <> ''
             GROUP BY codigo_empresa
             ORDER BY empresa"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cargos = $pdo->query(
            "SELECT cm.codigo_cargo,
                    COALESCE(
                      (SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                        WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci
                        LIMIT 1),
                      MAX(NULLIF(cm.cargo, '')),
                      cm.codigo_cargo
                    ) AS nome
             FROM colaboradores_metadados cm
             WHERE cm.codigo_cargo IS NOT NULL AND cm.codigo_cargo <> ''
             GROUP BY cm.codigo_cargo
             ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['empresas' => $empresas, 'cargos' => $cargos];
    }

    /**
     * Descrição oficial (catálogo `cargos`) por `codigo_cargo`. Código sem correspondência no
     * catálogo simplesmente não aparece no mapa — quem consome cai no fallback, nunca inventa nome.
     *
     * @param string[] $codigos
     * @return array<string,string> codigo_cargo => descrição oficial
     */
    public function nomesOficiaisDeCargos(array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter(array_map('strval', $codigos), static fn(string $c): bool => $c !== '')));
        if ($codigos === []) {
            return [];
        }
        $stmt = $this->connection()->prepare(
            'SELECT c.codigo_cargo, COALESCE(c.descricao_oficial, c.nome) AS nome
             FROM cargos c
             WHERE c.codigo_cargo IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')'
        );
        $stmt->execute($codigos);
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $nome = trim((string)($linha['nome'] ?? ''));
            if ($nome !== '') {
                $mapa[(string)$linha['codigo_cargo']] = $nome;
            }
        }
        return $mapa;
    }

    /** Primeiro ano com algum desligamento no espelho — define até onde o histórico é utilizável. */
    public function primeiroAnoComDesligamento(): ?int
    {
        $ano = $this->connection()->query('SELECT MIN(YEAR(demissao)) FROM colaboradores_metadados WHERE demissao IS NOT NULL')->fetchColumn();
        return $ano !== false && $ano !== null ? (int)$ano : null;
    }
}
