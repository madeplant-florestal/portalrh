<?php

/**
 * Unitário — Dashboard da Entrevista de Desligamento (DashboardEntrevistaDesligamentoService). Sem banco: agregados
 * sintéticos no formato do repository. Prova que:
 *   - filtros validados no servidor (datas, futuro, ordem, janela de 60 meses, unidade/cargo só por opção oficial);
 *   - duas populações com denominadores próprios (eNPS/satisfação/blocos sobre RESPOSTAS; cobertura sobre elegíveis);
 *   - "Sem base" = null (taxa sem geradas, eNPS/médias sem respostas, meses vazios), zero real continua 0;
 *   - competência = mês do desligamento (a série usa a chave mensal da demissão);
 *   - média por dimensão/bloco, Top 5 motivos com desempate estável, fatores de seleção múltipla;
 *   - tempo médio de permanência (fórmula documentada) descartando datas inválidas;
 *   - resumo por Unidade (identidade empresa+unidade) e por Cargo (código; vazio = Não informado; top 15);
 *   - fontes: só agregados, sem COUNT(DISTINCT), sem `colaboradores` legado, sem `respondida_em` como competência,
 *     sem Área/Gestor/Voluntário-Involuntário, sem recurso externo nem handler inline.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$S = DashboardEntrevistaDesligamentoService::class;
$hoje = new DateTimeImmutable('2026-09-19 15:30:00');
$opcoes = [
    'unidades' => [
        ['chave' => '1|01', 'codigo_empresa' => '1', 'codigo_unidade' => '01', 'nome' => 'Matriz — Madeplant'],
        ['chave' => '2|01', 'codigo_empresa' => '2', 'codigo_unidade' => '01', 'nome' => 'Matriz — Outra'],
    ],
    'cargos' => [['codigo' => '10', 'nome' => 'Operador'], ['codigo' => '20', 'nome' => 'Analista']],
];

// ---- filtros -----------------------------------------------------------------------------------------------------------
$f0 = $S::normalizarFiltros([], $hoje, $opcoes);
$check($f0['fim']->format('Y-m-d') === '2026-09-19' && $f0['inicio']->format('Y-m-d') === '2025-10-01' && $f0['unidade'] === null && $f0['codigo_cargo'] === '' && $f0['avisos'] === [], '(filtro) Padrão: últimos 12 meses (mês cheio inicial) até hoje, sem unidade/cargo');
$f1 = $S::normalizarFiltros(['inicio' => '2026-01-15', 'fim' => '2026-03-31'], $hoje, $opcoes);
$check($f1['inicio']->format('Y-m-d') === '2026-01-15' && $f1['fim']->format('Y-m-d') === '2026-03-31' && $f1['avisos'] === [], '(filtro) Período informado é respeitado (datas inclusivas)');
$f2 = $S::normalizarFiltros(['inicio' => '2026-01-01', 'fim' => '2099-12-31'], $hoje, $opcoes);
$check($f2['fim']->format('Y-m-d') === '2026-09-19' && count($f2['avisos']) === 1, '(filtro) Data final no futuro é ajustada para hoje (desligamento futuro não conta), com aviso');
foreach ([['inicio' => '2026-13-45'], ['inicio' => 'abc'], ['fim' => '2026-02-30'], ['inicio' => "2026-01-01'; DROP TABLE x;--"], ['inicio' => ['2026-01-01']], ['fim' => '19/09/2026']] as $ruim) {
    $fx = $S::normalizarFiltros($ruim, $hoje, $opcoes);
    $check($fx['fim']->format('Y-m-d') <= '2026-09-19' && $fx['inicio'] <= $fx['fim'], '(filtro) Data inválida não quebra nem vaza: ' . json_encode($ruim, JSON_UNESCAPED_UNICODE));
}
$f3 = $S::normalizarFiltros(['inicio' => '2026-08-01', 'fim' => '2026-02-01'], $hoje, $opcoes);
$check($f3['inicio'] <= $f3['fim'] && $f3['fim']->format('Y-m-d') === '2026-02-01' && $f3['inicio']->format('Y-m-d') === '2025-03-01' && count($f3['avisos']) === 1, '(filtro) Início posterior ao fim: usa os 12 meses até o fim, com aviso');
$f4 = $S::normalizarFiltros(['inicio' => '2000-01-01', 'fim' => '2026-09-19'], $hoje, $opcoes);
$check($f4['inicio']->format('Y-m-d') === '2021-10-01' && count($f4['avisos']) === 1, '(filtro) Janela limitada a 60 meses');
$f5 = $S::normalizarFiltros(['unidade' => '1|01', 'cargo' => '10'], $hoje, $opcoes);
$check($f5['unidade'] === ['codigo_empresa' => '1', 'codigo_unidade' => '01'] && $f5['unidade_chave'] === '1|01' && $f5['codigo_cargo'] === '10', '(filtro) Unidade por empresa+unidade e Cargo por código, validados contra as opções oficiais');
$f6 = $S::normalizarFiltros(['unidade' => '01', 'cargo' => 'Operador'], $hoje, $opcoes);
$check($f6['unidade'] === null && $f6['codigo_cargo'] === '', '(filtro) Só o código de unidade (sem empresa) ou o TEXTO do cargo não identificam nada');
$f7 = $S::normalizarFiltros(['unidade' => "1|01' OR '1'='1", 'cargo' => ['10']], $hoje, $opcoes);
$check($f7['unidade'] === null && $f7['codigo_cargo'] === '', '(filtro) Valor fora das opções (injeção/array) é ignorado');
$f8 = $S::normalizarFiltros(['unidade' => '2|01'], $hoje, $opcoes);
$check($f8['unidade']['codigo_empresa'] === '2' && $f5['unidade']['codigo_empresa'] === '1', '(unidade) Mesmo código de unidade em empresas diferentes são unidades diferentes');
$at = $S::atalhos($hoje);
$check(count($at) === 5 && $at[0]['inicio'] === '2026-07-01' && $at[0]['fim'] === '2026-09-19' && $at[3]['inicio'] === '2026-01-01' && $at[4]['inicio'] === '2025-01-01' && $at[4]['fim'] === '2025-12-31', '(atalhos) 3/6/12 meses, ano atual e ano anterior');

// ---- composição com agregados sintéticos ----------------------------------------------------------------------------------
$dims = DashboardEntrevistaDesligamentoRepository::dimensoes();
$check(count($dims) === 14 && !in_array('exp_remuneracao', $dims, true), '(dimensões) 14 colunas: Liderança (5) + Cultura (6) + Integração (3) — Experiência fica fora dos blocos');
$linhaEntrevista = static function (string $mes, int $geradas, int $respondidas, array $sat, array $enps, array $notas) use ($dims): array {
    $l = ['mes' => $mes, 'geradas' => $geradas, 'respondidas' => $respondidas, 'n_sat' => count($sat), 'soma_sat' => array_sum($sat),
        'n_enps' => count($enps), 'promotores' => count(array_filter($enps, static fn($n) => $n >= 9)),
        'neutros' => count(array_filter($enps, static fn($n) => $n >= 7 && $n <= 8)), 'detratores' => count(array_filter($enps, static fn($n) => $n <= 6))];
    foreach ($dims as $d) {
        $valores = $notas[$d] ?? [];
        $l['n_' . $d] = count($valores);
        $l['s_' . $d] = array_sum($valores);
    }
    return $l;
};
$todasNotas = static function (int ...$notas) use ($dims): array {
    $out = [];
    foreach ($dims as $d) {
        $out[$d] = $notas;
    }
    return $out;
};
$dados = [
    'desligamentos_mes' => [
        ['mes' => '2026-06', 'desligamentos' => 10, 'elegiveis' => 9, 'sem_entrevista' => 3, 'perm_dias' => 3650, 'perm_validos' => 8],
        ['mes' => '2026-08', 'desligamentos' => 4, 'elegiveis' => 4, 'sem_entrevista' => 0, 'perm_dias' => 730, 'perm_validos' => 2],
    ],
    'entrevistas_mes' => [
        $linhaEntrevista('2026-06', 6, 4, [8, 6, 10, 4], [10, 9, 7, 3], $todasNotas(5, 3, 4, 4)),
        $linhaEntrevista('2026-08', 3, 0, [], [], []),
    ],
    'motivos' => [['codigo' => 'remuneracao', 'quantidade' => 3], ['codigo' => 'nova_oportunidade', 'quantidade' => 3], ['codigo' => 'outro', 'quantidade' => 1], ['codigo' => 'equipe', 'quantidade' => 1], ['codigo' => 'jornada', 'quantidade' => 1], ['codigo' => 'desempenho', 'quantidade' => 1], ['codigo' => 'beneficios', 'quantidade' => 1]],
    'fatores' => [['codigo' => 'clima', 'quantidade' => 2], ['codigo' => 'remuneracao', 'quantidade' => 4], ['codigo' => 'lideranca', 'quantidade' => 4]],
    'desligamentos_unidade' => [
        ['codigo_empresa' => '1', 'codigo_unidade' => '01', 'unidade' => 'Matriz', 'empresa' => 'Madeplant', 'desligamentos' => 9],
        ['codigo_empresa' => '2', 'codigo_unidade' => '01', 'unidade' => 'Matriz', 'empresa' => 'Outra', 'desligamentos' => 5],
    ],
    'entrevistas_unidade' => [
        ['codigo_empresa' => '1', 'codigo_unidade' => '01', 'unidade' => 'Matriz', 'empresa' => 'Madeplant'] + $linhaEntrevista('', 6, 4, [8, 6, 10, 4], [10, 9, 7, 3], []),
        ['codigo_empresa' => '3', 'codigo_unidade' => '09', 'unidade' => '', 'empresa' => 'Snap', 'geradas' => 2, 'respondidas' => 0, 'n_sat' => 0, 'soma_sat' => 0, 'n_enps' => 0, 'promotores' => 0, 'neutros' => 0, 'detratores' => 0],
    ],
    'desligamentos_cargo' => [
        ['codigo_cargo' => '10', 'cargo' => 'Operador', 'desligamentos' => 8],
        ['codigo_cargo' => null, 'cargo' => null, 'desligamentos' => 2],
        ['codigo_cargo' => '', 'cargo' => '', 'desligamentos' => 1],
        ['codigo_cargo' => '20', 'cargo' => 'Analista', 'desligamentos' => 3],
    ],
    'entrevistas_cargo' => [
        ['codigo_cargo' => '10', 'cargo' => 'Operador'] + $linhaEntrevista('', 5, 3, [8, 6, 10], [10, 9, 3], []),
        ['codigo_cargo' => null, 'cargo' => null] + $linhaEntrevista('', 1, 1, [4], [7], []),
        ['codigo_cargo' => '', 'cargo' => ''] + $linhaEntrevista('', 1, 0, [], [], []),
    ],
];
$p = $S::montarPainelComDados($dados, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-09-19'));
$x = $p['executivo'];

$check($x['desligamentos'] === 14 && $x['geradas'] === 9 && $x['respondidas'] === 4, '(população A/B) Desligamentos (contratos) 14 = soma dos meses; geradas 9; respondidas 4 — populações separadas');
$check($x['taxa_resposta'] === 44.4, '(taxa) respondidas ÷ geradas = 4 ÷ 9 = 44,4% (denominador = geradas, não desligamentos)');
$check($x['satisfacao'] === ['media' => 7.0, 'n' => 4], '(satisfação) Média simples da nota 0–10 sobre as 4 respostas: (8+6+10+4)/4 = 7,0');
$check($x['enps']['n'] === 4 && $x['enps']['promotores'] === 2 && $x['enps']['neutros'] === 1 && $x['enps']['detratores'] === 1 && $x['enps']['valor'] === 25.0, '(eNPS) 2 promotores, 1 neutro, 1 detrator sobre 4 respostas = +25,0 (denominador = respostas válidas)');
$check($x['cobertura'] === ['elegiveis' => 13, 'nao_elegiveis' => 1, 'sem_entrevista' => 3, 'com_entrevista' => 10, 'percentual' => 76.9], '(cobertura) 13 elegíveis (1 Falecimento fora), 3 sem entrevista gerada → 10 cobertos = 76,9%');
// permanência: (3650 + 730) dias ÷ 10 contratos válidos ÷ 30,4375
$check($x['permanencia']['meses'] === round((4380 / 10) / 30.4375, 1) && $x['permanencia']['meses'] === 14.4 && $x['permanencia']['n'] === 10 && $x['permanencia']['invalidos'] === 4, '(permanência) (Σ dias ÷ contratos válidos) ÷ 30,4375 = 14,4 meses; 4 contratos sem datas válidas ficam de fora e contados à parte');

$lid = $p['blocos']['lideranca'];
$check($lid['media'] === 4.0 && $lid['n'] === 4 && count($lid['dimensoes']) === 5 && $lid['dimensoes'][0]['media'] === 4.0 && $lid['dimensoes'][0]['n'] === 4, '(liderança) Média do bloco = média de todas as notas (5,3,4,4) = 4,0 com 4 respostas; 5 dimensões');
$check(count($p['blocos']['cultura']['dimensoes']) === 6 && count($p['blocos']['integracao']['dimensoes']) === 3 && $p['blocos']['cultura']['dimensoes'][5]['rotulo'] === 'Ousadia', '(blocos) Cultura por valor (6) e Integração por pergunta (3)');
$semResp = $S::mediaBloco([], 'cultura');
$check($semResp['media'] === null && $semResp['n'] === 0 && $semResp['dimensoes'][0]['media'] === null, '(sem base) Bloco sem respostas: null (nunca 0,0)');

// série mensal — competência = mês da demissão
$meses = array_column($p['mensal'], null, 'mes');
$check(array_keys($meses) === ['2026-06', '2026-07', '2026-08', '2026-09'] && $p['mensal'][0]['rotulo'] === '06/26', '(mensal) Todos os meses do período, cada um pela chave mensal da demissão');
$check($meses['2026-06']['desligamentos'] === 10 && $meses['2026-06']['respondidas'] === 4 && $meses['2026-06']['taxa_resposta'] === 66.7 && $meses['2026-06']['satisfacao'] === 7.0 && $meses['2026-06']['enps'] === 25.0 && $meses['2026-06']['lideranca'] === 4.0, '(mensal) Junho: valores próprios do mês');
$check($meses['2026-07']['desligamentos'] === 0 && $meses['2026-07']['geradas'] === 0 && $meses['2026-07']['respondidas'] === 0 && $meses['2026-07']['taxa_resposta'] === null && $meses['2026-07']['enps'] === null && $meses['2026-07']['satisfacao'] === null && $meses['2026-07']['lideranca'] === null, '(sem base) Mês sem nenhuma entrevista: contagens 0 reais, mas taxa, eNPS, satisfação e liderança = null');
$check($meses['2026-08']['geradas'] === 3 && $meses['2026-08']['respondidas'] === 0 && $meses['2026-08']['taxa_resposta'] === 0.0 && $meses['2026-08']['enps'] === null, '(zero real × sem base) Agosto: 3 geradas e 0 respondidas = taxa 0,0% (zero real); eNPS sem respostas = null');
$check($meses['2026-09']['desligamentos'] === 0 && $meses['2026-09']['taxa_resposta'] === null, '(mensal) Mês corrente sem dados: sem base');
$vazio = $S::montarPainelComDados([], new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));
$check($vazio['executivo']['desligamentos'] === 0 && $vazio['executivo']['taxa_resposta'] === null && $vazio['executivo']['satisfacao']['media'] === null && $vazio['executivo']['enps']['valor'] === null && $vazio['executivo']['permanencia']['meses'] === null && $vazio['executivo']['cobertura']['percentual'] === null && $vazio['motivos']['itens'] === [] && $vazio['unidades'] === [] && $vazio['cargos']['itens'] === [], '(sem base) Sem nenhum dado: contagens 0, todas as taxas/médias/eNPS/permanência/cobertura = null (nunca 0%)');
$check(count($vazio['mensal']) === 3, '(mensal) Meses do período existem mesmo sem dados');
$check($p['periodo']['bordas_parciais'] === true && $vazio['periodo']['bordas_parciais'] === false, '(mensal) Período que não fecha meses inteiros é sinalizado');

// motivos declarados
$m = $p['motivos'];
$check($m['total'] === 11 && count($m['itens']) === 5, '(motivos) Top 5 de 7 alternativas; base = 11 respostas com motivo válido');
$check(array_column($m['itens'], 'quantidade') === [3, 3, 1, 1, 1], '(motivos) Ordenados por quantidade decrescente');
$check($m['itens'][0]['rotulo'] === 'Nova oportunidade profissional' && $m['itens'][1]['rotulo'] === 'Remuneração', '(motivos) Empate resolvido por rótulo — desempate estável e previsível');
$check($m['itens'][0]['percentual'] === 27.3 && $m['itens'][2]['rotulo'] === 'Benefícios' && $m['itens'][4]['rotulo'] === 'Jornada de trabalho', '(motivos) Percentual sobre respostas com motivo válido (3 ÷ 11 = 27,3%); empates seguintes por rótulo');
$check(!in_array('Reestruturação da empresa', array_column($m['itens'], 'rotulo'), true), '(motivos) Só o motivo DECLARADO das entrevistas (rótulos do módulo), nunca o motivo oficial do METADADOS');

// fatores
$fa = $p['fatores'];
$check($fa['base'] === 4 && array_column($fa['itens'], 'codigo') === ['lideranca', 'remuneracao', 'clima'] && array_column($fa['itens'], 'quantidade') === [4, 4, 2], '(fatores) Marcações por alternativa, ordenadas (empate por rótulo)');
$check(array_sum(array_column($fa['itens'], 'percentual')) > 100 && $fa['itens'][0]['percentual'] === 100.0 && $fa['itens'][2]['percentual'] === 50.0, '(fatores) Percentual sobre as ENTREVISTAS respondidas; seleção múltipla: a soma pode passar de 100%');

// unidades: identidade empresa+unidade, sem misturar
$u = array_column($p['unidades'], null, 'nome');
$check(count($p['unidades']) === 3 && isset($u['Matriz — Madeplant']) && isset($u['Matriz — Outra']) && isset($u['09 — Snap']), '(unidade) Mesmo código de unidade em empresas diferentes = linhas diferentes; nome com a empresa');
$check($u['Matriz — Madeplant']['desligamentos'] === 9 && $u['Matriz — Madeplant']['geradas'] === 6 && $u['Matriz — Madeplant']['respondidas'] === 4 && $u['Matriz — Madeplant']['taxa_resposta'] === 66.7 && $u['Matriz — Madeplant']['satisfacao'] === 7.0 && $u['Matriz — Madeplant']['enps'] === 25.0, '(unidade) Desligamentos, geradas, respondidas, taxa, satisfação e eNPS por unidade');
$check($u['Matriz — Outra']['desligamentos'] === 5 && $u['Matriz — Outra']['geradas'] === 0 && $u['Matriz — Outra']['taxa_resposta'] === null && $u['Matriz — Outra']['enps'] === null && $u['Matriz — Outra']['satisfacao'] === null, '(unidade) Unidade sem entrevistas: sem base (nunca 0%)');
$check($u['09 — Snap']['desligamentos'] === 0 && $u['09 — Snap']['geradas'] === 2 && $u['09 — Snap']['taxa_resposta'] === 0.0, '(unidade) Entrevista sem contrato correspondente no período continua visível; 0 de 2 respondidas = 0,0% real');
$check($S::nomeUnidade(['unidade' => 'MADEPLANT LTDA', 'empresa' => 'Madeplant Ltda', 'codigo_unidade' => '1']) === 'MADEPLANT LTDA' && $S::nomeUnidade(['unidade' => '', 'empresa' => '', 'codigo_unidade' => '07']) === '07', '(unidade) Quando a descrição da unidade repete o nome da empresa, aparece uma vez só; sem descrição cai no código');
$check(array_column($p['unidades'], 'nome') === ['Matriz — Madeplant', 'Matriz — Outra', '09 — Snap'], '(unidade) Ordenação: desligamentos desc, geradas desc, nome');

// cargos
$c = array_column($p['cargos']['itens'], null, 'nome');
$check(isset($c['Operador']) && isset($c['Analista']) && isset($c['Não informado']) && $p['cargos']['total_grupos'] === 3, '(cargo) Por código; cargo nulo e vazio viram um único "Não informado"');
$check($c['Não informado']['desligamentos'] === 3 && $c['Não informado']['geradas'] === 2 && $c['Não informado']['respondidas'] === 1 && $c['Não informado']['satisfacao'] === 4.0 && $c['Não informado']['enps'] === 0.0, '(cargo) Grupos nulo + vazio somados (desligamentos 2+1, geradas 1+1, respondidas 1+0)');
$check($c['Operador']['desligamentos'] === 8 && $c['Operador']['taxa_resposta'] === 60.0 && $c['Operador']['satisfacao'] === 8.0 && $c['Operador']['enps'] === 33.3 && $c['Analista']['geradas'] === 0 && $c['Analista']['taxa_resposta'] === null, '(cargo) Taxa, satisfação e eNPS por cargo; sem entrevistas = sem base');
$check(array_column($p['cargos']['itens'], 'nome') === ['Operador', 'Não informado', 'Analista'], '(cargo) Ordenação: desligamentos desc, depois geradas desc (empate em 3 desligamentos)');
$muitos = [];
for ($i = 1; $i <= 20; $i++) {
    $muitos[] = ['codigo_cargo' => (string)$i, 'cargo' => 'Cargo ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'desligamentos' => 21 - $i];
}
$pm = $S::montarPainelComDados(['desligamentos_cargo' => $muitos], new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));
$check(count($pm['cargos']['itens']) === 15 && $pm['cargos']['total_grupos'] === 20 && $pm['cargos']['itens'][0]['nome'] === 'Cargo 01', '(cargo) Top 15 de 20 por desligamentos');

// ---- fontes ---------------------------------------------------------------------------------------------------------------
$semComentarios = static function (string $arquivo): string {
    $saida = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $saida .= is_array($t) ? $t[1] : $t;
    }
    return $saida;
};
$repo = $semComentarios(APP_PATH . '/repositories/DashboardEntrevistaDesligamentoRepository.php');
$servico = $semComentarios(APP_PATH . '/services/DashboardEntrevistaDesligamentoService.php');
$controller = $semComentarios(APP_PATH . '/controllers/AdminDashboardEntrevistaDesligamentoController.php');
$viewBruta = (string)file_get_contents(APP_PATH . '/views/admin/dashboard-entrevista-desligamento.php');
$view = $semComentarios(APP_PATH . '/views/admin/dashboard-entrevista-desligamento.php');

$check(!preg_match('/COUNT\s*\(\s*DISTINCT/i', $repo) && !preg_match('/codigo_pessoa/i', $repo . $servico . $controller . $viewBruta), '(fonte) Unidade = contrato: sem COUNT(DISTINCT) e sem codigo_pessoa');
$check(!preg_match('/\b(FROM|JOIN)\s+colaboradores\b(?!_)/i', $repo), '(fonte) Só o espelho oficial e as tabelas do módulo — nunca `colaboradores` legado');
$check(preg_match_all('/FROM\s+(\w+)|JOIN\s+(\w+)/i', $repo, $mm) > 0 && (static function () use ($mm): bool { $t = array_values(array_unique(array_filter(array_merge($mm[1], $mm[2])))); sort($t); return $t === ['cargos', 'colaboradores_metadados', 'entrevistas_desligamento', 'entrevistas_desligamento_fatores']; })(), '(fonte) Tabelas lidas: colaboradores_metadados, entrevistas_desligamento, entrevistas_desligamento_fatores e cargos (catálogo)');
$check(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\b/', $repo), '(fonte) Repository somente leitura');
$check(!preg_match('/DATE_FORMAT\(\s*e\.respondida_em|e\.respondida_em\s+BETWEEN|e\.respondida_em\s*[<>]/i', $repo) && substr_count($repo, 'e.snap_demissao') >= 3 && str_contains($repo, "'cm.demissao'") && str_contains($repo, "' BETWEEN ? AND ?'"), '(competência) Período e séries usam demissao (contratos) e snap_demissao (entrevistas); respondida_em só define "respondida"');
$check(!preg_match('/\b(cpf|nascimento|salario|token|token_hash|snap_nome|cm\.nome|aberta_|motivo_descricao|comentario)/i', $repo), '(privacidade) Nenhuma coluna pessoal, token ou texto livre é lida');
$check(!preg_match('/volunt[aá]ri|involunt[aá]ri|MAPA_MOTIVOS|CODIGOS_VOLUNTARIO|PeopleAnalytics|TurnoverDashboard|RhIndicadores/iu', $repo . $servico . $controller . $viewBruta), '(escopo) Sem Voluntário/Involuntário e sem reaproveitar mapas de outros módulos');
$check(!preg_match('/name="(area|gestor|tipo|setor|centro_custo)/i', $viewBruta) && !preg_match('/\b(area|gestor|setor|centro_custo)\b/i', $repo . $servico . $controller), '(escopo) Sem filtro nem agregação por Área, Setor, Centro de Custo ou Gestor');
$check(!preg_match('#https?://|googleapis|gstatic|cdn\.|<script|@import#i', $view) && !preg_match('/\son[a-z]+\s*=/i', $viewBruta), '(CSP) View sem <script>, sem CDN/URL externa e sem handlers inline');
$check(str_contains($controller, "Authorization::requirePermissao('dashboard_entrevista_desligamento.visualizar')") && !str_contains($controller, 'entrevista_desligamento.resultados'), '(permissão) Exige a permissão própria do dashboard — não reaproveita `resultados`');
$seed = preg_replace('/^--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-22-dashboard-entrevista-desligamento-permissao-seed.sql'));
$check(str_contains($seed, 'INSERT IGNORE INTO permissoes') && str_contains($seed, "'dashboard_entrevista_desligamento.visualizar'") && str_contains($seed, ', 650, 1)') && !preg_match('/usuario_permissoes|CREATE|ALTER|DROP/i', $seed), '(seed) Só cadastra a permissão (650) — sem tabela e sem conceder a ninguém');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_DASHBOARD_ENTREVISTA_DESLIGAMENTO_OK\n";
