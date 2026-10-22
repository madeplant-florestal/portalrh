<?php

/**
 * Integração — Configurações → Mensagens (Etapa 3.1, 2026-10): a administração dos modelos de
 * mensagem foi realocada de módulo próprio na Central (`/admin/mensagens`) para dentro de
 * Configurações, ao lado de Integrações (mesmo padrão já aplicado aos Webhooks na Etapa 2 — ver
 * `integration_portal_configuracoes_integracoes.php`). Model `Mensagem`, `MensagemService` e o
 * controller `AdminMensagensController` não foram alterados em comportamento — só a URL, a view e
 * a navegação. Fixtures ZZCMG-* com limpeza em `finally`. Prova, contra o banco:
 *   - o card "Mensagens" não existe mais como módulo independente na Central;
 *   - a aba "Mensagens" existe dentro de Configurações, ao lado de "Integrações";
 *   - a rota nova funciona e a antiga (`/admin/mensagens`, `/admin/mensagens/novo`,
 *     `/admin/mensagens/editar/{id}`) redireciona;
 *   - a permissão `mensagens.visualizar/criar/editar` continua sendo a autoridade (nenhuma
 *     permissão nova, nenhum acesso antigo quebrado);
 *   - um usuário só com `mensagens.visualizar` entra em Configurações/Mensagens mas não vê a aba
 *     Integrações, e vice-versa para `recruitment_webhooks.visualizar` — as duas áreas são
 *     independentes, sem exigir acesso cruzado;
 *   - a página renderiza com o breadcrumb Portal RH › Configurações › Mensagens.
 *
 * CRUD/placeholders/imutabilidade do código/mensagens inativas continuam cobertos por
 * `integration_mensagens.php` (modelo/serviço, não tocados nesta etapa) — reexecutado junto.
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
foreach (['mensagens.visualizar', 'mensagens.criar', 'mensagens.editar', 'recruitment_webhooks.visualizar'] as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = []) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZCMG ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzcmg.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    $criados[] = $id;
    if ($permissoes !== []) {
        Authorization::sincronizar($id, array_map(static fn(string $p): int => $permId[$p], $permissoes));
    }
    return ['id' => $id, 'role' => $role];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZCMG Teste';
    $_SESSION['user_is_supervisor'] = 0;
};
$backendPermite = static function (string $href, array $u, string $metodoHttp = 'get') use ($rotas, $corpoDe): bool {
    if (!preg_match("#\\\$router->{$metodoHttp}\\('" . preg_quote($href, '#') . "', \\[(\\w+)::class, '(\\w+)'\\]\\)#", $rotas, $m)) {
        return false;
    }
    $corpo = $corpoDe($m[1], $m[2]);
    if (preg_match("#Auth::requireRole\\(\\[([^\\]]*)\\]\\)#", $corpo, $r)) {
        $roles = array_map(static fn(string $x): string => trim($x, " '\""), explode(',', $r[1]));
        if (!in_array($u['role'], $roles, true)) {
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
    $rh = $novoUsuario('RH', 'rh');
    $soMensagens = $novoUsuario('So Mensagens', 'viewer', ['mensagens.visualizar']);
    $soWeb = $novoUsuario('So Integracoes', 'viewer', ['recruitment_webhooks.visualizar']);
    $semNada = $novoUsuario('Sem Nada', 'viewer');

    // ---- (1) card "Mensagens" não existe mais como módulo independente na Central -----------------
    $chaves = array_column(PortalNavegacaoService::definicao(), 'chave');
    $check(!in_array('mensagens', $chaves, true), '(1) "mensagens" não é mais uma chave de módulo independente na Central');
    $check(in_array('configuracoes', $chaves, true), '(1) "configuracoes" continua existindo como módulo');

    // ---- (3)/(4) abas Mensagens e Integrações dentro de Configurações ------------------------------
    $definicao = PortalNavegacaoService::definicaoAbas()['configuracoes'] ?? null;
    $check($definicao !== null && array_column($definicao['abas'], 'chave') === ['mensagens', 'integracoes'], '(3)/(4) Configurações tem exatamente as abas Mensagens e Integrações, nesta ordem');
    $hrefPorChave = array_column($definicao['abas'], 'href', 'chave');
    $check(($hrefPorChave['mensagens'] ?? null) === '/admin/configuracoes/mensagens', '(3) Aba Mensagens aponta para a nova rota');
    $check(($hrefPorChave['integracoes'] ?? null) === '/admin/configuracoes/integracoes', '(4) Aba Integrações continua apontando para a rota de Integrações, inalterada');

    // ---- (5) a nova rota funciona -------------------------------------------------------------------
    $check(str_contains($rotas, "\$router->get('/admin/configuracoes/mensagens', [AdminMensagensController::class, 'index']);"), '(5) Rota GET /admin/configuracoes/mensagens registrada para o controller existente');
    $check($backendPermite('/admin/configuracoes/mensagens', $rh), '(5) GET /admin/configuracoes/mensagens abre para RH');

    // ---- (6) rotas antigas redirecionam --------------------------------------------------------------
    $check(preg_match("#\\\$router->get\\('/admin/mensagens', static fn\\(\\) => redirect\\('/admin/configuracoes/mensagens'\\)\\);#", $rotas) === 1, '(6) GET /admin/mensagens redireciona para a nova URL');
    $check(preg_match("#\\\$router->get\\('/admin/mensagens/novo', static fn\\(\\) => redirect\\('/admin/configuracoes/mensagens/novo'\\)\\);#", $rotas) === 1, '(6) GET /admin/mensagens/novo redireciona para a nova URL');
    $check(preg_match("#\\\$router->get\\('/admin/mensagens/editar/\\{id\\}', static fn\\(string \\\$id\\) => redirect\\('/admin/configuracoes/mensagens/editar/' \\. \\\$id\\)\\);#", $rotas) === 1, '(6) GET /admin/mensagens/editar/{id} redireciona para a nova URL, preservando o id');
    $check(preg_match("#\\\$router->(get|post)\\('/admin/mensagens/novo', \\[AdminMensagensController#", $rotas) === 0 && preg_match("#\\\$router->(get|post)\\('/admin/mensagens/editar/\\{id\\}', \\[AdminMensagensController#", $rotas) === 0, '(6) Nenhuma rota antiga aponta mais diretamente para o controller — só redirecionamentos');

    // ---- (11) permissões antigas continuam sendo a autoridade (nenhuma nova) -----------------------
    $check(
        str_contains($corpoDe(AdminMensagensController::class, 'index'), "Authorization::requirePermissao('mensagens.visualizar')")
        && str_contains($corpoDe(AdminMensagensController::class, 'store'), "Authorization::requirePermissao('mensagens.criar')")
        && str_contains($corpoDe(AdminMensagensController::class, 'update'), "Authorization::requirePermissao('mensagens.editar')"),
        '(11) AdminMensagensController mantém exatamente os mesmos gates de permissão individual de antes'
    );

    // ---- (12) usuário só com mensagens.visualizar: entra em Configurações/Mensagens, sem Integrações
    $comoUsuario($soMensagens);
    $svc = new PortalNavegacaoService();
    $cardSoMsg = array_values(array_filter($svc->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes'))[0] ?? null;
    $abasSoMsg = array_column($svc->abas('configuracoes'), 'chave');
    $check($cardSoMsg !== null && $cardSoMsg['href'] === '/admin/configuracoes/mensagens', '(12) Usuário só com mensagens.visualizar: card Configurações aparece, entrada em Mensagens');
    $check(in_array('mensagens', $abasSoMsg, true) && !in_array('integracoes', $abasSoMsg, true), '(12) Esse usuário vê a aba Mensagens mas NÃO a aba Integrações — acesso cruzado não é exigido');
    $check($backendPermite('/admin/configuracoes/mensagens', $soMensagens), '(12) O backend realmente abre /admin/configuracoes/mensagens para esse usuário');

    // ---- (13) usuário só com recruitment_webhooks.visualizar: entra em Configurações/Integrações, sem Mensagens
    $comoUsuario($soWeb);
    $svc = new PortalNavegacaoService();
    $cardSoWeb = array_values(array_filter($svc->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes'))[0] ?? null;
    $abasSoWeb = array_column($svc->abas('configuracoes'), 'chave');
    $check($cardSoWeb !== null && $cardSoWeb['href'] === '/admin/configuracoes/integracoes', '(13) Usuário só com recruitment_webhooks.visualizar: card Configurações aparece, entrada em Integrações');
    $check(in_array('integracoes', $abasSoWeb, true) && !in_array('mensagens', $abasSoWeb, true), '(13) Esse usuário vê a aba Integrações mas NÃO a aba Mensagens — as duas áreas são independentes');

    // ---- usuário sem nenhuma das duas permissões: sem card Configurações ----------------------------
    $comoUsuario($semNada);
    $check(array_values(array_filter((new PortalNavegacaoService())->modulos(), static fn(array $m): bool => $m['chave'] === 'configuracoes')) === [], '(2) Sem nenhuma das duas permissões, o card Configurações não aparece');

    // ---- renderização: breadcrumb Portal RH › Configurações › Mensagens ----------------------------
    $comoUsuario($rh);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $html = $renderizar(static fn() => (new AdminMensagensController())->index());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html)
        && str_contains($html, 'data-app-shell-v2="1"')
        && str_contains($html, 'aria-label="Configurações"')
        && preg_match('#aria-current="page"[^>]*>Mensagens<#i', $html) === 1
        && preg_match('#<title>[^<]+ — Portal RH</title>#', $html) === 1,
        '(breadcrumb) Mensagens renderiza no AppShell V2 com ModuleTabs de Configurações (aba Mensagens ativa) e <title>'
    );
    $_GET = [];
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
echo "\nINTEGRATION_PORTAL_CONFIGURACOES_MENSAGENS_OK\n";
