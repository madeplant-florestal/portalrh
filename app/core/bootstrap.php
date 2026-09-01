<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'app');
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH);
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');
}

// Dependência escopada via Composer só para geração de PDF (Dompdf) — ver CLAUDE.md §4/§21.
// O restante do projeto continua com autoload manual via glob() abaixo, sem Composer.
$vendorAutoload = BASE_PATH . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MetadadosDatabase.php';
require_once __DIR__ . '/MetadadosSyncSignature.php';
require_once __DIR__ . '/PasswordPolicy.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Cipher.php';
require_once __DIR__ . '/DateHelper.php';
require_once __DIR__ . '/Phone.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/AdminCatalogosController.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Upload.php';
require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Installer.php';

foreach (['dtos', 'requests', 'validators', 'repositories', 'services', 'models', 'controllers'] as $directory) {
    $target = APP_PATH . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($target)) {
        continue;
    }
    foreach (glob($target . DIRECTORY_SEPARATOR . '*.php') as $file) {
        require_once $file;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $config = Config::get();
        return rtrim((string)($config['app']['base_url'] ?? ''), '/');
    }
}

if (!function_exists('url')) {
    function url($path = ''): string
    {
        $path = (string)$path;
        if ($path !== '' && preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = app_base_url();
        if ($path === '' || $path === '/') {
            return $base;
        }
        $basePath = (string)parse_url($base, PHP_URL_PATH);
        $basePath = rtrim($basePath, '/');
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        if (str_starts_with($path, '/')) {
            if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
                return $origin . $path;
            }
            return $base . $path;
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect($path): void
    {
        header('Location: ' . url((string)$path));
        exit;
    }
}

$app = Config::app();
$env = strtolower((string)($app['env'] ?? 'production'));
$debug = ($env === 'dev' || $env === 'development' || $env === 'debug');

@mkdir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs', 0775, true);
@mkdir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions', 0775, true);
@mkdir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'resumes', 0775, true);
@mkdir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'ratelimit', 0775, true);
@mkdir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'audit', 0775, true);
@mkdir(BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos', 0775, true);

$errorLogFile = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . ($debug ? 'app-error.log' : 'error.log');
@ini_set('log_errors', '1');
@ini_set('error_log', $errorLogFile);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

Logger::init($app);
Security::startSecureSession($app['security']['session_name'] ?? 'RHMADEPLANTSESSID');
Security::enforceInactivityTimeout(1200);

set_exception_handler(function (\Throwable $e) use ($debug): void {
    http_response_code(500);
    Logger::exception($e, 'CRITICAL', Logger::captureContext(500));
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    }
    if ($debug) {
        echo "Erro 500\n\n";
        echo $e;
        return;
    }
    echo 'Erro interno do servidor.';
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    $level = 'WARNING';
    if (in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $level = 'ERROR';
    } elseif (in_array($severity, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) {
        $level = 'INFO';
    }
    Logger::log($level, $message, Logger::captureContext(http_response_code(), [
        'php_error' => ['severity' => $severity, 'file' => $file, 'line' => $line]
    ]));
    return false;
});

register_shutdown_function(function () use ($debug): void {
    $last = error_get_last();
    if (!is_array($last)) {
        return;
    }
    if (!in_array((int)$last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    http_response_code(500);
    Logger::critical('Fatal shutdown error', Logger::captureContext(500, ['fatal' => $last]));
    if ($debug && !headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Fatal 500\n";
        echo $last['message'] . ' @ ' . $last['file'] . ':' . $last['line'];
    }
});
