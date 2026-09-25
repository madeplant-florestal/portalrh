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

    /** Sentinela do filtro `codigo_setor` para "Setor não informado" — ver montarFiltros(). */
    public const SETOR_NAO_INFORMADO = '__sem_setor__';

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

    public function summary(): array
    {
        // `ativos` representa a população vigente AGORA: um contrato marcado `ausente_na_origem
        // = 1` (chave que sumiu de um sync completo — ver migration de reconciliação) nunca deve
        // contar aqui, mesmo que `ativo` continue 1 (esse campo não muda de sentido — ver
        // docstring da migration). `desligados`/`contratos` continuam somando tudo: são
        // contagens históricas, não population vigente, e a ausência não apaga histórico.
        $sql = 'SELECT
                    COUNT(*) AS contratos,
                    SUM(CASE WHEN ativo = 1 AND ausente_na_origem = 0 THEN 1 ELSE 0 END) AS ativos,
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
     *                       situacao ('ativos'|'desligados'|''), sexo ('M'|'F'|'nao_informado'|''),
     *                       situacao_metadados ('sincronizado'|'ausente'|''). Ausente/vazio = sem filtro.
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
                    m.cpf, m.salario_atual, m.sexo, m.ausente_na_origem,
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
     * Página "executiva" para a listagem de Colaboradores do People Analytics — mesmos filtros
     * de paginate() (reaproveita montarFiltros()), mas com colunas e JOINs próprios: nunca toca
     * `cpf`/`salario_atual`/`nascimento`/dados bancários (só o necessário para a listagem
     * operacional aprovada). `identificador` habilita cruzar com MetadadosMovimentacaoService::
     * classificarMovimentacoes() (coluna "Situação" = Transferido). Gestor Imediato: usuário
     * Portal vinculado a este contrato (`usuarios.colaborador_metadados_id`) → o `gestor_usuario_id`
     * DESSE usuário — nunca `aprovador_usuario_id`. A maioria dos contratos não tem usuário Portal
     * vinculado (nem todo colaborador tem login) — gestor vem `null`, nunca inventado.
     *
     * @return array{items:array,total:int,page:int,per_page:int,pages:int}
     */
    public function paginateExecutivo(array $filtros, int $page, int $perPage): array
    {
        $perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : self::ALLOWED_PER_PAGE[0];
        $page = max(1, $page);

        [$where, $params] = $this->montarFiltros($filtros);
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $countStmt = $this->connection()->prepare('SELECT COUNT(*) FROM colaboradores_metadados m' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT
                    m.id, m.identificador, m.nome, m.cargo, m.empresa, m.setor, m.codigo_setor,
                    m.unidade, m.codigo_unidade, m.numero_contrato, m.codigo_empresa, m.codigo_pessoa,
                    m.centro_custo, m.codigo_centro_custo,
                    m.sexo, m.ausente_na_origem, m.admissao, m.demissao, m.motivo_rescisao_descricao, m.ativo,
                    gestor.nome AS gestor_nome
                FROM colaboradores_metadados m
                LEFT JOIN usuarios u ON u.colaborador_metadados_id = m.id
                LEFT JOIN usuarios gestor ON gestor.id = u.gestor_usuario_id'
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
     * Mesmos filtros/colunas de paginateExecutivo(), mas SEM paginação — usado só pela
     * exportação CSV (§16 da correção de 2026-09), que precisa da população filtrada inteira.
     */
    public function listarExecutivo(array $filtros): array
    {
        [$where, $params] = $this->montarFiltros($filtros);
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $sql = 'SELECT
                    m.id, m.identificador, m.nome, m.cargo, m.empresa, m.setor, m.codigo_setor,
                    m.unidade, m.codigo_unidade, m.numero_contrato, m.codigo_empresa, m.codigo_pessoa,
                    m.centro_custo, m.codigo_centro_custo,
                    m.sexo, m.ausente_na_origem, m.admissao, m.demissao, m.motivo_rescisao_descricao, m.ativo,
                    gestor.nome AS gestor_nome
                FROM colaboradores_metadados m
                LEFT JOIN usuarios u ON u.colaborador_metadados_id = m.id
                LEFT JOIN usuarios gestor ON gestor.id = u.gestor_usuario_id'
                . $whereSql
                . ' ORDER BY m.nome ASC, m.numero_contrato ASC';
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * Um contrato oficial pelo id — usado pela administração de Usuários (vínculo opcional
     * `usuarios.colaborador_metadados_id`) para exibir o vínculo já configurado. Sem CPF.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, nome, empresa, unidade, setor, cargo, numero_contrato,
                    codigo_empresa, codigo_unidade, ativo
             FROM colaboradores_metadados WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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

        // codigo_setor: filtro por CÓDIGO oficial (mesma identidade usada em todo o People
        // Analytics — nunca o texto `setor`, que pode variar de grafia). Independente do filtro
        // `setor` (texto) acima, usado pela tela /admin/colaboradores. Sentinela
        // self::SETOR_NAO_INFORMADO: "Setor não informado" nunca é escondido do RH (§17 da
        // correção de 2026-09) — precisa de uma opção explícita, já que WHERE codigo_setor = ''
        // não é como o filtro comum funciona (vazio = sem filtro em todo o resto do módulo).
        $codigoSetor = trim((string)($filtros['codigo_setor'] ?? ''));
        if ($codigoSetor === self::SETOR_NAO_INFORMADO) {
            $where[] = "(m.codigo_setor IS NULL OR m.codigo_setor = '')";
        } elseif ($codigoSetor !== '') {
            $where[] = 'm.codigo_setor = ?';
            $params[] = $codigoSetor;
        }

        $cargo = trim((string)($filtros['cargo'] ?? ''));
        if ($cargo !== '') {
            $where[] = 'm.cargo = ?';
            $params[] = $cargo;
        }

        $situacao = (string)($filtros['situacao'] ?? '');
        if ($situacao === 'ativos') {
            // Coerente com summary(): população vigente exclui quem sumiu de um sync completo.
            $where[] = 'm.ativo = 1 AND m.ausente_na_origem = 0';
        } elseif ($situacao === 'desligados') {
            $where[] = '(m.ativo IS NULL OR m.ativo = 0)';
        }

        $sexo = (string)($filtros['sexo'] ?? '');
        if ($sexo === 'M' || $sexo === 'F') {
            $where[] = 'm.sexo = ?';
            $params[] = $sexo;
        } elseif ($sexo === 'nao_informado') {
            $where[] = "(m.sexo IS NULL OR TRIM(m.sexo) = '')";
        }

        // Situação METADADOS: dimensão independente de `situacao` (ativo/desligado) — um
        // contrato pode estar desligado e nunca ter ficado ausente, ou vice-versa.
        $situacaoMetadados = (string)($filtros['situacao_metadados'] ?? '');
        if ($situacaoMetadados === 'sincronizado') {
            $where[] = 'm.ausente_na_origem = 0';
        } elseif ($situacaoMetadados === 'ausente') {
            $where[] = 'm.ausente_na_origem = 1';
        }

        return [$where, $params];
    }
}
