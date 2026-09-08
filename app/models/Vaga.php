<?php
class Vaga
{
    public int $id;
    public string $titulo;
    public string $descricao;
    public string $requisitos;
    public string $area;
    public string $local;
    public int $ativo; // 1 ou 0
    public string $created_at;

    public static function allActive(): array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $sql = 'SELECT v.*, e.nome AS empresa_nome
                FROM vagas v
                LEFT JOIN empresas e ON e.id = v.empresa_id
                WHERE v.ativo = 1
                ORDER BY v.created_at DESC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function all(): array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $sql = 'SELECT v.*, e.nome AS empresa_nome
                FROM vagas v
                LEFT JOIN empresas e ON e.id = v.empresa_id
                ORDER BY v.created_at DESC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $sql = 'SELECT v.*, e.nome AS empresa_nome
                FROM vagas v
                LEFT JOIN empresas e ON e.id = v.empresa_id
                WHERE v.id = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public static function create(array $data): int
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $sql = 'INSERT INTO vagas (titulo, descricao, requisitos, area, local, empresa_id, ativo) VALUES (?,?,?,?,?,?,?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            $data['titulo'],
            $data['descricao'],
            $data['requisitos'],
            $data['area'],
            $data['local'],
            self::normalizeEmpresaId($data['empresa_id'] ?? null),
            (int)$data['ativo']
        ]);
        return (int)Database::conn()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        // `publicada_em` é carimbado na primeira vez que a vaga vai ao ar (ativo = 1), qualquer
        // que seja o caminho (publicação do rascunho ou edição manual). Nunca é apagado ao
        // desativar — responde "quando foi publicada?" na auditoria.
        $ativo = (int)$data['ativo'];
        $sql = 'UPDATE vagas
                SET titulo=?, descricao=?, requisitos=?, area=?, local=?, empresa_id=?, ativo=?,
                    publicada_em = CASE WHEN ? = 1 THEN COALESCE(publicada_em, NOW()) ELSE publicada_em END
                WHERE id=?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([
            $data['titulo'],
            $data['descricao'],
            $data['requisitos'],
            $data['area'],
            $data['local'],
            self::normalizeEmpresaId($data['empresa_id'] ?? null),
            $ativo,
            $ativo,
            $id
        ]);
    }

    public static function delete(int $id): bool
    {
        $sql = 'DELETE FROM vagas WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$id]);
    }

    private static function normalizeEmpresaId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $empresaId = (int)$value;
        return $empresaId > 0 ? $empresaId : null;
    }
}
