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
    'salario_atual' => '1234.56',
    'data_inicio_cargo' => '2024-03-01',
];

$normalized = MetadadosSyncService::normalizeSourceRow($raw);
$assert($normalized['codigo_empresa'] === '1', 'Falha: codigo_empresa deveria ser preservado.');
$assert($normalized['ativo'] === 1, 'Falha: ativo deveria ser normalizado para int.');
$assert($normalized['demissao'] === null, 'Falha: demissao ausente deveria virar null.');
$assert((float)$normalized['salario_atual'] === 1234.56, 'Falha: salario_atual deveria preservar o valor numerico de 1234.56.');
$assert($normalized['data_inicio_cargo'] === '2024-03-01', 'Falha: data_inicio_cargo deveria ser preservada como veio da origem.');
$assert($normalized['data_inicio_cargo'] !== $normalized['admissao'] || $raw['admissao'] === $raw['data_inicio_cargo'], 'Falha: nao deveria haver fallback forcado entre os dois campos.');

// Linha sem chave 'ativo' (origem pode não trazer em algum cenário) não deve quebrar.
unset($raw['ativo']);
$normalizedSemAtivo = MetadadosSyncService::normalizeSourceRow($raw);
$assert($normalizedSemAtivo['ativo'] === null, 'Falha: ausência de ativo deveria virar null, não 0 nem erro.');

// salario_atual e data_inicio_cargo nulos na origem devem permanecer nulos, sem fallback.
$rawSemSalarioECargo = $raw;
$rawSemSalarioECargo['salario_atual'] = null;
$rawSemSalarioECargo['data_inicio_cargo'] = null;
$normalizedNulos = MetadadosSyncService::normalizeSourceRow($rawSemSalarioECargo);
$assert($normalizedNulos['salario_atual'] === null, 'Falha: salario_atual nulo na origem deveria permanecer null.');
$assert($normalizedNulos['data_inicio_cargo'] === null, 'Falha: data_inicio_cargo nulo na origem deveria permanecer null, sem fallback para admissao.');
$assert($normalizedNulos['admissao'] !== null, 'Falha (sanity check): admissao nao deveria ter sido afetada pela ausencia de data_inicio_cargo.');

// Campos totalmente ausentes (chave nem existe no array) tambem nao devem quebrar nem inventar valor.
$rawSemChaves = $raw;
unset($rawSemChaves['salario_atual'], $rawSemChaves['data_inicio_cargo']);
$normalizedSemChaves = MetadadosSyncService::normalizeSourceRow($rawSemChaves);
$assert($normalizedSemChaves['salario_atual'] === null, 'Falha: ausencia da chave salario_atual deveria virar null, nao erro.');
$assert($normalizedSemChaves['data_inicio_cargo'] === null, 'Falha: ausencia da chave data_inicio_cargo deveria virar null, nao erro.');

echo "OK unit_metadados_normalize_row\n";
