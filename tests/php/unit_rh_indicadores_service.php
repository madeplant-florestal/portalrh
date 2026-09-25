<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Todos os dados são fictícios — nenhum CPF/nome/dado real. RhIndicadoresService nunca toca banco
// quando chamado através dos métodos static sobre arrays já carregados.
$S = RhIndicadoresService::class;

function contrato(array $overrides = []): array
{
    return array_merge([
        'codigo_empresa' => '0001',
        'empresa' => 'Empresa Teste',
        'codigo_unidade' => 'U1',
        'unidade' => 'Unidade Teste',
        'cargo' => 'Operador',
        'setor' => 'Produção',
        'centro_custo' => 'CC1',
        'admissao' => '2023-01-10',
        'demissao' => null,
        'motivo_rescisao_descricao' => null,
        'ativo' => 1,
    ], $overrides);
}

try {
    // Caso 1 — contratoAtivoEm: antes da admissão, durante, no dia exato da demissão, depois.
    $c1 = contrato(['admissao' => '2024-01-10', 'demissao' => '2024-06-30']);
    $assert($S::contratoAtivoEm($c1, new DateTimeImmutable('2024-01-01')) === false, 'Caso 1: antes da admissão não deveria estar ativo.');
    $assert($S::contratoAtivoEm($c1, new DateTimeImmutable('2024-03-01')) === true, 'Caso 1: dentro da vigência deveria estar ativo.');
    $assert($S::contratoAtivoEm($c1, new DateTimeImmutable('2024-06-30')) === true, 'Caso 1: no próprio dia da demissão ainda deveria contar como ativo (última data trabalhada).');
    $assert($S::contratoAtivoEm($c1, new DateTimeImmutable('2024-07-01')) === false, 'Caso 1: depois da demissão não deveria estar ativo.');

    $c1SemDemissao = contrato(['admissao' => '2024-01-10', 'demissao' => null]);
    $assert($S::contratoAtivoEm($c1SemDemissao, new DateTimeImmutable('2030-01-01')) === true, 'Caso 1: sem demissão deveria continuar ativo indefinidamente.');

    // Caso 2 — admissão em período: dentro, na borda, fora.
    $contratos2 = [
        contrato(['admissao' => '2024-03-01']),
        contrato(['admissao' => '2024-03-31']),
        contrato(['admissao' => '2024-04-01']),
        contrato(['admissao' => '2024-02-28']),
    ];
    $admissoes = $S::admissoesNoPeriodo($contratos2, new DateTimeImmutable('2024-03-01'), new DateTimeImmutable('2024-03-31'));
    $assert(count($admissoes) === 2, 'Caso 2: deveria contar exatamente as 2 admissões dentro do período (bordas inclusivas).');

    // Caso 3 — desligamento em período.
    $contratos3 = [
        contrato(['admissao' => '2023-01-01', 'demissao' => '2024-03-15']),
        contrato(['admissao' => '2023-01-01', 'demissao' => '2024-04-01']),
        contrato(['admissao' => '2023-01-01', 'demissao' => null]),
    ];
    $desligamentos = $S::desligamentosNoPeriodo($contratos3, new DateTimeImmutable('2024-03-01'), new DateTimeImmutable('2024-03-31'));
    $assert(count($desligamentos) === 1, 'Caso 3: deveria contar só o desligamento dentro do período.');

    // Caso 4 — readmissão: duas linhas (mesmo "codigo_pessoa" conceitual, mas o serviço não usa
    // esse campo) com vigências não sobrepostas contam como 2 contratos distintos, cada um com
    // sua própria vigência — nunca deduplicado.
    $readmissaoA = contrato(['admissao' => '2018-01-01', 'demissao' => '2019-06-30']);
    $readmissaoB = contrato(['admissao' => '2022-01-01', 'demissao' => null]);
    $assert($S::contratoAtivoEm($readmissaoA, new DateTimeImmutable('2018-06-01')) === true, 'Caso 4: primeiro contrato da readmissão deveria estar ativo em 2018.');
    $assert($S::contratoAtivoEm($readmissaoA, new DateTimeImmutable('2020-06-01')) === false, 'Caso 4: primeiro contrato da readmissão não deveria estar ativo em 2020 (já desligado).');
    $assert($S::contratoAtivoEm($readmissaoB, new DateTimeImmutable('2020-06-01')) === false, 'Caso 4: segundo contrato da readmissão não deveria estar ativo antes de sua própria admissão.');
    $assert($S::contratoAtivoEm($readmissaoB, new DateTimeImmutable('2023-06-01')) === true, 'Caso 4: segundo contrato da readmissão deveria estar ativo depois de sua própria admissão.');
    $assert($S::headcountEm([$readmissaoA, $readmissaoB], new DateTimeImmutable('2018-06-01')) === 1, 'Caso 4: headcount em 2018 deveria contar 1 (só o primeiro contrato ativo).');
    $assert($S::headcountEm([$readmissaoA, $readmissaoB], new DateTimeImmutable('2020-06-01')) === 0, 'Caso 4: headcount no intervalo entre os dois contratos deveria ser 0.');

    // Caso 5 — headcount histórico com múltiplos contratos entrando/saindo.
    $contratos5 = [
        contrato(['admissao' => '2023-01-01', 'demissao' => null]),
        contrato(['admissao' => '2023-06-01', 'demissao' => '2023-12-31']),
        contrato(['admissao' => '2023-09-01', 'demissao' => null]),
    ];
    $assert($S::headcountEm($contratos5, new DateTimeImmutable('2023-03-01')) === 1, 'Caso 5: headcount em março deveria ser 1.');
    $assert($S::headcountEm($contratos5, new DateTimeImmutable('2023-07-01')) === 2, 'Caso 5: headcount em julho deveria ser 2.');
    $assert($S::headcountEm($contratos5, new DateTimeImmutable('2023-10-01')) === 3, 'Caso 5: headcount em outubro deveria ser 3 (os 3 contratos coexistem — o do meio só desliga em 31/12).');
    $assert($S::headcountEm($contratos5, new DateTimeImmutable('2024-01-15')) === 2, 'Caso 5: headcount em janeiro/2024 deveria ser 2 (o do meio já desligado em 31/12, o terceiro entrou em setembro e continua).');

    // Caso 6 — turnover conforme fórmula aprovada: desligamentos / média(headcount início, fim) × 100.
    // 10 ativos no início do mês, 2 desligamentos no mês, 8 ativos no fim -> média 9 -> 2/9*100 = 22.2%.
    $assert(abs($S::taxaTurnover(2, 10, 8) - 22.2) < 0.05, 'Caso 6: taxa de turnover deveria seguir desligamentos/média(inicio,fim)*100.');
    $assert($S::taxaTurnover(0, 0, 0) === 0.0, 'Caso 6: sem desligamentos e sem headcount não pode dividir por zero — resultado 0.');
    $assert($S::taxaTurnover(5, 0, 0) === 0.0, 'Caso 6: headcount médio zero nunca pode gerar divisão por zero, mesmo com desligamentos.');

    // Caso 7 — turnover precoce: faixas e indicador headline (<=90 dias).
    $desligamentosPrecoce = [
        contrato(['admissao' => '2024-01-01', 'demissao' => '2024-01-20']), // 19 dias -> até 30
        contrato(['admissao' => '2024-01-01', 'demissao' => '2024-02-15']), // 45 dias -> 31-60
        contrato(['admissao' => '2024-01-01', 'demissao' => '2024-04-01']), // 91 dias -> 91-180
        contrato(['admissao' => '2020-01-01', 'demissao' => '2024-01-01']), // anos -> acima de 365
    ];
    $precoce = $S::turnoverPrecoce($desligamentosPrecoce);
    $assert($precoce['total_desligamentos'] === 4, 'Caso 7: total de desligamentos deveria ser 4.');
    $assert($precoce['precoces'] === 2, 'Caso 7: precoces (<=90 dias) deveriam ser 2 (19 e 45 dias).');
    $assert(abs($precoce['percentual_precoce'] - 50.0) < 0.01, 'Caso 7: percentual precoce deveria ser 50%.');
    $labelsComQuantidade = array_column($precoce['faixas'], 'quantidade', 'label');
    $assert($labelsComQuantidade['Até 30 dias'] === 1, 'Caso 7: faixa "Até 30 dias" deveria ter 1.');
    $assert($labelsComQuantidade['31 a 60 dias'] === 1, 'Caso 7: faixa "31 a 60 dias" deveria ter 1.');
    $assert($labelsComQuantidade['Acima de 365 dias'] === 1, 'Caso 7: faixa "Acima de 365 dias" deveria ter 1.');

    // Caso 8 — dimensão NULL/vazia vira "Não informado", nunca descarta o registro.
    $contratos8 = [
        contrato(['setor' => null]),
        contrato(['setor' => '']),
        contrato(['setor' => 'Produção']),
    ];
    $distSetor = $S::distribuicao($contratos8, 'setor');
    $naoInformado = array_values(array_filter($distSetor, static fn(array $d) => $d['label'] === RhIndicadoresService::NAO_INFORMADO))[0] ?? null;
    $assert($naoInformado !== null && $naoInformado['quantidade'] === 2, 'Caso 8: os 2 registros sem setor deveriam virar "Não informado", nunca ser descartados.');
    $totalDistribuido = array_sum(array_column($distSetor, 'quantidade'));
    $assert($totalDistribuido === 3, 'Caso 8: nenhum registro deveria ser perdido na distribuição por dimensão.');

    // Caso 9 — período sem nenhum movimento não pode quebrar nada.
    $painelVazio = $S::montarPainelComContratos([], new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'));
    $assert($painelVazio['headcount_atual'] === 0, 'Caso 9: painel sem contratos deveria ter headcount 0.');
    $assert($painelVazio['turnover_periodo'] === 0.0, 'Caso 9: painel sem contratos deveria ter turnover 0, sem divisão por zero.');
    $assert($painelVazio['admissoes_periodo'] === 0 && $painelVazio['desligamentos_periodo'] === 0, 'Caso 9: sem contratos, admissões e desligamentos devem ser 0.');

    // Caso 10 — mediana resiste a outlier que distorceria a média.
    $hoje = new DateTimeImmutable('2024-01-01');
    $contratos10 = [
        contrato(['admissao' => $hoje->modify('-30 days')->format('Y-m-d')]),
        contrato(['admissao' => $hoje->modify('-40 days')->format('Y-m-d')]),
        contrato(['admissao' => $hoje->modify('-50 days')->format('Y-m-d')]),
        contrato(['admissao' => $hoje->modify('-7300 days')->format('Y-m-d')]), // outlier de ~20 anos
    ];
    $tempo = $S::tempoPermanencia($contratos10, $hoje);
    $assert($tempo['mediana_dias'] < $tempo['media_dias'], 'Caso 10: a mediana deveria ficar bem abaixo da média distorcida pelo outlier de 20 anos.');
    $assert($tempo['mediana_dias'] < 100, 'Caso 10: a mediana deveria refletir o grupo majoritário (dezenas de dias), não o outlier.');

    // Extra — turnoverPorDimensao mostra quantidade absoluta e taxa, nunca só volume.
    $contratosDim = [
        contrato(['setor' => 'A', 'admissao' => '2023-01-01', 'demissao' => null]),
        contrato(['setor' => 'A', 'admissao' => '2023-01-01', 'demissao' => '2024-01-15']),
        contrato(['setor' => 'B', 'admissao' => '2023-01-01', 'demissao' => null]),
        contrato(['setor' => 'B', 'admissao' => '2023-01-01', 'demissao' => null]),
        contrato(['setor' => 'B', 'admissao' => '2023-01-01', 'demissao' => null]),
        contrato(['setor' => 'B', 'admissao' => '2023-01-01', 'demissao' => '2024-01-20']),
    ];
    $turnoverDim = $S::turnoverPorDimensao($contratosDim, 'setor', new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'));
    $porLabel = [];
    foreach ($turnoverDim as $item) {
        $porLabel[$item['label']] = $item;
    }
    $assert($porLabel['A']['desligamentos'] === 1, 'Extra: setor A deveria ter 1 desligamento no período.');
    $assert($porLabel['B']['desligamentos'] === 1, 'Extra: setor B deveria ter 1 desligamento no período.');
    $assert($porLabel['A']['taxa'] > $porLabel['B']['taxa'], 'Extra: setor A (1 desligamento em headcount menor) deveria ter taxa maior que setor B (1 desligamento em headcount maior) mesmo com volume absoluto igual — é exatamente o que a taxa deveria capturar.');

    // Caso 11 — diagnóstico "Transferências + Turnover por Sexo/Gênero": `distribuicao()` e
    // `turnoverPorDimensao()` já são genéricos por nome de campo — nenhuma mudança de código foi
    // necessária nelas, só confirmar que 'sexo' funciona como qualquer outra dimensão (empresa,
    // unidade, cargo, setor, centro_custo). Chave interna continua `sexo`, nunca `genero`.
    $contratosSexo = [
        contrato(['sexo' => 'M']),
        contrato(['sexo' => 'M']),
        contrato(['sexo' => 'M']),
        contrato(['sexo' => 'F']),
        contrato(['sexo' => 'F']),
        contrato(['sexo' => null]),
    ];
    $distSexo = $S::distribuicao($contratosSexo, 'sexo');
    $porSexo = array_column($distSexo, 'quantidade', 'label');
    $assert(($porSexo['M'] ?? null) === 3, 'Caso 11: distribuicao(sexo) deveria contar 3 registros M.');
    $assert(($porSexo['F'] ?? null) === 2, 'Caso 11: distribuicao(sexo) deveria contar 2 registros F.');
    $assert(($porSexo[RhIndicadoresService::NAO_INFORMADO] ?? null) === 1, 'Caso 11: sexo nulo deveria virar "Não informado", nunca ser descartado (mesmo comportamento de qualquer outra dimensão).');
    $assert(array_sum($porSexo) === 6, 'Caso 11: nenhum registro deveria ser perdido na distribuição por sexo.');

    // turnoverPorDimensao('sexo', ...) — mesma fórmula oficial (desligamentos / média(headcount
    // início, fim) × 100), cada sexo com sua própria população, sem denominador geral vazando
    // para o numerador segmentado. Cenário do diagnóstico: Masculino 10→8 com 2 desligamentos
    // (22,2%), Feminino 5→5 com 1 desligamento (20,0%).
    $contratosTurnoverSexo = [];
    // 8 homens permanecem ativos o mês inteiro (compõem o headcount de início E de fim).
    for ($i = 0; $i < 8; $i++) {
        $contratosTurnoverSexo[] = contrato(['sexo' => 'M', 'admissao' => '2023-01-01', 'demissao' => null]);
    }
    // 2 homens são desligados dentro do período (contam no headcount de início, não no de fim).
    $contratosTurnoverSexo[] = contrato(['sexo' => 'M', 'admissao' => '2023-01-01', 'demissao' => '2024-01-10']);
    $contratosTurnoverSexo[] = contrato(['sexo' => 'M', 'admissao' => '2023-01-01', 'demissao' => '2024-01-20']);
    // 4 mulheres permanecem ativas o mês inteiro.
    for ($i = 0; $i < 4; $i++) {
        $contratosTurnoverSexo[] = contrato(['sexo' => 'F', 'admissao' => '2023-01-01', 'demissao' => null]);
    }
    // 1 mulher é desligada dentro do período e 1 é admitida dentro do período (repõe a vaga) —
    // é assim que o headcount final permanece 5 mesmo com 1 desligamento, exatamente como no
    // cenário do diagnóstico (início=5, fim=5, desligamentos=1).
    $contratosTurnoverSexo[] = contrato(['sexo' => 'F', 'admissao' => '2023-01-01', 'demissao' => '2024-01-15']);
    $contratosTurnoverSexo[] = contrato(['sexo' => 'F', 'admissao' => '2024-01-05', 'demissao' => null]);

    $turnoverSexo = $S::turnoverPorDimensao($contratosTurnoverSexo, 'sexo', new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'));
    $porLabelSexo = [];
    foreach ($turnoverSexo as $item) {
        $porLabelSexo[$item['label']] = $item;
    }
    $assert($porLabelSexo['M']['desligamentos'] === 2, 'Caso 11: Masculino deveria ter 2 desligamentos no período (headcount início=10, fim=8).');
    $assert(abs($porLabelSexo['M']['taxa'] - 22.2) < 0.05, 'Caso 11: turnover Masculino deveria ser 2/média(10,8)*100 = 22,2%, pela mesma fórmula oficial.');
    $assert($porLabelSexo['F']['desligamentos'] === 1, 'Caso 11: Feminino deveria ter 1 desligamento no período (headcount início=5, fim=5).');
    $assert(abs($porLabelSexo['F']['taxa'] - 20.0) < 0.05, 'Caso 11: turnover Feminino deveria ser 1/média(5,5)*100 = 20,0%, pela mesma fórmula oficial.');

    // ---- Casos 12-14 (vigente × histórico — reconciliação de ausência) --------------------
    // Ver migration 2026-09-24-colaboradores-metadados-reconciliacao-ausencia.sql: um contrato
    // com ausente_na_origem=1 nunca pode contar como população VIGENTE agora, mas precisa
    // continuar contando normalmente em qualquer cálculo histórico de uma data passada — a
    // ausência foi detectada HOJE, não reescreve o passado.
    $hoje = new DateTimeImmutable('today');

    // Caso 12 — ausente, sem demissão (o padrão real dos "66 stale" do diagnóstico).
    $contratoAusenteAtivo = contrato(['admissao' => '2025-01-01', 'demissao' => null, 'ausente_na_origem' => 1]);
    $painelAtual12 = $S::montarPainelComContratos([$contratoAusenteAtivo], $hoje, $hoje);
    $assert($painelAtual12['headcount_atual'] === 0, 'Caso 12: registro ausente_na_origem=1 nunca conta no headcount ATUAL (fim=hoje).');
    $painelHistorico12 = $S::montarPainelComContratos([$contratoAusenteAtivo], new DateTimeImmutable('2025-05-01'), new DateTimeImmutable('2025-06-01'));
    $assert($painelHistorico12['headcount_atual'] === 1, 'Caso 12: o MESMO registro ausente continua contando no headcount de uma data histórica (2025-06-01), onde estava genuinamente vigente — ausência detectada hoje não reescreve o passado.');

    // Caso 13 — ausente, com demissão histórica (contrato já encerrado no passado).
    $contratoAusenteDesligado = contrato(['admissao' => '2025-01-01', 'demissao' => '2025-12-31', 'ausente_na_origem' => 1]);
    $painelAtual13 = $S::montarPainelComContratos([$contratoAusenteDesligado], $hoje, $hoje);
    $assert($painelAtual13['headcount_atual'] === 0, 'Caso 13: registro ausente e já desligado nunca conta no headcount ATUAL.');
    $painelHistorico13 = $S::montarPainelComContratos([$contratoAusenteDesligado], new DateTimeImmutable('2025-05-01'), new DateTimeImmutable('2025-06-01'));
    $assert($painelHistorico13['headcount_atual'] === 1, 'Caso 13: o mesmo registro continua contando no headcount de 2025-06-01 (dentro da janela em que esteve genuinamente ativo) — histórico preservado mesmo estando ausente hoje.');
    $admissoesHistoricas13 = $S::admissoesNoPeriodo([$contratoAusenteDesligado], new DateTimeImmutable('2024-12-01'), new DateTimeImmutable('2025-01-31'));
    $assert(count($admissoesHistoricas13) === 1, 'Caso 13: admissoesNoPeriodo() continua contando o registro ausente normalmente — reconciliação não muda admissões.');
    $desligamentosHistoricos13 = $S::desligamentosNoPeriodo([$contratoAusenteDesligado], new DateTimeImmutable('2025-12-01'), new DateTimeImmutable('2025-12-31'));
    $assert(count($desligamentosHistoricos13) === 1, 'Caso 13: desligamentosNoPeriodo() continua contando o registro ausente normalmente — reconciliação não muda desligamentos.');

    // Caso 14 — registro normal (sem a chave ausente_na_origem, equivalente a 0): nada muda.
    $contratoNormal = contrato(['admissao' => '2025-01-01', 'demissao' => null]);
    $painelNormalAtual = $S::montarPainelComContratos([$contratoNormal], $hoje, $hoje);
    $assert($painelNormalAtual['headcount_atual'] === 1, 'Caso 14: registro sem ausente_na_origem (default 0) continua contando normalmente no headcount atual.');

    // distribuicao_* é sempre uma fotografia de "agora" (já era, antes desta mudança) — por isso
    // exclui o ausente mesmo quando o painel foi pedido para uma data histórica ($fim=2025-06-01).
    $painelDist = $S::montarPainelComContratos([$contratoAusenteAtivo, $contratoNormal], new DateTimeImmutable('2025-05-01'), new DateTimeImmutable('2025-06-01'));
    $totalDistribuicaoEmpresa = array_sum(array_column($painelDist['distribuicao_empresa'], 'quantidade'));
    $assert($totalDistribuicaoEmpresa === 1, 'Caso 12b: distribuicao_empresa exclui o registro ausente mesmo com $fim histórico, porque distribuicao() é sempre uma fotografia de agora — só o contrato normal entra.');

    // Caso 15 — admissoesReaisNoPeriodo() (correção de 2026-09: "admissões duplicadas por
    // transferência contínua"). Cada fixture roda pelo classificador REAL
    // (MetadadosMovimentacaoService::classificarMovimentacoes()), nunca uma reimplementação
    // paralela — mesma forma que PeopleAnalyticsService alimenta o método em produção.
    $inicioP = new DateTimeImmutable('2025-10-01');
    $fimP = new DateTimeImmutable('2026-09-25');

    // 15-A — admissão normal, sem qualquer transferência: 1 admissão.
    $a1 = contrato(['identificador' => 'A1', 'cpf' => '10000000001', 'codigo_empresa' => '0001', 'admissao' => '2026-01-10', 'demissao' => null]);
    $classA = MetadadosMovimentacaoService::classificarMovimentacoes([$a1]);
    $resA = $S::admissoesReaisNoPeriodo([$a1], $inicioP, $fimP, $classA['excluir_admissao_ids'], $classA['origem_continua_ids']);
    $assert(count($resA) === 1, '15-A: admissão normal sem transferência conta 1 vez.');

    // 15-B — transferência contínua (Cenário A), admissão preservada DENTRO do período: origem
    // (órfã) e destino compartilham a MESMA admissao — deve contar 1 admissão total, nunca 2.
    $bOrigem = contrato(['identificador' => 'B-ORIGEM', 'cpf' => '10000000002', 'codigo_empresa' => '0001', 'admissao' => '2026-02-01', 'demissao' => null, 'ausente_na_origem' => 1]);
    $bDestino = contrato(['identificador' => 'B-DESTINO', 'cpf' => '10000000002', 'codigo_empresa' => '0002', 'admissao' => '2026-02-01', 'demissao' => null, 'ausente_na_origem' => 0]);
    $classB = MetadadosMovimentacaoService::classificarMovimentacoes([$bOrigem, $bDestino]);
    $assert(count($classB['continuas']) === 1, '15-B (pré-condição): o par foi classificado como transferência contínua.');
    $resB = $S::admissoesReaisNoPeriodo([$bOrigem, $bDestino], $inicioP, $fimP, $classB['excluir_admissao_ids'], $classB['origem_continua_ids']);
    $assert(count($resB) === 1, '15-B: transferência contínua com admissão preservada dentro do período conta 1 admissão total, nunca 2 (contrato de origem não gera evento duplicado).');
    $assert($resB[0]['identificador'] === 'B-DESTINO', '15-B: a admissão contabilizada é a do contrato de DESTINO (vigente), nunca a do órfão.');

    // 15-C — mesma transferência contínua, mas a admissão preservada cai FORA do período
    // consultado: 0 admissões (nem origem nem destino geram evento neste período).
    $cOrigem = contrato(['identificador' => 'C-ORIGEM', 'cpf' => '10000000003', 'codigo_empresa' => '0001', 'admissao' => '2020-05-01', 'demissao' => null, 'ausente_na_origem' => 1]);
    $cDestino = contrato(['identificador' => 'C-DESTINO', 'cpf' => '10000000003', 'codigo_empresa' => '0002', 'admissao' => '2020-05-01', 'demissao' => null, 'ausente_na_origem' => 0]);
    $classC = MetadadosMovimentacaoService::classificarMovimentacoes([$cOrigem, $cDestino]);
    $resC = $S::admissoesReaisNoPeriodo([$cOrigem, $cDestino], $inicioP, $fimP, $classC['excluir_admissao_ids'], $classC['origem_continua_ids']);
    $assert(count($resC) === 0, '15-C: admissão original fora do período consultado nunca gera evento — nem origem nem destino contam.');

    // 15-D — recontratação real (mesma empresa, saída e volta genuínas — nunca bate como
    // transferência, já que exige codigo_empresa diferente): a nova admissão conta normalmente.
    $dAntigo = contrato(['identificador' => 'D-ANTIGO', 'cpf' => '10000000004', 'codigo_empresa' => '0001', 'admissao' => '2020-01-01', 'demissao' => '2023-01-01']);
    $dNovo = contrato(['identificador' => 'D-NOVO', 'cpf' => '10000000004', 'codigo_empresa' => '0001', 'admissao' => '2026-03-15', 'demissao' => null]);
    $classD = MetadadosMovimentacaoService::classificarMovimentacoes([$dAntigo, $dNovo]);
    $assert($classD['continuas'] === [] && $classD['recontratacoes'] === [], '15-D (pré-condição): mesma empresa nunca é classificada como transferência.');
    $resD = $S::admissoesReaisNoPeriodo([$dAntigo, $dNovo], $inicioP, $fimP, $classD['excluir_admissao_ids'], $classD['origem_continua_ids']);
    $assert(count($resD) === 1 && $resD[0]['identificador'] === 'D-NOVO', '15-D: recontratação real (mesma empresa, saída e volta genuínas) conta como admissão normal.');

    // 15-E — Cenário B (rescisão + recontratação em outra empresa, gap curto): comportamento já
    // existente preservado — o contrato de destino não conta como admissão real.
    $eOrigem = contrato(['identificador' => 'E-ORIGEM', 'cpf' => '10000000005', 'codigo_empresa' => '0001', 'admissao' => '2020-01-01', 'demissao' => '2026-01-10']);
    $eDestino = contrato(['identificador' => 'E-DESTINO', 'cpf' => '10000000005', 'codigo_empresa' => '0002', 'admissao' => '2026-01-13', 'demissao' => null]);
    $classE = MetadadosMovimentacaoService::classificarMovimentacoes([$eOrigem, $eDestino]);
    $assert(count($classE['recontratacoes']) === 1, '15-E (pré-condição): rescisão + recontratação com gap curto em outra empresa é classificada como Cenário B.');
    $resE = $S::admissoesReaisNoPeriodo([$eOrigem, $eDestino], $inicioP, $fimP, $classE['excluir_admissao_ids'], $classE['origem_continua_ids']);
    $assert(count($resE) === 0, '15-E: destino do Cenário B continua excluído de Admissões (comportamento já existente, preservado por esta correção).');

    // 15-F — cenário encadeado: a MESMA pessoa passa por Cenário B (rescisão + recontratação) e,
    // depois, por Cenário A (transferência contínua) — o contrato do meio participa das DUAS
    // classificações ao mesmo tempo (destino do Cenário B + origem do Cenário A). A união dos
    // conjuntos de exclusão precisa remover esse identificador uma ÚNICA vez, nunca subtrair a
    // mesma admissão duas vezes nem deixar o contrato final de fora por engano.
    $fContrato1 = contrato(['identificador' => 'F-1', 'cpf' => '10000000006', 'codigo_empresa' => '0001', 'admissao' => '2020-01-01', 'demissao' => '2026-01-10']);
    $fContrato2 = contrato(['identificador' => 'F-2', 'cpf' => '10000000006', 'codigo_empresa' => '0002', 'admissao' => '2026-01-13', 'demissao' => null, 'ausente_na_origem' => 1]);
    $fContrato3 = contrato(['identificador' => 'F-3', 'cpf' => '10000000006', 'codigo_empresa' => '0003', 'admissao' => '2026-01-13', 'demissao' => null, 'ausente_na_origem' => 0]);
    $classF = MetadadosMovimentacaoService::classificarMovimentacoes([$fContrato1, $fContrato2, $fContrato3]);
    $assert(count($classF['recontratacoes']) === 1 && count($classF['continuas']) === 1, '15-F (pré-condição): o contrato do meio é classificado nas duas movimentações (destino do Cenário B e origem do Cenário A).');
    $assert(in_array('F-2', $classF['excluir_admissao_ids'], true) && in_array('F-2', $classF['origem_continua_ids'], true), '15-F (pré-condição): F-2 aparece nos DOIS conjuntos de exclusão.');
    $resF = $S::admissoesReaisNoPeriodo([$fContrato1, $fContrato2, $fContrato3], $inicioP, $fimP, $classF['excluir_admissao_ids'], $classF['origem_continua_ids']);
    $assert(count($resF) === 1 && $resF[0]['identificador'] === 'F-3', '15-F: cenário encadeado conta exatamente 1 admissão real (F-3) — F-2 é excluído uma única vez (união é set, nunca subtrai duas vezes o mesmo identificador), nunca sobra nem falta.');

    echo "OK unit_rh_indicadores_service\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
