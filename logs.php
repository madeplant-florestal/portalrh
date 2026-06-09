<?php
declare(strict_types=1);
require_once __DIR__ . '/app/core/bootstrap.php';

$app = Config::app();
$viewerKey = trim((string)($app['logging']['viewer_key'] ?? ''));

$provided = (string)($_GET['key'] ?? '');
if ($viewerKey === '' || !hash_equals($viewerKey, $provided)) {
    http_response_code(403);
    echo 'Acesso negado ao visualizador de logs.';
    exit;
}

$rows = Logger::readLatest(300);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Logs da Aplicação</title>
  <style>
    body{font-family:Montserrat,system-ui,-apple-system,sans-serif;background:#f7fafc;margin:0}
    .wrap{max-width:1200px;margin:20px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{border-bottom:1px solid #e2e8f0;padding:8px;vertical-align:top}
    th{text-align:left;background:#f8fafc}
    .level-CRITICAL,.level-ERROR{color:#991b1b;font-weight:700}
    .level-WARNING{color:#92400e;font-weight:700}
    .level-INFO{color:#1e3a8a}
    pre{white-space:pre-wrap;max-width:700px;overflow:auto;margin:0}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Logs recentes</h1>
      <p>Total exibido: <?= count($rows) ?></p>
      <table>
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Nível</th>
            <th>Mensagem</th>
            <th>Contexto (JSON)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_reverse($rows) as $row): ?>
            <?php $level = (string)($row['level'] ?? 'INFO'); ?>
            <tr>
              <td><?= htmlspecialchars((string)($row['timestamp'] ?? '')) ?></td>
              <td class="level-<?= htmlspecialchars($level) ?>"><?= htmlspecialchars($level) ?></td>
              <td><?= htmlspecialchars((string)($row['message'] ?? '')) ?></td>
              <td><pre><?= htmlspecialchars((string)json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
