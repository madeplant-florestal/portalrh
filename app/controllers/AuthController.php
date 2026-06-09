<?php
class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_WINDOW_SECONDS = 600;
    private const LOGIN_LOCKOUT_SECONDS = 900;

    private function postLoginPath(): string
    {
        if (class_exists('AdminController') && method_exists('AdminController', 'index')) {
            return '/admin';
        }
        return '/login';
    }

    public function login(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf = $_SESSION['csrf_token'];
        $expired = isset($_GET['expired']) && $_GET['expired'] === '1';
        $error = $expired ? 'Sessão expirada por inatividade de 20 minutos. Faça login novamente.' : null;
        $this->view->render('admin/login', ['csrf' => $csrf, 'error' => $error, 'isLoginPage' => true], 'layouts/main');
    }

    public function doLogin(): void
    {
        $ip = Security::clientIp();
        if (
            !isset($_POST['csrf_token']) ||
            !isset($_SESSION['csrf_token']) ||
            $_POST['csrf_token'] !== $_SESSION['csrf_token']
        ) {
            Logger::warning('Falha de autenticação por CSRF inválido', [
                'email' => Security::sanitizeString($_POST['email'] ?? ''),
                'ip' => $ip
            ]);
            $this->view->render('admin/login', [
                'error' => 'Falha na verificação de segurança. Atualize a página e tente novamente.',
                'csrf' => Security::csrfToken(),
                'isLoginPage' => true
            ]);
            return;
        }
        $email = Security::sanitizeString($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (!$email || !$pass) {
            $this->view->render('admin/login', ['error' => 'Informe e-mail e senha', 'csrf' => Security::csrfToken(), 'isLoginPage' => true]);
            return;
        }

        $scope = 'login';
        $key = $ip . '|' . strtolower($email);
        $rl = Security::rateLimitCheck($scope, $key, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW_SECONDS, self::LOGIN_LOCKOUT_SECONDS);
        if ($rl['blocked']) {
            $wait = (int)$rl['retry_after'];
            $msg = $wait > 0
                ? 'Muitas tentativas para este usuário/IP. Tente novamente em ' . $wait . 's.'
                : 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
            Logger::warning('Login bloqueado por rate limit', [
                'email' => strtolower($email),
                'ip' => $ip,
                'retry_after' => $wait,
                'attempts_count' => (int)($rl['attempts_count'] ?? 0)
            ]);
            $this->view->render('admin/login', ['error' => $msg, 'csrf' => Security::csrfToken(), 'isLoginPage' => true]);
            return;
        }

        try {
            $attempt = Auth::attemptLogin($email, $pass);
            if ($attempt['ok']) {
                Security::rateLimitHit(
                    $rl['file'],
                    $rl['data'],
                    true,
                    self::LOGIN_LOCKOUT_SECONDS,
                    self::LOGIN_MAX_ATTEMPTS,
                    self::LOGIN_WINDOW_SECONDS
                );
                Logger::info('Login realizado com sucesso', [
                    'email' => strtolower($email),
                    'ip' => $ip,
                    'user_id' => $attempt['user']->id ?? null,
                    'role' => $attempt['user']->role ?? null
                ]);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                redirect($this->postLoginPath());
            }

            $rateState = Security::rateLimitHit(
                $rl['file'],
                $rl['data'],
                false,
                self::LOGIN_LOCKOUT_SECONDS,
                self::LOGIN_MAX_ATTEMPTS,
                self::LOGIN_WINDOW_SECONDS
            );
            $error = (string)($attempt['message'] ?? 'Não foi possível autenticar.');
            if ($rateState['blocked']) {
                $error .= ' Acesso temporariamente bloqueado por excesso de tentativas. Tente novamente em ' . (int)$rateState['retry_after'] . 's.';
            } elseif (($rateState['remaining_attempts'] ?? 0) > 0) {
                $error .= ' Restam ' . (int)$rateState['remaining_attempts'] . ' tentativa(s) antes do bloqueio temporário.';
            }
            Logger::warning('Falha de autenticação', [
                'email' => strtolower($email),
                'ip' => $ip,
                'reason' => $attempt['reason'] ?? 'unknown',
                'user_id' => $attempt['user']->id ?? null,
                'attempts_count' => (int)($rateState['attempts_count'] ?? 0),
                'remaining_attempts' => (int)($rateState['remaining_attempts'] ?? 0),
                'blocked' => (bool)($rateState['blocked'] ?? false)
            ]);
            $this->view->render('admin/login', ['error' => $error, 'csrf' => Security::csrfToken(), 'isLoginPage' => true]);
        } catch (\Throwable $e) {
            Logger::exception($e, 'ERROR', Logger::captureContext(500, [
                'auth' => [
                    'email' => strtolower($email),
                    'ip' => $ip
                ]
            ]));
            $this->view->render('admin/login', ['error' => 'Não foi possível autenticar agora. Verifique a configuração do banco e tente novamente.', 'csrf' => Security::csrfToken(), 'isLoginPage' => true]);
        }
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
