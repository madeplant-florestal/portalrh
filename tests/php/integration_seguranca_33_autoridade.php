<?php

/**
 * Integração — Segurança 3.3: revogação de sessões legadas, desativação imediata e autoridade efetiva relida do banco a cada requisição.
 *
 * Tudo por requisições REAIS pelo bootstrap (fixture_sessao_http.php: cookie de sessão + relógio controlado), com contas fictícias ZZ33.
 *   A. sessão sem `credencial_impressao`/`conta_ativa_desde` (legada) = encerrada (motivo=seguranca), sem adoção; impressão válida = ok;
 *      impressão incorreta = encerrada; cookie antigo não reautentica.
 *   B. desativação durante a sessão: GET, POST e AJAX bloqueados ANTES do controller (motivo=desativada / 401 JSON); login recusado enquanto
 *      inativa; reativação exige novo login (sessão anterior encerrada, motivo=situacao); usuário excluído = encerrada.
 *   C. mudança de perfil vale na PRÓXIMA requisição, sem novo login: Admin→Viewer, Admin→RH, RH→Gestor, Gestor→Viewer, Viewer→Gestor
 *      (sem dispensar permissão individual e escopo setorial); duas sessões abertas; navegação reflete o perfil atual.
 *   D. Supervisor Geral = role admin + is_supervisor = 1, relido a cada requisição: revogação do flag e flag com role não-admin não concedem nada.
 *   E. regressões: dashboard autorizado do Viewer, login normal, inatividade.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$pdo = Database::conn();
$suf = substr((string)time(), -5) . random_int(10, 99);
$senha = 'Seguranca33#2026x';
$criados = ['usuarios' => [], 'setores' => [], 'vagas' => []];
$novo = static function (string $apelido, string $role, int $flag = 0) use ($pdo, $suf, $senha, &$criados): array {
    $email = strtolower($apelido) . '.zz33.' . $suf . '@teste.local';
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZ33 ' . $apelido, $email, password_hash($senha, PASSWORD_BCRYPT), $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $criados['usuarios'][] = $id;
    return ['id' => $id, 'email' => $email];
};
$req = static function (string $sid, int $agora, string $ctrl, string $metodo, array $post = [], array $cab = [], array $get = [], array $args = []): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_sessao_http.php', $sid === '' ? '-' : $sid, (string)$agora, $ctrl, $metodo, $post === [] ? '' : json_encode($post), $cab === [] ? '' : json_encode($cab), $get === [] ? '' : json_encode($get), $args === [] ? '' : json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    $meta = [];
    if (preg_match('/@@META=(.*)@@\s*$/s', $out, $m)) {
        $meta = json_decode($m[1], true) ?: [];
        $out = substr($out, 0, -strlen($m[0]));
    }
    return [$meta, $out];
};
$login = static function (array $u, int $t) use ($req, $senha): array {
    [$m1] = $req('', $t, 'AuthController', 'login');
    [$m2] = $req($m1['sid'], $t, 'AuthController', 'doLogin', ['csrf_token' => $m1['csrf'], 'email' => $u['email'], 'password' => $senha]);
    return [(string)$m2['sid'], $m2];
};
/** Edita, no armazenamento real de sessões, a sessão de um cookie (simula sessão aberta antes do deploy). */
$editarSessao = static function (string $sid, array $ops): bool {
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_editar_sessao.php', $sid, json_encode($ops)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($p) === 0 && trim($out) === 'ok';
};
$set = static fn(int $id, string $sql, array $p = []) => $pdo->prepare($sql)->execute(array_merge($p, [$id]));
$encerrada = static fn(array $m, string $motivo): bool => ($m['controller_rodou'] ?? true) === false && ($m['user_id'] ?? null) === null
    && str_contains((string)($m['location'] ?? ''), 'expired=1') && str_contains((string)($m['location'] ?? ''), 'motivo=' . $motivo);
$semAuth = static fn(array $m): bool => ($m['user_id'] ?? null) === null && str_contains((string)($m['location'] ?? ''), '/login');
$roda = static fn(array $m, int $uid): bool => ($m['user_id'] ?? null) === $uid && ($m['controller_rodou'] ?? false) === true && ($m['status'] ?? 0) === 200;
$negado = static fn(array $m): bool => ($m['status'] ?? 0) === 403;
$perm = static function (int $uid, array $codigos) use ($pdo): void {
    foreach ($codigos as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) SELECT ?, id FROM permissoes WHERE codigo = ?')->execute([$uid, $c]);
    }
};

$T = time();
try {
    // ---- A. sessões legadas ------------------------------------------------------------------------------------------------------------
    $a = $novo('legado', 'rh');
    [$s] = $login($a, $T);
    [$m] = $req($s, $T + 10, 'AdminCentralController', 'index');
    $check($roda($m, $a['id']), '(A) sessão com impressão válida (login atual): acesso normal');
    $check($editarSessao($s, ['remover' => ['credencial_impressao']]), '(A) setup: sessão real editada (sem impressão)');
    [$m] = $req($s, $T + 20, 'AdminCentralController', 'index');
    $check($encerrada($m, 'seguranca'), '(A) sessão sem credencial_impressao (legada): encerrada antes do controller, motivo=seguranca — sem adoção');
    [$m] = $req($s, $T + 21, 'AdminCentralController', 'index');
    $check($semAuth($m), '(A) o cookie antigo, reutilizado, não recupera a autenticação');
    [$s] = $login($a, $T + 30);
    $check($editarSessao($s, ['remover' => ['conta_ativa_desde']]), '(A) setup: sessão real editada (sem conta_ativa_desde)');
    [$m] = $req($s, $T + 40, 'AdminCentralController', 'index');
    $check($encerrada($m, 'seguranca'), '(A) sessão do commit anterior (com impressão, sem conta_ativa_desde): também legada e encerrada');
    [$s] = $login($a, $T + 50);
    $check($editarSessao($s, ['definir' => ['credencial_impressao' => str_repeat('0', 64)]]), '(A) setup: sessão real editada (impressão incorreta)');
    [$m] = $req($s, $T + 60, 'AdminCentralController', 'index');
    $check($encerrada($m, 'senha'), '(A) impressão incorreta: encerrada');
    [$m] = $req('', $T + 61, 'AuthController', 'login');
    $check(($m['status'] ?? 0) === 200 && ($m['user_id'] ?? null) === null, '(A) visitante anônimo / fluxo público intactos');

    // ---- B. desativação -----------------------------------------------------------------------------------------------------------------
    $b = $novo('desativ', 'rh');
    [$sGet] = $login($b, $T);
    [$sPost] = $login($b, $T);
    [$sAjax] = $login($b, $T);
    [$m] = $req($sGet, $T + 10, 'AdminCandidaturasController', 'index');
    $check($roda($m, $b['id']), '(B) usuário ativo autenticado: acesso normal');
    User::setActiveStatus($b['id'], false);
    [$m] = $req($sGet, $T + 20, 'AdminCandidaturasController', 'index');
    $check($encerrada($m, 'desativada'), '(B) desativação durante a sessão: GET bloqueado antes do controller (motivo=desativada)');
    [$m] = $req($sGet, $T + 21, 'AdminController', 'index');
    $check($semAuth($m), '(B) dashboard com o mesmo cookie depois: sem autenticação');
    [$m] = $req($sPost, $T + 22, 'AdminFeedbacksController', 'concluir', ['csrf' => 'x', 'resultado_geral' => 'x']);
    $check($encerrada($m, 'desativada') && str_contains((string)$m['location'], 'perdido=1'), '(B) POST após desativação: NÃO processado, login avisa que o envio foi perdido');
    [$m, $corpo] = $req($sAjax, $T + 23, 'AdminCentralController', 'index', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $j = json_decode(trim($corpo), true);
    $check(($m['status'] ?? 0) === 401 && ($j['motivo'] ?? '') === 'conta_desativada' && ($j['erro'] ?? '') === 'sessao_expirada' && str_contains((string)($j['error'] ?? ''), 'desativada') && ($m['controller_rodou'] ?? true) === false, '(B) AJAX após desativação: 401 JSON, ação não executada');
    [, $mL] = $login($b, $T + 30);
    $check(($mL['user_id'] ?? null) === null, '(B) login recusado enquanto a conta está desativada (estado oficial email_verified_at, inalterado)');
    [, $html] = $req('', $T + 31, 'AuthController', 'login', [], [], ['expired' => '1', 'motivo' => 'desativada']);
    $check(str_contains($html, 'esta conta foi desativada'), '(B) a tela de login orienta o usuário desativado');
    // reativação entre requisições: a sessão aberta ANTES da desativação não volta a valer
    $b2 = $novo('reativ', 'rh');
    [$sAntiga] = $login($b2, $T);
    User::setActiveStatus($b2['id'], false);
    sleep(1); // a reativação grava um NOVO instante em email_verified_at
    User::setActiveStatus($b2['id'], true);
    [$m] = $req($sAntiga, $T + 40, 'AdminCentralController', 'index');
    $check($encerrada($m, 'situacao'), '(B) reativação: a sessão anterior à desativação é encerrada (motivo=situacao), mesmo sem requisição durante a desativação');
    [$sNova] = $login($b2, $T + 50);
    [$m] = $req($sNova, $T + 60, 'AdminCentralController', 'index');
    $check($roda($m, $b2['id']), '(B) depois da reativação, novo login funciona');
    $bx = $novo('excluido', 'viewer');
    [$sx] = $login($bx, $T);
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$bx['id']]);
    [$m] = $req($sx, $T + 10, 'AdminCentralController', 'index');
    $check($encerrada($m, 'seguranca'), '(B) usuário excluído durante a sessão: encerrada (falha fechada)');

    // ---- C. mudança de perfil (sem novo login) --------------------------------------------------------------------------------------------
    $c1 = $novo('adm2viewer', 'admin');
    [$s1] = $login($c1, $T);
    [$s1b] = $login($c1, $T); // segunda sessão aberta
    [$m, $htmlAdm] = $req($s1, $T + 10, 'AdminCentralController', 'index');
    [$mU] = $req($s1, $T + 11, 'AdminUsuariosController', 'index');
    $check($roda($mU, $c1['id']) && str_contains($htmlAdm, '/admin/usuarios'), '(C) controle: admin acessa Usuários e vê o atalho na navegação');
    $set($c1['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['viewer']);
    [$mU] = $req($s1, $T + 20, 'AdminUsuariosController', 'index');
    [$mU2] = $req($s1b, $T + 20, 'AdminUsuariosController', 'index');
    $check($negado($mU) && ($mU['role'] ?? '') === 'viewer' && ($mU['user_id'] ?? null) === $c1['id'], '(C) Admin→Viewer: na próxima requisição 403 em Usuários, sessão continua com o perfil atual (viewer), sem novo login');
    $check($negado($mU2) && ($mU2['role'] ?? '') === 'viewer', '(C) duas sessões abertas: a segunda também passa a ser viewer');
    [$m, $htmlV] = $req($s1, $T + 21, 'AdminCentralController', 'index');
    $check(!str_contains($htmlV, 'href="/admin/usuarios"'), '(C) navegação reflete o perfil atual (sem o atalho de Usuários)');
    $mV = $req($s1, $T + 22, 'AdminCandidaturasController', 'index')[0];
    $check($negado($mV), '(C) Admin→Viewer: perde também Candidaturas (403)');

    $c2 = $novo('adm2rh', 'admin');
    [$s2] = $login($c2, $T);
    $set($c2['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['rh']);
    [$mU] = $req($s2, $T + 10, 'AdminUsuariosController', 'index');
    [$mC] = $req($s2, $T + 11, 'AdminCandidaturasController', 'index');
    $check($negado($mU) && $roda($mC, $c2['id']) && ($mC['role'] ?? '') === 'rh', '(C) Admin→RH: perde a administração de usuários (403) e mantém o acesso global de RH');

    $c3 = $novo('rh2gestor', 'rh');
    [$s3] = $login($c3, $T);
    [$mC] = $req($s3, $T + 5, 'AdminCandidaturasController', 'index');
    $set($c3['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['gestor']);
    [$mC2] = $req($s3, $T + 10, 'AdminCandidaturasController', 'index');
    $check($roda($mC, $c3['id']) && $negado($mC2) && ($mC2['role'] ?? '') === 'gestor', '(C) RH→Gestor: sem a permissão individual, Candidaturas passa a 403 na próxima requisição');

    // Gestor com permissão + setor; depois Viewer
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, 'zz-teste')")->execute(['Z' . substr($suf, -6), 'ZZ33 Setor ' . $suf, 'zz33-' . $suf]);
    $setor = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setor;
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'ZZ', 'l', 1, ?)")->execute(['ZZ33 Vaga ' . $suf, $setor]);
    $criados['vagas'][] = (int)$pdo->lastInsertId();
    $c4 = $novo('gestor2viewer', 'gestor');
    $perm($c4['id'], ['vagas.visualizar']);
    $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$c4['id'], $setor]);
    [$s4] = $login($c4, $T);
    [$mG, $htmlG] = $req($s4, $T + 5, 'AdminVagasController', 'index');
    $set($c4['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['viewer']);
    [$mG2, $htmlG2] = $req($s4, $T + 10, 'AdminVagasController', 'index');
    $check($roda($mG, $c4['id']) && str_contains($htmlG, 'ZZ33 Vaga ' . $suf) && $negado($mG2) && !str_contains($htmlG2, 'ZZ33 Vaga'), '(C) Gestor→Viewer: perde a leitura setorial de Vagas na próxima requisição (403, sem dados)');

    $c5 = $novo('viewer2gestor', 'viewer');
    [$s5] = $login($c5, $T);
    [$mV1] = $req($s5, $T + 5, 'AdminVagasController', 'index');
    $set($c5['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['gestor']);
    [$mV2] = $req($s5, $T + 10, 'AdminVagasController', 'index');
    $perm($c5['id'], ['vagas.visualizar']);
    $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$c5['id'], $setor]);
    [$mV3, $htmlV3] = $req($s5, $T + 15, 'AdminVagasController', 'index');
    $check($negado($mV1) && $negado($mV2) && ($mV2['role'] ?? '') === 'gestor', '(C) Viewer→Gestor: a role nova vale, mas SEM a permissão individual continua 403');
    $check($roda($mV3, $c5['id']) && str_contains($htmlV3, 'ZZ33 Vaga ' . $suf), '(C) Viewer→Gestor com permissão e setor concedidos: lista as vagas do setor, sem novo login');

    $c6 = $novo('perfilinv', 'rh');
    [$s6] = $login($c6, $T);
    $pdo->exec('SET @modo_anterior = @@SESSION.sql_mode');
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->prepare("UPDATE usuarios SET role = 'invalido' WHERE id = ?")->execute([$c6['id']]); // modo não estrito grava '' no ENUM
    $pdo->exec('SET SESSION sql_mode = @modo_anterior');
    $roleGravada = (string)$pdo->query('SELECT role FROM usuarios WHERE id = ' . (int)$c6['id'])->fetchColumn();
    [$m6] = $req($s6, $T + 10, 'AdminCandidaturasController', 'index');
    $check($roleGravada === '' && ($m6['role'] ?? '') === 'viewer' && $negado($m6), '(C) role inválida gravada fora do modo estrito (vazia): tratada como viewer (regra legada do login), nunca concede o perfil antigo');

    // ---- D. Supervisor Geral --------------------------------------------------------------------------------------------------------------
    $d1 = $novo('sg', 'admin', 1);
    [$sd] = $login($d1, $T);
    [$mS] = $req($sd, $T + 5, 'AdminSupervisorController', 'sessao');
    $check($roda($mS, $d1['id']) && ($mS['supervisor'] ?? false) === true, '(D) Supervisor Geral válido (admin + flag): acesso ao diagnóstico restrito');
    $set($d1['id'], 'UPDATE usuarios SET is_supervisor = 0 WHERE id = ?');
    [$mS2] = $req($sd, $T + 10, 'AdminSupervisorController', 'sessao');
    $check(($mS2['supervisor'] ?? true) === false && $negado($mS2) && ($mS2['role'] ?? '') === 'admin', '(D) revogação de is_supervisor: na próxima requisição deixa de ser Supervisor (403), continua admin');
    $d2 = $novo('flagrh', 'rh', 1);
    [$sd2] = $login($d2, $T);
    [$mF] = $req($sd2, $T + 5, 'AdminUsuariosController', 'index');
    $check(($mF['supervisor'] ?? true) === false && $negado($mF), '(D) flag de supervisor com role RH: não concede nada (403 em Usuários)');
    $d3 = $novo('sg2rh', 'admin', 1);
    [$sd3] = $login($d3, $T);
    $set($d3['id'], 'UPDATE usuarios SET role = ? WHERE id = ?', ['rh']);
    [$mF3] = $req($sd3, $T + 5, 'AdminUsuariosController', 'index');
    $check(($mF3['supervisor'] ?? true) === false && $negado($mF3) && ($mF3['role'] ?? '') === 'rh', '(D) Supervisor rebaixado a RH (flag mantido): perde o bypass de Supervisor na próxima requisição');

    // ---- E. regressões --------------------------------------------------------------------------------------------------------------------
    $e1 = $novo('viewerdash', 'viewer');
    $perm($e1['id'], ['dashboard.visualizar']);
    [$se] = $login($e1, $T);
    [$mE] = $req($se, $T + 5, 'AdminController', 'index');
    $check($roda($mE, $e1['id']), '(E) Viewer com dashboard autorizado continua acessando o People Analytics');
    [$mI] = $req($se, $T + 5 + SessionPolicy::ociosidade() + 1, 'AdminController', 'index');
    $check(($mI['controller_rodou'] ?? true) === false && str_contains((string)$mI['location'], 'expired=1') && !str_contains((string)$mI['location'], 'motivo='), '(E) inatividade continua com a mensagem própria');

    echo "\nSEGURANCA_33_AUTORIDADE_OK\n";
} finally {
    $_SESSION = [];
    foreach ($criados['vagas'] as $v) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$v]);
    }
    if ($criados['usuarios'] !== []) {
        $ph = implode(',', array_fill(0, count($criados['usuarios']), '?'));
        foreach (['usuario_setores_gerenciados', 'usuario_permissoes'] as $tab) {
            $pdo->prepare("DELETE FROM {$tab} WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        }
        $pdo->prepare("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($ph) OR actor_usuario_id IN ($ph)")->execute(array_merge($criados['usuarios'], $criados['usuarios']));
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($criados['usuarios']);
    }
    foreach ($criados['setores'] as $st) {
        $pdo->prepare("DELETE FROM setores WHERE id = ? AND origem_metadados = 'zz-teste'")->execute([$st]);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
