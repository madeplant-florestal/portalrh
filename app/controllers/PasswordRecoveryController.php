<?php
class PasswordRecoveryController extends Controller
{
    public function requestForm(): void
    {
        $this->view->render('admin/password_recovery_request', [
            'csrf' => Security::csrfToken(),
            'isLoginPage' => true,
        ], 'layouts/main');
    }

    public function sendToken(): void
    {
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $email = Security::sanitizeString($_POST['email'] ?? '');

        // Limite de solicitações (anti-abuso do formulário como disparador de e-mail): 3 por CONTA e 10 por IP em 15 minutos. Toda solicitação CONTA —
        // inclusive e-mail inexistente — e a resposta é SEMPRE a mesma: o limite não vira um oráculo de "esta conta existe".
        $ip = Security::clientIp();
        $contaOk = $email === '' || Security::rateLimitConsume('pwd_reset_conta', strtolower($email), 3, 900);
        $ipOk = Security::rateLimitConsume('pwd_reset_ip', $ip, 10, 900);
        $permitido = $contaOk && $ipOk;
        if (!$permitido) {
            Logger::warning('Recuperação de senha: limite de solicitações atingido', ['ip' => $ip, 'limite' => !$ipOk ? 'ip' : 'conta']); // sem e-mail
        }
        $user = ($email && $permitido) ? User::findByEmail($email) : null;

        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            PasswordReset::create($user->id, $rawToken, 30);
            $base = Config::app()['base_url'] ?? '';
            $link = $base . '/admin/reset-password/' . urlencode($rawToken);
            $subject = Config::app()['mail']['subject_password_recovery'] ?? 'Recuperação de senha';
            $message = "Olá {$user->nome},\n\nRecebemos uma solicitação para redefinição de senha.\nAcesse o link abaixo (válido por 30 minutos):\n{$link}\n\nSe você não solicitou, ignore este e-mail.";
            $envio = Mailer::enviar($user->email, $subject, $message);
            if (!$envio['ok']) {
                // A falha já foi registrada pelo Mailer (etapa/servidor, sem senha, corpo, token nem link). Aqui só o contexto; a tela NÃO muda.
                Logger::warning('Recuperação de senha: e-mail não enviado', ['usuario_id' => (int)$user->id, 'etapa' => $envio['etapa']]);
            }
        }

        $this->view->render('admin/password_recovery_request', [
            'csrf' => Security::csrfToken(),
            'isLoginPage' => true,
            'success' => 'Se o e-mail existir, um link de redefinição foi enviado.',
        ], 'layouts/main');
    }

    public function resetForm(string $token): void
    {
        SchemaManager::ensure();
        $row = PasswordReset::findValidByToken($token);
        if (!$row) {
            $this->view->render('admin/password_recovery_reset', [
                'isLoginPage' => true,
                'error' => 'Token inválido ou expirado.',
                'token' => '',
                'csrf' => Security::csrfToken(),
            ], 'layouts/main');
            return;
        }

        $this->view->render('admin/password_recovery_reset', [
            'isLoginPage' => true,
            'token' => $token,
            'csrf' => Security::csrfToken(),
        ], 'layouts/main');
    }

    public function performReset(string $token): void
    {
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $row = PasswordReset::findValidByToken($token);
        if (!$row) {
            $this->view->render('admin/password_recovery_reset', [
                'isLoginPage' => true,
                'error' => 'Token inválido ou expirado.',
                'token' => '',
                'csrf' => Security::csrfToken(),
            ], 'layouts/main');
            return;
        }

        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if ($password !== $passwordConfirm) {
            $this->view->render('admin/password_recovery_reset', [
                'isLoginPage' => true,
                'error' => 'As senhas não conferem.',
                'token' => $token,
                'csrf' => Security::csrfToken(),
            ], 'layouts/main');
            return;
        }

        $check = PasswordPolicy::validate($password);
        if (!$check['valid']) {
            $this->view->render('admin/password_recovery_reset', [
                'isLoginPage' => true,
                'error' => implode(' ', $check['errors']),
                'token' => $token,
                'csrf' => Security::csrfToken(),
            ], 'layouts/main');
            return;
        }

        $user = User::findById((int)$row['usuario_id']);
        if (!$user) {
            $this->view->render('admin/password_recovery_reset', [
                'isLoginPage' => true,
                'error' => 'Usuário não encontrado.',
                'token' => '',
                'csrf' => Security::csrfToken(),
            ], 'layouts/main');
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        User::updatePassword($user->id, $hash);
        PasswordReset::markUsed((int)$row['id']);

        $subject = Config::app()['mail']['subject_password_changed'] ?? 'Senha redefinida';
        $message = "Olá {$user->nome},\n\nSua senha foi redefinida com sucesso.\nSe você não reconhece esta ação, entre em contato com o suporte imediatamente.";
        Mailer::sendTo($user->email, $subject, $message);

        $this->view->render('admin/login', [
            'isLoginPage' => true,
            'csrf' => Security::csrfToken(),
            'success' => 'Senha redefinida com sucesso. Faça login com a nova senha.',
        ], 'layouts/main');
    }
}
