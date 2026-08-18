<?php
class Logger
{
    private static bool $initialized = false;
    private static string $logDir = '';
    private static string $minLevel = 'INFO';
    private static ?string $alertEmail = null;
    private static string $appEnv = 'prod';
    private static string $appName = 'rhmadeplant';
    private const LEVELS = ['DEBUG' => 10, 'INFO' => 20, 'WARNING' => 30, 'ERROR' => 40, 'CRITICAL' => 50];

    public static function init(array $app): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$appEnv = (string)($app['env'] ?? 'prod');
        self::$appName = (string)($app['name'] ?? 'RH Madeplant');
        $logging = $app['logging'] ?? [];
        self::$minLevel = strtoupper((string)($logging['level'] ?? 'INFO'));
        if (!isset(self::LEVELS[self::$minLevel])) {
            self::$minLevel = 'INFO';
        }
        $email = trim((string)($logging['alert_email'] ?? ''));
        self::$alertEmail = $email !== '' ? $email : null;

        $dir = defined('STORAGE_PATH') ? \STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        self::$logDir = $dir;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('CRITICAL', $message, $context);
    }

    public static function exception(Throwable $e, string $level = 'ERROR', array $context = []): void
    {
        $status = http_response_code();
        if ($status < 400) {
            $status = 500;
        }
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'code' => $e->getCode(),
        ];
        $context['http_status'] = $status;
        self::log($level, 'Unhandled exception', $context);
    }

    public static function captureContext(int $statusCode = 0, array $extra = []): array
    {
        $db = Config::app()['database'] ?? [];
        $session = $_SESSION ?? [];
        return array_merge([
            'request' => [
                'url' => self::requestUrl(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'status' => $statusCode > 0 ? $statusCode : http_response_code(),
                'get' => self::redact($_GET ?? []),
                'post' => self::redact($_POST ?? []),
            ],
            'server' => self::redact(self::filteredServer($_SERVER ?? [])),
            'session' => self::redact($session),
            'database' => [
                'dsn' => (string)($db['dsn'] ?? ''),
                'user' => self::mask((string)($db['user'] ?? '')),
            ],
        ], $extra);
    }

    public static function readLatest(int $maxLines = 300): array
    {
        if (self::$logDir === '') {
            return [];
        }
        $file = self::$logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $lines = array_slice($lines, -1 * max(1, $maxLines));
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);
        if (!isset(self::LEVELS[$level])) {
            $level = 'INFO';
        }
        if (self::LEVELS[$level] < self::LEVELS[self::$minLevel]) {
            return;
        }
        if (self::$logDir === '') {
            return;
        }
        $file = self::$logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.jsonl';
        $record = [
            'timestamp' => date('c'),
            'level' => $level,
            'app' => self::$appName,
            'env' => self::$appEnv,
            'message' => $message,
            'context' => self::redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0644);

        if (($level === 'CRITICAL' || $level === 'ERROR') && self::$alertEmail !== null) {
            $subject = '[RH Madeplant] ' . $level . ' detectado';
            $body = "Mensagem: {$message}\nTimestamp: {$record['timestamp']}\nURL: " . self::requestUrl();
            @mail(self::$alertEmail, $subject, $body);
        }
    }

    private static function requestUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return $scheme . '://' . $host . $uri;
    }

    private static function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
    }

    private static function redact($value)
    {
        $sensitive = ['password', 'pass', 'senha', 'token', 'csrf', 'cookie', 'authorization', 'db_pass', 'supervisor_password', 'secret'];
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = strtolower((string)$k);
                $hide = false;
                foreach ($sensitive as $needle) {
                    if (strpos($key, $needle) !== false) {
                        $hide = true;
                        break;
                    }
                }
                $out[$k] = $hide ? '[REDACTED]' : self::redact($v);
            }
            return $out;
        }
        if (is_object($value)) {
            return '[OBJECT ' . get_class($value) . ']';
        }
        if (is_string($value) && strlen($value) > 600) {
            return substr($value, 0, 600) . '...[TRUNCATED]';
        }
        return $value;
    }

    private static function filteredServer(array $server): array
    {
        $keys = [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'QUERY_STRING',
            'HTTP_HOST',
            'HTTP_USER_AGENT',
            'REMOTE_ADDR',
            'REMOTE_PORT',
            'SERVER_NAME',
            'SERVER_PORT',
            'SERVER_PROTOCOL',
            'REQUEST_TIME',
            'REQUEST_TIME_FLOAT',
            'HTTP_REFERER',
            'HTTPS',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
        ];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $server)) {
                $out[$key] = $server[$key];
            }
        }
        foreach ($server as $k => $v) {
            if (strpos((string)$k, 'HTTP_') === 0 && !array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
