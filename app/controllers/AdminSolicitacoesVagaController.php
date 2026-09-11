<?php
class AdminSolicitacoesVagaController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $this->view->render('admin/solicitacoes_vaga/index', [
            'items' => SolicitacaoVaga::allForUser(
                (int)($_SESSION['user_id'] ?? 0),
                Auth::role(),
                !empty($_SESSION['user_is_supervisor'])
            ),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        if (!$this->canCreate($userId, $role, $isSupervisor)) {
            http_response_code(403);
            echo 'Seu usuário não está autorizado a registrar solicitações de vaga. Peça a um administrador para habilitar "Pode solicitar vaga" no seu cadastro de usuário.';
            return;
        }

        // Aviso não-bloqueante: usuário comum autorizado, mas ainda sem aprovador (líder imediato)
        // configurado. O envio será rejeitado no backend — antecipamos a mensagem na tela.
        $aviso = '';
        if (!SolicitacaoVaga::userCanEditRh($role, $isSupervisor)) {
            $access = SolicitacaoVaga::userAccessProfilePublic($userId);
            if (is_array($access) && ($access['aprovador_usuario_id'] ?? null) === null) {
                $aviso = 'Seu usuário está autorizado a solicitar vagas, mas ainda não possui um aprovador (líder imediato) configurado. Procure o RH ou o administrador do Portal antes de enviar.';
            }
        }

        $this->view->render('admin/solicitacoes_vaga/form', [
            'mode' => 'create',
            'csrf' => Security::csrfToken(),
            'form' => $this->defaultFormValues(),
            'record' => null,
            'dependencies' => SolicitacaoVaga::formDependencies($userId, $userId),
            'error' => '',
            'aviso' => $aviso,
            'canEditRh' => SolicitacaoVaga::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        if (!$this->canCreate($userId, $role, $isSupervisor)) {
            http_response_code(403);
            echo 'Seu usuário não está autorizado a registrar solicitações de vaga. Peça a um administrador para habilitar "Pode solicitar vaga" no seu cadastro de usuário.';
            return;
        }

        try {
            $id = SolicitacaoVaga::create($_POST, $userId, Security::clientIp());
            redirect('/admin/solicitacoes-vaga/' . $id . '?ok=' . urlencode('Solicitação de vaga enviada para aprovação.'));
        } catch (Throwable $e) {
            $solicitanteTentado = ctype_digit((string)($_POST['solicitante_usuario_id'] ?? '')) ? (int)$_POST['solicitante_usuario_id'] : $userId;
            $this->view->render('admin/solicitacoes_vaga/form', [
                'mode' => 'create',
                'csrf' => Security::csrfToken(),
                'form' => $this->sanitizeFormInput($_POST),
                'record' => null,
                'dependencies' => SolicitacaoVaga::formDependencies($userId, $solicitanteTentado),
                'error' => $e->getMessage(),
                'canEditRh' => SolicitacaoVaga::userCanEditRh($role, $isSupervisor),
                'currentRole' => $role,
                'currentUserId' => $userId,
            ], 'layouts/admin');
        }
    }

    /**
     * Contexto organizacional (Cargo/Setores/Centros de Custo) do Usuário solicitante escolhido —
     * usado via AJAX quando Admin/RH troca o "Solicitante" no formulário de criação. Usuário comum
     * não troca solicitante, então não precisa deste endpoint (seu contexto já vem embutido).
     */
    public function solicitanteContexto(string $usuarioId): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        header('Content-Type: application/json; charset=UTF-8');

        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        if (!SolicitacaoVaga::userCanEditRh($role, $isSupervisor)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sem permissão para consultar contexto de outro usuário.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $contexto = SolicitacaoVaga::contextoOrganizacionalSolicitante((int)$usuarioId);
        echo json_encode(['ok' => true, 'contexto' => $contexto], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        $record = SolicitacaoVaga::findAccessible((int)$id, $userId, $role, $isSupervisor);
        if (!$record) {
            http_response_code(404);
            echo 'Solicitação de vaga não encontrada.';
            return;
        }

        $vagaVinculada = null;
        $stmtVaga = Database::conn()->prepare('SELECT id, ativo, publicada_em FROM vagas WHERE solicitacao_vaga_id = ? LIMIT 1');
        $stmtVaga->execute([(int)$id]);
        $vagaVinculada = $stmtVaga->fetch(PDO::FETCH_ASSOC) ?: null;

        $this->view->render('admin/solicitacoes_vaga/form', [
            'mode' => 'show',
            'csrf' => Security::csrfToken(),
            'form' => $record,
            'record' => $record,
            'dependencies' => SolicitacaoVaga::formDependencies($userId, $userId),
            'error' => Security::sanitizeString($_GET['erro'] ?? ''),
            'success' => Security::sanitizeString($_GET['ok'] ?? ''),
            'canEditRh' => SolicitacaoVaga::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
            'vagaVinculada' => $vagaVinculada,
        ], 'layouts/admin');
    }

    public function approveLeader(string $id): void
    {
        $this->handleApproval((int)$id, 'lider_imediato');
    }

    public function approveRh(string $id): void
    {
        $this->handleApproval((int)$id, 'rh');
    }

    public function updateRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $result = SolicitacaoVaga::saveRhControl((int)$id, $_POST, $userId, Security::clientIp());
        if (!($result['ok'] ?? false)) {
            redirect('/admin/solicitacoes-vaga/' . (int)$id . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao salvar o controle interno de RH.')));
        }

        redirect('/admin/solicitacoes-vaga/' . (int)$id . '?ok=' . urlencode('Controle interno de RH atualizado com sucesso.'));
    }

    public function addNota(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);

        if (!SolicitacaoVaga::findAccessible((int)$id, $userId, $role, $isSupervisor)) {
            http_response_code(404);
            echo 'Solicitação de vaga não encontrada.';
            return;
        }

        $texto = trim((string)($_POST['texto'] ?? ''));
        $result = SolicitacaoVaga::addKanbanNota((int)$id, $texto, $userId);

        if (!($result['ok'] ?? false)) {
            redirect('/admin/solicitacoes-vaga/' . (int)$id . '?erro=' . urlencode((string)($result['message'] ?? 'Falha ao salvar a anotação.')));
            return;
        }

        redirect('/admin/solicitacoes-vaga/' . (int)$id . '?ok=' . urlencode('Anotação registrada com sucesso.'));
    }

    /**
     * Rede de segurança / disparo manual: gera (ou revincula) o rascunho da vaga de uma
     * solicitação já APROVADA. Idempotente — nunca cria uma segunda vaga. Restrito a RH/Admin.
     */
    public function gerarVaga(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $result = (new SolicitacaoVagaPublicacaoService())->gerarRascunho(
            (int)$id,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            Security::clientIp()
        );

        if (!($result['ok'] ?? false)) {
            redirect('/admin/solicitacoes-vaga/' . (int)$id . '?erro=' . urlencode((string)($result['error'] ?? 'Não foi possível gerar o rascunho da vaga.')));
        }

        $msg = ($result['ja_existia'] ?? false)
            ? 'Esta solicitação já tem uma vaga vinculada.'
            : 'Rascunho da vaga gerado. Revise e publique em Vagas.';
        redirect('/admin/solicitacoes-vaga/' . (int)$id . '?ok=' . urlencode($msg));
    }

    private function handleApproval(int $id, string $step): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $decision = Security::sanitizeString($_POST['decision'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $result = SolicitacaoVaga::approve(
            $id,
            $step,
            (int)($_SESSION['user_id'] ?? 0),
            SolicitacaoVaga::userCanEditRh(Auth::role(), !empty($_SESSION['user_is_supervisor'])),
            !empty($_SESSION['user_is_supervisor']),
            $decision,
            $comment,
            Security::clientIp()
        );

        if (!($result['ok'] ?? false)) {
            redirect('/admin/solicitacoes-vaga/' . $id . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao registrar a aprovação.')));
        }

        redirect('/admin/solicitacoes-vaga/' . $id . '?ok=' . urlencode('Etapa de aprovação registrada com sucesso.'));
    }

    private function canCreate(int $userId, ?string $role, bool $isSupervisor): bool
    {
        if (!SolicitacaoVaga::userCanCreate($role, $isSupervisor)) {
            return false;
        }

        // RH / Admin / supervisor sempre podem abrir Solicitação de Vaga.
        if (SolicitacaoVaga::userCanEditRh($role, $isSupervisor)) {
            return true;
        }

        // Demais usuários: autorização EXPLÍCITA em `usuarios.pode_solicitar_vaga` (migration
        // 2026-09-09). Nunca inferida por cargo, setor ou vínculo com colaborador/METADADOS.
        // O usuário precisa também estar ativo (email_verified_at IS NOT NULL).
        $access = SolicitacaoVaga::userAccessProfilePublic($userId);
        return is_array($access)
            && (int)($access['ativo'] ?? 0) === 1
            && (int)($access['pode_solicitar_vaga'] ?? 0) === 1;
    }

    private function defaultFormValues(): array
    {
        return [
            'solicitante_usuario_id' => '',
            'setor_id' => '',
            'quantidade_vagas' => 1,
            'cargo_id' => '',
            'maquina_operada' => '',
            'gestor_solicitante_colaborador_id' => '',
            'tipo_vaga' => '',
            'colaborador_substituido_id' => '',
            'data_desligamento' => '',
            'motivo_saida' => '',
            'motivo_saida_outros' => '',
            'tipo_contratacao' => '',
            'salario_previsto' => '',
            'beneficio_ids' => [],
            'centro_custo_id' => '',
            'previsto_orcamento' => '',
            'justificativa_orcamento' => '',
            'jornada_trabalho' => '',
            'escala' => '',
            'turno' => '',
            'escolaridade_minima' => '',
            'formacao_academica' => '',
            'experiencia_necessaria' => '',
            'entregas_esperadas' => '',
            'competencia_tecnica_ids' => [],
            'competencia_comportamental_ids' => [],
            'nivel_responsabilidade' => '',
            'data_prevista_inicio' => '',
            'urgencia' => '',
            'data_limite_fechamento' => '',
            'nome_contratado_colaborador_id' => '',
            'data_admissao' => '',
            'avaliacao_90_dias' => '',
            'observacoes_rh' => '',
            'tempo_fechamento_dias' => '',
            'status_fluxo' => 'pendente_lider',
            'aprovacoes' => [],
            'auditoria' => [],
        ];
    }

    private function sanitizeFormInput(array $input): array
    {
        $data = $this->defaultFormValues();
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $data[$key] = is_array($input[$key]) ? array_map('strval', $input[$key]) : Security::sanitizeString((string)$input[$key]);
        }
        return $data;
    }
}
