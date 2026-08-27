<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

// Linha crua como viria do SQL Server (chaves em minúsculo, exatamente como aliasadas na query).
$raw = [
    'identificador' => '1-2-345',
    'codigo_empresa' => '1',
    'codigo_unidade' => '2',
    'numero_contrato' => '345',
    'codigo_pessoa' => '9876',
    'cpf' => '12345678901',
    'nome' => 'João da Silva',
    'empresa' => 'Madeplant Florestal LTDA',
    'nascimento' => '1990-01-01',
    'admissao' => '2024-03-01',
    'cargo' => 'Analista de RH',
    'demissao' => null,
    'motivo_rescisao_codigo' => null,
    'motivo_rescisao_descricao' => null,
    'unidade' => 'Matriz',
    'setor' => 'Recursos Humanos',
    'centro_custo' => 'RH-001',
    'ativo' => 1,
];

$normalized = MetadadosSyncService::normalizeSourceRow($raw);
$assert($normalized['codigo_empresa'] === '1', 'Falha: codigo_empresa deveria ser preservado.');
$assert($normalized['ativo'] === 1, 'Falha: ativo deveria ser normalizado para int.');
$assert($normalized['demissao'] === null, 'Falha: demissao ausente deveria virar null.');

// Linha sem chave 'ativo' (origem pode não trazer em algum cenário) não deve quebrar.
unset($raw['ativo']);
$normalizedSemAtivo = MetadadosSyncService::normalizeSourceRow($raw);
$assert($normalizedSemAtivo['ativo'] === null, 'Falha: ausência de ativo deveria virar null, não 0 nem erro.');

echo "OK unit_metadados_normalize_row\n";
