<?php
set_time_limit(10);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_admin_password_change (MySQL indisponível)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$suffix = (string)time();
$targetEmail = 'target_' . $suffix . '@rhmadeplant.local';
$adminEmail = 'admin_' . $suffix . '@rhmadeplant.local';
$rhEmail = 'rh_' . $suffix . '@rhmadeplant.local';

$targetId = User::create('Target User', $targetEmail, password_hash('SenhaInicial123!', PASSWORD_BCRYPT), 'viewer');
$adminId = User::create('Admin User', $adminEmail, password_hash('AdminSenha123!', PASSWORD_BCRYPT), 'admin');
$rhId = User::create('RH User', $rhEmail, password_hash('RhSenha123!abc', PASSWORD_BCRYPT), 'rh');

$targetBefore = User::findById($targetId);
$admin = User::findById($adminId);
$rh = User::findById($rhId);

$unauthorized = User::adminChangePassword($targetId, 'NovaSenhaValida123!', $rh, '127.0.0.1');
if (($unauthorized['ok'] ?? false) !== false || (int)($unauthorized['status'] ?? 0) !== 403) {
    fwrite(STDERR, "Falha: usuário sem permissão administrativa não deveria alterar senha.\n");
    exit(1);
}

$weakPassword = User::adminChangePassword($targetId, 'fraca', $admin, '127.0.0.1');
if (($weakPassword['ok'] ?? false) !== false || (int)($weakPassword['status'] ?? 0) !== 422) {
    fwrite(STDERR, "Falha: senha fraca deveria ser rejeitada pela política de complexidade.\n");
    exit(1);
}

$success = User::adminChangePassword($targetId, 'NovaSenhaValida123!', $admin, '127.0.0.1');
if (($success['ok'] ?? false) !== true) {
    fwrite(STDERR, "Falha: administrador deveria conseguir alterar a senha.\n");
    exit(1);
}

$targetAfter = User::findById($targetId);
if (!$targetBefore || !$targetAfter || $targetBefore->senha_hash === $targetAfter->senha_hash) {
    fwrite(STDERR, "Falha: hash de senha do usuário alvo não foi atualizado.\n");
    exit(1);
}
if (!password_verify('NovaSenhaValida123!', $targetAfter->senha_hash)) {
    fwrite(STDERR, "Falha: nova senha não confere com hash persistido.\n");
    exit(1);
}

$stmt = Database::conn()->prepare("SELECT COUNT(*) FROM auditoria_usuarios WHERE action = 'admin_password_change' AND actor_usuario_id = ? AND target_usuario_id = ?");
$stmt->execute([$adminId, $targetId]);
$count = (int)$stmt->fetchColumn();
if ($count < 1) {
    fwrite(STDERR, "Falha: alteração de senha por administrador não foi registrada na auditoria.\n");
    exit(1);
}

echo "OK integration_admin_password_change\n";
