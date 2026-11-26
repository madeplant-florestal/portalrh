<?php

/**
 * Integração — revogação de sessões por TROCA DE SENHA (SessionPolicy::avaliarCredencial + Auth::establishSession).
 *
 * (A) regra pura, em processo: sem login = ok; sessão sem impressão (aberta antes da regra) adota a credencial atual; impressão diferente,
 *     usuário inexistente = encerrada; alteração de outros dados do usuário não encerra.
 * (B) requisições reais pelo bootstrap (fixture_sessao_http.php: cookie de sessão + relógio controlado):
 *     - recuperação de senha real (PasswordRecoveryController::performReset) encerra TODAS as sessões abertas antes (2 navegadores), na
 *       próxima requisição, sem rodar o controller; o cookie antigo não volta a autenticar; AJAX recebe 401 JSON;
 *     - senha antiga não entra; senha nova entra e a nova sessão segue válida;
 *     - redefinição administrativa (User::adminChangePassword) e senha temporária (User::definirSenhaHash) também revogam; a sessão do
 *       administrador que fez a troca NÃO é afetada;
 *     - expiração normal por inatividade continua igual (sem motivo=senha); a tela de login explica o motivo da revogação.
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
$senhaInicial = 'SenhaInicial#2026x';
$criados = [];
$novo = static function (string $apelido, string $role) use ($pdo, $suf, $senhaInicial, &$criados): array {
    $email = strtolower($apelido) . '.zzrev.' . $suf . '@teste.local';
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, 0, NOW())')
        ->execute(['ZZREV ' . $apelido, $email, password_hash($senhaInicial, PASSWORD_BCRYPT), $role]);
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
/** Login real (GET para o CSRF + POST). Devolve [sid, meta do POST]. */
$login = static function (array $u, string $senha, int $t) use ($req): array {
    [$m1] = $req('', $t, 'AuthController', 'login');
    [$m2] = $req($m1['sid'], $t, 'AuthController', 'doLogin', ['csrf_token' => $m1['csrf'], 'email' => $u['email'], 'password' => $senha]);
    return [(string)$m2['sid'], $m2];
};
$autenticada = static fn(array $m, int $uid): bool => ($m['user_id'] ?? null) === $uid && ($m['controller_rodou'] ?? false) === true;
$revogada = static fn(array $m): bool => ($m['controller_rodou'] ?? true) === false && ($m['user_id'] ?? null) === null
    && str_contains((string)($m['location'] ?? ''), 'expired=1') && str_contains((string)($m['location'] ?? ''), 'motivo=senha');

try {
    // ---- A. regra pura ----------------------------------------------------------------------------------------------------------------------
    $alvoA = $novo('regra', 'rh');
    $hashA = (string)$pdo->query('SELECT senha_hash FROM usuarios WHERE id = ' . (int)$alvoA['id'])->fetchColumn();
    $_SESSION = [];
    $check(SessionPolicy::avaliarCredencial() === 'ok', '(A) visitante sem login: nada a verificar');
    $_SESSION = ['user_id' => $alvoA['id']];
    $check(SessionPolicy::avaliarCredencial() === 'ok' && ($_SESSION['credencial_impressao'] ?? '') === SessionPolicy::impressaoCredencial($hashA), '(A) sessão aberta antes da regra (sem impressão): adota a credencial atual e segue válida');
    $check(!str_contains((string)$_SESSION['credencial_impressao'], $hashA) && strlen((string)$_SESSION['credencial_impressao']) === 64, '(A) a sessão guarda só a impressão (SHA-256), nunca o hash da senha');
    $pdo->prepare('UPDATE usuarios SET nome = ? WHERE id = ?')->execute(['ZZREV regra renomeado', $alvoA['id']]);
    $check(SessionPolicy::avaliarCredencial() === 'ok', '(A) alterar outros dados do usuário (nome) NÃO encerra a sessão');
    User::updatePassword($alvoA['id'], password_hash('OutraSenha#2026x', PASSWORD_BCRYPT));
    $check(SessionPolicy::avaliarCredencial() === 'credencial_alterada', '(A) senha trocada depois da impressão: sessão encerrada');
    $_SESSION = ['user_id' => 2147480000, 'credencial_impressao' => str_repeat('a', 64)];
    $check(SessionPolicy::avaliarCredencial() === 'credencial_alterada', '(A) usuário inexistente (excluído): sessão encerrada (falha fechada)');
    $_SESSION = [];

    // ---- B. requisições reais ------------------------------------------------------------------------------------------------------------------
    $T0 = time();
    $alvo = $novo('alvo', 'rh');
    $admin = $novo('admin', 'admin');

    [$s1, $m] = $login($alvo, $senhaInicial, $T0);
    [$s2] = $login($alvo, $senhaInicial, $T0);           // segundo navegador
    [$sAdm] = $login($admin, $senhaInicial, $T0);
    [$m1] = $req($s1, $T0 + 60, 'AdminCentralController', 'index');
    [$m2] = $req($s2, $T0 + 60, 'AdminCentralController', 'index');
    $check($s1 !== $s2 && $autenticada($m1, $alvo['id']) && $autenticada($m2, $alvo['id']), '(B) controle: duas sessões do mesmo usuário ativas antes da troca');

    // recuperação de senha REAL (token + formulário com CSRF), feita de outro navegador (anônimo)
    $token = bin2hex(random_bytes(32));
    PasswordReset::create($alvo['id'], $token, 30);
    [$mf] = $req('', $T0 + 120, 'PasswordRecoveryController', 'resetForm', [], [], [], [$token]);
    $senhaNova = 'SenhaNova#2026xY';
    [$mr, $htmlReset] = $req((string)$mf['sid'], $T0 + 120, 'PasswordRecoveryController', 'performReset', ['csrf' => (string)$mf['csrf'], 'password' => $senhaNova, 'password_confirm' => $senhaNova], [], [], [$token]);
    $hashDepois = (string)$pdo->query('SELECT senha_hash FROM usuarios WHERE id = ' . (int)$alvo['id'])->fetchColumn();
    $check(password_verify($senhaNova, $hashDepois), '(B) recuperação de senha concluída pelo fluxo real');

    [$m1] = $req($s1, $T0 + 180, 'AdminCentralController', 'index');
    $check($revogada($m1), '(B) navegador 1: a sessão anterior à recuperação é encerrada na próxima requisição (controller NÃO roda; login com motivo=senha)');
    [$m1b] = $req($s1, $T0 + 181, 'AdminCentralController', 'index');
    $check(($m1b['user_id'] ?? null) === null && str_contains((string)($m1b['location'] ?? ''), '/login'), '(B) o mesmo cookie não volta a autenticar (o gate manda ao login)');
    [$m2, $corpo2] = $req($s2, $T0 + 182, 'AdminCentralController', 'index', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $j = json_decode(trim($corpo2), true);
    $check(($m2['status'] ?? 0) === 401 && ($j['erro'] ?? '') === 'sessao_expirada' && ($j['motivo'] ?? '') === 'credencial_alterada' && ($m2['controller_rodou'] ?? true) === false, '(B) navegador 2 (AJAX): 401 JSON, ação não executada');
    // navegador 3: sessão aberta antes da troca e usada pela 1ª vez num POST
    [$s3] = $login($alvo, $senhaNova, $T0 + 184);
    User::updatePassword($alvo['id'], password_hash($senhaNova, PASSWORD_BCRYPT)); // mesma senha, novo hash: também é uma redefinição
    [$mPost] = $req($s3, $T0 + 185, 'AdminFeedbacksController', 'concluir', ['csrf' => 'x', 'resultado_geral' => 'x']);
    $check(($mPost['controller_rodou'] ?? true) === false && str_contains((string)($mPost['location'] ?? ''), 'perdido=1') && str_contains((string)($mPost['location'] ?? ''), 'motivo=senha'), '(B) POST com sessão revogada NÃO é processado e o login avisa que o envio foi perdido');

    [, $mVelha] = $login($alvo, $senhaInicial, $T0 + 200);
    $check(($mVelha['user_id'] ?? null) === null, '(B) a senha antiga não autentica mais');
    [$sNova, $mNova] = $login($alvo, $senhaNova, $T0 + 210);
    [$mUsa] = $req($sNova, $T0 + 270, 'AdminCentralController', 'index');
    $check(($mNova['user_id'] ?? null) === $alvo['id'] && $autenticada($mUsa, $alvo['id']), '(B) login com a nova senha funciona e a nova sessão continua válida');

    // expiração normal preservada
    [$mIdle] = $req($sNova, $T0 + 270 + SessionPolicy::ociosidade() + 1, 'AdminCentralController', 'index');
    $check(($mIdle['controller_rodou'] ?? true) === false && str_contains((string)$mIdle['location'], 'expired=1') && !str_contains((string)$mIdle['location'], 'motivo=senha'), '(B) expiração por inatividade continua igual (sem motivo de senha)');

    // redefinição administrativa: revoga o alvo, preserva o administrador
    [$sAlvo2] = $login($alvo, $senhaNova, $T0 + 300);
    $actor = User::findById($admin['id']);
    $res = User::adminChangePassword($alvo['id'], 'SenhaAdmin#2026xZ', $actor, '127.0.0.1');
    [$mAlvo2] = $req($sAlvo2, $T0 + 360, 'AdminCentralController', 'index');
    [$mAdm] = $req($sAdm, $T0 + 360, 'AdminCentralController', 'index');
    $check(($res['ok'] ?? false) === true && $revogada($mAlvo2), '(B) redefinição administrativa: sessão do usuário alvo encerrada');
    $check($autenticada($mAdm, $admin['id']), '(B) a sessão do administrador que redefiniu a senha NÃO é afetada');

    // senha temporária (Colaboradores → Acesso), caminho que não grava last_password_reset_at
    [$sAlvo3] = $login($alvo, 'SenhaAdmin#2026xZ', $T0 + 400);
    User::definirSenhaHash($alvo['id'], password_hash('Temporaria#2026x', PASSWORD_BCRYPT));
    [$mAlvo3] = $req($sAlvo3, $T0 + 460, 'AdminCentralController', 'index');
    $check($revogada($mAlvo3), '(B) senha temporária definida pelo administrador também encerra as sessões anteriores');

    // usuário excluído
    $excluido = $novo('excluido', 'viewer');
    [$sExc] = $login($excluido, $senhaInicial, $T0 + 500);
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$excluido['id']]);
    [$mExc] = $req($sExc, $T0 + 560, 'AdminCentralController', 'index');
    $check(($mExc['controller_rodou'] ?? true) === false && ($mExc['user_id'] ?? null) === null, '(B) usuário excluído: a sessão aberta deixa de valer');

    // mensagem na tela de login
    [, $htmlLogin] = $req('', $T0, 'AuthController', 'login', [], [], ['expired' => '1', 'motivo' => 'senha']);
    $check(str_contains($htmlLogin, 'a senha desta conta foi alterada') && !str_contains($htmlLogin, 'inatividade'), '(B) a tela de login explica que a sessão foi encerrada pela troca de senha');

    echo "\nSESSAO_REVOGACAO_SENHA_OK\n";
} finally {
    $_SESSION = [];
    if ($criados !== []) {
        $ph = implode(',', array_fill(0, count($criados), '?'));
        $pdo->prepare("DELETE FROM password_resets WHERE usuario_id IN ($ph)")->execute($criados);
        $pdo->prepare("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($ph) OR actor_usuario_id IN ($ph)")->execute(array_merge($criados, $criados));
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($criados);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
