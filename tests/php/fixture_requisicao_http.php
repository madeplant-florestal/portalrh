<?php

/**
 * Fixture (NÃO é teste): executa UMA ação de controller como um usuário, num processo próprio, e imprime o corpo da resposta
 * seguido de "@@STATUS=<código>@@". Existe porque os gates de autorização terminam com exit (403) — o que mataria o processo
 * de um teste comum. Uso: php fixture_requisicao_http.php <userId> <role> <supervisor 0|1> <Controller> <metodo> <json GET> <json args>
 */

require __DIR__ . '/../../app/core/bootstrap.php';

[, $userId, $role, $supervisor, $controller, $metodo, $get, $args] = $argv + array_fill(0, 8, '');

$_SESSION['user'] = true;
$_SESSION['user_id'] = (int)$userId;
$_SESSION['user_role'] = $role;
$_SESSION['user_name'] = 'Teste 2A';
$_SESSION['user_is_supervisor'] = $supervisor === '1';
$_GET = json_decode($get !== '' ? $get : '[]', true) ?: [];
$_SERVER['REQUEST_METHOD'] = 'GET';

register_shutdown_function(static function (): void {
    echo "\n@@STATUS=" . (http_response_code() ?: 200) . "@@";
});

(new $controller())->$metodo(...(json_decode($args !== '' ? $args : '[]', true) ?: []));
