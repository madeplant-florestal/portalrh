<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Reverte exclusivamente os vínculos de um snapshot gerado por
 * scripts/aplicar_vinculos_colaboradores_metadados.php --aplicar. Nunca faz
 * "UPDATE colaboradores SET metadados_id = NULL" genérico — só reverte item a item, e só se o
 * vínculo atual ainda for exatamente o que aquele plano aplicou (ver
 * ColaboradorMetadadosLinkService::revert()).
 *
 * Uso: php scripts/reverter_vinculos_colaboradores_metadados.php --snapshot=<caminho.json>
 */
$options = getopt('', ['snapshot:']);
$snapshotPath = $options['snapshot'] ?? null;

if (!$snapshotPath || !is_string($snapshotPath) || !is_file($snapshotPath)) {
    fwrite(STDERR, "Uso: php scripts/reverter_vinculos_colaboradores_metadados.php --snapshot=<caminho.json>\n");
    exit(1);
}

try {
    $conteudo = json_decode((string)file_get_contents($snapshotPath), true);
    if (!is_array($conteudo) || !isset($conteudo['itens']) || !is_array($conteudo['itens'])) {
        throw new RuntimeException('Snapshot inválido: esperado JSON com a chave "itens".');
    }

    $linkService = new ColaboradorMetadadosLinkService();
    $resultado = $linkService->revert($conteudo['itens']);

    $resumo = [
        'snapshot_path' => $snapshotPath,
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'quantidade_no_snapshot' => count($conteudo['itens']),
        'resultado' => $resultado,
    ];
    echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($resultado['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'reverter_vinculos_colaboradores_metadados.php']);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
