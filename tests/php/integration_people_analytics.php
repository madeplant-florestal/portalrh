<?php

/**
 * Integração — People Analytics / Tela Inicial (PeopleAnalyticsRepository + PeopleAnalyticsService
 * + AdminController::index() + permissão dashboard.visualizar).
 *
 * Prova que:
 *   - a permissão dashboard.visualizar (catálogo já existente) exige o mecanismo central de
 *     Authorization — Admin via bypass, usuário via permissão individual, NUNCA por role sozinha;
 *   - Headcount Atual conta CONTRATO (unidade oficial dos indicadores corporativos de
 *     Headcount/movimentação) — nunca COUNT(DISTINCT codigo_pessoa); uma mesma pessoa com dois
 *     contratos oficiais válidos conta duas vezes, reaproveitando
 *     RhIndicadoresService::headcountEm() (admissao<=data e demissao IS NULL OU demissao>=data);
 *   - Filtro de Empresa e de Setor isolam corretamente colaboradores_metadados;
 *   - Ausência de codigo_setor cai em "Setor não informado", nunca inferido de outra fonte;
 *   - Admissões no período contam CONTRATO/evento (RhIndicadoresService::admissoesNoPeriodo()) —
 *     duas admissões da mesma pessoa no período contam duas vezes, nunca deduplicadas;
 *   - Desligamentos no período são por EVENTO/contrato, não deduplicados por pessoa;
 *   - Turnover Geral/Voluntário/Involuntário usam headcount por CONTRATO, com a âncora inicial
 *     `início do período - 1 dia`, mesma convenção de Indicadores de RH;
 *   - Colaboradores por Setor conta CONTRATOS ativos (pela flag `ativo`), não pessoas distintas;
 *   - Turnover Geral reaproveita RhIndicadoresService::taxaTurnover(), sem reimplementar a fórmula;
 *   - Turnover por Faixa Etária ignora desligamentos sem nascimento, e vira null (não 0 falso)
 *     quando nenhum desligamento do período é classificável;
 *   - Integrações Realizadas nunca confunde pesquisa respondida com integração marcada como
 *     realizada (são fontes/condições diferentes);
 *   - NPS Integração usa exclusivamente pesquisas_integracao, ignora respostas fora do período,
 *     e amostra zero nunca vira NPS 0 falso;
 *   - Avaliação de Experiência (Realizadas x Pendentes) usa avaliacao_90_dias real, ancorado ao
 *     vencimento dos 90 dias — não a data de admissão nem updated_at;
 *   - dados legados de `colaboradores` (ativo/data_admissao/data_demissao) NUNCA contaminam os
 *     indicadores oficiais (Headcount/Admissões/Desligamentos/Turnover vêm só de
 *     colaboradores_metadados);
 *   - ausência de dados não quebra o dashboard (filtro sem nenhuma correspondência produz zeros/
 *     nulls coerentes, não exceção);
 *   - Turnover Voluntário (códigos 003/006) e Involuntário (001/002/007) usam a MESMA base de
 *     headcount do Turnover Geral (RhIndicadoresService::taxaTurnover), variando só o numerador;
 *     códigos "outros" (005/008/016/020) continuam em Desligamentos/Turnover Geral mas não entram
 *     em nenhum dos dois numeradores — por isso Geral ≠ Voluntário + Involuntário;
 *   - usuário sem dashboard.visualizar não vê o item Dashboard na sidebar (fonte, não só 403);
 *   - Supervisor sem a permissão individual não recebe acesso simplesmente por ser Supervisor
 *     (mesma disciplina de "role/flag sozinha não basta" já provada para RH);
 *   - Authorization::primeiraRotaAcessivel() manda TODO usuário autenticado para a Central do Portal (/admin, aberta a
 *     qualquer sessão; o Dashboard People Analytics é /admin/dashboard e mantém o gate dashboard.visualizar), nunca para uma
 *     rota que devolva 403 e sem loop de redirect;
 *   - Empresa selecionada e RESOLVIDA no catálogo local filtra Vagas normalmente; Empresa
 *     selecionada e SEM correspondência local nunca degrada silenciosamente para o total geral.
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

// Sufixo curto: colaboradores_metadados.codigo_empresa/codigo_setor/codigo_pessoa são varchar(20).
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [
    'usuarios' => [], 'colaboradores' => [], 'pesquisas_integracao' => [],
    'solicitacoes' => [], 'setores' => [], 'empresas' => [],
    'metadados_identificadores' => [],
];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$empFixture = 'ZZE' . $suffix;
$setFixture = 'ZZS' . $suffix;
$emp2Fixture = 'ZZF' . $suffix;

try {
    // ---- 0) Permissão dashboard.visualizar já existe no catálogo (seed de sprint anterior) -----
    $permStmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $permStmt->execute(['dashboard.visualizar']);
    $permissaoId = (int)($permStmt->fetchColumn() ?: 0);
    $check($permissaoId > 0, 'Permissão dashboard.visualizar existe no catálogo (não foi inventada nesta sprint — já seedada em 2026-09-16-permissoes-cobertura-portal-seed.sql)');

    $adminId = User::create('ZZPA Admin', 'admin.zzpa.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $viewerComId = User::create('ZZPA Viewer Com Permissao', 'viewer.com.zzpa.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($viewerComId, true);
    $criados['usuarios'][] = $viewerComId;
    if ($permissaoId > 0) {
        Authorization::sincronizar($viewerComId, [$permissaoId]);
    }

    $viewerSemId = User::create('ZZPA Viewer Sem Permissao', 'viewer.sem.zzpa.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($viewerSemId, true);
    $criados['usuarios'][] = $viewerSemId;

    $rhSemId = User::create('ZZPA RH Sem Permissao', 'rh.sem.zzpa.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($rhSemId, true);
    $criados['usuarios'][] = $rhSemId;

    $check(Authorization::usuarioTemPermissao($adminId, 'dashboard.visualizar') === true, '(1) Admin acessa via bypass central, sem nenhuma permissão individual concedida');
    $check(Authorization::usuarioTemPermissao($viewerComId, 'dashboard.visualizar') === true, '(2) Usuário com a permissão individual acessa');
    $check(Authorization::usuarioTemPermissao($viewerSemId, 'dashboard.visualizar') === false, '(3) Usuário viewer sem a permissão NÃO acessa — usuário sem permissão recebe 403 no backend');
    $check(Authorization::usuarioTemPermissao($rhSemId, 'dashboard.visualizar') === false, '(3b) RH sem a permissão individual NÃO acessa — role sozinha não basta');

    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $reflexao = new ReflectionMethod($classe, $metodo);
        $arquivo = new SplFileObject($reflexao->getFileName());
        $arquivo->seek($reflexao->getStartLine() - 1);
        $corpo = '';
        while ($arquivo->key() < $reflexao->getEndLine()) {
            $corpo .= $arquivo->current();
            $arquivo->next();
        }
        return $corpo;
    };
    $corpoIndex = $corpoDoMetodo(AdminController::class, 'index');
    $check(str_contains($corpoIndex, "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(4a) index() exige sessão autenticada de área administrativa');
    $check(str_contains($corpoIndex, "Authorization::requirePermissao('dashboard.visualizar')"), '(4b) index() exige dashboard.visualizar no backend (403 real, não só ocultação de menu) — Authorization::requirePermissao() chama exit() no 403, por isso este teste usa leitura de fonte em vez de invocar index() sem a permissão');

    // ---- 1) Fixtures de colaboradores_metadados (Headcount/Admissões/Desligamentos/Turnover) ----
    $mkMetadados = static function (
        string $codigoPessoa,
        string $sufixoContrato,
        ?DateTimeImmutable $admissao,
        ?DateTimeImmutable $demissao,
        bool $ativo,
        string $codigoEmpresa,
        ?string $codigoSetor,
        ?DateTimeImmutable $nascimento = null
    ) use ($pdo, &$criados, $suffix): void {
        $identificador = 'ZZPA_' . $suffix . '_' . $codigoPessoa . '_' . $sufixoContrato;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                nome, nascimento, admissao, demissao, codigo_setor, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $identificador, $codigoEmpresa, 'ZZU' . $suffix, 'ZZC' . $codigoPessoa . $sufixoContrato,
            $codigoPessoa, 'ZZPA Fixture ' . $codigoPessoa, $nascimento?->format('Y-m-d'),
            $admissao?->format('Y-m-d'), $demissao?->format('Y-m-d'), $codigoSetor,
            $ativo ? 1 : 0, 'zzpa-teste',
        ]);
        $criados['metadados_identificadores'][] = $identificador;
    };

    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-30 days');
    $fim = $hoje;

    $p1 = 'ZZP' . $suffix . '1';
    $p2 = 'ZZP' . $suffix . '2';
    $p3 = 'ZZP' . $suffix . '3';
    $p4 = 'ZZP' . $suffix . '4';
    $p5 = 'ZZP' . $suffix . '5';
    $p6 = 'ZZP' . $suffix . '6';
    $p7 = 'ZZP' . $suffix . '7';

    // P1: ativo, Empresa+Setor fixture, DOIS contratos (mesma pessoa) — os dois contam
    // INDIVIDUALMENTE no Headcount (unidade oficial é o CONTRATO, nunca codigo_pessoa).
    $mkMetadados($p1, 'A', $hoje->modify('-800 days'), null, true, $empFixture, $setFixture);
    $mkMetadados($p1, 'B', $hoje->modify('-600 days'), null, true, $empFixture, $setFixture);

    // P2: já desligado ANTES do período (demissao < inicio) — some do headcount hoje e do início
    // do período; não deve aparecer em Desligamentos deste período.
    $mkMetadados($p2, 'A', $hoje->modify('-900 days'), $hoje->modify('-200 days'), false, $empFixture, $setFixture);

    // P3: ativo, SEM codigo_setor — nunca inferido, cai em "Setor não informado".
    $mkMetadados($p3, 'A', $hoje->modify('-700 days'), null, true, $empFixture, null);

    // P4: admissão DENTRO do período, em DOIS contratos (readmissão rápida) — as duas admissões
    // contam INDIVIDUALMENTE (unidade oficial é o contrato/evento, nunca deduplicado por pessoa).
    $mkMetadados($p4, 'A', $hoje->modify('-10 days'), null, true, $empFixture, $setFixture);
    $mkMetadados($p4, 'B', $hoje->modify('-8 days'), null, true, $empFixture, $setFixture);

    // P5: DOIS contratos desligados dentro do período (mesma pessoa) — Desligamentos são por
    // evento/contrato, NÃO deduplicados por pessoa. nascimento fixo -> idade 30 nas duas rescisões
    // (mesma faixa etária), prova de Turnover por Faixa Etária.
    $nascimentoP5 = $hoje->modify('-30 years')->modify('-2 months');
    $mkMetadados($p5, 'A', $hoje->modify('-400 days'), $hoje->modify('-5 days'), false, $empFixture, $setFixture, $nascimentoP5);
    $mkMetadados($p5, 'B', $hoje->modify('-300 days'), $hoje->modify('-3 days'), false, $empFixture, $setFixture, $nascimentoP5);

    // P6: desligado dentro do período, mas SEM nascimento — conta como desligamento, mas fica de
    // fora da classificação por faixa etária (nunca classificado às cegas).
    $mkMetadados($p6, 'A', $hoje->modify('-400 days'), $hoje->modify('-7 days'), false, $empFixture, $setFixture, null);

    // P7 (empresa isolada emp2Fixture): ÚNICO desligamento do período, também sem nascimento —
    // usado para provar que faixa_etaria vira null (nenhum desligamento classificável), não uma
    // lista de faixas zeradas.
    $mkMetadados($p7, 'A', $hoje->modify('-400 days'), $hoje->modify('-9 days'), false, $emp2Fixture, null, null);

    $service = new PeopleAnalyticsService();

    // ---- 2) Headcount: CONTRATO é a unidade oficial, não pessoa distinta ------------------------
    $painelEmp = $service->montarPainel(['codigo_empresa' => $empFixture], $inicio, $fim);
    $check($painelEmp['headcount']['atual'] === 5, '(A) Headcount Atual = 5 contratos (P1: 2 + P3: 1 + P4: 2, cada contrato contado individualmente) — P2/P5/P6 excluídos por já terem demissao <= hoje');

    // ---- 3) Filtro de Setor isola corretamente (independente do filtro de Empresa) -------------
    $painelSetor = $service->montarPainel(['codigo_setor' => $setFixture], $inicio, $fim);
    $check($painelSetor['headcount']['atual'] === 4, '(B/C) Filtro de Setor conta 4 contratos ativos hoje (P1: 2 + P4: 2) — P2/P5/P6 (mesmo Setor) já desligados, P3 fica de fora por não ter Setor');

    // ---- 4) "Setor não informado" nunca é inferido, aparece separado; distribuição por Setor conta
    //         CONTRATOS ativos (flag `ativo`), não pessoas distintas ----------------------------
    $porSetor = $painelEmp['colaboradores_por_setor'];
    $itemSetorFixture = null;
    $itemSemSetor = null;
    foreach ($porSetor as $item) {
        if ($item['label'] === $setFixture) {
            $itemSetorFixture = $item;
        }
        if ($item['label'] === 'Setor não informado') {
            $itemSemSetor = $item;
        }
    }
    $check($itemSetorFixture !== null && $itemSetorFixture['quantidade'] === 4, '(D/7) Colaboradores por Setor: setor fixture soma 4 CONTRATOS ativos (P1: 2 + P4: 2) — não COUNT(DISTINCT codigo_pessoa)');
    $check($itemSemSetor !== null && $itemSemSetor['quantidade'] === 1, '(19) Colaboradores por Setor: "Setor não informado" = 1 (P3) — nunca redistribuído nem inferido');

    // ---- 5) Admissões no período contam CONTRATO/evento, sem dedup por pessoa -------------------
    $check($painelEmp['admissoes']['periodo'] === 2, '(E) Admissões no período = 2 — as DUAS admissões de P4 (readmissão rápida) contam individualmente, cada contrato admitido é uma admissão oficial');

    // ---- 6) Desligamentos no período são por evento, NÃO deduplicados por pessoa (regra mantida) -
    $check($painelEmp['desligamentos']['periodo'] === 3, '(F) Desligamentos no período = 3 eventos — P5 tem 2 contratos desligados no período (não deduplicados) + P6 = 3, P2 fica de fora (desligado antes do período)');

    // ---- 7) Turnover Geral reaproveita a NOVA fórmula oficial (2026-09):
    //         RhIndicadoresService::taxaTurnoverPeriodo()/ativosNoPeriodo() — desligados do
    //         período / ativos do período (nunca mais a média de headcount) -------------------
    $headcountInicio = $painelEmp['turnover']['headcount_inicio'];
    $headcountFim = $painelEmp['turnover']['headcount_fim'];
    $ativosPeriodoEmp = $painelEmp['turnover']['ativos_periodo'];
    $esperadoTurnover = RhIndicadoresService::taxaTurnoverPeriodo($painelEmp['desligamentos']['periodo'], $ativosPeriodoEmp);
    $check($painelEmp['turnover']['geral_percentual'] === $esperadoTurnover, '(G) Turnover Geral bate exatamente com RhIndicadoresService::taxaTurnoverPeriodo()/ativosNoPeriodo() — fórmula não duplicada');
    $check($headcountFim === $painelEmp['headcount']['atual'], '(G-correlato) headcount_fim do Turnover é EXATAMENTE o mesmo valor do card Headcount Atual — mesma função, mesma data, nenhuma definição paralela dentro do próprio People Analytics');
    $check($headcountInicio === 6, '(4/G-correlato) Headcount no início do período = 6 contratos (P1: 2, P3: 1, P5: 2, P6: 1, todos ativos em início-1 dia) — P2 já tinha saído antes disso, P4 ainda não tinha entrado');
    $check($headcountFim === 5, '(G-correlato) Headcount no fim do período = 5 contratos (P1: 2, P3: 1, P4: 2) — P5 e P6 já tinham saído até hoje');
    $check($ativosPeriodoEmp === 8, '(G-nova-formula) Ativos do período = 8 contratos (P1: 2, P3: 1, P4: 2, P5: 2, P6: 1 — todos sobrepõem o período; P6 tem demissao 7 dias atrás, dentro do período de 30 dias, também sobrepõe) — só P2 fica de fora (demissao 200 dias atrás, antes do início do período)');

    // ---- 7b) Fixture dedicada — uma única pessoa com DOIS contratos, isolada, prova direta e
    //          inequívoca de que o Headcount NUNCA usa COUNT(DISTINCT codigo_pessoa) -------------
    $empDuploFixture = 'ZZD' . $suffix;
    $pDuplo = 'ZZP' . $suffix . 'DUP';
    $mkMetadados($pDuplo, 'A', $hoje->modify('-500 days'), null, true, $empDuploFixture, null);
    $mkMetadados($pDuplo, 'B', $hoje->modify('-15 days'), null, true, $empDuploFixture, null);
    $painelDuplo = $service->montarPainel(['codigo_empresa' => $empDuploFixture], $inicio, $fim);
    $check($painelDuplo['headcount']['atual'] === 2, '(4/A) Fixture dedicada: 1 pessoa com 2 contratos ativos isolada em empresa própria -> Headcount = 2, NUNCA 1 — prova direta contra regressão para COUNT(DISTINCT codigo_pessoa)');
    $check($painelDuplo['admissoes']['periodo'] === 1, '(4/E) Mesma pessoa: só 1 dos 2 contratos foi admitido dentro do período -> Admissões = 1 (contrato B, admitido há 15 dias) — o outro (contrato A, há 500 dias) fica de fora por data, não por dedup de pessoa');

    // ---- 7c) Headcount/Turnover/Desligamentos por Empresa: mesma unidade (CONTRATO), mesma base
    //          de headcount do card superior, mesma fórmula de Turnover (RhIndicadoresService),
    //          nomes resolvidos do próprio METADADOS (nunca inventados) --------------------------
    $setorMultiFixture = 'ZZM' . $suffix;
    $empMA = 'ZZMA' . $suffix;
    $empMB = 'ZZMB' . $suffix;
    $pMA1 = 'ZZP' . $suffix . 'MA1';
    $pMA1b = 'ZZP' . $suffix . 'MA1B';
    $pMA2 = 'ZZP' . $suffix . 'MA2';
    $pMB1 = 'ZZP' . $suffix . 'MB1';

    // empMA contrato 1: ativo hoje, com o nome oficial da Empresa preenchido no METADADOS (coluna
    // `empresa`) — prova que o rótulo do gráfico vem do próprio dado, nunca inventado.
    $identificadorMA1 = 'ZZPA_' . $suffix . '_' . $pMA1 . '_A';
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
            nome, empresa, admissao, codigo_setor, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $identificadorMA1, $empMA, 'ZZU' . $suffix, 'ZZC' . $pMA1 . 'A', $pMA1,
        'ZZPA Fixture ' . $pMA1, 'Empresa Fixture ' . $suffix, $hoje->modify('-500 days')->format('Y-m-d'),
        $setorMultiFixture, 'zzpa-teste',
    ]);
    $criados['metadados_identificadores'][] = $identificadorMA1;
    // empMA contrato 2: ativo hoje, SEM nome de Empresa no METADADOS (label ainda cai no nome já
    // resolvido para o código, vindo do outro contrato do mesmo codigo_empresa).
    $mkMetadados($pMA1b, 'A', $hoje->modify('-450 days'), null, true, $empMA, $setorMultiFixture);
    // empMA contrato 3: desligado DENTRO do período (único desligamento de empMA).
    $mkMetadados($pMA2, 'A', $hoje->modify('-400 days'), $hoje->modify('-10 days'), false, $empMA, $setorMultiFixture);
    // empMB: 1 contrato ativo hoje, sem nome de Empresa preenchido em nenhum contrato — label deve
    // cair no próprio codigo_empresa (nunca um nome inventado).
    $mkMetadados($pMB1, 'A', $hoje->modify('-500 days'), null, true, $empMB, $setorMultiFixture);

    $painelMulti = $service->montarPainel(['codigo_setor' => $setorMultiFixture], $inicio, $fim);

    $check(count($painelMulti['headcount_por_empresa']) === 2, '(21) Headcount por Empresa: 2 empresas encontradas (empMA + empMB), isoladas pelo filtro de Setor fixture');
    $somaHeadcountPorEmpresa = array_sum(array_column($painelMulti['headcount_por_empresa'], 'quantidade'));
    $check($somaHeadcountPorEmpresa === $painelMulti['headcount']['atual'], '(21) Headcount por Empresa: soma das barras é EXATAMENTE igual ao card Headcount Atual — mesma unidade (contrato) e mesma data de referência ($fim), nunca RhIndicadoresService::distribuicao() (que usaria sempre "hoje")');

    $itemEmpMA = $painelMulti['headcount_por_empresa'][0] ?? null;
    $itemEmpMB = $painelMulti['headcount_por_empresa'][1] ?? null;
    $check($itemEmpMA !== null && $itemEmpMA['codigo'] === $empMA && $itemEmpMA['quantidade'] === 2, '(21) Headcount por Empresa: empMA tem 2 contratos ativos hoje (pMA1 + pMA1b; pMA2 já desligado) e vem primeiro (ordenado por quantidade desc)');
    $check($itemEmpMA !== null && $itemEmpMA['label'] === 'Empresa Fixture ' . $suffix, '(21) Headcount por Empresa: rótulo de empMA vem da coluna `empresa` do METADADOS (nunca um nome inventado) — reaproveitado mesmo vindo de um contrato diferente do mesmo codigo_empresa');
    $check($itemEmpMB !== null && $itemEmpMB['codigo'] === $empMB && $itemEmpMB['quantidade'] === 1, '(21) Headcount por Empresa: empMB tem 1 contrato ativo, vem depois de empMA');
    $check($itemEmpMB !== null && $itemEmpMB['label'] === $empMB, '(21) Headcount por Empresa: empMB nunca teve nome de Empresa preenchido em nenhum contrato — rótulo cai no próprio código, nunca um nome inventado');

    $contratosEmpMA = [
        ['admissao' => $hoje->modify('-500 days')->format('Y-m-d'), 'demissao' => null],
        ['admissao' => $hoje->modify('-450 days')->format('Y-m-d'), 'demissao' => null],
        ['admissao' => $hoje->modify('-400 days')->format('Y-m-d'), 'demissao' => $hoje->modify('-10 days')->format('Y-m-d')],
    ];
    $ativosPeriodoEmpMA = count(RhIndicadoresService::ativosNoPeriodo($contratosEmpMA, $inicio, $fim));
    $esperadoTurnoverEmpMA = RhIndicadoresService::taxaTurnoverPeriodo(1, $ativosPeriodoEmpMA);
    $porEmpresaTurnover = [];
    foreach ($painelMulti['turnover']['por_empresa'] as $linha) {
        $porEmpresaTurnover[$linha['codigo']] = $linha;
    }
    $check(($porEmpresaTurnover[$empMA]['desligamentos'] ?? null) === 1, '(22) Desligamentos por Empresa: empMA tem 1 evento (pMA2) — mesma contagem de RhIndicadoresService::turnoverPorDimensaoPeriodo(), sem fórmula paralela');
    $check(($porEmpresaTurnover[$empMB]['desligamentos'] ?? null) === 0, '(22) Desligamentos por Empresa: empMB tem 0 eventos no período');
    $check($ativosPeriodoEmpMA === 3, '(23-base) empMA: os 3 contratos sobrepõem o período (pMA2 tem demissao 10 dias atrás, dentro dos 30 dias do período) — ativos_periodo = 3');
    $check(
        isset($porEmpresaTurnover[$empMA]) && $porEmpresaTurnover[$empMA]['taxa'] === $esperadoTurnoverEmpMA,
        '(23) Turnover por Empresa: empMA bate exatamente com RhIndicadoresService::taxaTurnoverPeriodo()/ativosNoPeriodo() (nova fórmula) — mesma fórmula do Turnover Geral, sem reimplementação'
    );
    $check(
        array_keys($porEmpresaTurnover) === [$empMA, $empMB] || array_values(array_map(static fn(array $l) => $l['codigo'], $painelMulti['turnover']['por_empresa'])) === [$empMA, $empMB],
        '(24) Turnover por Empresa segue a MESMA ordem de Headcount por Empresa (empMA antes de empMB) — permite comparar as duas barras lado a lado sem reordenar mentalmente'
    );

    $desligamentosPorEmpresaCodigos = array_map(static fn(array $l) => $l['codigo'], $painelMulti['desligamentos_por_empresa']);
    $check($desligamentosPorEmpresaCodigos === [$empMA, $empMB], '(25) Desligamentos por Empresa: ranking próprio por número de eventos desc (empMA com 1 evento antes de empMB com 0)');

    // Filtro de Empresa cascateando para o novo bloco (não só para o card Headcount Atual).
    $painelSoEmpMA = $service->montarPainel(['codigo_empresa' => $empMA], $inicio, $fim);
    $check(
        count($painelSoEmpMA['headcount_por_empresa']) === 1 && $painelSoEmpMA['headcount_por_empresa'][0]['codigo'] === $empMA,
        '(26) Filtro de Empresa isola Headcount por Empresa para só a Empresa selecionada — mesmo comportamento coerente já exigido para os demais indicadores'
    );

    // ---- 8) Turnover por Faixa Etária: ignora sem nascimento; null quando nada é classificável --
    $faixaEmp = $painelEmp['turnover']['faixa_etaria'];
    $check($faixaEmp !== null && $faixaEmp['amostra'] === 2, '(H) Faixa etária: amostra = 2 (as 2 rescisões de P5, que tem nascimento) — P6 (sem nascimento) fica fora da classificação, mas não do total de desligamentos');
    $faixa30 = null;
    foreach ($faixaEmp['faixas'] as $faixa) {
        if ($faixa['label'] === '26 a 35 anos') {
            $faixa30 = $faixa;
        }
    }
    $check($faixa30 !== null && $faixa30['quantidade'] === 2, '(H-correlato) As 2 rescisões de P5 (idade 30) caem na faixa "26 a 35 anos"');

    $painelEmp2 = $service->montarPainel(['codigo_empresa' => $emp2Fixture], $inicio, $fim);
    $check($painelEmp2['desligamentos']['periodo'] === 1, 'Fixture isolada emp2: 1 desligamento no período (P7)');
    $check($painelEmp2['turnover']['faixa_etaria'] === null, '(H-null) Faixa etária vira null quando NENHUM desligamento do período é classificável (P7 sem nascimento) — nunca uma lista de faixas zeradas simulando 0%');

    // ---- 9) Ausência de dados não quebra o dashboard (filtro sem nenhuma correspondência) -------
    $painelVazio = $service->montarPainel(['codigo_empresa' => 'ZZ-CODIGO-INEXISTENTE-' . $suffix], $inicio, $fim);
    $check($painelVazio['headcount']['atual'] === 0, '(I) Filtro sem correspondência: Headcount = 0, sem exceção');
    $check($painelVazio['admissoes']['periodo'] === 0, '(I) Filtro sem correspondência: Admissões = 0, sem exceção');
    $check($painelVazio['desligamentos']['periodo'] === 0, '(I) Filtro sem correspondência: Desligamentos = 0, sem exceção');
    $check($painelVazio['turnover']['faixa_etaria'] === null, '(I) Filtro sem correspondência: faixa etária null, não lista vazia quebrada');
    $check($painelVazio['colaboradores_por_setor'] === [], '(I) Filtro sem correspondência: Colaboradores por Setor = [], sem exceção');

    // ---- 10) Contaminação: colaboradores (legado local) NUNCA vaza para indicadores oficiais ----
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $check($cargoId > 0, 'Fixture: existe ao menos um cargo no catálogo local');

    $mkColaborador = static function (string $sufixoNome, array $overrides = []) use ($pdo, &$criados, $suffix, $cargoId): int {
        $dados = array_merge([
            'nome' => 'ZZPA Legado ' . $sufixoNome,
            'slug' => 'zzpa-legado-' . strtolower($sufixoNome) . '-' . $suffix,
            'cargo_id' => $cargoId,
            'integracao_status' => 'pendente',
            'integracao_data' => null,
            'data_admissao' => null,
            'data_demissao' => null,
            'ativo' => 1,
        ], $overrides);
        $stmt = $pdo->prepare(
            'INSERT INTO colaboradores (nome, slug, cargo_id, integracao_status, integracao_data, data_admissao, data_demissao, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dados['nome'], $dados['slug'], $dados['cargo_id'], $dados['integracao_status'],
            $dados['integracao_data'], $dados['data_admissao'], $dados['data_demissao'], $dados['ativo'],
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['colaboradores'][] = $id;
        return $id;
    };

    // Colaborador local "puro" (sem metadados_id), com admissão/demissão/ativo divergentes dos
    // fixtures oficiais acima e SEM nenhum vínculo com colaboradores_metadados — se algum
    // indicador oficial ler esta tabela por engano, os totais acima mudariam.
    $mkColaborador('Contaminacao', [
        'data_admissao' => $hoje->modify('-5 days')->format('Y-m-d'),
        'data_demissao' => null,
        'ativo' => 1,
    ]);

    $painelPosContaminacao = $service->montarPainel(['codigo_empresa' => $empFixture], $inicio, $fim);
    $check($painelPosContaminacao['headcount']['atual'] === $painelEmp['headcount']['atual'], '(J) Headcount Atual inalterado após inserir colaborador local sem vínculo com METADADOS — colaboradores nunca contamina indicadores oficiais');
    $check($painelPosContaminacao['admissoes']['periodo'] === $painelEmp['admissoes']['periodo'], '(J) Admissões no período inalteradas — colaboradores.data_admissao não é lida para este indicador');

    // ---- 11) Integrações Realizadas: nunca confunde pesquisa respondida com integração realizada
    $baseIntegracoes = $painelEmp['integracao']['realizadas_periodo'];
    $baseNps = $painelEmp['nps_integracao'];

    $colRealizado1 = $mkColaborador('IntegRealizada1', [
        'integracao_status' => 'realizada',
        'integracao_data' => $hoje->modify('-6 days')->format('Y-m-d'),
    ]);
    $colRealizado2 = $mkColaborador('IntegRealizada2', [
        'integracao_status' => 'realizada',
        'integracao_data' => $hoje->modify('-4 days')->format('Y-m-d'),
    ]);
    // Pesquisa RESPONDIDA, mas integração NÃO marcada como realizada — não pode contar como
    // "Integração Realizada" (são condições/fontes diferentes: status manual x pesquisa concluída).
    $colRespondidaSemRealizada = $mkColaborador('PesquisaSemIntegracaoRealizada', [
        'integracao_status' => 'pendente',
    ]);

    $mkPesquisa = static function (int $colaboradorId, ?int $nota, ?DateTimeImmutable $respondidaEm, string $marcador) use ($pdo, &$criados, $hoje): void {
        $pdo->prepare(
            'INSERT INTO pesquisas_integracao (colaborador_id, integracao_data_relacionada, token_hash, nota_nps, respondida_em)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $colaboradorId, $hoje->modify('-6 days')->format('Y-m-d'), hash('sha256', $marcador),
            $nota, $respondidaEm?->format('Y-m-d H:i:s'),
        ]);
    };

    $mkPesquisa($colRealizado1, 10, $hoje->modify('-6 days'), 'zzpa-nps-' . $suffix . '-1'); // Promotor
    $mkPesquisa($colRealizado2, 7, $hoje->modify('-4 days'), 'zzpa-nps-' . $suffix . '-2');  // Neutro
    $mkPesquisa($colRespondidaSemRealizada, 8, $hoje->modify('-2 days'), 'zzpa-nps-' . $suffix . '-3'); // Neutro, respondida, integração NÃO realizada
    // Fora do período (não deve contar em nenhum delta).
    $colForaPeriodo = $mkColaborador('ForaPeriodo', ['integracao_status' => 'realizada', 'integracao_data' => $hoje->modify('-90 days')->format('Y-m-d')]);
    $mkPesquisa($colForaPeriodo, 3, $hoje->modify('-90 days'), 'zzpa-nps-' . $suffix . '-4');
    // Pesquisa NÃO respondida — nunca entra na agregação de NPS.
    $colNaoRespondida = $mkColaborador('NaoRespondida');
    $mkPesquisa($colNaoRespondida, null, null, 'zzpa-nps-' . $suffix . '-5');

    $painelIntegracao = $service->montarPainel(['codigo_empresa' => $empFixture], $inicio, $fim);
    $check(($painelIntegracao['integracao']['realizadas_periodo'] - $baseIntegracoes) === 2, '(15) Integrações Realizadas: delta +2 (colRealizado1+2) — a pesquisa respondida sem status "realizada" (colRespondidaSemRealizada) NÃO conta, prova que pesquisa-respondida != integração-realizada');

    $npsDepois = $painelIntegracao['nps_integracao'];
    $deltaAmostra = $npsDepois['amostra'] - ($baseNps['amostra'] ?? 0);
    $check($deltaAmostra === 3, '(16) NPS Integração: delta de amostra = 3 (as 3 respostas dentro do período) — a fora do período e a não respondida ficam de fora');
    $check($npsDepois['amostra'] > 0 && $npsDepois['nps'] !== null, '(16-correlato) Com amostra > 0, NPS nunca fica null');

    // ---- 12) NPS sem amostra não retorna falso zero ---------------------------------------------
    $painelSemNps = $service->montarPainel(['codigo_empresa' => 'ZZ-SEM-NPS-' . $suffix], $hoje->modify('-1 days'), $hoje->modify('-1 days'));
    // Este filtro de empresa não tem colaboradores_metadados, mas o cálculo de NPS é global por
    // período — usa uma janela de 1 dia (ontem) sem nenhuma pesquisa fixture para garantir amostra 0.
    if ($painelSemNps['nps_integracao']['amostra'] === 0) {
        $check($painelSemNps['nps_integracao']['nps'] === null, '(16-null) NPS sem nenhuma resposta no período é null, nunca 0 falso');
    } else {
        echo "  [aviso] janela de 1 dia (ontem) teve amostra > 0 em DEV (dado residual de outra suíte) — verificação de NPS nulo pulada nesta execução\n";
    }

    // ---- 13) Avaliação de Experiência: Realizadas ancoradas ao vencimento dos 90 dias; Pendentes
    //          são retrato de agora ---------------------------------------------------------------
    $empCodigoResolvido = 'ZZR' . $suffix;
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo, codigo_empresa) VALUES (?, ?, 1, ?)')
        ->execute(['ZZPA Empresa ' . $suffix, 'zzpa-empresa-' . $suffix, $empCodigoResolvido]);
    $empresaLocalId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaLocalId;
    $pdo->prepare('INSERT INTO setores (nome, slug, empresa_id, ativo) VALUES (?, ?, ?, 1)')
        ->execute(['ZZPA Setor ' . $suffix, 'zzpa-setor-' . $suffix, $empresaLocalId]);
    $setorLocalId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorLocalId;

    $stagesKanban = [];
    foreach ($pdo->query('SELECT id, slug FROM solicitacao_vaga_stages') as $row) {
        $stagesKanban[(string)$row['slug']] = (int)$row['id'];
    }
    $stageAprovada = $stagesKanban['aprovada'] ?? 0;
    $check($stageAprovada > 0, 'Fixture: etapa "aprovada" existe em solicitacao_vaga_stages');

    $mkSolicitacaoAvaliacao = static function (?DateTimeImmutable $dataAdmissao, ?string $avaliacao) use ($pdo, $cargoId, $adminId, $setorLocalId, $stageAprovada, &$criados): int {
        $stmt = $pdo->prepare(
            'INSERT INTO solicitacoes_vaga (
                setor_id, quantidade_vagas, cargo_id, solicitante_usuario_id, tipo_vaga,
                tipo_contratacao, salario_previsto, centro_custo_id, previsto_orcamento, jornada_trabalho,
                escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade,
                data_prevista_inicio, urgencia, status_fluxo, situacao_kanban_id, data_admissao, avaliacao_90_dias
            ) VALUES (?, 1, ?, ?, \'nova_posicao\', \'clt\', 3000, NULL, 0, \'8h/dia\',
                \'medio\', \'fixture zzpa\', \'operacional\', CURDATE(), \'media\', \'concluida\', ?, ?, ?)'
        );
        $stmt->execute([$setorLocalId, $cargoId, $adminId, $stageAprovada, $dataAdmissao?->format('Y-m-d'), $avaliacao]);
        $id = (int)$pdo->lastInsertId();
        $criados['solicitacoes'][] = $id;
        return $id;
    };

    $baseAvaliacao = $painelEmp['avaliacao_experiencia'];

    // Realizada: admissão há 95 dias -> vencimento dos 90 dias cai há 5 dias, dentro do período.
    $mkSolicitacaoAvaliacao($hoje->modify('-95 days'), 'atendeu_plenamente');
    // Realizada, mas vencimento MUITO antes do período (admissão há 500 dias) -> não deve contar
    // como "realizada no período".
    $mkSolicitacaoAvaliacao($hoje->modify('-500 days'), 'atendeu_parcialmente');
    // Pendente: admissão há 100 dias -> prazo de 90 dias já venceu (retrato de agora, não do
    // período), avaliação ainda não preenchida.
    $mkSolicitacaoAvaliacao($hoje->modify('-100 days'), null);

    $painelAvaliacao = $service->montarPainel(['codigo_empresa' => $empFixture], $inicio, $fim);
    $avaliacaoDepois = $painelAvaliacao['avaliacao_experiencia'];
    $check(($avaliacaoDepois['realizadas'] - $baseAvaliacao['realizadas']) === 1, '(17a) Avaliação de Experiência Realizadas: delta +1 — só a avaliação cujo vencimento de 90 dias cai dentro do período conta, a de 500 dias atrás fica de fora');
    $check(($avaliacaoDepois['pendentes'] - $baseAvaliacao['pendentes']) === 1, '(17b/18) Avaliação de Experiência Pendentes: delta +1 — retrato de agora (prazo já vencido, sem avaliação preenchida)');

    // ---- 14) Empresa RESOLVIDA no catálogo local filtra Vagas normalmente ----------------------
    $painelEmpresaResolvida = $service->montarPainel(['codigo_empresa' => $empCodigoResolvido], $inicio, $fim);
    $check($painelEmpresaResolvida['vagas']['empresa_sem_correspondencia'] === false, '(9-correlato) Empresa com correspondência local: empresa_sem_correspondencia = false');
    $check($painelEmpresaResolvida['vagas']['abertas'] === 3, '(9-correlato) Empresa resolvida filtra Vagas Abertas corretamente (as 3 solicitações fixture, todas em "aprovada")');

    // ---- 15) Empresa SEM correspondência: NUNCA degrada para o total geral ----------------------
    $codigoSemCorrespondencia = 'ZZ-NAO-CADASTRADA-' . $suffix;
    $painelSemFiltroDeEmpresa = $service->montarPainel([], $inicio, $fim);
    $painelEmpresaNaoCadastrada = $service->montarPainel(['codigo_empresa' => $codigoSemCorrespondencia], $inicio, $fim);
    $check($painelSemFiltroDeEmpresa['vagas']['empresa_sem_correspondencia'] === false, 'Sem filtro de Empresa: empresa_sem_correspondencia = false (comportamento normal preservado)');
    $check($painelEmpresaNaoCadastrada['vagas']['empresa_sem_correspondencia'] === true, '(9) Empresa selecionada sem correspondência local: sinalizada explicitamente (empresa_sem_correspondencia = true)');
    $check($painelEmpresaNaoCadastrada['vagas']['abertas'] === 0, '(9) Empresa sem correspondência: Vagas Abertas = 0 — NUNCA o total geral silenciosamente ampliado');
    $check($painelEmpresaNaoCadastrada['vagas']['fechadas_no_periodo'] === 0, '(9) Empresa sem correspondência: Vagas Fechadas = 0 — mesma regra');
    $check($painelEmpresaNaoCadastrada['headcount_por_empresa'] === [], '(27) Empresa sem correspondência: Headcount por Empresa = [], nunca o total geral');
    $check($painelEmpresaNaoCadastrada['turnover']['por_empresa'] === [], '(27) Empresa sem correspondência: Turnover por Empresa = [], nunca o total geral');
    $check($painelEmpresaNaoCadastrada['desligamentos_por_empresa'] === [], '(27) Empresa sem correspondência: Desligamentos por Empresa = [], nunca o total geral');
    $check(
        $painelSemFiltroDeEmpresa['vagas']['abertas'] === 0 || $painelEmpresaNaoCadastrada['vagas']['abertas'] !== $painelSemFiltroDeEmpresa['vagas']['abertas'],
        '(9) Empresa sem correspondência não é numericamente igual ao total sem filtro (a menos que o total geral em DEV já seja 0) — prova de que não houve degradação para "sem filtro"'
    );

    // ---- 16) Turnover Voluntário/Involuntário: classificação oficial por motivo_rescisao_codigo -
    $empV = 'ZZV' . $suffix;
    $pv = 'ZZP' . $suffix . 'V';
    $mkMetadados($pv . '0', 'BASE', $hoje->modify('-900 days'), null, true, $empV, null);
    $codigosDesligamento = [
        '1' => '001', '2' => '002', '3' => '003', '5' => '005',
        '6' => '006', '7' => '007', '8' => '008', 'G' => '016', 'H' => '020',
    ];
    $dias = -20;
    foreach ($codigosDesligamento as $sufixoPessoa => $codigoMotivo) {
        $mkMetadadosComMotivo = static function () use ($pdo, &$criados, $suffix, $pv, $sufixoPessoa, $hoje, $dias, $codigoMotivo, $empV): void {
            $identificador = 'ZZPA_' . $suffix . '_' . $pv . $sufixoPessoa . '_MOT';
            $pdo->prepare(
                'INSERT INTO colaboradores_metadados (
                    identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                    nome, admissao, demissao, motivo_rescisao_codigo, ativo, origem_metadados
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
            )->execute([
                $identificador, $empV, 'ZZU' . $suffix, 'ZZC' . $pv . $sufixoPessoa . 'MOT',
                $pv . $sufixoPessoa, 'ZZPA Fixture Motivo ' . $codigoMotivo,
                $hoje->modify('-400 days')->format('Y-m-d'), $hoje->modify($dias . ' days')->format('Y-m-d'),
                $codigoMotivo, 'zzpa-teste',
            ]);
            $criados['metadados_identificadores'][] = $identificador;
        };
        $mkMetadadosComMotivo();
        $dias++;
    }

    $painelV = $service->montarPainel(['codigo_empresa' => $empV], $inicio, $fim);
    $check($painelV['desligamentos']['periodo'] === 9, 'Fixture de classificação: 9 desligamentos no período (um por código real encontrado no METADADOS)');
    $check($painelV['turnover']['voluntario']['eventos'] === 2, '(7) Turnover Voluntário conta só 003 (Pedido de Demissão) + 006 (Resc. Ant. Contr. Determ. p/Empregado) = 2 eventos');
    $check($painelV['turnover']['involuntario']['eventos'] === 3, '(8) Turnover Involuntário conta só 001 (Justa Causa) + 002 (Sem Justa Causa) + 007 (Resc. Ant. Contr. Determ. p/Empresa) = 3 eventos');
    $outros = $painelV['desligamentos']['periodo'] - $painelV['turnover']['voluntario']['eventos'] - $painelV['turnover']['involuntario']['eventos'];
    $check($outros === 4, '(5º ao 8º código "outros") 005/008/016/020 continuam em Desligamentos/Turnover Geral mas somados dão 4 eventos fora dos dois numeradores — Geral ≠ Voluntário + Involuntário');

    $ativosPeriodoV = $painelV['turnover']['ativos_periodo'];
    $check(
        $painelV['turnover']['voluntario']['percentual'] === RhIndicadoresService::taxaTurnoverPeriodo(2, $ativosPeriodoV),
        '(2) Turnover Voluntário usa RhIndicadoresService::taxaTurnoverPeriodo() com a MESMA base de ativos do período do Turnover Geral — fórmula não duplicada'
    );
    $check(
        $painelV['turnover']['involuntario']['percentual'] === RhIndicadoresService::taxaTurnoverPeriodo(3, $ativosPeriodoV),
        '(2) Turnover Involuntário usa a mesma fórmula/base, só troca o numerador'
    );
    $check(
        $painelV['turnover']['geral_percentual'] === RhIndicadoresService::taxaTurnoverPeriodo(9, $ativosPeriodoV),
        '(2) Turnover Geral desta fixture também bate com taxaTurnoverPeriodo(9, mesma base) — coerência matemática entre os três'
    );
    $check(
        $painelV['turnover']['geral_percentual'] !== round($painelV['turnover']['voluntario']['percentual'] + $painelV['turnover']['involuntario']['percentual'], 1),
        'Confirma explicitamente que Geral NÃO é a soma de Voluntário + Involuntário (existem desligamentos "outros" no meio)'
    );

    // ---- 16b) Rosca Voluntário x Involuntário x Outros: participação é % do TOTAL DE
    //           DESLIGAMENTOS do período (nunca da taxa de turnover) — as 3 fatias somam 100% dos
    //           desligamentos reais, nunca força Voluntário+Involuntário=100% quando há Outros ---
    $participacaoVolEsperada = round(2 / 9 * 100, 1);
    $participacaoInvolEsperada = round(3 / 9 * 100, 1);
    $participacaoOutrosEsperada = round(4 / 9 * 100, 1);
    $check($painelV['turnover']['voluntario']['participacao_desligamentos'] === $participacaoVolEsperada, '(28) Rosca: participação de Voluntário = 2/9 dos desligamentos do período (22,2%), não da taxa de turnover');
    $check($painelV['turnover']['involuntario']['participacao_desligamentos'] === $participacaoInvolEsperada, '(28) Rosca: participação de Involuntário = 3/9 dos desligamentos (33,3%)');
    $check($painelV['turnover']['outros']['eventos'] === 4 && $painelV['turnover']['outros']['participacao_desligamentos'] === $participacaoOutrosEsperada, '(28) Rosca: "Outros" é representado como fatia própria (4/9 = 44,4%) — nunca omitido, nunca absorvido por Voluntário/Involuntário para forçar 100%');
    $check(
        round($painelV['turnover']['voluntario']['participacao_desligamentos'] + $painelV['turnover']['involuntario']['participacao_desligamentos'] + $painelV['turnover']['outros']['participacao_desligamentos'], 1) >= 99.9,
        '(28) Rosca: as 3 fatias juntas cobrem a totalidade dos desligamentos do período (~100%, sujeito a arredondamento de 0,1 p.p.) — nunca uma composição matematicamente enganosa'
    );
    // Sem nenhum desligamento no período, participação é 0.0 (view decide omitir a rosca inteira,
    // nunca desenhar 3 fatias falsas de 0%).
    $check($painelVazio['turnover']['voluntario']['participacao_desligamentos'] === 0.0, '(28) Rosca: sem desligamentos no período, participação fica 0.0 — a view usa desligamentos.periodo === 0 para decidir omitir a rosca inteira, nunca 0% inventado como se fosse um dado real');

    // ---- 16c) Guard de regressão: nenhuma métrica contratual volta a usar
    //           COUNT(DISTINCT codigo_pessoa) (Headcount/Turnover/Desligamentos por Empresa
    //           incluídos) — a unidade oficial continua sendo o CONTRATO -----------------------
    $fonteRepository = strtoupper((string)file_get_contents(APP_PATH . '/repositories/PeopleAnalyticsRepository.php'));
    $fonteService = strtoupper((string)file_get_contents(APP_PATH . '/services/PeopleAnalyticsService.php'));
    $check(!str_contains($fonteRepository, 'COUNT(DISTINCT'), '(29) PeopleAnalyticsRepository nunca usa COUNT(DISTINCT ...) — unidade oficial é o contrato, nunca codigo_pessoa deduplicado');
    $check(!str_contains($fonteService, 'COUNT(DISTINCT'), '(29) PeopleAnalyticsService nunca usa COUNT(DISTINCT ...) — mesma garantia no lado do Service (agrupamentos por Empresa incluídos)');

    // ---- 17) Central: item Dashboard exige dashboard.visualizar (fonte, não só comportamento; sidebar removida) ---
    $regraNavDashboard = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/dashboard') { $regraNavDashboard = $it['regra']; }
        }
    }
    $check(
        $regraNavDashboard === 'perm:dashboard.visualizar',
        '(20) A Central só oferece o Dashboard (People Analytics, /admin/dashboard) sob a regra perm:dashboard.visualizar (PortalNavegacaoService::definicao()) — Admin continua vendo por bypass central, ninguém mais vê automaticamente por role'
    );

    // ---- 18) Supervisor sem a permissão individual NÃO recebe acesso só por ser Supervisor ------
    $supervisorSemId = User::create('ZZPA Supervisor Sem Permissao', 'supervisor.sem.zzpa.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($supervisorSemId, true);
    $criados['usuarios'][] = $supervisorSemId;
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supervisorSemId]);
    $check(Authorization::usuarioTemPermissao($supervisorSemId, 'dashboard.visualizar') === false, '(3c) Supervisor (is_supervisor=1, role viewer) sem a permissão individual NÃO acessa — Auth::requireRole() dá bypass de ROLE ao Supervisor, mas Authorization NUNCA replica esse bypass');

    // ---- 19) Resolução centralizada de destino pós-login -----------------------------------------
    $gestorId = User::create('ZZPA Gestor Restrito', 'gestor.zzpa.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($gestorId, true);
    $criados['usuarios'][] = $gestorId;
    $permSolicitacaoStmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $permSolicitacaoStmt->execute(['solicitacao_vaga.visualizar']);
    $permSolicitacaoId = (int)($permSolicitacaoStmt->fetchColumn() ?: 0);
    $check($permSolicitacaoId > 0, 'Fixture: permissão solicitacao_vaga.visualizar existe no catálogo (não inventada para este teste)');
    if ($permSolicitacaoId > 0) {
        Authorization::sincronizar($gestorId, [$permSolicitacaoId]);
    }

    $ninguemId = User::create('ZZPA Sem Nenhuma Permissao', 'ninguem.zzpa.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($ninguemId, true);
    $criados['usuarios'][] = $ninguemId;

    $comoUsuario = static function (int $usuarioId, string $role): array {
        $anterior = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_role' => $_SESSION['user_role'] ?? null,
            'user_is_supervisor' => $_SESSION['user_is_supervisor'] ?? null,
        ];
        $_SESSION['user_id'] = $usuarioId;
        $_SESSION['user_role'] = $role;
        $_SESSION['user_is_supervisor'] = 0;
        return $anterior;
    };
    $restaurarSessao = static function (array $anterior): void {
        foreach ($anterior as $chave => $valor) {
            if ($valor === null) {
                unset($_SESSION[$chave]);
            } else {
                $_SESSION[$chave] = $valor;
            }
        }
    };

    $sessaoOriginal = $comoUsuario($adminId, 'admin');
    $check(Authorization::primeiraRotaAcessivel() === '/admin', '(6-a) Admin entra pela Central (/admin) após o login');

    $comoUsuario($viewerComId, 'viewer');
    $check(Authorization::primeiraRotaAcessivel() === '/admin', '(6-b) Usuário com dashboard.visualizar também entra pela Central (/admin); o Dashboard virou /admin/dashboard');

    $comoUsuario($gestorId, 'viewer');
    $rotaGestor = Authorization::primeiraRotaAcessivel();
    $check($rotaGestor === '/admin', '(7) "Gestor restrito" (só solicitacao_vaga.visualizar, sem dashboard.visualizar) entra pela Central (/admin), aberta a qualquer autenticado — nunca 403; o card de Solicitações o leva ao módulo');
    $check($rotaGestor !== '/admin/dashboard', '(7-correlato) Confirma explicitamente que o Gestor restrito não é enviado ao Dashboard (/admin/dashboard, onde receberia 403)');

    $comoUsuario($ninguemId, 'viewer');
    $rotaSemPermissaoAlguma = Authorization::primeiraRotaAcessivel();
    $check($rotaSemPermissaoAlguma !== '/admin/dashboard', '(6-c) Usuário sem NENHUMA permissão individual não é enviado ao Dashboard (/admin/dashboard)');
    $check($rotaSemPermissaoAlguma === '/admin', '(6-c) Usuário sem nenhuma permissão entra pela Central (aberta por role, sem loop de redirect — /admin não redireciona)');

    $restaurarSessao($sessaoOriginal);

    // ---- (30) Turnover por Sexo: PeopleAnalyticsService::montarPainel()['turnover']['genero'] --
    // Nova fórmula oficial (2026-09): desligados / ATIVOS DO PERÍODO (nunca mais a média de
    // headcount). Aqui provamos que PeopleAnalyticsService::montarPainel() realmente expõe esses
    // números via RhIndicadoresService::turnoverPorDimensaoPeriodo('sexo', ...), não só a fórmula
    // isolada. Todos os 10 homens e as 6 mulheres (4 ativas + FD1 desligada + FN1 admitida dentro
    // do período) sobrepõem o período — nenhum contrato desta fixture fica fora da população.
    $empSexoFixture = 'ZZH' . $suffix;
    $inicioSexo = new DateTimeImmutable('2024-01-01');
    $fimSexo = new DateTimeImmutable('2024-01-31');
    $insertSexo = $pdo->prepare(
        'INSERT INTO colaboradores_metadados
            (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
             nome, sexo, admissao, demissao, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mkSexo = function (string $sexo, string $admissao, ?string $demissao, string $seq) use ($pdo, $insertSexo, &$criados, $suffix, $empSexoFixture): void {
        $identificador = 'ZZPASX_' . $suffix . '_' . $seq;
        $insertSexo->execute([
            $identificador, $empSexoFixture, 'ZZUSX' . $suffix, 'ZZCSX' . $suffix . $seq,
            'ZZPSX' . $suffix . $seq, 'ZZPA Sexo Fixture ' . $seq, $sexo, $admissao, $demissao,
            $demissao === null ? 1 : 0, 'zzpa-sexo-teste',
        ]);
        $criados['metadados_identificadores'][] = $identificador;
    };
    // 10 homens: 8 seguem ativos, 2 são desligados dentro do período (headcount início=10, fim=8).
    for ($i = 0; $i < 8; $i++) {
        $mkSexo('M', '2023-01-01', null, 'M' . $i);
    }
    $mkSexo('M', '2023-01-01', '2024-01-10', 'MD1');
    $mkSexo('M', '2023-01-01', '2024-01-20', 'MD2');
    // 5 mulheres: 4 seguem ativas, 1 é desligada e 1 é admitida dentro do período (repõe a vaga) —
    // headcount início=5, fim=5, com 1 desligamento.
    for ($i = 0; $i < 4; $i++) {
        $mkSexo('F', '2023-01-01', null, 'F' . $i);
    }
    $mkSexo('F', '2023-01-01', '2024-01-15', 'FD1');
    $mkSexo('F', '2024-01-05', null, 'FN1');

    $painelSexo = $service->montarPainel(['codigo_empresa' => $empSexoFixture], $inicioSexo, $fimSexo);
    $check($painelSexo['turnover']['genero']['disponivel'] === true, '(30) Turnover por Sexo: genero.disponivel = true (deixou de ser "Dado ainda não integrado").');
    $check((int)$painelSexo['turnover']['genero']['masculino']['ativos_periodo'] === 10, '(30) Turnover por Sexo: Masculino tem 10 contratos ativos no período (todos sobrepõem, nenhum desligado antes do início) — nova fórmula usa ativos do período, não headcount médio.');
    $check(abs($painelSexo['turnover']['genero']['masculino']['taxa'] - 20.0) < 0.05, '(30) Turnover por Sexo: Masculino = 2 desligamentos / 10 ativos do período × 100 = 20,0%, via montarPainel() real.');
    $check((int)$painelSexo['turnover']['genero']['masculino']['desligamentos'] === 2, '(30) Turnover por Sexo: Masculino registra 2 desligamentos no período.');
    $check((int)$painelSexo['turnover']['genero']['feminino']['ativos_periodo'] === 6, '(30) Turnover por Sexo: Feminino tem 6 contratos ativos no período (4 ativas + FD1 desligada dentro do período + FN1 admitida dentro do período)');
    $check(abs($painelSexo['turnover']['genero']['feminino']['taxa'] - 16.7) < 0.05, '(30) Turnover por Sexo: Feminino = 1 desligamento / 6 ativos do período × 100 = 16,7%, via montarPainel() real.');
    $check((int)$painelSexo['turnover']['genero']['feminino']['desligamentos'] === 1, '(30) Turnover por Sexo: Feminino registra 1 desligamento no período.');
    $check($painelSexo['turnover']['genero']['nao_informado'] === null, '(30) Turnover por Sexo: sem registro sem sexo neste cenário, "não_informado" fica null (nunca inventado).');
    // Turnover Geral do mesmo painel não pode ter sido afetado pela segmentação por sexo.
    $check($painelSexo['headcount']['atual'] === 13, '(30) Turnover por Sexo: Headcount Atual do painel (13 = 8M + 5F) não muda pela adição da segmentação por sexo.');

    echo "\nPEOPLE_ANALYTICS_OK\n";
} finally {
    // ---- limpeza (ordem respeita as FKs: filhos antes dos pais) --------------------------------
    if (!empty($criados['colaboradores'])) {
        $pdo->prepare('DELETE FROM pesquisas_integracao WHERE colaborador_id IN (' . implode(',', array_map('intval', $criados['colaboradores'])) . ')')->execute();
        $pdo->prepare('DELETE FROM colaboradores WHERE id IN (' . implode(',', array_map('intval', $criados['colaboradores'])) . ')')->execute();
    }
    if (!empty($criados['solicitacoes'])) {
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id IN (' . implode(',', array_map('intval', $criados['solicitacoes'])) . ')')->execute();
    }
    if (!empty($criados['setores'])) {
        $pdo->prepare('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $criados['setores'])) . ')')->execute();
    }
    if (!empty($criados['empresas'])) {
        $pdo->prepare('DELETE FROM empresas WHERE id IN (' . implode(',', array_map('intval', $criados['empresas'])) . ')')->execute();
    }
    if (!empty($criados['metadados_identificadores'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados_identificadores']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados_identificadores']);
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
