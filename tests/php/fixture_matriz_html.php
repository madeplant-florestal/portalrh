<?php

/**
 * Fixture (NÃO é teste): imprime uma página mínima com o select de perfil + a matriz de permissões (partial real), para o teste de
 * navegador (tests/matriz-permissoes.spec.js) exercitar o JavaScript real sem servidor nem login.
 * Uso: php fixture_matriz_html.php <perfil> <edicao 0|1> [códigos gravados separados por vírgula]
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/ui-shell.php';
require_once APP_PATH . '/views/partials/matriz-permissoes.php';

[, $perfil, $edicao, $gravadosCsv] = $argv + ['', 'viewer', '0', ''];
$gravados = [];
foreach (array_filter(explode(',', (string)$gravadosCsv)) as $codigo) {
    $st = Database::conn()->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $st->execute([$codigo]);
    $gravados[] = (int)$st->fetchColumn();
}
$matriz = PermissaoMatrizService::construir($perfil, $gravados, $edicao === '1', ['admin', 'rh', 'gestor', 'viewer']);
echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="bg-background">';
echo '<form id="f" class="p-4"><label for="role">Perfil</label><select id="role" name="role">';
foreach (['viewer' => 'Leitor', 'rh' => 'RH', 'gestor' => 'Gestor', 'admin' => 'Admin'] as $v => $r) {
    echo '<option value="' . $v . '"' . ($v === $perfil ? ' selected' : '') . '>' . $r . '</option>';
}
echo '</select>' . ui_matriz_permissoes($matriz, 'role') . '</form></body></html>';
