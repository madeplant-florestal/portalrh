<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

function enveloparDimensao(array $registros, array $overrides = []): array
{
    return array_merge([
        'versao' => '1',
        'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => '2026-09-04T00:00:00+00:00',
        'total' => count($registros),
        'registros' => $registros,
    ], $overrides);
}

// ===== EMPRESAS =====
$empOk = enveloparDimensao([
    ['codigo_empresa' => '0001', 'razao_social' => 'FOO LTDA'],
    ['codigo_empresa' => '0002', 'razao_social' => 'BAR SA'],
]);
$v = MetadadosDimensaoSyncRequestValidator::validar($empOk, 2000, 'empresas');
$assert($v['ok'] === true, 'Empresas: payload válido deveria passar: ' . implode('; ', $v['errors'] ?? []));
$assert($v['origem'] === 'RHMADEPLANT' && count($v['registros']) === 2, 'Empresas: origem/registros devolvidos.');
$assert(array_key_exists('correlacao_id', $v) && $v['correlacao_id'] === null, 'Empresas: correlacao_id ausente -> null.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['codigo_empresa' => '0001', 'razao_social' => '']]), 2000, 'empresas');
$assert($v['ok'] === false, 'Empresas: razao_social vazia deveria falhar.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['razao_social' => 'SEM CODIGO']]), 2000, 'empresas');
$assert($v['ok'] === false, 'Empresas: codigo_empresa ausente deveria falhar.');

$dup = enveloparDimensao([
    ['codigo_empresa' => '0001', 'razao_social' => 'FOO'],
    ['codigo_empresa' => '0001', 'razao_social' => 'FOO DE NOVO'],
]);
$v = MetadadosDimensaoSyncRequestValidator::validar($dup, 2000, 'empresas');
$assert($v['ok'] === false, 'Empresas: chave lógica duplicada no lote deveria falhar.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['codigo_empresa' => '1', 'razao_social' => 'X']], ['total' => 9]), 2000, 'empresas');
$assert($v['ok'] === false, 'Empresas: total divergente deveria falhar.');

// ===== UNIDADES =====
$uniOk = enveloparDimensao([
    ['codigo_empresa' => '0001', 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ'],
    ['codigo_empresa' => '0001', 'codigo_unidade' => '0002', 'descricao' => 'FILIAL'],
]);
$v = MetadadosDimensaoSyncRequestValidator::validar($uniOk, 2000, 'unidades');
$assert($v['ok'] === true, 'Unidades: payload válido deveria passar: ' . implode('; ', $v['errors'] ?? []));

// Mesmo codigo_unidade em empresas diferentes NÃO é duplicidade (chave composta).
$uniComposta = enveloparDimensao([
    ['codigo_empresa' => '0001', 'codigo_unidade' => '0001', 'descricao' => 'A'],
    ['codigo_empresa' => '0002', 'codigo_unidade' => '0001', 'descricao' => 'B'],
]);
$v = MetadadosDimensaoSyncRequestValidator::validar($uniComposta, 2000, 'unidades');
$assert($v['ok'] === true, 'Unidades: mesma unidade em empresas diferentes não é duplicidade.');

$uniDup = enveloparDimensao([
    ['codigo_empresa' => '0001', 'codigo_unidade' => '0001', 'descricao' => 'A'],
    ['codigo_empresa' => '0001', 'codigo_unidade' => '0001', 'descricao' => 'A DE NOVO'],
]);
$v = MetadadosDimensaoSyncRequestValidator::validar($uniDup, 2000, 'unidades');
$assert($v['ok'] === false, 'Unidades: (empresa, unidade) repetida no lote deveria falhar.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['codigo_empresa' => '0001', 'codigo_unidade' => '0001', 'descricao' => '']]), 2000, 'unidades');
$assert($v['ok'] === false, 'Unidades: descricao vazia deveria falhar.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['codigo_empresa' => '0001', 'descricao' => 'SEM UNIDADE']]), 2000, 'unidades');
$assert($v['ok'] === false, 'Unidades: codigo_unidade ausente deveria falhar.');

// ===== ENVELOPE / DIMENSÃO =====
$semOrigem = enveloparDimensao([['codigo_empresa' => '1', 'razao_social' => 'X']]);
unset($semOrigem['origem_metadados']);
$v = MetadadosDimensaoSyncRequestValidator::validar($semOrigem, 2000, 'empresas');
$assert($v['ok'] === false, 'Envelope: origem_metadados ausente deveria falhar.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([]), 2000, 'colaboradores');
$assert($v['ok'] === false, 'Dimensão não suportada (colaboradores usa o validator próprio) deveria falhar.');

$comCorrelacao = enveloparDimensao([['codigo_empresa' => '1', 'razao_social' => 'X']], ['correlacao_id' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301']);
$v = MetadadosDimensaoSyncRequestValidator::validar($comCorrelacao, 2000, 'empresas');
$assert($v['ok'] === true && $v['correlacao_id'] === '3f2504e0-4f89-41d3-9a0c-0305e82c3301', 'Envelope: correlacao_id UUID válido normalizado para minúsculas.');

$v = MetadadosDimensaoSyncRequestValidator::validar(enveloparDimensao([['codigo_empresa' => '1', 'razao_social' => 'X']], ['correlacao_id' => 'xyz']), 2000, 'empresas');
$assert($v['ok'] === false, 'Envelope: correlacao_id malformado deveria falhar.');

echo "OK unit_metadados_dimensao_payload_validator\n";
