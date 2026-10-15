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

    /**
     * IDs dos benefícios hoje associados à vaga — usado para marcar os checkboxes já selecionados
     * na tela administrativa de edição (nunca filtra por `beneficios.ativo`: um benefício
     * desativado depois de selecionado continua marcado até o RH revisar).
     *
     * @return array<int, int>
     */
    public static function beneficioIdsSelecionados(int $vagaId): array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT beneficio_id FROM vaga_beneficios WHERE vaga_id = ? ORDER BY beneficio_id');
        $stmt->execute([$vagaId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Benefícios a exibir na página pública desta vaga — só os explicitamente associados E ainda
     * ativos no catálogo (correção 2026-10: antes a página pública mostrava TODO
     * `Beneficio::allActive()`, sem relação com a vaga).
     *
     * @return array<int, array{id:int, nome:string, descricao:?string, parceiro:?string, logo_path:?string}>
     */
    public static function beneficiosPublicos(int $vagaId): array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $stmt = Database::conn()->prepare(
            'SELECT b.id, b.nome, b.descricao, b.parceiro, b.logo_path
             FROM vaga_beneficios vb
             INNER JOIN beneficios b ON b.id = vb.beneficio_id
             WHERE vb.vaga_id = ? AND b.ativo = 1
             ORDER BY b.nome ASC'
        );
        $stmt->execute([$vagaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Define a seleção de benefícios da vaga (substitui a anterior por completo — mesmo padrão de
     * SolicitacaoVaga::syncBenefits()). Só o RH/Admin que já pode editar a vaga chama isto
     * (AdminVagasController::store()/update(), ambos com Auth::requireRole(['admin','rh'])) —
     * nunca o solicitante da Solicitação de Vaga. IDs que não existirem em `beneficios` são
     * silenciosamente ignorados (defesa contra POST adulterado, nunca lança erro para o usuário).
     */
    public static function syncBeneficios(int $vagaId, array $beneficioIds): void
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $pdo = Database::conn();
        $pdo->prepare('DELETE FROM vaga_beneficios WHERE vaga_id = ?')->execute([$vagaId]);

        $ids = self::filterValidBeneficioIds($beneficioIds);
        if ($ids === []) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO vaga_beneficios (vaga_id, beneficio_id) VALUES (?, ?)');
        foreach ($ids as $beneficioId) {
            $stmt->execute([$vagaId, $beneficioId]);
        }
    }

    /**
     * @return array<int, int>
     */
    private static function filterValidBeneficioIds(array $value): array
    {
        $ids = [];
        foreach ($value as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::conn()->prepare("SELECT id FROM beneficios WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_intersect($ids, $validIds));
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
