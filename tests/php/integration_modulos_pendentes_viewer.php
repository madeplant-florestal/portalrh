<?php

/**
 * Integração — Etapa 2B complementar (2026-11): Entrevista de Desligamento, Pesquisa de Reação e Mensagens deixam de ser acessíveis
 * a `viewer`, mesmo com as permissões legadas gravadas. Cada ação é chamada por requisição direta (processo próprio), inclusive por ID
 * de terceiros, e a resposta 403 não carrega dado. Admin/RH mantêm o acesso; o dashboard AGREGADO da Entrevista de Desligamento
 * (permissão própria) continua aberto ao viewer autorizado. Nenhuma linha de usuario_permissoes é apagada e nenhum dado dos módulos muda.
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
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$criados = [];
$ids = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ids[$r['codigo']] = (int)$r['id'];
}
$novo = static function (string $apelido, string $role, int $flag, array $codigos) use ($pdo, $senha, $suffix, &$criados, $ids): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZPEN ' . $apelido, strtolower($apelido) . '.zzpen.' . $suffix . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $criados[] = $id;
    foreach ($codigos as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $ids[$c]]);
    }
    return $id;
};
$requisitar = static function (int $userId, string $role, bool $sessaoSup, string $controller, string $metodo, bool $post, array $args): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sessaoSup ? '1' : '0', $controller, $metodo, '{}', json_encode($args), $post ? json_encode(['x' => '1']) : ''],
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
$contagens = static function () use ($pdo): array {
    $r = [];
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (preg_match('/desligamento|pesquisa|mensagens|usuario_permissoes|^usuarios$|^permissoes$/i', (string)$t)) {
            $r[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
        }
    }
    return $r;
};

$ED = ['entrevista_desligamento.visualizar', 'entrevista_desligamento.gerenciar', 'entrevista_desligamento.resultados'];
$PR = ['pesquisa_reacao_integracao.visualizar', 'pesquisa_reacao_integracao.gerenciar'];
$MG = ['mensagens.visualizar', 'mensagens.criar', 'mensagens.editar'];

// [rótulo, controller, método, POST?, args]
$acoes = [
    ['Entrevistas: lista', 'AdminEntrevistaDesligamentoController', 'index', false, []],
    ['Entrevistas: gerar', 'AdminEntrevistaDesligamentoController', 'gerar', true, []],
    ['Entrevistas: regenerar (ID de terceiro)', 'AdminEntrevistaDesligamentoController', 'regenerar', true, ['1']],
    ['Entrevistas: cancelar (ID de terceiro)', 'AdminEntrevistaDesligamentoController', 'cancelar', true, ['1']],
    ['Entrevistas: resultado individual (ID de terceiro)', 'AdminEntrevistaDesligamentoController', 'resultado', false, ['1']],
    ['Pesquisa de Reação: central', 'AdminPesquisaReacaoIntegracaoController', 'index', false, []],
    ['Pesquisa de Reação: criar campanha', 'AdminPesquisaReacaoIntegracaoController', 'store', true, []],
    ['Pesquisa de Reação: desativar (ID de terceiro)', 'AdminPesquisaReacaoIntegracaoController', 'desativar', true, ['1']],
    ['Pesquisa de Reação: resultados (ID de terceiro)', 'AdminPesquisaReacaoIntegracaoController', 'resultados', false, ['1']],
    ['Mensagens: lista', 'AdminMensagensController', 'index', false, []],
    ['Mensagens: novo (form)', 'AdminMensagensController', 'create', false, []],
    ['Mensagens: criar', 'AdminMensagensController', 'store', true, []],
    ['Mensagens: editar (ID de terceiro)', 'AdminMensagensController', 'edit', false, ['1']],
    ['Mensagens: salvar edição (ID de terceiro)', 'AdminMensagensController', 'update', true, ['1']],
];

try {
    $antes = $contagens();
    $vED = $novo('ved', 'viewer', 0, $ED);
    $vPR = $novo('vpr', 'viewer', 0, $PR);
    $vMG = $novo('vmg', 'viewer', 0, $MG);
    $vTodos = $novo('vtodos', 'viewer', 0, array_merge($ED, $PR, $MG, ['dashboard_entrevista_desligamento.visualizar']));
    $vFlag = $novo('vflag', 'viewer', 1, array_merge($ED, $PR, $MG));
    $rh = $novo('rh', 'rh', 0, []);
    $adm = $novo('adm', 'admin', 0, []);
    $sg = $novo('sg', 'admin', 1, []);
    $gravadasAntes = (int)$pdo->query('SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id IN (' . implode(',', [$vED, $vPR, $vMG, $vTodos, $vFlag]) . ')')->fetchColumn();
    $check($gravadasAntes === 3 + 2 + 3 + 9 + 8, '(setup) 25 permissões legadas gravadas nos viewers de teste');

    $atores = [
        'viewer com permissões de Entrevista de Desligamento' => [$vED, 'viewer', false],
        'viewer com permissões de Pesquisa de Reação' => [$vPR, 'viewer', false],
        'viewer com permissões de Mensagens' => [$vMG, 'viewer', false],
        'viewer com TODAS as permissões legadas' => [$vTodos, 'viewer', false],
        'viewer com is_supervisor=1 + todas' => [$vFlag, 'viewer', true],
    ];
    foreach ($acoes as [$rotulo, $ctrl, $metodo, $post, $args]) {
        foreach ($atores as $ator => [$uid, $role, $sup]) {
            [$st, $corpo] = $requisitar($uid, $role, $sup, $ctrl, $metodo, $post, $args);
            $check($st === 403 && trim($corpo) === 'Acesso negado', "({$rotulo}) {$ator}: 403 sem dados");
        }
        foreach (['RH' => [$rh, 'rh', false], 'admin' => [$adm, 'admin', false], 'Supervisor Geral' => [$sg, 'admin', true]] as $ator => [$uid, $role, $sup]) {
            [$st, $corpo] = $requisitar($uid, $role, $sup, $ctrl, $metodo, $post, $args);
            $check($st !== 403 && !str_contains($corpo, 'Acesso negado'), "({$rotulo}) {$ator} mantém o acesso (status {$st})");
        }
    }

    // o dashboard AGREGADO da Entrevista de Desligamento tem permissão própria e segue aberto ao viewer autorizado
    [$st] = $requisitar($vTodos, 'viewer', false, 'AdminDashboardEntrevistaDesligamentoController', 'index', false, []);
    $check($st === 200, '(agregado) viewer com dashboard_entrevista_desligamento.visualizar continua abrindo o dashboard agregado');
    [$st] = $requisitar($vED, 'viewer', false, 'AdminDashboardEntrevistaDesligamentoController', 'index', false, []);
    $check($st === 403, '(agregado) as permissões de operação de entrevistas NÃO abrem o dashboard agregado (permissão própria)');

    // navegação: os cards/abas não aparecem para o viewer (e o backend já nega acima)
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $vTodos;
    $_SESSION['user_role'] = 'viewer';
    $_SESSION['user_is_supervisor'] = false;
    $abas = array_column((new PortalNavegacaoService())->abas('desligamento'), 'chave');
    $check(!in_array('entrevistas', $abas, true), '(navegação) a aba Entrevistas de Desligamento não aparece para o viewer');
    $cfg = array_column((new PortalNavegacaoService())->abas('configuracoes'), 'chave');
    $check(!in_array('mensagens', $cfg, true), '(navegação) a aba Mensagens não aparece para o viewer');

    // nada foi revogado/alterado
    $gravadasDepois = (int)$pdo->query('SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id IN (' . implode(',', [$vED, $vPR, $vMG, $vTodos, $vFlag]) . ')')->fetchColumn();
    $check($gravadasDepois === $gravadasAntes, '(legado) nenhuma linha de usuario_permissoes foi apagada');
    $depois = $contagens();
    $dif = [];
    foreach ($antes as $t => $n) {
        $esperado = $n + (($t === 'usuarios') ? 8 : (($t === 'usuario_permissoes') ? $gravadasAntes : 0));
        if (($depois[$t] ?? -1) !== $esperado) {
            $dif[] = "{$t}: {$n} -> " . ($depois[$t] ?? '?');
        }
    }
    $check($dif === [], '(dados) os módulos de entrevista, pesquisa e mensagens não tiveram nenhum registro criado, alterado ou removido pelas requisições de viewer' . ($dif === [] ? '' : ' — ' . implode(', ', $dif)));
} finally {
    if ($criados !== []) {
        $pdo->exec('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados)) . ')');
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — módulos pendentes fechados ao viewer.\n";
