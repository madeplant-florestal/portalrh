<?php

/**
 * Integração — Nova UI, Bloco E: Pesquisas de Integração + Turnover e Desligamento no AppShell V2. Fixtures ZZPED-* com limpeza em `finally`.
 * Prova, contra o banco:
 *   - módulos de abas `integracao` e `desligamento`: para vários perfis, toda aba visível leva a um destino que o controller REALMENTE aceita
 *     (gate lido do controller; a Central de Pesquisas abre com a permissão de Reação OU a da Integração); o card da Central escolhe o primeiro
 *     destino acessível; nenhum perfil vê aba/card que leve a 403; Admin pelo bypass; nenhuma permissão nova;
 *   - as seis telas administrativas renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader, ModuleTabs e <title>; sem <script>
 *     inline nem handlers inline (confirmações via `data-confirm-message`; scripts do QR via `ui_script_pagina`), sem hex solto nem `responsive-panel`;
 *   - semântica preservada nas fontes: mapa de motivos do Turnover, competência = data de desligamento, gráfico de liderança compacto
 *     (300px, max-w-[780px], escala 1–5, `null` ≠ zero), filtros (Turnover: Ano/Empresa/Cargo; Entrevista: período/unidade/cargo);
 *   - as páginas PÚBLICAS continuam no layout seguro/sem AppShell e sem navegação administrativa; controllers públicos intactos.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

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
$getOriginal = $_GET;
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$rotas = (string)file_get_contents(BASE_PATH . '/index.php');
$fonte = static fn(string $rel): string => (string)file_get_contents(BASE_PATH . '/' . $rel);
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
$codigos = ['dashboard_turnover.visualizar', 'dashboard_entrevista_desligamento.visualizar', 'entrevista_desligamento.visualizar', 'entrevista_desligamento.gerenciar', 'entrevista_desligamento.resultados', 'pesquisa_reacao_integracao.visualizar', 'pesquisa_reacao_integracao.gerenciar', 'integracao_colaborador.visualizar', 'integracao_colaborador.editar'];
$permId = [];
$stmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
foreach ($codigos as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = [], bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZPED ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzped.' . $suffix . '@teste.local', $senha, $role);
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
    $_SESSION['user_name'] = 'ZZPED Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
    $_GET = [];
};
/** O controller de destino aceita este usuário? Lê `requireRole` e cada `requirePermissao` do método; a Central de Pesquisas é a exceção (OU). */
$backendPermite = static function (string $href, array $u) use ($rotas, $corpoDe): bool {
    if (!preg_match("#\\\$router->get\\('" . preg_quote($href, '#') . "', \\[(\\w+)::class, '(\\w+)'\\]\\)#", $rotas, $m)) {
        return false;
    }
    $corpo = $corpoDe($m[1], $m[2]);
    if (preg_match("#Auth::requireRole\\(\\[([^\\]]*)\\]\\)#", $corpo, $r)) {
        $roles = array_map(static fn(string $x): string => trim($x, " '\""), explode(',', $r[1]));
        if (!$u['supervisor'] && !in_array($u['role'], $roles, true)) {
            return false;
        }
    }
    if ($m[1] === 'AdminPesquisaReacaoIntegracaoController' && $m[2] === 'index') {
        return Authorization::usuarioTemPermissao($u['id'], 'pesquisa_reacao_integracao.visualizar') || Authorization::usuarioTemPermissao($u['id'], 'integracao_colaborador.visualizar');
    }
    if (preg_match_all("#Authorization::requirePermissao\\((?:'([^']+)'|self::(PERM_[A-Z]+))\\)#", $corpo, $ps, PREG_SET_ORDER)) {
        foreach ($ps as $p) {
            $codigo = $p[1] !== '' ? $p[1] : (string)(new ReflectionClassConstant($m[1], $p[2]))->getValue();
            if (!Authorization::usuarioTemPermissao($u['id'], $codigo)) {
                return false;
            }
        }
    }
    return true;
};

try {
    $perfis = [
        'Admin' => $novoUsuario('Admin', 'admin'),
        'RH sem permissão' => $novoUsuario('RH sem permissao', 'rh'),
        'viewer sem permissão' => $novoUsuario('Viewer', 'viewer'),
        'supervisor sem permissão' => $novoUsuario('Supervisor', 'viewer', [], true),
        'só turnover' => $novoUsuario('So Turnover', 'viewer', ['dashboard_turnover.visualizar']),
        'só dashboard entrevista' => $novoUsuario('So DashEnt', 'viewer', ['dashboard_entrevista_desligamento.visualizar']),
        'só entrevistas' => $novoUsuario('So Entrevistas', 'viewer', ['entrevista_desligamento.visualizar']),
        'só reação' => $novoUsuario('So Reacao', 'viewer', ['pesquisa_reacao_integracao.visualizar']),
        'só integração' => $novoUsuario('So Integracao', 'viewer', ['integracao_colaborador.visualizar']),
        'RH com tudo' => $novoUsuario('RH Tudo', 'rh', $codigos),
    ];

    // ---- abas e cards ------------------------------------------------------------------------------------------------------------
    $modulos = PortalNavegacaoService::definicaoAbas();
    $check(array_column($modulos['integracao']['abas'], 'chave') === ['pesquisas', 'qr'] && array_column($modulos['desligamento']['abas'], 'chave') === ['turnover', 'dashboard-entrevista', 'entrevistas'], '(abas) Integração: Pesquisas e resultados | QR; Turnover e Desligamento: Turnover | Dashboard da Entrevista | Entrevistas — sem megabarra');
    $problemas = [];
    $visiveis = [];
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        foreach (['integracao', 'desligamento'] as $mod) {
            $abas = (new PortalNavegacaoService())->abas($mod);
            $visiveis[$rotulo][$mod] = array_column($abas, 'chave');
            foreach ($abas as $aba) {
                if (!$backendPermite($aba['href'], $u)) {
                    $problemas[] = "{$rotulo}: aba \"{$aba['label']}\" levaria a 403";
                }
            }
        }
        foreach ((new PortalNavegacaoService())->modulos() as $card) {
            if (in_array($card['chave'], ['integracao', 'desligamento'], true) && !$backendPermite($card['href'], $u)) {
                $problemas[] = "{$rotulo}: card \"{$card['titulo']}\" levaria a 403 ({$card['href']})";
            }
        }
    }
    $check($problemas === [], '(regra de ouro) Toda aba e todo card do bloco leva a um destino que o controller realmente aceita, para ' . count($perfis) . ' perfis' . ($problemas === [] ? '' : ': ' . implode(' | ', $problemas)));
    $check($visiveis['Admin'] === ['integracao' => ['pesquisas', 'qr'], 'desligamento' => ['turnover', 'dashboard-entrevista', 'entrevistas']], '(abas) Admin vê tudo (bypass central)');
    $check($visiveis['RH sem permissão'] === ['integracao' => [], 'desligamento' => []] && $visiveis['viewer sem permissão'] === ['integracao' => [], 'desligamento' => []] && $visiveis['supervisor sem permissão'] === ['integracao' => [], 'desligamento' => []], '(abas) RH, viewer e supervisor SEM permissão individual não veem nenhuma aba (o backend exige a permissão; supervisor não ganha escopo extra)');
    $check($visiveis['só turnover']['desligamento'] === ['turnover'] && $visiveis['só dashboard entrevista']['desligamento'] === ['dashboard-entrevista'] && $visiveis['só entrevistas']['desligamento'] === ['entrevistas'], '(abas) Cada permissão de Turnover/Desligamento libera só a própria aba');
    $check($visiveis['só reação']['integracao'] === ['pesquisas'] && $visiveis['só integração']['integracao'] === ['pesquisas', 'qr'], '(abas) Reação abre só a Central; Integração abre a Central (resultados por QR) e o QR');
    $comoUsuario($perfis['só integração']);
    $cardInteg = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $c): bool => $c['chave'] === 'integracao'));
    $comoUsuario($perfis['só entrevistas']);
    $cardDesl = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $c): bool => $c['chave'] === 'desligamento'));
    $check(($cardInteg[0]['href'] ?? '') === '/admin/pesquisa-integracao-qr' && ($cardDesl[0]['href'] ?? '') === '/admin/entrevistas-desligamento', '(Central) Os cards apontam para o primeiro destino realmente acessível (integração-só → QR; entrevistas-só → Entrevistas)');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova foi criada');

    // ---- páginas administrativas --------------------------------------------------------------------------------------------------
    $comoUsuario($perfis['Admin']);
    $paginas = [
        'Dashboard de Turnover' => [static fn() => (new AdminDashboardTurnoverController())->index(), 'Turnover e Desligamento', 'Turnover'],
        'Dashboard da Entrevista' => [static fn() => (new AdminDashboardEntrevistaDesligamentoController())->index(), 'Turnover e Desligamento', 'Dashboard da Entrevista'],
        'Entrevistas (admin)' => [static fn() => (new AdminEntrevistaDesligamentoController())->index(), 'Turnover e Desligamento', 'Entrevistas de Desligamento'],
        'Central de Pesquisas' => [static fn() => (new AdminPesquisaReacaoIntegracaoController())->index(), 'Integração', 'Pesquisas e resultados'],
        'QR da Integração' => [static fn() => (new AdminPesquisaIntegracaoQrController())->index(), 'Integração', 'QR Code da Integração'],
    ];
    $htmls = [];
    foreach ($paginas as $nome => [$acao, $modulo, $abaAtiva]) {
        ui_titulo_pagina(null, true);
        ui_script_pagina(null, true);
        $comoUsuario($perfis['Admin']);
        $html = $renderizar($acao);
        $htmls[$nome] = $html;
        $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html) && str_contains($html, 'data-app-shell-v2') && !str_contains($html, 'data-admin-sidebar') && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, '<h1') && str_contains($html, '<title>'), "(shell) \"{$nome}\": AppShell V2, sem sidebar antiga, com breadcrumb, PageHeader e <title>, sem Warning/Notice");
        $check(str_contains($html, 'aria-label="' . $modulo . '"') && preg_match('#aria-current="page"[^>]*>' . preg_quote($abaAtiva, '#') . '<#', $html) === 1, "(abas) \"{$nome}\": ModuleTabs \"{$modulo}\" com a aba \"{$abaAtiva}\" ativa");
        $check(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html) && !preg_match('/\son(click|submit|change|load|input|keyup|keydown)\s*=/i', $html), "(CSP) \"{$nome}\": sem <script> inline nem handlers inline");
    }
    $check(str_contains($htmls['QR da Integração'], '/assets/qrcode.js') && str_contains($htmls['QR da Integração'], '/assets/integracao-qr.js') && strpos($htmls['QR da Integração'], 'qrcode.js') < strpos($htmls['QR da Integração'], 'integracao-qr.js') && str_contains($htmls['QR da Integração'], 'data-qr-render="1"') && str_contains($htmls['QR da Integração'], 'data-copy-target="#url-publica"') && str_contains($htmls['QR da Integração'], 'data-print="1"') && str_contains($htmls['QR da Integração'], 'id="qr-print-area"'), '(QR) Scripts locais registrados no <head> na ordem correta; QR, cópia, impressão e área de impressão preservados');
    $check(str_contains($htmls['Central de Pesquisas'], 'name="csrf"') && str_contains($htmls['Central de Pesquisas'], 'Pesquisa de Reação') && str_contains($htmls['Central de Pesquisas'], 'Pesquisa de Integração'), '(Central de Pesquisas) Os dois blocos independentes e o CSRF do formulário de campanha continuam');
    foreach (['Dashboard de Turnover' => ['name="ano"', 'name="empresa"', 'name="cargo"', 'data-autosubmit="1"'], 'Dashboard da Entrevista' => ['name="inicio"', 'name="fim"', 'name="unidade"', 'name="cargo"']] as $nome => $campos) {
        $ok = true;
        foreach ($campos as $campo) {
            $ok = $ok && str_contains($htmls[$nome], $campo);
        }
        $check($ok && !preg_match('/name="(setor|area|gestor)"/i', $htmls[$nome]), "(filtros) \"{$nome}\" preserva seus filtros (" . implode(', ', $campos) . ') e não ganhou Setor/Área/Gestor');
    }

    // ---- fontes: sem hex de classe, sem responsive-panel, sem handlers ------------------------------------------------------------
    foreach (['dashboard-entrevista-desligamento.php', 'dashboard-turnover.php', 'entrevista_desligamento/index.php', 'entrevista_desligamento/resultado.php', 'pesquisa_integracao_qr/index.php', 'pesquisa_integracao_qr/resultados.php', 'pesquisa_reacao_integracao/index.php', 'pesquisa_reacao_integracao/resultados.php'] as $v) {
        $c = $fonte('app/views/admin/' . $v);
        $check(!str_contains($c, 'mobile-table-desktop') || str_contains($c, 'responsive-card-list'), "(mobile) admin/{$v}: nenhuma tabela escondida abaixo de 769px sem lista de cards alternativa (o CSS global de .mobile-table-desktop a esconderia)");
        $check(!preg_match('/(?:bg|text|border|ring|from|to)-\[#/', $c) && !str_contains($c, 'responsive-panel') && !str_contains($c, 'responsive-header') && !preg_match('/\son(click|submit|change|input|load)\s*=/i', $c) && str_contains($c, 'modulo-topo.php') && preg_match('/^<div class="space-y-/m', $c) === 1, "(tokens) admin/{$v}: sem classe hex, sem responsive-panel/header, sem handler inline, topo padrão do módulo");
    }
    foreach (['AdminDashboardEntrevistaDesligamentoController', 'AdminDashboardTurnoverController', 'AdminEntrevistaDesligamentoController', 'AdminPesquisaIntegracaoQrController', 'AdminPesquisaIntegracaoResultadosController', 'AdminPesquisaReacaoIntegracaoController'] as $ctl) {
        $c = $fonte("app/controllers/{$ctl}.php");
        $check(!str_contains($c, "'layouts/admin'") && str_contains($c, "'layouts/app-shell'") && substr_count($c, 'requirePermissao(') >= 1 && substr_count($c, 'Auth::requireRole(') >= 1, "(controller) {$ctl}: só trocou o layout; gates de role e permissão preservados");
    }

    // ---- semântica preservada (fontes de cálculo intactas) ------------------------------------------------------------------------
    $dashEnt = $fonte('app/views/admin/dashboard-entrevista-desligamento.php');
    $check(str_contains($dashEnt, 'max-w-[780px]') && str_contains($dashEnt, "['min' => 1, 'max' => 5]") && str_contains($dashEnt, 'zero real (0.0) NÃO é ausência de base') && str_contains($dashEnt, 'Sem base no período selecionado') && str_contains($dashEnt, '<strong>data de desligamento</strong> (não a data da resposta)') && str_contains($dashEnt, 'Nesta versão não há análise por tipo de desligamento, Área ou Gestor'), '(Dashboard da Entrevista) Gráfico de liderança compacto (max-w-780, escala 1–5, zero real ≠ ausência), competência = data de desligamento, sem Área/Gestor/tipo');
    $check(str_contains($dashEnt, 'Cobertura da pesquisa') && str_contains($dashEnt, 'Entrevista gerada (desligamentos elegíveis)') && str_contains($dashEnt, 'Entrevista respondida (geradas)') && str_contains($dashEnt, 'Falecimento') && str_contains($dashEnt, 'somente entrevistas respondidas'), '(Dashboard da Entrevista) Cobertura (elegíveis → geradas → respondidas), Falecimento fora e denominadores explícitos');
    $svcTurn = $fonte('app/services/TurnoverDashboardService.php');
    $check(TurnoverDashboardService::MAPA_MOTIVOS === ['Voluntário' => ['003', '006'], 'Involuntário' => ['002', '007'], 'Justa Causa' => ['001'], 'Término de Contrato' => ['005', '008'], 'Acordo' => ['016']] && TurnoverDashboardService::ORDEM_CATEGORIAS[count(TurnoverDashboardService::ORDEM_CATEGORIAS) - 1] === TurnoverDashboardService::CATEGORIA_OUTROS, '(Turnover) O mapa de motivos (Voluntário 003/006; Involuntário 002/007; Justa Causa 001; Término 005/008; Acordo 016; o resto — 020, 046, desconhecido, vazio — em Outros) continua idêntico no service');
    $turnView = $fonte('app/views/admin/dashboard-turnover.php');
    $check(str_contains($turnView, 'desligamentos ÷ média do headcount (início e fim do período) × 100') && !preg_match('/name="(setor|area)"/i', $turnView) && str_contains($turnView, 'bases pequenas geram percentuais altos'), '(Turnover) Fórmula oficial documentada na tela; filtros só Ano/Empresa/Cargo; bases pequenas continuam visíveis');
    foreach (['DashboardEntrevistaDesligamentoService', 'TurnoverDashboardService', 'EntrevistaDesligamentoService', 'PesquisaReacaoIntegracaoService', 'PesquisaIntegracaoQrService'] as $svc) {
        $check(!str_contains($fonte("app/services/{$svc}.php"), 'app-shell') && !str_contains($fonte("app/services/{$svc}.php"), 'ui_'), "(services) {$svc} não recebeu nenhuma dependência de UI — cálculo intacto");
    }

    // ---- páginas públicas: layout seguro, sem AppShell, sem navegação administrativa -----------------------------------------------
    foreach (['EntrevistaDesligamentoController' => 'layouts/publico-seguro', 'PesquisaIntegracaoController' => null, 'PesquisaIntegracaoQrController' => null, 'PesquisaReacaoIntegracaoController' => null] as $ctl => $layout) {
        $c = $fonte("app/controllers/{$ctl}.php");
        $check(!str_contains($c, 'app-shell') && ($layout === null || str_contains($c, $layout)), "(pública) {$ctl} não usa o AppShell V2" . ($layout !== null ? " e mantém {$layout}" : ''));
    }
    foreach (['entrevista_desligamento/publica.php', 'pesquisa_integracao/show.php', 'pesquisa_integracao_qr/show.php', 'pesquisa_reacao/show.php'] as $v) {
        $c = $fonte('app/views/' . $v);
        $check(!str_contains($c, 'modulo-topo') && !str_contains($c, 'ui-shell') && !str_contains($c, '/admin') && !preg_match('/<script(?![^>]*\bsrc=)/i', $c) && !preg_match('/\son(click|submit|change|input|load)\s*=/i', $c), "(pública) {$v}: sem navegação/AppShell, sem link administrativo, sem script/handler inline");
    }
    $seguro = $fonte('app/views/layouts/publico-seguro.php');
    $check(str_contains($seguro, 'no-referrer') && str_contains($seguro, 'noindex') && !preg_match('#(?:src|href)="https?://#i', $seguro), '(pública) publico-seguro: no-referrer, noindex e nenhum recurso externo');

    echo "\nPORTAL_MODULO_PESQUISAS_DESLIGAMENTO_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    $_GET = $getOriginal;
    if ($criados !== []) {
        $lu = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
