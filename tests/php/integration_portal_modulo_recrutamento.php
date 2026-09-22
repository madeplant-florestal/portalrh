<?php

/**
 * Integração — Nova UI, Bloco B: módulo Recrutamento e Seleção no AppShell V2 (ModuleTabs + topo padrão + telas migradas).
 * Fixtures ZZREC-* com limpeza em `finally`. Prova, contra o banco:
 *   - REGRA DE OURO das abas: para vários perfis, TODA aba visível aponta para um destino que o backend realmente aceita
 *     (o gate `Auth::requireRole([...])`/`Authorization::requirePermissao(...)` é lido do controller de cada rota) — nunca uma aba que leva a 403;
 *   - a antiga divergência sidebar × controller (Pipeline/Indicações/Webhooks para `viewer` com permissão) foi CORRIGIDA no backend
 *     (`Authorization::requireRoleOuPermissao`): viewer com a permissão vê a aba e abre a tela (200); sem ela, não vê e é bloqueado;
 *     ações sensíveis (mover, pagar, exportar, configurar, testar, reenviar) seguem restritas às suas roles;
 *   - a aba da página atual sempre aparece; `entradaDoModulo` = primeira aba visível; nenhuma autorização foi criada/alterada;
 *   - as telas migradas renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader, ModuleTabs, `aria-current` e <title>;
 *   - o card "Recrutamento e Seleção" da Central segue apontando para a primeira entrada válida.
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
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$rotas = (string)file_get_contents(BASE_PATH . '/index.php');
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

$permId = [];
$stmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
foreach (['dashboard_recrutamento.visualizar', 'pipeline.visualizar', 'recruitment_webhooks.visualizar', 'indicacoes.visualizar', 'solicitacao_vaga.criar', 'kanban_vagas.visualizar'] as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = [], bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZREC ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzrec.' . $suffix . '@teste.local', $senha, $role);
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
    $_SESSION['user_name'] = 'ZZREC Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
};

/** O backend deixa este usuário abrir a rota GET? (gate lido do próprio controller: requireRole + requirePermissao) */
$backendPermite = static function (string $href, array $u, string $metodoHttp = 'get') use ($rotas, $corpoDe): bool {
    if (!preg_match("#\\\$router->{$metodoHttp}\\('" . preg_quote($href, '#') . "', \\[(\\w+)::class, '(\\w+)'\\]\\)#", $rotas, $m)) {
        return false;
    }
    $corpo = $corpoDe($m[1], $m[2]);
    // Gate aditivo de entrada: role da lista OU a permissão individual (Authorization::requireRoleOuPermissao([...], 'codigo'))
    if (preg_match("#Authorization::requireRoleOuPermissao\\(\\[([^\\]]*)\\], '([^']+)'\\)#", $corpo, $rp)) {
        $rolesRp = array_map(static fn(string $x): string => trim($x, " '\""), explode(',', $rp[1]));
        return $u['supervisor'] || in_array($u['role'], $rolesRp, true) || Authorization::usuarioTemPermissao($u['id'], $rp[2]);
    }
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
    $admin = $novoUsuario('Admin', 'admin');
    $rh = $novoUsuario('RH', 'rh');
    $viewer = $novoUsuario('Viewer', 'viewer');
    $supervisor = $novoUsuario('Supervisor', 'viewer', [], true);
    $viewerPipe = $novoUsuario('Viewer Pipeline', 'viewer', ['pipeline.visualizar', 'recruitment_webhooks.visualizar', 'indicacoes.visualizar']);
    $viewerDash = $novoUsuario('Viewer Dashboard', 'viewer', ['dashboard_recrutamento.visualizar']);
    $gestor = $novoUsuario('Gestor', 'viewer', ['solicitacao_vaga.criar', 'kanban_vagas.visualizar']);
    $perfis = ['Admin' => $admin, 'RH' => $rh, 'viewer' => $viewer, 'supervisor' => $supervisor, 'viewer+pipeline/webhooks/indicações' => $viewerPipe, 'viewer+dashboard' => $viewerDash, 'gestor de vaga' => $gestor];

    $definicao = PortalNavegacaoService::definicaoAbas()['recrutamento'];
    $check(array_column($definicao['abas'], 'chave') === ['dashboard', 'solicitacoes', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks'], '(abas) Dashboard, Solicitações de Vaga, Vagas, Candidaturas, Pipeline Kanban, Indicações, Webhooks — só funcionalidades reais');
    $semRota = array_values(array_filter(array_column($definicao['abas'], 'href'), static fn(string $h): bool => !str_contains($rotas, "\$router->get('{$h}'")));
    $check($semRota === [], '(abas) Todo destino de aba é rota GET registrada' . ($semRota === [] ? '' : ': faltam ' . implode(', ', $semRota)));

    // ---- regra de ouro: aba visível => backend permite ----------------------------------------------------------------------
    $problemas = [];
    $visiveis = [];
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        $abas = (new PortalNavegacaoService())->abas('recrutamento');
        $visiveis[$rotulo] = array_column($abas, 'chave');
        foreach ($abas as $aba) {
            if (!$backendPermite($aba['href'], $u)) {
                $problemas[] = "{$rotulo}: aba \"{$aba['label']}\" ({$aba['href']}) levaria a 403";
            }
        }
    }
    $check($problemas === [], '(regra de ouro) Para ' . count($perfis) . ' perfis, toda aba visível leva a um destino que o controller realmente aceita — nunca a um 403' . ($problemas === [] ? '' : ': ' . implode(' | ', $problemas)));
    $check($visiveis['Admin'] === ['dashboard', 'solicitacoes', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks'], '(Admin) Vê as 7 abas (bypass central para o dashboard)');
    $check($visiveis['RH'] === ['solicitacoes', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks'], '(RH) Vê tudo, menos o Dashboard (que exige a permissão individual — RH não tem bypass)');
    $check($visiveis['viewer'] === ['vagas', 'candidaturas'], '(viewer sem permissão) Só Vagas e Candidaturas, abertas por role');
    $check($visiveis['gestor de vaga'] === ['solicitacoes', 'vagas', 'candidaturas'], '(gestor) Solicitações + as abertas');
    $check($visiveis['viewer+dashboard'] === ['dashboard', 'vagas', 'candidaturas'], '(viewer+dashboard) O Dashboard aparece com a permissão individual');
    $check($visiveis['viewer+pipeline/webhooks/indicações'] === ['vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks'] && $visiveis['supervisor'] === ['solicitacoes', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks'], '(correção) viewer com as permissões pipeline/indicações/webhooks agora RECEBE as 3 abas (a permissão individual libera a entrada); supervisor também');

    // ---- gates de entrada: viewer com permissão abre; sem permissão é bloqueado; Admin pelo bypass -----------------------------
    $soPipe = $novoUsuario('So Pipeline', 'viewer', ['pipeline.visualizar']);
    $soInd = $novoUsuario('So Indicacoes', 'viewer', ['indicacoes.visualizar']);
    $soWeb = $novoUsuario('So Webhooks', 'viewer', ['recruitment_webhooks.visualizar']);
    $entradas = ['/admin/pipeline' => [$soPipe, 'pipeline'], '/admin/indicacoes' => [$soInd, 'indicacoes'], '/admin/recruitment-webhooks' => [$soWeb, 'webhooks']];
    foreach ($entradas as $rota => [$comPerm, $chaveAba]) {
        $comoUsuario($comPerm);
        $check($backendPermite($rota, $comPerm) && in_array($chaveAba, array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave'), true), "(entrada) viewer com a permissão de {$chaveAba}: vê a aba e o backend abre {$rota} (200)");
        foreach ($entradas as $outraRota => [, $outraChave]) {
            if ($outraRota === $rota) {
                continue;
            }
            $check(!$backendPermite($outraRota, $comPerm) && !in_array($outraChave, array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave'), true), "(entrada) essa permissão NÃO abre {$outraRota}: sem aba e bloqueado (403)");
        }
        $comoUsuario($viewer);
        $check(!$backendPermite($rota, $viewer) && !Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'nao.existe.zz'), "(entrada) viewer SEM permissão: sem aba e {$rota} bloqueado");
        $comoUsuario($admin);
        $check($backendPermite($rota, $admin), "(entrada) Admin entra em {$rota} (role + bypass central preservados)");
    }
    $comoUsuario($soPipe);
    $check(Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'pipeline.visualizar') && !Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'indicacoes.visualizar'), '(gate) Authorization::temAcessoPorRoleOuPermissao: a permissão certa libera, a errada não');
    $comoUsuario($rh);
    $check(Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'pipeline.visualizar') && $backendPermite('/admin/pipeline', $rh) && $backendPermite('/admin/indicacoes', $rh) && $backendPermite('/admin/recruitment-webhooks', $rh), '(compatibilidade) RH continua entrando pela role, SEM precisar da permissão (o modelo é aditivo: nenhum acesso existente foi removido)');

    // ---- ações sensíveis continuam com os gates de antes -------------------------------------------------------------------------
    $sensiveis = [
        ['post', '/api/pipeline/move', false, true, true],
        ['get', '/admin/indicacoes/export', false, true, true],
        ['post', '/admin/indicacoes/{id}/pagar', false, true, true],
        ['post', '/admin/indicacoes/{id}/pagar/editar-data', false, true, true],
        ['post', '/admin/recruitment-webhooks/settings/save', false, false, true],
        ['post', '/admin/recruitment-webhooks/settings/regenerate-secret', false, false, true],
        ['post', '/admin/recruitment-webhooks/test', false, true, true],
        ['post', '/admin/recruitment-webhooks/process-pending', false, true, true],
        ['post', '/admin/recruitment-webhooks/events/{id}/retry', false, true, true],
    ];
    $sensivelOk = true;
    $detalhe = [];
    foreach ($sensiveis as [$metodoHttp, $rotaSensivel, $viewerComPerm, $rhPode, $adminPode]) {
        $resultado = [$backendPermite($rotaSensivel, $viewerPipe, $metodoHttp), $backendPermite($rotaSensivel, $rh, $metodoHttp), $backendPermite($rotaSensivel, $admin, $metodoHttp)];
        if ($resultado !== [$viewerComPerm, $rhPode, $adminPode]) {
            $sensivelOk = false;
            $detalhe[] = "{$rotaSensivel}=" . json_encode($resultado);
        }
    }
    $check($sensivelOk, '(ações sensíveis) mover card, exportar, pagar/editar pagamento, testar/reprocessar/reenviar continuam admin/rh; salvar configuração e regenerar segredo só admin; viewer com a permissão de VISUALIZAR NÃO as executa' . ($detalhe === [] ? '' : ' — divergências: ' . implode(' | ', $detalhe)));

    // ---- telas em modo leitura para quem entrou só pela permissão -----------------------------------------------------------------
    $_GET = [];
    $comoUsuario($viewerPipe);
    ui_titulo_pagina(null, true);
    $htmlPipe = $renderizar(static fn() => (new AdminPipelineController())->index());
    ui_titulo_pagina(null, true);
    $htmlInd = $renderizar(static fn() => (new AdminIndicacoesController())->index());
    ui_titulo_pagina(null, true);
    $htmlWeb = $renderizar(static fn() => (new AdminRecruitmentWebhooksController())->index());
    $check(str_contains($htmlPipe, 'data-kanban-readonly="1"') && str_contains($htmlPipe, 'Somente visualização') && !str_contains($htmlPipe, 'draggable="true"') && str_contains($htmlPipe, 'draggable="false"'), '(leitura) Pipeline para viewer com permissão: quadro em somente visualização (o JS não habilita arrastar)');
    $check(!str_contains($htmlInd, 'Exportar Excel') && !str_contains($htmlInd, 'data-pagamento-open') && !str_contains($htmlInd, 'data-pagamento-edit-open') && str_contains($htmlInd, 'Programa de Indicações'), '(leitura) Indicações para viewer com permissão: lista e filtros, sem exportar nem registrar/editar pagamento');
    $check(!str_contains($htmlWeb, 'settings/save') && !str_contains($htmlWeb, 'regenerate-secret') && !str_contains($htmlWeb, '/test"') && !str_contains($htmlWeb, 'process-pending') && !str_contains($htmlWeb, '/retry') && str_contains($htmlWeb, 'Somente visualização'), '(leitura) Webhooks para viewer com permissão: configuração, fila e histórico em leitura, sem nenhum formulário de ação');
    $comoUsuario($rh);
    ui_titulo_pagina(null, true);
    $htmlPipeRh = $renderizar(static fn() => (new AdminPipelineController())->index());
    ui_titulo_pagina(null, true);
    $htmlIndRh = $renderizar(static fn() => (new AdminIndicacoesController())->index());
    ui_titulo_pagina(null, true);
    $htmlWebRh = $renderizar(static fn() => (new AdminRecruitmentWebhooksController())->index());
    $check(!str_contains($htmlPipeRh, 'data-kanban-readonly') && str_contains($htmlIndRh, 'Exportar Excel') && str_contains($htmlWebRh, 'settings/save') && str_contains($htmlWebRh, 'process-pending') && str_contains($htmlWebRh, 'data-confirm-message="Gerar um novo segredo'), '(RH) A visão de quem opera não mudou: arrasta cards, exporta, vê os formulários de ação (confirmação de segredo via data-confirm-message)');

    // ---- aba atual sempre aparece; entrada do módulo ---------------------------------------------------------------------------
    $comoUsuario($viewer);
    $svc = new PortalNavegacaoService();
    $comPipeline = $svc->abas('recrutamento', 'pipeline');
    $check(in_array('pipeline', array_column($comPipeline, 'chave'), true) && count(array_filter($comPipeline, static fn(array $a): bool => $a['ativo'])) === 1, '(aba atual) A aba da página em que o usuário já está aparece marcada como ativa mesmo sem regra de visibilidade');
    $check($svc->entradaDoModulo('recrutamento') === '/admin/vagas' && $svc->abas('modulo-inexistente') === [], '(entrada) Entrada do módulo = primeira aba visível; módulo desconhecido = sem abas');
    $comoUsuario($viewerDash);
    $check((new PortalNavegacaoService())->entradaDoModulo('recrutamento') === '/admin/dashboard-recrutamento', '(entrada) Com o Dashboard liberado, a entrada é o Dashboard');

    // ---- Central: card Recrutamento ----------------------------------------------------------------------------------------------
    $comoUsuario($viewerDash);
    $card = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'recrutamento'))[0] ?? null;
    $check($card !== null && $card['href'] === '/admin/dashboard-recrutamento', '(Central) O card Recrutamento continua apontando para a primeira entrada válida (Dashboard, se permitido)');
    $comoUsuario($viewer);
    $card = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'recrutamento'))[0] ?? null;
    $check($card !== null && $card['href'] === '/admin/candidaturas', '(Central) Sem o Dashboard, o card cai em Candidaturas (destino aberto)');

    // ---- helper de topo -----------------------------------------------------------------------------------------------------------
    $comoUsuario($rh);
    $topo = ui_modulo_topo('', 'recrutamento', 'candidaturas', ['titulo' => 'Candidato <X>', 'descricao' => 'd'], [['label' => 'Fulano', 'href' => null]]);
    $check(str_contains($topo, 'Portal RH') && str_contains($topo, 'href="/admin"') && str_contains($topo, 'Recrutamento e Seleção') && str_contains($topo, 'href="/admin/candidaturas"') && substr_count($topo, 'aria-current="page"') === 2 && str_contains($topo, 'Candidato &lt;X&gt;'), '(topo) Breadcrumb Portal RH › Módulo › Aba › Detalhe (aba vira link quando há detalhe), título escapado, aba ativa com aria-current');
    $check(strpos($topo, 'Trilha de navegação') < strpos($topo, '<h1') && strpos($topo, '<h1') < strpos($topo, 'aria-label="Recrutamento e Seleção"'), '(topo) Ordem do Design System: Breadcrumb → PageHeader → ModuleTabs');
    $check(ui_modulo_topo('', 'modulo-inexistente', 'x', ['titulo' => 'T']) !== '' && !str_contains(ui_modulo_topo('', 'modulo-inexistente', 'x', ['titulo' => 'T']), '<nav'), '(topo) Módulo desconhecido degrada para só o PageHeader');

    // ---- telas migradas renderizam no AppShell V2 -----------------------------------------------------------------------------------
    $paginas = [
        'Candidaturas' => [$rh, AdminCandidaturasController::class, 'index', 'candidaturas'],
        'Vagas' => [$rh, AdminVagasController::class, 'index', 'vagas'],
        'Pipeline' => [$rh, AdminPipelineController::class, 'index', 'Pipeline Kanban'],
        'Solicitações' => [$rh, AdminSolicitacoesVagaController::class, 'index', 'Solicitações de Vaga'],
        'Indicações' => [$rh, AdminIndicacoesController::class, 'index', 'Indicações'],
        'Webhooks' => [$rh, AdminRecruitmentWebhooksController::class, 'index', 'Webhooks'],
        'Dashboard' => [$viewerDash, AdminDashboardRecrutamentoController::class, 'index', 'Dashboard'],
    ];
    foreach ($paginas as $nome => [$u, $classe, $metodo, $rotuloAba]) {
        $comoUsuario($u);
        $_GET = [];
        ui_titulo_pagina(null, true);
        $html = $renderizar(static fn() => (new $classe())->$metodo());
        $ok = !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html)
            && str_contains($html, 'data-app-shell-v2="1"')
            && !str_contains($html, 'data-admin-sidebar') && !str_contains($html, 'class="sidebar')
            && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, 'aria-label="Recrutamento e Seleção"')
            && str_contains($html, '<h1') && preg_match('#<title>[^<]+ — Portal RH</title>#', $html) === 1
            && preg_match('#aria-current="page"[^>]*>' . preg_quote($rotuloAba, '#') . '<#i', $html) === 1;
        $check($ok, "(tela) {$nome}: AppShell V2 sem sidebar, com breadcrumb, PageHeader, ModuleTabs (aba ativa \"{$rotuloAba}\"), <title> e sem Warning/Notice");
    }
    $_GET = [];

    // ---- nenhuma autorização nova / código ----------------------------------------------------------------------------------------
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão foi criada');
    $migrados = ['AdminCandidaturasController', 'AdminVagasController', 'AdminPipelineController', 'AdminSolicitacoesVagaController', 'AdminSolicitacoesVagaKanbanController', 'AdminIndicacoesController', 'AdminRecruitmentWebhooksController', 'AdminDashboardRecrutamentoController'];
    $todosOk = true;
    foreach ($migrados as $ctrl) {
        $fonte = (string)file_get_contents(APP_PATH . "/controllers/{$ctrl}.php");
        $todosOk = $todosOk && !str_contains($fonte, "'layouts/admin'") && str_contains($fonte, "'layouts/app-shell'") && str_contains($fonte, 'Auth::requireRole');
    }
    $check($todosOk, '(controllers) Os 8 controllers do módulo só trocaram o layout para app-shell e mantêm todos os seus gates requireRole/requirePermissao');
    $check(str_contains($corpoDe(AdminPipelineController::class, 'index'), "Authorization::requireRoleOuPermissao(['admin', 'rh'], 'pipeline.visualizar')") && str_contains($corpoDe(AdminIndicacoesController::class, 'index'), "Authorization::requireRoleOuPermissao(['admin', 'rh'], 'indicacoes.visualizar')") && str_contains($corpoDe(AdminRecruitmentWebhooksController::class, 'index'), "Authorization::requireRoleOuPermissao(['admin', 'rh'], 'recruitment_webhooks.visualizar')") && str_contains($corpoDe(AdminDashboardRecrutamentoController::class, 'index'), "Authorization::requirePermissao('dashboard_recrutamento.visualizar')"), '(controllers) Entradas de Pipeline, Indicações e Webhooks: role admin/rh OU a permissão individual <módulo>.visualizar (permissões já existentes); Dashboard segue exigindo a sua permissão');
    $check(str_contains($corpoDe(AdminPipelineController::class, 'move'), "Auth::requireRole(['admin', 'rh'])") && str_contains($corpoDe(AdminIndicacoesController::class, 'markPago'), "Auth::requireRole(['admin', 'rh'])") && str_contains($corpoDe(AdminIndicacoesController::class, 'export'), "Auth::requireRole(['admin', 'rh'])") && str_contains($corpoDe(AdminRecruitmentWebhooksController::class, 'saveSetting'), "Auth::requireRole(['admin'])"), '(controllers) Mover, pagar, exportar e configurar webhooks mantêm os gates de escrita/operação de antes');
    $check((int)$pdo->query("SELECT COUNT(*) FROM permissoes")->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova: só as já existentes (pipeline/indicacoes/recruitment_webhooks .visualizar) passaram a valer no backend');
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
echo "\nINTEGRATION_PORTAL_MODULO_RECRUTAMENTO_OK\n";
