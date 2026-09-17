<?php

/**
 * Integração — People Analytics / Tela Inicial (PeopleAnalyticsRepository + PeopleAnalyticsService
 * + AdminController::index() + permissão dashboard.visualizar).
 *
 * Prova que:
 *   - a permissão dashboard.visualizar (catálogo já existente) exige o mecanismo central de
 *     Authorization — Admin via bypass, usuário via permissão individual, NUNCA por role sozinha;
 *   - Headcount Atual conta PESSOA distinta (codigo_pessoa), nunca contrato — duas linhas ativas da
 *     mesma pessoa contam uma vez;
 *   - Headcount só considera ativo = 1;
 *   - Filtro de Empresa e de Setor isolam corretamente colaboradores_metadados;
 *   - Ausência de codigo_setor cai em "Setor não informado", nunca inferido de outra fonte;
 *   - Admissões no período deduplicam por pessoa (readmissão rápida não conta duas vezes);
 *   - Desligamentos no período são por EVENTO/contrato, não deduplicados por pessoa;
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
 *   - Authorization::primeiraRotaAcessivel() nunca manda quem tem dashboard.visualizar para outro
 *     lugar, manda quem só tem permissão funcional (ex.: Solicitação de Vaga) para essa área real
 *     (nunca para /admin, nunca 403), e tem uma rota de segurança final para quem não tem nenhuma
 *     permissão — sem loop de redirect;
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

    // P1: ativo, Empresa+Setor fixture, DOIS contratos (mesma pessoa) — prova que Headcount conta
    // pessoa distinta, não contrato.
    $mkMetadados($p1, 'A', $hoje->modify('-800 days'), null, true, $empFixture, $setFixture);
    $mkMetadados($p1, 'B', $hoje->modify('-600 days'), null, true, $empFixture, $setFixture);

    // P2: já desligado ANTES do período (demissao < inicio) — some do headcount hoje e do início
    // do período; não deve aparecer em Desligamentos deste período.
    $mkMetadados($p2, 'A', $hoje->modify('-900 days'), $hoje->modify('-200 days'), false, $empFixture, $setFixture);

    // P3: ativo, SEM codigo_setor — nunca inferido, cai em "Setor não informado".
    $mkMetadados($p3, 'A', $hoje->modify('-700 days'), null, true, $empFixture, null);

    // P4: admissão DENTRO do período, em DOIS contratos (readmissão rápida) — Admissões dedup por pessoa.
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

    // ---- 2) Headcount: pessoa distinta, não contrato; só ativo = 1 ----------------------------
    $painelEmp = $service->montarPainel(['codigo_empresa' => $empFixture], $inicio, $fim);
    $check($painelEmp['headcount']['atual'] === 3, '(A) Headcount Atual = 3 (P1 dedup de 2 contratos, P3, P4 dedup de 2 contratos) — P2 e P5/P6 excluídos por ativo=0');

    // ---- 3) Filtro de Setor isola corretamente (independente do filtro de Empresa) -------------
    $painelSetor = $service->montarPainel(['codigo_setor' => $setFixture], $inicio, $fim);
    $check($painelSetor['headcount']['atual'] === 2, '(B/C) Filtro de Setor conta só P1+P4 (ativos com codigo_setor = fixture) — P3 fica de fora por não ter Setor');

    // ---- 4) "Setor não informado" nunca é inferido, aparece separado --------------------------
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
    $check($itemSetorFixture !== null && $itemSetorFixture['quantidade'] === 2, '(D) Colaboradores por Setor: setor fixture soma 2 pessoas ativas distintas (P1+P4)');
    $check($itemSemSetor !== null && $itemSemSetor['quantidade'] === 1, '(19) Colaboradores por Setor: "Setor não informado" = 1 (P3) — nunca redistribuído nem inferido');

    // ---- 5) Admissões no período dedup por pessoa -----------------------------------------------
    $check($painelEmp['admissoes']['periodo'] === 1, '(E) Admissões no período = 1 — P4 tem 2 contratos com admissão no período (readmissão), mas conta como 1 pessoa distinta');

    // ---- 6) Desligamentos no período são por evento, NÃO deduplicados por pessoa ----------------
    $check($painelEmp['desligamentos']['periodo'] === 3, '(F) Desligamentos no período = 3 eventos — P5 tem 2 contratos desligados no período (não deduplicados) + P6 = 3, P2 fica de fora (desligado antes do período)');

    // ---- 7) Turnover Geral reaproveita RhIndicadoresService::taxaTurnover() ---------------------
    $headcountInicio = $painelEmp['turnover']['headcount_inicio'];
    $headcountFim = $painelEmp['turnover']['headcount_fim'];
    $esperadoTurnover = RhIndicadoresService::taxaTurnover($painelEmp['desligamentos']['periodo'], $headcountInicio, $headcountFim);
    $check($painelEmp['turnover']['geral_percentual'] === $esperadoTurnover, '(G) Turnover Geral bate exatamente com RhIndicadoresService::taxaTurnover() — fórmula não duplicada');
    $check($headcountInicio === 4, '(G-correlato) Headcount no início do período = 4 (P1, P3, P5, P6 já ativos em -30d) — P2 já tinha saído, P4 ainda não tinha entrado');
    $check($headcountFim === 3, '(G-correlato) Headcount no fim do período = 3 (P1, P3, P4) — P5 e P6 já tinham saído até hoje');

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

    $hcInicioV = $painelV['turnover']['headcount_inicio'];
    $hcFimV = $painelV['turnover']['headcount_fim'];
    $check(
        $painelV['turnover']['voluntario']['percentual'] === RhIndicadoresService::taxaTurnover(2, $hcInicioV, $hcFimV),
        '(2) Turnover Voluntário usa RhIndicadoresService::taxaTurnover() com a MESMA base de headcount do Turnover Geral — fórmula não duplicada'
    );
    $check(
        $painelV['turnover']['involuntario']['percentual'] === RhIndicadoresService::taxaTurnover(3, $hcInicioV, $hcFimV),
        '(2) Turnover Involuntário usa a mesma fórmula/base, só troca o numerador'
    );
    $check(
        $painelV['turnover']['geral_percentual'] === RhIndicadoresService::taxaTurnover(9, $hcInicioV, $hcFimV),
        '(2) Turnover Geral desta fixture também bate com taxaTurnover(9, mesma base) — coerência matemática entre os três'
    );
    $check(
        $painelV['turnover']['geral_percentual'] !== round($painelV['turnover']['voluntario']['percentual'] + $painelV['turnover']['involuntario']['percentual'], 1),
        'Confirma explicitamente que Geral NÃO é a soma de Voluntário + Involuntário (existem desligamentos "outros" no meio)'
    );

    // ---- 17) Sidebar: item Dashboard exige dashboard.visualizar (fonte, não só comportamento) ---
    $sidebarFonte = (string)file_get_contents(APP_PATH . '/views/layouts/sidebar.php');
    $check(
        (bool)preg_match('/temPermissao\(\'dashboard\.visualizar\'\)\s*\)\s*:\s*\?>\s*<a href="<\?= \$base \?>\/admin"/s', $sidebarFonte),
        '(20) Sidebar só renderiza o link "Dashboard" dentro de um if Authorization::temPermissao(\'dashboard.visualizar\') — Admin continua vendo por bypass central, ninguém mais vê automaticamente por role'
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
    $check(Authorization::primeiraRotaAcessivel() === '/admin', '(6-a) Admin (bypass) continua indo para /admin');

    $comoUsuario($viewerComId, 'viewer');
    $check(Authorization::primeiraRotaAcessivel() === '/admin', '(6-b) Usuário com dashboard.visualizar continua indo para /admin');

    $comoUsuario($gestorId, 'viewer');
    $rotaGestor = Authorization::primeiraRotaAcessivel();
    $check($rotaGestor === '/admin/solicitacoes-vaga', '(7) "Gestor restrito" (só solicitacao_vaga.visualizar, sem dashboard.visualizar) é direcionado para /admin/solicitacoes-vaga — nunca /admin, nunca 403');
    $check($rotaGestor !== '/admin', '(7-correlato) Confirma explicitamente que o Gestor restrito não cai em /admin (onde receberia 403)');

    $comoUsuario($ninguemId, 'viewer');
    $rotaSemPermissaoAlguma = Authorization::primeiraRotaAcessivel();
    $check($rotaSemPermissaoAlguma !== '/admin', '(6-c) Usuário sem NENHUMA permissão individual não é enviado para /admin');
    $check(in_array($rotaSemPermissaoAlguma, ['/admin/candidaturas', '/admin/manual'], true), '(6-c) Usuário sem nenhuma permissão é tratado de forma explícita e segura — recebe uma rota real aberta por role, sem loop de redirect');

    $restaurarSessao($sessaoOriginal);

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
