<?php
set_time_limit(10);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_password_reset (MySQL indisponível)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$email = 'reset_' . time() . '@rhmadeplant.local';
$oldHash = password_hash('Abcd1234!xyz', PASSWORD_BCRYPT);
$userId = User::create('Reset User', $email, $oldHash, 'viewer');

$token = bin2hex(random_bytes(32));
PasswordReset::create($userId, $token, 30);
$row = PasswordReset::findValidByToken($token);
if (!$row) {
    fwrite(STDERR, "Falha: token válido não encontrado.\n");
    exit(1);
}

$newPassword = 'Abcd1234!xyzNOVA';
$updated = User::updatePassword($userId, password_hash($newPassword, PASSWORD_BCRYPT));
if (!$updated) {
    fwrite(STDERR, "Falha: não atualizou senha.\n");
    exit(1);
}
PasswordReset::markUsed((int)$row['id']);
$rowAfter = PasswordReset::findValidByToken($token);
if ($rowAfter) {
    fwrite(STDERR, "Falha: token deveria estar inválido após uso.\n");
    exit(1);
}

$user = User::findById($userId);
if (!$user || !password_verify($newPassword, $user->senha_hash)) {
    fwrite(STDERR, "Falha: senha atual não confere.\n");
    exit(1);
}

echo "OK integration_password_reset\n";
