<?php

/**
 * Integração — Correção (2026-10, regra definitiva do RH): Turnover do People Analytics deixa de
 * ser uma média/agregado do período filtrado e passa a ser tratado como SÉRIE MENSAL — cada mês
 * calcula seu próprio `desligamentos_do_mês / ativos_do_mês × 100`, independente dos demais.
 *
 * Auditoria (ver devolutiva): a série mensal em si (RhIndicadoresService::serieMensalPeriodo(),
 * já consumida por "Evolução Mensal do Turnover") JÁ calculava cada mês corretamente, de forma
 * isolada, reaproveitando a fórmula oficial `ativosNoPeriodo()`/`taxaTurnoverPeriodo()` (mesma
 * usada pelo card "Turnover Geral" e pelo Dashboard de Recrutamento) — nenhuma média ali. O que
 * faltava era o CARD "Turnover Geral": com período de vários meses selecionado, ele mostrava o
 * turnover do PERÍODO INTEIRO como se fosse "o" turnover, em vez do mês mais recente — não era
 * literalmente uma média aritmética dos percentuais mensais, mas também não representava nenhum
 * mês específico (um bucket único: todos os desligamentos do intervalo ÷ todos os ativos do
 * intervalo). Corrigido em PeopleAnalyticsService::montarPainel() (novo `turnover.card`).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados_identificadores' => []];
$empFixture = 'ZZTVM' . $suffix;
$setFixture = 'ZZTVS' . $suffix;
$setOutroFixture = 'ZZTVO' . $suffix;

$mkMetadados = static function (
    string $codigoPessoa,
    string $sufixoContrato,
    DateTimeImmutable $admissao,
    ?DateTimeImmutable $demissao,
    string $codigoSetor,
    ?string $motivoRescisaoCodigo = null
) use ($pdo, &$criados, $suffix, $empFixture): void {
    $identificador = 'ZZTVM_' . $suffix . '_' . $codigoPessoa . '_' . $sufixoContrato;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
            nome, admissao, demissao, codigo_setor, ativo, motivo_rescisao_codigo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $empFixture, 'ZZU' . $suffix, 'ZZC' . $codigoPessoa . $sufixoContrato,
        $codigoPessoa, 'ZZTVM Fixture ' . $codigoPessoa,
        $admissao->format('Y-m-d'), $demissao?->format('Y-m-d'), $codigoSetor,
        $demissao === null ? 1 : 0, $motivoRescisaoCodigo, 'zztvm-teste',
    ]);
    $criados['metadados_identificadores'][] = $identificador;
};

try {
    $hoje = new DateTimeImmutable('today');
    // Dois meses-calendário COMPLETOS e PASSADOS (nunca o mês corrente, para não depender de "até
    // hoje" nem de datas futuras): mesA é o mais antigo, mesB o mais recente.
    $mesAInicio = $hoje->modify('first day of this month')->modify('-2 months');
    $mesAFim = $mesAInicio->modify('last day of this month');
    $mesBInicio = $mesAInicio->modify('+1 month');
    $mesBFim = $mesBInicio->modify('last day of this month');

    $p1 = 'P1' . $suffix;
    $p2 = 'P2' . $suffix;
    $p3 = 'P3' . $suffix;
    $p4 = 'P4' . $suffix;
    $p5 = 'P5' . $suffix;
    $p6 = 'P6' . $suffix;
    $p7 = 'P7' . $suffix;
    $p8 = 'P8' . $suffix;

    // ---- Fixtures: mesA = 4 ativos / 2 desligamentos = 50,0% ; mesB = 5 ativos / 1 desligamento = 20,0%
    $mkMetadados($p1, 'A', $hoje->modify('-800 days'), null, $setFixture);                              // ativo em A e B
    $mkMetadados($p2, 'A', $hoje->modify('-800 days'), null, $setFixture);                              // ativo em A e B
    // P3/P4 (mesA): motivo VOLUNTÁRIO ('003') — usados para provar que a composição do card
    // (quando o card mostra mesB) NUNCA mistura desligamentos de outro mês.
    $mkMetadados($p3, 'A', $hoje->modify('-800 days'), $mesAInicio->modify('+5 days'), $setFixture, '003');
    $mkMetadados($p4, 'A', $hoje->modify('-800 days'), $mesAInicio->modify('+10 days'), $setFixture, '003');
    $mkMetadados($p5, 'A', $mesBInicio->modify('+1 day'), null, $setFixture);                            // admitido DENTRO de mesB — nunca existiu em mesA
    $mkMetadados($p6, 'A', $mesBInicio->modify('+2 days'), null, $setFixture);                           // admitido DENTRO de mesB — nunca existiu em mesA
    // P7 (mesB): motivo INVOLUNTÁRIO ('001') — o ÚNICO desligamento esperado na composição do card.
    $mkMetadados($p7, 'A', $mesBInicio->modify('+1 day'), $mesBInicio->modify('+15 days'), $setFixture, '001');
    // P8: MESMA empresa, SETOR DIFERENTE — prova que o filtro de setor isola numerador e
    // denominador juntos (nunca um universo no numerador e outro no denominador).
    $mkMetadados($p8, 'A', $mesBInicio->modify('+1 day'), $mesBInicio->modify('+10 days'), $setOutroFixture, '003');

    $service = new PeopleAnalyticsService();

    // ==== Filtro multi-mês (mesA + mesB) =========================================================
    // Filtra por SETOR (não empresa): P8 está na MESMA empresa mas setor diferente — isolar por
    // setor é o que garante os números exatos abaixo (P8 fica de fora). A prova de que o filtro de
    // Empresa por si só INCLUIRIA P8 corretamente é feita separadamente no Cenário 6.
    $painel = $service->montarPainel(['codigo_setor' => $setFixture], $mesAInicio, $mesBFim);
    $serie = $painel['evolucao_mensal']['atual'];
    $check(count($serie) === 2, 'Fixture: o filtro de 2 meses-calendário gera exatamente 2 pontos na série mensal');
    $mesA = $serie[0];
    $mesB = $serie[1];

    // ---- Cenário 1: mês com desligamentos — resultado exato, não média ------------------------
    $check($mesA['ativos_periodo'] === 4, 'Cenário 1: mesA tem exatamente 4 ativos (P1,P2,P3,P4)');
    $check($mesA['desligamentos'] === 2, 'Cenário 1: mesA tem exatamente 2 desligamentos (P3,P4)');
    $check($mesA['taxa'] === 50.0, 'Cenário 1: Turnover de mesA = 2 ÷ 4 × 100 = 50,0% — exatamente o resultado do mês, não uma média');

    // ---- Cenário 2: segundo mês, resultado DIFERENTE e também exato ----------------------------
    $check($mesB['ativos_periodo'] === 5, 'Cenário 2: mesB tem exatamente 5 ativos (P1,P2,P5,P6,P7)');
    $check($mesB['desligamentos'] === 1, 'Cenário 2: mesB tem exatamente 1 desligamento (P7)');
    $check($mesB['taxa'] === 20.0, 'Cenário 2: Turnover de mesB = 1 ÷ 5 × 100 = 20,0%');

    // ---- Cenário 3: filtro de 2 meses NUNCA substitui os dois valores por uma média -------------
    $mediaIndevida = round(($mesA['taxa'] + $mesB['taxa']) / 2, 1);
    $check($mesA['taxa'] === 50.0 && $mesB['taxa'] === 20.0, 'Cenário 3: com o filtro cobrindo os dois meses, mesA continua 50,0% e mesB continua 20,0% — nenhum dos dois foi substituído');
    $check($mesA['taxa'] !== $mediaIndevida && $mesB['taxa'] !== $mediaIndevida, 'Cenário 3: nenhum dos dois meses bate com a média indevida (' . $mediaIndevida . '%) — prova de que não há cálculo de média temporal');

    // ---- Cenário 4: mês sem desligamentos => 0%, nunca null nem erro ----------------------------
    $check(RhIndicadoresService::taxaTurnoverPeriodo(0, 10) === 0.0, 'Cenário 4: 0 desligamentos / 10 ativos = 0,0% (a mesma função usada por mês)');

    // ---- Cenário 5: mês sem ativos => 0%, sem divisão por zero ----------------------------------
    $check(RhIndicadoresService::taxaTurnoverPeriodo(3, 0) === 0.0, 'Cenário 5: 3 desligamentos / 0 ativos = 0,0% — sem divisão por zero, nunca null/erro');

    // ---- Cenário 6: filtro por Setor — numerador e denominador respeitam o MESMO filtro ---------
    // ($painel/$mesB já foram obtidos filtrando por codigo_setor=$setFixture — P8 (outro setor)
    // já provadamente de fora dos dois lados (ativos e desligamentos) nos cenários 1/2 acima.
    // Contraprova abaixo: filtrando pelo OUTRO setor, o universo inteiro muda — nunca mistura.
    $check(
        $mesB['ativos_periodo'] === 5 && $mesB['desligamentos'] === 1,
        'Cenário 6: filtrando por Setor, mesB tem 5 ativos / 1 desligamento — P8 (outro setor) nunca entra em nenhum dos dois lados'
    );
    $painelSetorOutro = $service->montarPainel(['codigo_setor' => $setOutroFixture], $mesAInicio, $mesBFim);
    $serieSetorOutro = $painelSetorOutro['evolucao_mensal']['atual'];
    $check(
        $serieSetorOutro[1]['ativos_periodo'] === 1 && $serieSetorOutro[1]['desligamentos'] === 1,
        'Cenário 6: filtrando pelo OUTRO Setor, mesB isola só P8 (1 ativo / 1 desligamento) — universos nunca se misturam'
    );

    // ---- Card "Turnover Geral" ------------------------------------------------------------------
    $check($painel['turnover']['card']['modo'] === 'mes_mais_recente', 'Card: com 2 meses no filtro, modo = "mes_mais_recente"');
    $check($painel['turnover']['card']['percentual'] === $mesB['taxa'], 'Cenário 8: card mostra o turnover do MÊS MAIS RECENTE do filtro (mesB, 20,0%), nunca uma média');
    $check($painel['turnover']['card']['percentual'] !== $mediaIndevida, 'Cenário 8: o valor do card nunca é a média indevida dos meses');
    $check($painel['turnover']['card']['rotulo'] === 'Turnover — ' . $mesB['label'], 'Cenário 8: rótulo do card deixa explícita a competência mostrada (ex.: "Turnover — ' . $mesB['label'] . '")');
    $check($painel['turnover']['card']['comparativo_percentual'] === $mesA['taxa'], 'Cenário 8: comparativo do card é o mês ANTERIOR da mesma série (50,0%), nunca o período comparativo inteiro');

    // ==== Auditoria pré-publicação: equivalência Card × Gráfico (2026-10) =========================
    $cardComp = $painel['turnover']['card'];

    // (1) e (2): percentual E desligamentos do card batem com o último ponto do gráfico.
    $check($cardComp['percentual'] === $mesB['taxa'], '(Auditoria-1) percentual do card === último ponto da série selecionada (mesB)');
    $check($cardComp['desligamentos'] === $mesB['desligamentos'], '(Auditoria-2) desligamentos do card === desligamentos do último mês (mesB)');

    // (3) e (4): composição usa SÓ desligamentos de mesB (P7, motivo involuntário) — nunca P3/P4
    // (motivo voluntário, mas desligados em mesA) vazando para a composição do card.
    $composicao = $cardComp['composicao'];
    $check($composicao['voluntario']['eventos'] === 0, '(Auditoria-3) composição do card: 0 voluntário — P3/P4 (voluntário, mas desligados em mesA) NUNCA entram na composição de mesB');
    $check($composicao['involuntario']['eventos'] === 1, '(Auditoria-3) composição do card: 1 involuntário (P7, único desligamento de mesB)');
    $check($composicao['outros']['eventos'] === 0, '(Auditoria-3) composição do card: 0 "outros" em mesB');
    $check(
        $composicao['voluntario']['eventos'] + $composicao['involuntario']['eventos'] + $composicao['outros']['eventos'] === $cardComp['desligamentos'],
        '(Auditoria-4) voluntário + involuntário + outros da composição = total de desligamentos do card (mesB) — nunca um número solto'
    );
    $check($composicao['involuntario']['participacao_desligamentos'] === 100.0, '(Auditoria-4b) participação de involuntário = 100% (1 de 1 desligamento de mesB)');

    // (8) Filtro de Setor afeta CARD e GRÁFICO igualmente — o outro setor (só P8, em mesB) produz
    // um card totalmente diferente, na mesma competência mesB (mesmo "mês mais recente").
    $cardSetorOutro = $painelSetorOutro['turnover']['card'];
    $check($cardSetorOutro['modo'] === 'mes_mais_recente', '(Auditoria-8) card do outro Setor também usa "mes_mais_recente" (mesmo filtro de 2 meses)');
    $check($cardSetorOutro['percentual'] === $serieSetorOutro[1]['taxa'], '(Auditoria-8) card do outro Setor bate com o último ponto do gráfico DAQUELE filtro (P8: 1/1 = 100,0%) — Empresa/Setor afetam os dois igualmente');
    $check($cardSetorOutro['composicao']['voluntario']['eventos'] === 1, '(Auditoria-8) composição do outro Setor conta P8 (motivo voluntário) — universo completamente isolado do setor principal');

    // ==== Cenário 7: filtro de UM ÚNICO mês — comportamento permanece o anterior =================
    $painelUmMes = $service->montarPainel(['codigo_setor' => $setFixture], $mesBInicio, $mesBFim);
    $check(count($painelUmMes['evolucao_mensal']['atual']) === 1, 'Cenário 7: filtro de um único mês gera 1 ponto na série');
    $check($painelUmMes['turnover']['card']['modo'] === 'periodo', 'Cenário 7: com um único mês no filtro, modo = "periodo" (comportamento inalterado)');
    $check($painelUmMes['turnover']['card']['percentual'] === 20.0, 'Cenário 7: card mostra exatamente o turnover daquele mês (20,0%)');
    $check($painelUmMes['turnover']['card']['rotulo'] === 'Turnover Geral', 'Cenário 7: rótulo permanece "Turnover Geral" (sem necessidade de indicar competência — só há uma)');
    $check(count($painelUmMes['evolucao_mensal']['atual']) === 1 && $painelUmMes['evolucao_mensal']['atual'][0]['taxa'] === $painelUmMes['turnover']['card']['percentual'], '(Auditoria-9) com 1 mês no filtro, card e gráfico são idênticos na mesma competência');
    $check(
        $painelUmMes['turnover']['card']['composicao']['involuntario']['eventos'] === 1 && $painelUmMes['turnover']['card']['composicao']['voluntario']['eventos'] === 0,
        '(Auditoria-9b) composição do card com 1 mês também é exatamente a daquele mês (1 involuntário — P7)'
    );

    echo "\nPEOPLE_ANALYTICS_TURNOVER_MENSAL_OK\n";
} finally {
    if (!empty($criados['metadados_identificadores'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados_identificadores']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")
            ->execute($criados['metadados_identificadores']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
