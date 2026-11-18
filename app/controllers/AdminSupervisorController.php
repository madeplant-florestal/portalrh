<?php
class AdminSupervisorController extends Controller
{
    public function ensure(): void
    {
        Auth::requireRole(['admin']);
        SchemaManager::ensure();
        // Etapa 2A-bis: somente um Supervisor Geral (admin + flag, lido do BANCO) garante o supervisor. Um administrador comum não
        // pode usar esta rota para mexer na conta do Supervisor Geral.
        $ator = Autoridade::atorDaSessao();
        if (!Autoridade::ehSupervisorGeral($ator)) {
            AuditLog::log($ator?->id, null, 'blocked_supervisor_ensured', 'Tentativa bloqueada de garantir o Supervisor Geral', Security::clientIp());
            http_response_code(403);
            echo 'Acesso negado';
            return;
        }
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
        $message = "O usuário Supervisor foi criado/restaurado (a senha de uma conta existente NÃO é alterada).\nE-mail: {$supervisorEmail}";
        Mailer::sendTo($supervisorEmail, $subject, $message);
        Mailer::notifyHR($subject, $message);

        redirect('/admin/usuarios/novo?supervisor=ok');
    }
}
