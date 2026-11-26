<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/core/bootstrap.php';
require_once __DIR__ . '/lib/senha_cli.php';

// Uso: RH_NOVA_SENHA='...' php scripts/reset_password_cli.php <email>   (ou sem a variável, digitando a senha no terminal)
// Sem e-mail ou sem senha: nada é alterado. Nunca existe senha padrão. A troca encerra as sessões abertas do usuário.
$email = trim((string)($argv[1] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    senhaCliAbortar('Informe o e-mail da conta: php scripts/reset_password_cli.php <email>. Nada foi alterado.');
}
senhaCliRecusarArgumento($argv, 2, 'RH_NOVA_SENHA');
$newPassword = senhaCliObrigatoria('RH_NOVA_SENHA');

$timestamp = date('c');
$auditDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'audit';
if (!is_dir($auditDir)) {
    @mkdir($auditDir, 0775, true);
}
$auditFile = $auditDir . DIRECTORY_SEPARATOR . 'password_change.log';

$log = static function (array $payload) use ($auditFile): void {
    @file_put_contents($auditFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
};

try {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    if ($hash === false) {
        throw new RuntimeException('Falha ao gerar hash da senha.');
    }

    if (!password_verify($newPassword, $hash)) {
        throw new RuntimeException('Hash incompatível com password_verify.');
    }

    $pdo = Database::conn();
    $sql = 'UPDATE usuarios SET senha_hash = ?, last_password_reset_at = NOW() WHERE email = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hash, $email]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Usuário não encontrado para o e-mail informado.');
    }

    $checkStmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE email = ? LIMIT 1');
    $checkStmt->execute([$email]);
    $storedHash = (string)($checkStmt->fetchColumn() ?: '');
    if ($storedHash === '' || !password_verify($newPassword, $storedHash)) {
        throw new RuntimeException('Falha na validação final do hash persistido.');
    }

    $algoInfo = password_get_info($storedHash);
    foreach (['127.0.0.1', '::1', '0.0.0.0'] as $ip) {
        Security::rateLimitReset('login', $ip . '|' . strtolower($email));
    }
    $payload = [
        'timestamp' => $timestamp,
        'action' => 'password_reset_direct_update',
        'email' => $email,
        'algo_name' => $algoInfo['algoName'] ?? 'unknown',
        'hash_prefix' => substr($storedHash, 0, 7),
        'result' => 'success',
    ];
    $log($payload);

    echo "OK: senha atualizada com sucesso.\n";
    echo "Email: {$email}\n";
    echo "Algoritmo: " . ($algoInfo['algoName'] ?? 'desconhecido') . "\n";
    exit(0);
} catch (Throwable $e) {
    $payload = [
        'timestamp' => $timestamp,
        'action' => 'password_reset_direct_update',
        'email' => $email,
        'result' => 'failed',
        'error' => $e->getMessage(),
    ];
    $log($payload);

    fwrite(STDERR, "ERRO: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
