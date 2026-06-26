<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$service = new CollaboratorSpreadsheetImportService();
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('analyzeRows');
$method->setAccessible(true);

$rows = [
    [
        'row_number' => 2,
        'values' => [
            'COD' => '100',
            'COLABORADOR' => 'TESTE COLABORADOR',
            'EMPRESA' => 'EMPRESA TESTE',
            'CPF' => '529.982.247-25',
            'ADMISSÃO' => '01/01/2024',
            'NASC.' => '01/01/1990',
            'CARGO' => 'CARGO TESTE',
            'DEMISSÃO' => '',
            'MOTIVO RESCISÃO' => '',
        ],
    ],
    [
        'row_number' => 3,
        'values' => [
            'COD' => '',
            'COLABORADOR' => '',
            'EMPRESA' => '',
            'CPF' => '',
            'ADMISSÃO' => '',
            'NASC.' => '',
            'CARGO' => '',
            'DEMISSÃO' => '',
            'MOTIVO RESCISÃO' => '',
        ],
    ],
];

$analysis = $method->invoke($service, $rows);
$summary = $analysis['summary'] ?? [];
$rejected = $analysis['rejected_records'] ?? [];

if (($summary['processed'] ?? null) !== 1) {
    throw new RuntimeException('Linhas em branco nao deveriam ser contabilizadas como processadas.');
}

if (($summary['ignored_blank_rows'] ?? null) !== 1) {
    throw new RuntimeException('A linha em branco deveria ser contabilizada como ignorada.');
}

if (($summary['valid'] ?? null) !== 1 || ($summary['rejected'] ?? null) !== 0) {
    throw new RuntimeException('A linha valida deveria permanecer importavel sem rejeicoes extras.');
}

if ($rejected !== []) {
    throw new RuntimeException('Linhas em branco nao devem aparecer na lista de rejeitados.');
}

echo "COLABORADORES_IMPORT_BLANK_ROWS_OK\n";
