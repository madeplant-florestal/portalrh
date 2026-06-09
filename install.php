<?php
declare(strict_types=1);
require_once __DIR__ . '/app/core/bootstrap.php';
$app = Config::app();

if (($app['env'] ?? 'prod') === 'dev' && isset($_GET['__force500'])) {
    throw new RuntimeException('Falha forçada para teste de logging 500');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = $_SESSION['install_csrf'] ?? '';
if ($token === '') {
    $token = bin2hex(random_bytes(32));
    $_SESSION['install_csrf'] = $token;
}

$requirements = Installer::requirements();
$allOk = true;
foreach ($requirements as $item) {
    if (!$item['ok']) {
        $allOk = false;
    }
}

$defaults = [
    'app_env' => (string)($app['env'] ?? 'prod'),
    'config_mode' => 'config',
    'db_dsn' => (string)($app['database']['dsn'] ?? ''),
    'db_user' => (string)($app['database']['user'] ?? ''),
    'mail_to_hr' => (string)($app['mail']['to_hr'] ?? ''),
    'mail_from' => (string)($app['mail']['from'] ?? ''),
    'supervisor_email' => (string)($app['security']['supervisor_email'] ?? ''),
    'admin_email' => 'fabio.ozuna@madeplant.com.br',
    'log_level' => (string)($app['logging']['level'] ?? 'INFO'),
    'log_alert_email' => (string)($app['logging']['alert_email'] ?? ''),
    'log_viewer_key' => (string)($app['logging']['viewer_key'] ?? ''),
];

$messages = [];
$success = false;
$selfDeleted = false;

if (Installer::isInstalled()) {
    http_response_code(403);
    $messages[] = 'Instalador bloqueado: a aplicação já está instalada.';
    Logger::warning('Installer blocked: already installed', Logger::captureContext(403));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Installer::isInstalled()) {
    $postedToken = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($token, $postedToken)) {
        $messages[] = 'Token de segurança inválido.';
        Logger::warning('Installer invalid CSRF token', Logger::captureContext(400));
    } else {
        $requiredKey = getenv('INSTALLER_KEY');
        if (is_string($requiredKey) && trim($requiredKey) !== '') {
            $postedKey = (string)($_POST['installer_key'] ?? '');
            if (!hash_equals(trim($requiredKey), trim($postedKey))) {
                $messages[] = 'Chave do instalador inválida.';
                Logger::warning('Installer invalid key', Logger::captureContext(403));
            }
        }
    }

    if (count($messages) === 0) {
        try {
            $result = Installer::run([
                'app_env' => (string)($_POST['app_env'] ?? 'prod'),
                'config_mode' => (string)($_POST['config_mode'] ?? 'config'),
                'allow_overwrite_config' => isset($_POST['allow_overwrite_config']) ? '1' : '0',
                'db_dsn' => (string)($_POST['db_dsn'] ?? ''),
                'db_user' => (string)($_POST['db_user'] ?? ''),
                'db_pass' => (string)($_POST['db_pass'] ?? ''),
                'mail_from' => (string)($_POST['mail_from'] ?? ''),
                'mail_to_hr' => (string)($_POST['mail_to_hr'] ?? ''),
                'supervisor_email' => (string)($_POST['supervisor_email'] ?? ''),
                'supervisor_password' => (string)($_POST['supervisor_password'] ?? ''),
                'admin_email' => (string)($_POST['admin_email'] ?? ''),
                'admin_password' => (string)($_POST['admin_password'] ?? ''),
                'log_level' => (string)($_POST['log_level'] ?? 'INFO'),
                'log_alert_email' => (string)($_POST['log_alert_email'] ?? ''),
                'log_viewer_key' => (string)($_POST['log_viewer_key'] ?? ''),
            ], function (string $line) use (&$messages): void {
                $messages[] = $line;
            });
            $success = true;
            $selfDeleted = (bool)($result['self_delete'] ?? false);
        } catch (Throwable $e) {
            $messages[] = 'Erro na instalação: ' . $e->getMessage();
            Logger::exception($e, 'CRITICAL', Logger::captureContext(500, ['installer' => ['phase' => 'run']]));
        }
    }
}

$isLocked = Installer::isInstalled();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Instalador RH Madeplant</title>
  <style>
    body{font-family:Montserrat,system-ui,-apple-system,sans-serif;background:#f7fafc;margin:0}
    .wrap{max-width:900px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(15,23,42,.06)}
    h1{margin:0 0 12px;color:#0f172a}
    h2{font-size:18px;margin:18px 0 10px;color:#0f172a}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .field{display:flex;flex-direction:column;gap:6px}
    label{font-size:13px;color:#334155}
    input,select{border:1px solid #cbd5e1;border-radius:8px;padding:10px;font-size:14px}
    button{background:#0d1321;color:#fff;border:0;border-radius:10px;padding:12px 16px;font-weight:600;cursor:pointer}
    button:disabled{opacity:.5;cursor:not-allowed}
    .ok{color:#1d2d44}
    .bad{color:#991b1b}
    .log{background:#0b1220;color:#dbeafe;padding:12px;border-radius:10px;white-space:pre-wrap;font-size:12px;max-height:260px;overflow:auto}
    .note{font-size:13px;color:#475569}
    @media(max-width:760px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Instalador Web RH Madeplant</h1>
      <p class="note">Preencha os dados e clique em instalar. O processo cria configuração local, importa banco, aplica migrações e prepara diretórios.</p>

      <h2>Requisitos do servidor</h2>
      <ul>
        <?php foreach ($requirements as $req): ?>
          <li class="<?= $req['ok'] ? 'ok' : 'bad' ?>">
            <?= $req['ok'] ? 'OK' : 'FALHA' ?> - <?= htmlspecialchars($req['label']) ?>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php if ($isLocked): ?>
        <p class="bad"><strong>Instalador bloqueado:</strong> instalação já concluída.</p>
      <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
        <div class="field">
          <label>Ambiente</label>
          <select name="app_env">
            <option value="prod" <?= $defaults['app_env'] === 'prod' ? 'selected' : '' ?>>prod</option>
            <option value="dev" <?= $defaults['app_env'] === 'dev' ? 'selected' : '' ?>>dev</option>
          </select>
        </div>
        <div class="field">
          <label>Modo de configuração</label>
          <select name="config_mode">
            <option value="config">app/config/config.php (recomendado)</option>
            <option value="local">app/config/local.php</option>
          </select>
        </div>
        <?php if (is_string(getenv('INSTALLER_KEY')) && trim((string)getenv('INSTALLER_KEY')) !== ''): ?>
        <div class="field">
          <label>Chave do instalador</label>
          <input name="installer_key" type="password" required>
        </div>
        <?php endif; ?>

        <div class="field"><label>DB DSN</label><input name="db_dsn" required placeholder="mysql:host=localhost;dbname=...;charset=utf8mb4" value="<?= htmlspecialchars($defaults['db_dsn']) ?>"></div>
        <div class="field"><label>DB Usuário</label><input name="db_user" required value="<?= htmlspecialchars($defaults['db_user']) ?>"></div>
        <div class="field"><label>DB Senha</label><input name="db_pass" type="password"></div>
        <div class="field"><label>E-mail RH</label><input name="mail_to_hr" required placeholder="rh@dominio.com" value="<?= htmlspecialchars($defaults['mail_to_hr']) ?>"></div>
        <div class="field"><label>E-mail remetente</label><input name="mail_from" required placeholder="no-reply@dominio.com" value="<?= htmlspecialchars($defaults['mail_from']) ?>"></div>
        <div class="field"><label>E-mail supervisor</label><input name="supervisor_email" required value="<?= htmlspecialchars($defaults['supervisor_email']) ?>"></div>
        <div class="field"><label>Senha supervisor</label><input name="supervisor_password" type="password" required></div>
        <div class="field"><label>E-mail admin inicial</label><input name="admin_email" placeholder="admin@dominio.com" value="<?= htmlspecialchars($defaults['admin_email']) ?>"></div>
        <div class="field"><label>Senha admin inicial</label><input name="admin_password" type="password"></div>
        <div class="field">
          <label>Nível de log</label>
          <select name="log_level">
            <option value="DEBUG" <?= $defaults['log_level'] === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
            <option value="INFO" <?= $defaults['log_level'] === 'INFO' ? 'selected' : '' ?>>INFO</option>
            <option value="WARNING" <?= $defaults['log_level'] === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
            <option value="ERROR" <?= $defaults['log_level'] === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
            <option value="CRITICAL" <?= $defaults['log_level'] === 'CRITICAL' ? 'selected' : '' ?>>CRITICAL</option>
          </select>
        </div>
        <div class="field"><label>E-mail de alerta de log</label><input name="log_alert_email" placeholder="devops@dominio.com" value="<?= htmlspecialchars($defaults['log_alert_email']) ?>"></div>
        <div class="field"><label>Chave do visualizador de logs</label><input name="log_viewer_key" placeholder="chave-forte" value="<?= htmlspecialchars($defaults['log_viewer_key']) ?>"></div>
        <div class="field" style="justify-content:flex-end">
          <label><input type="checkbox" name="allow_overwrite_config" value="1"> Permitir sobrescrever config existente</label>
        </div>
        <div style="grid-column:1/-1;display:flex;gap:12px;align-items:center">
          <button type="submit" <?= $allOk ? '' : 'disabled' ?>>Instalar agora</button>
          <span class="note">Após sucesso, o instalador é desativado automaticamente.</span>
        </div>
      </form>
      <?php endif; ?>

      <?php if (count($messages) > 0): ?>
        <h2>Log da instalação</h2>
        <div class="log"><?php foreach ($messages as $line) { echo htmlspecialchars($line) . "\n"; } ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <p class="ok"><strong>Instalação concluída.</strong> <?= $selfDeleted ? 'O instalador foi removido automaticamente.' : 'Remova manualmente o arquivo public/install.php.' ?></p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
