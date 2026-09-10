<?php
/**
 * Escrita/leitura das tabelas de catálogo `setores` e `cargos` no contexto da sincronização com o
 * METADADOS (Fase 5.2). Uma única classe parametrizada pela dimensão — mesmo desenho de
 * MetadadosDimensaoSyncIngestService (empresas/unidades). As tabelas e colunas dinâmicas vêm de um
 * WHITELIST interno (nunca de input), como CadastroOrganizacional.
 *
 * Identidade oficial: `RHSETORES.SETOR` / `RHCARGOS.CARGO` — string opaca GLOBAL (as tabelas de
 * catálogo do METADADOS não têm EMPRESA/UNIDADE). Nunca convertida para número.
 *
 * `id`, `nome`, `slug`, `ativo` (e `setores.empresa_id`, legado do Portal) e todas as FKs
 * existentes são preservados. A sincronização carimba `codigo_*`, `descricao_oficial`,
 * `situacao_metadados` (valor BRUTO de ATIVADESATIVADA), `origem_metadados`, `sincronizado_em`.
 * NÃO altera `ativo` local (a semântica de ATIVADESATIVADA ainda não foi confirmada — ver
 * migration 2026-09-08-setores-cargos-metadados.sql).
 *
 * A conexão com o METADADOS (SQL Server) é somente leitura — este repositório escreve só no MySQL.
 */
class CatalogoMetadadosRepository
{
    /** dimensão => [tabela local, coluna de código]. É o whitelist — nada aqui vem de request. */
    private const MAPA = [
        'setores' => ['tabela' => 'setores', 'coluna_codigo' => 'codigo_setor', 'nome_max' => 140, 'slug_max' => 160],
        'cargos'  => ['tabela' => 'cargos',  'coluna_codigo' => 'codigo_cargo', 'nome_max' => 160, 'slug_max' => 180],
    ];

    private string $tabela;
    private string $colCodigo;
    private int $nomeMax;
    private int $slugMax;
    private PDO $pdo;

    public function __construct(string $dimensao, ?PDO $pdo = null)
    {
        if (!isset(self::MAPA[$dimensao])) {
            throw new \InvalidArgumentException("Dimensão de catálogo não suportada: {$dimensao}");
        }
        $this->tabela = self::MAPA[$dimensao]['tabela'];
        $this->colCodigo = self::MAPA[$dimensao]['coluna_codigo'];
        $this->nomeMax = self::MAPA[$dimensao]['nome_max'];
        $this->slugMax = self::MAPA[$dimensao]['slug_max'];
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function findByCodigo(string $codigo): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tabela} WHERE {$this->colCodigo} = ? LIMIT 1");
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Catálogo OFICIAL da dimensão — só registros com identidade do METADADOS
     * (`codigo_* IS NOT NULL`) e ativos. É a fonte dos seletores "somente oficiais" das telas
     * (contexto organizacional do usuário, etc.): os registros legados sem código NUNCA aparecem.
     *
     * @return array<int, array{id:int, codigo:string, nome:string, descricao_oficial:?string}>
     */
    public function listarOficiais(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, {$this->colCodigo} AS codigo, nome, descricao_oficial
             FROM {$this->tabela}
             WHERE {$this->colCodigo} IS NOT NULL AND {$this->colCodigo} <> '' AND ativo = 1
             ORDER BY COALESCE(NULLIF(descricao_oficial, ''), nome) ASC"
        );
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'codigo' => (string)$row['codigo'],
                'nome' => (string)$row['nome'],
                'descricao_oficial' => $row['descricao_oficial'] !== null ? (string)$row['descricao_oficial'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Um registro oficial pelo id local — valida que o id pertence ao catálogo oficial (não-legado). */
    public function oficialPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, {$this->colCodigo} AS codigo, nome, descricao_oficial
             FROM {$this->tabela}
             WHERE id = ? AND {$this->colCodigo} IS NOT NULL AND {$this->colCodigo} <> '' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Registros locais ainda SEM código oficial — candidatos a adoção única por nome.
     * nomeNormalizado => lista de linhas (lista porque dois registros locais podem normalizar
     * para o mesmo nome; nesse caso a adoção é ambígua e NÃO acontece).
     *
     * @return array<string, array<int, array<string,mixed>>>
     */
    public function naoAdotadosPorNomeNormalizado(): array
    {
        $stmt = $this->pdo->query("SELECT id, nome, slug, ativo FROM {$this->tabela} WHERE {$this->colCodigo} IS NULL");
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapa[MetadadosTexto::normalizarNome((string)$row['nome'])][] = $row;
        }
        return $mapa;
    }

    /** Adoção: carimba a identidade oficial em um registro local existente, sem tocar nome/slug/ativo. */
    public function adotar(int $id, string $codigo, string $descricaoOficial, ?string $situacao, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->tabela}
             SET {$this->colCodigo} = ?, descricao_oficial = ?, situacao_metadados = ?,
                 origem_metadados = ?, sincronizado_em = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$codigo, $descricaoOficial, $situacao, $origem, $id]);
    }

    /** Atualização de um registro já vinculado: descrição/situação oficiais + carimbo. Não toca nome/slug/ativo. */
    public function atualizarOficial(int $id, string $descricaoOficial, ?string $situacao, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->tabela}
             SET descricao_oficial = ?, situacao_metadados = ?, origem_metadados = ?, sincronizado_em = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$descricaoOficial, $situacao, $origem, $id]);
    }

    /**
     * Insere um registro novo vindo do METADADOS, sem match de adoção. `nome`/`slug` recebem a
     * descrição oficial (com sufixo do código se houver colisão com um cadastro legado de mesmo
     * nome/slug — nunca falha silenciosamente por UNIQUE). `descricao_oficial` NUNCA recebe sufixo.
     *
     * @return int id criado
     */
    public function inserir(string $codigo, string $descricaoOficial, ?string $situacao, string $origem): int
    {
        $nome = $this->valorDisponivel('nome', $descricaoOficial, $codigo, $this->nomeMax);
        $slug = $this->valorDisponivel('slug', self::slugify($descricaoOficial) ?: ('registro-' . $codigo), $codigo, $this->slugMax, true);

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->tabela}
                ({$this->colCodigo}, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados, sincronizado_em)
             VALUES (?, ?, ?, ?, 1, ?, ?, NOW())"
        );
        $stmt->execute([$codigo, $nome, $descricaoOficial, $slug, $situacao, $origem]);
        return (int)$this->pdo->lastInsertId();
    }

    private function valorDisponivel(string $coluna, string $base, string $codigo, int $max, bool $slug = false): string
    {
        $candidato = $base;
        if ($this->existe($coluna, $candidato)) {
            $candidato = $slug ? ($base . '-' . strtolower($codigo)) : ($base . ' (' . $codigo . ')');
        }
        return mb_substr($candidato, 0, $max);
    }

    private function existe(string $coluna, string $valor): bool
    {
        // $coluna é literal controlado — só 'nome' ou 'slug'.
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tabela} WHERE {$coluna} = ? LIMIT 1");
        $stmt->execute([$valor]);
        return $stmt->fetchColumn() !== false;
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($translit) && $translit !== '') {
            $value = $translit;
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
