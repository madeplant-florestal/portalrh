<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$email = 'tmp_auth_' . time() . '@example.test';
$password = 'SenhaForte123!';
$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())');
$stmt->execute(['Usuário Temporário', $email, $hash, 'viewer', 0]);
$userId = (int)$pdo->lastInsertId();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

try {
    $missing = Auth::attemptLogin('naoexiste@example.test', $password);
    $assert(($missing['reason'] ?? '') === 'user_not_found', 'Falha: usuário inexistente deveria retornar reason user_not_found.');

    $pdo->prepare('UPDATE usuarios SET email_verified_at = NULL WHERE id = ?')->execute([$userId]);
    $inactive = Auth::attemptLogin($email, $password);
    $assert(($inactive['reason'] ?? '') === 'inactive_account', 'Falha: usuário inativo deveria retornar reason inactive_account.');

    $pdo->prepare('UPDATE usuarios SET email_verified_at = NOW() WHERE id = ?')->execute([$userId]);
    $invalidPassword = Auth::attemptLogin($email, 'SenhaIncorreta123!');
    $assert(($invalidPassword['reason'] ?? '') === 'invalid_password', 'Falha: senha incorreta deveria retornar reason invalid_password.');

    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_is_supervisor']);
    $success = Auth::attemptLogin($email, $password);
    $assert(($success['ok'] ?? false) === true, 'Falha: login válido deveria autenticar com sucesso.');
    $assert((int)($_SESSION['user_id'] ?? 0) === $userId, 'Falha: sessão não foi preenchida com o usuário autenticado.');
} finally {
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$userId]);
}

echo "OK unit_auth_login_diagnostics\n";
