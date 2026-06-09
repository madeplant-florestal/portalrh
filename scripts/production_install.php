<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/core/bootstrap.php';
echo "Iniciando instalação de produção...\n";
try {
    $app = Config::app();
    $db = $app['database'] ?? [];
    $mail = $app['mail'] ?? [];
    $security = $app['security'] ?? [];
    $logging = $app['logging'] ?? [];
    $input = [
        'app_env' => getenv('APP_ENV') ?: ($app['env'] ?? 'prod'),
        'config_mode' => getenv('CONFIG_MODE') ?: 'config',
        'allow_overwrite_config' => getenv('ALLOW_OVERWRITE_CONFIG') ?: '0',
        'db_dsn' => getenv('DB_DSN') ?: ($db['dsn'] ?? ''),
        'db_user' => getenv('DB_USER') ?: ($db['user'] ?? ''),
        'db_pass' => getenv('DB_PASS') ?: ($db['pass'] ?? ''),
        'mail_from' => getenv('MAIL_FROM') ?: ($mail['from'] ?? ''),
        'mail_to_hr' => getenv('MAIL_TO_HR') ?: ($mail['to_hr'] ?? ''),
        'supervisor_email' => getenv('SUPERVISOR_EMAIL') ?: ($security['supervisor_email'] ?? ''),
        'supervisor_password' => getenv('SUPERVISOR_PASSWORD') ?: ($security['supervisor_password'] ?? ''),
        'admin_email' => getenv('ADMIN_EMAIL') ?: 'fabio.ozuna@madeplant.com.br',
        'admin_password' => getenv('ADMIN_PASSWORD') ?: '',
        'log_level' => getenv('LOG_LEVEL') ?: ($logging['level'] ?? 'INFO'),
        'log_alert_email' => getenv('LOG_ALERT_EMAIL') ?: ($logging['alert_email'] ?? ''),
        'log_viewer_key' => getenv('LOG_VIEWER_KEY') ?: ($logging['viewer_key'] ?? ''),
    ];
    Installer::run($input, function (string $line): void {
        echo $line . PHP_EOL;
    });
    echo "Instalação concluída com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na instalação: " . $e->getMessage() . "\n");
    exit(1);
}
