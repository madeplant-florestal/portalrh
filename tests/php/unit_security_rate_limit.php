<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$scope = 'login';
$key = '127.0.0.1|unit-rate-limit@example.test';
$maxAttempts = 5;
$windowSeconds = 600;
$lockoutSeconds = 900;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

Security::rateLimitReset($scope, $key);
$check = Security::rateLimitCheck($scope, $key, $maxAttempts, $windowSeconds, $lockoutSeconds);
$assert(($check['blocked'] ?? true) === false, 'Falha: chave nova não deveria iniciar bloqueada.');

for ($i = 1; $i < $maxAttempts; $i++) {
    $state = Security::rateLimitHit($check['file'], $check['data'], false, $lockoutSeconds, $maxAttempts, $windowSeconds);
    $assert(($state['blocked'] ?? true) === false, 'Falha: bloqueio ocorreu antes do limite máximo de tentativas.');
    $check = Security::rateLimitCheck($scope, $key, $maxAttempts, $windowSeconds, $lockoutSeconds);
}

$state = Security::rateLimitHit($check['file'], $check['data'], false, $lockoutSeconds, $maxAttempts, $windowSeconds);
$assert(($state['blocked'] ?? false) === true, 'Falha: a última tentativa deveria acionar bloqueio.');

$blocked = Security::rateLimitCheck($scope, $key, $maxAttempts, $windowSeconds, $lockoutSeconds);
$assert(($blocked['blocked'] ?? false) === true, 'Falha: rateLimitCheck deveria refletir o bloqueio persistido.');
$assert((int)($blocked['retry_after'] ?? 0) > 0, 'Falha: retry_after deveria ser maior que zero após o bloqueio.');

Security::rateLimitReset($scope, $key);
$reset = Security::rateLimitCheck($scope, $key, $maxAttempts, $windowSeconds, $lockoutSeconds);
$assert(($reset['blocked'] ?? true) === false, 'Falha: reset do rate limit não removeu o bloqueio.');

echo "OK unit_security_rate_limit\n";
