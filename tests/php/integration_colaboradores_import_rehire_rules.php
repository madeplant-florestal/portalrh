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
            'COLABORADOR' => 'COLABORADOR A',
            'EMPRESA' => 'EMPRESA ALFA',
            'CPF' => '529.982.247-25',
            'ADMISSÃO' => '01/01/2020',
            'NASC.' => '01/01/1990',
            'CARGO' => 'OPERADOR',
            'DEMISSÃO' => '01/01/2021',
            'MOTIVO RESCISÃO' => 'ENCERRAMENTO',
        ],
    ],
    [
        'row_number' => 3,
        'values' => [
            'COD' => '100',
            'COLABORADOR' => 'COLABORADOR B',
            'EMPRESA' => 'EMPRESA BETA',
            'CPF' => '529.982.247-25',
            'ADMISSÃO' => '01/03/2022',
            'NASC.' => '01/01/1990',
            'CARGO' => 'OPERADOR',
            'DEMISSÃO' => '',
            'MOTIVO RESCISÃO' => '',
        ],
    ],
    [
        'row_number' => 4,
        'values' => [
            'COD' => '200',
            'COLABORADOR' => 'COLABORADOR C',
            'EMPRESA' => 'EMPRESA ALFA',
            'CPF' => '111.444.777-35',
            'ADMISSÃO' => '01/02/2023',
            'NASC.' => '01/01/1991',
            'CARGO' => 'OPERADOR',
            'DEMISSÃO' => '',
            'MOTIVO RESCISÃO' => '',
        ],
    ],
    [
        'row_number' => 5,
        'values' => [
            'COD' => '200',
            'COLABORADOR' => 'COLABORADOR D',
            'EMPRESA' => 'EMPRESA ALFA',
            'CPF' => '222.333.444-05',
            'ADMISSÃO' => '01/04/2023',
            'NASC.' => '01/01/1992',
            'CARGO' => 'OPERADOR',
            'DEMISSÃO' => '',
            'MOTIVO RESCISÃO' => '',
        ],
    ],
];

$analysis = $method->invoke($service, $rows);
$summary = $analysis['summary'] ?? [];
$rejected = $analysis['rejected_records'] ?? [];
$warnings = $analysis['warnings'] ?? [];

if (($summary['valid'] ?? null) !== 2) {
    throw new RuntimeException('CPF repetido em recontratacao e COD repetido entre empresas diferentes devem permanecer validos.');
}

if (($summary['rejected'] ?? null) !== 2) {
    throw new RuntimeException('Somente o COD repetido na mesma empresa deve ser rejeitado.');
}

$hasExpectedRejection = false;
foreach ($rejected as $item) {
    $causes = $item['causes'] ?? [];
    if (in_array('COD duplicado para a mesma empresa na planilha', $causes, true)) {
        $hasExpectedRejection = true;
        break;
    }
}

if (!$hasExpectedRejection) {
    throw new RuntimeException('A rejeicao esperada para COD repetido na mesma empresa nao foi encontrada.');
}

if ($warnings === []) {
    throw new RuntimeException('O teste esperava ao menos um aviso para CPF repetido tratado como historico.');
}

echo "COLABORADORES_IMPORT_REHIRE_RULES_OK\n";
