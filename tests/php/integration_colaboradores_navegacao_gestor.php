<?php

/**
 * Integração — Navegação de Colaboradores para o escopo gerencial (correção de 2026-11, segunda
 * parte do caso Odair Pedro Tonin). O commit 244357a corrigiu o BACKEND
 * (AdminColaboradoresController::index()): um viewer com `colaboradores.visualizar` E pelo menos
 * um Setor Gerenciado passou a conseguir consultar a listagem, escopada. Mas a navegação
 * (PortalNavegacaoService, regra `staff`) continuou sem mostrar o item — o usuário só conseguia
 * entrar digitando a URL direto. Esta correção troca a regra do item "Colaboradores" (Central e
 * módulo Pessoas) para a nova regra composable `staff_ou_setor_gerenciado:<codigo>`, que espelha
 * EXATAMENTE o gate do controller.
 *
 * Prova, contra o banco:
 *   - Admin/RH continuam vendo o item (regra `staff`, sem alteração de comportamento);
 *   - Viewer com a permissão E Setor Gerenciado agora VÊ o item, na Central e no módulo Pessoas;
 *   - Viewer sem a permissão não vê, mesmo com Setor Gerenciado (setor nunca substitui a permissão);
 *   - Viewer com a permissão mas sem nenhum Setor Gerenciado não vê (setor de atuação/principal
 *     nunca conta — só usuario_setores_gerenciados);
 *   - Clicar no item abre de fato a listagem já escopada (render real via sessão simulada);
 *   - Nenhuma ação administrativa (Editar dados RH/Acesso e liderança) aparece para esse perfil;
 *   - A navegação é só representação: mesmo vendo o item, os outros endpoints do módulo
 *     (edição/acesso) continuam 403 para esse usuário — ver nunca é igual a poder editar.
 *
 * Fixtures ZZCOLNAV-* com limpeza em `finally`.
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

$suffix = substr((string)time(), -4) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'setores' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$mkSetor = static function (string $codigo, string $nome) use ($pdo, &$criados): int {
    $stmt = $pdo->prepare(
        "INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados)
         VALUES (?, ?, ?, ?, 1, 'A', 'zzcolnav-teste')"
    );
    $stmt->execute([$codigo, $nome, $nome, 'zzcolnav-' . strtolower($codigo) . '-' . uniqid()]);
    $id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $id;
    return $id;
};

$mkUsuario = static function (string $nome) use ($pdo, &$criados, $senha, $suffix): int {
    static $i = 0;
    $i++;
    $id = User::create("ZZCOLNAV {$nome}", "zzcolnav.{$suffix}.{$i}@teste.local", $senha, 'viewer');
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

$concederPermissao = static function (int $usuarioId, string $codigo) use ($pdo): void {
    $permId = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = " . $pdo->quote($codigo))->fetchColumn();
    if ($permId <= 0) {
        throw new RuntimeException("Permissão '{$codigo}' não existe no catálogo.");
    }
    $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$usuarioId, $permId]);
};

$comoUsuario = static function (int $usuarioId, string $role, bool $supervisor = false): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $usuarioId;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = 'ZZCOLNAV Teste';
    $_SESSION['user_is_supervisor'] = $supervisor ? 1 : 0;
};

try {
    $setorGestor = $mkSetor('ZZCNV1', 'ZZCOLNAV Logística e Transportes');

    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $rh = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'rh' ORDER BY id LIMIT 1")->fetchColumn();
    $check($admin > 0 && $rh > 0, '(pré-condição) Existem usuários admin e RH locais');

    $gestorOk = $mkUsuario('Gestor OK (permissão + Setor Gerenciado)');
    $concederPermissao($gestorOk, 'colaboradores.visualizar');
    (new UsuarioSetoresGerenciadosService())->definirSetoresGerenciados($gestorOk, [$setorGestor]);

    $viewerSemPermissao = $mkUsuario('Viewer Sem Permissão (com Setor Gerenciado)');
    (new UsuarioSetoresGerenciadosService())->definirSetoresGerenciados($viewerSemPermissao, [$setorGestor]);

    $viewerSemSetor = $mkUsuario('Viewer Com Permissão, Sem Setor Gerenciado');
    $concederPermissao($viewerSemSetor, 'colaboradores.visualizar');

    $viewerPlano = $mkUsuario('Viewer Plano (nada)');

    $sessaoOriginal = $_SESSION;
    try {
        // ---- 1/2) Admin e RH veem o item, como antes --------------------------------------------
        $comoUsuario($admin, 'admin');
        $nav = new PortalNavegacaoService();
        $check($nav->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(1) Admin vê o item Colaboradores (regra staff, sem alteração)');
        $abasAdmin = (new PortalNavegacaoService())->abas('pessoas');
        $check(in_array('colaboradores', array_column($abasAdmin, 'chave'), true), '(1) Admin: aba "Colaboradores" aparece no módulo Pessoas');
        $modulosAdmin = (new PortalNavegacaoService())->modulos();
        $cardColabAdmin = current(array_filter($modulosAdmin, static fn(array $m): bool => $m['chave'] === 'colaboradores'));
        $check(($cardColabAdmin['href'] ?? null) === '/admin/colaboradores', '(1) Admin: card da Central aponta para a listagem real, não para Movimentações');

        $comoUsuario($rh, 'rh');
        $check((new PortalNavegacaoService())->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(2) RH vê o item Colaboradores (regra staff, sem alteração)');

        // ---- 3) Viewer com permissão + Setor Gerenciado: item aparece, na Central e em Pessoas ---
        $comoUsuario($gestorOk, 'viewer');
        $check((new PortalNavegacaoService())->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(3) Gestor OK (permissão + Setor Gerenciado) vê o item Colaboradores');
        $abasGestor = (new PortalNavegacaoService())->abas('pessoas');
        $check(in_array('colaboradores', array_column($abasGestor, 'chave'), true), '(3) Gestor OK: aba "Colaboradores" aparece no módulo Pessoas');
        $modulosGestor = (new PortalNavegacaoService())->modulos();
        $cardColabGestor = current(array_filter($modulosGestor, static fn(array $m): bool => $m['chave'] === 'colaboradores'));
        $check(($cardColabGestor['href'] ?? null) === '/admin/colaboradores', '(3) Gestor OK: card da Central agora aponta para a listagem real (antes caía em Movimentações)');

        // ---- 4) Viewer SEM a permissão, mesmo com Setor Gerenciado: item NUNCA aparece -----------
        $comoUsuario($viewerSemPermissao, 'viewer');
        $check(!(new PortalNavegacaoService())->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(4) Viewer sem a permissão NÃO vê o item, mesmo tendo Setor Gerenciado — setor nunca substitui a permissão');
        $abasSemPerm = (new PortalNavegacaoService())->abas('pessoas');
        $check(!in_array('colaboradores', array_column($abasSemPerm, 'chave'), true), '(4) Viewer sem a permissão: aba "Colaboradores" não aparece');

        // ---- 5) Viewer com a permissão, SEM nenhum Setor Gerenciado: item NUNCA aparece ----------
        $comoUsuario($viewerSemSetor, 'viewer');
        $check(!(new PortalNavegacaoService())->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(5) Viewer com a permissão mas SEM Setor Gerenciado NÃO vê o item');
        $modulosSemSetor = (new PortalNavegacaoService())->modulos();
        $cardColabSemSetor = current(array_filter($modulosSemSetor, static fn(array $m): bool => $m['chave'] === 'colaboradores'));
        $check(($cardColabSemSetor['href'] ?? null) === '/admin/movimentacoes-pessoal', '(5) Viewer sem Setor Gerenciado: card da Central continua caindo em Movimentações');

        // ---- viewer plano (nada): item nunca aparece (baseline) -----------------------------------
        $comoUsuario($viewerPlano, 'viewer');
        $check(!(new PortalNavegacaoService())->visivel('staff_ou_setor_gerenciado:colaboradores.visualizar'), '(baseline) Viewer sem permissão nem setor não vê o item');

        // ---- 6) "Clicar no item" abre de fato a listagem já escopada (render real) --------------
        $comoUsuario($gestorOk, 'viewer');
        $_GET = [];
        ob_start();
        (new AdminColaboradoresController())->index();
        $html = (string)ob_get_clean();
        $check(!str_contains($html, 'Acesso negado'), '(6) Gestor OK: clicar no item abre a listagem de verdade, sem 403');
        $check(str_contains($html, 'Lista de Colaboradores'), '(6) Gestor OK: a página renderizada é realmente a listagem de Colaboradores');

        // ---- 7) Nenhuma ação administrativa aparece para esse perfil ----------------------------
        $check(!str_contains($html, 'Editar dados RH') && !str_contains($html, 'Acesso e liderança') && !str_contains($html, '>Salário<'), '(7) Render real: nenhuma ação administrativa nem a coluna Salário aparecem para o Gestor');

        // ---- 8) Navegação é só representação: ver o item nunca libera os outros endpoints -------
        // Auth::requireRole() termina o processo com exit() quando nega — nunca chamar diretamente
        // um método que vai negar (destruiria o resto do teste/cleanup). Inspeção de código, mesmo
        // padrão já usado em integration_colaboradores_escopo_gestor.php (9): confirma que editRh()
        // continua exigindo só admin/rh, mesmo para o Gestor que agora vê o item de navegação.
        $rEditRh = new ReflectionMethod(AdminColaboradoresController::class, 'editRh');
        $arqEditRh = new SplFileObject($rEditRh->getFileName());
        $arqEditRh->seek($rEditRh->getStartLine() - 1);
        $corpoEditRh = '';
        while ($arqEditRh->key() < $rEditRh->getEndLine()) { $corpoEditRh .= $arqEditRh->current(); $arqEditRh->next(); }
        $check(str_contains($corpoEditRh, "Auth::requireRole(['admin', 'rh'])"), '(8) editRh() continua exigindo só admin/rh — o Gestor vê o item de navegação, mas isso nunca libera edição (navegação nunca é autorização)');
    } finally {
        $_SESSION = $sessaoOriginal;
    }

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nCOLABORADORES_NAVEGACAO_GESTOR_OK\n";
} finally {
    if (!empty($criados['usuarios'])) {
        $ph = implode(',', array_fill(0, count($criados['usuarios']), '?'));
        $pdo->prepare("DELETE FROM usuario_setores_gerenciados WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM usuario_permissoes WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($criados['usuarios']);
    }
    if (!empty($criados['setores'])) {
        $ph = implode(',', array_fill(0, count($criados['setores']), '?'));
        $pdo->prepare("DELETE FROM setores WHERE id IN ($ph)")->execute($criados['setores']);
    }
}
