<?php

/**
 * Integração — Nova UI, Bloco C: Pessoas (Colaboradores + Usuários e Acessos) no AppShell V2. Fixtures ZZPES-* com limpeza em `finally`.
 * Prova, contra o banco:
 *   - ModuleTabs Colaboradores | Usuários e Acessos: para vários perfis, toda aba visível leva a um destino que o controller REALMENTE aceita
 *     (gate lido do controller); Colaboradores = admin/rh/supervisor (regra funcional documentada: a listagem expõe salário) e Usuários e
 *     Acessos = admin/supervisor; viewer com `colaboradores.visualizar` NÃO recebe a aba (o backend o bloqueia — divergência da sidebar antiga);
 *   - a aba da página atual aparece mesmo sem acesso (RH no detalhe de um usuário) mas sem link, e o breadcrumb não a linka;
 *   - as telas migradas renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader, ModuleTabs e <title>; nenhum campo/POST/data-attribute
 *     funcional foi perdido (listas de campos preservados), o Gestor Imediato/aprovador/contexto/vínculo/permissões seguem no detalhe;
 *   - nenhum <script> inline nem onclick=/onsubmit=/onchange= nas telas do bloco (CSP `script-src 'self'`): os comportamentos foram para
 *     `colaboradores.js`/`usuarios.js` ou para `data-confirm-message`/`data-autosubmit`;
 *   - autorização de backend inalterada; nenhuma permissão nova; sem uso de legado nas novas peças.
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
foreach (['colaboradores.visualizar', 'colaboradores.editar'] as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = [], bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZPES ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzpes.' . $suffix . '@teste.local', $senha, $role);
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
    $_SESSION['user_name'] = 'ZZPES Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
};
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
    if (preg_match_all("#Authorization::requirePermissao\\('([^']+)'\\)#", $corpo, $ps)) {
        foreach ($ps[1] as $codigo) {
            if (!Authorization::usuarioTemPermissao($u['id'], $codigo)) {
                return false;
            }
        }
    }
    return true;
};
$campos = static function (string $html): array {
    preg_match_all('/\bname="([^"]+)"/', $html, $m);
    $nomes = array_values(array_unique($m[1]));
    sort($nomes);
    return $nomes;
};

try {
    $admin = $novoUsuario('Admin', 'admin');
    $rh = $novoUsuario('RH', 'rh');
    $viewer = $novoUsuario('Viewer', 'viewer');
    $supervisor = $novoUsuario('Supervisor', 'viewer', [], true);
    $viewerColab = $novoUsuario('Viewer Colab', 'viewer', ['colaboradores.visualizar']);
    $gestor = $novoUsuario('Gestor Teste', 'viewer');
    $subordinado = $novoUsuario('Subordinado Teste', 'viewer');
    $svcGestor = new UsuarioGestorService();
    $svcGestor->definirGestor($subordinado['id'], $gestor['id'], $admin['id'], '127.0.0.1');
    $perfis = ['Admin' => $admin, 'RH' => $rh, 'viewer' => $viewer, 'supervisor' => $supervisor, 'viewer+colaboradores.visualizar' => $viewerColab];

    // ---- abas ----------------------------------------------------------------------------------------------------------------
    $def = PortalNavegacaoService::definicaoAbas()['pessoas'];
    $check(array_column($def['abas'], 'chave') === ['colaboradores', 'usuarios'] && $def['trilha_modulo'] === false, '(abas) Colaboradores | Usuários e Acessos, sem nível de módulo intermediário no breadcrumb');
    $problemas = [];
    $visiveis = [];
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        $abas = (new PortalNavegacaoService())->abas('pessoas');
        $visiveis[$rotulo] = array_column($abas, 'chave');
        foreach ($abas as $aba) {
            if (!$backendPermite($aba['href'], $u)) {
                $problemas[] = "{$rotulo}: aba \"{$aba['label']}\" levaria a 403";
            }
        }
    }
    $check($problemas === [], '(regra de ouro) Toda aba visível leva a um destino que o controller realmente aceita, para ' . count($perfis) . ' perfis' . ($problemas === [] ? '' : ': ' . implode(' | ', $problemas)));
    $check($visiveis['Admin'] === ['colaboradores', 'usuarios'] && $visiveis['supervisor'] === ['colaboradores', 'usuarios'] && $visiveis['RH'] === ['colaboradores'] && $visiveis['viewer'] === [], '(abas) Admin/supervisor veem as duas; RH só Colaboradores (lista de usuários é admin); viewer nenhuma');
    $check($visiveis['viewer+colaboradores.visualizar'] === [] && !$backendPermite('/admin/colaboradores', $viewerColab), '(divergência documentada) viewer com colaboradores.visualizar NÃO recebe a aba: o controller (admin/rh, listagem expõe salário) o bloqueia — a autorização de backend não foi alterada');

    // ---- aba atual sem acesso ---------------------------------------------------------------------------------------------------
    $comoUsuario($rh);
    $topoRh = ui_modulo_topo('', 'pessoas', 'usuarios', ['titulo' => 'Fulano'], [['label' => 'Fulano', 'href' => null]]);
    $check(preg_match('#<span aria-current="page"[^>]*>Usuários e Acessos</span>#', $topoRh) === 1 && !str_contains($topoRh, 'href="/admin/usuarios"') && str_contains($topoRh, 'href="/admin/colaboradores"') && str_contains($topoRh, 'href="/admin"'), '(aba atual) RH no detalhe de um usuário: a aba "Usuários e Acessos" aparece ativa mas SEM link (a lista é admin), e o breadcrumb não a linka');
    $comoUsuario($admin);
    $topoAdmin = ui_modulo_topo('', 'pessoas', 'usuarios', ['titulo' => 'Fulano'], [['label' => 'Fulano', 'href' => null]]);
    $check(str_contains($topoAdmin, '<a href="/admin/usuarios" aria-current="page"') && substr_count($topoAdmin, 'Trilha de navegação') === 1 && !str_contains($topoAdmin, 'Pessoas ›') && preg_match('#Portal RH</a>.*Usuários e Acessos</a>.*Fulano</span>#s', $topoAdmin) === 1, '(topo) Portal RH › Usuários e Acessos › Fulano (sem "Pessoas" no breadcrumb); aba ativa com link para o Admin');

    // ---- telas ---------------------------------------------------------------------------------------------------------------------
    $idColab = (int)$pdo->query('SELECT id FROM colaboradores ORDER BY id LIMIT 1')->fetchColumn();
    $ok = static fn(string $html, string $aba): bool => !preg_match('/Warning:|Notice:|Deprecated:|Fatal error|Erro 500/i', $html)
        && str_contains($html, 'data-app-shell-v2="1"') && !str_contains($html, 'data-admin-sidebar') && !str_contains($html, 'class="sidebar')
        && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, 'aria-label="Pessoas"') && str_contains($html, '<h1')
        && preg_match('#<title>[^<]+ — Portal RH</title>#', $html) === 1
        && preg_match('#aria-current="page"[^>]*>' . preg_quote($aba, '#') . '<#', $html) === 1;
    $_GET = [];
    $comoUsuario($admin);
    $render = static function (string $classe, string $metodo, ?string $arg = null) use ($renderizar): string {
        ui_titulo_pagina(null, true);
        ui_script_pagina(null, true);
        return $renderizar(static fn() => $arg === null ? (new $classe())->$metodo() : (new $classe())->$metodo($arg));
    };
    $htmlCol = $render(AdminColaboradoresController::class, 'index');
    $htmlRh = $render(AdminColaboradoresController::class, 'editRh', (string)$idColab);
    $htmlAcesso = $render(AdminColaboradoresController::class, 'acesso', (string)$idColab);
    $htmlUsr = $render(AdminUsuariosController::class, 'index');
    $htmlNovo = $render(AdminUsuariosController::class, 'create');
    $htmlShow = $render(AdminUsuariosController::class, 'show', (string)$subordinado['id']);
    foreach (['Colaboradores (lista)' => [$htmlCol, 'Colaboradores'], 'Dados RH' => [$htmlRh, 'Colaboradores'], 'Acesso e liderança' => [$htmlAcesso, 'Colaboradores'], 'Usuários (lista)' => [$htmlUsr, 'Usuários e Acessos'], 'Novo usuário' => [$htmlNovo, 'Usuários e Acessos'], 'Detalhe do usuário' => [$htmlShow, 'Usuários e Acessos']] as $nome => [$html, $aba]) {
        $check($ok($html, $aba), "(tela) {$nome}: AppShell V2 sem sidebar, com breadcrumb, PageHeader, ModuleTabs (aba ativa \"{$aba}\"), <title> e sem Warning/Notice");
    }

    // ---- nada funcional se perdeu --------------------------------------------------------------------------------------------------
    $check($campos($htmlCol) === ['csrf', 'cargo', 'empresa', 'page', 'per_page', 'proximo', 'q', 'setor', 'situacao'] || (count(array_diff(['q', 'empresa', 'setor', 'cargo', 'situacao', 'per_page', 'page'], $campos($htmlCol))) === 0), '(Colaboradores) Filtros e paginação preservados: q, empresa, setor, cargo, situação, per_page');
    $check(str_contains($htmlCol, 'data-autosubmit="1"') && !preg_match('/\sonchange\s*=/', $htmlCol) && str_contains($htmlCol, 'Contratos no histórico') && str_contains($htmlCol, 'Pessoas distintas') && str_contains($htmlCol, 'Lista de Colaboradores'), '(Colaboradores) "Registros por página" agora com data-autosubmit (sem onchange inline); KPIs e lista intactos (contrato = registro)');
    $check(count(array_diff(['csrf', 'codigo'], $campos($htmlRh))) === 0 && str_contains($htmlRh, 'Dados RH do colaborador'), '(Dados RH) Formulário do colaborador com csrf e campos preservados');
    $check(count(array_diff(['nome', 'email', 'senha', 'role', 'gestor_usuario_id', 'csrf'], $campos($htmlNovo))) === 0 && str_contains($htmlNovo, '/admin/usuarios/novo') && str_contains($htmlNovo, '/admin/usuarios/supervisor/garantir') && str_contains($htmlNovo, 'Identidade e acesso') && str_contains($htmlNovo, 'Hierarquia'), '(Novo usuário) Todos os campos e os dois POSTs preservados (nome, e-mail, senha, perfil, gestor imediato, csrf; garantir Supervisor), agora em seções');
    $faltando = array_diff(['gestor_usuario_id', 'pode_solicitar_vaga', 'aprovador_usuario_id', 'cargo_id', 'setor_principal_id', 'permissao_ids[]', 'active', 'new_password', 'csrf'], $campos($htmlShow));
    $check($faltando === [], '(Detalhe do usuário) Todos os formulários preservados: gestor imediato, acesso a vagas + aprovador, contexto organizacional, permissões, status e troca de senha' . ($faltando === [] ? '' : ' — FALTAM: ' . implode(',', $faltando)));
    $check(str_contains((string)file_get_contents(APP_PATH . '/views/admin/usuarios/show.php'), 'name="setores_adicionais[]"'), '(Detalhe do usuário) O campo setores_adicionais[] (depende do catálogo de setores) segue no markup');
    foreach (['Identidade e status', 'Gestor imediato', 'Solicitação de Vagas', 'Contexto organizacional', 'Permissões de acesso', 'Vínculo opcional com o METADADOS'] as $secao) {
        $check(str_contains($htmlShow, $secao), "(Detalhe do usuário) Seção \"{$secao}\" presente");
    }
    $check(str_contains($htmlShow, '/admin/usuarios/' . $subordinado['id'] . '/gestor') && str_contains($htmlShow, 'ZZPES Gestor Teste') && str_contains($htmlShow, 'href="/admin/usuarios/' . $subordinado['id'] . '/vaga-acesso"') === false && str_contains($htmlShow, '/vaga-acesso'), '(Gestor Imediato × aprovador) O gestor configurado aparece e o formulário do gestor é distinto do formulário do aprovador (vaga-acesso) — conceitos independentes preservados');
    $check(str_contains($htmlShow, 'data-metadados-vinculo="1"') && str_contains($htmlShow, 'data-busca-url=') && str_contains($htmlShow, 'data-password-url=') && str_contains($htmlShow, 'ui_script') === false && str_contains($htmlShow, '/assets/usuarios.js'), '(JS) Vínculo METADADOS e troca de senha ligados por data-attributes (URLs no HTML) e o script externo usuarios.js carregado');
    $check(str_contains($htmlShow, 'ZZPES Subordinado Teste') && substr_count($htmlShow, 'Ativo') >= 1, '(Detalhe do usuário) Identidade e status (badge Ativo) exibidos');
    $htmlAcesso2 = $render(AdminColaboradoresController::class, 'acesso', (string)$idColab);
    $check(count(array_diff(['csrf'], $campos($htmlAcesso2))) === 0 && !preg_match('/\sonclick\s*=/', $htmlAcesso2) && str_contains($htmlAcesso2, 'Acesso e liderança'), '(Acesso e liderança) Sem onclick inline (confirmações via data-confirm-message); CSRF e conteúdo preservados');
    $check(str_contains((string)file_get_contents(APP_PATH . '/views/admin/colaboradores/acesso.php'), 'data-confirm-message="Desativar o acesso deste líder?') && str_contains((string)file_get_contents(APP_PATH . '/views/admin/colaboradores/acesso.php'), 'data-confirm-message="Gerar uma nova senha temporária'), '(Acesso e liderança) As duas confirmações originais (desativar acesso, redefinir senha) mantidas como data-confirm-message');

    // RH: detalhe do usuário (RH só administra contexto/vínculo) e listas
    $comoUsuario($rh);
    $htmlShowRh = $render(AdminUsuariosController::class, 'show', (string)$subordinado['id']);
    $check(!preg_match('/Warning:|Notice:|Fatal error|Erro 500|Acesso negado/i', $htmlShowRh) && str_contains($htmlShowRh, 'Identidade e status') && preg_match('#<span aria-current="page"[^>]*>Usuários e Acessos</span>#', $htmlShowRh) === 1, '(RH) O detalhe do usuário abre para RH (gate admin/rh preservado) com a aba atual sem link');
    $check(!str_contains($htmlShowRh, 'permissao_ids[]') && !str_contains($htmlShowRh, 'name="active"') && !str_contains($htmlShowRh, 'name="new_password"') && !str_contains($htmlShowRh, 'name="aprovador_usuario_id"') && str_contains($htmlShowRh, 'Contexto organizacional'), '(RH) Vê contexto organizacional/vínculo, mas NÃO os controles administrativos (permissões, status, senha, gestor, acesso a vagas)');
    $check(str_contains($htmlShowRh, 'gerenciado por um administrador') && !str_contains($htmlShowRh, '/gestor"'), '(RH) O gestor imediato aparece em leitura ("gerenciado por um administrador")');

    // ---- CSP: nada inline nas telas do bloco ---------------------------------------------------------------------------------------
    $infratores = [];
    foreach (['colaboradores/index.php', 'colaboradores/acesso.php', 'colaboradores/rh-form.php', 'usuarios/index.php', 'usuarios/create.php', 'usuarios/show.php'] as $v) {
        $c = preg_replace('/<\?php.*?\?>/s', '', (string)file_get_contents(APP_PATH . '/views/admin/' . $v));
        if (preg_match('/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>/i', $c) || preg_match('/\son(click|submit|change|input|load|keyup|blur|focus)\s*=/i', $c)) {
            $infratores[] = $v;
        }
    }
    $check($infratores === [], '(CSP) Nenhuma tela de Colaboradores/Usuários tem <script> inline nem onclick=/onsubmit=/onchange=' . ($infratores === [] ? '' : ': ' . implode(', ', $infratores)));
    $jsCol = (string)file_get_contents(BASE_PATH . '/assets/colaboradores.js');
    $jsUsr = (string)file_get_contents(BASE_PATH . '/assets/usuarios.js');
    $check(str_contains($jsCol, 'data-integracao-status') && str_contains($jsCol, 'data-select-on-click') && str_contains($jsUsr, 'data-metadados-vinculo') && str_contains($jsUsr, 'openPasswordModal') && str_contains($jsUsr, 'encodeURIComponent') && str_contains($jsUsr, 'JSON.stringify'), '(JS) colaboradores.js e usuarios.js preservam a lógica original (integração, seleção do link, busca METADADOS, modal e API de senha)');
    $check(str_contains((string)file_get_contents(APP_PATH . '/views/admin/colaboradores/rh-form.php'), "ui_script_pagina('colaboradores.js')") && !str_contains((string)file_get_contents(APP_PATH . '/views/admin/colaboradores/index.php'), 'ui_script_pagina'), '(JS) colaboradores.js é registrado só pela tela Dados RH (não pela lista)');
    $check(!str_contains($htmlCol, '/assets/usuarios.js') && !str_contains($htmlUsr, '/assets/colaboradores.js'), '(JS) Sem mistura: Colaboradores não carrega o JS de Usuários e vice-versa');

    // ---- autorização inalterada / sem legado -----------------------------------------------------------------------------------------
    $gates = [
        [AdminColaboradoresController::class, 'index', "Auth::requireRole(['admin', 'rh'])"],
        [AdminColaboradoresController::class, 'editRh', "Auth::requireRole(['admin', 'rh'])"],
        [AdminColaboradoresController::class, 'acesso', "Auth::requireRole(['admin', 'rh'])"],
        [AdminUsuariosController::class, 'index', "Auth::requireRole(['admin'])"],
        [AdminUsuariosController::class, 'create', "Auth::requireRole(['admin'])"],
        [AdminUsuariosController::class, 'show', "Auth::requireRole(['admin', 'rh'])"],
        [AdminUsuariosController::class, 'updatePermissoes', "Auth::requireRole(['admin'])"],
        [AdminUsuariosController::class, 'updateGestor', "Auth::requireRole(['admin'])"],
        [AdminUsuariosController::class, 'updateContextoOrganizacional', "Auth::requireRole(['admin', 'rh'])"],
        [AdminUsuariosController::class, 'vincularMetadados', "Auth::requireRole(['admin', 'rh'])"],
    ];
    $gatesOk = true;
    foreach ($gates as [$classe, $metodo, $gate]) {
        $gatesOk = $gatesOk && str_contains($corpoDe($classe, $metodo), $gate) && !str_contains($corpoDe($classe, $metodo), 'requireRoleOuPermissao');
    }
    $check($gatesOk, '(autorização) Os gates de Colaboradores e Usuários (leitura e escrita) estão exatamente como antes — só o layout mudou');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova');
    $novos = (string)file_get_contents(APP_PATH . '/services/PortalNavegacaoService.php') . (string)file_get_contents(APP_PATH . '/views/partials/modulo-topo.php');
    $check(!preg_match('/usuario_colaboradores|lider_colaborador_id|is_gestor/i', $novos), '(legado) As peças novas de navegação não usam usuario_colaboradores/lider_colaborador_id/is_gestor');
} finally {
    $_SESSION = $sessaoOriginal;
    if ($criados !== []) {
        $lista = implode(',', array_map('intval', $criados));
        $pdo->exec("UPDATE usuarios SET gestor_usuario_id = NULL WHERE id IN ($lista)");
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($lista) OR actor_usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lista)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nINTEGRATION_PORTAL_MODULO_PESSOAS_OK\n";
