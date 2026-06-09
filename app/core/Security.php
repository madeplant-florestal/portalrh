<?php
class Security
{
    public static function startSecureSession(string $name = 'RHMADEPLANTSESSID'): void
    {
        $storagePath = defined('STORAGE_PATH') ? \STORAGE_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        $savePath = $storagePath . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0775, true);
        }
        if (is_dir($savePath)) {
            @ini_set('session.save_path', $savePath);
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || ($proto && strtolower($proto) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name($name);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['__session_initialized'])) {
            session_regenerate_id(true);
            $_SESSION['__session_initialized'] = 1;
        }
    }

    public static function enforceInactivityTimeout(int $seconds = 1200): void
    {
        $now = time();
        $last = $_SESSION['last_activity'] ?? null;
        $isLogged = !empty($_SESSION['user_id']);
        if ($isLogged && $last !== null && ($now - (int)$last) > $seconds) {
            Auth::logout();
            redirect('/admin/login?expired=1');
        }
        $_SESSION['last_activity'] = $now;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function sanitizeString(?string $str): string
    {
        $str = trim((string)$str);
        $str = strip_tags($str);
        return $str;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfCheck(?string $token): bool
    {
        if (!isset($token) || !isset($_SESSION['csrf_token'])) {
            return false;
        }
        return (string)$token === (string)$_SESSION['csrf_token'];
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }
        return $ip;
    }

    public static function rateLimitCheck(string $scope, string $key, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): array
    {
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = self::rateLimitFile($scope, $key);
        $now = time();
        $data = [
            'scope' => $scope,
            'key' => $key,
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'lockout_seconds' => $lockoutSeconds,
            'attempts' => [],
            'locked_until' => 0
        ];
        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $tmp = json_decode($raw, true);
                if (is_array($tmp)) {
                    $data = array_merge($data, $tmp);
                }
            }
        }
        $data['attempts'] = array_values(array_filter($data['attempts'], function ($ts) use ($now, $windowSeconds) {
            return ($now - (int)$ts) <= $windowSeconds;
        }));
        if ($now >= (int)($data['locked_until'] ?? 0)) {
            $data['locked_until'] = 0;
        }
        $attemptsCount = count($data['attempts']);
        $blocked = $now < (int)$data['locked_until'];
        $retryAfter = 0;
        if ($blocked) {
            $retryAfter = max(0, (int)$data['locked_until'] - $now);
        }
        return [
            'blocked' => $blocked,
            'retry_after' => $retryAfter,
            'attempts_count' => $attemptsCount,
            'remaining_attempts' => max(0, $maxAttempts - $attemptsCount),
            'file' => $file,
            'data' => $data
        ];
    }

    public static function rateLimitHit(string $file, array $data, bool $success, int $lockoutSeconds, int $maxAttempts = 5, int $windowSeconds = 600): array
    {
        $now = time();
        $data['attempts'] = array_values(array_filter((array)($data['attempts'] ?? []), function ($ts) use ($now, $windowSeconds) {
            return ($now - (int)$ts) <= $windowSeconds;
        }));
        if ($success) {
            $data['attempts'] = [];
            $data['locked_until'] = 0;
        } else {
            $data['attempts'][] = $now;
            if (count($data['attempts']) >= $maxAttempts) {
                $data['locked_until'] = $now + $lockoutSeconds;
            } else {
                $data['locked_until'] = 0;
            }
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $attemptsCount = count($data['attempts']);
        $blocked = $now < (int)$data['locked_until'];
        return [
            'blocked' => $blocked,
            'retry_after' => $blocked ? max(0, (int)$data['locked_until'] - $now) : 0,
            'attempts_count' => $attemptsCount,
            'remaining_attempts' => max(0, $maxAttempts - $attemptsCount),
            'file' => $file,
            'data' => $data
        ];
    }

    public static function rateLimitReset(string $scope, string $key): void
    {
        $file = self::rateLimitFile($scope, $key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function isValidCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11) { return false; }
        // rejeitar sequências de dígitos iguais
        if (preg_match('/^(\d)\1{10}$/', $cpf)) { return false; }
        // calcular primeiros 9 dígitos
        $sum = 0;
        for ($i = 0, $weight = 10; $i < 9; $i++, $weight--) {
            $sum += ((int)$cpf[$i]) * $weight;
        }
        $rest = $sum % 11;
        $d1 = ($rest < 2) ? 0 : 11 - $rest;
        if ((int)$cpf[9] !== $d1) { return false; }
        // segundo dígito
        $sum = 0;
        for ($i = 0, $weight = 11; $i < 10; $i++, $weight--) {
            $sum += ((int)$cpf[$i]) * $weight;
        }
        $rest = $sum % 11;
        $d2 = ($rest < 2) ? 0 : 11 - $rest;
        return (int)$cpf[10] === $d2;
    }

    private static function rateLimitFile(string $scope, string $key): string
    {
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $hash = hash('sha256', $scope . '|' . $key);
        return $dir . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}
