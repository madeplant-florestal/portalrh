<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

function registroValido(array $overrides = []): array
{
    return array_merge([
        'identificador' => 'E1-U1-1',
        'codigo_empresa' => 'E1',
        'codigo_unidade' => 'U1',
        'numero_contrato' => '1',
        'codigo_pessoa' => 'P1',
        'cpf' => '11122233344',
        'nome' => 'Colaborador Teste',
        'admissao' => '2023-01-01',
        'demissao' => null,
        'ativo' => 1,
    ], $overrides);
}

try {
    // Caso 1 — payload válido passa e devolve origem/registros normalizados.
    $payloadValido = [
        'versao' => '1',
        'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => '2026-08-31T00:00:00+00:00',
        'total' => 2,
        'registros' => [registroValido(), registroValido(['identificador' => 'E1-U1-2', 'numero_contrato' => '2'])],
    ];
    $v1 = MetadadosSyncRequestValidator::validar($payloadValido, 2000);
    $assert($v1['ok'] === true, 'Caso 1: payload válido deveria passar: ' . implode('; ', $v1['errors'] ?? []));
    $assert($v1['origem'] === 'RHMADEPLANT', 'Caso 1: origem deveria ser extraída corretamente.');
    $assert(count($v1['registros']) === 2, 'Caso 1: os 2 registros deveriam ser devolvidos.');

    // Caso 2 — campo obrigatório do envelope ausente.
    $payloadSemOrigem = $payloadValido;
    unset($payloadSemOrigem['origem_metadados']);
    $v2 = MetadadosSyncRequestValidator::validar($payloadSemOrigem, 2000);
    $assert($v2['ok'] === false, 'Caso 2: payload sem origem_metadados deveria falhar.');

    // Caso 3 — quantidade declarada (total) diverge da quantidade recebida.
    $payloadTotalDivergente = $payloadValido;
    $payloadTotalDivergente['total'] = 5;
    $v3 = MetadadosSyncRequestValidator::validar($payloadTotalDivergente, 2000);
    $assert($v3['ok'] === false, 'Caso 3: total divergente da quantidade real de registros deveria falhar.');

    // Caso 4 — chave lógica duplicada dentro do lote rejeita o lote inteiro.
    $payloadChaveDuplicada = $payloadValido;
    $payloadChaveDuplicada['registros'] = [registroValido(), registroValido()]; // mesma chave lógica (E1/U1/1) duas vezes
    $payloadChaveDuplicada['total'] = 2;
    $v4 = MetadadosSyncRequestValidator::validar($payloadChaveDuplicada, 2000);
    $assert($v4['ok'] === false, 'Caso 4: chave lógica duplicada no lote deveria rejeitar o lote inteiro.');

    // Caso 5 — origem ausente/inválida (string vazia) é rejeitada.
    $payloadOrigemVazia = $payloadValido;
    $payloadOrigemVazia['origem_metadados'] = '   ';
    $v5 = MetadadosSyncRequestValidator::validar($payloadOrigemVazia, 2000);
    $assert($v5['ok'] === false, 'Caso 5: origem_metadados em branco deveria ser rejeitada.');

    // Caso 6 — lote excede o tamanho máximo configurado.
    $registrosGrandes = [];
    for ($i = 1; $i <= 5; $i++) {
        $registrosGrandes[] = registroValido(['identificador' => "E1-U1-{$i}", 'numero_contrato' => (string)$i]);
    }
    $payloadGrande = $payloadValido;
    $payloadGrande['registros'] = $registrosGrandes;
    $payloadGrande['total'] = 5;
    $v6 = MetadadosSyncRequestValidator::validar($payloadGrande, 3);
    $assert($v6['ok'] === false, 'Caso 6: lote maior que o máximo configurado deveria ser rejeitado.');

    // Caso 7 — campo de chave lógica ausente/vazio em um registro específico rejeita o lote.
    $payloadSemChave = $payloadValido;
    $payloadSemChave['registros'] = [registroValido(['codigo_empresa' => ''])];
    $payloadSemChave['total'] = 1;
    $v7 = MetadadosSyncRequestValidator::validar($payloadSemChave, 2000);
    $assert($v7['ok'] === false, 'Caso 7: registro sem codigo_empresa deveria ser rejeitado.');

    // Caso 8 — formato de data inválido é rejeitado.
    $payloadDataInvalida = $payloadValido;
    $payloadDataInvalida['registros'] = [registroValido(['admissao' => '01/01/2023'])];
    $payloadDataInvalida['total'] = 1;
    $v8 = MetadadosSyncRequestValidator::validar($payloadDataInvalida, 2000);
    $assert($v8['ok'] === false, 'Caso 8: data fora do formato AAAA-MM-DD deveria ser rejeitada.');

    // Caso 9 — registros que não é uma lista é rejeitado sem quebrar.
    $payloadRegistrosInvalido = $payloadValido;
    $payloadRegistrosInvalido['registros'] = 'não é uma lista';
    $v9 = MetadadosSyncRequestValidator::validar($payloadRegistrosInvalido, 2000);
    $assert($v9['ok'] === false, 'Caso 9: registros não sendo uma lista deveria ser rejeitado.');

    // Caso 10 — lote vazio (0 registros) é estruturalmente válido.
    $payloadVazio = $payloadValido;
    $payloadVazio['registros'] = [];
    $payloadVazio['total'] = 0;
    $v10 = MetadadosSyncRequestValidator::validar($payloadVazio, 2000);
    $assert($v10['ok'] === true, 'Caso 10: lote vazio (0 registros, total=0) deveria ser estruturalmente válido.');

    echo "OK unit_metadados_sync_payload_validator\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
