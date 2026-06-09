<?php
class AdminSupervisorController extends Controller
{
    public function ensure(): void
    {
        Auth::requireRole(['admin']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $cfg = Config::app();
        $supervisorEmail = $cfg['security']['supervisor_email'] ?? '';
        $supervisorPassword = $cfg['security']['supervisor_password'] ?? '';
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));

        if ($supervisorEmail === '' || $supervisorPassword === '') {
            http_response_code(500);
            echo 'Configuração de supervisor inválida.';
            return;
        }

        $id = User::ensureSupervisor('Supervisor', $supervisorEmail, $supervisorPassword);
        AuditLog::log($actor?->id, $id, 'supervisor_ensured', 'Usuário supervisor garantido', Security::clientIp());

        $subject = $cfg['mail']['subject_supervisor_created'] ?? 'Usuário Supervisor criado';
        $message = "O usuário Supervisor foi criado/atualizado com privilégios irrestritos.\nE-mail: {$supervisorEmail}";
        Mailer::sendTo($supervisorEmail, $subject, $message);
        Mailer::notifyHR($subject, $message);

        redirect('/admin/usuarios/novo?supervisor=ok');
    }
}
