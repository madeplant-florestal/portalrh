<?php

/**
 * Snapshot/restauração de `ausente_na_origem`/`ausente_desde` de TODA a `colaboradores_metadados`
 * — usado por testes que chamam `ColaboradorMetadadosRepository::reconciliarAusentes()` (direto
 * ou via `MetadadosSyncService::applyRows(..., reconciliarAusentes: true)`/`receberLote()`, que
 * SEMPRE reconcilia a dimensão inteira recebida). `reconciliarAusentes()` varre a tabela INTEIRA
 * (`WHERE ausente_na_origem = 0 AND identificador NOT IN (...)`) — por design, correto para uma
 * sincronização real. Mas um teste que chama isso com um lote fixture de 2-5 linhas, contra o
 * banco de dev COMPARTILHADO, marca como ausente todo dado REAL coexistindo na mesma tabela.
 *
 * Foi exatamente o que corrompeu a base local em 2026-09-25: `integration_metadados_reconciliacao_
 * ausencia.php`/`integration_metadados_sync_ingest.php`/`integration_metadados_sync_execucoes.php`
 * reconciliando contra lotes fixture apagaram `ausente_na_origem=0` de 731 registros reais recém-
 * sincronizados do RHMADEPLANT, fazendo o card "Headcount Atual" do People Analytics renderizar 0.
 *
 * Uso: tirar o snapshot ANTES de qualquer fixture ser inserida (logo após `Database::conn()`), e
 * restaurar em `finally`, antes ou depois da limpeza das próprias fixtures (não há conflito: a
 * restauração só toca identificadores que já existiam no snapshot, nunca as fixtures novas).
 */
function ausenciaSnapshot(PDO $pdo): array
{
    return $pdo->query('SELECT identificador, ausente_na_origem, ausente_desde FROM colaboradores_metadados')->fetchAll(PDO::FETCH_ASSOC);
}

function ausenciaRestaurar(PDO $pdo, array $snapshot): void
{
    if ($snapshot === []) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE colaboradores_metadados SET ausente_na_origem = ?, ausente_desde = ? WHERE identificador = ?');
    foreach ($snapshot as $linha) {
        $stmt->execute([(int)$linha['ausente_na_origem'], $linha['ausente_desde'], $linha['identificador']]);
    }
}
