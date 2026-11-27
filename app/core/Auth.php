<?php
class Auth
{
    public static function attemptLogin(string $email, string $password): array
    {
        $user = User::findByEmail($email);
        if (!$user) {
            return [
                'ok' => false,
                'reason' => 'user_not_found',
                'message' => 'Usuário não encontrado para o e-mail informado.'
            ];
        }
        if (empty($user->email_verified_at)) {
            return [
                'ok' => false,
                'reason' => 'inactive_account',
                'message' => 'Conta inativa. Solicite a reativação a um administrador.',
                'user' => $user
            ];
        }
        if (!User::verifyPassword($user, $password)) {
            return [
                'ok' => false,
                'reason' => 'invalid_password',
                'message' => 'Senha incorreta para o usuário informado.',
                'user' => $user
            ];
        }
        // Etapa 2C.1: valor de role desconhecido é RECUSADO (nunca convertido em viewer). Role vazia (legado) segue o tratamento antigo.
        $roleCadastrada = strtolower(trim((string)($user->role ?? '')));
        if (!self::roleAceitaNoLogin($roleCadastrada)) {
            Logger::warning('Login recusado: perfil desconhecido', ['usuario_id' => (int)$user->id, 'role' => $roleCadastrada]);
            return [
                'ok' => false,
                'reason' => 'invalid_role',
                'message' => 'Perfil de acesso inválido. Procure um administrador.',
                'user' => $user
            ];
        }
        self::establishSession($user);
        return [
            'ok' => true,
            'reason' => 'success',
            'message' => 'Login realizado com sucesso.',
            'user' => $user
        ];
    }

    /** Role vazia (legado) segue o tratamento antigo; qualquer valor preenchido precisa ser um perfil conhecido (admin/rh/gestor/viewer). */
    public static function roleAceitaNoLogin(?string $role): bool
    {
        $r = strtolower(trim((string)$role));
        return $r === '' || Autoridade::roleConhecida($r);
    }

    public static function login(string $email, string $password): bool
    {
        $attempt = self::attemptLogin($email, $password);
        return (bool)($attempt['ok'] ?? false);
    }

    /**
     * FONTE ÚNICA da autoridade efetiva (Segurança 3.3): calculada do cadastro ATUAL (linha/objeto de `usuarios`: role, is_supervisor, e-mail),
     * no login (establishSession) e a cada requisição autenticada (SessionPolicy::avaliarSessaoAutenticada) — nunca da sessão anterior.
     * Regras (inalteradas desde a Etapa 2A): Supervisor Geral = role admin E is_supervisor = 1 (o flag sozinho não concede nada);
     * role vazia (dado legado inválido) só vira admin+supervisor se o e-mail for o supervisor configurado, senão viewer; role
     * preenchida e desconhecida = null (sem autoridade: o login é recusado e a sessão aberta é encerrada).
     *
     * @return array{role:string,supervisor:bool}|null
     */
    public static function autoridadeEfetiva(array|User $cadastro): ?array
    {
        $dados = is_array($cadastro) ? $cadastro : ['role' => $cadastro->role ?? '', 'is_supervisor' => $cadastro->is_supervisor ?? 0, 'email' => $cadastro->email];
        $role = strtolower(trim((string)($dados['role'] ?? '')));
        if (!self::roleAceitaNoLogin($role)) {
            return null;
        }
        // Supervisor Geral: decidido SÓ pela política central (role admin E flag = 1).
        $isSupervisor = Autoridade::ehSupervisorGeral(['role' => $role, 'is_supervisor' => (int)($dados['is_supervisor'] ?? 0)]);
        $supervisorEmail = strtolower(trim((string)(Config::app()['security']['supervisor_email'] ?? '')));
        // Repara cadastros legados com role vazia quando o e-mail bate com o supervisor configurado (ver AUTH_PERMISSOES_FIX.md);
        // é o único caso, além da própria coluna usuarios.is_supervisor, em que a sessão vira supervisor.
        if ($role === '' && $supervisorEmail !== '' && strtolower(trim((string)($dados['email'] ?? ''))) === $supervisorEmail) {
            $role = 'admin';
            $isSupervisor = true;
        }
        if ($role === '') {
            $role = 'viewer';
        }
        return ['role' => $role, 'supervisor' => $isSupervisor && $role === 'admin'];
    }

    /** Grava a autoridade efetiva na sessão — o flag de Supervisor SOMENTE com role admin (Etapa 2A). */
    public static function aplicarAutoridadeNaSessao(array $autoridade): void
    {
        $role = (string)$autoridade['role'];
        $isSupervisor = (bool)$autoridade['supervisor'];
        $_SESSION['user_role'] = $role;
        $_SESSION['user_is_supervisor'] = $isSupervisor && $role === 'admin';
    }

    private static function establishSession(User $user): void
    {
        $autoridade = self::autoridadeEfetiva($user) ?? ['role' => 'viewer', 'supervisor' => false];
        // is_supervisor NÃO é bypass por si só (Etapa 2A): só tem efeito junto com role admin.
        if ((int)($user->is_supervisor ?? 0) === 1 && $autoridade['role'] !== 'admin') {
            Logger::warning('Usuário is_supervisor=1 sem role admin: o flag foi ignorado', ['usuario_id' => (int)$user->id, 'role' => $autoridade['role']]);
        }
        $_SESSION['user'] = true;
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_name'] = $user->nome;
        self::aplicarAutoridadeNaSessao($autoridade);
        session_regenerate_id(true);
        // Marca o início da sessão autenticada (teto total da SessionPolicy) e a atividade; o ID já foi regenerado (sem fixação de sessão).
        $_SESSION['session_started_at'] = SessionPolicy::agora();
        $_SESSION['last_activity'] = SessionPolicy::agora();
        // Âncoras da sessão (SessionPolicy::avaliarSessaoAutenticada): vale só enquanto a senha e a ativação da conta forem as deste login.
        $_SESSION['credencial_impressao'] = SessionPolicy::impressaoCredencial((string)$user->senha_hash);
        $_SESSION['conta_ativa_desde'] = (string)($user->email_verified_at ?? '');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    /**
     * `usuarios.is_supervisor` só vale como supervisor quando a role é admin (Etapa 2A, 2026-11): a role determina as
     * capacidades gerais, a permissão as operações e o escopo setorial os dados — o flag, sozinho, nunca eleva uma role
     * rh/viewer. Também normaliza sessões abertas ANTES desta regra (o flag na sessão é reescrito aqui, e todos os
     * leitores de `$_SESSION['user_is_supervisor']` passam a ver o valor corrigido na mesma requisição).
     */
    public static function normalizarSupervisorDaSessao(): void
    {
        if (!empty($_SESSION['user_is_supervisor']) && strtolower(trim((string)($_SESSION['user_role'] ?? ''))) !== 'admin') {
            $_SESSION['user_is_supervisor'] = false;
        }
    }

    public static function canAccessAdmin(): bool
    {
        self::normalizarSupervisorDaSessao();
        if (!self::check()) {
            return false;
        }
        if (!empty($_SESSION['user_is_supervisor'])) {
            return true;
        }
        $role = strtolower(trim((string)(self::role() ?? '')));
        return in_array($role, ['admin', 'rh', 'gestor', 'viewer'], true);
    }

    public static function requireRole(array $roles): void
    {
        self::normalizarSupervisorDaSessao();
        if (!empty($_SESSION['user_is_supervisor'])) {
            return;
        }
        if (!self::check()) {
            redirect('/login');
        }
        $grantedRoles = array_map(static fn($r) => strtolower(trim((string)$r)), $roles);
        $currentRole = strtolower(trim((string)(self::role() ?? '')));
        if (!in_array($currentRole, $grantedRoles, true)) {
            http_response_code(403);
            echo 'Acesso negado';
            exit;
        }
    }
}
