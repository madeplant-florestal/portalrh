<?php
class AdminSupervisorController extends Controller
{
    private const TESTE_EMAIL_MAX_POR_HORA = 5;

    /** Exige sessão de admin E Supervisor Geral (lido do banco). Devolve o ator ou null (resposta 403 já enviada). */
    private function exigirSupervisorGeral(): ?User
    {
        Auth::requireRole(['admin']);
        $ator = Autoridade::atorDaSessao();
        if (!Autoridade::ehSupervisorGeral($ator)) {
            http_response_code(403);
            echo 'Acesso negado';
            return null;
        }
        return $ator;
    }

    /** Configuração de e-mail SEM segredos (a senha nunca sai daqui). */
    private function configEmailPublica(): array
    {
        $c = Mailer::configuracao();
        $u = (string)$c['smtp_user'];
        $mascarado = $u === '' ? '(vazio)' : (str_contains($u, '@') ? substr($u, 0, 2) . '***@' . substr(strrchr($u, '@'), 1) : substr($u, 0, 2) . '***');
        return [
            'enabled' => (bool)$c['enabled'], 'driver' => (string)$c['driver'], 'from' => (string)$c['from'], 'from_name' => (string)$c['from_name'],
            'smtp_host' => (string)$c['smtp_host'], 'smtp_port' => (int)$c['smtp_port'], 'smtp_secure' => (string)$c['smtp_secure'],
            'usuario_mascarado' => $mascarado, 'senha_configurada' => (string)$c['smtp_pass'] !== '',
        ];
    }

    private function renderEmailDiagnostico(User $ator, ?array $resultado, string $erro = ''): void
    {
        $this->view->render('admin/supervisor/email_diagnostico', [
            'config' => $this->configEmailPublica(), 'resultado' => $resultado, 'erro' => $erro,
            'csrf' => Security::csrfToken(), 'destinatarioPadrao' => (string)$ator->email,
        ], 'layouts/app-shell');
    }

    /** Página do diagnóstico de e-mail (somente leitura da configuração). */
    public function emailDiagnostico(): void
    {
        $ator = $this->exigirSupervisorGeral();
        if ($ator === null) {
            return;
        }
        $this->renderEmailDiagnostico($ator, null);
    }

    /**
     * Envia UMA mensagem de teste (texto fixo) a UM destinatário. Anti-abuso: CSRF, só Supervisor Geral, 1 destinatário por requisição, 5/hora, auditado.
     * Informa se o SMTP ACEITOU a mensagem — nunca afirma entrega.
     */
    public function emailTestar(): void
    {
        $ator = $this->exigirSupervisorGeral();
        if ($ator === null) {
            return;
        }
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            AuditLog::log($ator->id, null, 'blocked_email_test', 'Teste de e-mail bloqueado: CSRF inválido', Security::clientIp());
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $destino = trim((string)($_POST['destinatario'] ?? ''));
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL) || strlen($destino) > 190 || str_contains($destino, ',') || str_contains($destino, ';')) {
            $this->renderEmailDiagnostico($ator, null, 'Informe UM e-mail válido.');
            return;
        }
        if (!Security::rateLimitConsume('smtp_teste_sg', (string)$ator->id, self::TESTE_EMAIL_MAX_POR_HORA, 3600)) {
            AuditLog::log($ator->id, null, 'blocked_email_test', 'Teste de e-mail bloqueado: limite por hora', Security::clientIp());
            $this->renderEmailDiagnostico($ator, null, 'Limite de ' . self::TESTE_EMAIL_MAX_POR_HORA . ' testes por hora atingido. Tente mais tarde.');
            return;
        }
        $resultado = Mailer::enviar($destino, 'Teste de envio — Portal RH Madeplant', "Esta é uma mensagem de teste enviada pelo diagnóstico do Portal RH Madeplant.\nSe você a recebeu, o envio de e-mail está funcionando.\n\nNenhuma ação é necessária.");
        AuditLog::log($ator->id, null, $resultado['ok'] ? 'email_test_accepted' : 'email_test_failed', 'Teste de e-mail: ' . $resultado['etapa'] . ' (destino em ' . substr((string)strrchr($destino, '@'), 1) . ')', Security::clientIp());
        $this->renderEmailDiagnostico($ator, $resultado);
    }

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
