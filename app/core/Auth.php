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
        self::establishSession($user);
        return [
            'ok' => true,
            'reason' => 'success',
            'message' => 'Login realizado com sucesso.',
            'user' => $user
        ];
    }

    public static function login(string $email, string $password): bool
    {
        $attempt = self::attemptLogin($email, $password);
        return (bool)($attempt['ok'] ?? false);
    }

    private static function establishSession(User $user): void
    {
        $role = strtolower(trim((string)($user->role ?? '')));
        $isSupervisor = (int)($user->is_supervisor ?? 0) === 1;
        $supervisorEmail = strtolower(trim((string)(Config::app()['security']['supervisor_email'] ?? '')));
        // Repara cadastros legados com role vazia quando o e-mail bate com o
        // supervisor configurado (ver AUTH_PERMISSOES_FIX.md); é o único caso,
        // além da própria coluna usuarios.is_supervisor, em que promovemos a
        // sessão a supervisor. Não promover mais todo usuário role='admin' —
        // isso é o que estava sendo corrigido aqui.
        if ($role === '' && $supervisorEmail !== '' && strtolower((string)$user->email) === $supervisorEmail) {
            $role = 'admin';
            $isSupervisor = true;
        }
        if ($role === '') {
            $role = 'viewer';
        }
        $_SESSION['user'] = true;
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_role'] = $role;
        $_SESSION['user_name'] = $user->nome;
        $_SESSION['user_is_supervisor'] = $isSupervisor;
        session_regenerate_id(true);
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

    public static function canAccessAdmin(): bool
    {
        if (!self::check()) {
            return false;
        }
        if (!empty($_SESSION['user_is_supervisor'])) {
            return true;
        }
        $role = strtolower(trim((string)(self::role() ?? '')));
        return in_array($role, ['admin', 'rh', 'viewer'], true);
    }

    public static function requireRole(array $roles): void
    {
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
