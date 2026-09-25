<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Cobre MetadadosMovimentacaoService — classificação pura, sem banco:
 *   - classificarTransferencia()/identificarTransferencias(): Cenário B (rescisão real + novo
 *     contrato), regra fechada no diagnóstico "Transferências + Turnover por Sexo/Gênero";
 *   - classificarTransferenciaContinua()/identificarTransferenciasContinuas(): Cenário A
 *     (transferência contínua, sem rescisão real), correção de 2026-09;
 *   - classificarMovimentacoes(): ponto único que combina as duas classes.
 * Todos os dados são fictícios (CPFs de teste, nunca reais). A validação end-to-end via
 * PeopleAnalyticsService::montarPainel() (exclusão real de população/Admissões/Desligamentos/
 * Turnover) está em integration_people_analytics_transferencias.php.
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
        'ausente_na_origem' => 0,
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

    // ---- Cenário A: classificarTransferenciaContinua() — transferência contínua, sem rescisão --
    // Caso 11 — origem órfã (ausente + sem demissão) + destino vigente em outra empresa -> contínua.
    $origem11 = contratoMov(['identificador' => 'A11', 'codigo_empresa' => '0001', 'demissao' => null, 'ausente_na_origem' => 1]);
    $destino11 = contratoMov(['identificador' => 'B11', 'codigo_empresa' => '0002', 'admissao' => '2024-01-01', 'ausente_na_origem' => 0]);
    $ca11 = $M::classificarTransferenciaContinua($origem11, $destino11);
    $assert($ca11['eh_transferencia_continua'] === true, 'Caso 11: origem órfã sem demissão + destino vigente em outra empresa deveria ser transferência contínua.');
    $assert($ca11['contrato_origem'] === 'A11' && $ca11['contrato_destino'] === 'B11', 'Caso 11: identificadores de origem/destino deveriam vir no retorno.');

    // Caso 12 — origem TEM demissão preenchida (rescisão real represada) -> NÃO é Cenário A (é o B).
    $ca12 = $M::classificarTransferenciaContinua(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => '2024-01-10', 'ausente_na_origem' => 1]),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-11', 'ausente_na_origem' => 0])
    );
    $assert($ca12['eh_transferencia_continua'] === false, 'Caso 12: origem com demissão preenchida nunca é Cenário A (mesmo estando ausente_na_origem=1) — é rescisão real, cenário do classificarTransferencia().');

    // Caso 13 — origem NÃO está ausente_na_origem -> não é Cenário A (nunca sumiu da fonte).
    $ca13 = $M::classificarTransferenciaContinua(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => null, 'ausente_na_origem' => 0]),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-01', 'ausente_na_origem' => 0])
    );
    $assert($ca13['eh_transferencia_continua'] === false, 'Caso 13: origem que não está ausente_na_origem nunca é Cenário A.');

    // Caso 14 — destino TAMBÉM ausente_na_origem -> nunca vira "sucessor", classificação recusada.
    $ca14 = $M::classificarTransferenciaContinua(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['codigo_empresa' => '0002', 'admissao' => '2024-01-01', 'ausente_na_origem' => 1])
    );
    $assert($ca14['eh_transferencia_continua'] === false, 'Caso 14: destino também órfão nunca pode ser o sucessor vigente.');

    // Caso 15 — mesma empresa -> nunca transferência entre empresas (mesma regra do Cenário B).
    $ca15 = $M::classificarTransferenciaContinua(
        contratoMov(['codigo_empresa' => '0001', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['codigo_empresa' => '0001', 'admissao' => '2024-01-01', 'ausente_na_origem' => 0])
    );
    $assert($ca15['eh_transferencia_continua'] === false, 'Caso 15: mesma empresa nunca é transferência interempresa.');

    // Caso 16 — CPFs diferentes -> nunca é a mesma pessoa.
    $ca16 = $M::classificarTransferenciaContinua(
        contratoMov(['cpf' => '10000000001', 'codigo_empresa' => '0001', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['cpf' => '20000000002', 'codigo_empresa' => '0002', 'admissao' => '2024-01-01', 'ausente_na_origem' => 0])
    );
    $assert($ca16['eh_transferencia_continua'] === false, 'Caso 16: CPFs diferentes nunca podem ser classificados como a mesma transferência.');

    // ---- identificarTransferenciasContinuas(): só classifica pares INEQUÍVOCOS -------------------
    $contratosContinua = [
        // Pessoa 11: par inequívoco (1 órfão + 1 vigente) -> classificado.
        contratoMov(['identificador' => 'P11-A', 'cpf' => '11000000011', 'codigo_empresa' => '0001', 'admissao' => '2020-01-01', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['identificador' => 'P11-B', 'cpf' => '11000000011', 'codigo_empresa' => '0002', 'admissao' => '2020-01-01', 'demissao' => null, 'ausente_na_origem' => 0]),

        // Pessoa 12: 2 órfãos para o mesmo CPF (ambíguo) -> NENHUM classificado automaticamente.
        contratoMov(['identificador' => 'P12-A', 'cpf' => '12000000012', 'codigo_empresa' => '0001', 'admissao' => '2019-01-01', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['identificador' => 'P12-B', 'cpf' => '12000000012', 'codigo_empresa' => '0002', 'admissao' => '2019-06-01', 'demissao' => null, 'ausente_na_origem' => 1]),
        contratoMov(['identificador' => 'P12-C', 'cpf' => '12000000012', 'codigo_empresa' => '0003', 'admissao' => '2020-01-01', 'demissao' => null, 'ausente_na_origem' => 0]),

        // Pessoa 13: órfão sem NENHUM sucessor vigente -> não classificado (sem sucessor confiável).
        contratoMov(['identificador' => 'P13-A', 'cpf' => '13000000013', 'codigo_empresa' => '0001', 'admissao' => '2018-01-01', 'demissao' => null, 'ausente_na_origem' => 1]),

        // Pessoa 14: só 1 contrato normal (nem órfão) -> irrelevante para este classificador.
        contratoMov(['identificador' => 'P14-A', 'cpf' => '14000000014', 'codigo_empresa' => '0001', 'admissao' => '2021-01-01', 'demissao' => null, 'ausente_na_origem' => 0]),
    ];
    // Pessoa 15 — caso real encontrado na validação com os 66: um emprego ANTERIOR genuinamente
    // encerrado (com demissão real, NUNCA ausente_na_origem) não pode contar como candidato a
    // sucessor só por não estar ausente — só um registro VIGENTE DE VERDADE (sem demissão) é
    // candidato. Sem essa regra, esse grupo pareceria ter 2 "vigentes" e ficaria ambíguo.
    $contratosContinua[] = contratoMov(['identificador' => 'P15-ANTIGO', 'cpf' => '15000000015', 'codigo_empresa' => '0001', 'admissao' => '2020-01-01', 'demissao' => '2021-06-30', 'ausente_na_origem' => 0]);
    $contratosContinua[] = contratoMov(['identificador' => 'P15-A', 'cpf' => '15000000015', 'codigo_empresa' => '0005', 'admissao' => '2023-01-01', 'demissao' => null, 'ausente_na_origem' => 1]);
    $contratosContinua[] = contratoMov(['identificador' => 'P15-B', 'cpf' => '15000000015', 'codigo_empresa' => '0002', 'admissao' => '2023-01-01', 'demissao' => null, 'ausente_na_origem' => 0]);

    $continuas = $M::identificarTransferenciasContinuas($contratosContinua);
    $paresContinuas = array_map(static fn(array $t) => $t['contrato_origem'] . '->' . $t['contrato_destino'], $continuas);
    $assert(in_array('P11-A->P11-B', $paresContinuas, true), 'identificarTransferenciasContinuas: par inequívoco da Pessoa 11 deveria ser identificado.');
    $assert(in_array('P15-A->P15-B', $paresContinuas, true), 'identificarTransferenciasContinuas: Pessoa 15 deveria ser identificada mesmo com um emprego anterior real e encerrado no grupo (não é candidato a sucessor, só a Pessoa 11 e a Pessoa 15 contam).');
    $assert(count($continuas) === 2, 'identificarTransferenciasContinuas: total esperado de 2 (Pessoa 11 e Pessoa 15 — Pessoa 12 é ambígua, Pessoa 13 não tem sucessor, Pessoa 14 nem é órfã).');

    // ---- classificarMovimentacoes(): combina as duas classes num único resultado -----------------
    $contratosCombinados = array_merge(
        $contratosContinua,
        [
            contratoMov(['identificador' => 'P20-A', 'cpf' => '20000000020', 'codigo_empresa' => '0001', 'admissao' => '2019-01-01', 'demissao' => '2024-01-10', 'ausente_na_origem' => 0]),
            contratoMov(['identificador' => 'P20-B', 'cpf' => '20000000020', 'codigo_empresa' => '0002', 'admissao' => '2024-01-11', 'demissao' => null, 'ausente_na_origem' => 0]),
        ]
    );
    $combinado = $M::classificarMovimentacoes($contratosCombinados);
    $assert(count($combinado['continuas']) === 2, 'classificarMovimentacoes: 2 transferências contínuas (Cenário A: Pessoa 11 e Pessoa 15) na fixture combinada.');
    $assert(count($combinado['recontratacoes']) === 1, 'classificarMovimentacoes: 1 recontratação (Cenário B) na fixture combinada.');
    $assert(in_array('P11-A', $combinado['origem_continua_ids'], true) && in_array('P15-A', $combinado['origem_continua_ids'], true), 'classificarMovimentacoes: origem_continua_ids traz as origens do Cenário A (P11-A, P15-A), nunca a do Cenário B.');
    $assert($combinado['excluir_demissao_ids'] === ['P20-A'], 'classificarMovimentacoes: excluir_demissao_ids traz só a origem do Cenário B (P20-A) — Cenário A nunca entra aqui (nunca tem demissao preenchida).');
    $assert($combinado['excluir_admissao_ids'] === ['P20-B'], 'classificarMovimentacoes: excluir_admissao_ids traz só o destino do Cenário B (P20-B).');

    echo "OK unit_metadados_movimentacao_service\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
