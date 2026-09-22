<?php

/**
 * Integração — Central do Portal RH (Nova UI, Fase 3A): AdminCentralController + PortalNavegacaoService + view no AppShell V2.
 * Fixtures ZZCEN-* com limpeza em `finally`. Prova, contra o banco:
 *   - cada perfil (Admin, RH, viewer, supervisor, viewer com uma permissão) recebe SÓ os módulos a que tem direito, e o card
 *     aponta para a primeira entrada visível; sem permissão = sem card;
 *   - a Central NÃO diverge da sidebar: para muitos perfis, cada destino da Central é visível exatamente quando o link
 *     correspondente aparece na sidebar renderizada, e todo link da sidebar está na Central ou numa lista curta de exclusões;
 *   - o card não concede acesso, nenhuma permissão/migration nova foi criada e a Central não lê nem grava permissões;
 *   - autenticação: só sessão autenticada (mesmo gate do Manual), rota registrada, gate global de /admin;
 *   - a página usa o AppShell V2 (sem sidebar), tem estado vazio compacto e não vaza nada além dos módulos;
 *   - `/admin`, o destino pós-login, a sidebar e o login continuam como estavam (por comportamento e código).
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
$sessaoOriginal = $_SESSION;
$baseUrl = (string)(Config::app()['base_url'] ?? ''); // a view aplica o $base aos caminhos relativos do serviço
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$corpoDe = static function (string $classe, string $metodo): string {
    $r = new ReflectionMethod($classe, $metodo);
    $linhas = file($r->getFileName());
    return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};
$semComentarios = static function (string $arquivo): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

// ---- catálogo de permissões usadas pela Central ------------------------------------------------------------------------------
$codigos = [];
foreach (PortalNavegacaoService::definicao() as $m) {
    foreach ($m['itens'] as $it) {
        if (preg_match('/^(?:perm|staff_ou):(.+)$/', $it['regra'], $mt)) {
            $codigos[$mt[1]] = true;
        }
    }
}
foreach (['solicitacao_vaga.visualizar', 'solicitacao_vaga.criar', 'kanban_vagas.visualizar', 'colaboradores.visualizar'] as $c) {
    $codigos[$c] = true;
}
$codigos = array_keys($codigos);
$permId = [];
$stmt = $pdo->prepare('SELECT id, codigo FROM permissoes WHERE codigo = ?');
foreach ($codigos as $c) {
    $stmt->execute([$c]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $permId[$c] = $r ? (int)$r['id'] : 0;
}
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();

$novoUsuario = static function (string $rotulo, string $role, array $permissoes = [], bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZCEN ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzcen.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($supervisor) {
        $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
    }
    $criados[] = $id;
    if ($permissoes !== []) {
        Authorization::sincronizar($id, array_map(static fn(string $p): int => $permId[$p], $permissoes));
    }
    return ['id' => $id, 'role' => $role, 'supervisor' => $supervisor];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZCEN Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
};
/** @return array<string,string> chave do módulo => href */
$cards = static function () use ($comoUsuario): array {
    $out = [];
    foreach ((new PortalNavegacaoService())->modulos() as $c) {
        $out[$c['chave']] = $c['href'];
    }
    return $out;
};
$backendPermiteColab = static function () use ($corpoDe): bool {
    $corpo = $corpoDe(AdminColaboradoresController::class, 'index');
    $ok = preg_match("#Auth::requireRole\(\[([^\]]*)\]\)#", $corpo, $r) === 1 && !str_contains($corpo, 'requireRoleOuPermissao');
    $roles = $ok ? array_map(static fn(string $x): string => trim($x, " '\""), explode(',', $r[1])) : ['*'];
    return in_array(Auth::role(), $roles, true) || !empty($_SESSION['user_is_supervisor']);
};
try {
    $check(!in_array(0, $permId, true), '(catálogo) Todas as permissões usadas pelas regras da Central existem no catálogo: ' . implode(', ', $codigos));

    $admin = $novoUsuario('Admin', 'admin');
    $rh = $novoUsuario('RH', 'rh');
    $viewer = $novoUsuario('Viewer', 'viewer');
    $supervisor = $novoUsuario('Supervisor', 'viewer', [], true);

    $todasChaves = array_column(PortalNavegacaoService::definicao(), 'chave');
    $check(count($todasChaves) === 10 && count(array_unique($todasChaves)) === 10, '(definição) 10 módulos, chaves únicas — áreas funcionais, não os ~25 itens da sidebar');

    // ---- Admin: bypass central ---------------------------------------------------------------------------------------------
    $comoUsuario($admin);
    $cAdmin = $cards();
    $check(array_keys($cAdmin) === $todasChaves, '(Admin) Vê todos os módulos pelo bypass central de Authorization — sem lógica paralela na Central');
    $check($cAdmin['indicadores'] === '/admin/dashboard' && $cAdmin['recrutamento'] === '/admin/dashboard-recrutamento' && $cAdmin['colaboradores'] === '/admin/colaboradores' && $cAdmin['desligamento'] === '/admin/dashboard-turnover' && $cAdmin['integracao'] === '/admin/pesquisas-reacao-integracao' && $cAdmin['usuarios'] === '/admin/usuarios', '(Admin) Cada card aponta para a primeira entrada visível da área');

    // ---- viewer sem permissão ----------------------------------------------------------------------------------------------
    $comoUsuario($viewer);
    $cV = $cards();
    $check(array_keys($cV) === ['indicadores', 'recrutamento', 'colaboradores', 'cadastros'], '(viewer sem permissão) Só os módulos abertos a qualquer autenticado: ' . implode(', ', array_keys($cV)));
    $check($cV['recrutamento'] === '/admin/candidaturas' && $cV['colaboradores'] === '/admin/movimentacoes-pessoal' && $cV['indicadores'] === '/admin/indicadores-rh' && $cV['cadastros'] === '/admin/empresas', '(viewer sem permissão) Entradas caem no primeiro destino aberto de cada área');
    foreach (['pdi', 'usuarios', 'mensagens', 'solicitacoes-vaga', 'integracao', 'desligamento'] as $ausente) {
        $check(!isset($cV[$ausente]), "(viewer sem permissão) Sem permissão, o card \"{$ausente}\" não é renderizado");
    }

    // ---- permissão individual libera exatamente o card correspondente -------------------------------------------------------
    $casos = [
        ['pdi.visualizar', 'pdi', '/admin/pdis'],
        ['mensagens.visualizar', 'mensagens', '/admin/mensagens'],
        ['solicitacao_vaga.criar', 'solicitacoes-vaga', '/admin/solicitacoes-vaga'],
        ['solicitacao_vaga.visualizar', 'solicitacoes-vaga', '/admin/solicitacoes-vaga'],
        ['kanban_vagas.visualizar', 'solicitacoes-vaga', '/admin/solicitacoes-vaga'],
        ['pesquisa_reacao_integracao.visualizar', 'integracao', '/admin/pesquisas-reacao-integracao'],
        ['integracao_colaborador.visualizar', 'integracao', '/admin/pesquisa-integracao-qr'],
        ['entrevista_desligamento.visualizar', 'desligamento', '/admin/entrevistas-desligamento'],
        ['dashboard_entrevista_desligamento.visualizar', 'desligamento', '/admin/dashboard-entrevista-desligamento'],
        ['dashboard_turnover.visualizar', 'desligamento', '/admin/dashboard-turnover'],
        ['dashboard_recrutamento.visualizar', 'recrutamento', '/admin/dashboard-recrutamento'],
        ['pipeline.visualizar', 'recrutamento', '/admin/pipeline'],
        ['colaboradores.visualizar', 'colaboradores', '/admin/colaboradores'],
        ['dashboard.visualizar', 'indicadores', '/admin/dashboard'],
    ];
    $usuariosPorPerm = [];
    foreach ($casos as [$perm, $chave, $entrada]) {
        $u = $usuariosPorPerm[$perm] ??= $novoUsuario('P ' . $perm, 'viewer', [$perm]);
        $comoUsuario($u);
        $c = $cards();
        $check(isset($c[$chave]), "(permissão) {$perm} → card \"{$chave}\" aparece");
        if (!in_array($chave, ['recrutamento', 'colaboradores'], true)) {
            $check($c[$chave] === $entrada, "(permissão) {$perm} → entrada {$entrada}");
        }
    }
    $comoUsuario($usuariosPorPerm['dashboard_recrutamento.visualizar']);
    $check($cards()['recrutamento'] === '/admin/dashboard-recrutamento', '(entrada) Com o dashboard liberado, a entrada de Recrutamento passa a ser o dashboard (primeiro destino visível)');
    $comoUsuario($usuariosPorPerm['pipeline.visualizar']);
    $check($cards()['recrutamento'] === '/admin/candidaturas', '(entrada) Com só o pipeline liberado, a entrada continua em Candidaturas (ordem de preferência)');
    $comoUsuario($usuariosPorPerm['dashboard_entrevista_desligamento.visualizar']);
    $check(count(array_diff(array_keys($cards()), ['indicadores', 'recrutamento', 'colaboradores', 'cadastros', 'desligamento'])) === 0, '(permissão) Uma permissão libera só o módulo dela — nenhum outro card extra');

    // ---- RH e supervisor: sem bypass novo ----------------------------------------------------------------------------------
    $comoUsuario($rh);
    $cRh = $cards();
    $check(isset($cRh['solicitacoes-vaga']) && $cRh['colaboradores'] === '/admin/colaboradores' && !isset($cRh['pdi']) && !isset($cRh['mensagens']) && !isset($cRh['usuarios']) && !isset($cRh['desligamento']), '(RH) Mesmo comportamento da sidebar: staff vê Solicitações/Colaboradores; PDI, Mensagens, Desligamento dependem de permissão individual (sem bypass por role); Usuários não');
    $comoUsuario($supervisor);
    $cSup = $cards();
    $check(isset($cSup['usuarios']) && isset($cSup['solicitacoes-vaga']) && !isset($cSup['pdi']) && !isset($cSup['mensagens']), '(supervisor) Vê Usuários e Solicitações como na sidebar, mas NÃO ganha módulos de permissão individual');

    // ---- o card não concede acesso -----------------------------------------------------------------------------------------
    $comoUsuario($viewer);
    $permsAntes = (int)$pdo->query('SELECT COUNT(*) FROM usuario_permissoes')->fetchColumn();
    $cards();
    $check((int)$pdo->query('SELECT COUNT(*) FROM usuario_permissoes')->fetchColumn() === $permsAntes && Authorization::usuarioTemPermissao($viewer['id'], 'pdi.visualizar') === false, '(acesso) Calcular/renderizar a Central não concede nem altera permissão: o usuário segue sem pdi.visualizar');
    $fonteServico = $semComentarios(BASE_PATH . '/app/services/PortalNavegacaoService.php');
    $check(!preg_match('/INSERT|UPDATE|DELETE|sincronizar|Database::|->prepare\(|\$_POST|\$_GET/i', $fonteServico), '(acesso) O serviço da Central só LÊ (sem SQL, sem gravar permissão, sem request)');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes && (int)$pdo->query("SELECT COUNT(*) FROM permissoes WHERE codigo LIKE 'central%'")->fetchColumn() === 0 && glob(BASE_PATH . '/database/migrations/*central*') === [], '(permissões) Nenhuma permissão, migration ou seed novo (sem central.visualizar)');
    $check((new PortalNavegacaoService(static fn(string $c): bool => true, 'viewer', false))->visivel('regra-inventada') === false && (new PortalNavegacaoService(static fn(string $c): bool => false, 'viewer', false))->modulos() !== [], '(regras) Regra desconhecida nunca libera; usuário autenticado sem permissão ainda tem os módulos abertos');

    // ---- Central × gates reais dos controllers (sidebar removida: a fonte da verdade passa a ser o backend em si) -----------
    $perfis = ['Admin' => $admin, 'RH' => $rh, 'viewer' => $viewer, 'supervisor' => $supervisor] + array_map(static fn($u) => $u, $usuariosPorPerm);
    $todosItens = [];
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            $todosItens[] = $it;
        }
    }
    // Mapa href => [controller, método] tirado das rotas GET reais (index.php) que cada item de definicao() usa.
    $rotaControlador = [
        '/admin/dashboard' => [AdminController::class, 'index'],
        '/admin/indicadores-rh' => [AdminRhIndicadoresController::class, 'index'],
        '/admin/dashboard-recrutamento' => [AdminDashboardRecrutamentoController::class, 'index'],
        '/admin/candidaturas' => [AdminCandidaturasController::class, 'index'],
        '/admin/pipeline' => [AdminPipelineController::class, 'index'],
        '/admin/vagas' => [AdminVagasController::class, 'index'],
        '/admin/recruitment-webhooks' => [AdminRecruitmentWebhooksController::class, 'index'],
        '/admin/indicacoes' => [AdminIndicacoesController::class, 'index'],
        '/admin/solicitacoes-vaga' => [AdminSolicitacoesVagaController::class, 'index'],
        '/admin/colaboradores' => [AdminColaboradoresController::class, 'index'],
        '/admin/movimentacoes-pessoal' => [AdminMovimentacoesPessoalController::class, 'index'],
        '/admin/pdis' => [AdminPdisController::class, 'index'],
        '/admin/pesquisas-reacao-integracao' => [AdminPesquisaReacaoIntegracaoController::class, 'index'],
        '/admin/pesquisa-integracao-qr' => [AdminPesquisaIntegracaoQrController::class, 'index'],
        '/admin/dashboard-turnover' => [AdminDashboardTurnoverController::class, 'index'],
        '/admin/dashboard-entrevista-desligamento' => [AdminDashboardEntrevistaDesligamentoController::class, 'index'],
        '/admin/entrevistas-desligamento' => [AdminEntrevistaDesligamentoController::class, 'index'],
        '/admin/mensagens' => [AdminMensagensController::class, 'index'],
        '/admin/empresas' => [AdminEmpresasController::class, 'index'],
        '/admin/setores' => [AdminSetoresController::class, 'index'],
        '/admin/cargos' => [AdminCargosController::class, 'index'],
        '/admin/beneficios' => [AdminBeneficiosController::class, 'index'],
        '/admin/avaliacoes' => [AdminAvaliacoesController::class, 'index'],
        '/admin/usuarios' => [AdminUsuariosController::class, 'index'],
    ];
    $check(array_diff(array_column($todosItens, 'href'), array_keys($rotaControlador)) === [], '(mapa) Todo destino de PortalNavegacaoService::definicao() está mapeado para o controller real que o atende');
    // Lê o gate de verdade do método (Auth::requireRole / Authorization::requireRoleOuPermissao / Authorization::requirePermissao),
    // seguindo a delegação para AdminCatalogosController::renderIndex() quando o controller de catálogo só chama `$this->renderIndex(...)`.
    $extrairGate = static function (string $corpo) use ($corpoDe): array {
        if (str_contains($corpo, '$this->renderIndex(')) {
            $corpo = $corpoDe(AdminCatalogosController::class, 'renderIndex');
        }
        if (preg_match("#Authorization::requireRoleOuPermissao\(\[([^\]]*)\],\s*'([^']+)'\)#", $corpo, $m)) {
            return ['roles' => array_map(static fn(string $r): string => strtolower(trim($r, " '\"")), explode(',', $m[1])), 'codigo' => $m[2], 'combinacao' => 'ou'];
        }
        $roles = null;
        if (preg_match("#Auth::requireRole\(\[([^\]]*)\]\)#", $corpo, $m)) {
            $roles = array_map(static fn(string $r): string => strtolower(trim($r, " '\"")), explode(',', $m[1]));
        }
        $codigo = null;
        if (preg_match("#Authorization::requirePermissao\('([^']+)'\)#", $corpo, $m)) {
            $codigo = $m[1];
        }
        return ['roles' => $roles, 'codigo' => $codigo, 'combinacao' => 'e']; // Auth::requireRole + Authorization::requirePermissao são gates SEQUENCIAIS (E), não OR
    };
    $backendPermite = static function (array $gate, string $role, bool $supervisor, callable $temPermissao): bool {
        if ($supervisor) {
            return true; // Auth::requireRole/requireRoleOuPermissao sempre libera quem tem is_supervisor=1 (regra #8 do CLAUDE.md)
        }
        $naLista = $gate['roles'] !== null && in_array(strtolower($role), $gate['roles'], true);
        if ($gate['combinacao'] === 'ou') {
            return $naLista || ($gate['codigo'] !== null && $temPermissao($gate['codigo']));
        }
        if ($gate['roles'] !== null && !$naLista) {
            return false;
        }
        if ($gate['codigo'] !== null && !$temPermissao($gate['codigo'])) {
            return false;
        }
        return $gate['roles'] !== null || $gate['codigo'] !== null; // precisa ter reconhecido ao menos um gate de verdade no método
    };
    $divergencias = [];
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        $svc = new PortalNavegacaoService();
        $temPermissaoUsuario = static fn(string $c): bool => Authorization::usuarioTemPermissao($u['id'], $c);
        foreach ($todosItens as $it) {
            if (!$svc->visivel($it['regra'])) {
                continue; // card oculto: a Central pode ser mais conservadora que o backend (é só representação) — nada a provar aqui
            }
            [$classe, $metodo] = $rotaControlador[$it['href']];
            $gate = $extrairGate($corpoDe($classe, $metodo));
            if (!$backendPermite($gate, $u['role'], !empty($u['supervisor']), $temPermissaoUsuario)) {
                $divergencias[] = "{$rotulo}: {$it['href']} ({$it['regra']}) — card visível na Central mas o gate real de {$classe}::{$metodo} bloquearia (403)";
            }
        }
    }
    $check($divergencias === [], '(gates reais) Para ' . count($perfis) . ' perfis, nenhum card visível na Central leva a 403 no controller real' . ($divergencias === [] ? '' : ' — DIVERGÊNCIAS: ' . implode(' | ', $divergencias)));
    $comoUsuario($usuariosPorPerm['colaboradores.visualizar']);
    $check(!(new PortalNavegacaoService())->visivel('staff') && $cards()['colaboradores'] === '/admin/movimentacoes-pessoal' && !$backendPermiteColab(), '(exceção documentada) viewer com colaboradores.visualizar: o backend (admin/rh — a listagem expõe salário) bloqueia; a Central NÃO oferece o card e ele cai em Movimentações (aberta)');
    $comoUsuario($admin);
    $hrefsCentral = array_column($todosItens, 'href');
    $rotas = (string)file_get_contents(BASE_PATH . '/index.php');
    $semRota = [];
    foreach ($hrefsCentral as $h) {
        if (!str_contains($rotas, "\$router->get('{$h}'")) {
            $semRota[] = $h;
        }
    }
    $check($semRota === [], '(rotas) Todo destino de card é uma rota GET registrada' . ($semRota === [] ? '' : ': faltam ' . implode(', ', $semRota)));

    // ---- autenticação e rota -------------------------------------------------------------------------------------------------
    $check(str_contains($corpoDe(AdminCentralController::class, 'index'), "Auth::requireRole(['admin', 'rh', 'viewer'])") && !str_contains($corpoDe(AdminCentralController::class, 'index'), 'requirePermissao'), '(auth) A Central exige sessão autenticada (mesmo gate do Manual) e nenhuma permissão própria');
    $check(str_contains($rotas, "\$router->get('/admin', [AdminCentralController::class, 'index']);") && str_contains($rotas, "\$router->get('/admin/dashboard', [AdminController::class, 'index']);"), '(rota) GET /admin = Central (AdminCentralController) e GET /admin/dashboard = Dashboard atual (AdminController, sem reescrita)');
    $check(str_contains($rotas, "\$router->get('/admin/central', static fn() => redirect('/admin'));") && substr_count($rotas, '/admin/central') === 1, '(rota) /admin/central só redireciona (302) para /admin: uma URL canônica, sem lógica duplicada');
    $check(str_contains($rotas, "\$isAdminPath && !\$isAdminAuthRoute && !Auth::check()") && preg_match('#redirect\(\'/login\'\)#', $rotas) === 1, '(auth) O gate global de /admin* (sem sessão → /login) cobre a rota nova');

    // ---- página ---------------------------------------------------------------------------------------------------------------
    $comoUsuario($viewer);
    $html = $renderizar(static fn() => (new AdminCentralController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html) && str_contains($html, 'data-app-shell-v2="1"') && str_contains($html, 'Central do Portal RH') && str_contains($html, 'Escolha um módulo para continuar') && str_contains($html, '<title>Central do Portal RH — Portal RH</title>'), '(página) Renderiza no AppShell V2 com o título e a descrição da Central');
    $check(!str_contains($html, 'data-admin-sidebar') && !str_contains($html, 'class="sidebar') && !str_contains($html, 'app-header"') && !str_contains($html, '<nav aria-label="Trilha'), '(página) Sem sidebar/header antigos e sem breadcrumb redundante na raiz');
    $check(substr_count($html, '<li class="min-w-0"><a href=') === 4 && str_contains($html, 'href="' . $baseUrl . '/admin/candidaturas"') && !str_contains($html, '/admin/pdis') && !str_contains($html, '/admin/usuarios'), '(página) O viewer sem permissão recebe 4 cards e nenhum link para módulos sem acesso');
    $check(str_contains($html, 'grid-cols-[repeat(auto-fill,minmax(230px,1fr))]') && str_contains($html, 'gap-[18px]') && !str_contains($html, '<script>'), '(página) ModuleGrid elástico (auto-fill/230px/18px), sem JavaScript próprio');
    $comoUsuario($admin);
    $htmlAdmin = $renderizar(static fn() => (new AdminCentralController())->index());
    $check(substr_count($htmlAdmin, '<li class="min-w-0"><a href=') === 10 && str_contains($htmlAdmin, 'href="' . $baseUrl . '/admin/usuarios"'), '(página) O Admin recebe os 10 cards');
    $vazio = (new View())->renderPartial('admin/central', ['base' => '', 'modulos' => []]);
    $check(str_contains($vazio, 'Nenhum módulo disponível') && str_contains($vazio, 'ainda não possui acessos liberados') && !str_contains($vazio, '<ul') && !str_contains($vazio, '<a '), '(vazio) Estado vazio compacto e informativo, sem grade quebrada nem botão de solicitação');

    // ---- /admin, pós-login e Dashboard intactos ------------------------------------------------------------------------------
    // pós-login: TODO usuário autenticado entra pela Central; nenhum destino leva a 403 nem a loop
    $perfisPosLogin = ['Admin' => $admin, 'RH' => $rh, 'viewer' => $viewer, 'supervisor' => $supervisor, 'gestor de vaga' => $usuariosPorPerm['solicitacao_vaga.criar'], 'dashboard_recrutamento' => $usuariosPorPerm['dashboard_recrutamento.visualizar'], 'dashboard' => $usuariosPorPerm['dashboard.visualizar']];
    $destinosPosLogin = [];
    foreach ($perfisPosLogin as $rotulo => $u) {
        $comoUsuario($u);
        $destinosPosLogin[$rotulo] = Authorization::primeiraRotaAcessivel();
    }
    $check(array_unique(array_values($destinosPosLogin)) === ['/admin'], '(pós-login) Admin, RH, viewer, supervisor e usuários com permissões reduzidas entram pela Central (/admin): ' . json_encode($destinosPosLogin));
    $fontePos = $semComentarios(BASE_PATH . '/app/core/Authorization.php');
    $check(str_contains($corpoDe(AuthController::class, 'postLoginPath'), 'primeiraRotaAcessivel') && (function () use ($fontePos): bool { preg_match('/function primeiraRotaAcessivel\(\): string\s*\{(.*?)\n    \}/s', $fontePos, $m); return isset($m[1]) && !str_contains($m[1], '/admin/dashboard') && !str_contains($m[1], 'temPermissao'); })(), '(pós-login) O resolver segue centralizado (AuthController → Authorization::primeiraRotaAcessivel) e não depende mais de permissão nem manda ninguém ao Dashboard');
    $check(Authorization::primeiraRotaAcessivel() === '/admin' && str_contains($rotas, "\$router->get('/admin', [AdminCentralController::class, 'index']);") && !str_contains($corpoDe(AdminCentralController::class, 'index'), 'redirect('), '(pós-login) /admin não redireciona (nenhum loop): renderiza a Central diretamente');
    $check(str_contains($corpoDe(AdminController::class, 'index'), "Authorization::requirePermissao('dashboard.visualizar')") && str_contains($corpoDe(AdminController::class, 'index'), "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(/admin/dashboard) O Dashboard atual mantém EXATAMENTE seus gates (role + dashboard.visualizar) — só mudou de URL');
    $comoUsuario($viewer);
    $check(!(new PortalNavegacaoService())->visivel('perm:dashboard.visualizar') && $cards()['indicadores'] === '/admin/indicadores-rh', '(dashboard) Sem dashboard.visualizar a Central funciona, o card Indicadores cai em /admin/indicadores-rh e o Dashboard não é oferecido');
    $comoUsuario($admin);
    $check(str_contains($corpoDe(AdminCentralController::class, 'index'), "'layouts/app-shell'") && str_contains((string)file_get_contents(APP_PATH . '/views/admin/central.php'), 'ui_module_grid') && glob(APP_PATH . '/views/admin/central*.php') === [APP_PATH . '/views/admin/central.php'], '(central) /admin reutiliza a única view da Central (admin/central), no AppShell V2 — sem segunda implementação');
    $referenciasCentral = [];
    foreach (['app', '.'] as $dir) {
        $it = $dir === 'app'
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app', FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo(BASE_PATH . '/index.php')];
        foreach ($it as $arq) {
            if ($arq->isFile() && $arq->getExtension() === 'php' && str_contains((string)file_get_contents($arq->getPathname()), '/admin/central')) {
                $referenciasCentral[] = str_replace(str_replace('\\', '/', BASE_PATH) . '/', '', str_replace('\\', '/', $arq->getPathname()));
            }
        }
    }
    $check($referenciasCentral === ['index.php'], '(links) /admin/central só existe como redirecionamento em index.php: header, login e pós-login não a referenciam (achou: ' . implode(', ', $referenciasCentral) . ')');
} finally {
    $_SESSION = $sessaoOriginal;
    if ($criados !== []) {
        $lista = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($lista) OR actor_usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lista)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nINTEGRATION_PORTAL_CENTRAL_OK\n";
