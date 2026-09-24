<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Cobre MetadadosMovimentacaoService::classificarTransferencia()/identificarTransferencias() —
 * regra fechada no diagnóstico "Transferências + Turnover por Sexo/Gênero". Todos os dados são
 * fictícios (CPFs de teste, nunca reais). Esta suíte NÃO valida nenhuma mudança de comportamento
 * em RhIndicadoresService/PeopleAnalyticsService — a classe testada aqui ainda não é usada por
 * nenhum dos dois (ver doc da classe).
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$M = MetadadosMovimentacaoService::class;

function contratoMov(array $overrides = []): array
{
    return array_merge([
        'identificador' => '0001-0001-1',
        'cpf' => '11122233344',
        'codigo_empresa' => '0001',
        'admissao' => '2023-01-01',
        'demissao' => null,
        'motivo_rescisao_codigo' => null,
    ], $overrides);
}

try {
    // Caso 1 — mesmo CPF + empresa diferente + gap 1 dia -> transferência.
    $origem1 = contratoMov(['identificador' => 'A1', 'codigo_empresa' => '0001', 'demissao' => '2024-01-10']);
    $destino1 = contratoMov(['identificador' => 'B1', 'codigo_empresa' => '0002', 'admissao' => '2024-01-11']);
    $c1 = $M::classificarTransferencia($origem1, $destino1);
    $assert($c1['eh_transferencia'] === true, 'Caso 1: gap de 1 dia entre empresas diferentes deveria ser transferência.');
    $assert($c1['gap_dias'] === 1, 'Caso 1: gap_dias deveria ser 1.');
    $assert($c1['contrato_origem'] === 'A1' && $c1['contrato_destino'] === 'B1', 'Caso 1: identificadores de origem/destino deveriam vir no retorno.');

    // Caso 2 — gap de 7 dias -> transferência (maior gap real observado no diagnóstico).
    $c2 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-11']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-18'])
    );
    $assert($c2['eh_transferencia'] === true, 'Caso 2: gap de 7 dias deveria ser transferência.');
    $assert($c2['gap_dias'] === 7, 'Caso 2: gap_dias deveria ser 7.');

    // Caso 3 — gap de exatamente 10 dias (limite) -> transferência.
    $c3 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-11'])
    );
    $assert($c3['eh_transferencia'] === true, 'Caso 3: gap de exatamente 10 dias (limite inclusive) deveria ser transferência.');
    $assert($c3['gap_dias'] === 10, 'Caso 3: gap_dias deveria ser 10.');

    // Caso 4 — gap de 11 dias (1 acima do limite) -> NÃO transferência.
    $c4 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-12'])
    );
    $assert($c4['eh_transferencia'] === false, 'Caso 4: gap de 11 dias deveria ficar fora da janela e não ser transferência.');
    $assert($c4['gap_dias'] === 11, 'Caso 4: gap_dias deveria continuar informado (11) mesmo não sendo transferência — é dado, não decisão escondida.');

    // Caso 5 — mesmo CPF + MESMA empresa -> nunca transferência entre empresas.
    $c5 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01']),
        contratoMov(['codigo_empresa' => '0001', 'admissao' => '2024-01-02'])
    );
    $assert($c5['eh_transferencia'] === false, 'Caso 5: mesma empresa nunca deveria ser classificado como transferência entre empresas.');

    // Caso 6 — CPF diferente + gap de 1 dia -> NÃO transferência (mesmo com todo o resto batendo).
    $c6 = $M::classificarTransferencia(
        contratoMov(['cpf' => '11122233344', 'codigo_empresa' => '0001', 'demissao' => '2024-01-01']),
        contratoMov(['cpf' => '55566677788', 'codigo_empresa' => '0002', 'admissao' => '2024-01-02'])
    );
    $assert($c6['eh_transferencia'] === false, 'Caso 6: CPFs diferentes nunca podem ser classificados como transferência.');

    // Caso 7 — motivo 016 + gap 1 dia -> transferência (motivo de reforço presente).
    $c7 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01', 'motivo_rescisao_codigo' => '016']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-02'])
    );
    $assert($c7['eh_transferencia'] === true, 'Caso 7: motivo 016 presente não deveria impedir a classificação.');
    $assert($c7['motivo_origem'] === '016', 'Caso 7: motivo_origem deveria refletir o motivo real da rescisão de origem.');

    // Caso 8 — motivo 046 (não é 011/012/016) + gap 1 dia -> AINDA transferência: motivo nunca é
    // obrigatório. Importante porque existe 1 caso real assim no diagnóstico (17/18 usam 016, 1
    // usa 046) — se o motivo fosse obrigatório, esse caso real ficaria de fora incorretamente.
    $c8 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01', 'motivo_rescisao_codigo' => '046']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-02'])
    );
    $assert($c8['eh_transferencia'] === true, 'Caso 8: motivo diferente de 016 (ex.: 046, caso real do diagnóstico) não pode impedir a classificação — motivo é só sinal complementar.');
    $assert($c8['motivo_origem'] === '046', 'Caso 8: motivo_origem deveria refletir 046.');

    // Caso 9 — motivo 016 presente, mas gap muito longo -> NÃO transferência. Prova que motivo
    // sozinho nunca basta (a maioria das 55 rescisões reais com motivo 016 são rescisões
    // legítimas, sem readmissão em outra empresa depois).
    $c9 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01', 'motivo_rescisao_codigo' => '016']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-08-01'])
    );
    $assert($c9['eh_transferencia'] === false, 'Caso 9: motivo 016 com gap de meses não deveria ser transferência — motivo sozinho não basta.');

    // Caso 10 — datas sobrepostas (destino admitido ANTES da rescisão da origem) -> gap negativo,
    // nunca classificado automaticamente.
    $c10 = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-20']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-10'])
    );
    $assert($c10['eh_transferencia'] === false, 'Caso 10: vínculos sobrepostos (gap negativo) nunca devem ser classificados automaticamente como transferência.');
    $assert($c10['gap_dias'] === -10, 'Caso 10: gap_dias deveria refletir o valor negativo real (-10), não ser ocultado.');

    // ---- Regras adicionais de estrutura (§3/§4 origem/destino sem dado) ----
    $semDemissao = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => null]),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-02'])
    );
    $assert($semDemissao['eh_transferencia'] === false, 'Regra 3: origem sem demissão nunca pode ser transferência (ainda está no primeiro vínculo).');

    $semAdmissao = $M::classificarTransferencia(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-01']),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => null])
    );
    $assert($semAdmissao['eh_transferencia'] === false, 'Regra 4: destino sem admissão nunca pode ser transferência.');

    // ---- identificarTransferencias(): agrupamento por CPF + comparação SEMPRE com o próximo
    // contrato cronológico elegível (§16 — nunca o primeiro, nunca um episódio distante) ----
    $contratos = [
        // Pessoa 1: transferência real (0001 -> 0002, gap 1 dia).
        contratoMov(['identificador' => 'P1-A', 'cpf' => '10000000001', 'codigo_empresa' => '0001', 'admissao' => '2022-01-01', 'demissao' => '2023-06-10']),
        contratoMov(['identificador' => 'P1-B', 'cpf' => '10000000001', 'codigo_empresa' => '0002', 'admissao' => '2023-06-11', 'demissao' => null]),

        // Pessoa 2: recontratação (gap de quase 2 anos) — NÃO deveria aparecer no resultado.
        contratoMov(['identificador' => 'P2-A', 'cpf' => '20000000002', 'codigo_empresa' => '0001', 'admissao' => '2019-01-01', 'demissao' => '2020-01-01']),
        contratoMov(['identificador' => 'P2-B', 'cpf' => '20000000002', 'codigo_empresa' => '0002', 'admissao' => '2022-01-01', 'demissao' => null]),

        // Pessoa 3: 3 contratos na mesma linha do tempo — só o par consecutivo B->C é transferência
        // (empresa muda de 0002 para 0003); A->B é mesma empresa (0001->0002 é diferente na
        // verdade — ajustado abaixo para also martelar "não pular direto de A pra C").
        contratoMov(['identificador' => 'P3-A', 'cpf' => '30000000003', 'codigo_empresa' => '0001', 'admissao' => '2018-01-01', 'demissao' => '2019-12-31']),
        contratoMov(['identificador' => 'P3-B', 'cpf' => '30000000003', 'codigo_empresa' => '0002', 'admissao' => '2022-01-01', 'demissao' => '2023-03-05']),
        contratoMov(['identificador' => 'P3-C', 'cpf' => '30000000003', 'codigo_empresa' => '0003', 'admissao' => '2023-03-10', 'demissao' => null]),

        // Pessoa 4: só 1 contrato — não pode gerar par nenhum.
        contratoMov(['identificador' => 'P4-A', 'cpf' => '40000000004', 'codigo_empresa' => '0001', 'admissao' => '2021-01-01', 'demissao' => null]),
    ];

    $transferencias = $M::identificarTransferencias($contratos);
    $pares = array_map(static fn(array $t) => $t['contrato_origem'] . '->' . $t['contrato_destino'], $transferencias);

    $assert(in_array('P1-A->P1-B', $pares, true), 'identificarTransferencias: deveria identificar a transferência real da Pessoa 1.');
    $assert(!in_array('P2-A->P2-B', $pares, true), 'identificarTransferencias: recontratação da Pessoa 2 (gap de anos) não deveria aparecer.');
    $assert(in_array('P3-B->P3-C', $pares, true), 'identificarTransferencias: par consecutivo B->C da Pessoa 3 deveria ser identificado (gap de 5 dias, empresas diferentes).');
    $assert(!in_array('P3-A->P3-C', $pares, true), 'identificarTransferencias: nunca deveria comparar A diretamente com C, pulando o contrato do meio.');
    $assert(!in_array('P3-A->P3-B', $pares, true), 'identificarTransferencias: A->B da Pessoa 3 tem gap de mais de 2 anos, não deveria ser transferência.');
    $assert(count($transferencias) === 2, 'identificarTransferencias: total esperado de 2 transferências reais nesta fixture (Pessoa 1 e o par B->C da Pessoa 3).');

    echo "OK unit_metadados_movimentacao_service\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
