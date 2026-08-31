<?php
/**
 * Sincronização Portal RH ↔ METADADOS (SQL Server, sistema oficial de RH/DP).
 *
 * Fase 1 da integração (ver auditoria da sprint): só consome e espelha em
 * colaboradores_metadados. Não cria FK nova, não altera `colaboradores`, não é chamada durante
 * navegação normal do Portal — só por scripts/sync_metadados_colaboradores.php (CLI, agendado
 * externamente).
 *
 * fetchSourceRows() e applyRows() são separados de propósito: fetchSourceRows() exige o driver
 * pdo_sqlsrv e conectividade real com o METADADOS; applyRows() é pura lógica de upsert em MySQL
 * e pode ser testada sem SQL Server, injetando linhas já normalizadas (ver
 * tests/php/integration_colaborador_metadados_sync.php).
 *
 * Proteção contra mistura silenciosa de origem (RHTESTE vs RHMADEPLANT, ver
 * docs/claude/roadmap-tecnico.md): toda linha grava `origem_metadados` (o `Database=` do DSN
 * ativo, via MetadadosDatabase::sourceLabel()); applyRows() recusa sincronizar uma origem
 * diferente da já predominante no espelho, a menos que `--permitir-origem-mista` seja explícito.
 */
class MetadadosSyncService
{
    /**
     * Cada linha representa um CONTRATO (RHCONTRATOS), não uma pessoa — ver §7 da auditoria.
     * Motivo de rescisão traz código + descrição via RHMOTIVOSRESCISOES (confirmado com o time
     * do METADADOS). CPF confirmado como RHPESSOAS.CPF.
     *
     * JOIN de RHCENTROSCUSTO1 é só por CENTROCUSTO1 (sem UNIDADE) — validado contra dados reais
     * em RHTESTE: RHCENTROSCUSTO1.UNIDADE veio vazio para as linhas existentes, então exigir
     * UNIDADE == UNIDADE zerava 100% dos matches (0/30), enquanto casar só por CENTROCUSTO1
     * encontrou 30/30. Amostra pequena (só 2 linhas na tabela) — reconfirmar contra produção
     * antes de tratar como definitivo. RHSETORES está vazia em RHTESTE (0 linhas) e
     * RHCONTRATOS.SETOR veio em branco nos 40 contratos lidos — não há evidência de bug de JOIN
     * aqui, mas também não há como validar o JOIN de setor nesta base; mesma ressalva.
     *
     * Todos os 7 JOINs acima foram revalidados em 2026-08-27 contra o banco real RHMADEPLANT
     * (727 contratos) com 100% de correspondência técnica em cada um — a ressalva de amostra
     * pequena do parágrafo anterior está resolvida, nenhuma correção pendente.
     *
     * salario_atual e data_inicio_cargo (Fase 3.1) vêm de RHCONTRATOS.SALARIOCONTRATUAL e
     * RHCONTRATOS.DATAULTALTCARGO — sem JOIN adicional, mesma tabela já consultada. Ver
     * investigação dedicada: SALARIOMES é numericamente idêntico a SALARIOCONTRATUAL em 100%
     * dos 725 contratos preenchidos em RHMADEPLANT, mas SALARIOCONTRATUAL foi escolhido por
     * representar semanticamente o salário-base contratual, nunca total recebido no mês.
     * DATAULTALTCARGO nunca cai em fallback para admissao — são conceitos diferentes.
     */
    private const QUERY = "
        SELECT
            CONCAT(ctr.EMPRESA, '-', ctr.UNIDADE, '-', ctr.CONTRATO) AS identificador,
            ctr.EMPRESA                                    AS codigo_empresa,
            ctr.UNIDADE                                    AS codigo_unidade,
            ctr.CONTRATO                                   AS numero_contrato,
            ctr.PESSOA                                      AS codigo_pessoa,
            pes.CPF                                        AS cpf,
            pes.NOME                                       AS nome,
            emp.RAZAOSOCIAL                                AS empresa,
            CONVERT(char(10), pes.NASCIMENTO, 23)          AS nascimento,
            CONVERT(char(10), ctr.DATAADMISSAO, 23)        AS admissao,
            cargo.DESCRICAO40                              AS cargo,
            CONVERT(char(10), ctr.DATARESCISAO, 23)        AS demissao,
            ctr.MOTIVORESCISAO                             AS motivo_rescisao_codigo,
            mot.DESCRICAO40                                AS motivo_rescisao_descricao,
            unid.DESCRICAO40                               AS unidade,
            setor.DESCRICAO40                               AS setor,
            cc.DESCRICAO40                                  AS centro_custo,
            CASE WHEN ctr.DATARESCISAO IS NULL THEN 1 ELSE 0 END AS ativo,
            ctr.SALARIOCONTRATUAL                          AS salario_atual,
            CONVERT(char(10), ctr.DATAULTALTCARGO, 23)     AS data_inicio_cargo
        FROM RHCONTRATOS ctr
        INNER JOIN RHPESSOAS pes ON pes.EMPRESA = ctr.EMPRESA AND pes.PESSOA = ctr.PESSOA
        INNER JOIN RHEMPRESAS emp ON emp.EMPRESA = ctr.EMPRESA
        LEFT JOIN RHUNIDADES unid ON unid.EMPRESA = ctr.EMPRESA AND unid.UNIDADE = ctr.UNIDADE
        LEFT JOIN RHCARGOS cargo ON cargo.CARGO = ctr.CARGO
        LEFT JOIN RHSETORES setor ON setor.SETOR = ctr.SETOR
        LEFT JOIN RHCENTROSCUSTO1 cc ON cc.CENTROCUSTO1 = ctr.CENTROCUSTO1
        LEFT JOIN RHMOTIVOSRESCISOES mot ON mot.MOTIVORESCISAO = ctr.MOTIVORESCISAO
        ORDER BY pes.NOME
    ";

    private const REQUIRED_FIELDS = ['codigo_empresa', 'codigo_unidade', 'numero_contrato', 'codigo_pessoa', 'nome'];

    private ColaboradorMetadadosRepository $repository;

    public function __construct(?ColaboradorMetadadosRepository $repository = null)
    {
        $this->repository = $repository ?? new ColaboradorMetadadosRepository();
    }

    /**
     * @param bool $permitirOrigemMista Ver applyRows() — só passe true sabendo exatamente por quê.
     */
    public function run(bool $permitirOrigemMista = false): array
    {
        $rows = $this->fetchSourceRows();
        return $this->applyRows($rows, null, $permitirOrigemMista);
    }

    /**
     * Exige pdo_sqlsrv + conectividade real com o METADADOS. Lança RuntimeException clara
     * (via MetadadosDatabase::conn()) se o driver ou a configuração estiverem ausentes.
     */
    public function fetchSourceRows(): array
    {
        $pdo = MetadadosDatabase::conn();
        $stmt = $pdo->query(self::QUERY);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'normalizeSourceRow'], $rows);
    }

    public static function normalizeSourceRow(array $row): array
    {
        return [
            'identificador' => (string)($row['identificador'] ?? ''),
            'codigo_empresa' => (string)($row['codigo_empresa'] ?? ''),
            'codigo_unidade' => (string)($row['codigo_unidade'] ?? ''),
            'numero_contrato' => (string)($row['numero_contrato'] ?? ''),
            'codigo_pessoa' => (string)($row['codigo_pessoa'] ?? ''),
            'cpf' => $row['cpf'] ?? null,
            'nome' => (string)($row['nome'] ?? ''),
            'empresa' => $row['empresa'] ?? null,
            'nascimento' => $row['nascimento'] ?? null,
            'admissao' => $row['admissao'] ?? null,
            'cargo' => $row['cargo'] ?? null,
            'demissao' => $row['demissao'] ?? null,
            'motivo_rescisao_codigo' => $row['motivo_rescisao_codigo'] ?? null,
            'motivo_rescisao_descricao' => $row['motivo_rescisao_descricao'] ?? null,
            'unidade' => $row['unidade'] ?? null,
            'setor' => $row['setor'] ?? null,
            'centro_custo' => $row['centro_custo'] ?? null,
            'ativo' => array_key_exists('ativo', $row) ? (int)$row['ativo'] : null,
            'salario_atual' => isset($row['salario_atual']) && $row['salario_atual'] !== '' ? (string)$row['salario_atual'] : null,
            'data_inicio_cargo' => $row['data_inicio_cargo'] ?? null,
            'atualizado_em_origem' => $row['atualizado_em_origem'] ?? null,
        ];
    }

    /**
     * Upsert puro (nunca DELETE — vínculos históricos nunca são removidos, ver §11/§7 da
     * auditoria). Uma linha inválida não aborta o lote inteiro: é contada em 'errors' e
     * registrada via Logger, o restante do lote continua.
     *
     * Proteção contra mistura silenciosa de origem (ver docs/claude/roadmap-tecnico.md, missão
     * corretiva "Pureza da base analítica METADADOS"): antes de escrever qualquer linha, verifica
     * se o espelho já contém registros de uma origem DIFERENTE da desta sincronização
     * (`$origem`). Se sim, a sincronização inteira é recusada — nenhuma linha é escrita — a menos
     * que `$permitirOrigemMista` seja explicitamente true. Isso é o que teria impedido os 6
     * contratos de RHTESTE de ficarem misturados aos 727 de RHMADEPLANT sem que ninguém soubesse.
     *
     * @param string|null $origem Rótulo da origem desta sincronização. Nulo = resolve
     *                            automaticamente via MetadadosDatabase::sourceLabel() (o
     *                            `Database=` do DSN ativo). Testes injetam um valor explícito
     *                            para não depender do local.php do ambiente.
     * @return array{inserted:int, updated:int, unchanged:int, errors:int, error_details:array, origem:string}
     */
    public function applyRows(array $rows, ?string $origem = null, bool $permitirOrigemMista = false): array
    {
        $origem = $origem ?? MetadadosDatabase::sourceLabel();
        $pdo = $this->repository->connection();

        if (!$permitirOrigemMista) {
            $conflito = $this->originConflict($origem, $pdo);
            if ($conflito !== []) {
                $detalhe = [];
                foreach ($conflito as $outraOrigem => $quantidade) {
                    $detalhe[] = "{$outraOrigem} ({$quantidade} registro(s))";
                }
                throw new \RuntimeException(
                    "Sincronização bloqueada: o espelho colaboradores_metadados já contém registros de outra origem — "
                    . implode(', ', $detalhe) . " — e esta sincronização é de origem '{$origem}'. "
                    . 'Misturar origens sem saber pode contaminar a base analítica oficial (ver docs/claude/roadmap-tecnico.md). '
                    . 'Use --permitir-origem-mista apenas se você entende exatamente o impacto.'
                );
            }
        }

        $summary = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'error_details' => [], 'origem' => $origem];

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $vinculo = ($row['codigo_empresa'] ?? '?') . '-' . ($row['codigo_unidade'] ?? '?') . '-' . ($row['numero_contrato'] ?? '?');
                try {
                    $this->validateRow($row);
                    $result = $this->repository->upsert($row, $origem);
                    $summary[$result]++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = ['vinculo' => $vinculo, 'erro' => $e->getMessage()];
                    Logger::error('Falha ao sincronizar vínculo do METADADOS', [
                        'vinculo' => $vinculo,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao aplicar lote de sincronização do METADADOS', ['erro' => $e->getMessage()]);
            throw $e;
        }

        return $summary;
    }

    /**
     * @return array<string,int> Origem => quantidade de registros, para toda origem PRESENTE no
     *                           espelho que seja diferente de `$origemAtual` (nunca inclui
     *                           `$origemAtual` nem origem vazia/legada). Array vazio = sem
     *                           conflito — sincronizar é seguro.
     */
    public function originConflict(string $origemAtual, ?\PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->repository->connection();
        $stmt = $pdo->prepare(
            "SELECT origem_metadados, COUNT(*) AS quantidade
             FROM colaboradores_metadados
             WHERE origem_metadados <> '' AND origem_metadados <> ?
             GROUP BY origem_metadados"
        );
        $stmt->execute([$origemAtual]);

        $conflitos = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $conflitos[(string)$row['origem_metadados']] = (int)$row['quantidade'];
        }
        return $conflitos;
    }

    private function validateRow(array $row): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string)($row[$field] ?? '')) === '') {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }
    }
}
