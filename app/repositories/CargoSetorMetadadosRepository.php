<?php

/**
 * Matriz OFICIAL Cargo x Setor da Solicitação de Vaga — espelho técnico do METADADOS
 * (`cargo_setores_metadados`), não um cadastro manual do Portal. Sem CRUD: RH/Admin não editam
 * esta relação pelo Portal — se faltar vínculo, a correção é na fonte oficial (METADADOS).
 *
 * Fonte, por prioridade (nunca misturadas — ver migration 2026-09-14-cargo-setores-metadados.sql):
 *   1. RHQUADROLOTCARGO (METADADOS), quando estiver populada;
 *   2. combinações distintas do histórico completo de RHCONTRATOS, enquanto RHQUADROLOTCARGO
 *      estiver vazia (carga inicial desta sprint).
 *
 * Deliberadamente independente da tabela LEGADA `cargo_setores` (importação manual anterior à
 * integração oficial) — esta classe nunca lê nem escreve nela.
 *
 * Mesma geração de CatalogoMetadadosRepository: construtor `?PDO`, 100% prepared statements.
 */
class CargoSetorMetadadosRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    /**
     * Cargos oficiais ativos vinculados a um Setor na matriz. Lista vazia = nenhum Cargo oficial
     * associado àquele Setor no METADADOS (a UI/backend devem tratar isso como bloqueio, nunca
     * como "mostrar todos os Cargos").
     *
     * @return array<int, array{id:int, nome:string, salario_min:float, salario_max:float, requires_machine_description:bool}>
     */
    public function cargosPorSetor(int $setorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.nome, c.descricao_oficial,
                    COALESCE(fs.salario_min, 0) AS salario_min,
                    COALESCE(fs.salario_max, 0) AS salario_max
             FROM cargo_setores_metadados csm
             INNER JOIN cargos c ON c.id = csm.cargo_id
             LEFT JOIN cargo_faixas_salariais fs ON fs.cargo_id = c.id AND fs.ativo = 1
             WHERE csm.setor_id = ? AND c.ativo = 1
             ORDER BY COALESCE(NULLIF(c.descricao_oficial, ''), c.nome) ASC"
        );
        $stmt->execute([$setorId]);

        return array_map(static function (array $row): array {
            $rotulo = trim((string)($row['descricao_oficial'] ?? '')) !== ''
                ? (string)$row['descricao_oficial']
                : (string)$row['nome'];
            return [
                'id' => (int)$row['id'],
                'nome' => $rotulo,
                'salario_min' => (float)$row['salario_min'],
                'salario_max' => (float)$row['salario_max'],
                'requires_machine_description' => SolicitacaoVaga::cargoRequiresMachine($rotulo),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mapa `setor_id => cargos` (mesmo formato de cargosPorSetor) para vários Setores de uma vez —
     * usado para montar o contexto do solicitante (um por Setor de atuação dele).
     *
     * @param int[] $setorIds
     * @return array<int, array<int, array{id:int, nome:string, salario_min:float, salario_max:float, requires_machine_description:bool}>>
     */
    public function cargosPorSetores(array $setorIds): array
    {
        $mapa = [];
        foreach (array_unique(array_map('intval', $setorIds)) as $setorId) {
            $mapa[$setorId] = $this->cargosPorSetor($setorId);
        }
        return $mapa;
    }

    /** Validação de backend: o par (Cargo, Setor) existe na matriz oficial? */
    public function existeVinculo(int $cargoId, int $setorId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM cargo_setores_metadados WHERE cargo_id = ? AND setor_id = ? LIMIT 1'
        );
        $stmt->execute([$cargoId, $setorId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Materializa pares Cargo x Setor (por CÓDIGO oficial, extraídos do RHCONTRATOS FORA desta
     * classe — nunca acessa SQL Server aqui) na matriz oficial `cargo_setores_metadados`.
     * Correção 2026-10 (caso recorrente: MANUTENÇÃO/código 12, RHQUADROLOTCARGO não é fonte
     * utilizável na Madeplant) — generaliza para script re-executável a mesma lógica da carga
     * inicial de 2026-09-14 (ver database/migrations/2026-09-14-cargo-setores-metadados-seed.sql).
     *
     * `INSERT IGNORE` sobre a PK composta: idempotente, nunca duplica, NUNCA apaga uma relação já
     * existente (de qualquer origem). Só grava quando AMBOS os códigos já existem no catálogo
     * local ativo (`cargos.codigo_cargo`, `setores.codigo_setor`) — nunca cria Cargo/Setor por
     * nome, nunca aceita código desconhecido.
     *
     * @param array<int, array{codigo_cargo:string, codigo_setor:string}> $pares
     * @return array{inseridos:int, ja_existentes:int, sem_correspondencia_local:int}
     */
    public function materializarPorCodigos(array $pares, string $origemMetadados): array
    {
        $inseridos = 0;
        $jaExistentes = 0;
        $semCorrespondencia = 0;

        $buscarCargo = $this->pdo->prepare('SELECT id FROM cargos WHERE codigo_cargo = ? AND ativo = 1 LIMIT 1');
        $buscarSetor = $this->pdo->prepare('SELECT id FROM setores WHERE codigo_setor = ? AND ativo = 1 LIMIT 1');
        $upsert = $this->pdo->prepare(
            'INSERT IGNORE INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em) VALUES (?, ?, ?, NOW())'
        );

        $vistos = [];
        foreach ($pares as $par) {
            $codigoCargo = trim((string)($par['codigo_cargo'] ?? ''));
            $codigoSetor = trim((string)($par['codigo_setor'] ?? ''));
            if ($codigoCargo === '' || $codigoSetor === '') {
                continue;
            }
            $chave = $codigoCargo . '|' . $codigoSetor;
            if (isset($vistos[$chave])) {
                continue; // dedup dentro do próprio lote — nunca conta/insere o mesmo par 2x
            }
            $vistos[$chave] = true;

            $buscarCargo->execute([$codigoCargo]);
            $cargoId = $buscarCargo->fetchColumn();
            $buscarSetor->execute([$codigoSetor]);
            $setorId = $buscarSetor->fetchColumn();
            if ($cargoId === false || $setorId === false) {
                $semCorrespondencia++;
                continue;
            }

            if ($this->existeVinculo((int)$cargoId, (int)$setorId)) {
                $jaExistentes++;
                continue;
            }

            $upsert->execute([(int)$cargoId, (int)$setorId, $origemMetadados]);
            $inseridos++;
        }

        return ['inseridos' => $inseridos, 'ja_existentes' => $jaExistentes, 'sem_correspondencia_local' => $semCorrespondencia];
    }
}
