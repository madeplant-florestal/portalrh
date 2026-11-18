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
    public ?int $cargo_id = null;
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

    /**
     * Garante o Supervisor Geral CONFIGURADO (security.supervisor_email). Etapa 2A-bis: NUNCA redefine a senha de uma conta
     * existente (antes, qualquer administrador podia usá-la para trocar a senha do supervisor pela do arquivo de
     * configuração). Conta existente: só restaura role admin + flag + verificação de e-mail. Conta nova: criada com a
     * senha configurada. Quem pode chamar é decidido no controller (somente Supervisor Geral).
     */
    public static function ensureSupervisor(string $nome, string $email, string $password): int
    {
        $existing = self::findByEmail($email);
        if ($existing) {
            $sql = 'UPDATE usuarios SET role = ?, is_supervisor = 1, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?';
            Database::conn()->prepare($sql)->execute(['admin', $existing->id]);
            return $existing->id;
        }
        return self::createSupervisor($nome, $email, password_hash($password, PASSWORD_BCRYPT));
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
        // Etapa 2A-bis: a autoridade vem de Autoridade (admin ou Supervisor Geral de fato — o flag sozinho não conta) e a conta
        // de destino também é verificada: Administrador não redefine a senha de Supervisor Geral; RH nunca redefine senhas.
        if (!Autoridade::ehAdministrativo($actor)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Apenas administradores podem alterar senhas de outros usuários.'];
        }
        $target = self::findById($targetId);
        if (!$target) {
            return ['ok' => false, 'status' => 404, 'error' => 'Usuário alvo não encontrado.'];
        }
        if (!Autoridade::podeGerenciar($actor, $target)) {
            AuditLog::log($actor->id, $target->id, 'blocked_password_change', 'Tentativa bloqueada de redefinir senha de conta de nível superior', $ip);
            return ['ok' => false, 'status' => 403, 'error' => 'Operação não permitida para esta conta.'];
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

    /** @deprecated usar Autoridade::ehSupervisorGeral() — mantido por compatibilidade (agora exige role admin + flag). */
    public static function isProtectedSupervisor(self $user): bool
    {
        return Autoridade::ehSupervisorGeral($user);
    }

    /** @deprecated usar Autoridade::podeGerenciar() — mantido por compatibilidade. */
    public static function canManageUser(?self $actor, self $target): bool
    {
        return Autoridade::podeGerenciar($actor, $target);
    }

    /** Motivo da última recusa de uma operação administrativa: negado | nao_encontrado | autoexclusao | ultimo_supervisor | ... */
    public static ?string $ultimoMotivo = null;

    /**
     * Executa `$mutacao` (que REDUZ a autoridade do alvo: excluir, desativar, revogar o flag) sob trava dos Supervisores
     * Gerais ativos. O SELECT ... FOR UPDATE serializa operações concorrentes: duas requisições que tentariam remover, ao
     * mesmo tempo, os dois últimos supervisores não passam juntas — a segunda lê o estado já commitado e é recusada.
     * Recusa (motivo `ultimo_supervisor`) se o alvo é um Supervisor Geral ativo e é o único.
     */
    private static function protegendoUltimoSupervisor(int $alvoId, callable $mutacao): bool
    {
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $ids = array_map('intval', $pdo->query(
                "SELECT id FROM usuarios WHERE role = 'admin' AND is_supervisor = 1 AND email_verified_at IS NOT NULL ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN));
            if (in_array($alvoId, $ids, true) && count($ids) <= 1) {
                $pdo->rollBack();
                self::$ultimoMotivo = 'ultimo_supervisor';
                return false;
            }
            $ok = (bool)$mutacao($pdo);
            $ok ? $pdo->commit() : $pdo->rollBack();
            return $ok;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function attemptRoleUpdate(int $targetId, string $newRole, ?self $actor, ?string $ip): bool
    {
        self::$ultimoMotivo = null;
        $target = self::findById($targetId);
        if (!$target) {
            self::$ultimoMotivo = 'nao_encontrado';
            return false;
        }
        if (!Autoridade::podeAtribuirRole($actor, $target, $newRole)) {
            self::$ultimoMotivo = 'negado';
            AuditLog::log($actor?->id, $targetId, 'blocked_role_change', 'Tentativa bloqueada de alterar perfil: ' . $target->role . ' -> ' . $newRole, $ip);
            return false;
        }
        $ok = Database::conn()->prepare('UPDATE usuarios SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
        if ($ok) {
            AuditLog::log($actor?->id, $targetId, 'role_change', 'Perfil alterado: ' . $target->role . ' -> ' . $newRole, $ip);
        }
        return $ok;
    }

    public static function attemptDelete(int $targetId, ?self $actor, ?string $ip): bool
    {
        self::$ultimoMotivo = null;
        $target = self::findById($targetId);
        if (!$target) {
            self::$ultimoMotivo = 'nao_encontrado';
            return false;
        }
        if ($actor && $actor->id === $target->id) {
            self::$ultimoMotivo = 'autoexclusao';
            AuditLog::log($actor->id, $targetId, 'blocked_user_delete', 'Tentativa bloqueada de autoexclusão', $ip);
            return false;
        }
        if (!Autoridade::podeGerenciar($actor, $target)) {
            self::$ultimoMotivo = 'negado';
            AuditLog::log($actor?->id, $targetId, 'blocked_user_delete', 'Tentativa bloqueada de excluir conta de nível superior', $ip);
            return false;
        }
        $excluir = static fn(PDO $pdo): bool => $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$targetId]);
        $ok = Autoridade::ehSupervisorGeral($target) ? self::protegendoUltimoSupervisor($targetId, $excluir) : $excluir(Database::conn());
        if ($ok) {
            // alvo NULL de propósito: a linha do alvo já não existe (FK); o id fica no texto.
            AuditLog::log($actor?->id, null, 'user_delete', 'Conta #' . $targetId . ' excluída (perfil ' . $target->role . ')', $ip);
        } elseif (self::$ultimoMotivo === 'ultimo_supervisor') {
            AuditLog::log($actor?->id, $targetId, 'blocked_user_delete', 'Tentativa bloqueada de excluir o último Supervisor Geral ativo', $ip);
        }
        return $ok;
    }

    /** Ativa/desativa uma conta pela via administrativa: ninguém desativa a própria conta; o último Supervisor Geral ativo não é desativado. */
    public static function attemptSetActive(int $targetId, bool $active, ?self $actor, ?string $ip): bool
    {
        self::$ultimoMotivo = null;
        $target = self::findById($targetId);
        if (!$target) {
            self::$ultimoMotivo = 'nao_encontrado';
            return false;
        }
        if ($actor && $actor->id === $target->id) {
            self::$ultimoMotivo = 'autoalteracao';
            AuditLog::log($actor->id, $targetId, 'blocked_status_change', 'Tentativa bloqueada de alterar o próprio status', $ip);
            return false;
        }
        if (!Autoridade::podeGerenciar($actor, $target)) {
            self::$ultimoMotivo = 'negado';
            AuditLog::log($actor?->id, $targetId, 'blocked_status_change', 'Tentativa bloqueada de alterar o status de conta de nível superior', $ip);
            return false;
        }
        $mutacao = static function (PDO $pdo) use ($targetId, $active): bool {
            $sql = $active
                ? 'UPDATE usuarios SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?'
                : 'UPDATE usuarios SET email_verified_at = NULL WHERE id = ?';
            return $pdo->prepare($sql)->execute([$targetId]);
        };
        $ok = (!$active && Autoridade::ehSupervisorGeral($target)) ? self::protegendoUltimoSupervisor($targetId, $mutacao) : $mutacao(Database::conn());
        if ($ok) {
            AuditLog::log($actor?->id, $targetId, 'user_status_change', $active ? 'Conta ativada' : 'Conta desativada', $ip);
        } elseif (self::$ultimoMotivo === 'ultimo_supervisor') {
            AuditLog::log($actor?->id, $targetId, 'blocked_status_change', 'Tentativa bloqueada de desativar o último Supervisor Geral ativo', $ip);
        }
        return $ok;
    }

    /**
     * Concede o flag de Supervisor Geral. Única via de escrita do flag (além do `ensureSupervisor` do supervisor configurado):
     * somente um Supervisor Geral, sobre uma conta de role admin (o flag em outra role seria inconsistente — nunca se muda a
     * role por baixo), auditada.
     */
    public static function concederSupervisorGeral(int $targetId, ?self $actor, ?string $ip): bool
    {
        self::$ultimoMotivo = null;
        $target = self::findById($targetId);
        if (!$target) {
            self::$ultimoMotivo = 'nao_encontrado';
            return false;
        }
        if (!Autoridade::podeAlterarSupervisorGeral($actor)) {
            self::$ultimoMotivo = 'negado';
            AuditLog::log($actor?->id, $targetId, 'blocked_supervisor_grant', 'Tentativa bloqueada de conceder Supervisor Geral', $ip);
            return false;
        }
        if (strtolower(trim($target->role)) !== 'admin') {
            self::$ultimoMotivo = 'alvo_nao_admin';
            return false;
        }
        if ((int)$target->is_supervisor === 1) {
            self::$ultimoMotivo = 'ja_supervisor';
            return false;
        }
        $ok = Database::conn()->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ? AND role = ?')->execute([$targetId, 'admin']);
        if ($ok) {
            AuditLog::log($actor?->id, $targetId, 'supervisor_grant', 'Supervisor Geral concedido', $ip);
        }
        return $ok;
    }

    /** Remove o flag de Supervisor Geral: nunca o próprio, nunca o último ativo; somente por um Supervisor Geral; auditada. */
    public static function revogarSupervisorGeral(int $targetId, ?self $actor, ?string $ip): bool
    {
        self::$ultimoMotivo = null;
        $target = self::findById($targetId);
        if (!$target) {
            self::$ultimoMotivo = 'nao_encontrado';
            return false;
        }
        if (!Autoridade::podeAlterarSupervisorGeral($actor)) {
            self::$ultimoMotivo = 'negado';
            AuditLog::log($actor?->id, $targetId, 'blocked_supervisor_revoke', 'Tentativa bloqueada de remover Supervisor Geral', $ip);
            return false;
        }
        if ($actor && $actor->id === $target->id) {
            self::$ultimoMotivo = 'autoalteracao';
            AuditLog::log($actor->id, $targetId, 'blocked_supervisor_revoke', 'Tentativa bloqueada de remover o próprio privilégio', $ip);
            return false;
        }
        if ((int)$target->is_supervisor !== 1) {
            self::$ultimoMotivo = 'nao_supervisor';
            return false;
        }
        $mutacao = static fn(PDO $pdo): bool => $pdo->prepare('UPDATE usuarios SET is_supervisor = 0 WHERE id = ?')->execute([$targetId]);
        $ok = self::protegendoUltimoSupervisor($targetId, $mutacao);
        if ($ok) {
            AuditLog::log($actor?->id, $targetId, 'supervisor_revoke', 'Supervisor Geral removido', $ip);
        } elseif (self::$ultimoMotivo === 'ultimo_supervisor') {
            AuditLog::log($actor?->id, $targetId, 'blocked_supervisor_revoke', 'Tentativa bloqueada de remover o último Supervisor Geral ativo', $ip);
        }
        return $ok;
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
     * Usuários elegíveis para serem o SOLICITANTE de uma vaga aberta por outra pessoa (Admin/RH
     * "em nome de outro" — Sprint Solicitação de Vaga, Etapa 2). Ativo + `pode_solicitar_vaga = 1`,
     * ou já intrinsecamente autorizado por ser Admin/RH/supervisor — mesmo critério usado em
     * `SolicitacaoVaga::resolveApprover()`/`canCreate()`.
     */
    public static function candidatosSolicitante(): array
    {
        $stmt = Database::conn()->query(
            "SELECT id, nome, email, role FROM usuarios
             WHERE email_verified_at IS NOT NULL
               AND (pode_solicitar_vaga = 1 OR role IN ('admin', 'rh'))
             ORDER BY nome ASC"
        );
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

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ? WHERE id = ?');
            $stmt->execute([$colaboradorMetadadosId, $id]);
            // Herança do contexto organizacional (Cargo principal + Setor principal oficiais).
            (new UsuarioContextoOrganizacionalService($pdo))
                ->aplicarContextoDoVinculo($id, $colaboradorMetadadosId, $actor, $ip);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Não foi possível vincular: o contrato já está em uso por outro usuário.'];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Não foi possível aplicar o contexto organizacional do contrato oficial.'];
        }

        AuditLog::log($actor?->id, $id, 'vaga_metadados_link', 'colaborador_metadados_id=' . $colaboradorMetadadosId, $ip);
        return ['ok' => true];
    }

    public static function desvincularMetadados(int $id, ?self $actor = null, ?string $ip = null): array
    {
        if (!self::findById($id)) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = NULL WHERE id = ?')->execute([$id]);
            // Preserva as associações já gravadas; as linhas herdadas passam a ser MANUAL e o
            // Cargo (`usuarios.cargo_id`) é mantido — a edição volta a ser liberada na tela.
            (new UsuarioContextoOrganizacionalService($pdo))->aoDesvincular($id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Falha ao remover o vínculo com o METADADOS.'];
        }
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
        $u->cargo_id = isset($data['cargo_id']) ? (int)$data['cargo_id'] : null;
        $u->is_supervisor = (int)($data['is_supervisor'] ?? 0);
        $u->email_verified_at = $data['email_verified_at'] ?? null;
        $u->last_password_reset_at = $data['last_password_reset_at'] ?? null;
        $u->created_at = $data['created_at'];
        return $u;
    }
}
