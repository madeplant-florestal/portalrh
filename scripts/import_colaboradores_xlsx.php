<?php
require __DIR__ . '/../app/core/bootstrap.php';

$options = getopt('', ['file::', 'sheet::', 'dry-run', 'validate-only']);
$filePath = isset($options['file']) && is_string($options['file']) && $options['file'] !== ''
    ? $options['file']
    : BASE_PATH . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'colaboradores.xlsx';
$sheetName = isset($options['sheet']) && is_string($options['sheet']) && $options['sheet'] !== ''
    ? $options['sheet']
    : 'Ativos x Desligados';
$dryRun = array_key_exists('dry-run', $options);
$validateOnly = array_key_exists('validate-only', $options);

try {
    $service = new CollaboratorSpreadsheetImportService();
    $report = $service->import($filePath, [
        'sheet_name' => $sheetName,
        'dry_run' => $dryRun,
        'validate_only' => $validateOnly,
    ]);

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($report['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', [
        'script' => 'import_colaboradores_xlsx.php',
        'file_path' => $filePath,
        'sheet_name' => $sheetName,
    ]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
