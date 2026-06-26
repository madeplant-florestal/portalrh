<?php

class SpreadsheetXlsxReader
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function validate(string $requiredSheetName): array
    {
        $errors = [];
        $exists = is_file($this->filePath);
        if (!$exists) {
            return [
                'ok' => false,
                'file_exists' => false,
                'file_path' => $this->filePath,
                'sheet_exists' => false,
                'sheet_names' => [],
                'errors' => ['Arquivo XLSX nao encontrado.'],
            ];
        }

        try {
            $sheetMap = $this->sheetMap();
            $sheetNames = array_keys($sheetMap);
            $sheetExists = isset($sheetMap[$requiredSheetName]);

            if (!$sheetExists) {
                $errors[] = sprintf('A aba obrigatoria "%s" nao foi encontrada na planilha.', $requiredSheetName);
            }

            return [
                'ok' => $sheetExists,
                'file_exists' => true,
                'file_path' => $this->filePath,
                'sheet_exists' => $sheetExists,
                'sheet_names' => $sheetNames,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'file_exists' => true,
                'file_path' => $this->filePath,
                'sheet_exists' => false,
                'sheet_names' => [],
                'errors' => ['Falha ao ler o arquivo XLSX: ' . $e->getMessage()],
            ];
        }
    }

    public function readSheet(string $sheetName): array
    {
        $sheetMap = $this->sheetMap();
        if (!isset($sheetMap[$sheetName])) {
            throw new RuntimeException(sprintf('A aba "%s" nao existe no arquivo XLSX.', $sheetName));
        }

        $sheetXml = $this->readXmlEntry($sheetMap[$sheetName]);
        $namespace = new DOMXPath($sheetXml);
        $namespace->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $sharedStrings = $this->sharedStrings();
        $rows = [];

        foreach ($namespace->query('//x:sheetData/x:row') as $rowNode) {
            $rowIndex = (int)$rowNode->attributes?->getNamedItem('r')?->nodeValue;
            $cells = [];
            foreach ($namespace->query('./x:c', $rowNode) as $cellNode) {
                $reference = (string)$cellNode->attributes?->getNamedItem('r')?->nodeValue;
                $columnLetters = $this->columnLettersFromReference($reference);
                $columnIndex = $this->columnLettersToIndex($columnLetters);
                $cells[$columnIndex] = $this->cellValue($namespace, $cellNode, $sharedStrings);
            }
            if ($cells !== []) {
                $rows[$rowIndex] = $cells;
            }
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf('A aba "%s" nao possui linhas legiveis.', $sheetName));
        }

        ksort($rows);
        $firstRow = reset($rows);
        $maxColumn = max(array_keys($firstRow));
        $headers = [];
        for ($index = 1; $index <= $maxColumn; $index++) {
            $headers[$index] = trim((string)($firstRow[$index] ?? ''));
        }

        $dataRows = [];
        foreach ($rows as $rowIndex => $columns) {
            if ($rowIndex === (int)array_key_first($rows)) {
                continue;
            }
            $record = [];
            for ($index = 1; $index <= $maxColumn; $index++) {
                $header = $headers[$index] ?? '';
                if ($header === '') {
                    continue;
                }
                $record[$header] = $columns[$index] ?? '';
            }
            $dataRows[] = [
                'row_number' => $rowIndex,
                'values' => $record,
            ];
        }

        return [
            'headers' => array_values(array_filter($headers, static fn($value): bool => $value !== '')),
            'rows' => $dataRows,
        ];
    }

    private function sheetMap(): array
    {
        $workbookXml = $this->readXmlEntry('xl/workbook.xml');
        $relsXml = $this->readXmlEntry('xl/_rels/workbook.xml.rels');

        $workbookXPath = new DOMXPath($workbookXml);
        $workbookXPath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $relationships = [];

        foreach ($relsXml->getElementsByTagName('Relationship') as $relationshipNode) {
            $id = (string)$relationshipNode->attributes?->getNamedItem('Id')?->nodeValue;
            $target = (string)$relationshipNode->attributes?->getNamedItem('Target')?->nodeValue;
            if ($id !== '' && $target !== '') {
                $relationships[$id] = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
            }
        }

        $sheetMap = [];
        foreach ($workbookXPath->query('//x:sheets/x:sheet') as $sheetNode) {
            $name = (string)$sheetNode->attributes?->getNamedItem('name')?->nodeValue;
            $relationId = (string)$sheetNode->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            );
            if ($name !== '' && isset($relationships[$relationId])) {
                $sheetMap[$name] = $relationships[$relationId];
            }
        }

        if ($sheetMap === []) {
            throw new RuntimeException('Nao foi possivel mapear as abas da planilha XLSX.');
        }

        return $sheetMap;
    }

    private function sharedStrings(): array
    {
        try {
            $xml = $this->readXmlEntry('xl/sharedStrings.xml');
        } catch (Throwable $e) {
            return [];
        }

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = [];

        foreach ($xpath->query('//x:si') as $stringItem) {
            $parts = [];
            foreach ($xpath->query('.//x:t', $stringItem) as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $items[] = implode('', $parts);
        }

        return $items;
    }

    private function cellValue(DOMXPath $xpath, DOMNode $cellNode, array $sharedStrings): string
    {
        $type = (string)$cellNode->attributes?->getNamedItem('t')?->nodeValue;
        $inlineText = $xpath->query('./x:is/x:t', $cellNode);
        if ($inlineText->length > 0) {
            return trim((string)$inlineText->item(0)?->textContent);
        }

        $valueNode = $xpath->query('./x:v', $cellNode);
        $rawValue = trim((string)$valueNode->item(0)?->textContent);
        if ($rawValue === '') {
            return '';
        }

        if ($type === 's' && ctype_digit($rawValue)) {
            $index = (int)$rawValue;
            return trim((string)($sharedStrings[$index] ?? $rawValue));
        }

        return $rawValue;
    }

    private function readXmlEntry(string $entryPath): DOMDocument
    {
        $content = $this->entryContents($entryPath);
        $xml = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml->loadXML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException(sprintf('Falha ao interpretar o XML interno "%s" do arquivo XLSX.', $entryPath));
        }

        return $xml;
    }

    private function entryContents(string $entryPath): string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($this->filePath) === true) {
                try {
                    $content = $zip->getFromName($entryPath);
                    if ($content === false) {
                        throw new RuntimeException(sprintf('Entrada "%s" nao encontrada no XLSX.', $entryPath));
                    }
                    return $content;
                } finally {
                    $zip->close();
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->entryContentsWithPowerShell($entryPath);
        }

        return $this->entryContentsWithUnzip($entryPath);
    }

    private function entryContentsWithPowerShell(string $entryPath): string
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'xlsx-entry-');
        if ($scriptPath === false) {
            throw new RuntimeException('Nao foi possivel criar arquivo temporario para leitura do XLSX.');
        }

        $ps1Path = $scriptPath . '.ps1';
        @rename($scriptPath, $ps1Path);

        $script = <<<'PS'
param(
    [string]$WorkbookPath,
    [string]$EntryPath
)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$fs = New-Object System.IO.FileStream($WorkbookPath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
$zip = $null
try {
    $zip = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Read, $true)
    $entry = $zip.GetEntry($EntryPath)
    if (-not $entry) {
        throw "ENTRY_NOT_FOUND:$EntryPath"
    }
    $stream = $entry.Open()
    try {
        $memory = New-Object System.IO.MemoryStream
        $stream.CopyTo($memory)
        [Console]::Out.Write([Convert]::ToBase64String($memory.ToArray()))
    } finally {
        $stream.Dispose()
    }
} finally {
    if ($zip) { $zip.Dispose() }
    $fs.Dispose()
}
PS;

        file_put_contents($ps1Path, $script);
        $command = sprintf(
            'powershell -NoProfile -ExecutionPolicy Bypass -File %s -WorkbookPath %s -EntryPath %s',
            escapeshellarg($ps1Path),
            escapeshellarg($this->filePath),
            escapeshellarg($entryPath)
        );

        $output = shell_exec($command);
        @unlink($ps1Path);

        if (!is_string($output) || trim($output) === '') {
            throw new RuntimeException(sprintf('Falha ao ler a entrada "%s" via PowerShell.', $entryPath));
        }

        $decoded = base64_decode(trim($output), true);
        if ($decoded === false) {
            throw new RuntimeException(sprintf('Conteudo invalido retornado ao ler "%s" via PowerShell.', $entryPath));
        }

        return $decoded;
    }

    private function entryContentsWithUnzip(string $entryPath): string
    {
        $command = sprintf(
            'unzip -p %s %s 2>/dev/null',
            escapeshellarg($this->filePath),
            escapeshellarg($entryPath)
        );
        $output = shell_exec($command);
        if (!is_string($output) || $output === '') {
            throw new RuntimeException(sprintf('Falha ao ler a entrada "%s" usando unzip.', $entryPath));
        }

        return $output;
    }

    private function columnLettersFromReference(string $reference): string
    {
        return preg_replace('/[^A-Z]/i', '', strtoupper($reference)) ?? '';
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return $index;
    }
}
