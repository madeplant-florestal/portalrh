<?php
class Mailer
{
    public static function notifyHR(string $subject, string $message): bool
    {
        $cfg = Config::app()['mail'];
        return self::sendTo($cfg['to_hr'], $subject, $message);
    }

    public static function sendTo(string $to, string $subject, string $message): bool
    {
        $cfg = Config::app()['mail'];
        if (!$cfg['enabled']) {
            return false;
        }
        $headers = 'From: ' . $cfg['from'] . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
        return @mail($to, $subject, $message, $headers);
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
