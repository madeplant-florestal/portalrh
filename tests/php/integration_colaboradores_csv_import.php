<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$csv = <<<CSV
COD;COLABORADOR;EMPRESA;CPF;ADMISSÃO;NASC.;CARGO;DEMISSÃO;MOTIVO RESCISÃO
200;COLABORADOR CSV;EMPRESA CSV;529.982.247-25;01/01/2024;01/01/1990;ANALISTA;;; 
CSV;

$tempFile = tempnam(sys_get_temp_dir(), 'colab_csv_');
if ($tempFile === false) {
    throw new RuntimeException('Nao foi possivel criar arquivo temporario para o teste CSV.');
}

$csvPath = $tempFile . '.csv';
rename($tempFile, $csvPath);
file_put_contents($csvPath, $csv);

try {
    $service = new CollaboratorSpreadsheetImportService();
    $validation = $service->validateFile($csvPath);
    if (!($validation['ok'] ?? false)) {
        throw new RuntimeException('A validacao do CSV deveria ser bem-sucedida.');
    }

    if (($validation['file_type'] ?? '') !== 'csv') {
        throw new RuntimeException('O tipo do arquivo deveria ser identificado como CSV.');
    }

    $report = $service->import($csvPath, ['validate_only' => true]);
    if (!($report['ok'] ?? false)) {
        throw new RuntimeException('A importacao em modo validate_only deveria aceitar o CSV valido.');
    }

    $summary = $report['summary'] ?? [];
    if (($summary['processed'] ?? null) !== 1 || ($summary['valid'] ?? null) !== 1 || ($summary['rejected'] ?? null) !== 0) {
        throw new RuntimeException('Os totais do CSV importado nao correspondem ao esperado.');
    }

    echo "COLABORADORES_CSV_IMPORT_OK\n";
} finally {
    if (is_file($csvPath)) {
        @unlink($csvPath);
    }
}
