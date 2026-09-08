<?php
/**
 * Sincronização da dimensão EMPRESAS a partir do METADADOS (RHEMPRESAS, SQL Server) — Fase 5.1A.
 * Ver docs/claude/roadmap-tecnico.md (Estrutura Organizacional — Estratégia B).
 *
 * Mesmo desenho de MetadadosSyncService (colaboradores):
 *   - fetchSourceRows()  -> SELECT no SQL Server (exige pdo_sqlsrv + conectividade). NUNCA escreve.
 *   - planejar()         -> plano de reconciliação SOMENTE LEITURA (fonte única da decisão de
 *                           adoção; usado pelo dry-run do sender e por applyRows()).
 *   - applyRows()        -> EXECUTA o plano (upsert puro no MySQL local), testável sem SQL Server.
 *
 * Identidade oficial é `codigo_empresa` (RHEMPRESAS.EMPRESA). Na PRIMEIRA sincronização, uma
 * empresa local sem `codigo_empresa` é ADOTADA (recebe o código) se — e só se — exatamente uma
 * empresa do METADADOS tem o mesmo nome normalizado; isso preserva as FKs legadas que já apontam
 * para `empresas.id`. Ambiguidade (0 ou 2+ matches) NUNCA adota — insere como empresa nova e
 * reporta em `avisos` para revisão manual. Nome nunca é a identidade depois disso.
 *
 * `ativo` de `empresas` NÃO é alterado pela sincronização nesta fase — o status oficial de
 * RHEMPRESAS ainda não foi confirmado (pendência registrada no roadmap). Empresa nova entra
 * com ativo = 1.
 */
class EmpresaMetadadosSyncService
{
    /**
     * Só EMPRESA + RAZAOSOCIAL — as duas colunas confirmadas de RHEMPRESAS (já usadas no JOIN da
     * query de colaboradores). Se/quando houver uma coluna oficial de status confirmada por
     * schema/SELECT read-only, ela entra aqui.
     */
    private const QUERY = "
        SELECT
            EMPRESA      AS codigo_empresa,
            RAZAOSOCIAL  AS razao_social
        FROM RHEMPRESAS
        ORDER BY EMPRESA
    ";

    private const REQUIRED_FIELDS = ['codigo_empresa', 'razao_social'];

    private EmpresaMetadadosRepository $repository;

    public function __construct(?EmpresaMetadadosRepository $repository = null)
    {
        $this->repository = $repository ?? new EmpresaMetadadosRepository();
    }

    public function run(): array
    {
        return $this->applyRows($this->fetchSourceRows());
    }

    /**
     * Exige pdo_sqlsrv + conectividade real com o METADADOS (via MetadadosDatabase::conn()).
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
            'codigo_empresa' => trim((string)($row['codigo_empresa'] ?? '')),
            'razao_social' => trim((string)($row['razao_social'] ?? '')),
        ];
    }

    /**
     * Upsert puro (nunca DELETE — uma empresa que sai de RHEMPRESAS não é removida do Portal).
     * Uma linha inválida é contada em 'errors' e não aborta o lote.
     *
     * @param string|null $origem Rótulo da origem (Database= do DSN). Nulo = resolve via
     *                            MetadadosDatabase::sourceLabel(). Testes injetam valor explícito.
     * @return array{inserted:int, updated:int, unchanged:int, adopted:int, errors:int, error_details:array, avisos:array, origem:string}
     */
    public function applyRows(array $rows, ?string $origem = null): array
    {
        $origem = $origem ?? MetadadosDatabase::sourceLabel();
        $pdo = $this->repository->connection();

        $summary = [
            'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'adopted' => 0,
            'errors' => 0, 'error_details' => [], 'avisos' => [], 'origem' => $origem,
        ];
        $plano = ['itens' => [], 'locais_sem_correspondencia' => []];

        $pdo->beginTransaction();
        try {
            // O plano é a MESMA decisão que a prévia do dry-run enxerga — calculado aqui dentro da
            // transação para refletir o estado atual do banco. applyRows() só EXECUTA o plano.
            $plano = $this->planejar($rows, $origem);

            foreach ($plano['itens'] as $item) {
                $codigo = $item['codigo_empresa'];
                try {
                    switch ($item['acao']) {
                        case 'ERRO':
                            throw new \InvalidArgumentException($item['criterio']);
                        case 'INALTERADA':
                            $summary['unchanged']++;
                            break;
                        case 'ATUALIZAR_EXISTENTE':
                            $this->repository->atualizarOficial((int)$item['empresa_local_id'], $item['razao_social'], $origem);
                            $summary['updated']++;
                            break;
                        case 'ADOTAR_EXISTENTE':
                            $this->repository->adotar((int)$item['empresa_local_id'], $codigo, $item['razao_social'], $origem);
                            $summary['adopted']++;
                            break;
                        case 'INSERIR_NOVA':
                            $this->repository->inserir($codigo, $item['razao_social'], $origem);
                            $summary['inserted']++;
                            if (($item['candidatos_ambiguos'] ?? 0) > 1) {
                                $summary['avisos'][] = "Empresa {$codigo} ('{$item['razao_social']}') inserida como nova: "
                                    . $item['candidatos_ambiguos'] . ' empresas locais têm esse mesmo nome — adoção ambígua, não aplicada.';
                            }
                            break;
                    }
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = ['codigo_empresa' => $codigo, 'erro' => $e->getMessage()];
                    Logger::error('Falha ao sincronizar empresa do METADADOS', ['codigo_empresa' => $codigo, 'erro' => $e->getMessage()]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao aplicar lote de sincronização de empresas do METADADOS', ['erro' => $e->getMessage()]);
            throw $e;
        }

        foreach ($plano['locais_sem_correspondencia'] as $linha) {
            $summary['avisos'][] = "Empresa local '{$linha['nome']}' (id {$linha['id']}) não adotada: "
                . 'nenhuma empresa do METADADOS tem esse nome exato.';
        }

        return $summary;
    }

    /**
     * Plano de reconciliação SOMENTE LEITURA entre `$rows` (RHEMPRESAS) e a tabela `empresas`
     * local. Não escreve nada. É a fonte única da decisão de adoção — applyRows() executa
     * exatamente este plano, e o dry-run do sender o exibe. NUNCA duplicar esta lógica.
     *
     * Ações possíveis por item:
     *   ERRO                -> linha inválida (codigo_empresa/razao_social vazio)
     *   INALTERADA          -> já vinculada por codigo_empresa, razão social/origem iguais
     *   ATUALIZAR_EXISTENTE -> já vinculada por codigo_empresa, razão social/origem mudou
     *   ADOTAR_EXISTENTE    -> não vinculada; exatamente 1 empresa local sem código com nome
     *                          normalizado idêntico (adoção conservadora)
     *   INSERIR_NOVA        -> não vinculada; 0 correspondências OU 2+ (ambiguidade nunca adota)
     *
     * @return array{origem:string, itens:list<array{codigo_empresa:string, razao_social:string, acao:string, empresa_local_id:?int, nome_local:?string, criterio:string, candidatos_ambiguos:int}>, locais_sem_correspondencia:list<array{id:int, nome:string, slug:string, ativo:int, classificacao:string}>}
     */
    public function planejar(array $rows, ?string $origem = null): array
    {
        $origem = $origem ?? MetadadosDatabase::sourceLabel();
        $naoAdotadas = $this->repository->naoAdotadasPorNomeNormalizado();
        $idsAdotados = [];
        $itens = [];

        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo_empresa'] ?? ''));
            $razao = trim((string)($row['razao_social'] ?? ''));

            $faltando = null;
            foreach (self::REQUIRED_FIELDS as $field) {
                if (trim((string)($row[$field] ?? '')) === '') {
                    $faltando = $field;
                    break;
                }
            }
            if ($faltando !== null) {
                $itens[] = [
                    'codigo_empresa' => $codigo, 'razao_social' => $razao, 'acao' => 'ERRO',
                    'empresa_local_id' => null, 'nome_local' => null, 'candidatos_ambiguos' => 0,
                    'criterio' => "Campo obrigatório ausente: {$faltando}",
                ];
                continue;
            }

            $existing = $this->repository->findByCodigo($codigo);
            if ($existing !== null) {
                $igual = (string)($existing['razao_social'] ?? '') === $razao
                    && (string)($existing['origem_metadados'] ?? '') === $origem;
                $itens[] = [
                    'codigo_empresa' => $codigo, 'razao_social' => $razao,
                    'acao' => $igual ? 'INALTERADA' : 'ATUALIZAR_EXISTENTE',
                    'empresa_local_id' => (int)$existing['id'],
                    'nome_local' => (string)($existing['nome'] ?? ''),
                    'candidatos_ambiguos' => 0,
                    'criterio' => $igual
                        ? 'Já vinculada por codigo_empresa; razão social e origem sem mudança.'
                        : 'Já vinculada por codigo_empresa; razão social ou origem mudou na fonte.',
                ];
                continue;
            }

            $chave = EmpresaMetadadosRepository::normalizarNome($razao);
            $candidatos = array_values(array_filter(
                $naoAdotadas[$chave] ?? [],
                static fn (array $e) => !in_array((int)$e['id'], $idsAdotados, true)
            ));

            if (count($candidatos) === 1) {
                $idsAdotados[] = (int)$candidatos[0]['id'];
                $itens[] = [
                    'codigo_empresa' => $codigo, 'razao_social' => $razao, 'acao' => 'ADOTAR_EXISTENTE',
                    'empresa_local_id' => (int)$candidatos[0]['id'],
                    'nome_local' => (string)$candidatos[0]['nome'],
                    'candidatos_ambiguos' => 0,
                    'criterio' => "Nome local normalizado ('{$chave}') idêntico a exatamente uma empresa local sem codigo_empresa.",
                ];
                continue;
            }

            $itens[] = [
                'codigo_empresa' => $codigo, 'razao_social' => $razao, 'acao' => 'INSERIR_NOVA',
                'empresa_local_id' => null, 'nome_local' => null,
                'candidatos_ambiguos' => count($candidatos),
                'criterio' => count($candidatos) > 1
                    ? 'Adoção ambígua: ' . count($candidatos) . " empresas locais com o nome normalizado ('{$chave}') — nunca adota."
                    : "Nenhuma empresa local sem codigo_empresa com o nome normalizado ('{$chave}').",
            ];
        }

        $locaisSemCorrespondencia = [];
        foreach ($naoAdotadas as $linhas) {
            foreach ($linhas as $linha) {
                if (in_array((int)$linha['id'], $idsAdotados, true)) {
                    continue;
                }
                $locaisSemCorrespondencia[] = [
                    'id' => (int)$linha['id'],
                    'nome' => (string)$linha['nome'],
                    'slug' => (string)($linha['slug'] ?? ''),
                    'ativo' => (int)($linha['ativo'] ?? 0),
                    'classificacao' => 'LOCAL_SEM_CORRESPONDENCIA_OFICIAL',
                ];
            }
        }

        return [
            'origem' => $origem,
            'itens' => $itens,
            'locais_sem_correspondencia' => $locaisSemCorrespondencia,
        ];
    }
}
