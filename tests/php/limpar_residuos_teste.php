<?php

/**
 * Remove do banco de DESENVOLVIMENTO os resíduos de teste comprovados (ver tests/php/lib/ResiduosTeste.php para a prova de propriedade).
 * Padrão = SIMULAÇÃO (transação com ROLLBACK, só relata). `--executar` remove de fato. Recusa rodar fora do ambiente de desenvolvimento local.
 *
 * Uso: php tests/php/limpar_residuos_teste.php [--executar]
 * (não é um teste: não é coletado pelo executor)
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/lib/ResiduosTeste.php';

$executar = in_array('--executar', $argv, true);
$pdo = Database::conn();
try {
    ResiduosTeste::garantirAmbienteDeTeste($pdo);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$lev = ResiduosTeste::levantar($pdo);
echo 'Resíduos comprovados de teste: usuários=' . count($lev['usuarios']) . ' · contratos=' . count($lev['metadados']) . ' · setores=' . count($lev['setores']) . ' · pesquisas órfãs=' . count($lev['pesquisas_orfas']) . "\n";
if (ResiduosTeste::total($lev) === 0) {
    echo "Nada a remover.\n";
    exit(0);
}
$contagem = ResiduosTeste::remover($pdo, $lev, $executar);
ksort($contagem);
echo ($executar ? "REMOVIDO" : "SIMULAÇÃO (nada foi apagado)") . ", linhas por tabela:\n";
foreach ($contagem as $tabela => $n) {
    echo sprintf("  %-48s %d\n", $tabela, $n);
}
if (!$executar) {
    echo "Para remover de fato: php tests/php/limpar_residuos_teste.php --executar\n";
}
