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
            echo 'Apenas usuários vinculados como gestores ou RH podem registrar solicitações de vaga.';
            return;
        }

        $this->view->render('admin/solicitacoes_vaga/form', [
            'mode' => 'create',
            'csrf' => Security::csrfToken(),
            'form' => $this->defaultFormValues(),
            'record' => null,
            'dependencies' => SolicitacaoVaga::formDependencies($userId),
            'error' => '',
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
            echo 'Apenas usuários vinculados como gestores ou RH podem registrar solicitações de vaga.';
            return;
        }

        try {
            $id = SolicitacaoVaga::create($_POST, $userId, Security::clientIp());
            redirect('/admin/solicitacoes-vaga/' . $id . '?ok=' . urlencode('Solicitação de vaga enviada para aprovação.'));
        } catch (Throwable $e) {
            $this->view->render('admin/solicitacoes_vaga/form', [
                'mode' => 'create',
                'csrf' => Security::csrfToken(),
                'form' => $this->sanitizeFormInput($_POST),
                'record' => null,
                'dependencies' => SolicitacaoVaga::formDependencies($userId),
                'error' => $e->getMessage(),
                'canEditRh' => SolicitacaoVaga::userCanEditRh($role, $isSupervisor),
                'currentRole' => $role,
                'currentUserId' => $userId,
            ], 'layouts/admin');
        }
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

        $this->view->render('admin/solicitacoes_vaga/form', [
            'mode' => 'show',
            'csrf' => Security::csrfToken(),
            'form' => $record,
            'record' => $record,
            'dependencies' => SolicitacaoVaga::formDependencies($userId),
            'error' => Security::sanitizeString($_GET['erro'] ?? ''),
            'success' => Security::sanitizeString($_GET['ok'] ?? ''),
            'canEditRh' => SolicitacaoVaga::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
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

        if (SolicitacaoVaga::userCanEditRh($role, $isSupervisor)) {
            return true;
        }

        $dependencies = SolicitacaoVaga::formDependencies($userId);
        $access = $dependencies['current_access'] ?? null;
        return is_array($access) && (int)($access['is_gestor'] ?? 0) === 1;
    }

    private function defaultFormValues(): array
    {
        return [
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
