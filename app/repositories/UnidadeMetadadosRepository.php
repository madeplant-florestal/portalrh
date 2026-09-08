<?php
/**
 * Escrita/leitura da tabela `unidades` — criada na Fase 5.1A e populada exclusivamente pela
 * sincronização com o METADADOS (RHUNIDADES). Ver docs/claude/roadmap-tecnico.md.
 *
 * Chave oficial: (codigo_empresa, codigo_unidade) — nunca codigo_unidade isolado.
 * A conexão com o METADADOS (SQL Server) é somente leitura — este repositório escreve só no MySQL.
 */
class UnidadeMetadadosRepository
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

    public function findByCodigo(string $codigoEmpresa, string $codigoUnidade): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM unidades WHERE codigo_empresa = ? AND codigo_unidade = ? LIMIT 1'
        );
        $stmt->execute([$codigoEmpresa, $codigoUnidade]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(int $empresaId, string $codigoEmpresa, string $codigoUnidade, string $descricao, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO unidades (empresa_id, codigo_empresa, codigo_unidade, descricao, ativo, origem_metadados, sincronizado_em)
             VALUES (?, ?, ?, ?, 1, ?, NOW())'
        );
        $stmt->execute([$empresaId, $codigoEmpresa, $codigoUnidade, $descricao, $origem]);
    }

    /**
     * Atualiza descrição, o vínculo com a empresa e o carimbo de sincronização. `ativo` não é
     * tocado nesta fase (status oficial de RHUNIDADES ainda não confirmado — ver roadmap).
     */
    public function update(int $id, int $empresaId, string $descricao, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE unidades
             SET empresa_id = ?, descricao = ?, origem_metadados = ?, sincronizado_em = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$empresaId, $descricao, $origem, $id]);
    }
}
