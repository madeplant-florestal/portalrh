<?php

/**
 * Fixture (NÃO é teste): UMA requisição HTTP simulada, com COOKIE de sessão e RELÓGIO controlado, passando pelo bootstrap REAL (que valida a sessão).
 * Uso: php fixture_sessao_http.php <sid|-> <agora epoch> <Controller> <metodo> [json post] [json cabeçalhos extras] [json get] [json args do método]
 * Saída: corpo + "@@META=<json>@@" (sid final, status, Location, user_id, role, supervisor, csrf, se o controller chegou a rodar).
 */

[, $sid, $agora, $controller, $metodo, $post, $cab, $get, $args] = $argv + array_fill(0, 9, '');

putenv('RH_TEST_NOW=' . $agora);
$_COOKIE['RHMADEPLANTSESSID'] = $sid !== '-' ? $sid : '';
if ($sid === '-') {
    unset($_COOKIE['RHMADEPLANTSESSID']);
}
$_SERVER['REQUEST_METHOD'] = $post !== '' ? 'POST' : 'GET';
$_SERVER['REQUEST_URI'] = '/admin/teste-sessao';
foreach (json_decode($cab !== '' ? $cab : '{}', true) ?: [] as $k => $v) {
    $_SERVER[$k] = $v;
}
$_GET = json_decode($get !== '' ? $get : '[]', true) ?: [];
if ($post !== '') {
    $_POST = json_decode($post, true) ?: [];
}
$GLOBALS['__fixture_controller_rodou'] = false;
$GLOBALS['__fixture_loc'] = '';
// No CLI headers_list() não registra o Location: o redirect() do bootstrap (protegido por function_exists) é substituído por uma versão que só anota o destino.
function redirect($path): void
{
    $GLOBALS['__fixture_loc'] = url((string)$path);
    exit;
}

register_shutdown_function(static function (): void {
    $loc = (string)$GLOBALS['__fixture_loc'];
    echo "\n@@META=" . json_encode([
        'sid' => (string)session_id(),
        'status' => http_response_code() ?: 200,
        'location' => $loc,
        'user_id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'supervisor' => !empty($_SESSION['user_is_supervisor']),
        'csrf' => $_SESSION['csrf_token'] ?? null,
        'iniciada_em' => $_SESSION['session_started_at'] ?? null,
        'ultima' => $_SESSION['last_activity'] ?? null,
        'controller_rodou' => $GLOBALS['__fixture_controller_rodou'],
    ], JSON_UNESCAPED_UNICODE) . "@@";
});

require __DIR__ . '/../../app/core/bootstrap.php'; // <- valida/renova (ou encerra) a sessão com o relógio informado

$GLOBALS['__fixture_controller_rodou'] = true;
(new $controller())->$metodo(...(json_decode($args !== '' ? $args : '[]', true) ?: []));
