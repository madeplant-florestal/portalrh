<?php
class Config
{
    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $baseConfigPath = __DIR__ . '/../config/config.php';
        $config = [
            'app' => [
                'name' => 'RH Madeplant',
                'version' => '1.0.0',
                'release_date' => '',
                'base_url' => '',
                'public_jobs_url' => '',
                'env' => 'auto'
            ],
            'database' => [
                'dsn' => '',
                'user' => '',
                'pass' => ''
            ],
            'security' => [
                'csrf_key' => 'csrf_token',
                'session_name' => 'RHMADEPLANTSESSID',
                'webhook_allow_private_targets' => false
            ],
            'mail' => [
                'enabled' => false,
                'from' => '',
                'to_hr' => ''
            ],
            'logging' => [
                'level' => 'INFO',
                'alert_email' => '',
                'viewer_key' => ''
            ]
        ];
        if (is_file($baseConfigPath)) {
            $base = require $baseConfigPath;
            if (is_array($base)) {
                $config = array_replace_recursive($config, $base);
            }
        }

        $localPath = __DIR__ . '/../config/local.php';
        if (is_file($localPath)) {
            $local = require $localPath;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }

        $buildPath = __DIR__ . '/../config/build.php';
        if (is_file($buildPath)) {
            $build = require $buildPath;
            if (is_array($build)) {
                $config = array_replace_recursive($config, $build);
            }
        }

        $env = self::detectEnv((string)($config['app']['env'] ?? 'auto'));
        $config['app']['env'] = $env;
        $config['app']['base_url'] = self::detectBaseUrl((string)($config['app']['base_url'] ?? ''));
        $config['app']['public_jobs_url'] = self::detectPublicJobsUrl(
            (string)($config['app']['public_jobs_url'] ?? ''),
            (string)$config['app']['base_url']
        );

        self::$cache = $config;
        return self::$cache;
    }

    public static function app(): array
    {
        $cfg = self::get();
        return [
            'name' => $cfg['app']['name'],
            'product_name' => $cfg['app']['name'],
            'version' => (string)($cfg['app']['version'] ?? '1.0.0'),
            'release_date' => (string)($cfg['app']['release_date'] ?? ''),
            'base_url' => (string)($cfg['app']['base_url'] ?? ''),
            'public_jobs_url' => (string)($cfg['app']['public_jobs_url'] ?? ''),
            'env' => (string)($cfg['app']['env'] ?? 'development'),
            'security' => $cfg['security'],
            'mail' => $cfg['mail'],
            'logging' => $cfg['logging'],
            'database' => [
                'dsn' => $cfg['database']['dsn'],
                'user' => $cfg['database']['user'],
                'pass' => $cfg['database']['pass'],
                'options' => [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_TIMEOUT => 5
                ]
            ]
        ];
    }

    private static function detectEnv(string $configured): string
    {
        $configured = strtolower(trim($configured));
        if ($configured !== '' && $configured !== 'auto') {
            return $configured;
        }
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '' && PHP_SAPI === 'cli') {
            return 'development';
        }
        if (
            $host === 'localhost' ||
            str_starts_with($host, 'localhost:') ||
            $host === '127.0.0.1' ||
            str_starts_with($host, '127.0.0.1:') ||
            $host === '::1'
        ) {
            return 'development';
        }
        return 'production';
    }

    private static function detectBaseUrl(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $isHttps =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            strtolower($proto) === 'https';
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePath = rtrim(dirname($scriptName), '/');
        if ($basePath === '.' || $basePath === '\\' || $basePath === '/') {
            $basePath = '';
        }
        return rtrim($scheme . '://' . $host . $basePath, '/');
    }

    private static function detectPublicJobsUrl(string $configured, string $baseUrl): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            if (preg_match('#^https?://#i', $configured)) {
                return rtrim($configured, '/');
            }
            return rtrim($baseUrl, '/') . '/' . ltrim($configured, '/');
        }
        return rtrim($baseUrl, '/') . '/vagas';
    }
}
