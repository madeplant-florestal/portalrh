<?php

/**
 * Integração — Dashboard de Turnover (TurnoverDashboardRepository + TurnoverDashboardService +
 * AdminDashboardTurnoverController + permissão dashboard_turnover.visualizar + menu + seed).
 *
 * Prova que:
 *   - o repository lê só o espelho oficial (sem CPF/nome/nascimento/salário) e filtra por
 *     codigo_empresa e codigo_cargo (identidade = código, nunca texto);
 *   - os filtros de Empresa/Cargo valem para TODOS os cálculos e para os DOIS anos do comparativo;
 *   - os anos selecionáveis vão do primeiro ano com desligamento + 1 até o ano atual;
 *   - a página exige a permissão individual no backend (sem ela: 403; RH só por role não acessa; Admin
 *     por bypass), o menu só aparece com a permissão e o seed só CADASTRA a permissão;
 *   - a página renderiza as seis análises, valores também em tabela, sem dados pessoais e sem
 *     Warning/Notice;
 *   - People Analytics e Indicadores de RH seguem funcionando (semântica intacta).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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
$criados = ['usuarios' => [], 'metadados' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$hoje = new DateTimeImmutable('2026-09-19');

$empA = 'ZTA' . $suffix;
$empB = 'ZTB' . $suffix;
$cargoA = 'ZTCA' . $suffix;
$cargoB = 'ZTCB' . $suffix;
$nomeEmpA = 'ZZTD Empresa A ' . $suffix;
$nomeEmpB = 'ZZTD Empresa B ' . $suffix;
$cpfSecreto = '12345678909';
$nomeSecreto = 'ZZTD Pessoa Secreta';

$mk = static function (string $emp, string $nomeEmp, string $cargo, string $textoCargo, string $adm, ?string $dem, ?string $motivo) use ($pdo, &$criados, $suffix, $cpfSecreto, $nomeSecreto): void {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZTD_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, 'ZTU' . $suffix, 'ZTC' . $suffix . $seq, 'ZTP' . $suffix . $seq, $cpfSecreto, $nomeSecreto, $nomeEmp,
        $textoCargo, $cargo, $adm, $dem, $motivo, $dem === null ? 1 : 0, 'zztd-teste',
    ]);
    $criados['metadados'][] = $identificador;
};

try {
    // ---- permissão / usuários -------------------------------------------------------------------------
    $perm = $pdo->query("SELECT id, modulo, ordem, ativo FROM permissoes WHERE codigo = 'dashboard_turnover.visualizar'")->fetch(PDO::FETCH_ASSOC);
    $check($perm !== false && (int)$perm['ativo'] === 1 && $perm['modulo'] === 'dashboard_turnover' && (int)$perm['ordem'] === 610, '(permissão) dashboard_turnover.visualizar existe no catálogo (seed aplicado localmente), módulo dashboard_turnover');
    $permId = (int)($perm['id'] ?? 0);

    $seedFonte = (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-20-dashboard-turnover-permissao-seed.sql');
    $seedSemComentarios = preg_replace('/^--.*$/m', '', $seedFonte);
    $check(str_contains($seedSemComentarios, 'INSERT IGNORE INTO permissoes') && !preg_match('/usuario_permissoes|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s/i', $seedSemComentarios), '(seed) O seed só CADASTRA a permissão (INSERT IGNORE em permissoes) — sem tabela, sem schema e sem conceder a ninguém');

    $adminId = User::create('ZZTD Admin', 'admin.zztd.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;
    $comPermId = User::create('ZZTD Com Permissao', 'com.zztd.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($comPermId, true);
    $criados['usuarios'][] = $comPermId;
    Authorization::sincronizar($comPermId, [$permId]);
    $rhSemPermId = User::create('ZZTD RH Sem Permissao', 'rh.zztd.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($rhSemPermId, true);
    $criados['usuarios'][] = $rhSemPermId;

    $check(Authorization::usuarioTemPermissao($adminId, 'dashboard_turnover.visualizar') === true, '(permissão) Admin acessa pelo bypass central');
    $check(Authorization::usuarioTemPermissao($comPermId, 'dashboard_turnover.visualizar') === true, '(permissão) Usuário com a permissão individual acessa');
    $check(Authorization::usuarioTemPermissao($rhSemPermId, 'dashboard_turnover.visualizar') === false, '(permissão) RH SEM a permissão individual NÃO acessa — role sozinha não basta');
    $concedidosFora = (int)$pdo->query(
        "SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id
         WHERE p.codigo = 'dashboard_turnover.visualizar' AND up.usuario_id NOT IN (" . (int)$comPermId . ")"
    )->fetchColumn();
    $check($concedidosFora === 0, '(permissão) Ninguém além do usuário de teste recebeu a permissão — o seed não concede automaticamente');

    $corpoIndex = (static function (): string {
        $r = new ReflectionMethod(AdminDashboardTurnoverController::class, 'index');
        $arq = new SplFileObject($r->getFileName());
        $arq->seek($r->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $r->getEndLine()) {
            $corpo .= $arq->current();
            $arq->next();
        }
        return $corpo;
    })();
    $check(str_contains($corpoIndex, "Auth::requireRole(['admin', 'rh', 'viewer'])") && str_contains($corpoIndex, "Authorization::requirePermissao('dashboard_turnover.visualizar')"), '(permissão) index() exige sessão + dashboard_turnover.visualizar no backend (403 real, não só ocultação de menu)');
    $fonteIndexPhp = (string)file_get_contents(BASE_PATH . '/index.php');
    $check(str_contains($fonteIndexPhp, "\$router->get('/admin/dashboard-turnover', [AdminDashboardTurnoverController::class, 'index'])"), '(rota) /admin/dashboard-turnover registrada sob /admin (autenticação global do index.php)');
    $check((bool)preg_match('/temPermissao\(\'dashboard_turnover\.visualizar\'\)\s*\)\s*:\s*\?>\s*<a href="<\?= \$base \?>\/admin\/dashboard-turnover"/s', (string)file_get_contents(APP_PATH . '/views/layouts/sidebar.php')), '(menu) O link só é renderizado dentro de um if Authorization::temPermissao(\'dashboard_turnover.visualizar\')');

    // ---- fixtures de contratos -----------------------------------------------------------------------------
    $mk($empA, $nomeEmpA, $cargoA, 'ZZTD Cargo A', '2000-01-01', null, null);          // A1 ativo
    $mk($empA, $nomeEmpA, $cargoA, 'ZZTD Cargo A', '2000-01-01', '2004-03-10', '003'); // A2 desligado em 2004
    $mk($empA, $nomeEmpA, $cargoB, 'ZZTD Cargo B', '2000-01-01', '2003-05-05', '002'); // A3 desligado em 2003
    $mk($empB, $nomeEmpB, $cargoB, 'ZZTD Cargo B', '2000-01-01', '2004-06-01', '001'); // B1 desligado em 2004
    $mk($empB, $nomeEmpB, $cargoB, 'ZZTD Cargo B', '2000-01-01', null, null);          // B2 ativo

    $repo = new TurnoverDashboardRepository();
    $service = new TurnoverDashboardService($repo);

    // ---- repository ----------------------------------------------------------------------------------------------
    $contratosA = $repo->buscarContratos(['codigo_empresa' => $empA]);
    $check(count($contratosA) === 3, '(repository) Filtro por codigo_empresa isola os 3 contratos da Empresa A');
    $check(array_keys($contratosA[0]) === ['admissao', 'demissao', 'motivo_rescisao_codigo', 'codigo_empresa', 'empresa', 'codigo_cargo', 'cargo'], '(repository) Só as colunas dos indicadores — sem CPF, nome, nascimento ou salário');
    $check(count($repo->buscarContratos(['codigo_cargo' => $cargoB])) === 3 && count($repo->buscarContratos(['codigo_empresa' => $empA, 'codigo_cargo' => $cargoB])) === 1, '(repository) Filtro por codigo_cargo e combinação Empresa + Cargo');
    $check(count($repo->buscarContratos(['codigo_empresa' => 'NAO-EXISTE-' . $suffix])) === 0, '(repository) Código inexistente não devolve o total geral');
    $check($repo->buscarContratos(['codigo_empresa' => $nomeEmpA]) === [], '(repository) Filtro é pelo CÓDIGO — o texto do nome não identifica a empresa');
    $opcoes = $repo->opcoesFiltro();
    $empresasPorCodigo = array_column($opcoes['empresas'], 'empresa', 'codigo_empresa');
    $cargosPorCodigo = array_column($opcoes['cargos'], 'nome', 'codigo_cargo');
    $check(($empresasPorCodigo[$empA] ?? null) === $nomeEmpA, '(filtros) Opção de Empresa por codigo_empresa com o nome do espelho');
    $check(($cargosPorCodigo[$cargoA] ?? null) === 'ZZTD Cargo A', '(filtros) Opção de Cargo por codigo_cargo (sem catálogo cai no texto do espelho)');
    $catalogo = $pdo->query("SELECT codigo_cargo, COALESCE(descricao_oficial, nome) AS nome FROM cargos WHERE codigo_cargo IS NOT NULL AND codigo_cargo <> '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($catalogo) {
        $check(($repo->nomesOficiaisDeCargos([$catalogo['codigo_cargo']])[$catalogo['codigo_cargo']] ?? null) === $catalogo['nome'], '(cargo) Nome oficial resolvido pelo catálogo cargos via codigo_cargo');
    }
    $check($repo->nomesOficiaisDeCargos(['CODIGO-SEM-CATALOGO-' . $suffix]) === [] && $repo->nomesOficiaisDeCargos([]) === [], '(cargo) Código sem correspondência no catálogo não recebe nome inventado');

    // anos selecionáveis
    $primeiro = (int)$pdo->query('SELECT MIN(YEAR(demissao)) FROM colaboradores_metadados WHERE demissao IS NOT NULL')->fetchColumn();
    $anos = $service->anosDisponiveis($hoje);
    $check($primeiro === 2003 && $anos[0] === 2004 && end($anos) === 2026 && $anos === range(2004, 2026), '(ano) Anos selecionáveis: do primeiro ano com desligamento + 1 (2004) até o ano atual (2026)');

    // ---- filtros aplicados a todos os cálculos e aos dois anos -------------------------------------------------------
    $painelA = $service->montarPainel(['codigo_empresa' => $empA], 2004, $hoje);
    $puroA = TurnoverDashboardService::montarPainelComContratos($contratosA, 2004, $hoje, []);
    $check($painelA['comparativo'] === $puroA['comparativo'] && $painelA['motivos'] === $puroA['motivos'] && $painelA['empresas'] === $puroA['empresas'] && $painelA['tempo_empresa'] === $puroA['tempo_empresa'], '(filtro) O painel filtrado é exatamente o cálculo puro sobre os contratos da Empresa A');
    $check($painelA['total_contratos'] === 3, '(filtro) Só os 3 contratos da Empresa A entram no cálculo');
    $check($painelA['comparativo']['anterior'][4]['desligamentos'] === 1 && $painelA['comparativo']['atual'][2]['desligamentos'] === 1 && $painelA['comparativo']['anterior'][2]['desligamentos'] === 0, '(filtro) Empresa A nos DOIS anos: 2003 (mai: A3) × 2004 (mar: A2) — o filtro vale para Y-1 e Y');
    $check(count($painelA['empresas']['itens']) === 1 && $painelA['empresas']['itens'][0]['codigo'] === $empA && $painelA['empresas']['itens'][0]['nome'] === $nomeEmpA, '(filtro) Turnover por Empresa mostra só a Empresa A, por código');
    $categoriasA = array_column($painelA['motivos']['categorias'], 'quantidade', 'categoria');
    $check($painelA['motivos']['total'] === 1 && $categoriasA['Voluntário'] === 1 && $categoriasA['Involuntário'] === 0, '(filtro) Motivos 2004 da Empresa A: só A2 (003 Voluntário); A3 é de 2003');

    $painelCargoB = $service->montarPainel(['codigo_cargo' => $cargoB], 2004, $hoje);
    $categoriasB = array_column($painelCargoB['motivos']['categorias'], 'quantidade', 'categoria');
    $check($painelCargoB['total_contratos'] === 3 && $categoriasB['Justa Causa'] === 1 && $painelCargoB['motivos']['total'] === 1, '(filtro) Cargo B em 2004: B1 (001 => Justa Causa)');
    $check(count($painelCargoB['cargos']['itens']) === 1 && $painelCargoB['cargos']['itens'][0]['codigo'] === $cargoB && $painelCargoB['cargos']['itens'][0]['nome'] === 'ZZTD Cargo B', '(cargo) Por codigo_cargo; sem catálogo o nome vem do texto do espelho');
    $painelAB = $service->montarPainel(['codigo_empresa' => $empA, 'codigo_cargo' => $cargoB], 2004, $hoje);
    $check($painelAB['total_contratos'] === 1 && $painelAB['motivos']['total'] === 0 && $painelAB['comparativo']['anterior'][4]['desligamentos'] === 1, '(filtro) Empresa A + Cargo B: só A3 (desligado em mai/2003) — combinação aplicada aos dois anos');
    $semFiltro = $service->montarPainel([], 2004, $hoje);
    $check($semFiltro['total_contratos'] >= 5, '(filtro) Sem filtro considera todos os contratos do espelho');

    // ---- página ----------------------------------------------------------------------------------------------------------------
    $renderizar = static function (callable $acao): string {
        ob_start();
        try {
            $acao();
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    };
    $comoUsuario = static function (int $id, string $role): void {
        $_SESSION['user_id'] = $id;
        $_SESSION['user_role'] = $role;
        $_SESSION['user_is_supervisor'] = 0;
    };

    $comoUsuario($comPermId, 'viewer');
    $_GET = ['ano' => '2004', 'empresa' => $empA];
    $html = $renderizar(static fn() => (new AdminDashboardTurnoverController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html), '(página) Renderiza sem Warning/Notice/Fatal');
    foreach (['Dashboard de Turnover', 'Evolução Mensal do Turnover — 2003 × 2004', 'Admissões × Desligamentos — 2004', 'Desligamentos por Motivo', 'Tempo de Empresa dos Desligados', 'Turnover por Cargo', 'Turnover por Empresa'] as $titulo) {
        $check(str_contains($html, $titulo), "(página) Exibe \"{$titulo}\"");
    }
    $check(substr_count($html, '<svg viewBox="0 0 720 280"') === 2, '(página) Os dois gráficos multissérie (linha e colunas agrupadas) são SVG próprio');
    $check(substr_count($html, 'Ver valores em tabela') >= 5 && str_contains($html, '<caption class="sr-only">'), '(acessibilidade) Valores também em tabela acessível (não dependem só do SVG nem de hover)');
    $check(str_contains($html, 'name="ano"') && str_contains($html, 'name="empresa"') && str_contains($html, 'name="cargo"') && str_contains($html, '2004 × 2003'), '(filtros) Ano, Empresa e Cargo na página; ano selecionado compara com o anterior');
    $check(str_contains($html, '<option value="' . $empA . '" selected'), '(filtros) A Empresa selecionada permanece marcada');
    $check(str_contains($html, 'MADEPLANT') || str_contains($html, 'Última atualização do METADADOS'), '(página) Cabeçalho com "Última atualização do METADADOS"');
    $check(!str_contains($html, $cpfSecreto) && !str_contains($html, $nomeSecreto) && !str_contains($html, '123.456.789-09'), '(privacidade) Nenhum CPF nem nome de colaborador na página');
    $viewFonte = (string)file_get_contents(APP_PATH . '/views/admin/dashboard-turnover.php') . (string)file_get_contents(APP_PATH . '/views/admin/partials/chart-helpers.php');
    $check(!preg_match('/\son(click|submit|change|load)\s*=/i', $viewFonte) && !str_contains($viewFonte, '<script'), '(CSP) A view e os helpers não usam handlers inline nem <script> (compatível com script-src \'self\')');
    $check(!preg_match('#https?://#i', (string)file_get_contents(APP_PATH . '/views/admin/dashboard-turnover.php')), '(visual) Sem biblioteca/CDN externa na view do dashboard');
    $check(str_contains($html, '/admin/dashboard-turnover'), '(menu) Usuário COM a permissão vê o item no menu');

    $_GET = ['ano' => '1900'];
    $htmlAnoInvalido = $renderizar(static fn() => (new AdminDashboardTurnoverController())->index());
    $check(str_contains($htmlAnoInvalido, '<option value="2026" selected'), '(ano) Ano fora das opções cai no ano atual, sem erro');
    $_GET = ['ano' => '2004', 'empresa' => "'; DROP TABLE x;--"];
    $htmlInjecao = $renderizar(static fn() => (new AdminDashboardTurnoverController())->index());
    $check(!preg_match('/Warning:|Notice:|Fatal error/i', $htmlInjecao), '(segurança) Valor malicioso em empresa é só um parâmetro (prepared statement) — sem erro');

    $comoUsuario($adminId, 'admin');
    $_GET = [];
    $htmlAdmin = $renderizar(static fn() => (new AdminDashboardTurnoverController())->index());
    $check(str_contains($htmlAdmin, 'Evolução Mensal do Turnover — 2025 × 2026'), '(página) Admin (bypass): padrão = ano atual × ano anterior');

    // ---- não-regressão de outros módulos ------------------------------------------------------------------------------------
    $pa = (new PeopleAnalyticsService())->montarPainel([], new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
    $check(isset($pa['turnover']['geral_percentual']) && is_float($pa['turnover']['geral_percentual']), '(regressão) People Analytics continua calculando o Turnover Geral');
    $ri = RhIndicadoresService::montarPainelComContratos($contratosA, new DateTimeImmutable('2004-01-01'), new DateTimeImmutable('2004-12-31'));
    $check(isset($ri['turnover_mensal']) && $ri['turnover_periodo'] === RhIndicadoresService::taxaTurnover(1, 2, 1), '(regressão) Indicadores de RH continua com a mesma fórmula e semântica');
    $check(RhIndicadoresService::taxaTurnover(0, 0, 0) === 0.0, '(regressão) taxaTurnover() sem base continua 0.0 nos dashboards existentes (o "Sem base" é só deste dashboard)');

    echo "\nDASHBOARD_TURNOVER_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
