<?php

/**
 * Exclusão analítica de contratos específicos (2026-10) — mecanismo central e reaproveitável
 * para "este contrato nunca deve entrar em nenhuma estatística/listagem analítica do Portal",
 * sem apagar o registro original em `colaboradores_metadados` e sem depender de nome hardcoded
 * ou de heurística genérica (a hipótese "setor vazio = excluir" foi avaliada e rejeitada — há
 * muitos contratos legítimos sem setor, nunca escondidos do RH por design).
 *
 * Cada linha em `colaboradores_metadados_exclusoes_analiticas` é uma decisão administrativa
 * explícita e auditável: motivo + quem excluiu + quando. Ausência de linha para o vínculo = o
 * contrato participa normalmente de tudo.
 *
 * IDENTIFICAÇÃO PERMANENTE (2026-11, migration 2026-11-11-colaboradores-metadados-exclusoes-chave-
 * natural.sql): a exclusão é identificada pela CHAVE NATURAL do vínculo —
 * (codigo_empresa, codigo_unidade, numero_contrato), a mesma que o sincronizador usa no upsert e
 * que é UNIQUE em colaboradores_metadados — e NÃO por `metadados_id` (AUTO_INCREMENT, difere entre
 * ambientes e muda numa reconstrução da base). `metadados_id` fica só como referência de auditoria
 * das exclusões migradas e NUNCA decide quem é excluído quando a migration está aplicada; assim um
 * ID interno reaproveitado jamais exclui outro contrato. Uma exclusão afeta somente o vínculo da
 * chave correspondente — nunca todos os contratos da mesma pessoa, nunca por CPF/nome; uma
 * transferência interempresa gera novo vínculo (nova chave) e não herda exclusões.
 *
 * `clausulaSqlExclusao()` é o único ponto que os repositories de camada analítica devem usar —
 * aplicado hoje em `ColaboradorMetadadosConsultaRepository` (listagem de Colaboradores),
 * `PeopleAnalyticsRepository::buscarContratos()`/`distribuicaoAtivosPorSetor()`,
 * `RhIndicadoresRepository::buscarContratos()` e `TurnoverDashboardRepository::buscarContratos()`
 * — nunca em telas administrativas de ponto único (ex.: `findById()`, usada pelo vínculo de
 * Usuários), que precisam continuar encontrando o contrato mesmo que ele esteja excluído das
 * estatísticas.
 *
 * SCHEMA: este serviço NÃO cria nem altera tabelas em runtime — migrations são aplicadas
 * manualmente. `modoSchema()` valida a compatibilidade: tabela ausente = erro controlado; colunas
 * da chave natural ausentes (migration 2026-11-11 ainda não aplicada) = modo LEGADO, em que o filtro
 * de leitura continua funcionando por `metadados_id` (comportamento anterior, com aviso no log) e
 * toda ESCRITA (excluir/incluir) é recusada com orientação — assim o deploy do código não derruba
 * a listagem se a migration ainda não foi aplicada.
 */
class ColaboradorMetadadosElegibilidadeService
{
    public const MODO_NATURAL = 'natural';
    public const MODO_LEGADO = 'legado';

    private const TABELA = 'colaboradores_metadados_exclusoes_analiticas';
    private const COLUNAS_CHAVE_NATURAL = ['codigo_empresa', 'codigo_unidade', 'numero_contrato'];
    private const MIGRATION_CHAVE_NATURAL = 'database/migrations/2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql';

    private static ?string $modoCache = null;
    private static bool $avisouLegado = false;
    /** Estado DESTA requisição (ver acessoIndividual()). */
    private static ?string $avisoAuditoria = null;
    private static bool $ocultadoNaRequisicao = false;

    /**
     * Compatibilidade do schema real com este código (cache por processo).
     *
     * @return string MODO_NATURAL (migration 2026-11-11 aplicada) | MODO_LEGADO (só metadados_id)
     * @throws RuntimeException se a tabela de exclusões nem existe (migration 2026-10-29 ausente).
     */
    public static function modoSchema(): string
    {
        if (self::$modoCache !== null) {
            return self::$modoCache;
        }

        $stmt = Database::conn()->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([self::TABELA]);
        $colunas = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($colunas === []) {
            // Não cacheia a falha: se a migration for aplicada com o processo vivo, a próxima chamada enxerga.
            throw new RuntimeException(
                'A tabela ' . self::TABELA . ' não existe. Aplique database/migrations/2026-10-29-colaboradores-metadados-exclusoes-analiticas.sql '
                . 'e depois ' . self::MIGRATION_CHAVE_NATURAL . '.'
            );
        }

        $temChaveNatural = count(array_intersect(self::COLUNAS_CHAVE_NATURAL, $colunas)) === count(self::COLUNAS_CHAVE_NATURAL);
        self::$modoCache = $temChaveNatural ? self::MODO_NATURAL : self::MODO_LEGADO;
        return self::$modoCache;
    }

    /**
     * Predicado ÚNICO de VIGÊNCIA CONTRATUAL na data de referência (decisão de negócio 2026-11-13):
     * admissão preenchida e <= data, demissão nula ou >= data (a data do desligamento é o último dia de
     * vigência) e não ausente na origem. A flag `ativo` NÃO entra: um desligamento programado para data
     * futura mantém o contrato vigente até lá. Mesma regra de RhIndicadoresService::contratoAtivoEm() +
     * filtro `ausente_na_origem = 0` do card Headcount Atual. A exclusão analítica NÃO está aqui
     * (use clausulaSqlExclusao()).
     *
     * @param string $alias Alias da tabela colaboradores_metadados na query ('' = sem alias).
     * @return array{0:string,1:array} [fragmento SQL com 3 placeholders posicionais, parâmetros]
     */
    public static function clausulaSqlVigente(string $alias, DateTimeImmutable $data): array
    {
        if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Alias inválido para a cláusula de vigência.');
        }
        $p = $alias === '' ? '' : $alias . '.';
        $dia = $data->format('Y-m-d');
        return [
            "({$p}ausente_na_origem = 0 AND {$p}admissao IS NOT NULL AND {$p}admissao <= ? AND ({$p}demissao IS NULL OR {$p}demissao >= ?))",
            [$dia, $dia],
        ];
    }

    /**
     * Predicado de DESLIGADO na data de referência: desligamento EFETIVO, isto é, demissão preenchida e ANTERIOR à
     * data (a data da demissão ainda é o último dia de vigência — ver clausulaSqlVigente). Não usa a flag `ativo`
     * nem `ausente_na_origem`: contrato sem demissão, com admissão futura ou sem admissão nunca é "desligado", e
     * um desligamento programado para o futuro ainda é vigente. Mesma convenção dos Desligamentos do People Analytics.
     *
     * @return array{0:string,1:array} [fragmento SQL com 1 placeholder posicional, parâmetros]
     */
    public static function clausulaSqlDesligado(string $alias, DateTimeImmutable $data): array
    {
        if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Alias inválido para a cláusula de desligado.');
        }
        $p = $alias === '' ? '' : $alias . '.';
        return ["({$p}demissao IS NOT NULL AND {$p}demissao < ?)", [$data->format('Y-m-d')]];
    }

    /**
     * Fragmento de WHERE autocontido (sem placeholders) para excluir contratos marcados — splica
     * direto em qualquer array de condições já existente.
     *
     * @param string $colunaId Coluna `id` da query de destino, COM o alias dela quando houver:
     *                         `m.id`, `cm.id`, ou `id` quando a query lê `colaboradores_metadados`
     *                         sem alias. O alias (ou o nome da tabela) é usado para ler a chave
     *                         natural do contrato da query externa.
     * @throws InvalidArgumentException se $colunaId não tiver a forma `[alias.]id`.
     */
    public static function clausulaSqlExclusao(string $colunaId = 'id'): string
    {
        $prefixo = self::prefixoDaColunaId($colunaId);

        if (self::modoSchema() === self::MODO_NATURAL) {
            return 'NOT EXISTS (SELECT 1 FROM ' . self::TABELA . ' ex'
                . " WHERE ex.codigo_empresa = {$prefixo}codigo_empresa"
                . " AND ex.codigo_unidade = {$prefixo}codigo_unidade"
                . " AND ex.numero_contrato = {$prefixo}numero_contrato)";
        }

        if (!self::$avisouLegado) {
            self::$avisouLegado = true;
            Logger::warning(
                'Exclusão analítica em modo LEGADO (por metadados_id): migration da chave natural ainda não aplicada.',
                ['migration' => self::MIGRATION_CHAVE_NATURAL]
            );
        }
        return "{$colunaId} NOT IN (SELECT metadados_id FROM " . self::TABELA . ')';
    }

    /**
     * Mesma regra de `clausulaSqlExclusao()`, para consultas que NÃO leem `colaboradores_metadados`
     * mas uma tabela de módulo que guarda o vínculo em `metadados_id` (pdis, avaliacoes_*,
     * entrevistas_desligamento, feedbacks, pesquisas_integracao…) — a exclusão é GLOBAL: o registro
     * desse módulo vinculado a um contrato excluído não entra em lista, contagem, média nem
     * distribuição. Linhas sem vínculo (`metadados_id` NULL) NÃO são afetadas.
     *
     * @param string $expressaoMetadadosId `alias.coluna` ou `COALESCE(alias.a, alias.b)` — SEMPRE
     *                                     qualificado (a tabela de exclusões também tem uma coluna
     *                                     `metadados_id`: sem qualificador a subconsulta leria a
     *                                     coluna errada) e só identificadores (validado; nunca texto
     *                                     vindo de request). Sem alias na query, use o nome da tabela.
     * @throws InvalidArgumentException se a expressão não tiver uma dessas formas.
     */
    public static function clausulaSqlExclusaoPorMetadadosId(string $expressaoMetadadosId): string
    {
        $id = '[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*';
        if (!preg_match("/^(?:{$id}|COALESCE\\(\\s*{$id}\\s*,\\s*{$id}\\s*\\))$/", $expressaoMetadadosId)) {
            throw new InvalidArgumentException("Expressão de metadados_id inválida para a cláusula de exclusão analítica: {$expressaoMetadadosId}");
        }

        if (self::modoSchema() === self::MODO_NATURAL) {
            return "({$expressaoMetadadosId} IS NULL OR NOT EXISTS (SELECT 1 FROM colaboradores_metadados xcm"
                . ' INNER JOIN ' . self::TABELA . ' xex'
                . ' ON xex.codigo_empresa = xcm.codigo_empresa'
                . ' AND xex.codigo_unidade = xcm.codigo_unidade'
                . ' AND xex.numero_contrato = xcm.numero_contrato'
                . " WHERE xcm.id = {$expressaoMetadadosId}))";
        }

        return "({$expressaoMetadadosId} IS NULL OR {$expressaoMetadadosId} NOT IN (SELECT metadados_id FROM " . self::TABELA . '))';
    }

    /**
     * BARREIRA de acesso INDIVIDUAL (um registro por id/token) a algo ligado a um contrato excluído
     * analiticamente. Os carregadores individuais dos módulos (avaliações, feedback, PDI, entrevista,
     * pesquisa…) chamam isto e devolvem "não encontrado" quando devolve false.
     *
     *   - contrato NÃO excluído, ou sem vínculo (`null`)  → true (nada muda);
     *   - excluído + usuário ADMIN + requisição de LEITURA (GET/HEAD) → true: consulta técnica de
     *     auditoria, somente leitura, sinalizada por `avisoAuditoria()` (o shell exibe um aviso);
     *   - qualquer outro caso (RH, Gestor, Viewer, visitante, token público, ou escrita — inclusive
     *     de Admin) → false; numa requisição web responde 404 e encerra.
     *
     * A exclusão prevalece SEMPRE nas listagens, indicadores e exportações (cláusulas SQL): isto só
     * abre a consulta técnica individual, nunca alimenta cálculo nem relatório.
     */
    public static function acessoIndividual(?int $metadadosId): bool
    {
        if ($metadadosId === null || $metadadosId <= 0 || !self::estaExcluido($metadadosId)) {
            return true;
        }

        $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $somenteLeitura = in_array($metodo, ['GET', 'HEAD'], true);
        if ($somenteLeitura && strtolower(trim((string)Auth::role())) === 'admin') {
            self::$avisoAuditoria = 'Este registro pertence a um contrato EXCLUÍDO ANALITICAMENTE (cadastro indevido). '
                . 'Consulta técnica de auditoria, somente leitura: não participa de listas, indicadores nem relatórios do RH.';
            return true;
        }

        self::$ocultadoNaRequisicao = true;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(404);
            echo 'Registro não encontrado.';
            exit;
        }
        return false;
    }

    /**
     * FASE C — cadastro LOCAL (`colaboradores`). Para colunas de PROCESSO que guardam o id de um
     * colaborador local (`solicitacoes_vaga.colaborador_substituido_id`, `movimentacoes_pessoal.
     * colaborador_id`…): o processo cujo colaborador está ligado (`colaboradores.metadados_id`) a um
     * contrato excluído não entra em lista nem em estatística. Coluna sem valor (NULL) não é afetada.
     *
     * @param string $colunaColaboradorId SEMPRE `alias.coluna` (validado; só identificadores).
     */
    public static function clausulaSqlExclusaoColaboradorLocal(string $colunaColaboradorId): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $colunaColaboradorId)) {
            throw new InvalidArgumentException("Coluna de colaborador local inválida para a cláusula de exclusão analítica: {$colunaColaboradorId}");
        }

        if (self::modoSchema() === self::MODO_NATURAL) {
            return "({$colunaColaboradorId} IS NULL OR NOT EXISTS (SELECT 1 FROM colaboradores xlc"
                . ' INNER JOIN colaboradores_metadados xcm ON xcm.id = xlc.metadados_id'
                . ' INNER JOIN ' . self::TABELA . ' xex'
                . ' ON xex.codigo_empresa = xcm.codigo_empresa'
                . ' AND xex.codigo_unidade = xcm.codigo_unidade'
                . ' AND xex.numero_contrato = xcm.numero_contrato'
                . " WHERE xlc.id = {$colunaColaboradorId}))";
        }

        return "({$colunaColaboradorId} IS NULL OR NOT EXISTS (SELECT 1 FROM colaboradores xlc"
            . ' WHERE xlc.id = ' . $colunaColaboradorId . ' AND xlc.metadados_id IN (SELECT metadados_id FROM ' . self::TABELA . ')))';
    }

    /**
     * Conjunto de cláusulas (unidas por AND) para um PROCESSO que referencia vários colaboradores
     * locais: basta UM dos envolvidos estar ligado a contrato excluído para o processo sair das
     * listas e das estatísticas. Preserva o processo (nada é alterado nem apagado).
     *
     * @param string[] $colunas cada uma `alias.coluna`
     */
    public static function clausulaSqlExclusaoProcesso(array $colunas): string
    {
        return implode(' AND ', array_map([self::class, 'clausulaSqlExclusaoColaboradorLocal'], $colunas));
    }

    /** Contrato (`colaboradores_metadados.id`) a que um colaborador LOCAL está ligado, ou null. */
    public static function metadadosIdDoColaboradorLocal(?int $colaboradorId): ?int
    {
        if ($colaboradorId === null || $colaboradorId <= 0) {
            return null;
        }
        $stmt = Database::conn()->prepare('SELECT metadados_id FROM colaboradores WHERE id = ?');
        $stmt->execute([$colaboradorId]);
        $valor = $stmt->fetchColumn();
        return ($valor !== false && $valor !== null) ? (int)$valor : null;
    }

    /** O colaborador LOCAL está ligado a um contrato excluído? (uso em validação de NOVOS processos — vale para qualquer perfil) */
    public static function colaboradorLocalExcluido(?int $colaboradorId): bool
    {
        $metadadosId = self::metadadosIdDoColaboradorLocal($colaboradorId);
        return $metadadosId !== null && self::estaExcluido($metadadosId);
    }

    /**
     * Acesso INDIVIDUAL a um PROCESSO já carregado (solicitação de vaga, movimentação…): se QUALQUER dos
     * colaboradores locais referenciados por `$camposColaborador` estiver ligado a contrato excluído, vale
     * a mesma barreira de acessoIndividual() (só Admin, só leitura; demais → 404).
     *
     * @param string[] $camposColaborador chaves do registro que guardam ids de colaboradores locais
     */
    public static function filtrarProcessoIndividual(?array $registro, array $camposColaborador): ?array
    {
        if ($registro === null) {
            return null;
        }
        foreach ($camposColaborador as $campo) {
            $mid = self::metadadosIdDoColaboradorLocal(isset($registro[$campo]) && $registro[$campo] !== null ? (int)$registro[$campo] : null);
            if (!self::acessoIndividual($mid)) {
                return null;
            }
        }
        return $registro;
    }

    /**
     * Aplica acessoIndividual() a um registro já carregado: devolve o próprio registro ou null.
     * O contrato vem de `metadados_id`; se vazio e houver `colaborador_id` (fluxos antigos), do vínculo
     * do colaborador local (`colaboradores.metadados_id`). Sem vínculo nenhum → o registro passa.
     */
    public static function filtrarRegistroIndividual(?array $registro): ?array
    {
        if ($registro === null) {
            return null;
        }
        $metadadosId = isset($registro['metadados_id']) && $registro['metadados_id'] !== null ? (int)$registro['metadados_id'] : null;
        if ($metadadosId === null && !empty($registro['colaborador_id'])) {
            $stmt = Database::conn()->prepare('SELECT metadados_id FROM colaboradores WHERE id = ?');
            $stmt->execute([(int)$registro['colaborador_id']]);
            $valor = $stmt->fetchColumn();
            $metadadosId = ($valor !== false && $valor !== null) ? (int)$valor : null;
        }
        return self::acessoIndividual($metadadosId) ? $registro : null;
    }

    /** Mensagem de auditoria desta requisição (Admin consultando registro excluído), ou null. */
    public static function avisoAuditoria(): ?string
    {
        return self::$avisoAuditoria;
    }

    /** Esta requisição já ocultou algum registro por exclusão analítica? (testes/diagnóstico) */
    public static function ocultadoNaRequisicao(): bool
    {
        return self::$ocultadoNaRequisicao;
    }

    /** Zera o estado por requisição (testes; em produção cada requisição é um processo novo). */
    public static function reiniciarEstadoDaRequisicao(): void
    {
        self::$avisoAuditoria = null;
        self::$ocultadoNaRequisicao = false;
    }

    /** Contrato (identificado pelo id interno atual) está excluído das estatísticas? */
    public static function estaExcluido(int $metadadosId): bool
    {
        if (self::modoSchema() === self::MODO_LEGADO) {
            $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM ' . self::TABELA . ' WHERE metadados_id = ?');
            $stmt->execute([$metadadosId]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $vinculo = self::vinculoPorMetadadosId($metadadosId);
        return $vinculo !== null
            && self::estaExcluidoPorVinculo($vinculo['codigo_empresa'], $vinculo['codigo_unidade'], $vinculo['numero_contrato']);
    }

    /** O vínculo (chave natural completa) está excluído das estatísticas? */
    public static function estaExcluidoPorVinculo(string $codigoEmpresa, string $codigoUnidade, string $numeroContrato): bool
    {
        self::exigirModoNatural();
        $stmt = Database::conn()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABELA . ' WHERE codigo_empresa = ? AND codigo_unidade = ? AND numero_contrato = ?'
        );
        $stmt->execute([$codigoEmpresa, $codigoUnidade, $numeroContrato]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Compatibilidade: identifica o contrato pelo id interno ATUAL, resolve a chave natural e
     * registra a exclusão por ela (o id interno em si nunca é gravado como critério).
     *
     * @param int|null $usuarioId Quem excluiu — nulo só é aceitável em contexto de manutenção sem
     *                            usuário autenticado; uma futura tela administrativa deve sempre
     *                            passar o usuário da sessão.
     * @return array{ok:bool,error?:string}
     */
    public static function excluir(int $metadadosId, string $motivo, ?int $usuarioId): array
    {
        $vinculo = self::vinculoPorMetadadosId($metadadosId);
        if ($vinculo === null) {
            return ['ok' => false, 'error' => 'Contrato não encontrado em colaboradores_metadados.'];
        }
        return self::excluirPorVinculo($vinculo['codigo_empresa'], $vinculo['codigo_unidade'], $vinculo['numero_contrato'], $motivo, $usuarioId);
    }

    /**
     * Registra a exclusão analítica de UM vínculo, pela chave natural. Valida que o vínculo existe
     * em colaboradores_metadados, que o motivo não é vazio e que ainda não está excluído. Nunca
     * altera nem apaga o contrato original.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function excluirPorVinculo(
        string $codigoEmpresa,
        string $codigoUnidade,
        string $numeroContrato,
        string $motivo,
        ?int $usuarioId
    ): array {
        $erroSchema = self::erroSeSchemaIncompativelParaEscrita();
        if ($erroSchema !== null) {
            return ['ok' => false, 'error' => $erroSchema];
        }

        $codigoEmpresa = trim($codigoEmpresa);
        $codigoUnidade = trim($codigoUnidade);
        $numeroContrato = trim($numeroContrato);
        $motivo = trim($motivo);
        if ($codigoEmpresa === '' || $codigoUnidade === '' || $numeroContrato === '') {
            return ['ok' => false, 'error' => 'Informe empresa, unidade e contrato (chave natural completa).'];
        }
        if ($motivo === '') {
            return ['ok' => false, 'error' => 'Informe o motivo da exclusão analítica.'];
        }

        $pdo = Database::conn();
        $existe = $pdo->prepare(
            'SELECT COUNT(*) FROM colaboradores_metadados WHERE codigo_empresa = ? AND codigo_unidade = ? AND numero_contrato = ?'
        );
        $existe->execute([$codigoEmpresa, $codigoUnidade, $numeroContrato]);
        if ((int)$existe->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'Contrato não encontrado em colaboradores_metadados.'];
        }

        if (self::estaExcluidoPorVinculo($codigoEmpresa, $codigoUnidade, $numeroContrato)) {
            return ['ok' => false, 'error' => 'Este contrato já está excluído das estatísticas.'];
        }

        try {
            // metadados_id fica NULL de propósito: a identidade é a chave natural; um id interno
            // gravado aqui ficaria defasado numa reconstrução da base.
            $pdo->prepare(
                'INSERT INTO ' . self::TABELA . ' (codigo_empresa, codigo_unidade, numero_contrato, motivo, excluido_por_usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$codigoEmpresa, $codigoUnidade, $numeroContrato, $motivo, $usuarioId]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) { // corrida: outra requisição inseriu a mesma chave
                return ['ok' => false, 'error' => 'Este contrato já está excluído das estatísticas.'];
            }
            throw $e;
        }

        return ['ok' => true];
    }

    /** Compatibilidade: reverte a exclusão do contrato identificado pelo id interno atual. */
    public static function incluir(int $metadadosId): void
    {
        self::exigirModoNatural();
        $vinculo = self::vinculoPorMetadadosId($metadadosId);
        if ($vinculo !== null) {
            self::incluirPorVinculo($vinculo['codigo_empresa'], $vinculo['codigo_unidade'], $vinculo['numero_contrato']);
        }
    }

    /**
     * Reverte a exclusão (o contrato volta a participar normalmente das estatísticas). Remove só a
     * marca — nunca o contrato.
     *
     * @return bool true se havia uma exclusão para esse vínculo e ela foi removida.
     */
    public static function incluirPorVinculo(string $codigoEmpresa, string $codigoUnidade, string $numeroContrato): bool
    {
        self::exigirModoNatural();
        $stmt = Database::conn()->prepare(
            'DELETE FROM ' . self::TABELA . ' WHERE codigo_empresa = ? AND codigo_unidade = ? AND numero_contrato = ?'
        );
        $stmt->execute([trim($codigoEmpresa), trim($codigoUnidade), trim($numeroContrato)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resolve o identificador oficial `empresa-unidade-contrato` (colaboradores_metadados.identificador)
     * para o vínculo, validando a existência e a IDENTIDADE: o identificador precisa ser único e
     * coerente com a chave natural gravada na mesma linha.
     *
     * @return array{ok:bool,error?:string,vinculo?:array{id:int,identificador:string,codigo_empresa:string,codigo_unidade:string,numero_contrato:string,nome:string,empresa:?string}}
     */
    public static function resolverIdentificador(string $identificador): array
    {
        $identificador = trim($identificador);
        if ($identificador === '' || !preg_match('/^[A-Za-z0-9]+-[A-Za-z0-9]+-[A-Za-z0-9]+$/', $identificador)) {
            return ['ok' => false, 'error' => 'Identificador inválido. Use o formato empresa-unidade-contrato (ex.: 0002-0002-347).'];
        }

        $stmt = Database::conn()->prepare(
            'SELECT id, identificador, codigo_empresa, codigo_unidade, numero_contrato, nome, empresa
             FROM colaboradores_metadados WHERE identificador = ? LIMIT 2'
        );
        $stmt->execute([$identificador]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($linhas === []) {
            return ['ok' => false, 'error' => 'Nenhum contrato encontrado com o identificador ' . $identificador . '.'];
        }
        if (count($linhas) > 1) {
            return ['ok' => false, 'error' => 'Identificador ambíguo: mais de um contrato encontrado.'];
        }

        $l = $linhas[0];
        $composto = $l['codigo_empresa'] . '-' . $l['codigo_unidade'] . '-' . $l['numero_contrato'];
        if (strcasecmp($composto, (string)$l['identificador']) !== 0) {
            return ['ok' => false, 'error' => 'Identificador inconsistente com a chave natural do contrato; revise antes de excluir.'];
        }

        return ['ok' => true, 'vinculo' => [
            'id' => (int)$l['id'],
            'identificador' => (string)$l['identificador'],
            'codigo_empresa' => (string)$l['codigo_empresa'],
            'codigo_unidade' => (string)$l['codigo_unidade'],
            'numero_contrato' => (string)$l['numero_contrato'],
            'nome' => (string)$l['nome'],
            'empresa' => $l['empresa'] !== null ? (string)$l['empresa'] : null,
        ]];
    }

    /**
     * Para auditoria (nunca usado nos filtros de estatística — esses usam clausulaSqlExclusao()).
     * Casa o contrato ATUAL pela chave natural (LEFT JOIN: uma exclusão sem contrato correspondente
     * continua listada, com nome/empresa nulos). `metadados_id` = id interno atual do contrato;
     * `metadados_id_original` = o que estava gravado na exclusão (referência histórica, pode estar
     * defasada após uma reconstrução da base).
     */
    public static function listarExcluidos(): array
    {
        if (self::modoSchema() === self::MODO_LEGADO) {
            $stmt = Database::conn()->query(
                'SELECT e.id, e.metadados_id, e.metadados_id AS metadados_id_original, e.motivo, e.excluido_por_usuario_id, e.created_at,
                        m.nome, m.numero_contrato, m.empresa, m.identificador,
                        u.nome AS excluido_por_nome
                 FROM ' . self::TABELA . ' e
                 JOIN colaboradores_metadados m ON m.id = e.metadados_id
                 LEFT JOIN usuarios u ON u.id = e.excluido_por_usuario_id
                 ORDER BY e.created_at DESC, e.id DESC'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = Database::conn()->query(
            'SELECT e.id, m.id AS metadados_id, e.metadados_id AS metadados_id_original,
                    e.codigo_empresa, e.codigo_unidade, e.numero_contrato,
                    CONCAT(e.codigo_empresa, \'-\', e.codigo_unidade, \'-\', e.numero_contrato) AS identificador,
                    e.motivo, e.excluido_por_usuario_id, e.created_at,
                    m.nome, m.empresa,
                    u.nome AS excluido_por_nome
             FROM ' . self::TABELA . ' e
             LEFT JOIN colaboradores_metadados m
               ON m.codigo_empresa = e.codigo_empresa AND m.codigo_unidade = e.codigo_unidade AND m.numero_contrato = e.numero_contrato
             LEFT JOIN usuarios u ON u.id = e.excluido_por_usuario_id
             ORDER BY e.created_at DESC, e.id DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{codigo_empresa:string,codigo_unidade:string,numero_contrato:string}|null */
    private static function vinculoPorMetadadosId(int $metadadosId): ?array
    {
        $stmt = Database::conn()->prepare(
            'SELECT codigo_empresa, codigo_unidade, numero_contrato FROM colaboradores_metadados WHERE id = ?'
        );
        $stmt->execute([$metadadosId]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($linha === false) {
            return null;
        }
        return [
            'codigo_empresa' => (string)$linha['codigo_empresa'],
            'codigo_unidade' => (string)$linha['codigo_unidade'],
            'numero_contrato' => (string)$linha['numero_contrato'],
        ];
    }

    /** Alias (ou nome da tabela) + ponto, para ler a chave natural do contrato na query externa. */
    private static function prefixoDaColunaId(string $colunaId): string
    {
        if (!preg_match('/^(?:([A-Za-z_][A-Za-z0-9_]*)\.)?id$/', $colunaId, $m)) {
            throw new InvalidArgumentException("Coluna de id inválida para a cláusula de exclusão analítica: {$colunaId}");
        }
        return (($m[1] ?? '') !== '' ? $m[1] : 'colaboradores_metadados') . '.';
    }

    /** Escrita só é segura com a chave natural: sem ela, devolve a orientação em vez de gravar por id. */
    private static function erroSeSchemaIncompativelParaEscrita(): ?string
    {
        try {
            if (self::modoSchema() === self::MODO_NATURAL) {
                return null;
            }
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return 'A exclusão analítica por chave natural exige a migration ' . self::MIGRATION_CHAVE_NATURAL . ' (ainda não aplicada neste banco).';
    }

    private static function exigirModoNatural(): void
    {
        $erro = self::erroSeSchemaIncompativelParaEscrita();
        if ($erro !== null) {
            throw new RuntimeException($erro);
        }
    }
}
