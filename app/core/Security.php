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
        // Duração da sessão: política central (SessionPolicy). O gc_maxlifetime precisa ser definido ANTES do session_start — sem isso o coletor do PHP
        // apagava sessões ociosas por ~24 min, independentemente do limite lógico da aplicação.
        self::$statusIni = SessionPolicy::aplicarIni();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!self::$statusIni['aceito'] && empty($_SESSION['__aviso_gc_registrado'])) {
            $_SESSION['__aviso_gc_registrado'] = 1;
            Logger::warning('A hospedagem NÃO aceitou session.gc_maxlifetime: o prazo real da sessão pode ser menor que o configurado', self::$statusIni);
        }
        if (empty($_SESSION['__session_initialized'])) {
            session_regenerate_id(true);
            $_SESSION['__session_initialized'] = 1;
        }
    }

    /** Resultado de SessionPolicy::aplicarIni() desta requisição (diagnóstico). @var array{gc_maxlifetime_pedido:int,gc_maxlifetime_efetivo:int,aceito:bool}|null */
    public static ?array $statusIni = null;

    /**
     * Valida e renova a sessão conforme SessionPolicy (ociosidade 8 h por padrão + teto total). Sessão expirada NUNCA é renovada: é encerrada
     * (destruída no servidor) e o usuário vai ao login — com 401 JSON em chamadas AJAX/API. O parâmetro $seconds existe só por compatibilidade.
     */
    public static function enforceInactivityTimeout(?int $seconds = null): void
    {
        $motivo = SessionPolicy::avaliar(SessionPolicy::agora());
        if ($motivo === 'ok') {
            return;
        }
        $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $ajax = self::requisicaoAjax();
        Auth::logout();
        if ($ajax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Sua sessão expirou. Entre novamente — esta ação NÃO foi concluída.', 'erro' => 'sessao_expirada', 'motivo' => $motivo], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $q = ['expired' => '1'];
        if ($motivo === 'limite_total') {
            $q['motivo'] = 'limite';
        }
        if ($metodo === 'GET') {
            $next = self::caminhoDeRetornoSeguro($uri);
            if ($next !== null) {
                $q['next'] = $next;
            }
        } else {
            $q['perdido'] = '1'; // POST/PUT...: o envio NÃO foi processado — a tela de login avisa (sem perda silenciosa)
        }
        redirect('/admin/login?' . http_build_query($q));
    }

    public static function requisicaoAjax(): bool
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $tipo = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($tipo, 'application/json')
            || str_contains($uri, '/api/');
    }

    /**
     * Caminho para voltar depois do login: SOMENTE caminho relativo ao mesmo site (começa com uma única "/", sem esquema, sem barra invertida nem
     * caracteres de controle), dentro de /admin e fora de login/logout — evita redirecionamento aberto. `null` = não aproveitar.
     */
    public static function caminhoDeRetornoSeguro(?string $uri): ?string
    {
        $uri = trim((string)$uri);
        if ($uri === '' || strlen($uri) > 500 || $uri[0] !== '/' || str_starts_with($uri, '//') || str_contains($uri, '\\') || preg_match('/[\x00-\x1f\x7f]/', $uri)) {
            return null;
        }
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if (!preg_match('#/admin(/|$)#', $path) || preg_match('#/(login|logout)(/|$)#', $path)) {
            return null;
        }
        return $uri;
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

    /**
     * Limite simples "no máximo $max solicitações em $janela segundos" (janela deslizante), com bloqueio de arquivo (sem corrida entre requisições
     * simultâneas). Toda chamada CONTA a tentativa. Devolve true se ainda estava dentro do limite. A chave é gravada só como hash (nunca o e-mail/IP em claro).
     */
    public static function rateLimitConsume(string $scope, string $key, int $max, int $janela): bool
    {
        $arquivo = self::rateLimitFile($scope, $key);
        $fp = @fopen($arquivo, 'c+');
        if ($fp === false) {
            return true; // sem como medir: não bloqueia (o login/endpoint segue disponível); o erro de disco aparece nos logs do servidor
        }
        flock($fp, LOCK_EX);
        $agora = time();
        $dados = json_decode((string)stream_get_contents($fp), true);
        $tentativas = array_values(array_filter(is_array($dados['tentativas'] ?? null) ? $dados['tentativas'] : [], static fn($t): bool => ($agora - (int)$t) < $janela));
        $dentro = count($tentativas) < $max;
        $tentativas[] = $agora;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(['tentativas' => $tentativas], JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
        fclose($fp);
        return $dentro;
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
