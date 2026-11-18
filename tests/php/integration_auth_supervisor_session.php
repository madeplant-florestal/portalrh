<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_auth_supervisor_session (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$password = 'SenhaForte123!';
$hash = password_hash($password, PASSWORD_BCRYPT);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$adminEmail = 'tmp_admin_nosuper_' . time() . '@example.test';
$supervisorFlagEmail = 'tmp_supervisor_flag_' . time() . '@example.test';
$adminId = null;
$supervisorFlagId = null;

try {
    // Caso 1: role='admin' comum (is_supervisor=0) NAO deve mais virar
    // supervisor em sessao - este e' o bug corrigido nesta Sprint.
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['Admin Comum Teste', $adminEmail, $hash, 'admin', 0]);
    $adminId = (int)$pdo->lastInsertId();

    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_is_supervisor']);
    $result = Auth::attemptLogin($adminEmail, $password);
    $assert(($result['ok'] ?? false) === true, 'Falha: login do admin comum deveria ter sucesso.');
    $assert(($_SESSION['user_role'] ?? '') === 'admin', 'Falha: role da sessao deveria continuar admin.');
    $assert(empty($_SESSION['user_is_supervisor']), 'Falha: admin comum (is_supervisor=0) nao deveria virar supervisor em sessao.');

    // Caso 2 (Etapa 2A, 2026-11): is_supervisor=1 NAO e' bypass por si so - com role viewer a sessao
    // NAO vira supervisor (o perfil determina as capacidades); so vale junto com role admin (caso 3).
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['Supervisor Flag Teste', $supervisorFlagEmail, $hash, 'viewer', 1]);
    $supervisorFlagId = (int)$pdo->lastInsertId();

    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_is_supervisor']);
    $result = Auth::attemptLogin($supervisorFlagEmail, $password);
    $assert(($result['ok'] ?? false) === true, 'Falha: login do usuario com is_supervisor=1 deveria ter sucesso.');
    $assert(empty($_SESSION['user_is_supervisor']), 'Falha: is_supervisor=1 com role viewer nao deve virar supervisor em sessao.');
    $assert(($_SESSION['user_role'] ?? '') === 'viewer', 'Falha: a role da sessao deve continuar viewer.');

    // Caso 3: is_supervisor=1 COM role admin continua sendo supervisor (acesso legitimo preservado).
    $supervisorAdminEmail = 'tmp_supervisor_admin_' . time() . '@example.test';
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['Supervisor Admin Teste', $supervisorAdminEmail, $hash, 'admin', 1]);
    $supervisorAdminId = (int)$pdo->lastInsertId();
    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_is_supervisor']);
    $result = Auth::attemptLogin($supervisorAdminEmail, $password);
    $assert(($result['ok'] ?? false) === true && !empty($_SESSION['user_is_supervisor']), 'Falha: supervisor com role admin deve continuar supervisor em sessao.');
} finally {
    if (isset($supervisorAdminId)) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$supervisorAdminId]);
    }
    if ($adminId !== null) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$adminId]);
    }
    if ($supervisorFlagId !== null) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$supervisorFlagId]);
    }
}

echo "OK integration_auth_supervisor_session\n";
