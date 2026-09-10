<?php
/**
 * Sincronização das dimensões de catálogo SETORES e CARGOS a partir do METADADOS (RHSETORES /
 * RHCARGOS, SQL Server) — Fase 5.2. Uma classe parametrizada pela dimensão; mesmo desenho de
 * EmpresaMetadadosSyncService:
 *   - fetchSourceRows()  -> SELECT no SQL Server (nunca escreve).
 *   - planejar()         -> plano de reconciliação SOMENTE LEITURA (fonte única da decisão de
 *                           adoção; usado pelo dry-run do sender e por applyRows()).
 *   - applyRows()        -> EXECUTA o plano (upsert puro no MySQL local), testável sem SQL Server.
 *
 * Identidade oficial CONFIRMADA (diagnóstico 08/09/2026): `RHSETORES.SETOR` / `RHCARGOS.CARGO` —
 * chave GLOBAL (as tabelas não têm EMPRESA/UNIDADE), string OPACA (nunca convertida para número,
 * ex.: '0120'). Descrição oficial: `DESCRICAO40`. Situação oficial: `ATIVADESATIVADA` — semântica
 * ainda não confirmada, guardada como valor BRUTO em `situacao_metadados`; `ativo` local NÃO é
 * alterado pela sincronização.
 *
 * Adoção conservadora: registro local sem código é adotado só quando exatamente UMA linha local
 * tem o mesmo nome normalizado que a descrição oficial normalizada. 0 ou 2+ nunca adota — insere
 * como registro novo e reporta em `avisos`. Nunca fuzzy.
 */
class CatalogoMetadadosSyncService
{
    private const QUERIES = [
        'setores' => "
            SELECT SETOR AS codigo, DESCRICAO40 AS descricao_oficial, ATIVADESATIVADA AS situacao_oficial
            FROM RHSETORES ORDER BY SETOR
        ",
        'cargos' => "
            SELECT CARGO AS codigo, DESCRICAO40 AS descricao_oficial, ATIVADESATIVADA AS situacao_oficial
            FROM RHCARGOS ORDER BY CARGO
        ",
    ];

    private string $dimensao;
    private CatalogoMetadadosRepository $repository;

    public function __construct(string $dimensao, ?CatalogoMetadadosRepository $repository = null)
    {
        if (!isset(self::QUERIES[$dimensao])) {
            throw new \InvalidArgumentException("Dimensão de catálogo não suportada: {$dimensao}");
        }
        $this->dimensao = $dimensao;
        $this->repository = $repository ?? new CatalogoMetadadosRepository($dimensao);
    }

    public function run(): array
    {
        return $this->applyRows($this->fetchSourceRows());
    }

    public function fetchSourceRows(): array
    {
        $pdo = MetadadosDatabase::conn();
        $stmt = $pdo->query(self::QUERIES[$this->dimensao]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'normalizeSourceRow'], $rows);
    }

    /** Código = string opaca (trim apenas — NUNCA conversão numérica). Situação = valor bruto ou null. */
    public static function normalizeSourceRow(array $row): array
    {
        $situacao = $row['situacao_oficial'] ?? null;
        if ($situacao !== null) {
            $situacao = trim((string)$situacao);
            if ($situacao === '') {
                $situacao = null;
            }
        }
        return [
            'codigo' => trim((string)($row['codigo'] ?? '')),
            'descricao_oficial' => trim((string)($row['descricao_oficial'] ?? '')),
            'situacao_oficial' => $situacao,
        ];
    }

    /**
     * Plano de reconciliação SOMENTE LEITURA. Fonte única da decisão de adoção — applyRows()
     * executa exatamente este plano; o dry-run do sender o exibe. NUNCA duplicar esta lógica.
     *
     * Ações: ERRO | INALTERADA | ATUALIZAR_EXISTENTE | ADOTAR_EXISTENTE | INSERIR_NOVA.
     *
     * @return array{dimensao:string, origem:string, itens:list<array{codigo:string, descricao_oficial:string, situacao_oficial:?string, acao:string, local_id:?int, nome_local:?string, candidatos_ambiguos:int, criterio:string}>, locais_sem_correspondencia:list<array{id:int, nome:string, slug:string, ativo:int, classificacao:string}>}
     */
    public function planejar(array $rows, ?string $origem = null): array
    {
        $origem = $origem ?? MetadadosDatabase::sourceLabel();
        $naoAdotados = $this->repository->naoAdotadosPorNomeNormalizado();
        $idsAdotados = [];
        $itens = [];

        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo'] ?? ''));
            $descricao = trim((string)($row['descricao_oficial'] ?? ''));
            $situacao = isset($row['situacao_oficial']) && $row['situacao_oficial'] !== ''
                ? (string)$row['situacao_oficial'] : null;

            if ($codigo === '' || $descricao === '') {
                $itens[] = [
                    'codigo' => $codigo, 'descricao_oficial' => $descricao, 'situacao_oficial' => $situacao,
                    'acao' => 'ERRO', 'local_id' => null, 'nome_local' => null, 'candidatos_ambiguos' => 0,
                    'criterio' => 'Campo obrigatório ausente: ' . ($codigo === '' ? 'codigo' : 'descricao_oficial'),
                ];
                continue;
            }

            $existing = $this->repository->findByCodigo($codigo);
            if ($existing !== null) {
                $igual = (string)($existing['descricao_oficial'] ?? '') === $descricao
                    && (string)($existing['situacao_metadados'] ?? '') === (string)($situacao ?? '')
                    && (string)($existing['origem_metadados'] ?? '') === $origem;
                $itens[] = [
                    'codigo' => $codigo, 'descricao_oficial' => $descricao, 'situacao_oficial' => $situacao,
                    'acao' => $igual ? 'INALTERADA' : 'ATUALIZAR_EXISTENTE',
                    'local_id' => (int)$existing['id'],
                    'nome_local' => (string)($existing['nome'] ?? ''),
                    'candidatos_ambiguos' => 0,
                    'criterio' => $igual
                        ? 'Já vinculado por código; descrição/situação/origem sem mudança.'
                        : 'Já vinculado por código; descrição, situação ou origem mudou na fonte.',
                ];
                continue;
            }

            $chave = MetadadosTexto::normalizarNome($descricao);
            $candidatos = array_values(array_filter(
                $naoAdotados[$chave] ?? [],
                static fn (array $r) => !in_array((int)$r['id'], $idsAdotados, true)
            ));

            if (count($candidatos) === 1) {
                $idsAdotados[] = (int)$candidatos[0]['id'];
                $itens[] = [
                    'codigo' => $codigo, 'descricao_oficial' => $descricao, 'situacao_oficial' => $situacao,
                    'acao' => 'ADOTAR_EXISTENTE',
                    'local_id' => (int)$candidatos[0]['id'],
                    'nome_local' => (string)$candidatos[0]['nome'],
                    'candidatos_ambiguos' => 0,
                    'criterio' => "Nome local normalizado ('{$chave}') idêntico a exatamente um registro local sem código.",
                ];
                continue;
            }

            $itens[] = [
                'codigo' => $codigo, 'descricao_oficial' => $descricao, 'situacao_oficial' => $situacao,
                'acao' => 'INSERIR_NOVA', 'local_id' => null, 'nome_local' => null,
                'candidatos_ambiguos' => count($candidatos),
                'criterio' => count($candidatos) > 1
                    ? 'Adoção ambígua: ' . count($candidatos) . " registros locais com o nome normalizado ('{$chave}') — nunca adota."
                    : "Nenhum registro local sem código com o nome normalizado ('{$chave}').",
            ];
        }

        $locaisSemCorrespondencia = [];
        foreach ($naoAdotados as $linhas) {
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
            'dimensao' => $this->dimensao,
            'origem' => $origem,
            'itens' => $itens,
            'locais_sem_correspondencia' => $locaisSemCorrespondencia,
        ];
    }

    /**
     * @param string|null $origem Nulo = resolve via MetadadosDatabase::sourceLabel().
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
            $plano = $this->planejar($rows, $origem);

            foreach ($plano['itens'] as $item) {
                $codigo = $item['codigo'];
                try {
                    switch ($item['acao']) {
                        case 'ERRO':
                            throw new \InvalidArgumentException($item['criterio']);
                        case 'INALTERADA':
                            $summary['unchanged']++;
                            break;
                        case 'ATUALIZAR_EXISTENTE':
                            $this->repository->atualizarOficial((int)$item['local_id'], $item['descricao_oficial'], $item['situacao_oficial'], $origem);
                            $summary['updated']++;
                            break;
                        case 'ADOTAR_EXISTENTE':
                            $this->repository->adotar((int)$item['local_id'], $codigo, $item['descricao_oficial'], $item['situacao_oficial'], $origem);
                            $summary['adopted']++;
                            break;
                        case 'INSERIR_NOVA':
                            $this->repository->inserir($codigo, $item['descricao_oficial'], $item['situacao_oficial'], $origem);
                            $summary['inserted']++;
                            if (($item['candidatos_ambiguos'] ?? 0) > 1) {
                                $summary['avisos'][] = "{$this->dimensao}: código {$codigo} ('{$item['descricao_oficial']}') inserido como novo: "
                                    . $item['candidatos_ambiguos'] . ' registros locais têm esse mesmo nome — adoção ambígua, não aplicada.';
                            }
                            break;
                    }
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = ['codigo' => $codigo, 'erro' => $e->getMessage()];
                    Logger::error('Falha ao sincronizar registro de catálogo do METADADOS', [
                        'dimensao' => $this->dimensao, 'codigo' => $codigo, 'erro' => $e->getMessage(),
                    ]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao aplicar lote de sincronização de catálogo do METADADOS', [
                'dimensao' => $this->dimensao, 'erro' => $e->getMessage(),
            ]);
            throw $e;
        }

        foreach ($plano['locais_sem_correspondencia'] as $linha) {
            $summary['avisos'][] = "{$this->dimensao}: registro local '{$linha['nome']}' (id {$linha['id']}) não adotado: "
                . 'nenhum registro do METADADOS tem esse nome exato.';
        }

        return $summary;
    }
}
