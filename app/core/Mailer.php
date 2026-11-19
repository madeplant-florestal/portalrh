<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envio de e-mail do Portal RH (PHPMailer + SMTP autenticado). Configuração em `mail.*` (app/config/local.php, FORA do Git):
 *
 *   enabled      bool    liga/desliga o envio (padrão false — nada é enviado até a configuração ser concluída e testada)
 *   driver       string  'smtp' (padrão) | 'mail' (PHP mail(), só ambientes de desenvolvimento)
 *   from         string  remetente (ex.: no-reply@appmadeplant.com)       from_name  string  nome exibido (Portal RH Madeplant)
 *   reply_to     string  opcional — só definir quando houver uma caixa que realmente leia respostas
 *   smtp_host    string  servidor SMTP                                    smtp_port  int     465 (TLS implícito) ou 587 (STARTTLS)
 *   smtp_secure  string  'ssl' (TLS implícito, porta 465) | 'tls' (STARTTLS, porta 587)
 *   smtp_user    string  usuário da caixa (normalmente o próprio e-mail)  smtp_pass  string  senha — SOMENTE em local.php
 *   smtp_timeout int     segundos (padrão 10)                              smtp_cafile string opcional: CA adicional (nunca desliga a verificação)
 *
 * O certificado do servidor é SEMPRE validado (verify_peer + nome do host); não existe opção para desativar isso.
 * Contrato público preservado: sendTo()/notifyHR()/notifyUserPasswordChanged() devolvem bool e nunca lançam exceção. Para saber O QUE falhou use
 * enviar() (diagnóstico/recuperação de senha): devolve a ETAPA da falha e uma mensagem segura — nunca senha, corpo, destinatário completo nem token.
 * A aceitação pelo SMTP NÃO prova entrega na caixa de entrada (spam, SPF/DKIM do domínio e política do destinatário continuam valendo).
 */
class Mailer
{
    public const ETAPA_OK = 'ok';
    public const ETAPA_DESATIVADO = 'desativado';
    public const ETAPA_CONFIG = 'config';
    public const ETAPA_CONEXAO = 'conexao';
    public const ETAPA_TLS = 'tls';
    public const ETAPA_AUTENTICACAO = 'autenticacao';
    public const ETAPA_REMETENTE = 'remetente';
    public const ETAPA_DESTINATARIO = 'destinatario';
    public const ETAPA_ENVIO = 'envio';

    private const MENSAGENS = [
        self::ETAPA_OK => 'O servidor SMTP aceitou a mensagem.',
        self::ETAPA_DESATIVADO => 'O envio de e-mail está desativado (mail.enabled = false).',
        self::ETAPA_CONFIG => 'A configuração de e-mail está incompleta ou inválida.',
        self::ETAPA_CONEXAO => 'Não foi possível conectar ao servidor SMTP (host, porta ou rede).',
        self::ETAPA_TLS => 'Falha na negociação TLS ou na validação do certificado do servidor SMTP.',
        self::ETAPA_AUTENTICACAO => 'O servidor SMTP recusou o usuário/senha.',
        self::ETAPA_REMETENTE => 'O servidor SMTP recusou o remetente.',
        self::ETAPA_DESTINATARIO => 'O servidor SMTP recusou o destinatário.',
        self::ETAPA_ENVIO => 'O servidor SMTP não aceitou a mensagem.',
    ];

    /** Configuração efetiva (padrões + `mail.*`). Em CLI aceita RH_TEST_MAIL_CONFIG (JSON) para testes com SMTP simulado — inoperante na web. */
    public static function configuracao(): array
    {
        $cfg = (array)(Config::app()['mail'] ?? []);
        if (PHP_SAPI === 'cli') {
            $teste = getenv('RH_TEST_MAIL_CONFIG');
            if ($teste !== false && $teste !== '') {
                $dec = json_decode((string)$teste, true);
                if (is_array($dec)) {
                    $cfg = $dec;
                }
            }
        }
        return $cfg + [
            'enabled' => false, 'driver' => 'smtp', 'from' => '', 'from_name' => 'Portal RH Madeplant', 'reply_to' => '', 'to_hr' => '',
            'smtp_host' => '', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_timeout' => 10, 'smtp_cafile' => '',
        ];
    }

    public static function notifyHR(string $subject, string $message): bool
    {
        return self::sendTo((string)self::configuracao()['to_hr'], $subject, $message);
    }

    public static function sendTo(string $to, string $subject, string $message): bool
    {
        return self::enviar($to, $subject, $message)['ok'];
    }

    /**
     * Envia e devolve o resultado detalhado e SEGURO.
     *
     * @return array{ok:bool,etapa:string,mensagem:string,codigo_smtp:?int}
     */
    public static function enviar(string $to, string $subject, string $message): array
    {
        $cfg = self::configuracao();
        if (!$cfg['enabled']) {
            return self::resultado(self::ETAPA_DESATIVADO);
        }
        $to = trim($to);
        if (!PHPMailer::validateAddress($to)) {
            return self::falha(self::ETAPA_DESTINATARIO, $cfg, $to, 'destinatário inválido');
        }
        $erroConfig = self::validarConfiguracao($cfg);
        if ($erroConfig !== null) {
            return self::falha(self::ETAPA_CONFIG, $cfg, $to, $erroConfig);
        }
        if (strtolower((string)$cfg['driver']) === 'mail') {
            return self::enviarPorMailNativo($cfg, $to, $subject, $message);
        }

        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = (string)$cfg['smtp_host'];
            $m->Port = (int)$cfg['smtp_port'];
            $m->SMTPAuth = true;
            $m->Username = (string)$cfg['smtp_user'];
            $m->Password = (string)$cfg['smtp_pass'];
            $m->SMTPSecure = self::modoSeguranca($cfg);
            $m->SMTPAutoTLS = false;
            $m->Timeout = max(3, min(60, (int)$cfg['smtp_timeout']));
            $m->SMTPDebug = 0;
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            // Certificado SEMPRE validado (padrão do PHP: verify_peer + verify_peer_name). Só se aceita uma CA ADICIONAL — nunca se desliga a verificação.
            $caFile = trim((string)$cfg['smtp_cafile']);
            if ($caFile !== '' && is_file($caFile)) {
                $m->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $caFile]];
            }
            $m->setFrom((string)$cfg['from'], (string)$cfg['from_name'], false);
            if (trim((string)$cfg['reply_to']) !== '' && PHPMailer::validateAddress((string)$cfg['reply_to'])) {
                $m->addReplyTo((string)$cfg['reply_to']);
            }
            $m->addAddress($to);
            $m->isHTML(false);
            $m->Subject = self::semQuebras($subject);
            $m->Body = $message;
            $m->send();
            return self::resultado(self::ETAPA_OK);
        } catch (PHPMailerException $e) {
            $smtp = $m->getSMTPInstance();
            $detalhe = '';
            $erroSmtp = $smtp->getError();
            if (is_array($erroSmtp)) {
                $detalhe = (string)($erroSmtp['error'] ?? '') . ' ' . (string)($erroSmtp['detail'] ?? '') . ' ' . (string)($erroSmtp['smtp_code'] ?? '');
            }
            $codigo = isset($erroSmtp['smtp_code']) && ctype_digit((string)$erroSmtp['smtp_code']) ? (int)$erroSmtp['smtp_code'] : null;
            return self::falha(self::classificar($e->getMessage(), $detalhe, $cfg), $cfg, $to, null, $codigo > 0 ? $codigo : null);
        } catch (\Throwable $e) {
            return self::falha(self::ETAPA_ENVIO, $cfg, $to, 'erro inesperado: ' . get_class($e));
        }
    }

    /** Mapeia a falha do PHPMailer/SMTP para uma ETAPA (sem expor o texto bruto, que pode conter respostas do servidor). */
    private static function classificar(string $mensagem, string $detalhe, array $cfg): string
    {
        $t = strtolower($mensagem . ' ' . $detalhe);
        if (str_contains($t, 'could not authenticate') || str_contains($t, 'authentication') && !str_contains($t, 'connect')) {
            return self::ETAPA_AUTENTICACAO;
        }
        if (str_contains($t, 'from address failed')) {
            return self::ETAPA_REMETENTE;
        }
        if (str_contains($t, 'recipients failed') || str_contains($t, 'invalid address')) {
            return self::ETAPA_DESTINATARIO;
        }
        if (preg_match('/ssl|tls|certificate|handshake|crypto|verify/', $t)) {
            return self::ETAPA_TLS;
        }
        if (str_contains($t, 'connect') || str_contains($t, 'could not resolve') || str_contains($t, 'timed out')) {
            // O PHPMailer não diferencia "porta fechada" de "TLS recusado" (certificado inválido). Sonda a porta em TCP puro: se ela ACEITA a
            // conexão e o SMTP falhou, o problema é a negociação TLS/validação do certificado — não a rede.
            $sonda = @stream_socket_client('tcp://' . (string)$cfg['smtp_host'] . ':' . (int)$cfg['smtp_port'], $en, $es, 3);
            if ($sonda !== false) {
                fclose($sonda);
                return self::ETAPA_TLS;
            }
            return self::ETAPA_CONEXAO;
        }
        return self::ETAPA_ENVIO;
    }

    private static function modoSeguranca(array $cfg): string
    {
        return in_array(strtolower((string)$cfg['smtp_secure']), ['tls', 'starttls'], true) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    }

    /** @return string|null descrição SEM segredos do que está inválido */
    private static function validarConfiguracao(array $cfg): ?string
    {
        if (strtolower((string)$cfg['driver']) === 'mail') {
            return PHPMailer::validateAddress((string)$cfg['from']) ? null : 'mail.from inválido';
        }
        if (strtolower((string)$cfg['driver']) !== 'smtp') {
            return 'mail.driver desconhecido';
        }
        if (!PHPMailer::validateAddress((string)$cfg['from'])) {
            return 'mail.from inválido';
        }
        if (trim((string)$cfg['smtp_host']) === '' || preg_match('/[\s\/]/', (string)$cfg['smtp_host'])) {
            return 'mail.smtp_host ausente ou inválido';
        }
        $secure = strtolower((string)$cfg['smtp_secure']);
        if (!in_array($secure, ['ssl', 'smtps', 'tls', 'starttls'], true)) {
            return 'mail.smtp_secure deve ser ssl (465) ou tls (587): conexão sem criptografia não é aceita';
        }
        $porta = (int)$cfg['smtp_port'];
        if ($porta < 1 || $porta > 65535) {
            return 'mail.smtp_port inválida';
        }
        if (($porta === 465 && in_array($secure, ['tls', 'starttls'], true)) || ($porta === 587 && in_array($secure, ['ssl', 'smtps'], true))) {
            return 'mail.smtp_port e mail.smtp_secure incompatíveis (465 = ssl, 587 = tls)';
        }
        if (trim((string)$cfg['smtp_user']) === '' || (string)$cfg['smtp_pass'] === '') {
            return 'mail.smtp_user/mail.smtp_pass ausentes';
        }
        return null;
    }

    private static function enviarPorMailNativo(array $cfg, string $to, string $subject, string $message): array
    {
        $headers = 'From: ' . self::semQuebras((string)$cfg['from']) . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
        $ok = @mail($to, self::semQuebras($subject), $message, $headers);
        return $ok ? self::resultado(self::ETAPA_OK) : self::falha(self::ETAPA_ENVIO, $cfg, $to, 'mail() recusou');
    }

    private static function semQuebras(string $v): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $v) ?? '');
    }

    /** @return array{ok:bool,etapa:string,mensagem:string,codigo_smtp:?int} */
    private static function resultado(string $etapa, ?int $codigoSmtp = null): array
    {
        return ['ok' => $etapa === self::ETAPA_OK, 'etapa' => $etapa, 'mensagem' => self::MENSAGENS[$etapa] ?? self::MENSAGENS[self::ETAPA_ENVIO], 'codigo_smtp' => $codigoSmtp];
    }

    /** Registra a falha de forma SEGURA (etapa, servidor, porta, domínio do destinatário, código SMTP) e devolve o resultado. */
    private static function falha(string $etapa, array $cfg, string $to, ?string $detalheSeguro = null, ?int $codigoSmtp = null): array
    {
        $contexto = [
            'etapa' => $etapa, 'driver' => (string)$cfg['driver'], 'host' => (string)$cfg['smtp_host'], 'porta' => (int)$cfg['smtp_port'],
            'seguranca' => (string)$cfg['smtp_secure'], 'destino_dominio' => self::dominio($to), 'codigo_smtp' => $codigoSmtp,
        ];
        if ($detalheSeguro !== null) {
            $contexto['detalhe'] = $detalheSeguro;
        }
        // Nunca: senha, usuário, corpo/assunto (podem conter token/link), destinatário completo nem o texto bruto do servidor.
        Logger::warning('Falha no envio de e-mail', $contexto);
        $r = self::resultado($etapa, $codigoSmtp);
        if ($etapa === self::ETAPA_CONFIG && $detalheSeguro !== null) {
            $r['mensagem'] .= ' (' . $detalheSeguro . ')';
        }
        return $r;
    }

    private static function dominio(string $email): string
    {
        $pos = strrpos($email, '@');
        return $pos === false ? '' : strtolower(substr($email, $pos + 1));
    }

    public static function notifyUserPasswordChanged(string $to, string $userName, int $adminId): bool
    {
        $cfg = Config::app();
        $subject = (string)($cfg['mail']['subject_password_changed'] ?? 'Senha alterada');
        $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
        $loginUrl = $baseUrl !== '' ? $baseUrl . '/login' : '/login';
        $timestamp = date('d/m/Y H:i:s');
        $message = "Olá {$userName},\n\nSua senha foi alterada por um administrador.\nData e hora da alteração: {$timestamp}\nID do administrador responsável: {$adminId}\n\nPara acessar o sistema, utilize o link: {$loginUrl}\nCaso não reconheça esta ação, entre em contato imediatamente com o suporte de TI.\n";
        return self::sendTo($to, $subject, $message);
    }
}
