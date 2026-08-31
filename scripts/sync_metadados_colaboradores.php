<?php
require __DIR__ . '/../app/core/bootstrap.php';

$options = getopt('', ['dry-run', 'permitir-origem-mista']);
$dryRun = array_key_exists('dry-run', $options);
$permitirOrigemMista = array_key_exists('permitir-origem-mista', $options);

try {
    $service = new MetadadosSyncService();
    $origemAtual = MetadadosDatabase::sourceLabel();

    if ($dryRun) {
        $rows = $service->fetchSourceRows();
        $conflito = $service->originConflict($origemAtual);
        $report = [
            'ok' => true,
            'dry_run' => true,
            'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'summary' => ['linhas_lidas_do_metadados' => count($rows)],
            'origem' => $origemAtual,
            'conflito_de_origem' => $conflito === [] ? null : $conflito,
        ];
    } else {
        $summary = $service->run($permitirOrigemMista);
        $report = [
            'ok' => ($summary['errors'] ?? 0) === 0,
            'dry_run' => false,
            'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'summary' => $summary,
        ];
    }

    $reportPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
        . 'metadados-sync-' . date('Ymd-His') . '.json';
    @mkdir(dirname($reportPath), 0775, true);
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $report['report_path'] = $reportPath;

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($report['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'sync_metadados_colaboradores.php', 'dry_run' => $dryRun]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
