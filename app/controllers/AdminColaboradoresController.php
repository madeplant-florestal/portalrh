<?php
class AdminColaboradoresController extends Controller
{
    public function index(): void
    {
        // Restrito a admin/RH: a listagem expõe salário individual dos colaboradores. `viewer`
        // (usado a partir da sprint de Vagas para o acesso de líderes) NÃO deve ver isto.
        Auth::requireRole(['admin', 'rh']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = ctype_digit((string)($_GET['per_page'] ?? '')) ? (int)$_GET['per_page'] : 20;

        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'cargo_id' => ctype_digit((string)($_GET['cargo_id'] ?? '')) ? (int)$_GET['cargo_id'] : null,
            'empresa_id' => ctype_digit((string)($_GET['empresa_id'] ?? '')) ? (int)$_GET['empresa_id'] : null,
            'setor_id' => ctype_digit((string)($_GET['setor_id'] ?? '')) ? (int)$_GET['setor_id'] : null,
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];
        $result = Colaborador::paginateAdmin($filters, $page, $perPage);

        $this->view->render('admin/colaboradores/index', [
            'colaboradores' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'perPage' => $result['per_page'],
            'filters' => $filters,
            'summary' => Colaborador::summary(),
            'cargoOptions' => Colaborador::cargoOptions(),
            'empresaOptions' => Colaborador::empresaOptions(),
            'setorOptions' => Colaborador::setorOptions(),
            'csrf' => Security::csrfToken(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    public function import(): void
    {
        Auth::requireRole(['admin', 'rh']);
        header('Content-Type: application/json; charset=UTF-8');

        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            $this->jsonResponse(['ok' => false, 'error' => 'Falha na verificacao de seguranca (CSRF).'], 400);
            return;
        }

        $upload = $_FILES['import_file'] ?? null;
        if (!is_array($upload)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Nenhum arquivo foi enviado para importacao.'], 400);
            return;
        }

        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->jsonResponse(['ok' => false, 'error' => $this->uploadErrorMessage($uploadError)], 400);
            return;
        }

        $originalName = Security::sanitizeString((string)($upload['name'] ?? ''));
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Formato invalido. Utilize um arquivo .xlsx ou .csv.'], 422);
            return;
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Arquivo temporario de importacao nao encontrado.'], 400);
            return;
        }

        try {
            $service = new CollaboratorSpreadsheetImportService();
            $report = $service->import($tmpName, [
                'sheet_name' => $extension === 'xlsx' ? 'Ativos x Desligados' : 'CSV',
                'dry_run' => false,
                'validate_only' => false,
            ]);

            $summary = $report['summary'] ?? [];
            $payload = [
                'ok' => (bool)($report['ok'] ?? false),
                'message' => ($report['ok'] ?? false)
                    ? sprintf(
                        'Importacao concluida com sucesso. %d inseridos, %d atualizados e %d rejeitados.',
                        (int)($summary['inserted'] ?? 0),
                        (int)($summary['updated'] ?? 0),
                        (int)($summary['rejected'] ?? 0)
                    )
                    : ((($report['errors'][0] ?? '') !== '') ? (string)$report['errors'][0] : 'Nao foi possivel concluir a importacao.'),
                'summary' => $summary,
                'warnings' => array_values(array_slice((array)($report['warnings'] ?? []), 0, 10)),
                'errors' => array_values(array_slice((array)($report['errors'] ?? []), 0, 10)),
                'rejected_records' => array_values(array_slice((array)($report['rejected_records'] ?? []), 0, 10)),
                'report_path' => $report['report_path'] ?? null,
                'file_type' => $report['file_type'] ?? $extension,
                'file_name' => $originalName,
            ];

            $this->jsonResponse($payload, ($report['ok'] ?? false) ? 200 : 422);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', [
                'controller' => __CLASS__,
                'action' => __FUNCTION__,
                'file_name' => $originalName,
            ]);

            $this->jsonResponse([
                'ok' => false,
                'error' => 'Erro interno ao processar a importacao de colaboradores.',
            ], 500);
        }
    }

    public function editRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $colaborador = Colaborador::find((int)$id);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $this->view->render('admin/colaboradores/rh-form', [
            'csrf' => Security::csrfToken(),
            'colaborador' => $colaborador,
            'error' => '',
        ], 'layouts/admin');
    }

    public function updateRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $colaborador = Colaborador::find((int)$id);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $payload = [
            'matricula' => Security::sanitizeString($_POST['matricula'] ?? ''),
            'codigo' => Security::sanitizeString($_POST['codigo'] ?? ''),
            'cpf' => Security::sanitizeString($_POST['cpf'] ?? ''),
            'salario_atual' => Security::sanitizeString($_POST['salario_atual'] ?? ''),
            'data_admissao' => Security::sanitizeString($_POST['data_admissao'] ?? ''),
            'data_inicio_cargo' => Security::sanitizeString($_POST['data_inicio_cargo'] ?? ''),
            'data_nascimento' => Security::sanitizeString($_POST['data_nascimento'] ?? ''),
            'data_demissao' => Security::sanitizeString($_POST['data_demissao'] ?? ''),
            'motivo_rescisao' => Security::sanitizeString($_POST['motivo_rescisao'] ?? ''),
        ];
        $result = Colaborador::updateRhData((int)$id, $payload);
        if (!($result['ok'] ?? false)) {
            $colaborador = array_merge($colaborador, $payload);
            $this->view->render('admin/colaboradores/rh-form', [
                'csrf' => Security::csrfToken(),
                'colaborador' => $colaborador,
                'error' => $result['error'] ?? 'Falha ao atualizar os dados de RH do colaborador.',
            ], 'layouts/admin');
            return;
        }

        redirect('/admin/colaboradores?ok=' . urlencode('Dados de RH do colaborador atualizados com sucesso.'));
    }

    /**
     * Tela de Acesso/Liderança de um colaborador oficial: vincula (ou cria) o usuário de login,
     * define os papéis do Portal (é líder / pode solicitar vaga / RH / líder imediato) e o estado
     * de acesso. Nada aqui é inferido do METADADOS.
     */
    public function acesso(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $colaborador = Colaborador::find((int)$id);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $repo = new UsuarioColaboradorRepository();
        $vinculo = $repo->findByColaboradorId((int)$id);
        $usuario = $vinculo ? User::findById((int)$vinculo['usuario_id']) : null;

        $senhaTemp = $_SESSION['flash_acesso_senha_temp'] ?? null;
        unset($_SESSION['flash_acesso_senha_temp']);

        $this->view->render('admin/colaboradores/acesso', [
            'csrf' => Security::csrfToken(),
            'colaborador' => $colaborador,
            'vinculo' => $vinculo,
            'usuario' => $usuario,
            'lideres' => $repo->colaboradoresComAcessoAtivo(),
            'isAdmin' => strtolower(trim((string)(Auth::role() ?? ''))) === 'admin' || !empty($_SESSION['user_is_supervisor']),
            'senhaTemp' => is_string($senhaTemp) ? $senhaTemp : null,
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    public function updateAcesso(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }
        $colaboradorId = (int)$id;
        $colaborador = Colaborador::find($colaboradorId);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $isAdmin = strtolower(trim((string)(Auth::role() ?? ''))) === 'admin' || !empty($_SESSION['user_is_supervisor']);
        $acao = Security::sanitizeString($_POST['acao'] ?? '');
        $repo = new UsuarioColaboradorRepository();
        $vinculo = $repo->findByColaboradorId($colaboradorId);
        $ip = Security::clientIp();
        $back = '/admin/colaboradores/' . $colaboradorId . '/acesso';

        $flagsFromPost = static function (array $post): array {
            $lider = ctype_digit((string)($post['lider_colaborador_id'] ?? '')) ? (int)$post['lider_colaborador_id'] : null;
            return [
                'is_gestor' => !empty($post['is_gestor']) ? 1 : 0,
                'is_rh' => !empty($post['is_rh']) ? 1 : 0,
                'pode_solicitar_vaga' => !empty($post['pode_solicitar_vaga']) ? 1 : 0,
                'lider_colaborador_id' => $lider,
            ];
        };

        try {
            if ($acao === 'criar_acesso' || $acao === 'vincular_existente') {
                if (!$isAdmin) {
                    http_response_code(403);
                    echo 'Apenas administradores podem criar ou vincular acessos.';
                    return;
                }
                $email = Security::sanitizeString($_POST['email'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    redirect($back . '?erro=' . urlencode('Informe um e-mail válido para o acesso.'));
                }
                $supervisorEmail = (string)(Config::app()['security']['supervisor_email'] ?? '');
                if ($supervisorEmail !== '' && strcasecmp($email, $supervisorEmail) === 0) {
                    redirect($back . '?erro=' . urlencode('Este e-mail é reservado ao usuário Supervisor.'));
                }

                $existing = User::findByEmail($email);
                if ($acao === 'vincular_existente') {
                    if (!$existing) {
                        redirect($back . '?erro=' . urlencode('Nenhum usuário encontrado com esse e-mail.'));
                    }
                    $repo->vincular($colaboradorId, (int)$existing->id);
                    $repo->atualizarPapeis($colaboradorId, $flagsFromPost($_POST));
                    redirect($back . '?ok=' . urlencode('Usuário existente vinculado ao colaborador.'));
                }

                // criar_acesso
                if ($existing) {
                    redirect($back . '?erro=' . urlencode('Já existe um usuário com esse e-mail. Use "Vincular usuário existente".'));
                }
                $senhaTemp = self::senhaTemporaria();
                $usuarioId = User::create((string)$colaborador['nome'], $email, password_hash($senhaTemp, PASSWORD_BCRYPT), 'viewer');
                User::setActiveStatus($usuarioId, true);
                $repo->vincular($colaboradorId, $usuarioId);
                $repo->atualizarPapeis($colaboradorId, $flagsFromPost($_POST));
                $_SESSION['flash_acesso_senha_temp'] = $senhaTemp;
                Logger::info('Acesso de líder provisionado', ['colaborador_id' => $colaboradorId, 'usuario_id' => $usuarioId, 'ator' => (int)($_SESSION['user_id'] ?? 0)]);
                redirect($back . '?ok=' . urlencode('Acesso criado. Copie a senha temporária exibida e entregue ao líder.'));
            }

            if (!$vinculo) {
                redirect($back . '?erro=' . urlencode('Este colaborador ainda não tem acesso. Crie ou vincule um usuário primeiro.'));
            }

            if ($acao === 'atualizar') {
                $repo->atualizarPapeis($colaboradorId, $flagsFromPost($_POST));
                redirect($back . '?ok=' . urlencode('Permissões atualizadas.'));
            }

            if ($acao === 'reativar') {
                $repo->atualizarPapeis($colaboradorId, ['ativo' => 1]);
                redirect($back . '?ok=' . urlencode('Acesso reativado.'));
            }

            if ($acao === 'desativar_acesso') {
                $repo->atualizarPapeis($colaboradorId, ['ativo' => 0]);
                redirect($back . '?ok=' . urlencode('Acesso desativado. O vínculo e o histórico são preservados.'));
            }

            if ($acao === 'redefinir_senha') {
                if (!$isAdmin) {
                    http_response_code(403);
                    echo 'Apenas administradores podem redefinir senhas.';
                    return;
                }
                $senhaTemp = self::senhaTemporaria();
                User::definirSenhaHash((int)$vinculo['usuario_id'], password_hash($senhaTemp, PASSWORD_BCRYPT));
                User::setActiveStatus((int)$vinculo['usuario_id'], true);
                $_SESSION['flash_acesso_senha_temp'] = $senhaTemp;
                Logger::info('Senha de acesso de líder redefinida', ['colaborador_id' => $colaboradorId, 'usuario_id' => (int)$vinculo['usuario_id'], 'ator' => (int)($_SESSION['user_id'] ?? 0)]);
                redirect($back . '?ok=' . urlencode('Nova senha temporária gerada. Copie e entregue ao líder.'));
            }

            redirect($back . '?erro=' . urlencode('Ação não reconhecida.'));
        } catch (\RuntimeException $e) {
            redirect($back . '?erro=' . urlencode($e->getMessage()));
        } catch (\Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => __CLASS__, 'action' => 'updateAcesso', 'colaborador_id' => $colaboradorId]);
            redirect($back . '?erro=' . urlencode('Falha ao atualizar o acesso do colaborador.'));
        }
    }

    /** Senha temporária forte, compatível com PasswordPolicy (>=12, maiúscula, minúscula, dígito, especial). */
    private static function senhaTemporaria(): string
    {
        $maiusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minusculas = 'abcdefghijkmnpqrstuvwxyz';
        $digitos = '23456789';
        $especiais = '!@#$%&*?';
        $todos = $maiusculas . $minusculas . $digitos . $especiais;
        $senha = $maiusculas[random_int(0, strlen($maiusculas) - 1)]
            . $minusculas[random_int(0, strlen($minusculas) - 1)]
            . $digitos[random_int(0, strlen($digitos) - 1)]
            . $especiais[random_int(0, strlen($especiais) - 1)];
        for ($i = 0; $i < 12; $i++) {
            $senha .= $todos[random_int(0, strlen($todos) - 1)];
        }
        return str_shuffle($senha);
    }

    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho maximo permitido para upload.',
            UPLOAD_ERR_PARTIAL => 'O upload foi interrompido antes da conclusao.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi selecionado para importacao.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretorio temporario de upload indisponivel.',
            UPLOAD_ERR_CANT_WRITE => 'Nao foi possivel gravar o arquivo enviado no servidor.',
            UPLOAD_ERR_EXTENSION => 'O upload foi bloqueado por uma extensao do servidor.',
            default => 'Falha ao receber o arquivo de importacao.',
        };
    }
}
