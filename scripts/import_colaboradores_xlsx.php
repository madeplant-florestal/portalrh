<?php
require __DIR__ . '/../app/core/bootstrap.php';

// Uso: php scripts/import_colaboradores_xlsx.php --file=<caminho da planilha .xlsx/.csv> [--sheet=<aba>] [--dry-run] [--validate-only]
// O caminho é OBRIGATÓRIO: não existe planilha padrão no repositório (Segurança 3.4 — a planilha real foi retirada do Git).
// Guarde o arquivo operacional FORA da raiz publicada (ex.: um diretório privado do servidor, ou storage/, que é bloqueado por HTTP),
// nunca em assets/ ou public/.
$options = getopt('', ['file::', 'sheet::', 'dry-run', 'validate-only']);
$filePath = isset($options['file']) && is_string($options['file']) ? trim($options['file']) : '';
if ($filePath === '') {
    fwrite(STDERR, 'Informe a planilha: php scripts/import_colaboradores_xlsx.php --file=<caminho> [--sheet=<aba>] [--dry-run] [--validate-only]' . PHP_EOL);
    fwrite(STDERR, 'Nenhum arquivo padrão é usado. Nada foi importado.' . PHP_EOL);
    exit(2);
}
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
