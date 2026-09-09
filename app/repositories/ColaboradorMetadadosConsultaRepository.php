<?php

/**
 * Consulta de LEITURA da tela administrativa de Colaboradores (/admin/colaboradores).
 *
 * Fonte única: o espelho oficial `colaboradores_metadados` (sincronizado do METADADOS —
 * ver docs/claude/roadmap-tecnico.md). Cada linha representa um CONTRATO, nunca uma pessoa:
 * uma readmissão aparece em múltiplas linhas com `numero_contrato` diferente.
 *
 * A tabela hub legada `colaboradores` entra APENAS via LEFT JOIN por `metadados_id`, para
 * expor o `local_id` das ações que ainda dependem de `colaboradores.id` (edição de dados de
 * RH, acesso/liderança, avaliações). O JOIN nunca altera a cardinalidade: `colaboradores`
 * tem UNIQUE em `metadados_id` (migration 2026-08-28-colaboradores-metadados-id.sql), então
 * cada contrato oficial casa com 0 ou 1 registro local.
 *
 * Mesma geração de `RhIndicadoresRepository`: construtor `?PDO`, 100% prepared statements,
 * sem dependência do SQL Server (lê só o espelho MySQL já sincronizado). Não escreve nada e
 * nunca registra CPF/dados pessoais em log.
 */
class ColaboradorMetadadosConsultaRepository
{
    private const ALLOWED_PER_PAGE = [20, 50, 100];

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
     * Os 6 indicadores da tela, todos derivados exclusivamente do espelho oficial.
     *
     * `desligados` = tudo que não está explicitamente ativo (`ativo = 0` OU `ativo IS NULL`),
     * espelhando a mesma semântica da coluna usada em toda a integração.
     *
     * `cargos_distintos`: o código oficial `codigo_cargo` (Fase 5.1A) ainda pode estar NULL em
     * boa parte do espelho enquanto a sincronização de códigos não roda em todos os ambientes;
     * o COALESCE com o texto `cargo` evita que a contagem colapse para zero nesse cenário.
     */
    public function summary(): array
    {
        $sql = 'SELECT
                    COUNT(*) AS contratos,
                    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
                    SUM(CASE WHEN ativo IS NULL OR ativo = 0 THEN 1 ELSE 0 END) AS desligados,
                    COUNT(DISTINCT codigo_empresa) AS empresas,
                    COUNT(DISTINCT COALESCE(NULLIF(codigo_cargo, \'\'), cargo)) AS cargos_distintos,
                    COUNT(DISTINCT codigo_pessoa) AS pessoas_distintas
                FROM colaboradores_metadados';
        $row = $this->connection()->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'contratos' => (int)($row['contratos'] ?? 0),
            'ativos' => (int)($row['ativos'] ?? 0),
            'desligados' => (int)($row['desligados'] ?? 0),
            'empresas' => (int)($row['empresas'] ?? 0),
            'cargos_distintos' => (int)($row['cargos_distintos'] ?? 0),
            'pessoas_distintas' => (int)($row['pessoas_distintas'] ?? 0),
        ];
    }

    /**
     * Página da listagem oficial. Uma linha do resultado = um contrato de `colaboradores_metadados`.
     *
     * @param array $filtros Chaves: q, empresa (codigo_empresa), setor (texto), cargo (texto),
     *                       situacao ('ativos'|'desligados'|''). Ausente/vazio = sem filtro.
     * @return array{items:array,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filtros, int $page, int $perPage): array
    {
        $perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : self::ALLOWED_PER_PAGE[0];
        $page = max(1, $page);

        [$where, $params] = $this->montarFiltros($filtros);
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $countStmt = $this->connection()->prepare(
            'SELECT COUNT(*) FROM colaboradores_metadados m' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        // LEFT JOIN só para trazer o id local; UNIQUE(metadados_id) garante 0..1 correspondência.
        $sql = 'SELECT
                    m.id, m.nome, m.cargo, m.empresa, m.setor, m.unidade,
                    m.numero_contrato, m.codigo_empresa, m.codigo_pessoa,
                    m.cpf, m.salario_atual,
                    m.admissao, m.nascimento, m.demissao, m.motivo_rescisao_descricao,
                    m.ativo,
                    c.id AS local_id
                FROM colaboradores_metadados m
                LEFT JOIN colaboradores c ON c.metadados_id = m.id'
                . $whereSql
                . ' ORDER BY m.nome ASC, m.numero_contrato ASC
                    LIMIT ? OFFSET ?';
        $stmt = $this->connection()->prepare($sql);
        $execParams = $params;
        $execParams[] = $perPage;
        $execParams[] = $offset;
        $stmt->execute($execParams);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Valores distintos para os selects de filtro — mesmo critério do Dashboard de Indicadores:
     * empresa agrupada pelo código estável (`codigo_empresa`), exibindo o nome mais recente;
     * setor e cargo pelo valor textual sincronizado.
     */
    public function opcoesFiltro(): array
    {
        $pdo = $this->connection();

        $empresas = $pdo->query(
            'SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             WHERE codigo_empresa IS NOT NULL AND codigo_empresa <> \'\'
             GROUP BY codigo_empresa
             ORDER BY empresa'
        )->fetchAll(PDO::FETCH_ASSOC);

        $setores = $pdo->query(
            'SELECT DISTINCT setor FROM colaboradores_metadados
             WHERE setor IS NOT NULL AND setor <> \'\' ORDER BY setor'
        )->fetchAll(PDO::FETCH_COLUMN);

        $cargos = $pdo->query(
            'SELECT DISTINCT cargo FROM colaboradores_metadados
             WHERE cargo IS NOT NULL AND cargo <> \'\' ORDER BY cargo'
        )->fetchAll(PDO::FETCH_COLUMN);

        return [
            'empresas' => $empresas,
            'setores' => $setores,
            'cargos' => $cargos,
        ];
    }

    /**
     * @return array{0: string[], 1: array<int, string>} [cláusulas WHERE, parâmetros posicionais]
     */
    private function montarFiltros(array $filtros): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filtros['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(m.nome LIKE ? OR m.cargo LIKE ? OR m.empresa LIKE ? OR m.setor LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $empresa = trim((string)($filtros['empresa'] ?? ''));
        if ($empresa !== '') {
            $where[] = 'm.codigo_empresa = ?';
            $params[] = $empresa;
        }

        $setor = trim((string)($filtros['setor'] ?? ''));
        if ($setor !== '') {
            $where[] = 'm.setor = ?';
            $params[] = $setor;
        }

        $cargo = trim((string)($filtros['cargo'] ?? ''));
        if ($cargo !== '') {
            $where[] = 'm.cargo = ?';
            $params[] = $cargo;
        }

        $situacao = (string)($filtros['situacao'] ?? '');
        if ($situacao === 'ativos') {
            $where[] = 'm.ativo = 1';
        } elseif ($situacao === 'desligados') {
            $where[] = '(m.ativo IS NULL OR m.ativo = 0)';
        }

        return [$where, $params];
    }
}
