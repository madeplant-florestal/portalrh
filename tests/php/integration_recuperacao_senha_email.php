<?php

/**
 * Integração — recuperação de senha com e-mail (SMTP SIMULADO) e diagnóstico de envio do Supervisor Geral.
 * Recuperação: conta existente/inexistente (resposta IDÊNTICA), token aleatório com SÓ o hash no banco, uso único, validade de 30 min, invalidação dos
 * anteriores, limite de 3 por conta e 10 por IP em 15 min (também para e-mail inexistente), CSRF, falha de SMTP sem vazamento. Diagnóstico: só Supervisor
 * Geral, CSRF, um destinatário, limite por hora, auditoria, "aceito pelo SMTP" sem afirmar entrega, sem senha na tela.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
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

$suf = substr((string)time(), -5) . random_int(10, 99);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_rec_' . bin2hex(random_bytes(4));
mkdir($tmp);
$procs = [];
$criados = [];
$limites = [];
$SENHA_SMTP = 'ZZ-SMTP-' . bin2hex(random_bytes(6));
$USER_SMTP = 'no-reply@teste.invalid';
$senhaClara = 'SenhaForte#2026x';
$logPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.jsonl';
$tamLog = is_file($logPath) ? filesize($logPath) : 0;
$logNovo = static function () use ($logPath, &$tamLog): string {
    clearstatcache();
    $atual = is_file($logPath) ? filesize($logPath) : 0;
    $txt = $atual > $tamLog ? (string)file_get_contents($logPath, false, null, $tamLog, $atual - $tamLog) : '';
    $tamLog = $atual;
    return $txt;
};

// certificado de teste + servidor SMTP simulado
$cnf = $tmp . DIRECTORY_SEPARATOR . 'o.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=127.0.0.1\n[ext]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
$key = openssl_pkey_new(['config' => $cnf, 'private_key_bits' => 2048]);
$crt = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $cnf]), null, $key, 1, ['config' => $cnf, 'x509_extensions' => 'ext']);
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);
$pem = $tmp . DIRECTORY_SEPARATOR . 's.pem';
file_put_contents($pem, $crtPem . $keyPem);
$sobe = static function (string $modo = 'ok') use (&$procs, $tmp, $pem, $USER_SMTP, $SENHA_SMTP): array {
    $saida = $tmp . DIRECTORY_SEPARATOR . 'out_' . bin2hex(random_bytes(3)) . '.json';
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_smtp_simulado.php', $modo, $saida, 'ssl', $USER_SMTP, $SENHA_SMTP, $pem, '90'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$p, $pipes];
    preg_match('/PORT=(\d+)/', (string)fgets($pipes[1]), $m);
    return [(int)($m[1] ?? 0), $saida];
};
$mensagens = static fn(string $saida): array => (array)((json_decode((string)@file_get_contents($saida), true) ?: [])['mensagens'] ?? []);
$cfgJson = static fn(int $porta, string $senha) => json_encode([
    'enabled' => true, 'driver' => 'smtp', 'from' => $USER_SMTP, 'from_name' => 'Portal RH Madeplant', 'smtp_host' => '127.0.0.1', 'smtp_port' => $porta,
    'smtp_secure' => 'ssl', 'smtp_user' => $USER_SMTP, 'smtp_pass' => $senha, 'smtp_timeout' => 5, 'smtp_cafile' => $pem, 'to_hr' => 'rh@teste.invalid',
]);
/** Uma requisição HTTP (controller em processo próprio, SMTP via env). [status, corpo] */
$http = static function (string $cfg, int $uid, string $role, bool $sup, string $ctrl, string $metodo, array $post = [], array $args = [], string $ip = '10.9.9.9') use ($cfgJson): array {
    $env = array_merge(getenv(), ['RH_TEST_MAIL_CONFIG' => $cfg]);
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, $sup ? '1' : '0', $ctrl, $metodo, '[]', json_encode($args), $post === [] ? '' : json_encode($post), json_encode(['REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => $ip])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    $st = preg_match('/@@STATUS=(\d+)@@\s*$/', $out, $m) ? (int)$m[1] : 0;
    return [$st, preg_replace('/\n?@@STATUS=\d+@@\s*$/', '', $out)];
};
$norm = static fn(string $html): string => preg_replace('/\s+/', ' ', preg_replace(['/value="[0-9a-f]{64}"/', '/content="[0-9a-f]{64}"/'], ['value="X"', 'content="X"'], $html));
$recuperar = static function (string $cfg, string $email, string $ip, array $extra = []) use ($http): array {
    return $http($cfg, 0, 'viewer', false, 'PasswordRecoveryController', 'sendToken', ['email' => $email] + $extra, [], $ip);
};
$novoUsuario = static function (string $apelido, string $role, int $flag = 0) use ($pdo, $suf, $senhaClara, &$criados): array {
    $email = strtolower($apelido) . '.zzr.' . $suf . '@teste.local';
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute(['ZZR ' . $apelido, $email, password_hash($senhaClara, PASSWORD_BCRYPT), $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $criados[] = $id;
    return ['id' => $id, 'email' => $email, 'role' => $role];
};
$valor = static function (string $sql, array $p = []) use ($pdo): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
};
$limite = static function (string $escopo, string $chave) use (&$limites): void {
    $limites[] = hash('sha256', $escopo . '|' . $chave);
};
$tokenDe = static function (array $msg): ?string {
    return preg_match('#/admin/reset-password/([0-9a-f]{64})#', (string)($msg['data'] ?? ''), $m) ? $m[1] : null;
};

try {
    [$porta, $saida] = $sobe('ok');
    $cfg = $cfgJson($porta, $SENHA_SMTP);
    $uA = $novoUsuario('contaa', 'viewer');
    $uB = $novoUsuario('contab', 'viewer');
    $inexistente = 'inexistente.zzr.' . $suf . '@teste.local';
    $ipA = '10.20.' . random_int(1, 200) . '.1';
    foreach ([$uA['email'], $uB['email'], $inexistente] as $e) {
        $limite('pwd_reset_conta', strtolower($e));
    }
    $limite('pwd_reset_ip', $ipA);

    // ---- 1. conta existente x inexistente ----------------------------------------------------------------------------------------------------------------------
    [$st1, $html1] = $recuperar($cfg, $uA['email'], $ipA);
    $msgs = $mensagens($saida);
    $tok = $tokenDe($msgs[0] ?? []);
    $check($st1 === 200 && str_contains($html1, 'Se o e-mail existir, um link de redefinição foi enviado.') && count($msgs) === 1 && $tok !== null, '(1) conta existente: resposta genérica e UM e-mail aceito pelo SMTP com o link de redefinição');
    $linha = $pdo->prepare('SELECT token_hash, used_at, expires_at FROM password_resets WHERE usuario_id = ? ORDER BY id DESC LIMIT 1');
    $linha->execute([$uA['id']]);
    $reset = $linha->fetch(PDO::FETCH_ASSOC) ?: [];
    $expira = new DateTimeImmutable((string)($reset['expires_at'] ?? '2000-01-01'), new DateTimeZone('UTC'));
    $dif = $expira->getTimestamp() - time();
    $check(($reset['token_hash'] ?? '') === hash('sha256', (string)$tok) && $reset['used_at'] === null && $dif > 29 * 60 && $dif <= 30 * 60 + 5, '(1) token de 64 hex (32 bytes): o banco guarda SÓ o SHA-256, não usado, validade de 30 minutos');
    $check((int)$valor('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [(string)$tok]) === 0, '(1) o token em claro NÃO existe em nenhuma coluna');
    $antesResets = (int)$valor('SELECT COUNT(*) FROM password_resets');
    [$st2, $html2] = $recuperar($cfg, $inexistente, $ipA);
    $check($st2 === 200 && $norm($html1) === $norm($html2), '(1) e-mail INEXISTENTE: a página é IDÊNTICA à de conta existente (sem enumeração por diferença de resposta)');
    $check(count($mensagens($saida)) === 1 && (int)$valor('SELECT COUNT(*) FROM password_resets') === $antesResets, '(1) e-mail inexistente: nenhum e-mail enviado e nenhum token criado');

    // ---- 2. invalidação dos tokens anteriores e uso único -----------------------------------------------------------------------------------------------------
    [$st3] = $recuperar($cfg, $uA['email'], $ipA);
    $msgs = $mensagens($saida);
    $tok2 = $tokenDe(end($msgs) ?: []);
    $check($tok2 !== null && $tok2 !== $tok && $valor('SELECT used_at FROM password_resets WHERE token_hash = ?', [hash('sha256', (string)$tok)]) !== null && PasswordReset::findValidByToken((string)$tok) === null && PasswordReset::findValidByToken((string)$tok2) !== null, '(2) nova solicitação INVALIDA o token anterior; só o novo é válido');
    [$stR, $htmlR] = $http($cfg, 0, 'viewer', false, 'PasswordRecoveryController', 'performReset', ['password' => 'NovaSenha#2026xyz', 'password_confirm' => 'NovaSenha#2026xyz'], [(string)$tok2], $ipA);
    $check(str_contains($htmlR, 'Senha redefinida com sucesso') && count($mensagens($saida)) === 3 && str_contains((string)(end($msgs) ?: [])['data'] ?? '', 'x') , '(2) o link do e-mail redefine a senha (e o aviso "Senha redefinida" também sai pelo SMTP — compatibilidade)');
    [, $htmlR2] = $http($cfg, 0, 'viewer', false, 'PasswordRecoveryController', 'performReset', ['password' => 'OutraSenha#2026xyz', 'password_confirm' => 'OutraSenha#2026xyz'], [(string)$tok2], $ipA);
    $check(str_contains($htmlR2, 'Token inválido ou expirado') && PasswordReset::findValidByToken((string)$tok2) === null, '(2) USO ÚNICO: o mesmo token não redefine uma segunda vez');
    $agora0 = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
    PasswordReset::create($uB['id'], 'zz-token-controlado-' . $suf, 30, $agora0);
    $check(PasswordReset::findValidByToken('zz-token-controlado-' . $suf, $agora0->modify('+29 minutes 59 seconds')) !== null && PasswordReset::findValidByToken('zz-token-controlado-' . $suf, $agora0->modify('+30 minutes 1 second')) === null, '(2) validade de 30 minutos preservada (vale em 29 min 59 s; expira após 30 min)');

    // ---- 3. limite por conta (3 / 15 min) — também sem diferença de resposta ------------------------------------------------------------------------------------
    $ipB = '10.30.' . random_int(1, 200) . '.1';
    $limite('pwd_reset_ip', $ipB);
    $antes = count($mensagens($saida));
    $respostas = [];
    for ($i = 1; $i <= 5; $i++) {
        [, $h] = $recuperar($cfg, $uB['email'], $ipB);
        $respostas[] = $norm($h);
    }
    $enviados = count($mensagens($saida)) - $antes;
    $check($enviados === 3 && count(array_unique($respostas)) === 1, '(3) conta existente: 5 pedidos em 15 min => só 3 e-mails; a 4ª e a 5ª resposta são IDÊNTICAS às demais (o limite não revela nada)');
    $ipC = '10.31.' . random_int(1, 200) . '.1';
    $limite('pwd_reset_ip', $ipC);
    $respI = [];
    for ($i = 1; $i <= 5; $i++) {
        [, $h] = $recuperar($cfg, $inexistente, $ipC);
        $respI[] = $norm($h);
    }
    $check(count(array_unique($respI)) === 1 && $respI[0] === $norm($html1), '(3) e-mail inexistente também é limitado, com a MESMA resposta (sem enumeração)');

    // ---- 4. limite por IP (10 / 15 min) -------------------------------------------------------------------------------------------------------------------------
    $ipD = '10.32.' . random_int(1, 200) . '.1';
    $limite('pwd_reset_ip', $ipD);
    for ($i = 1; $i <= 10; $i++) {
        $recuperar($cfg, "zzr.alvo{$i}.{$suf}@teste.local", $ipD);
        $limite('pwd_reset_conta', strtolower("zzr.alvo{$i}.{$suf}@teste.local"));
    }
    $uC = $novoUsuario('contac', 'viewer');
    $limite('pwd_reset_conta', strtolower($uC['email']));
    $antes = count($mensagens($saida));
    [, $hIp] = $recuperar($cfg, $uC['email'], $ipD);
    $check(count($mensagens($saida)) === $antes && $norm($hIp) === $norm($html1), '(4) 11ª solicitação do MESMO IP (conta que nunca pediu): nenhum e-mail e resposta idêntica');
    [, $hOutroIp] = $recuperar($cfg, $uC['email'], '10.33.' . random_int(1, 200) . '.1');
    $limite('pwd_reset_ip', '10.33.0.0');
    $check(count($mensagens($saida)) === $antes + 1, '(4) o limite é por IP: a mesma conta, de outro IP, ainda recebe o e-mail');

    // ---- 5. CSRF ----------------------------------------------------------------------------------------------------------------------------------------------------------
    $ipE = '10.34.' . random_int(1, 200) . '.1';
    $limite('pwd_reset_ip', $ipE);
    $antes = count($mensagens($saida));
    [$stC] = $recuperar($cfg, $uA['email'], $ipE, ['csrf' => 'forjado']);
    $check($stC === 400 && count($mensagens($saida)) === $antes, '(5) CSRF inválido: 400 e nenhum e-mail');

    // ---- 6. falha do SMTP: tela inalterada, nada vaza ---------------------------------------------------------------------------------------------------------------
    $uD = $novoUsuario('contad', 'viewer');
    $limite('pwd_reset_conta', strtolower($uD['email']));
    $ipF = '10.35.' . random_int(1, 200) . '.1';
    $limite('pwd_reset_ip', $ipF);
    $logNovo();
    [$stF, $htmlF] = $recuperar($cfgJson($porta, 'senha-errada-ZZ'), $uD['email'], $ipF);
    $logF = $logNovo();
    $check($stF === 200 && $norm($htmlF) === $norm($html1), '(6) SMTP recusando a senha: o usuário vê a MESMA mensagem genérica (sem erro 500, sem revelar a falha)');
    $check(str_contains($logF, 'Falha no envio de e-mail') && str_contains($logF, 'e-mail não enviado') && str_contains($logF, 'autenticacao'), '(6) a falha é registrada com etapa e usuario_id');
    $check(!str_contains($logF, 'senha-errada-ZZ') && !str_contains($logF, $SENHA_SMTP) && !str_contains($logF, 'reset-password') && !preg_match('/[0-9a-f]{64}/', $logF) && !str_contains($logF, $uD['email']), '(6) o log não contém senha, token, link de redefinição nem o e-mail completo');
    $linhaD = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE usuario_id = ?');
    $linhaD->execute([$uD['id']]);
    $check((int)$linhaD->fetchColumn() === 1, '(6) o token foi criado (válido 30 min) mesmo com falha de envio — a pessoa pode pedir de novo sem perder a conta');

    // ---- 7. diagnóstico de envio (Supervisor Geral) ---------------------------------------------------------------------------------------------------------------------
    $sg = $novoUsuario('sg', 'admin', 1);
    $adm = $novoUsuario('adm', 'admin');
    $rh = $novoUsuario('rh', 'rh');
    $ge = $novoUsuario('gestor', 'gestor');
    $vw = $novoUsuario('viewer', 'viewer');
    $limite('smtp_teste_sg', (string)$sg['id']);
    $get = static fn(array $u, bool $sup) => $http($cfg, $u['id'], $u['role'], $sup, 'AdminSupervisorController', 'emailDiagnostico');
    [$stSg, $paginaSg] = $get($sg, true);
    $check($stSg === 200 && str_contains($paginaSg, 'Diagnóstico de envio') && str_contains($paginaSg, '127.0.0.1') && !str_contains($paginaSg, $SENHA_SMTP) && str_contains($paginaSg, 'Senha SMTP configurada'), '(7) Supervisor Geral abre o diagnóstico: vê a configuração efetiva, SEM a senha');
    $bloq = [];
    foreach ([['admin comum', $adm, false], ['RH', $rh, false], ['Gestor', $ge, false], ['Viewer', $vw, false]] as [$n, $u, $s]) {
        [$st] = $get($u, $s);
        [$st2] = $http($cfg, $u['id'], $u['role'], $s, 'AdminSupervisorController', 'emailTestar', ['destinatario' => 'x@teste.invalid']);
        $bloq[$n] = $st === 403 && $st2 === 403;
    }
    $check(!in_array(false, $bloq, true), '(7) Admin comum, RH, Gestor e Viewer: 403 na página e no envio de teste');
    $antes = count($mensagens($saida));
    [$stT, $paginaT] = $http($cfg, $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailTestar', ['destinatario' => $sg['email']]);
    $check($stT === 200 && count($mensagens($saida)) === $antes + 1 && str_contains($paginaT, 'Aceito pelo servidor SMTP') && str_contains($paginaT, 'confirma apenas que o servidor SMTP aceitou') && !str_contains($paginaT, $SENHA_SMTP), '(7) envio de teste: UMA mensagem aceita; a tela diz que isso NÃO garante entrega na caixa de entrada; sem senha');
    $check((int)$valor("SELECT COUNT(*) FROM auditoria_usuarios WHERE actor_usuario_id = ? AND action = 'email_test_accepted'", [$sg['id']]) === 1, '(7) o teste é auditado (sem conteúdo)');
    [$stC2] = $http($cfg, $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailTestar', ['csrf' => 'forjado', 'destinatario' => $sg['email']]);
    $check($stC2 === 400 && count($mensagens($saida)) === $antes + 1, '(7) CSRF inválido: 400 e nada é enviado');
    [, $paginaM] = $http($cfg, $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailTestar', ['destinatario' => 'a@teste.invalid,b@teste.invalid']);
    $check(count($mensagens($saida)) === $antes + 1 && str_contains($paginaM, 'UM e-mail válido'), '(7) vários destinatários em um envio: recusado (não vira ferramenta de envio em massa)');
    for ($i = 0; $i < 6; $i++) {
        $http($cfg, $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailTestar', ['destinatario' => $sg['email']]);
    }
    $total = count($mensagens($saida)) - $antes;
    [, $paginaL] = $http($cfg, $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailTestar', ['destinatario' => $sg['email']]);
    $check($total === 5 && str_contains($paginaL, 'Limite de 5 testes por hora'), '(7) limite de 5 testes por hora (o 6º em diante é bloqueado e informado)');
    [, $paginaFalha] = $http($cfgJson($porta, 'senha-errada-ZZ'), $sg['id'], 'admin', true, 'AdminSupervisorController', 'emailDiagnostico');
    $check(!str_contains($paginaFalha, 'senha-errada-ZZ') && str_contains($paginaFalha, 'Senha SMTP configurada'), '(7) mesmo com a senha errada configurada, a página nunca a exibe');
} finally {
    foreach ($procs as [$p, $pipes]) {
        @proc_terminate($p);
        foreach ($pipes as $pp) {
            @fclose($pp);
        }
        @proc_close($p);
    }
    if ($criados !== []) {
        $lu = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM password_resets WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE actor_usuario_id IN ({$lu}) OR target_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
    foreach ($limites as $h) {
        @unlink(STORAGE_PATH . DIRECTORY_SEPARATOR . 'ratelimit' . DIRECTORY_SEPARATOR . $h . '.json');
    }
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — recuperação de senha por e-mail e diagnóstico de envio.\n";
