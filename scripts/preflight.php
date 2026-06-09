<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/core/bootstrap.php';

$checks = [];
$add = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='));
$add('Extensão pdo_mysql', extension_loaded('pdo_mysql'));
$add('Arquivo public/index.php', is_file(BASE_PATH . '/public/index.php'));
$add('Arquivo install.php', is_file(BASE_PATH . '/install.php'));
$add('Arquivo schema.sql', is_file(BASE_PATH . '/database/schema.sql'));

$req = Installer::preflightSummary();
foreach ($req['failed_requirements'] as $failed) {
    $add('Requisito Installer', false, $failed);
}
if (count($req['failed_requirements']) === 0) {
    $add('Requisitos Installer', true);
}

$app = Config::app();
$db = $app['database'] ?? [];
$dsn = (string)($db['dsn'] ?? '');
$user = (string)($db['user'] ?? '');
$pass = (string)($db['pass'] ?? '');
if ($dsn !== '' && $user !== '') {
    try {
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 2,
        ]);
        $pdo->query('SELECT 1');
        $add('Conectividade com banco', true);
    } catch (\Throwable $e) {
        $add('Conectividade com banco', false, $e->getMessage());
    }
} else {
    $add('Conectividade com banco', false, 'DSN/usuário não configurados.');
}

$okAll = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $okAll = false;
    }
    echo ($c['ok'] ? '[OK] ' : '[FAIL] ') . $c['name'];
    if ($c['detail'] !== '') {
        echo ' - ' . $c['detail'];
    }
    echo PHP_EOL;
}

echo PHP_EOL . 'Resumo: ' . ($okAll ? 'APROVADO' : 'FALHAS ENCONTRADAS') . PHP_EOL;
exit($okAll ? 0 : 1);
