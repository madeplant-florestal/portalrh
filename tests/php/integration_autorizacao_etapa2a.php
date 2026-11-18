<?php

/**
 * Integração — Etapa 2A (2026-11): correção das falhas de autorização apontadas na auditoria.
 *
 * Cada requisição roda num PROCESSO próprio (fixture_requisicao_http.php), porque os gates terminam com exit/403.
 * Prova, por endpoint e por ator (viewer sem permissão, viewer com TODAS as permissões legadas, viewer com is_supervisor=1,
 * admin, RH, admin supervisor), que:
 *   - People Analytics: viewer autorizado recebe só o AGREGADO (sem nome no HTML nem no JSON); CSV nominal é 403; admin/RH
 *     mantêm lista e CSV;
 *   - Candidaturas (lista/detalhe), Pipeline, Indicações (tela + 4 APIs financeiras) e Avaliações legado: 403 para viewer,
 *     mesmo com a permissão `.visualizar` gravada (permissão legada não concede acesso);
 *   - Indicadores de RH: viewer só com `indicadores_rh.visualizar`; sem ela, 403;
 *   - is_supervisor=1 com role viewer não é bypass (nem para gates admin/rh, nem para os de permissão); admin supervisor segue
 *     como admin;
 *   - a resposta 403 não carrega nenhum dado protegido.
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
$empresa = 'ZZ2A' . $suffix;
$sentinela = 'ZZ2A SENTINELA ' . $suffix;

/** @return array{0:int,1:string} [status HTTP, corpo] */
$requisitar = static function (int $userId, string $role, bool $supervisor, string $controller, string $metodo, array $get = [], array $args = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $supervisor ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m)) {
        $status = (int)$m[1];
        $saida = substr($saida, 0, -strlen($m[0]));
    }
    return [$status, $saida];
};

try {
    $codigos = ['dashboard.visualizar', 'candidaturas.visualizar', 'candidaturas.editar', 'indicacoes.visualizar', 'indicacoes.gerenciar_pagamento',
        'pipeline.visualizar', 'pipeline.movimentar', 'indicadores_rh.visualizar', 'colaboradores.visualizar'];
    $in = implode(',', array_fill(0, count($codigos), '?'));
    $stmt = $pdo->prepare("SELECT id, codigo FROM permissoes WHERE codigo IN ($in) AND ativo = 1");
    $stmt->execute($codigos);
    $permId = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id', 'codigo');
    $check(count($permId) === count($codigos), '(setup) as permissões do teste existem no catálogo');

    $novo = static function (string $apelido, string $role, array $permissoes) use (&$criados, $pdo, $senha, $suffix, $permId): int {
        $id = User::create('ZZ2A ' . $apelido, strtolower($apelido) . '.zz2a.' . $suffix . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        if ($permissoes !== []) {
            Authorization::sincronizar($id, array_map(static fn(string $c): int => (int)$permId[$c], $permissoes));
        }
        return $id;
    };
    $admin = $novo('admin', 'admin', []);
    $rh = $novo('rh', 'rh', []);
    $viewerSem = $novo('viewersem', 'viewer', []);
    $viewerDash = $novo('viewerdash', 'viewer', ['dashboard.visualizar']);
    $viewerTudo = $novo('viewertudo', 'viewer', $codigos);                       // todas as permissões legadas
    $viewerSupTudo = $novo('viewersuptudo', 'viewer', $codigos);                 // + is_supervisor=1
    $viewerSupSem = $novo('viewersupsem', 'viewer', []);                         // is_supervisor=1 sem permissão alguma
    $rhSup = $novo('rhsup', 'rh', []);                                           // role rh + is_supervisor=1
    $adminSup = $novo('adminsup', 'admin', []);                                  // admin supervisor legítimo

    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, cargo, codigo_cargo, admissao, demissao, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?)'
    )->execute(['ZZ2A_' . $suffix, $empresa, 'U' . $suffix, 'C' . $suffix, 'P' . $suffix, '39053344705', $sentinela, 'Empresa 2A', 'Cargo 2A', 'CG2A' . $suffix, '2024-01-10', 'zz2a-teste']);
    $criados['metadados'][] = 'ZZ2A_' . $suffix;

    // ---- 1. People Analytics ------------------------------------------------------------------------------------------------
    $filtro = ['empresa' => $empresa, 'periodo' => '12m'];
    foreach (['index' => 'página', 'dadosDashboard' => 'JSON /dados'] as $metodo => $rotulo) {
        [$st, $corpo] = $requisitar($admin, 'admin', false, 'AdminController', $metodo, $filtro);
        $check($st === 200 && str_contains($corpo, $sentinela), "(People Analytics {$rotulo}) admin continua vendo a lista nominal");
        $check(str_contains($corpo, 'colaboradores/exportar') || str_contains($corpo, 'colaboradores\/exportar'), "(People Analytics {$rotulo}) admin continua com o link do CSV (valida o marcador do teste)");
        [$st, $corpo] = $requisitar($rh, 'rh', false, 'AdminController', $metodo, $filtro);
        $check($st === 200 && str_contains($corpo, $sentinela), "(People Analytics {$rotulo}) RH continua vendo a lista nominal");
        foreach (['viewerDash' => $viewerDash, 'viewerTudo' => $viewerTudo] as $nome => $uid) {
            [$st, $corpo] = $requisitar($uid, 'viewer', false, 'AdminController', $metodo, $filtro);
            $check($st === 200 && !str_contains($corpo, $sentinela) && !str_contains($corpo, '390.533.447-05') && !str_contains($corpo, '39053344705'), "(People Analytics {$rotulo}) {$nome} recebe o painel SEM nome/CPF no corpo");
            $check($st === 200 && !str_contains($corpo, 'colaboradores\/exportar') && !str_contains($corpo, 'colaboradores/exportar') && !str_contains($corpo, 'data-pa-pagina="2"'), "(People Analytics {$rotulo}) {$nome} não recebe nem o link do CSV nem a seção da lista");
            $check($st === 200 && (str_contains($corpo, 'Headcount') || str_contains($corpo, 'Turnover')), "(People Analytics {$rotulo}) {$nome} mantém os indicadores agregados");
        }
        [$st, $corpo] = $requisitar($viewerSem, 'viewer', false, 'AdminController', $metodo, $filtro);
        $check($st === 403 && trim($corpo) === 'Acesso negado', "(People Analytics {$rotulo}) viewer sem dashboard.visualizar: 403 sem dados");
    }
    // contexto/filtros que antes alimentavam a lista não a ressuscitam para viewer
    [$st, $corpo] = $requisitar($viewerDash, 'viewer', false, 'AdminController', 'dadosDashboard', $filtro + ['contexto_lista' => 'desligados', 'mes_evento' => '2024-01', 'pagina' => '1', 'por_pagina' => '50']);
    $check($st === 200 && !str_contains($corpo, $sentinela), '(People Analytics) filtros de lista/paginação enviados por viewer não revelam nomes');

    // CSV nominal
    [$st, $corpo] = $requisitar($admin, 'admin', false, 'AdminController', 'exportarColaboradores', $filtro);
    $check($st === 200 && str_contains($corpo, $sentinela) && str_contains($corpo, 'Nome'), '(CSV) admin exporta normalmente');
    [$st, $corpo] = $requisitar($rh, 'rh', false, 'AdminController', 'exportarColaboradores', $filtro);
    $check($st === 200 && str_contains($corpo, $sentinela), '(CSV) RH exporta normalmente');
    foreach (['viewerDash' => $viewerDash, 'viewerTudo' => $viewerTudo, 'viewerSem' => $viewerSem] as $nome => $uid) {
        [$st, $corpo] = $requisitar($uid, 'viewer', false, 'AdminController', 'exportarColaboradores', $filtro);
        $check($st === 403 && !str_contains($corpo, $sentinela) && trim($corpo) === 'Acesso negado', "(CSV) {$nome}: 403 e nenhum dado no corpo");
    }
    [$st, $corpo] = $requisitar($viewerSupTudo, 'viewer', true, 'AdminController', 'exportarColaboradores', $filtro);
    $check($st === 403 && !str_contains($corpo, $sentinela), '(CSV) viewer com is_supervisor=1 também recebe 403');

    // ---- 2. Candidaturas, Pipeline, Indicações, Avaliações legado ---------------------------------------------------------
    $idTerceiro = (int)($pdo->query('SELECT id FROM candidaturas ORDER BY id LIMIT 1')->fetchColumn() ?: 999999);
    $rotas = [
        'Candidaturas lista' => ['AdminCandidaturasController', 'index', [], []],
        'Candidaturas detalhe (ID de terceiro)' => ['AdminCandidaturasController', 'show', [], [(string)$idTerceiro]],
        'Pipeline' => ['AdminPipelineController', 'index', [], []],
        'Indicações tela' => ['AdminIndicacoesController', 'index', [], []],
        'Indicações status API' => ['AdminIndicacoesController', 'statusApi', [], [(string)$idTerceiro]],
        'Financeiro contas a receber' => ['AdminIndicacoesController', 'contasReceberApi', ['pagamento' => 'pendente'], []],
        'Financeiro conciliação' => ['AdminIndicacoesController', 'conciliacaoApi', [], []],
        'Financeiro relatórios' => ['AdminIndicacoesController', 'relatoriosFinanceirosApi', [], []],
        'Avaliações legado' => ['AdminAvaliacoesController', 'index', [], []],
    ];
    $atoresViewer = [
        'viewer sem permissão' => [$viewerSem, 'viewer', false],
        'viewer com TODAS as permissões legadas' => [$viewerTudo, 'viewer', false],
        'viewer com is_supervisor=1 + permissões' => [$viewerSupTudo, 'viewer', true],
        'viewer com is_supervisor=1 sem permissão' => [$viewerSupSem, 'viewer', true],
    ];
    foreach ($rotas as $nome => [$ctrl, $metodo, $get, $args]) {
        foreach ($atoresViewer as $ator => [$uid, $role, $sup]) {
            [$st, $corpo] = $requisitar($uid, $role, $sup, $ctrl, $metodo, $get, $args);
            $check($st === 403 && trim($corpo) === 'Acesso negado', "({$nome}) {$ator}: 403 e corpo sem dados");
        }
        foreach (['admin' => [$admin, 'admin', false], 'RH' => [$rh, 'rh', false], 'admin supervisor' => [$adminSup, 'admin', true]] as $ator => [$uid, $role, $sup]) {
            [$st, $corpo] = $requisitar($uid, $role, $sup, $ctrl, $metodo, $get, $args);
            $check($st !== 403 && !str_contains($corpo, 'Acesso negado'), "({$nome}) {$ator} continua com acesso (status {$st})");
        }
    }
    // APIs JSON: formato preservado para quem tem acesso
    [$st, $corpo] = $requisitar($rh, 'rh', false, 'AdminIndicacoesController', 'relatoriosFinanceirosApi');
    $json = json_decode($corpo, true);
    $check($st === 200 && is_array($json) && ($json['ok'] ?? false) === true && isset($json['totais'], $json['items']), '(Financeiro relatórios) RH recebe o mesmo contrato JSON de antes');

    // ---- 3. Indicadores de RH ----------------------------------------------------------------------------------------------
    [$st, $corpo] = $requisitar($viewerSem, 'viewer', false, 'AdminRhIndicadoresController', 'index');
    $check($st === 403 && trim($corpo) === 'Acesso negado', '(Indicadores RH) viewer sem a permissão: 403');
    [$st] = $requisitar($viewerDash, 'viewer', false, 'AdminRhIndicadoresController', 'index');
    $check($st === 403, '(Indicadores RH) outra permissão (dashboard.visualizar) não abre a tela');
    [$st, $corpo] = $requisitar($viewerTudo, 'viewer', false, 'AdminRhIndicadoresController', 'index');
    $check($st === 200 && !str_contains($corpo, $sentinela), '(Indicadores RH) viewer com indicadores_rh.visualizar abre, só agregados');
    [$st] = $requisitar($viewerSupSem, 'viewer', true, 'AdminRhIndicadoresController', 'index');
    $check($st === 403, '(Indicadores RH) is_supervisor=1 sem a permissão continua 403');
    [$st] = $requisitar($viewerSupTudo, 'viewer', true, 'AdminRhIndicadoresController', 'index');
    $check($st === 200, '(Indicadores RH) viewer supervisor COM a permissão abre (pela permissão, não pelo flag)');
    foreach (['admin' => [$admin, 'admin', false], 'RH' => [$rh, 'rh', false]] as $ator => [$uid, $role, $sup]) {
        [$st] = $requisitar($uid, $role, $sup, 'AdminRhIndicadoresController', 'index');
        $check($st === 200, "(Indicadores RH) {$ator} mantém acesso global");
    }

    // ---- 4. is_supervisor não é bypass ------------------------------------------------------------------------------------
    foreach (['AdminUsuariosController' => 'index'] as $ctrl => $metodo) {
        [$st] = $requisitar($viewerSupSem, 'viewer', true, $ctrl, $metodo);
        $check($st === 403, "({$ctrl}) viewer com is_supervisor=1 não acessa gate exclusivo de admin");
        [$st] = $requisitar($rhSup, 'rh', true, $ctrl, $metodo);
        $check($st === 403, "({$ctrl}) RH com is_supervisor=1 não acessa gate exclusivo de admin");
        [$st] = $requisitar($adminSup, 'admin', true, $ctrl, $metodo);
        $check($st === 200, "({$ctrl}) admin supervisor continua acessando");
    }
    // lógica pura (sessão de processo)
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $viewerSupSem;
    $_SESSION['user_role'] = 'viewer';
    $_SESSION['user_is_supervisor'] = true; // sessão antiga, anterior à regra
    $check(Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'qualquer.codigo') === false && empty($_SESSION['user_is_supervisor']), '(sessão antiga) is_supervisor numa sessão de viewer é neutralizado e não dá acesso');
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = true;
    Auth::normalizarSupervisorDaSessao();
    $check(!empty($_SESSION['user_is_supervisor']), '(sessão) admin supervisor mantém o flag');
    $_SESSION['user_role'] = 'viewer';
    $_SESSION['user_is_supervisor'] = false;
    $check(Authorization::primeiraRotaAcessivel() === '/admin', '(login) destino inicial do viewer continua a Central');

    // permissão legada gravada não concede o acesso nominal (continua armazenada)
    $gravadas = Authorization::idsAtribuidos($viewerTudo);
    $check(in_array((int)$permId['candidaturas.visualizar'], $gravadas, true) && in_array((int)$permId['indicacoes.visualizar'], $gravadas, true), '(legado) as permissões continuam gravadas em usuario_permissoes — não foram apagadas');
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
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — autorização Etapa 2A.\n";
