<?php
require __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Leitor de XLSX (SpreadsheetXlsxReader) sobre uma planilha FICTÍCIA gerada aqui, em diretório temporário (Segurança 3.4: a planilha real
 * de colaboradores saiu do repositório — nenhum teste depende de dados reais). Mesmas verificações de antes: validação da aba, cabeçalhos
 * exatos e primeira linha com COD e COLABORADOR.
 */

/** Monta um .xlsx mínimo (ZIP sem compressão — não exige a extensão zip do PHP). */
function zz_xlsx_ficticio(string $destino, string $aba, array $linhas): void
{
    $celulas = static function (array $valores, int $linha): string {
        $xml = '';
        foreach (array_values($valores) as $i => $v) {
            $col = chr(65 + $i);
            $xml .= '<c r="' . $col . $linha . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>';
        }
        return '<row r="' . $linha . '">' . $xml . '</row>';
    };
    $sheetData = '';
    foreach ($linhas as $i => $l) {
        $sheetData .= $celulas($l, $i + 1);
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $entradas = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $r . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $ns . '" xmlns:r="' . $r . '"><sheets><sheet name="' . htmlspecialchars($aba, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $r . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $ns . '"><sheetData>' . $sheetData . '</sheetData></worksheet>',
        'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . $ns . '" count="0" uniqueCount="0"></sst>',
    ];
    $zip = '';
    $central = '';
    foreach ($entradas as $nome => $dados) {
        $crc = crc32($dados);
        $tam = strlen($dados);
        $offset = strlen($zip);
        $zip .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $tam, $tam, strlen($nome), 0) . $nome . $dados;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $tam, $tam, strlen($nome), 0, 0, 0, 0, 0, $offset) . $nome;
    }
    $zip .= $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entradas), count($entradas), strlen($central), strlen($zip), 0);
    file_put_contents($destino, $zip);
}

$filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz-colaboradores-ficticio-' . bin2hex(random_bytes(4)) . '.xlsx';
$expectedHeaders = ['COD', 'COLABORADOR', 'EMPRESA', 'CPF', 'ADMISSÃO', 'NASC.', 'CARGO', 'DEMISSÃO', 'MOTIVO RESCISÃO'];
zz_xlsx_ficticio($filePath, 'Ativos x Desligados', [
    $expectedHeaders,
    ['900', 'ZZ COLABORADOR FICTICIO', 'ZZ EMPRESA', '529.982.247-25', '01/01/2024', '01/01/1990', 'ANALISTA', '', ''],
]);

try {
    $reader = new SpreadsheetXlsxReader($filePath);
    $validation = $reader->validate('Ativos x Desligados');

    if (!($validation['ok'] ?? false)) {
        throw new RuntimeException('Falha ao validar a planilha de colaboradores: ' . implode('; ', $validation['errors'] ?? []));
    }

    $sheet = $reader->readSheet('Ativos x Desligados');
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
} finally {
    @unlink($filePath);
}

echo "COLABORADORES_XLSX_READER_OK\n";
