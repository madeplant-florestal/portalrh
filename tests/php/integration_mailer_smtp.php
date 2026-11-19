<?php

/**
 * Integração — Mailer (PHPMailer + SMTP autenticado) contra um servidor SMTP SIMULADO local (fixture_smtp_simulado.php): nada é enviado de verdade.
 * Cobre: configuração correta (TLS implícito 465-like e STARTTLS 587-like), desativado/ausente/inválida, senha incorreta, conexão recusada, certificado
 * NÃO confiável (a verificação TLS nunca é desligada), remetente/destinatário recusados, dados recusados, e que NENHUM log vaza senha, corpo, token nem
 * o destinatário completo. Também prova a compatibilidade do contrato bool (sendTo/notifyHR) usado pelos demais avisos do Portal.
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_smtp_' . bin2hex(random_bytes(4));
mkdir($tmp);
$procs = [];
$SENHA = 'ZZ-SENHA-SMTP-' . bin2hex(random_bytes(6));
$TOKEN = 'zztoken' . bin2hex(random_bytes(16));
$USER = 'no-reply@teste.invalid';

// ---- certificado autoassinado para 127.0.0.1 (SAN) -----------------------------------------------------------------------------------------------
$cnf = $tmp . DIRECTORY_SEPARATOR . 'openssl.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=127.0.0.1\n[ext]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
$key = openssl_pkey_new(['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $cnf]);
$crt = openssl_csr_sign($csr, null, $key, 1, ['config' => $cnf, 'x509_extensions' => 'ext']);
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);
$pem = $tmp . DIRECTORY_SEPARATOR . 'servidor.pem';
file_put_contents($pem, $crtPem . $keyPem);
$ca = $tmp . DIRECTORY_SEPARATOR . 'ca.pem';
file_put_contents($ca, $crtPem);

/** Sobe o SMTP simulado; devolve [porta, arquivo de saída]. */
$sobe = static function (string $modo, string $seguranca) use (&$procs, $tmp, $pem, $USER, $SENHA): array {
    $saida = $tmp . DIRECTORY_SEPARATOR . 'saida_' . bin2hex(random_bytes(3)) . '.json';
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_smtp_simulado.php', $modo, $saida, $seguranca, $USER, $SENHA, $pem, '30'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$p, $pipes];
    $linha = (string)fgets($pipes[1]);
    return [preg_match('/PORT=(\d+)/', $linha, $m) ? (int)$m[1] : 0, $saida];
};
$estado = static fn(string $saida): array => (array)(json_decode((string)@file_get_contents($saida), true) ?: []);
$cfg = static fn(int $porta, string $seguranca = 'ssl', array $extra = []): array => $extra + [
    'enabled' => true, 'driver' => 'smtp', 'from' => $USER, 'from_name' => 'Portal RH Madeplant', 'reply_to' => '', 'to_hr' => 'rh@teste.invalid',
    'smtp_host' => '127.0.0.1', 'smtp_port' => $porta, 'smtp_secure' => $seguranca, 'smtp_user' => $USER, 'smtp_pass' => $SENHA, 'smtp_timeout' => 5, 'smtp_cafile' => $ca,
];
$usa = static function (array $c): void {
    putenv('RH_TEST_MAIL_CONFIG=' . json_encode($c));
};
$logPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.jsonl';
$tamLog = is_file($logPath) ? filesize($logPath) : 0;
$logNovo = static function () use ($logPath, &$tamLog): string {
    clearstatcache();
    $atual = is_file($logPath) ? filesize($logPath) : 0;
    $txt = $atual > $tamLog ? (string)file_get_contents($logPath, false, null, $tamLog, $atual - $tamLog) : '';
    $tamLog = $atual;
    return $txt;
};
$corpo = "Olá,\nLink: https://exemplo.invalid/admin/reset-password/{$TOKEN}\nNão responda.";

try {
    // ---- 1. configuração ausente / desativada / inválida ---------------------------------------------------------------------------------------------
    putenv('RH_TEST_MAIL_CONFIG=' . json_encode(['enabled' => false]));
    $r = Mailer::enviar('alguem@teste.invalid', 'Assunto', $corpo);
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_DESATIVADO && Mailer::sendTo('alguem@teste.invalid', 'a', 'b') === false, '(1) desativado (padrão): nada é enviado, etapa "desativado" e sendTo() = false');
    $usa(['enabled' => true, 'driver' => 'smtp', 'from' => $USER]);
    $r = Mailer::enviar('alguem@teste.invalid', 'Assunto', $corpo);
    $check($r['etapa'] === Mailer::ETAPA_CONFIG && str_contains($r['mensagem'], 'smtp_host'), '(1) configuração ausente (sem host/credenciais): etapa "config", sem tentar conexão');
    $usa($cfg(465, 'ssl', ['from' => 'isto-nao-e-email']));
    $check(Mailer::enviar('a@teste.invalid', 'x', 'y')['etapa'] === Mailer::ETAPA_CONFIG, '(1) remetente (mail.from) inválido: etapa "config"');
    $usa($cfg(465, 'ssl', ['smtp_secure' => 'none']));
    $check(Mailer::enviar('a@teste.invalid', 'x', 'y')['etapa'] === Mailer::ETAPA_CONFIG, '(1) conexão sem criptografia NÃO é aceita (smtp_secure inválido)');
    $usa($cfg(465, 'tls'));
    $check(Mailer::enviar('a@teste.invalid', 'x', 'y')['etapa'] === Mailer::ETAPA_CONFIG, '(1) porta 465 com STARTTLS (incompatível) é recusada na configuração');
    $usa($cfg(587, 'ssl'));
    $check(Mailer::enviar('a@teste.invalid', 'x', 'y')['etapa'] === Mailer::ETAPA_CONFIG, '(1) porta 587 com TLS implícito (incompatível) é recusada na configuração');
    $usa($cfg(465));
    $check(Mailer::enviar('nao-e-email', 'x', 'y')['etapa'] === Mailer::ETAPA_DESTINATARIO, '(1) destinatário inválido: recusado antes de conectar');
    $logNovo();

    // ---- 2. SMTP correto: TLS implícito (estilo 465) ----------------------------------------------------------------------------------------------------
    [$porta, $saida] = $sobe('ok', 'ssl');
    $check($porta > 0, '(2) servidor SMTP simulado no ar (TLS implícito)');
    $usa($cfg($porta, 'ssl'));
    $r = Mailer::enviar('Destino.Teste@exemplo.invalid', 'Recuperação de senha — Portal', $corpo);
    $e = $estado($saida);
    $msg = $e['mensagens'][0] ?? null;
    $dados = (string)($msg['data'] ?? '');
    $check($r['ok'] === true && $r['etapa'] === 'ok' && count($e['mensagens'] ?? []) === 1 && ($e['auth_ok'] ?? 0) === 1, '(2) TLS implícito + autenticação: o SMTP aceita e a mensagem chega ao servidor simulado');
    $check(str_contains((string)$msg['from'], $USER) && str_contains(strtolower(implode(' ', $msg['to'])), 'destino.teste@exemplo.invalid'), '(2) remetente (envelope) = mail.from e destinatário correto');
    $check(str_contains($dados, 'From: Portal RH Madeplant <' . $USER . '>') && !str_contains($dados, 'Reply-To:') && str_contains($dados, "Link: https://exemplo.invalid/admin/reset-password/{$TOKEN}"), '(2) cabeçalho From com o nome oficial; sem Reply-To quando não configurado; corpo íntegro');
    $check(preg_match('/Subject: (.+)/', $dados, $mm) === 1 && iconv_mime_decode(trim($mm[1]), 0, 'UTF-8') === 'Recuperação de senha — Portal', '(2) assunto com acentuação preservado (UTF-8)');
    $check(Mailer::sendTo('Destino.Teste@exemplo.invalid', 'a', 'b') === true && Mailer::notifyHR('Aviso RH', 'texto') === true, '(2) compatibilidade: sendTo() e notifyHR() (usados pelos demais avisos) devolvem true');
    $usa($cfg($porta, 'ssl', ['reply_to' => 'rh-respostas@teste.invalid']));
    Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $e = $estado($saida);
    $check(str_contains((string)end($e['mensagens'])['data'], 'Reply-To: rh-respostas@teste.invalid'), '(2) Reply-To só quando configurado');
    $logSucesso = $logNovo();
    $check(!str_contains($logSucesso, $SENHA) && !str_contains($logSucesso, $TOKEN), '(2) envio bem-sucedido não deixa senha nem token no log');

    // ---- 3. STARTTLS (estilo 587) -----------------------------------------------------------------------------------------------------------------------------
    [$p587, $s587] = $sobe('ok', 'starttls');
    $usa($cfg($p587, 'tls'));
    $r = Mailer::enviar('d@exemplo.invalid', 'STARTTLS', 'corpo');
    $check($r['ok'] === true && count($estado($s587)['mensagens'] ?? []) === 1, '(3) STARTTLS: conexão em texto, upgrade para TLS com certificado validado e envio aceito');

    // ---- 4. falhas ---------------------------------------------------------------------------------------------------------------------------------------------------
    [$pA, $sA] = $sobe('ok', 'ssl');
    $usa($cfg($pA, 'ssl', ['smtp_pass' => 'senha-errada-ZZ']));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', $corpo);
    $logAuth = $logNovo();
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_AUTENTICACAO && ($estado($sA)['auth_falha'] ?? 0) >= 1 && count($estado($sA)['mensagens'] ?? []) === 0, '(4) senha incorreta: etapa "autenticacao" e nenhuma mensagem aceita');
    $check(!str_contains($logAuth, 'senha-errada-ZZ') && !str_contains($logAuth, $SENHA) && !str_contains($logAuth, $TOKEN) && str_contains($logAuth, 'Falha no envio de e-mail') && str_contains($logAuth, 'autenticacao'), '(4) a falha é registrada (etapa/host/porta) SEM senha, usuário, corpo ou token');
    $check(!str_contains(json_encode($r), $SENHA) && !str_contains(json_encode($r), 'senha-errada-ZZ'), '(4) o resultado devolvido à tela também não contém a senha');

    $porta0 = 1; // porta fechada
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $portaFechada = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $usa($cfg($portaFechada, 'ssl'));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_CONEXAO, '(4) conexão recusada (porta fechada): etapa "conexao"');

    [$pT, $sT] = $sobe('ok', 'ssl');
    $usa($cfg($pT, 'ssl', ['smtp_cafile' => '']));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_TLS && count($estado($sT)['mensagens'] ?? []) === 0 && ($estado($sT)['auth_ok'] ?? 0) === 0, '(4) certificado NÃO confiável (autoassinado, sem a CA): falha TLS ANTES de autenticar — a verificação nunca é desligada');

    [$pR, $sR] = $sobe('rejeita_remetente', 'ssl');
    $usa($cfg($pR));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_REMETENTE && ($r['codigo_smtp'] ?? 0) === 550, '(4) remetente recusado pelo servidor (550): etapa "remetente"');
    [$pD, $sD] = $sobe('rejeita_destinatario', 'ssl');
    $usa($cfg($pD));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_DESTINATARIO, '(4) destinatário recusado (550): etapa "destinatario"');
    [$pX, $sX] = $sobe('recusa_dados', 'ssl');
    $usa($cfg($pX));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && $r['etapa'] === Mailer::ETAPA_ENVIO, '(4) mensagem recusada no DATA (554): etapa "envio"');
    [$pQ] = $sobe('derruba', 'ssl');
    $usa($cfg($pQ));
    $r = Mailer::enviar('d@exemplo.invalid', 'a', 'b');
    $check($r['ok'] === false && Mailer::sendTo('d@exemplo.invalid', 'a', 'b') === false, '(4) servidor que derruba a conexão: falha tratada (bool false), sem exceção');

    $todosLogs = $logNovo();
    $check(!str_contains($todosLogs, $SENHA) && !str_contains($todosLogs, $TOKEN) && !str_contains($todosLogs, 'Destino.Teste') && !preg_match('/"[^"]*@(?!teste\.invalid)[a-z.]+/i', preg_replace('/"destino_dominio":"[^"]*"/', '', $todosLogs)) , '(4) em nenhuma falha o log contém senha, token, corpo ou o destinatário completo (só o domínio)');
} finally {
    foreach ($procs as [$p, $pipes]) {
        @proc_terminate($p);
        foreach ($pipes as $pp) {
            @fclose($pp);
        }
        @proc_close($p);
    }
    putenv('RH_TEST_MAIL_CONFIG');
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

// ---- 5. contratos estáticos ----------------------------------------------------------------------------------------------------------------------------------------
$fonte = (string)file_get_contents(__DIR__ . '/../../app/core/Mailer.php');
$check(!preg_match('/verify_peer[\'"]\s*=>\s*false|verify_peer_name[\'"]\s*=>\s*false|allow_self_signed[\'"]\s*=>\s*true|SMTPSecure\s*=\s*false/i', $fonte), '(5) o código do Mailer não desativa a verificação de certificado nem aceita autoassinado');
$lock = json_decode((string)file_get_contents(__DIR__ . '/../../composer.lock'), true);
$versoes = array_column($lock['packages'] ?? [], 'version', 'name');
$check(isset($versoes['phpmailer/phpmailer']) && str_starts_with($versoes['phpmailer/phpmailer'], 'v6.') && class_exists('PHPMailer\\PHPMailer\\PHPMailer') && isset($versoes['dompdf/dompdf']) && version_compare(ltrim($versoes['dompdf/dompdf'], 'v'), '3.1.6', '>='), '(5) autoload do Composer e lock consistentes: PHPMailer 6.x carregável; dompdf >= 3.1.6');
$cfgExemplo = (string)file_get_contents(__DIR__ . '/../../app/config/config.example.php') . (string)file_get_contents(__DIR__ . '/../../app/config/config.php');
$check(!preg_match("/smtp_pass'\s*=>\s*'[^']+'/", $cfgExemplo) && preg_match("/'enabled' => false/", $cfgExemplo) === 1, '(5) os arquivos de configuração VERSIONADOS não contêm senha SMTP e vêm com o envio desligado');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — Mailer SMTP (servidor simulado).\n";
