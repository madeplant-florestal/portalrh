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

    public static function all(?EscopoSetorial $escopo = null): array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $params = [];
        $where = self::condicaoEscopo($escopo, $params);
        if ($where === null) {
            return [];
        }
        $sql = 'SELECT v.*, e.nome AS empresa_nome' . self::selectSetor() . '
                FROM vagas v
                LEFT JOIN empresas e ON e.id = v.empresa_id' . self::joinSetor() . '
                WHERE ' . $where . '
                ORDER BY v.created_at DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Etapa 3.2B.1: condição de escopo setorial sobre `vagas.setor_id` (única fonte; nunca `area`). `null` ou escopo global = sem restrição
     * (comportamento anterior, usado por Admin/RH). Escopo restrito/vazio/nenhum => só vagas com setor_id dentro dos Setores Gerenciados;
     * vaga sem setor_id nunca casa. Retorna `null` quando o escopo é restrito mas o schema ainda não tem `vagas.setor_id` (restritivo: nada).
     *
     * @param array<int,mixed> $params
     */
    private static function condicaoEscopo(?EscopoSetorial $escopo, array &$params): ?string
    {
        if ($escopo === null || $escopo->ehGlobal()) {
            return '1 = 1';
        }
        if (!VagaSetorService::schemaSuportaSetor()) {
            return null;
        }
        return $escopo->sqlSetorId('v.setor_id', $params);
    }

    /** Etapa 3.2A: nome/situação do setor responsável (só quando a migration já foi executada; antes dela nada muda). */
    private static function selectSetor(): string
    {
        return VagaSetorService::schemaSuportaSetor() ? ', sv.nome AS setor_nome, sv.ativo AS setor_ativo' : '';
    }

    private static function joinSetor(): string
    {
        return VagaSetorService::schemaSuportaSetor() ? ' LEFT JOIN setores sv ON sv.id = v.setor_id' : '';
    }

    public static function find(int $id, ?EscopoSetorial $escopo = null): ?array
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $params = [];
        $where = self::condicaoEscopo($escopo, $params);
        if ($where === null) {
            return null;
        }
        $sql = 'SELECT v.*, e.nome AS empresa_nome' . self::selectSetor() . '
                FROM vagas v
                LEFT JOIN empresas e ON e.id = v.empresa_id' . self::joinSetor() . '
                WHERE v.id = ? AND ' . $where . ' LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute(array_merge([$id], $params));
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    /**
     * Etapa 3.2C.1 (complemento de segurança) — listagem de vagas do GESTOR. Consulta PRÓPRIA (não reaproveita `v.*` de all()): projeção
     * explícita só com o que a tela do Gestor exibe — id, título, empresa, área (rótulo público), local, setor e situação. Nunca traz
     * descrição, requisitos, solicitação de origem, ids internos nem datas internas.
     *
     * Regras (todas no SQL): escopo RESTRITO obrigatório (`vagas.setor_id` ∈ Setores Gerenciados; global/nenhum/vazio => []); só vagas
     * PUBLICADAS (`ativo = 1`, o mesmo critério da vitrine pública `allActive()`): rascunhos (nunca publicados) e vagas despublicadas/encerradas
     * ficam de fora (decisão conservadora: não há mecanismo de liberação operacional de vaga ao Gestor). Vaga sem setor nunca casa.
     *
     * @return array<int,array{id:int,titulo:string,empresa_nome:?string,area:?string,local:?string,setor_nome:?string,ativo:int}>
     */
    public static function listarSetorial(EscopoSetorial $escopo): array
    {
        if (!$escopo->ehRestrito() || !$escopo->enxergaAlgo() || !VagaSetorService::schemaSuportaSetor()) {
            return [];
        }
        RecruitmentWebhookSchemaService::ensureSchema();
        $params = [];
        $cond = $escopo->sqlSetorId('v.setor_id', $params);
        $sql = 'SELECT v.id, v.titulo, e.nome AS empresa_nome, v.area, v.local, s.nome AS setor_nome, v.ativo
                FROM vagas v
                INNER JOIN setores s ON s.id = v.setor_id
                LEFT JOIN empresas e ON e.id = v.empresa_id
                WHERE v.ativo = 1 AND ' . $cond . '
                ORDER BY v.created_at DESC, v.id DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        // `setor_id` só é gravado quando informado E a coluna existe (a OBRIGATORIEDADE é validada no controller via VagaSetorService).
        $comSetor = array_key_exists('setor_id', $data) && VagaSetorService::schemaSuportaSetor();
        $sql = 'INSERT INTO vagas (titulo, descricao, requisitos, area, local, empresa_id, ativo' . ($comSetor ? ', setor_id' : '') . ') VALUES (?,?,?,?,?,?,?' . ($comSetor ? ',?' : '') . ')';
        $stmt = Database::conn()->prepare($sql);
        $params = [
            $data['titulo'],
            $data['descricao'],
            $data['requisitos'],
            $data['area'],
            $data['local'],
            self::normalizeEmpresaId($data['empresa_id'] ?? null),
            (int)$data['ativo']
        ];
        if ($comSetor) {
            $params[] = $data['setor_id'] === null ? null : (int)$data['setor_id'];
        }
        $stmt->execute($params);
        return (int)Database::conn()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        // `publicada_em` é carimbado na primeira vez que a vaga vai ao ar (ativo = 1), qualquer
        // que seja o caminho (publicação do rascunho ou edição manual). Nunca é apagado ao
        // desativar — responde "quando foi publicada?" na auditoria.
        $ativo = (int)$data['ativo'];
        // `setor_id` só é tocado quando a chave vem em $data (chamadores antigos nunca o apagam) e a coluna existe.
        $comSetor = array_key_exists('setor_id', $data) && VagaSetorService::schemaSuportaSetor();
        $sql = 'UPDATE vagas
                SET titulo=?, descricao=?, requisitos=?, area=?, local=?, empresa_id=?, ativo=?,
                    publicada_em = CASE WHEN ? = 1 THEN COALESCE(publicada_em, NOW()) ELSE publicada_em END'
                . ($comSetor ? ', setor_id=?' : '') . '
                WHERE id=?';
        $stmt = Database::conn()->prepare($sql);
        $params = [
            $data['titulo'],
            $data['descricao'],
            $data['requisitos'],
            $data['area'],
            $data['local'],
            self::normalizeEmpresaId($data['empresa_id'] ?? null),
            $ativo,
            $ativo,
        ];
        if ($comSetor) {
            $params[] = $data['setor_id'] === null ? null : (int)$data['setor_id'];
        }
        $params[] = $id;
        return $stmt->execute($params);
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
