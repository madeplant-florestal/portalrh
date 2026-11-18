<?php

/**
 * Fixture (NÃO é teste): tenta excluir uma conta como um ator, num processo próprio, começando num instante combinado
 * (barreira) para que duas execuções colidam de verdade. Imprime "OK" ou "NEGADO:<motivo>".
 * Uso: php fixture_remover_supervisor.php <atorId> <alvoId> <microtime de início>
 */

require __DIR__ . '/../../app/core/bootstrap.php';

[, $atorId, $alvoId, $inicio] = $argv + array_fill(0, 4, '');
$ator = User::findById((int)$atorId);
while (microtime(true) < (float)$inicio) {
    usleep(200);
}
echo User::attemptDelete((int)$alvoId, $ator, '127.0.0.1') ? 'OK' : 'NEGADO:' . (string)User::$ultimoMotivo;
