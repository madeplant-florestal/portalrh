<?php
/**
 * Escrita/leitura da tabela `empresas` no contexto da sincronização com o METADADOS
 * (Fase 5.1A — ver docs/claude/roadmap-tecnico.md). Camada da geração nova (Repository puro,
 * PDO injetável), separada de propósito de EmpresaRepository (leitura genérica para o Portal) e
 * de CadastroOrganizacional (CRUD manual legado) — nenhum dos dois é alterado nesta fase.
 *
 * Identidade oficial de uma empresa é `codigo_empresa` (RHEMPRESAS.EMPRESA), nunca o nome.
 * `nome`/`slug` continuam sendo a identidade de exibição legada durante a transição e NÃO são
 * sobrescritos após a adoção — só `razao_social` (nome oficial) é mantido em dia pela sincronização.
 *
 * A conexão com o METADADOS (SQL Server) é somente leitura — este repositório escreve apenas no
 * MySQL local.
 */
class EmpresaMetadadosRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function findByCodigo(string $codigoEmpresa): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM empresas WHERE codigo_empresa = ? LIMIT 1');
        $stmt->execute([$codigoEmpresa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findIdByCodigo(string $codigoEmpresa): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM empresas WHERE codigo_empresa = ? LIMIT 1');
        $stmt->execute([$codigoEmpresa]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Empresas que ainda NÃO têm codigo_empresa (candidatas a adoção única por nome na primeira
     * sincronização). Retorna nomeNormalizado => [lista de linhas] — lista, não linha única,
     * porque duas empresas locais podem normalizar para o mesmo nome (aí a adoção é ambígua e
     * NÃO acontece).
     *
     * @return array<string, array<int, array<string,mixed>>>
     */
    public function naoAdotadasPorNomeNormalizado(): array
    {
        $stmt = $this->pdo->query('SELECT id, nome, slug, ativo FROM empresas WHERE codigo_empresa IS NULL');
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chave = self::normalizarNome((string)$row['nome']);
            $mapa[$chave][] = $row;
        }
        return $mapa;
    }

    /**
     * Adoção única: vincula uma empresa local existente (referenciada pelas FKs legadas) ao código
     * oficial do METADADOS, sem tocar em `nome`/`slug`/`ativo` — só carimba a identidade oficial.
     */
    public function adotar(int $id, string $codigoEmpresa, string $razaoSocial, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE empresas
             SET codigo_empresa = ?, razao_social = ?, origem_metadados = ?, sincronizado_em = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$codigoEmpresa, $razaoSocial, $origem, $id]);
    }

    /**
     * Atualização de uma empresa já vinculada: só a razão social oficial e o carimbo de
     * sincronização. NÃO toca `nome`/`slug` (identidade de exibição legada) nem `ativo` — o
     * status oficial de RHEMPRESAS ainda não foi confirmado (ver roadmap-tecnico.md, Fase 5.1A).
     */
    public function atualizarOficial(int $id, string $razaoSocial, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE empresas
             SET razao_social = ?, origem_metadados = ?, sincronizado_em = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$razaoSocial, $origem, $id]);
    }

    /**
     * Insere uma empresa nova, vinda do METADADOS, que não existia no Portal e não teve match de
     * adoção. `nome`/`slug` recebem a razão social (com sufixo do código se houver colisão com um
     * cadastro legado de mesmo nome/slug — nunca falha silenciosamente por UNIQUE).
     *
     * @return int id da empresa criada
     */
    public function inserir(string $codigoEmpresa, string $razaoSocial, string $origem): int
    {
        $nome = $this->nomeDisponivel($razaoSocial, $codigoEmpresa);
        $slug = $this->slugDisponivel(self::slugify($razaoSocial), $codigoEmpresa);

        $stmt = $this->pdo->prepare(
            'INSERT INTO empresas (codigo_empresa, nome, razao_social, slug, ativo, origem_metadados, sincronizado_em)
             VALUES (?, ?, ?, ?, 1, ?, NOW())'
        );
        $stmt->execute([$codigoEmpresa, $nome, $razaoSocial, $slug, $origem]);
        return (int)$this->pdo->lastInsertId();
    }

    private function nomeDisponivel(string $nome, string $codigo): string
    {
        $candidato = $nome;
        if ($this->existe('nome', $candidato)) {
            $candidato = $nome . ' (' . $codigo . ')';
        }
        return mb_substr($candidato, 0, 160);
    }

    private function slugDisponivel(string $slug, string $codigo): string
    {
        $candidato = $slug !== '' ? $slug : 'empresa-' . $codigo;
        if ($this->existe('slug', $candidato)) {
            $candidato = $candidato . '-' . strtolower($codigo);
        }
        return mb_substr($candidato, 0, 180);
    }

    private function existe(string $coluna, string $valor): bool
    {
        // $coluna é literal controlado (nunca vem de input) — só 'nome' ou 'slug'.
        $stmt = $this->pdo->prepare("SELECT 1 FROM empresas WHERE {$coluna} = ? LIMIT 1");
        $stmt->execute([$valor]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Normaliza um nome de empresa para comparação de adoção: maiúsculas, sem acento, espaços
     * colapsados. NUNCA é a identidade — só um heurístico de match único na primeira carga.
     */
    /** Transliteração determinística de acentos PT-BR — independente de locale/libiconv (Windows). */
    private const ACENTOS = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    public static function normalizarNome(string $nome): string
    {
        $nome = strtr(trim($nome), self::ACENTOS);
        $nome = preg_replace('/[^A-Za-z0-9]+/', ' ', $nome) ?? '';
        $nome = preg_replace('/\s+/', ' ', $nome) ?? '';
        return strtoupper(trim($nome));
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
