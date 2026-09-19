<?php

/**
 * Unitário — Dashboard de Turnover (TurnoverDashboardService + helpers SVG). Só arrays sintéticos,
 * sem banco. Prova que:
 *   - a fórmula oficial (RhIndicadoresService) é aplicada mês a mês e bate com turnoverMensal() nos
 *     meses completos; contrato é a unidade de análise;
 *   - comparativo Y-1 × Y; mês corrente PARCIAL (só até hoje, ao contrário de turnoverMensal());
 *     meses futuros `null`; sem base `null` (nunca 0%) — só neste dashboard;
 *   - Admissões × Desligamentos mensais (admissao/demissao, nunca `ativo`), futuros `null`;
 *   - classificação dos 10 códigos conhecidos, código desconhecido/vazio/nulo => Outros, categorias
 *     mutuamente exclusivas, mapa em um único ponto;
 *   - Cargo por codigo_cargo (nome do catálogo, base pequena visível, sem base no fim, ordenação,
 *     top 15) e Empresa por codigo_empresa;
 *   - Tempo de Empresa: fronteiras 90/91/180/181/365/366/730/731, sem usar a data atual;
 *   - helpers gráficos (linha multissérie, colunas agrupadas, legenda, tabela acessível);
 *   - fontes sem COUNT(DISTINCT), sem `colaboradores` legado, sem escrita, sem dados pessoais.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/admin/partials/chart-helpers.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$hoje = new DateTimeImmutable('2026-09-19');
$c = static fn(?string $adm, ?string $dem, ?string $motivo = null, string $emp = '0001', string $nomeEmp = 'Empresa Um', ?string $cargoCod = 'X1', string $cargoTxt = 'Operador'): array => [
    'admissao' => $adm, 'demissao' => $dem, 'motivo_rescisao_codigo' => $motivo,
    'codigo_empresa' => $emp, 'empresa' => $nomeEmp, 'codigo_cargo' => $cargoCod, 'cargo' => $cargoTxt,
];

// ---- Dataset A: série mensal --------------------------------------------------------------------
$A = [];
for ($i = 0; $i < 10; $i++) {
    $A[] = $c('2020-01-01', null);
}
$A[] = $c('2020-01-01', '2026-01-15', '003'); // D1
$A[] = $c('2025-06-01', '2026-01-20', '002'); // D2
$A[] = $c('2026-01-10', '2026-02-05', '001'); // D3
$A[] = $c('2026-02-10', null);                // N1
$A[] = $c('2020-01-01', '2025-03-10', '016'); // D4
$A[] = $c('2019-01-01', '2025-03-20', '005'); // D5
$A[] = $c('2020-01-01', '2026-09-25', '008'); // D6 — desligamento FUTURO (agendado) em relação a "hoje"

$comp = TurnoverDashboardService::comparativoMensal($A, 2026, $hoje);
$check($comp['ano'] === 2026 && $comp['ano_anterior'] === 2025 && count($comp['atual']) === 12 && count($comp['anterior']) === 12 && count($comp['labels']) === 12, '(comparativo) Ano selecionado × ano anterior, 12 meses cada');
$check($comp['labels'][0] === 'Jan' && $comp['labels'][11] === 'Dez', '(comparativo) Eixo X = Jan a Dez');
// Jan/2026: headcount início (31/12/2025)=13; fim (31/01)=12; desligamentos do mês=2 -> 2 / 12,5 * 100 = 16,0
$check($comp['atual'][0]['taxa'] === 16.0 && $comp['atual'][0]['desligamentos'] === 2 && $comp['atual'][0]['headcount_medio'] === 12.5, '(fórmula) Jan/2026 = 2 ÷ média(13, 12) × 100 = 16,0 — headcount por CONTRATO, início = dia anterior ao mês');
$check($comp['atual'][1]['taxa'] === 8.3 && $comp['atual'][1]['desligamentos'] === 1, '(fórmula) Fev/2026 = 1 ÷ média(12, 12) × 100 = 8,3');
$check($comp['anterior'][2]['taxa'] === 15.4 && $comp['anterior'][2]['desligamentos'] === 2, '(comparativo) Mar/2025 (ano anterior) = 2 ÷ média(14, 12) × 100 = 15,4');
$check($comp['atual'][2]['taxa'] === 0.0 && $comp['atual'][2]['status'] === 'completo', '(fórmula) Mês completo com base e sem desligamentos = 0,0% (0 legítimo, diferente de "sem base")');

$viaOficial2025 = RhIndicadoresService::turnoverMensal($A, new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'))['valores'];
$viaDashboard2025 = array_map(static fn(array $m) => $m['taxa'], $comp['anterior']);
$check($viaOficial2025 === $viaDashboard2025, '(fórmula) Os 12 meses de 2025 batem EXATAMENTE com RhIndicadoresService::turnoverMensal() — nenhuma fórmula paralela');
$viaOficial2026 = RhIndicadoresService::turnoverMensal($A, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-08-31'))['valores'];
$check($viaOficial2026 === array_slice(array_map(static fn(array $m) => $m['taxa'], $comp['atual']), 0, 8), '(fórmula) Jan–Ago/2026 (meses completos) batem com turnoverMensal()');

$set = $comp['atual'][8];
$check($set['status'] === 'parcial' && $set['parcial_ate'] === '19/09', '(mês corrente) Setembro/2026 é PARCIAL, identificado "até 19/09"');
$check($set['taxa'] === 0.0 && $set['desligamentos'] === 0, '(mês corrente) Só até hoje: o desligamento agendado para 25/09 não entra e o headcount final é o de hoje');
$mesCheioSet = RhIndicadoresService::turnoverMensal($A, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'))['valores'][0];
$check($mesCheioSet === 8.7 && $set['taxa'] !== $mesCheioSet, '(mês corrente) Diferente do mês cheio (8,7% em turnoverMensal) — o parcial NÃO projeta o mês inteiro');
foreach ([9, 10, 11] as $i) {
    $m = $comp['atual'][$i];
    $check($m['status'] === 'futuro' && $m['taxa'] === null && $m['desligamentos'] === null, '(meses futuros) ' . $comp['labels'][$i] . '/2026 => null (sem ponto), nunca 0%');
}
$check(array_unique(array_map(static fn(array $m) => $m['status'], $comp['anterior'])) === ['completo'], '(comparativo) O ano anterior tem sempre meses completos');

// ---- Sem base: nunca 0% ---------------------------------------------------------------------------
$vazio = TurnoverDashboardService::comparativoMensal([], 2026, $hoje);
$semBase = array_filter($vazio['anterior'], static fn(array $m) => $m['taxa'] !== null);
$check($semBase === [], '(sem base) Sem nenhum contrato, os 12 meses de 2025 são null — jamais 0%');
$check($vazio['anterior'][0]['status'] === 'completo' && $vazio['anterior'][0]['taxa'] === null, '(sem base) Mês completo sem base = null (não "futuro"), a view mostra "Sem base"');
$check(TurnoverDashboardService::taxaOuNull(3, 0, 0) === null && TurnoverDashboardService::taxaOuNull(0, 0, 0) === null, '(sem base) taxaOuNull: média de headcount <= 0 => null');
$check(TurnoverDashboardService::taxaOuNull(1, 1, 0) === 200.0 && TurnoverDashboardService::taxaOuNull(0, 4, 4) === 0.0, '(sem base) Com base válida usa a fórmula oficial (incl. 0% legítimo)');
$check(RhIndicadoresService::taxaTurnover(0, 0, 0) === 0.0, '(regressão) RhIndicadoresService::taxaTurnover() continua devolvendo 0.0 sem base — semântica dos dashboards existentes intacta');
$soMarco = [$c('2026-03-01', '2026-03-20')];
$marco = TurnoverDashboardService::comparativoMensal($soMarco, 2026, $hoje);
$check($marco['atual'][2]['taxa'] === null && $marco['atual'][2]['desligamentos'] === 1, '(sem base) Contrato admitido e desligado no mesmo mês: 1 desligamento, base 0 => null, nunca 0%');

// ---- Admissões × Desligamentos --------------------------------------------------------------------
$ad = TurnoverDashboardService::admissoesDesligamentosMensal($A, 2026, $hoje);
$adm = array_map(static fn(array $m) => $m['admissoes'], $ad['meses']);
$dem = array_map(static fn(array $m) => $m['desligamentos'], $ad['meses']);
$check(array_slice($adm, 0, 3) === [1, 1, 0] && array_slice($dem, 0, 3) === [2, 1, 0], '(adm×deslig) Jan/Fev/Mar 2026: admissões 1,1,0 e desligamentos 2,1,0 — por admissao/demissao do contrato');
$check($adm[8] === 0 && $dem[8] === 0 && $ad['meses'][8]['status'] === 'parcial' && $ad['parcial_ate'] === '19/09', '(adm×deslig) Mês corrente parcial (até 19/09), sem o desligamento agendado');
$check($adm[9] === null && $dem[9] === null && $adm[11] === null && $dem[11] === null, '(adm×deslig) Meses futuros null — não preenchidos com zero');
$semAtivo = array_map(static fn(array $x) => array_merge($x, ['ativo' => 1]), $A);
$check(TurnoverDashboardService::admissoesDesligamentosMensal($semAtivo, 2026, $hoje) === $ad, '(adm×deslig) Independe do flag `ativo` — só admissao/demissao');
$check(count(RhIndicadoresService::admissoesNoPeriodo($A, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'))) === $adm[0], '(adm×deslig) Reaproveita RhIndicadoresService::admissoesNoPeriodo()');

// ---- Motivos ---------------------------------------------------------------------------------------
$esperado = ['001' => 'Justa Causa', '002' => 'Involuntário', '003' => 'Voluntário', '005' => 'Término de Contrato', '006' => 'Voluntário', '007' => 'Involuntário', '008' => 'Término de Contrato', '016' => 'Acordo', '020' => 'Outros', '046' => 'Outros'];
foreach ($esperado as $codigo => $categoria) {
    $check(TurnoverDashboardService::categoriaDoMotivo($codigo) === $categoria, "(motivos) {$codigo} => {$categoria}");
}
foreach (['999', 'X1', ' ', ''] as $desconhecido) {
    $check(TurnoverDashboardService::categoriaDoMotivo($desconhecido) === 'Outros', '(motivos) Código desconhecido/vazio "' . $desconhecido . '" => Outros');
}
$check(TurnoverDashboardService::categoriaDoMotivo(null) === 'Outros', '(motivos) Código nulo => Outros');
$todosCodigos = array_merge(...array_values(TurnoverDashboardService::MAPA_MOTIVOS));
$check(count($todosCodigos) === count(array_unique($todosCodigos)), '(motivos) Categorias mutuamente exclusivas: nenhum código aparece em duas categorias');
$check(array_keys(TurnoverDashboardService::MAPA_MOTIVOS) === ['Voluntário', 'Involuntário', 'Justa Causa', 'Término de Contrato', 'Acordo'] && TurnoverDashboardService::ORDEM_CATEGORIAS[5] === 'Outros', '(motivos) Mapa centralizado em UMA constante; Outros é o resíduo');
$mot = TurnoverDashboardService::desligamentosPorMotivo($A, new DateTimeImmutable('2026-01-01'), $hoje);
$porCategoria = array_column($mot['categorias'], 'quantidade', 'categoria');
$check($mot['total'] === 3 && $porCategoria['Voluntário'] === 1 && $porCategoria['Involuntário'] === 1 && $porCategoria['Justa Causa'] === 1 && $porCategoria['Término de Contrato'] === 0 && $porCategoria['Acordo'] === 0 && $porCategoria['Outros'] === 0, '(motivos) 2026 até hoje: Voluntário 1, Involuntário 1, Justa Causa 1 (desligamento agendado fora do período)');
$check(array_sum($porCategoria) === $mot['total'], '(motivos) A soma das categorias é igual ao total (nenhum desligamento em duas categorias nem fora)');
$outros = [$c('2020-01-01', '2026-02-01', '020'), $c('2020-01-01', '2026-02-02', '046'), $c('2020-01-01', '2026-02-03', '999'), $c('2020-01-01', '2026-02-04', null), $c('2020-01-01', '2026-02-05', '')];
$motOutros = TurnoverDashboardService::desligamentosPorMotivo($outros, new DateTimeImmutable('2026-01-01'), $hoje);
$check(array_column($motOutros['categorias'], 'quantidade', 'categoria')['Outros'] === 5, '(motivos) 020, 046, código futuro, nulo e vazio => Outros (5)');
$check($motOutros['detalhe_outros'] === [['codigo' => '020', 'quantidade' => 1], ['codigo' => '046', 'quantidade' => 1], ['codigo' => '999', 'quantidade' => 1], ['codigo' => 'sem código', 'quantidade' => 2]], '(motivos) Detalhe dos códigos em "Outros" para transparência');

// ---- Cargo e Empresa (ano 2025 fechado) ----------------------------------------------------------------
$B = [];
for ($i = 0; $i < 4; $i++) {
    $B[] = $c('2020-01-01', null, null, '0001', 'Empresa Um', 'X1', 'Operador');
}
$B[] = $c('2020-01-01', '2025-06-10', '003', '0001', 'Empresa Um', 'X1', 'Operador I');   // x5: mesmo código, outro texto
$B[] = $c('2020-01-01', null, null, '0001', 'Empresa Um', 'X2', 'Operador');              // y1: mesmo texto, outro código
$B[] = $c('2020-01-01', '2025-08-01', '002', '0002', 'Dois', 'X2', 'Operador');           // y2
$B[] = $c('2020-01-01', '2025-05-05', '003', '0002', 'Dois LTDA', 'Y1', 'Cargo Y');       // y3
$B[] = $c('2025-03-01', '2025-03-20', '005', '0003', 'Tres', 'Z1', 'Cargo Z');            // z1: sem base
$B[] = $c('2020-01-01', null, null, '0001', 'Empresa Um', 'W1', 'Cargo W');               // w1
$B[] = $c('2020-01-01', null, null, '0001', 'Empresa Um', 'W1', 'Cargo W');               // w2
$B[] = $c('2020-01-01', '2025-02-02', '016', '0001', 'Empresa Um', null, 'Sem código');   // n1: sem codigo_cargo
$B[] = $c('2015-01-01', '2018-01-01', '003', '0004', 'Quatro', 'W1', 'Cargo W');          // o1: fora do período
$B[] = $c('2020-01-01', null, null, '0005', 'Empresa Um', 'W1', 'Cargo W');               // q1: mesmo nome de E1, outro código
[$ini25, $fim25] = TurnoverDashboardService::periodoDoAno(2025, $hoje);
$check($ini25->format('Y-m-d') === '2025-01-01' && $fim25->format('Y-m-d') === '2025-12-31', '(período) Ano encerrado: 01/01 a 31/12');
[$ini26, $fim26] = TurnoverDashboardService::periodoDoAno(2026, $hoje);
$check($ini26->format('Y-m-d') === '2026-01-01' && $fim26->format('Y-m-d') === '2026-09-19', '(período) Ano atual: acumulado de 01/01 até hoje');

$nomes = ['X1' => 'Operador Oficial', 'X2' => 'Operador Oficial 2', 'Y1' => 'Cargo Y'];
$cargos = TurnoverDashboardService::turnoverPorCargo($B, $ini25, $fim25, $nomes);
$codigos = array_column($cargos['itens'], 'codigo');
$check($codigos === ['Y1', '', 'X2', 'X1', 'Z1'], '(cargo) Ordenação: maior %, depois mais desligamentos, desempate por nome; sem base por último — ' . json_encode($codigos));
$porCodigo = [];
foreach ($cargos['itens'] as $i) {
    $porCodigo[$i['codigo']] = $i;
}
$check($porCodigo['X1']['desligamentos'] === 1 && $porCodigo['X1']['taxa'] === 22.2 && $porCodigo['X1']['base'] === 4.5, '(cargo) Por codigo_cargo: dois textos ("Operador"/"Operador I") do mesmo código viram UM cargo — 1 desl., 22,2%, base 4,5');
$check($porCodigo['X2']['taxa'] === 66.7 && $porCodigo['X2']['nome'] === 'Operador Oficial 2' && $porCodigo['X1']['nome'] === 'Operador Oficial', '(cargo) Mesmo texto "Operador" em dois códigos NÃO é agrupado; nome oficial vem do catálogo');
$check($porCodigo['Y1']['taxa'] === 200.0 && $porCodigo['Y1']['base'] === 0.5 && $porCodigo['Y1']['desligamentos'] === 1, '(cargo) Base pequena (0,5) continua aparecendo, com o percentual matemático (200,0%) e a base');
$check($porCodigo['Z1']['taxa'] === null && $porCodigo['Z1']['base'] === 0.0 && $porCodigo['Z1']['desligamentos'] === 1 && $porCodigo['Z1']['nome'] === 'Cargo Z', '(cargo) Sem base => taxa null (nunca 0%), mantém os desligamentos; nome cai no texto do espelho quando não há catálogo');
$check($porCodigo['']['nome'] === 'Não informado' && $porCodigo['']['taxa'] === 200.0, '(cargo) Contrato sem codigo_cargo vai para "Não informado"');
$check(!isset($porCodigo['W1']), '(cargo) Cargo sem desligamentos no período não entra no ranking');
$muitos = [];
for ($i = 1; $i <= 20; $i++) {
    $cod = sprintf('K%02d', $i);
    $muitos[] = $c('2020-01-01', '2025-06-01', '003', '0001', 'Empresa Um', $cod, 'Cargo ' . $cod);
    $muitos[] = $c('2020-01-01', null, null, '0001', 'Empresa Um', $cod, 'Cargo ' . $cod);
}
$nomesK = [];
foreach ($muitos as $m) {
    $nomesK[$m['codigo_cargo']] = 'Cargo ' . $m['codigo_cargo'];
}
$top = TurnoverDashboardService::turnoverPorCargo($muitos, $ini25, $fim25, $nomesK);
$check(count($top['itens']) === 15 && $top['total_cargos'] === 20 && $top['exibidos'] === 15, '(cargo) Top 15 de 20 cargos');
$check($top['itens'][0]['nome'] === 'Cargo K01' && $top['itens'][14]['nome'] === 'Cargo K15', '(cargo) Empate total: critério estável por nome');

$empresas = TurnoverDashboardService::turnoverPorEmpresa($B, $ini25, $fim25);
$check(array_column($empresas['itens'], 'codigo') === ['0002', '0001', '0005', '0003'], '(empresa) Ordenação por %, sem base por último; empresa sem desligamento e sem base (0004) fora — ' . json_encode(array_column($empresas['itens'], 'codigo')));
$porEmp = [];
foreach ($empresas['itens'] as $i) {
    $porEmp[$i['codigo']] = $i;
}
$check($porEmp['0001']['desligamentos'] === 2 && $porEmp['0001']['taxa'] === 25.0 && $porEmp['0001']['base'] === 8.0, '(empresa) Fórmula oficial: 2 ÷ média(9, 7) × 100 = 25,0');
$check($porEmp['0002']['nome'] === 'Dois LTDA' && $porEmp['0002']['desligamentos'] === 2 && $porEmp['0002']['taxa'] === 200.0, '(empresa) Por codigo_empresa: dois nomes do mesmo código viram UMA empresa (nome mais recente)');
$check($porEmp['0005']['nome'] === 'Empresa Um' && $porEmp['0001']['nome'] === 'Empresa Um' && $porEmp['0005']['codigo'] !== $porEmp['0001']['codigo'], '(empresa) Mesmo nome em dois códigos NÃO é agrupado');
$check($porEmp['0003']['taxa'] === null && $porEmp['0003']['desligamentos'] === 1, '(empresa) Sem base => null');
$check($porEmp['0005']['taxa'] === 0.0 && $porEmp['0005']['desligamentos'] === 0, '(empresa) Empresa com base e sem desligamentos aparece com 0,0% legítimo');

// contrato é a unidade: mesma pessoa, dois contratos
$doisContratos = [
    array_merge($c('2020-01-01', null, null, '0009', 'Nove'), ['codigo_pessoa' => 'P1', 'cpf' => '111']),
    array_merge($c('2021-01-01', null, null, '0009', 'Nove'), ['codigo_pessoa' => 'P1', 'cpf' => '111']),
];
$nove = TurnoverDashboardService::turnoverPorEmpresa($doisContratos, $ini25, $fim25)['itens'][0];
$check($nove['base'] === 2.0, '(contrato) Uma pessoa com DOIS contratos ativos conta como headcount 2 — unidade é o contrato, nunca pessoa distinta');

// ---- Tempo de Empresa ---------------------------------------------------------------------------------------
$tempo = [];
$demissaoBase = new DateTimeImmutable('2025-12-20');
foreach ([0, 90, 91, 180, 181, 365, 366, 730, 731] as $dias) {
    $tempo[] = $c($demissaoBase->modify("-{$dias} days")->format('Y-m-d'), '2025-12-20');
}
$tempo[] = $c('2024-01-01', '2025-01-10');                 // 375 dias: 1 a 2 anos (com "hoje" seria enorme)
$tempo[] = $c(null, '2025-06-01');                         // sem admissão
$tempo[] = $c('2026-01-01', '2025-12-21');                 // demissão anterior à admissão
$tempo[] = $c('not-a-date', '2025-07-01');                 // admissão inválida
$te = TurnoverDashboardService::tempoDeEmpresaDosDesligados($tempo, $ini25, $fim25);
$q = array_column($te['faixas'], 'quantidade', 'label');
$check(array_column($te['faixas'], 'label') === ['Até 90 dias', '3 a 6 meses', '6 a 12 meses', '1 a 2 anos', 'Acima de 2 anos'], '(tempo) As cinco faixas do RH, na ordem');
$check($q['Até 90 dias'] === 2 && $q['3 a 6 meses'] === 2 && $q['6 a 12 meses'] === 2 && $q['1 a 2 anos'] === 3 && $q['Acima de 2 anos'] === 1, '(tempo) Fronteiras: 0/90 | 91/180 | 181/365 | 366/730(+374) | 731 — sem sobreposição');
$check($te['total_classificados'] === 10 && $te['nao_classificados'] === 3 && array_sum($q) === 10, '(tempo) Sem admissão, demissão < admissão e data inválida NÃO são classificados (3)');
$check(TurnoverDashboardService::diasEntre('2024-01-01', '2025-01-10') === 375, '(tempo) demissao - admissao em dias corridos, sem usar a data atual');
$check(TurnoverDashboardService::diasEntre('2025-06-01', '2025-05-31') === null && TurnoverDashboardService::diasEntre(null, '2025-05-31') === null, '(tempo) Datas inválidas => null');
$check(array_sum(array_column($te['faixas'], 'percentual')) > 99.0, '(tempo) Percentuais sobre os classificados');
$paridade = RhIndicadoresService::turnoverPrecoce(RhIndicadoresService::desligamentosNoPeriodo($tempo, $ini25, $fim25));
$ateNoventaOficial = $paridade['faixas'][0]['quantidade'] + $paridade['faixas'][1]['quantidade'] + $paridade['faixas'][2]['quantidade'];
$check($ateNoventaOficial === $q['Até 90 dias'], '(tempo) "Até 90 dias" coincide com as três primeiras faixas do turnover precoce oficial (mesma régua de dias)');

// ---- Painel completo -------------------------------------------------------------------------------------------
$painel = TurnoverDashboardService::montarPainelComContratos($A, 2026, $hoje, []);
$check(array_keys($painel) === ['ano', 'ano_anterior', 'periodo', 'comparativo', 'admissoes_desligamentos', 'motivos', 'cargos', 'empresas', 'tempo_empresa', 'total_contratos'], '(painel) Seis análises + metadados');
$check($painel['periodo']['parcial'] === true && $painel['total_contratos'] === count($A), '(painel) Ano atual marcado como acumulado (parcial)');
$check(TurnoverDashboardService::montarPainelComContratos($A, 2025, $hoje)['periodo']['parcial'] === false, '(painel) Ano encerrado não é parcial');

// ---- Helpers gráficos -------------------------------------------------------------------------------------------------
$labels = ['Jan', 'Fev', 'Mar', 'Abr'];
$svg = dashboard_multi_line_chart($labels, [
    ['label' => 'Ano <A>', 'color' => '#A9B885', 'values' => [1.0, 2.0, null, 4.0]],
    ['label' => 'Ano B', 'color' => '#3B4822', 'values' => [2.0, 3.0, 5.0, null], 'partial' => [false, false, true, false]],
], '%', 1, 'Teste linhas');
$check(str_contains($svg, '<svg') && str_contains($svg, 'role="img"') && str_contains($svg, 'aria-label="Teste linhas"'), '(helper) Linha multissérie: SVG acessível');
$check(substr_count($svg, '<circle') === 6, '(helper) Ponto só para valores não nulos (6 de 8) — null não vira ponto');
$check(str_contains($svg, 'stroke-dasharray="4 4"') && str_contains($svg, 'fill="#ffffff"'), '(helper) Ponto parcial: marcador vazado e trecho tracejado');
$check(str_contains($svg, '(parcial)') && str_contains($svg, '5,0%'), '(helper) Título e rótulo de valor visíveis para o ponto parcial');
$check(!str_contains($svg, 'NAN') && !str_contains($svg, 'INF') && !str_contains($svg, '<A>'), '(helper) Sem NaN/INF e rótulos escapados (Ano <A> não vaza como HTML)');
$svgVazio = dashboard_multi_line_chart($labels, [['label' => 'X', 'color' => '#000', 'values' => [null, null, null, null]]]);
$check(!str_contains($svgVazio, '<circle') && !str_contains($svgVazio, 'NAN'), '(helper) Série toda null: nenhum ponto e nenhum erro');
$col = dashboard_grouped_columns($labels, [
    ['label' => 'Adm', 'color' => '#3B4822', 'values' => [3, 0, null, 5], 'partial' => [false, false, false, true]],
    ['label' => 'Des', 'color' => '#A9B885', 'values' => [1, 2, null, 4]],
]);
$check(substr_count($col, '<rect') === 5, '(helper) Colunas agrupadas: barra só para valor > 0 (null e 0 sem barra) — 5 barras');
$check(str_contains($col, '>0<') && str_contains($col, 'stroke-dasharray="3 2"'), '(helper) Zero legítimo recebe rótulo "0"; coluna parcial tracejada');
$leg = dashboard_chart_legend([['label' => 'A<b>', 'color' => '#111'], ['label' => 'Parcial', 'color' => '#222', 'estilo' => 'hollow']]);
$check(str_contains($leg, 'A&lt;b&gt;') && str_contains($leg, '<li') , '(helper) Legenda escapa rótulos');
$tab = dashboard_data_table('Legenda <x>', ['Mês', 'V'], [['Jan', '1'], ['Fev', '—']]);
$check(str_contains($tab, '<details') && str_contains($tab, '<caption class="sr-only">Legenda &lt;x&gt;</caption>') && str_contains($tab, 'scope="row"') && str_contains($tab, 'scope="col"'), '(helper) Tabela acessível (details + caption + scopes)');
$check(dashboard_nice_max(17.4) === 20.0 && dashboard_nice_max(0.0) === 1.0 && dashboard_nice_max(4.0) === 5.0 && dashboard_nice_max(200.0) === 200.0, '(helper) Teto do eixo Y');

// ---- Fontes ---------------------------------------------------------------------------------------------------------------
$fontes = [
    'service' => (string)file_get_contents(APP_PATH . '/services/TurnoverDashboardService.php'),
    'repository' => (string)file_get_contents(APP_PATH . '/repositories/TurnoverDashboardRepository.php'),
    'controller' => (string)file_get_contents(APP_PATH . '/controllers/AdminDashboardTurnoverController.php'),
];
foreach ($fontes as $nome => $src) {
    $check(!preg_match('/COUNT\s*\(\s*DISTINCT/i', $src) && !preg_match('/DISTINCT\s+codigo_pessoa/i', $src), "(fonte) {$nome}: nenhum COUNT(DISTINCT ...) / codigo_pessoa distinto");
    $check(!preg_match('/\b(FROM|JOIN)\s+colaboradores\b(?!_)/i', $src), "(fonte) {$nome}: não usa `colaboradores` legado como fonte");
    $check(!preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM)/i', $src) && !preg_match('/sqlsrv/i', $src), "(fonte) {$nome}: só leitura, sem SQL Server");
}
$check(str_contains($fontes['service'], 'RhIndicadoresService::taxaTurnover(') && str_contains($fontes['service'], 'RhIndicadoresService::turnoverPorDimensao(') && str_contains($fontes['service'], 'RhIndicadoresService::headcountEm('), '(fonte) O serviço reaproveita taxaTurnover/turnoverPorDimensao/headcountEm de RhIndicadoresService');
$selecaoRepo = (string)(preg_match('/SELECT admissao.*?FROM colaboradores_metadados/s', $fontes['repository'], $m) ? $m[0] : '');
$check($selecaoRepo !== '' && !preg_match('/\b(cpf|nascimento|nome|salario)/i', $selecaoRepo), '(fonte) O SELECT dos contratos não lê CPF, nascimento, nome nem salário');

echo "\nUNIT_TURNOVER_DASHBOARD_OK\n";
if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
