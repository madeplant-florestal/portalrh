<?php
class AdminSupervisorController extends Controller
{
    /**
     * Diagnóstico SOMENTE LEITURA da sessão como o servidor WEB a vê (php-fpm/Apache, não o CLI): política, diretivas efetivas do PHP e a idade dos arquivos
     * de sessão. Existe para PROVAR na hospedagem que o prazo configurado é cumprido (ver docs/claude/riscos-conhecidos.md). Só Supervisor Geral; não
     * expõe ID de sessão, e-mail nem conteúdo de sessão.
     */
    public function sessao(): void
    {
        Auth::requireRole(['admin']);
        if (!Autoridade::ehSupervisorGeral(Autoridade::atorDaSessao())) {
            http_response_code(403);
            echo 'Acesso negado';
            return;
        }
        $dir = (string)session_save_path();
        $arquivos = 0;
        $maisAntigo = null;
        $agora = time();
        if ($dir !== '' && is_dir($dir)) {
            foreach (new DirectoryIterator($dir) as $f) {
                if ($f->isFile() && str_starts_with($f->getFilename(), 'sess_')) {
                    $arquivos++;
                    $idade = $agora - $f->getMTime();
                    $maisAntigo = $maisAntigo === null ? $idade : max($maisAntigo, $idade);
                    if ($arquivos >= 5000) {
                        break;
                    }
                }
            }
        }
        $cookie = session_get_cookie_params();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'politica' => ['ociosidade_s' => SessionPolicy::ociosidade(), 'limite_total_s' => SessionPolicy::limiteTotal(), 'gc_maxlifetime_esperado_s' => SessionPolicy::gcMaxLifetime()],
            'php' => ['versao' => PHP_VERSION, 'sapi' => PHP_SAPI, 'session.gc_maxlifetime' => (int)ini_get('session.gc_maxlifetime'), 'session.gc_probability' => (int)ini_get('session.gc_probability'), 'session.gc_divisor' => (int)ini_get('session.gc_divisor'), 'session.cookie_lifetime' => (int)ini_get('session.cookie_lifetime'), 'session.use_strict_mode' => (int)ini_get('session.use_strict_mode'), 'session.save_handler' => (string)ini_get('session.save_handler')],
            'aplicado_pela_aplicacao' => Security::$statusIni,
            'prazo_garantido' => (int)ini_get('session.gc_maxlifetime') >= SessionPolicy::gcMaxLifetime() && (int)ini_get('session.gc_probability') >= 0,
            'cookie' => ['lifetime' => $cookie['lifetime'], 'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite'] ?? ''],
            'armazenamento' => ['pasta_da_aplicacao' => $dir !== '' && str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $dir), 'storage/sessions'), 'arquivos_de_sessao' => $arquivos, 'idade_maxima_arquivo_s' => $maisAntigo],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

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
