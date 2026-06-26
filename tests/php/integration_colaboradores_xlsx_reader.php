<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$filePath = BASE_PATH . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'colaboradores.xlsx';
$reader = new SpreadsheetXlsxReader($filePath);
$validation = $reader->validate('Ativos x Desligados');

if (!($validation['ok'] ?? false)) {
    throw new RuntimeException('Falha ao validar a planilha de colaboradores: ' . implode('; ', $validation['errors'] ?? []));
}

$sheet = $reader->readSheet('Ativos x Desligados');
$expectedHeaders = ['COD', 'COLABORADOR', 'EMPRESA', 'CPF', 'ADMISSÃO', 'NASC.', 'CARGO', 'DEMISSÃO', 'MOTIVO RESCISÃO'];
$headers = $sheet['headers'] ?? [];

if ($headers !== $expectedHeaders) {
    throw new RuntimeException('Cabecalhos inesperados na aba Ativos x Desligados.');
}

if (count($sheet['rows'] ?? []) < 1) {
    throw new RuntimeException('A aba Ativos x Desligados nao possui dados para importacao.');
}

$firstRow = $sheet['rows'][0]['values'] ?? [];
if (($firstRow['COLABORADOR'] ?? '') === '' || ($firstRow['COD'] ?? '') === '') {
    throw new RuntimeException('A primeira linha de dados nao contem COD e COLABORADOR validos.');
}

echo "COLABORADORES_XLSX_READER_OK\n";
