<?php

/**
 * Integração — Nova UI, Bloco F: Indicadores de RH (People Analytics em /admin/dashboard + Indicadores de RH em /admin/indicadores-rh) no
 * AppShell V2, e correção das tabelas escondidas no mobile das telas JÁ migradas (PDI lista/seleção, Webhooks). Fixtures ZZPIN-* com limpeza.
 * Prova, contra o banco:
 *   - módulo de abas `indicadores` (People Analytics | Indicadores de RH): toda aba visível abre no backend (gate lido do controller);
 *     People Analytics exige `dashboard.visualizar` (nenhuma role o dispensa; Admin por bypass); Indicadores de RH é aberto às roles do gate;
 *     o card da Central aponta para o primeiro destino acessível; nenhuma permissão nova;
 *   - as duas telas renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader, ModuleTabs e <title>; sem <script>/<style> inline nem
 *     handlers; o JS do botão de sincronização é externo (`indicadores-rh.js`, registrado por `ui_script_pagina`), idêntico em public/assets;
 *   - filtros preservados (People Analytics: período/empresa/setor; Indicadores: período/empresa/unidade/cargo/setor/centro de custo);
 *   - semântica preservada: contrato (sem deduplicar por pessoa), mapa de motivos do People Analytics (Voluntário 003/006; Involuntário 001/002/007),
 *     services e controllers sem dependência de UI; gates dos controllers inalterados;
 *   - tabelas: nenhuma tela migrada esconde a tabela no mobile sem lista de cards (Avaliações e Movimentação foram corrigidas no Bloco G).
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
$permDashboard = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'dashboard.visualizar'")->fetchColumn();
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, bool $comDashboard = false, bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permDashboard): array {
    $id = User::create('ZZPIN ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzpin.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($supervisor) {
        $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
    }
    $criados[] = $id;
    if ($comDashboard) {
        Authorization::sincronizar($id, [$permDashboard]);
    }
    return ['id' => $id, 'role' => $role, 'supervisor' => $supervisor];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZPIN Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
    $_GET = [];
};
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
    if (preg_match_all("#Authorization::requirePermissao\\('([^']+)'\\)#", $corpo, $ps)) {
        foreach ($ps[1] as $codigo) {
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
        'RH com dashboard' => $novoUsuario('RH com dashboard', 'rh', true),
        'viewer sem permissão' => $novoUsuario('Viewer', 'viewer'),
        'viewer com dashboard' => $novoUsuario('Viewer Dashboard', 'viewer', true),
        'supervisor sem permissão' => $novoUsuario('Supervisor', 'viewer', false, true),
    ];

    // ---- abas e card da Central ----------------------------------------------------------------------------------------------------
    $def = PortalNavegacaoService::definicaoAbas()['indicadores'];
    $check(array_column($def['abas'], 'chave') === ['people-analytics', 'indicadores-rh'] && $def['trilha_modulo'] === false, '(abas) People Analytics | Indicadores de RH, sem nível de módulo intermediário no breadcrumb');
    $problemas = [];
    $visiveis = [];
    $cards = [];
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        $abas = (new PortalNavegacaoService())->abas('indicadores');
        $visiveis[$rotulo] = array_column($abas, 'chave');
        foreach ($abas as $aba) {
            if (!$backendPermite($aba['href'], $u)) {
                $problemas[] = "{$rotulo}: aba \"{$aba['label']}\" levaria a 403";
            }
        }
        foreach ((new PortalNavegacaoService())->modulos() as $card) {
            if ($card['chave'] === 'indicadores') {
                $cards[$rotulo] = $card['href'];
                if (!$backendPermite($card['href'], $u)) {
                    $problemas[] = "{$rotulo}: card levaria a 403 ({$card['href']})";
                }
            }
        }
    }
    $check($problemas === [], '(regra de ouro) Toda aba e o card de Indicadores levam a um destino que o controller aceita, para ' . count($perfis) . ' perfis' . ($problemas === [] ? '' : ': ' . implode(' | ', $problemas)));
    $check($visiveis['Admin'] === ['people-analytics', 'indicadores-rh'] && $visiveis['RH com dashboard'] === ['people-analytics', 'indicadores-rh'] && $visiveis['viewer com dashboard'] === ['people-analytics', 'indicadores-rh'], '(abas) Admin (bypass) e quem tem dashboard.visualizar veem as duas abas');
    $check($visiveis['RH sem permissão'] === ['indicadores-rh'] && $visiveis['viewer sem permissão'] === ['indicadores-rh'] && $visiveis['supervisor sem permissão'] === ['indicadores-rh'], '(abas) RH, viewer e supervisor SEM dashboard.visualizar veem só Indicadores de RH — nenhuma role dispensa a permissão do People Analytics (supervisor não ganha escopo extra)');
    $check($cards['Admin'] === '/admin/dashboard' && $cards['viewer com dashboard'] === '/admin/dashboard' && $cards['viewer sem permissão'] === '/admin/indicadores-rh' && $cards['supervisor sem permissão'] === '/admin/indicadores-rh', '(Central) O card Indicadores de RH segue apontando para o primeiro destino acessível (dashboard.visualizar → People Analytics; senão → Indicadores de RH)');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova foi criada');

    // ---- páginas ---------------------------------------------------------------------------------------------------------------------
    $comoUsuario($perfis['Admin']);
    $paginas = [
        'People Analytics' => [static fn() => (new AdminController())->index(), 'People Analytics', ['name="periodo"', 'name="empresa"', 'name="setor"']],
        'Indicadores de RH' => [static fn() => (new AdminRhIndicadoresController())->index(), 'Indicadores de RH', ['name="periodo"', 'name="empresa"', 'name="unidade"', 'name="cargo"', 'name="setor"', 'name="centro_custo"']],
    ];
    $htmls = [];
    foreach ($paginas as $nome => [$acao, $abaAtiva, $campos]) {
        ui_titulo_pagina(null, true);
        ui_script_pagina(null, true);
        $comoUsuario($perfis['Admin']);
        $html = $renderizar($acao);
        $htmls[$nome] = $html;
        $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html) && str_contains($html, 'data-app-shell-v2') && !str_contains($html, 'data-admin-sidebar') && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, '<h1') && str_contains($html, '<title>'), "(shell) \"{$nome}\": AppShell V2, sem sidebar antiga, com breadcrumb, PageHeader e <title>, sem Warning/Notice");
        $check(str_contains($html, 'aria-label="Indicadores de RH"') && preg_match('#aria-current="page"[^>]*>' . preg_quote($abaAtiva, '#') . '<#', $html) === 1 && str_contains($html, '/admin/dashboard"') && str_contains($html, '/admin/indicadores-rh"'), "(abas) \"{$nome}\": ModuleTabs com a aba \"{$abaAtiva}\" ativa e o destino irmão navegável");
        $check(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html) && !preg_match('/<style\b/i', $html) && !preg_match('/\son(click|submit|change|load|input|keyup|keydown)\s*=/i', $html), "(CSP) \"{$nome}\": sem <script>/<style> inline nem handlers inline");
        $ok = true;
        foreach ($campos as $campo) {
            $ok = $ok && str_contains($html, $campo);
        }
        $check($ok, "(filtros) \"{$nome}\" preserva os filtros (" . implode(', ', array_map(static fn(string $c): string => trim(str_replace('name=', '', $c), '"'), $campos)) . ')');
    }
    $check(str_contains($htmls['Indicadores de RH'], '/assets/indicadores-rh.js') && str_contains($htmls['Indicadores de RH'], 'data-sync-btn') && str_contains($htmls['Indicadores de RH'], 'data-endpoint=') && str_contains($htmls['Indicadores de RH'], '/admin/indicadores-rh/sincronizar"') && str_contains($htmls['Indicadores de RH'], '/admin/indicadores-rh/sincronizar/status"') && str_contains($htmls['Indicadores de RH'], 'data-classe-ok=') && str_contains($htmls['Indicadores de RH'], 'data-sync-feedback'), '(sincronização) O botão "Atualizar dados" mantém endpoints e feedback por data-attributes; o script é externo e registrado no <head>');
    $check(!str_contains($htmls['People Analytics'], 'indicadores-rh.js'), '(escopo) O script de sincronização só carrega em Indicadores de RH');
    $js = $fonte('assets/indicadores-rh.js');
    $check(is_file(BASE_PATH . '/public/assets/indicadores-rh.js') && $js === $fonte('public/assets/indicadores-rh.js') && str_contains($js, "'/X-Requested-With'") === false && str_contains($js, 'X-Requested-With') && str_contains($js, 'correlacao_id') && str_contains($js, '3 * 60 * 1000') && str_contains($js, '4000') && str_contains($js, 'window.location.reload'), '(JS) indicadores-rh.js: cópia idêntica em public/assets; mesma lógica (POST 202 → polling a cada 4s por até 3 min → recarrega)');

    // ---- fontes ----------------------------------------------------------------------------------------------------------------------
    foreach (['dashboard.php', 'indicadores-rh.php'] as $v) {
        $c = $fonte('app/views/admin/' . $v);
        $check(!preg_match('/(?:bg|text|border|ring|divide|from|to)-\[#/', $c) && !preg_match('/\b(?:bg|text|border|ring)-(?:slate|blue|red|amber|green)-\d+|\bct-btn|bg-ct|text-ct/', $c) && !str_contains($c, 'responsive-panel') && !str_contains($c, 'ind-panel') && !preg_match('/<(script|style)\b/i', $c) && str_contains($c, 'modulo-topo.php'), "(tokens) admin/{$v}: sem hex/paleta legada/ct*, sem responsive-panel nem <style>/<script>, topo padrão do módulo");
    }
    foreach (['AdminController', 'AdminRhIndicadoresController'] as $ctl) {
        $c = $fonte("app/controllers/{$ctl}.php");
        $check(!str_contains($c, "'layouts/admin'") && str_contains($c, "'layouts/app-shell'") && str_contains($c, 'Auth::requireRole([\'admin\', \'rh\', \'viewer\'])'), "(controller) {$ctl}: só trocou o layout; gate de role preservado");
    }
    $check(str_contains($fonte('app/controllers/AdminController.php'), "Authorization::requirePermissao('dashboard.visualizar')") && !str_contains($fonte('app/controllers/AdminRhIndicadoresController.php'), 'requirePermissao'), '(autorização) People Analytics segue com dashboard.visualizar; Indicadores de RH segue SEM permissão individual (só o gate de role) — nada foi ampliado nem restringido');
    $check((new ReflectionClassConstant('PeopleAnalyticsService', 'CODIGOS_VOLUNTARIO'))->getValue() === ['003', '006'] && (new ReflectionClassConstant('PeopleAnalyticsService', 'CODIGOS_INVOLUNTARIO'))->getValue() === ['001', '002', '007'] && TurnoverDashboardService::MAPA_MOTIVOS['Involuntário'] === ['002', '007'], '(People Analytics) Mapa de motivos próprio (Voluntário 003/006; Involuntário 001/002/007) — distinto do Dashboard de Turnover (Involuntário 002/007; 001 = Justa Causa), sem reaproveitamento');
    $pa = $fonte('app/services/PeopleAnalyticsService.php');
    $check(str_contains($pa, 'não são') && str_contains($pa, 'deduplicados por codigo_pessoa') && !str_contains($pa, 'app-shell') && !preg_match('/\bui_[a-z_]+\(/', $pa) && !preg_match('/\bui_[a-z_]+\(/', $fonte('app/services/RhIndicadoresService.php')), '(semântica) People Analytics segue por CONTRATO (sem deduplicar por pessoa); services sem dependência de UI — cálculo intacto');

    // ---- tabelas no mobile -------------------------------------------------------------------------------------------------------------
    foreach (['pdis/index.php', 'pdis/selecionar-contrato.php', 'recruitment_webhooks/index.php'] as $v) {
        $c = $fonte('app/views/admin/' . $v);
        $check(!str_contains($c, 'mobile-table-desktop') && str_contains($c, 'responsive-table-wrap') && substr_count($c, '<table') >= 1, "(mobile) admin/{$v}: a tabela não é escondida abaixo de 769px e rola dentro do próprio container (responsive-table-wrap)");
    }
    foreach (glob(BASE_PATH . '/app/views/admin/**/*.php') ?: [] as $arq) {
        $rel = substr(str_replace('\\', '/', $arq), strlen(str_replace('\\', '/', BASE_PATH . '/app/views/admin/')));
        $c = (string)file_get_contents($arq);
        $migrada = str_contains($c, 'modulo-topo.php') || str_contains($c, 'ui-shell.php') || str_contains($c, '_helpers.php') && str_starts_with($rel, 'pdis/');
        if ($migrada && str_contains($c, 'mobile-table-desktop')) {
            $check(str_contains($c, 'responsive-card-list'), "(mobile) admin/{$rel}: tela migrada com .mobile-table-desktop precisa de lista de cards alternativa");
        }
    }
    $check(!str_contains($fonte('app/views/admin/avaliacoes/index.php'), 'mobile-table-desktop') && !str_contains($fonte('app/views/admin/movimentacoes_pessoal/index.php'), 'mobile-table-desktop'), '(mobile) Avaliações e Movimentação de Pessoal (migradas no Bloco G) também não escondem mais a tabela no mobile');

    echo "\nPORTAL_MODULO_INDICADORES_OK\n";
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
