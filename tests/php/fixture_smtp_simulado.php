<?php

/**
 * Fixture (NÃO é teste): servidor SMTP SIMULADO em 127.0.0.1 — a suíte nunca envia e-mail real.
 * Uso: php fixture_smtp_simulado.php <modo> <arquivo_saida> <seguranca ssl|starttls|plain> <usuario> <senha> [pem do certificado] [segundos de vida]
 * Modos: ok | rejeita_remetente | rejeita_destinatario | recusa_dados | derruba
 * Imprime "PORT=<n>" ao ficar pronto e grava em <arquivo_saida> (JSON) os eventos e as mensagens recebidas.
 */

[, $modo, $saida, $seguranca, $usuario, $senha, $pem, $vida] = $argv + array_fill(0, 8, '');
$vida = (int)($vida ?: 40);
$estado = ['modo' => $modo, 'conexoes' => 0, 'auth_ok' => 0, 'auth_falha' => 0, 'mensagens' => [], 'eventos' => []];
$grava = static function () use (&$estado, $saida): void {
    file_put_contents($saida, json_encode($estado, JSON_UNESCAPED_UNICODE));
};

$ctx = stream_context_create($pem !== '' ? ['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]] : []);
$flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
$srv = @stream_socket_server(($seguranca === 'ssl' ? 'ssl' : 'tcp') . '://127.0.0.1:0', $errno, $errstr, $flags, $ctx);
if ($srv === false) {
    fwrite(STDERR, "nao foi possivel abrir o servidor: {$errstr}\n");
    exit(1);
}
$porta = (int)substr(strrchr((string)stream_socket_get_name($srv, false), ':'), 1);
$grava();
echo "PORT={$porta}\n";
fflush(STDOUT);

$fim = time() + $vida;
while (time() < $fim) {
    $c = @stream_socket_accept($srv, 1);
    if ($c === false) {
        continue; // timeout do accept OU handshake TLS recusado pelo cliente (certificado não confiável)
    }
    $estado['conexoes']++;
    $grava();
    if ($modo === 'derruba') {
        fclose($c);
        continue;
    }
    stream_set_timeout($c, 5);
    $envia = static function (string $l) use ($c): void {
        fwrite($c, $l . "\r\n");
    };
    $envia('220 simulado ESMTP');
    $autenticado = false;
    $tlsAtivo = $seguranca !== 'starttls';
    $msg = ['from' => '', 'to' => [], 'data' => ''];
    while (($linha = fgets($c)) !== false) {
        $l = rtrim($linha, "\r\n");
        $u = strtoupper($l);
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) {
            $envia('250-simulado');
            $envia('250-AUTH LOGIN PLAIN');
            if ($seguranca === 'starttls' && !$tlsAtivo) {
                $envia('250-STARTTLS');
            }
            $envia('250 8BITMIME');
        } elseif ($u === 'STARTTLS') {
            $envia('220 pronto para TLS');
            stream_context_set_option($c, 'ssl', 'local_cert', $pem);
            stream_context_set_option($c, 'ssl', 'verify_peer', false);
            if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                break;
            }
            $tlsAtivo = true;
        } elseif (str_starts_with($u, 'AUTH LOGIN')) {
            $envia('334 VXNlcm5hbWU6');
            $u1 = base64_decode(trim((string)fgets($c)));
            $envia('334 UGFzc3dvcmQ6');
            $p1 = base64_decode(trim((string)fgets($c)));
            $ok = $u1 === $usuario && $p1 === $senha;
            $estado[$ok ? 'auth_ok' : 'auth_falha']++;
            $autenticado = $ok;
            $envia($ok ? '235 2.7.0 autenticado' : '535 5.7.8 credenciais invalidas');
        } elseif (str_starts_with($u, 'AUTH PLAIN')) {
            $b = trim(substr($l, 10));
            if ($b === '') {
                $envia('334 ');
                $b = trim((string)fgets($c));
            }
            $partes = explode("\0", (string)base64_decode($b));
            $ok = ($partes[1] ?? '') === $usuario && ($partes[2] ?? '') === $senha;
            $estado[$ok ? 'auth_ok' : 'auth_falha']++;
            $autenticado = $ok;
            $envia($ok ? '235 2.7.0 autenticado' : '535 5.7.8 credenciais invalidas');
        } elseif (str_starts_with($u, 'MAIL FROM')) {
            if (!$autenticado) {
                $envia('530 5.7.0 autenticacao necessaria');
            } elseif ($modo === 'rejeita_remetente') {
                $envia('550 5.7.1 remetente recusado');
            } else {
                $msg = ['from' => $l, 'to' => [], 'data' => ''];
                $envia('250 2.1.0 ok');
            }
        } elseif (str_starts_with($u, 'RCPT TO')) {
            if ($modo === 'rejeita_destinatario') {
                $envia('550 5.1.1 destinatario inexistente');
            } else {
                $msg['to'][] = $l;
                $envia('250 2.1.5 ok');
            }
        } elseif ($u === 'DATA') {
            $envia('354 envie, termine com <CRLF>.<CRLF>');
            $dados = '';
            while (($d = fgets($c)) !== false) {
                if ($d === ".\r\n") {
                    break;
                }
                $dados .= $d;
            }
            if ($modo === 'recusa_dados') {
                $envia('554 5.6.0 mensagem recusada');
            } else {
                $msg['data'] = $dados;
                $estado['mensagens'][] = $msg;
                $envia('250 2.0.0 mensagem aceita');
            }
        } elseif ($u === 'RSET') {
            $envia('250 ok');
        } elseif ($u === 'NOOP') {
            $envia('250 ok');
        } elseif ($u === 'QUIT') {
            $envia('221 tchau');
            break;
        } else {
            $envia('502 comando nao implementado');
        }
        $grava();
    }
    @fclose($c);
    $grava();
}
$grava();
