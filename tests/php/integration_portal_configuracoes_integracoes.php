<?php

/**
 * Integração — Configurações → Integrações (Etapa 2, 2026-10): a administração da integração de
 * Webhooks do Recrutamento com o n8n foi realocada de `/admin/recruitment-webhooks` para
 * `/admin/configuracoes/integracoes` (`AdminIntegracoesController`), antes hospedada dentro do
 * módulo de Recrutamento (ver `integration_portal_modulo_recrutamento.php` e a auditoria técnica da
 * Etapa 1). Mesma permissão de antes (`recruitment_webhooks.visualizar`), mesmos
 * services/repositories de infraestrutura (`RecruitmentWebhookAdminService` e companhia) — só
 * mudou organização/navegação/rotas; nenhum comportamento funcional foi alterado. Fixtures ZZCFG-*
 * com limpeza em `finally`. Prova, contra o banco:
 *   - a aba "Integrações" existe dentro do módulo "Configurações" (`PortalNavegacaoService`), com
 *     rota GET registrada;
 *   - a rota antiga `GET /admin/recruitment-webhooks` redireciona para a nova URL, e nenhuma rota
 *     POST antiga foi mantida como alias (decisão documentada: sem uso externo conhecido);
 *   - a permissão `recruitment_webhooks.visualizar` continua abrindo a tela (viewer em modo
 *     leitura; admin/rh operam normalmente) — nenhum acesso existente foi removido;
 *   - salvar configuração, regenerar segredo, testar webhook, processar pendentes e reenviar evento
 *     continuam funcionando através do novo controller, reaproveitando o service existente;
 *   - a proteção SSRF (`RecruitmentWebhookUrlGuard`) continua bloqueando URL privada mesmo depois
 *     da remoção do logging temporário `[INVESTIGACAO SSRF]`.
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
$empresaId = null;
$testEventId = null;
$originalSetting = null;
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
foreach (['recruitment_webhooks.visualizar'] as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = [], bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZCFG ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzcfg.' . $suffix . '@teste.local', $senha, $role);
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
    $_SESSION['user_name'] = 'ZZCFG Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
};
/** O backend deixa este usuário abrir a rota GET? (gate lido do próprio controller) */
$backendPermite = static function (string $href, array $u, string $metodoHttp = 'get') use ($rotas, $corpoDe): bool {
    if (!preg_match("#\\\$router->{$metodoHttp}\\('" . preg_quote($href, '#') . "', \\[(\\w+)::class, '(\\w+)'\\]\\)#", $rotas, $m)) {
        return false;
    }
    $corpo = $corpoDe($m[1], $m[2]);
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
    return true;
};

try {
    $admin = $novoUsuario('Admin', 'admin');
    $rh = $novoUsuario('RH', 'rh');
    $viewer = $novoUsuario('Viewer', 'viewer');
    $soWeb = $novoUsuario('So Integracoes', 'viewer', ['recruitment_webhooks.visualizar']);

    // ---- (1)/(3) a aba Integrações existe dentro do módulo Configurações ------------------------
    $definicao = PortalNavegacaoService::definicaoAbas()['configuracoes'] ?? null;
    $check($definicao !== null && array_column($definicao['abas'], 'chave') === ['integracoes'], '(abas) O módulo Configurações existe e tem a aba Integrações');
    $check(($definicao['abas'][0]['href'] ?? null) === '/admin/configuracoes/integracoes', '(abas) Integrações aponta para a nova rota /admin/configuracoes/integracoes');
    $check(str_contains($rotas, "\$router->get('/admin/configuracoes/integracoes', [AdminIntegracoesController::class, 'index']);"), '(rota) GET /admin/configuracoes/integracoes está registrada para o novo controller');

    // ---- (2) card Configurações aparece para usuário autorizado; não aparece para quem não tem a permissão
    $comoUsuario($soWeb);
    $card = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes'))[0] ?? null;
    $check($card !== null && $card['href'] === '/admin/configuracoes/integracoes', '(Central) viewer com recruitment_webhooks.visualizar vê o card Configurações, apontando para Integrações');
    $comoUsuario($viewer);
    $check(array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes')) === [], '(Central) viewer sem a permissão NÃO vê o card Configurações');
    $comoUsuario($rh);
    $cardRh = array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes'))[0] ?? null;
    $check($cardRh !== null && $cardRh['href'] === '/admin/configuracoes/integracoes', '(Central) RH vê Configurações pelo bypass central de staff_ou (igual já valia para Webhooks dentro de Recrutamento)');

    // ---- (4) a nova rota carrega a página -----------------------------------------------------------
    $check($backendPermite('/admin/configuracoes/integracoes', $rh), '(rota) GET /admin/configuracoes/integracoes abre para RH');
    $check($backendPermite('/admin/configuracoes/integracoes', $admin), '(rota) GET /admin/configuracoes/integracoes abre para Admin');

    // ---- (5) a rota antiga GET redireciona; nenhuma rota POST antiga foi mantida como alias --------
    $check(preg_match("#\\\$router->get\\('/admin/recruitment-webhooks', static fn\\(\\) => redirect\\('/admin/configuracoes/integracoes'\\)\\);#", $rotas) === 1, '(compatibilidade) GET /admin/recruitment-webhooks redireciona para a nova URL');
    $check(preg_match("#\\\$router->(get|post)\\('/admin/recruitment-webhooks/#", $rotas) === 0, '(compatibilidade) Nenhuma rota de ação antiga (/admin/recruitment-webhooks/...) foi mantida — sem uso externo conhecido além dos próprios formulários da tela, já atualizados');

    // ---- (6)/(7) permissão existente continua funcionando; modo leitura continua funcionando ------
    $check($backendPermite('/admin/configuracoes/integracoes', $soWeb) && !$backendPermite('/admin/configuracoes/integracoes', $viewer), '(permissão) recruitment_webhooks.visualizar continua abrindo a tela; viewer sem ela é bloqueado');
    $check(str_contains($corpoDe(AdminIntegracoesController::class, 'index'), "Authorization::requireRoleOuPermissao(['admin', 'rh'], 'recruitment_webhooks.visualizar')"), '(permissão) O novo controller mantém o mesmo gate de entrada (role admin/rh OU a permissão já existente — débito técnico de nomenclatura documentado)');
    $check(str_contains($corpoDe(AdminIntegracoesController::class, 'saveSetting'), "Auth::requireRole(['admin'])") && str_contains($corpoDe(AdminIntegracoesController::class, 'regenerateSecret'), "Auth::requireRole(['admin'])") && str_contains($corpoDe(AdminIntegracoesController::class, 'testWebhook'), "Auth::requireRole(['admin', 'rh'])") && str_contains($corpoDe(AdminIntegracoesController::class, 'processPending'), "Auth::requireRole(['admin', 'rh'])") && str_contains($corpoDe(AdminIntegracoesController::class, 'retryEvent'), "Auth::requireRole(['admin', 'rh'])"), '(ações) Salvar/regenerar continuam só admin; testar/processar/reenviar continuam admin/rh');

    $comoUsuario($soWeb);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlLeitura = $renderizar(static fn() => (new AdminIntegracoesController())->index());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlLeitura)
        && !str_contains($htmlLeitura, 'webhook/salvar') && !str_contains($htmlLeitura, 'webhook/regenerar-segredo')
        && !str_contains($htmlLeitura, 'webhook/testar') && !str_contains($htmlLeitura, 'webhook/processar-pendentes')
        && !str_contains($htmlLeitura, 'webhook/reenviar') && str_contains($htmlLeitura, 'Somente visualização'),
        '(leitura) viewer com só a permissão de visualizar: tela em modo leitura, sem nenhum formulário de ação'
    );

    $comoUsuario($rh);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlRh = $renderizar(static fn() => (new AdminIntegracoesController())->index());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlRh)
        && str_contains($htmlRh, 'webhook/salvar') && str_contains($htmlRh, 'webhook/regenerar-segredo')
        && str_contains($htmlRh, 'webhook/testar') && str_contains($htmlRh, 'webhook/processar-pendentes')
        && str_contains($htmlRh, 'data-app-shell-v2="1"') && str_contains($htmlRh, 'aria-label="Recrutamento e Seleção"') === false
        && str_contains($htmlRh, 'aria-label="Configurações"')
        && preg_match('#aria-current="page"[^>]*>Integra\x{00e7}\x{00f5}es<#iu', $htmlRh) === 1
        && preg_match('#<title>[^<]+ — Portal RH</title>#', $htmlRh) === 1,
        '(tela) RH: AppShell V2 com breadcrumb/abas de Configurações (nunca mais de Recrutamento), aba Integrações ativa, formulários de ação presentes, sem Warning/Notice'
    );
    $_GET = [];

    // ---- (9)-(12) salvar, regenerar, testar, processar pendentes e reenviar continuam funcionando -
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute(['EMPRESA ZZCFG ' . $suffix, 'empresa-zzcfg-' . $suffix]);
    $empresaId = (int)$pdo->lastInsertId();

    $settingRepository = new RecruitmentWebhookSettingRepository($pdo);
    $originalSetting = $pdo->query('SELECT enabled, webhook_url, webhook_secret_encrypted FROM recruitment_webhook_global_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    $service = new RecruitmentWebhookAdminService($settingRepository, new WebhookEventRepository($pdo));

    // (8) salvar configuração
    $service->saveSetting(['enabled' => '1', 'webhook_url' => 'https://appmadeplant.com/api/webhooks/zzcfg']);
    $check($settingRepository->get()['webhook_url'] === 'https://appmadeplant.com/api/webhooks/zzcfg' && (int)$settingRepository->get()['enabled'] === 1, '(salvar) saveSetting() continua gravando a configuração pelo service reaproveitado (RecruitmentWebhookAdminService)');

    // (9) regenerar segredo
    $secret = $service->regenerateSecret();
    $check($secret !== '' && $settingRepository->hasSecret(), '(segredo) regenerateSecret() continua gerando e gravando um novo segredo criptografado em banco');

    // (17) proteção SSRF continua ativa após a remoção do logging temporário
    $ssrfBlocked = false;
    try {
        $service->saveSetting(['enabled' => '1', 'webhook_url' => 'http://127.0.0.1/malicioso']);
    } catch (InvalidArgumentException $e) {
        $ssrfBlocked = true;
    }
    $check($ssrfBlocked, '(SSRF) RecruitmentWebhookUrlGuard continua bloqueando URL privada mesmo após a remoção do logging temporário [INVESTIGACAO SSRF]');
    $check(!str_contains((string)file_get_contents(APP_PATH . '/services/RecruitmentWebhookUrlGuard.php'), 'INVESTIGACAO SSRF') && !str_contains((string)file_get_contents(APP_PATH . '/controllers/AdminIntegracoesController.php'), 'INVESTIGACAO SSRF'), '(logging) O logging temporário de investigação foi removido do guard e do controller');
    // A tentativa acima falha ANTES de persistir (validação ocorre antes do save) — a configuração
    // salva no passo (8) continua intacta, sem necessidade de salvar de novo.
    $check($settingRepository->get()['webhook_url'] === 'https://appmadeplant.com/api/webhooks/zzcfg', '(SSRF) A tentativa bloqueada não alterou a configuração válida já salva');

    // (10) testar webhook
    $testResult = $service->sendTestWebhook();
    $testEventRow = $pdo->query("SELECT id FROM webhook_events WHERE event_type = 'recrutamento.webhook.teste' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $testEventId = $testEventRow ? (int)$testEventRow['id'] : 0;
    $check($testEventId > 0 && array_key_exists('ok', $testResult), '(testar) sendTestWebhook() continua registrando e processando um evento de teste pela mesma fila');

    // (12) reenviar evento
    $eventRepository = new WebhookEventRepository($pdo);
    $eventRepository->requeue($testEventId);
    $retryResult = $service->retryEvent($testEventId);
    $check(array_key_exists('ok', $retryResult), '(reenviar) retryEvent() continua reprocessando o evento pela fila existente');

    // (11) processar pendentes
    $pendingResult = $service->processPending(25);
    $check(array_key_exists('processed', $pendingResult) && array_key_exists('failed', $pendingResult), '(fila) processPending() continua retornando processed/failed, mesma assinatura de antes');

    echo "\nINTEGRATION_PORTAL_CONFIGURACOES_INTEGRACOES_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    if (!empty($testEventId)) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$testEventId]);
    }
    if (!empty($empresaId)) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresaId]);
    }
    if (!empty($originalSetting)) {
        $pdo->prepare('UPDATE recruitment_webhook_global_settings SET enabled = ?, webhook_url = ?, webhook_secret_encrypted = ? WHERE id = 1')
            ->execute([$originalSetting['enabled'], $originalSetting['webhook_url'], $originalSetting['webhook_secret_encrypted']]);
    }
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
echo "\nINTEGRATION_PORTAL_CONFIGURACOES_INTEGRACOES_OK\n";
