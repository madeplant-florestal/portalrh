<?php

/**
 * Fixture (NÃO é teste): edita, no armazenamento real de sessões (storage/sessions), a sessão de um cookie — usada para simular sessões
 * abertas ANTES de uma regra nova (ex.: sem `credencial_impressao`). Não passa pelo bootstrap (que validaria/encerraria a sessão).
 * Uso: php fixture_editar_sessao.php <sid> <json {"remover":[chaves], "definir":{chave:valor}}>
 * Só roda em CLI, só altera uma sessão JÁ autenticada do armazenamento local e não toca no banco.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[, $sid, $ops] = $argv + ['', '', '{}'];
if (!preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sid)) {
    fwrite(STDERR, "sid inválido\n");
    exit(2);
}
define('STORAGE_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
$ops = json_decode($ops, true) ?: [];
ini_set('session.save_path', STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions');
ini_set('session.use_strict_mode', '0');
session_name('RHMADEPLANTSESSID');
session_id($sid);
session_start();
if (empty($_SESSION['user_id'])) {
    fwrite(STDERR, "sessão não autenticada\n");
    exit(3);
}
foreach ((array)($ops['remover'] ?? []) as $k) {
    unset($_SESSION[$k]);
}
foreach ((array)($ops['definir'] ?? []) as $k => $v) {
    $_SESSION[$k] = $v;
}
session_write_close();
echo "ok\n";
