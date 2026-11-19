<?php

/**
 * Integração — política de SESSÃO (ociosidade 8 h renovável + teto total 24 h), com RELÓGIO CONTROLADO (RH_TEST_NOW, só em CLI).
 * Duas camadas: (A) SessionPolicy::avaliar() puro (limites e renovação); (B) requisições reais pelo bootstrap (cookie de sessão + relógio simulado):
 * login com regeneração de ID, atividade que renova, expiração que NÃO renova, teto total, logout imediato com cookie reaproveitado, várias abas,
 * AJAX (401 JSON), POST após expiração (não processado + aviso), retorno seguro (`next`), CSRF, perfis (Admin, Supervisor Geral, RH, Gestor, Viewer)
 * sem ganho de privilégio na renovação, e (C) o armazenamento: o coletor de sessões do PHP respeita o prazo configurado.
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
$H = 3600;
$IDLE = SessionPolicy::ociosidade();
$TOTAL = SessionPolicy::limiteTotal();

// ---- A. política pura -------------------------------------------------------------------------------------------------------------------------------------------
$check($IDLE === 8 * $H && $TOTAL === 24 * $H && SessionPolicy::gcMaxLifetime() === $TOTAL + $H, '(A) política padrão: ociosidade 8 h, teto total 24 h, gc_maxlifetime derivado (teto + 1 h) — um único lugar define tudo');
$sessao = static function (int $ultima, ?int $inicio = 0): void {
    $_SESSION = ['user_id' => 7, 'user_role' => 'rh', 'last_activity' => $ultima];
    if ($inicio !== null) {
        $_SESSION['session_started_at'] = $inicio;
    }
};
$T = 1_000_000;
foreach ([['20 minutos', 20 * 60], ['1 hora', $H], ['7 h 59 min 59 s', 8 * $H - 1], ['exatamente 8 h', 8 * $H]] as [$rot, $d]) {
    $sessao($T, $T);
    $check(SessionPolicy::avaliar($T + $d) === 'ok', "(A) sessão ociosa por {$rot}: continua ativa");
}
$sessao($T, $T);
$check(SessionPolicy::avaliar($T + 8 * $H + 1) === 'inatividade', '(A) 8 h e 1 s sem atividade: expirada por inatividade');
$sessao($T, $T);
$antes = $_SESSION;
SessionPolicy::avaliar($T + 9 * $H);
$check($_SESSION === $antes, '(A) sessão expirada NÃO é renovada (last_activity e início intactos)');
$sessao($T, $T);
$r = [];
foreach ([7, 14, 21] as $h) {
    $r[] = SessionPolicy::avaliar($T + $h * $H);
}
$check($r === ['ok', 'ok', 'ok'] && $_SESSION['last_activity'] === $T + 21 * $H, '(A) atividade a cada 7 h renova a ociosidade (21 h de sessão contínua)');
$check(SessionPolicy::avaliar($T + 24 * $H + 1) === 'limite_total', '(A) mesmo com atividade contínua, passadas 24 h do login a sessão expira (nenhuma sessão é permanente)');
$_SESSION = ['last_activity' => $T];
$check(SessionPolicy::avaliar($T + 99 * $H) === 'ok' && $_SESSION['last_activity'] === $T + 99 * $H, '(A) visitante sem login: nunca "expira" (só grava a atividade)');
$_SESSION = ['user_id' => 7, 'last_activity' => $T];
SessionPolicy::avaliar($T + $H);
$check($_SESSION['session_started_at'] === $T + $H, '(A) sessão anterior à política (sem início registrado): o teto total passa a contar na 1ª requisição');
$_SESSION = [];

// ---- B. requisições reais --------------------------------------------------------------------------------------------------------------------------------------------
$suf = substr((string)time(), -5) . random_int(10, 99);
$pdo = Database::conn();
$senhaClara = 'SenhaForte#2026x';
$criados = [];
$novo = static function (string $apelido, string $role, int $flag = 0) use ($pdo, $suf, $senhaClara, &$criados): array {
    $email = strtolower($apelido) . '.zzs.' . $suf . '@teste.local';
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZS ' . $apelido, $email, password_hash($senhaClara, PASSWORD_BCRYPT), $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $criados[] = $id;
    return ['id' => $id, 'email' => $email, 'role' => $role];
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
/** Login real (GET para obter CSRF + POST) no instante $t. Devolve [sid, meta do POST]. */
$login = static function (array $u, int $t, string $get = '') use ($req, $senhaClara): array {
    [$m1] = $req('', $t, 'AuthController', 'login', [], [], $get !== '' ? ['next' => $get] : []);
    [$m2] = $req($m1['sid'], $t, 'AuthController', 'doLogin', ['csrf_token' => $m1['csrf'], 'email' => $u['email'], 'password' => $senhaClara]);
    return [(string)$m2['sid'], $m2, (string)$m1['sid']];
};
$T0 = time();
try {
    $rh = $novo('rh', 'rh');
    [$sid, $m, $sidAnonimo] = $login($rh, $T0);
    $check(($m['user_id'] ?? null) === $rh['id'] && $sid !== '' && $sid !== $sidAnonimo, '(B) login: o ID da sessão é REGENERADO (anti-fixação) e a sessão fica autenticada');
    $check((int)$m['iniciada_em'] === $T0 && (int)$m['ultima'] === $T0, '(B) o login registra início e última atividade');

    // atividade ao longo do tempo
    [$m] = $req($sid, $T0 + 20 * 60, 'AdminCentralController', 'index');
    $check($m['status'] === 200 && $m['user_id'] === $rh['id'] && $m['controller_rodou'] === true, '(B) 20 minutos depois (antes: expirava): sessão ativa');
    [$m] = $req($sid, $T0 + 20 * 60 + $H, 'AdminCentralController', 'index');
    $check($m['user_id'] === $rh['id'] && $m['controller_rodou'] === true, '(B) mais 1 hora depois: ativa');
    $t = $T0 + 20 * 60 + $H;
    [$m] = $req($sid, $t + 8 * $H - 5, 'AdminCentralController', 'index');
    $check($m['user_id'] === $rh['id'] && $m['controller_rodou'] === true, '(B) 8 h menos 5 s sem atividade (desde a última): ainda ativa');
    $t = $t + 8 * $H - 5;
    $check((int)$m['ultima'] === $t, '(B) a atividade autenticada RENOVOU a referência de última atividade');
    [$m] = $req($sid, $t + 8 * $H - 5, 'AdminCentralController', 'index');
    $check($m['user_id'] === $rh['id'], '(B) renovada: mais 8 h menos 5 s depois (≈ 16 h de sessão contínua): ativa');
    $t = $t + 8 * $H - 5;

    // expiração por inatividade completa — e a sessão expirada não revive
    [$sid2] = $login($rh, $T0);
    [$m, $corpo] = $req($sid2, $T0 + 8 * $H + 1, 'AdminCentralController', 'index', [], [], []);
    $check($m['controller_rodou'] === false && str_contains((string)$m['location'], 'expired=1') && str_contains((string)$m['location'], 'next='), '(B) 8 h e 1 s sem atividade: o controller NÃO roda e o usuário vai ao login (expired=1) com retorno à página');
    [$m] = $req($sid2, $T0 + 8 * $H + 2, 'AdminCentralController', 'index');
    $check($m['user_id'] === null && str_contains((string)$m['location'], '/login'), '(B) a sessão expirada NÃO é renovada nem reaproveitável: o mesmo cookie, um segundo depois, segue sem autenticação');

    // teto total
    [$sid3] = $login($rh, $T0);
    $ok = true;
    foreach ([7, 14, 21] as $h) {
        [$m] = $req($sid3, $T0 + $h * $H, 'AdminCentralController', 'index');
        $ok = $ok && $m['user_id'] === $rh['id'];
    }
    [$m] = $req($sid3, $T0 + 24 * $H + 1, 'AdminCentralController', 'index');
    $check($ok && $m['controller_rodou'] === false && str_contains((string)$m['location'], 'motivo=limite'), '(B) atividade a cada 7 h mantém a sessão até 21 h, mas após 24 h do login ela é encerrada (teto total) — mesmo estando ativa');

    // logout imediato + reutilização de cookie
    [$sid4] = $login($rh, $T0);
    [$m] = $req($sid4, $T0 + 60, 'AuthController', 'logout');
    $check(str_contains((string)$m['location'], '/login'), '(B) logout redireciona ao login');
    [$m] = $req($sid4, $T0 + 61, 'AdminCentralController', 'index');
    $check($m['user_id'] === null && str_contains((string)$m['location'], '/login') && $m['sid'] !== $sid4, '(B) cookie reutilizado depois do logout: sem autenticação, e o servidor não aceita o ID antigo (modo estrito)');

    // várias abas = mesma sessão (mesmo cookie), cada aba renova
    [$sid5] = $login($rh, $T0);
    [$a] = $req($sid5, $T0 + 7 * $H, 'AdminCentralController', 'index');            // aba A
    [$b] = $req($sid5, $T0 + 14 * $H, 'AdminCentralController', 'index');           // aba B, 7 h depois da A
    [$a2] = $req($sid5, $T0 + 14 * $H + 60, 'AdminCentralController', 'index');     // aba A de novo
    $check($a['user_id'] === $rh['id'] && $b['user_id'] === $rh['id'] && $a2['user_id'] === $rh['id'] && (int)$a2['ultima'] === $T0 + 14 * $H + 60, '(B) várias abas compartilham a sessão: a atividade em qualquer uma renova para todas');

    // AJAX/API: 401 JSON, nunca HTML de login
    [$sid6] = $login($rh, $T0);
    [$m, $corpo] = $req($sid6, $T0 + 9 * $H, 'AdminCentralController', 'index', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $j = json_decode(trim($corpo), true);
    $check($m['status'] === 401 && ($j['erro'] ?? '') === 'sessao_expirada' && !empty($j['error']) && $m['controller_rodou'] === false, '(B) chamada AJAX com sessão expirada: 401 JSON (a tela mostra o erro, a ação não é concluída)');

    // POST após expiração: não é processado e o usuário é avisado (sem perda silenciosa)
    [$sid7] = $login($rh, $T0);
    [$m] = $req($sid7, $T0 + 9 * $H, 'AdminFeedbacksController', 'concluir', ['csrf' => 'x', 'resultado_geral' => 'x']);
    $check($m['controller_rodou'] === false && str_contains((string)$m['location'], 'perdido=1') && !str_contains((string)$m['location'], 'next='), '(B) formulário enviado depois de expirar: NÃO é processado e o login recebe perdido=1 (aviso explícito; sem retorno automático por GET)');
    [, $htmlLogin] = $req('', $T0, 'AuthController', 'login', [], [], ['expired' => '1', 'perdido' => '1']);
    $check(str_contains($htmlLogin, 'NÃO foi salvo') && str_contains($htmlLogin, '8 hora') && !str_contains($htmlLogin, '20 minutos'), '(B) a tela de login avisa que o envio NÃO foi salvo e informa o prazo real (8 horas), nunca "20 minutos"');
    [, $htmlLimite] = $req('', $T0, 'AuthController', 'login', [], [], ['expired' => '1', 'motivo' => 'limite']);
    $check(str_contains($htmlLimite, 'tempo máximo de 24'), '(B) expiração pelo teto total tem mensagem própria');

    // retorno seguro após o login
    [, $mm] = $login($rh, $T0, '/admin/pdis/12?x=1');
    $check(str_contains((string)$mm['location'], '/admin/pdis/12?x=1'), '(B) depois de entrar, volta à página em que o usuário estava');
    [, $mm] = $login($rh, $T0, '//evil.example/admin');
    [, $mm2] = $login($rh, $T0, 'https://evil.example/admin');
    [, $mm3] = $login($rh, $T0, '/admin/login');
    $check(!str_contains((string)$mm['location'], 'evil') && !str_contains((string)$mm2['location'], 'evil') && !str_contains((string)$mm3['location'], '/login'), '(B) retorno aberto/externo/login é ignorado (sem redirecionamento aberto)');
    $check(Security::caminhoDeRetornoSeguro('/admin/x') === '/admin/x' && Security::caminhoDeRetornoSeguro('/outra') === null && Security::caminhoDeRetornoSeguro("/admin/a\r\nSet-Cookie: x") === null && Security::caminhoDeRetornoSeguro('/\\evil/admin') === null, '(B) validação do caminho de retorno (mesmo site, dentro de /admin, sem controle/barra invertida)');

    // CSRF continua valendo e sobrevive à renovação
    [$sid8, $mLogin8] = $login($rh, $T0);
    $p1 = ['csrf' => $mLogin8['csrf']]; // token emitido NO LOGIN (T0), usado 7 h depois
    [$bad] = $req($sid8, $T0 + 7 * $H + 5, 'AdminAvaliacoesDesempenhoController', 'cancelar', ['csrf' => 'forjado', 'motivo' => 'x'], [], [], ['2147480000']);
    [$good] = $req($sid8, $T0 + 7 * $H + 6, 'AdminAvaliacoesDesempenhoController', 'cancelar', ['csrf' => (string)$p1['csrf'], 'motivo' => 'x'], [], [], ['2147480000']);
    $check($bad['status'] === 400 && $good['controller_rodou'] === true && $good['status'] !== 400 && $good['user_id'] === $rh['id'], '(B) CSRF: inválido = 400; o token emitido horas antes da renovação continua válido (a sessão é a mesma)');

    // perfis: renovação não concede privilégio
    $perfis = ['admin' => $novo('adm', 'admin'), 'sg' => $novo('sg', 'admin', 1), 'rh' => $rh, 'gestor' => $novo('gestor', 'gestor'), 'viewer' => $novo('viewer', 'viewer')];
    foreach ($perfis as $nome => $u) {
        [$s] = $login($u, $T0);
        [$m1] = $req($s, $T0 + 3 * $H, 'AdminCentralController', 'index');
        [$m2] = $req($s, $T0 + 3 * $H + 7 * $H, 'AdminCentralController', 'index');
        $sgEsperado = $nome === 'sg';
        $check($m1['role'] === $u['role'] && $m2['role'] === $u['role'] && $m2['supervisor'] === $sgEsperado && $m1['supervisor'] === $sgEsperado && $m2['user_id'] === $u['id'], "(B) {$nome}: sessão de 10 h ativa, perfil e flag de Supervisor Geral IDÊNTICOS após renovações");
        [$s2] = $login($u, $T0);
        [$me] = $req($s2, $T0 + 8 * $H + 5, 'AdminCentralController', 'index');
        $check($me['controller_rodou'] === false && str_contains((string)$me['location'], 'expired=1'), "(B) {$nome}: expira após 8 h de inatividade");
    }
    [$sg] = $login($perfis['gestor'], $T0);
    [$gx] = $req($sg, $T0 + 7 * $H, 'AdminUsuariosController', 'index');
    $check($gx['status'] === 403, '(B) Gestor com sessão renovada continua SEM acesso à administração de usuários (403)');
    [$sv] = $login($perfis['viewer'], $T0);
    [$vx] = $req($sv, $T0 + 7 * $H, 'AdminUsuariosController', 'index');
    $check($vx['status'] === 403, '(B) Viewer com sessão renovada continua SEM acesso à administração de usuários (403)');
    // diagnóstico somente leitura (Supervisor Geral): prova, no servidor web, que o prazo é cumprido
    $diag = static function (array $u, bool $sup): array {
        $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$u['id'], $u['role'], $sup ? '1' : '0', 'AdminSupervisorController', 'sessao', '[]', '[]', ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        $st = preg_match('/@@STATUS=(\d+)@@\s*$/', $out, $m) ? (int)$m[1] : 0;
        return [$st, json_decode(preg_replace('/@@STATUS=\d+@@\s*$/', '', $out), true)];
    };
    [$stSg, $jSg] = $diag($perfis['sg'], true);
    [$stAd] = $diag($perfis['admin'], false);
    [$stRh] = $diag($perfis['rh'], false);
    $check($stSg === 200 && ($jSg['prazo_garantido'] ?? false) === true && ($jSg['politica']['ociosidade_s'] ?? 0) === 28800 && !isset($jSg['sid']) && $stAd === 403 && $stRh === 403, '(B) diagnóstico de sessão (somente leitura): Supervisor Geral vê política e diretivas efetivas e a confirmação do prazo; Admin comum e RH recebem 403; nenhum ID de sessão exposto');
} finally {
    if ($criados !== []) {
        $lu = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE actor_usuario_id IN ({$lu}) OR target_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
}

// ---- C. armazenamento: o coletor de sessões do PHP respeita o prazo configurado ----------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_sess_' . bin2hex(random_bytes(4));
mkdir($tmp);
$script = $tmp . DIRECTORY_SEPARATOR . 'gc.php';
file_put_contents($script, "<?php\nrequire " . var_export(realpath(__DIR__ . '/../../app/core/SessionPolicy.php'), true) . ";\nrequire " . var_export(realpath(__DIR__ . '/../../app/core/Config.php'), true) . ";\n"
    . "\$st = SessionPolicy::aplicarIni();\nsession_save_path(" . var_export($tmp, true) . ");\nini_set('session.gc_probability', '0');\nsession_start();\n\$n = session_gc();\nsession_write_close();\necho json_encode(['gc' => (int)ini_get('session.gc_maxlifetime'), 'aceito' => \$st['aceito']]);\n");
$gc = static function (int $idadeS) use ($tmp, $script): array {
    $f = $tmp . DIRECTORY_SEPARATOR . 'sess_zzgc';
    file_put_contents($f, 'x|i:1;');
    touch($f, time() - $idadeS);
    $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    $existe = is_file($f);
    @unlink($f);
    return [$existe, json_decode($out, true) ?: []];
};
try {
    [$existe7h, $info] = $gc(7 * $H);
    [$existe25h] = $gc($TOTAL + 2 * $H);
    [$existe23h] = $gc($TOTAL - $H);
    $check(($info['gc'] ?? 0) === SessionPolicy::gcMaxLifetime() && ($info['aceito'] ?? false) === true, '(C) o PHP aceita e aplica o gc_maxlifetime derivado da política (' . ($info['gc'] ?? '?') . ' s) — neste ambiente');
    $check($existe7h === true && $existe23h === true, '(C) o coletor do PHP NÃO apaga a sessão ociosa há 7 h nem uma de ~23 h (dentro do prazo + margem)');
    $check($existe25h === false, '(C) o coletor apaga sessão parada além do teto (mantém o disco limpo)');
} finally {
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}
$boot = (string)file_get_contents(__DIR__ . '/../../app/core/bootstrap.php');
$check(!str_contains($boot, 'enforceInactivityTimeout(1200)') && str_contains($boot, 'SessionPolicy.php'), '(C) o limite de 20 minutos deixou de existir no bootstrap (a política vem de SessionPolicy)');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — política de sessão.\n";
