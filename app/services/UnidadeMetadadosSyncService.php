<?php
/**
 * Sincronização da dimensão UNIDADES a partir do METADADOS (RHUNIDADES, SQL Server) — Fase 5.1A.
 * Ver docs/claude/roadmap-tecnico.md (Estrutura Organizacional — Estratégia B).
 *
 * Mesmo desenho de MetadadosSyncService / EmpresaMetadadosSyncService:
 *   - fetchSourceRows()  -> SELECT no SQL Server (nunca escreve).
 *   - applyRows()        -> upsert puro no MySQL local, testável sem SQL Server.
 *
 * Chave oficial: (codigo_empresa, codigo_unidade). `empresa_id` é resolvido a partir de
 * `codigo_empresa` contra a tabela `empresas` já sincronizada — se a empresa correspondente ainda
 * não existir, a unidade é um ERRO CONTROLADO de integridade (contado em 'errors', reportado, sem
 * abortar o lote). Por isso a ordem canônica de sincronização é empresas -> unidades -> colaboradores.
 */
class UnidadeMetadadosSyncService
{
    private const QUERY = "
        SELECT
            EMPRESA      AS codigo_empresa,
            UNIDADE      AS codigo_unidade,
            DESCRICAO40  AS descricao
        FROM RHUNIDADES
        ORDER BY EMPRESA, UNIDADE
    ";

    private const REQUIRED_FIELDS = ['codigo_empresa', 'codigo_unidade', 'descricao'];

    private UnidadeMetadadosRepository $repository;
    private EmpresaMetadadosRepository $empresaRepository;

    public function __construct(
        ?UnidadeMetadadosRepository $repository = null,
        ?EmpresaMetadadosRepository $empresaRepository = null
    ) {
        $this->repository = $repository ?? new UnidadeMetadadosRepository();
        $this->empresaRepository = $empresaRepository ?? new EmpresaMetadadosRepository($this->repository->connection());
    }

    public function run(): array
    {
        return $this->applyRows($this->fetchSourceRows());
    }

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
            'codigo_empresa' => trim((string)($row['codigo_empresa'] ?? '')),
            'codigo_unidade' => trim((string)($row['codigo_unidade'] ?? '')),
            'descricao' => trim((string)($row['descricao'] ?? '')),
        ];
    }

    /**
     * @param string|null $origem Nulo = resolve via MetadadosDatabase::sourceLabel().
     * @return array{inserted:int, updated:int, unchanged:int, errors:int, error_details:array, origem:string}
     */
    public function applyRows(array $rows, ?string $origem = null): array
    {
        $origem = $origem ?? MetadadosDatabase::sourceLabel();
        $pdo = $this->repository->connection();

        $summary = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'error_details' => [], 'origem' => $origem];

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $vinculo = ($row['codigo_empresa'] ?? '?') . '-' . ($row['codigo_unidade'] ?? '?');
                try {
                    $this->validateRow($row);
                    $codigoEmpresa = (string)$row['codigo_empresa'];
                    $codigoUnidade = (string)$row['codigo_unidade'];
                    $descricao = (string)$row['descricao'];

                    $empresaId = $this->empresaRepository->findIdByCodigo($codigoEmpresa);
                    if ($empresaId === null) {
                        throw new \RuntimeException(
                            "Empresa {$codigoEmpresa} ainda não sincronizada — sincronize empresas antes de unidades."
                        );
                    }

                    $existing = $this->repository->findByCodigo($codigoEmpresa, $codigoUnidade);
                    if ($existing === null) {
                        $this->repository->insert($empresaId, $codigoEmpresa, $codigoUnidade, $descricao, $origem);
                        $summary['inserted']++;
                        continue;
                    }

                    if ((string)($existing['descricao'] ?? '') === $descricao
                        && (int)($existing['empresa_id'] ?? 0) === $empresaId
                        && (string)($existing['origem_metadados'] ?? '') === $origem) {
                        $summary['unchanged']++;
                        continue;
                    }

                    $this->repository->update((int)$existing['id'], $empresaId, $descricao, $origem);
                    $summary['updated']++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = ['vinculo' => $vinculo, 'erro' => $e->getMessage()];
                    Logger::error('Falha ao sincronizar unidade do METADADOS', ['vinculo' => $vinculo, 'erro' => $e->getMessage()]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao aplicar lote de sincronização de unidades do METADADOS', ['erro' => $e->getMessage()]);
            throw $e;
        }

        return $summary;
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
