<?php
class User
{
    public int $id;
    public string $nome;
    public string $email;
    public string $senha_hash;
    public string $role;
    public int $pode_solicitar_vaga = 0;
    public ?int $colaborador_metadados_id = null;
    public ?int $aprovador_usuario_id = null;
    public int $is_supervisor;
    public ?string $email_verified_at;
    public ?string $last_password_reset_at;
    public string $created_at;

    public static function findByEmail(string $email): ?self
    {
        $sql = 'SELECT * FROM usuarios WHERE email = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? self::map($data) : null;
    }

    public static function verifyPassword(self $user, string $password): bool
    {
        return password_verify($password, $user->senha_hash);
    }

    public static function create(string $nome, string $email, string $senha_hash, string $role = 'viewer'): int
    {
        $sql = 'INSERT INTO usuarios (nome, email, senha_hash, role) VALUES (?,?,?,?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$nome, $email, $senha_hash, $role]);
        return (int)Database::conn()->lastInsertId();
    }

    public static function createSupervisor(string $nome, string $email, string $senhaHash): int
    {
        $sql = 'INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?,?,?,?,?,NOW())';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$nome, $email, $senhaHash, 'admin', 1]);
        return (int)Database::conn()->lastInsertId();
    }

    public static function ensureSupervisor(string $nome, string $email, string $password): int
    {
        $existing = self::findByEmail($email);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($existing) {
            $sql = 'UPDATE usuarios SET role = ?, is_supervisor = 1, senha_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?';
            $stmt = Database::conn()->prepare($sql);
            $stmt->execute(['admin', $hash, $existing->id]);
            return $existing->id;
        }
        return self::createSupervisor($nome, $email, $hash);
    }

    public static function findById(int $id): ?self
    {
        $sql = 'SELECT * FROM usuarios WHERE id = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? self::map($data) : null;
    }

    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.nome LIKE ? OR u.email LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $role = strtolower(trim((string)($filters['role'] ?? '')));
        if (in_array($role, ['admin', 'rh', 'viewer'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }

        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status === 'active') {
            $where[] = 'u.email_verified_at IS NOT NULL';
        } elseif ($status === 'inactive') {
            $where[] = 'u.email_verified_at IS NULL';
        }

        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $countSql = 'SELECT COUNT(*) FROM usuarios u' . $whereSql;
        $countStmt = Database::conn()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = 'SELECT u.*, CASE WHEN u.email_verified_at IS NULL THEN 0 ELSE 1 END AS ativo FROM usuarios u'
            . $whereSql
            . ' ORDER BY u.created_at DESC LIMIT ? OFFSET ?';
        $stmt = Database::conn()->prepare($sql);
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        $stmt->execute($queryParams);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages
        ];
    }

    public static function updatePassword(int $id, string $passwordHash): bool
    {
        $sql = 'UPDATE usuarios SET senha_hash = ?, last_password_reset_at = NOW() WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$passwordHash, $id]);
    }

    public static function adminChangePassword(int $targetId, string $newPassword, ?self $actor, ?string $ip): array
    {
        if (!$actor) {
            return ['ok' => false, 'status' => 401, 'error' => 'Usuário autenticado não encontrado.'];
        }
        $actorIsAdmin = strtolower(trim((string)($actor->role ?? ''))) === 'admin' || (int)($actor->is_supervisor ?? 0) === 1;
        if (!$actorIsAdmin) {
            return ['ok' => false, 'status' => 403, 'error' => 'Apenas administradores podem alterar senhas de outros usuários.'];
        }
        $target = self::findById($targetId);
        if (!$target) {
            return ['ok' => false, 'status' => 404, 'error' => 'Usuário alvo não encontrado.'];
        }
        if ($actor->id === $target->id) {
            return ['ok' => false, 'status' => 400, 'error' => 'Use a recuperação de senha para alterar a sua própria senha.'];
        }
        $policy = PasswordPolicy::validate($newPassword);
        if (!($policy['valid'] ?? false)) {
            return ['ok' => false, 'status' => 422, 'error' => implode(' ', $policy['errors'] ?? [])];
        }
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updated = self::updatePassword($target->id, $passwordHash);
        if (!$updated) {
            return ['ok' => false, 'status' => 500, 'error' => 'Falha ao atualizar senha.'];
        }
        AuditLog::log($actor->id, $target->id, 'admin_password_change', 'Alteração administrativa de senha', $ip);
        Logger::info('Senha de usuário alterada por administrador', [
            'admin_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'target_user_email' => $target->email,
            'ip' => (string)$ip
        ]);
        Mailer::notifyUserPasswordChanged($target->email, $target->nome, $actor->id);
        return ['ok' => true, 'status' => 200];
    }

    /**
     * Define o hash de senha diretamente. Uso restrito ao provisionamento de acesso de líderes
     * (Colaboradores → Acesso), onde o admin gera uma senha temporária e a comunica ao líder.
     * Sem e-mail, sem checagem de "self" (o alvo nunca é o próprio admin). O controller é quem
     * garante que o ator é admin.
     */
    public static function definirSenhaHash(int $id, string $senhaHash): bool
    {
        $stmt = Database::conn()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?');
        return $stmt->execute([$senhaHash, $id]);
    }

    public static function setActiveStatus(int $id, bool $active): bool
    {
        if ($active) {
            $sql = 'UPDATE usuarios SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?';
        } else {
            $sql = 'UPDATE usuarios SET email_verified_at = NULL WHERE id = ?';
        }
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$id]);
    }

    public static function isProtectedSupervisor(self $user): bool
    {
        return (int)($user->is_supervisor ?? 0) === 1;
    }

    public static function canManageUser(?self $actor, self $target): bool
    {
        if (!self::isProtectedSupervisor($target)) {
            return true;
        }
        if (!$actor) {
            return false;
        }
        return (int)($actor->is_supervisor ?? 0) === 1;
    }

    public static function attemptRoleUpdate(int $targetId, string $newRole, ?self $actor, ?string $ip): bool
    {
        $target = self::findById($targetId);
        if (!$target) {
            return false;
        }
        if (!self::canManageUser($actor, $target)) {
            AuditLog::log($actor?->id, $targetId, 'blocked_role_change', 'Tentativa de alterar permissão de supervisor', $ip);
            return false;
        }
        $sql = 'UPDATE usuarios SET role = ? WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$newRole, $targetId]);
    }

    public static function attemptDelete(int $targetId, ?self $actor, ?string $ip): bool
    {
        $target = self::findById($targetId);
        if (!$target) {
            return false;
        }
        if (!self::canManageUser($actor, $target)) {
            AuditLog::log($actor?->id, $targetId, 'blocked_user_delete', 'Tentativa de excluir supervisor', $ip);
            return false;
        }
        $sql = 'DELETE FROM usuarios WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$targetId]);
    }

    /**
     * Usuários que podem ser escolhidos como aprovador/líder imediato de outro usuário:
     * qualquer conta ativa (email_verified_at IS NOT NULL), exceto o próprio.
     */
    public static function candidatosAprovador(int $excluirUsuarioId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT id, nome, email, role FROM usuarios
             WHERE email_verified_at IS NOT NULL AND id <> ?
             ORDER BY nome ASC"
        );
        $stmt->execute([$excluirUsuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Domínio de Solicitação de Vaga (migration 2026-09-09-usuarios-dominio-vagas.sql):
     * autorização (`pode_solicitar_vaga`, fonte canônica) + aprovador/líder imediato
     * (`aprovador_usuario_id`, NULL = sem líder configurado). Nada aqui infere hierarquia por
     * cargo, setor ou METADADOS.
     */
    public static function setVagaAccess(int $id, bool $podeSolicitarVaga, ?int $aprovadorUsuarioId, ?self $actor = null, ?string $ip = null): array
    {
        $target = self::findById($id);
        if (!$target) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }

        if ($aprovadorUsuarioId !== null) {
            if ($aprovadorUsuarioId === $id) {
                return ['ok' => false, 'error' => 'Um usuário não pode ser o próprio aprovador.'];
            }
            $aprovador = self::findById($aprovadorUsuarioId);
            if (!$aprovador) {
                return ['ok' => false, 'error' => 'O aprovador informado não é um usuário válido.'];
            }
            if (empty($aprovador->email_verified_at)) {
                return ['ok' => false, 'error' => 'O aprovador informado está inativo. Ative o usuário antes de designá-lo.'];
            }
            // Impede o ciclo direto A -> B e B -> A (validação simples, sem algoritmo de grafo).
            if ((int)($aprovador->aprovador_usuario_id ?? 0) === $id) {
                return ['ok' => false, 'error' => 'Ciclo de aprovação inválido: o aprovador escolhido já é aprovado por este usuário.'];
            }
        }

        $stmt = Database::conn()->prepare(
            'UPDATE usuarios SET pode_solicitar_vaga = ?, aprovador_usuario_id = ? WHERE id = ?'
        );
        $stmt->execute([$podeSolicitarVaga ? 1 : 0, $aprovadorUsuarioId, $id]);

        AuditLog::log($actor?->id, $id, 'vaga_access_update', sprintf(
            'pode_solicitar_vaga=%d aprovador_usuario_id=%s',
            $podeSolicitarVaga ? 1 : 0,
            $aprovadorUsuarioId === null ? 'NULL' : (string)$aprovadorUsuarioId
        ), $ip);

        return ['ok' => true];
    }

    /** Vínculo OPCIONAL do usuário com um contrato oficial do METADADOS (colaboradores_metadados.id). */
    public static function vincularMetadados(int $id, int $colaboradorMetadadosId, ?self $actor = null, ?string $ip = null): array
    {
        if (!self::findById($id)) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }
        $existe = Database::conn()->prepare('SELECT id FROM colaboradores_metadados WHERE id = ? LIMIT 1');
        $existe->execute([$colaboradorMetadadosId]);
        if (!$existe->fetchColumn()) {
            return ['ok' => false, 'error' => 'O contrato oficial informado não existe na base do METADADOS.'];
        }
        $emUso = Database::conn()->prepare('SELECT id FROM usuarios WHERE colaborador_metadados_id = ? AND id <> ? LIMIT 1');
        $emUso->execute([$colaboradorMetadadosId, $id]);
        if ($emUso->fetchColumn()) {
            return ['ok' => false, 'error' => 'Este contrato oficial já está vinculado a outro usuário.'];
        }

        try {
            $stmt = Database::conn()->prepare('UPDATE usuarios SET colaborador_metadados_id = ? WHERE id = ?');
            $stmt->execute([$colaboradorMetadadosId, $id]);
        } catch (\PDOException $e) {
            return ['ok' => false, 'error' => 'Não foi possível vincular: o contrato já está em uso por outro usuário.'];
        }

        AuditLog::log($actor?->id, $id, 'vaga_metadados_link', 'colaborador_metadados_id=' . $colaboradorMetadadosId, $ip);
        return ['ok' => true];
    }

    public static function desvincularMetadados(int $id, ?self $actor = null, ?string $ip = null): array
    {
        if (!self::findById($id)) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }
        $stmt = Database::conn()->prepare('UPDATE usuarios SET colaborador_metadados_id = NULL WHERE id = ?');
        $stmt->execute([$id]);
        AuditLog::log($actor?->id, $id, 'vaga_metadados_unlink', 'colaborador_metadados_id=NULL', $ip);
        return ['ok' => true];
    }

    private static function map(array $data): self
    {
        $u = new self();
        $u->id = (int)$data['id'];
        $u->nome = $data['nome'];
        $u->email = $data['email'];
        $u->senha_hash = $data['senha_hash'];
        $u->role = $data['role'];
        $u->pode_solicitar_vaga = (int)($data['pode_solicitar_vaga'] ?? 0);
        $u->colaborador_metadados_id = isset($data['colaborador_metadados_id']) ? (int)$data['colaborador_metadados_id'] : null;
        $u->aprovador_usuario_id = isset($data['aprovador_usuario_id']) ? (int)$data['aprovador_usuario_id'] : null;
        $u->is_supervisor = (int)($data['is_supervisor'] ?? 0);
        $u->email_verified_at = $data['email_verified_at'] ?? null;
        $u->last_password_reset_at = $data['last_password_reset_at'] ?? null;
        $u->created_at = $data['created_at'];
        return $u;
    }
}
