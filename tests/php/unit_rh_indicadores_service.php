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

    echo "OK unit_rh_indicadores_service\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
