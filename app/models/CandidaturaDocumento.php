<?php

/**
 * Documentos de contratação anexados a uma candidatura (correção de 2026-11, pedido do RH) —
 * migration 2026-11-05-candidatura-documentos.sql. N:1 com `candidaturas`: múltiplos arquivos por
 * candidatura, preservados mesmo que ela avance para outras etapas depois de Aprovado. Arquivo
 * físico fica em storage/candidatura_documentos/ (fora de public/, bloqueado por .htaccess — ver
 * Upload::saveDocumento()); aqui só os metadados.
 */
class CandidaturaDocumento
{
    private static bool $ensured = false;

    public static function allByCandidatura(int $candidaturaId): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'SELECT d.*, u.nome AS enviado_por_nome
             FROM candidatura_documentos d
             LEFT JOIN usuarios u ON u.id = d.enviado_por_usuario_id
             WHERE d.candidatura_id = ?
             ORDER BY d.criado_em DESC'
        );
        $stmt->execute([$candidaturaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM candidatura_documentos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{candidatura_id:int,nome_original:string,nome_armazenado:string,mime:string,tamanho_bytes:int,enviado_por_usuario_id:int} $data
     */
    public static function create(array $data): int
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'INSERT INTO candidatura_documentos
                (candidatura_id, nome_original, nome_armazenado, mime, tamanho_bytes, enviado_por_usuario_id, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            (int)$data['candidatura_id'],
            (string)$data['nome_original'],
            (string)$data['nome_armazenado'],
            (string)$data['mime'],
            (int)$data['tamanho_bytes'],
            (int)$data['enviado_por_usuario_id'],
        ]);
        return (int)Database::conn()->lastInsertId();
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-11-05-candidatura-documentos.sql
     * ainda não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7).
     */
    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS candidatura_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidatura_id INT NOT NULL,
            nome_original VARCHAR(255) NOT NULL,
            nome_armazenado VARCHAR(64) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            tamanho_bytes INT NOT NULL,
            enviado_por_usuario_id INT NOT NULL,
            criado_em DATETIME NOT NULL,
            KEY idx_canddoc_candidatura (candidatura_id),
            CONSTRAINT fk_canddoc_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
            CONSTRAINT fk_canddoc_usuario FOREIGN KEY (enviado_por_usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
