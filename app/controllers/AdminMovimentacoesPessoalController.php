<?php
class AdminMovimentacoesPessoalController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $this->view->render('admin/movimentacoes_pessoal/index', [
            'items' => MovimentacaoPessoal::listForUser(
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
            echo 'Apenas gestores, RH ou administradores podem registrar movimentações de pessoal.';
            return;
        }

        $this->view->render('admin/movimentacoes_pessoal/form', [
            'mode' => 'create',
            'csrf' => Security::csrfToken(),
            'form' => $this->defaultFormValues(),
            'record' => null,
            'dependencies' => MovimentacaoPessoal::formDependencies($userId),
            'error' => '',
            'success' => '',
            'canEditRh' => MovimentacaoPessoal::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
            'isSupervisor' => $isSupervisor,
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
            echo 'Apenas gestores, RH ou administradores podem registrar movimentações de pessoal.';
            return;
        }

        $action = (string)($_POST['submit_action'] ?? 'save_draft');
        if ($action === 'sign_manager') {
            $result = MovimentacaoPessoal::signManager($_POST, $userId, $isSupervisor, null, Security::clientIp());
            if ($result['ok'] ?? false) {
                redirect('/admin/movimentacoes-pessoal/' . (int)$result['id'] . '?ok=' . urlencode('Movimentação assinada pelo gestor e encaminhada ao RH.'));
            }
            $this->renderFormError('create', $_POST, $result['error'] ?? 'Falha ao assinar a movimentação.', $userId, $role, $isSupervisor);
            return;
        }

        $result = MovimentacaoPessoal::saveDraft($_POST, $userId, null, Security::clientIp());
        if ($result['ok'] ?? false) {
            redirect('/admin/movimentacoes-pessoal/' . (int)$result['id'] . '?ok=' . urlencode('Rascunho salvo com sucesso.'));
        }

        $this->renderFormError('create', $_POST, $result['error'] ?? 'Falha ao salvar o rascunho.', $userId, $role, $isSupervisor);
    }

    public function show(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        $record = MovimentacaoPessoal::findAccessible((int)$id, $userId, $role, $isSupervisor);
        if (!$record) {
            http_response_code(404);
            echo 'Movimentação de pessoal não encontrada.';
            return;
        }

        $this->view->render('admin/movimentacoes_pessoal/form', [
            'mode' => 'show',
            'csrf' => Security::csrfToken(),
            'form' => $record,
            'record' => $record,
            'dependencies' => MovimentacaoPessoal::formDependencies($userId),
            'error' => Security::sanitizeString($_GET['erro'] ?? ''),
            'success' => Security::sanitizeString($_GET['ok'] ?? ''),
            'canEditRh' => MovimentacaoPessoal::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
            'isSupervisor' => $isSupervisor,
        ], 'layouts/admin');
    }

    public function update(string $id): void
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
        $recordId = (int)$id;
        $action = (string)($_POST['submit_action'] ?? 'save_draft');

        if ($action === 'sign_manager') {
            $result = MovimentacaoPessoal::signManager($_POST, $userId, $isSupervisor, $recordId, Security::clientIp());
            if ($result['ok'] ?? false) {
                redirect('/admin/movimentacoes-pessoal/' . $recordId . '?ok=' . urlencode('Movimentação assinada pelo gestor e encaminhada ao RH.'));
            }
            redirect('/admin/movimentacoes-pessoal/' . $recordId . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao assinar como gestor.')));
        }

        $result = MovimentacaoPessoal::saveDraft($_POST, $userId, $recordId, Security::clientIp());
        if ($result['ok'] ?? false) {
            redirect('/admin/movimentacoes-pessoal/' . $recordId . '?ok=' . urlencode('Rascunho atualizado com sucesso.'));
        }
        redirect('/admin/movimentacoes-pessoal/' . $recordId . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao atualizar o rascunho.')));
    }

    public function signRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);
        $result = MovimentacaoPessoal::signRh((int)$id, $userId, MovimentacaoPessoal::userCanEditRh($role, $isSupervisor), Security::clientIp());
        if ($result['ok'] ?? false) {
            redirect('/admin/movimentacoes-pessoal/' . (int)$id . '?ok=' . urlencode('Movimentação aprovada e assinada pelo RH.'));
        }
        redirect('/admin/movimentacoes-pessoal/' . (int)$id . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao assinar como RH.')));
    }

    private function renderFormError(string $mode, array $input, string $error, int $userId, ?string $role, bool $isSupervisor): void
    {
        $this->view->render('admin/movimentacoes_pessoal/form', [
            'mode' => $mode,
            'csrf' => Security::csrfToken(),
            'form' => $this->sanitizeFormInput($input),
            'record' => null,
            'dependencies' => MovimentacaoPessoal::formDependencies($userId),
            'error' => $error,
            'success' => '',
            'canEditRh' => MovimentacaoPessoal::userCanEditRh($role, $isSupervisor),
            'currentRole' => $role,
            'currentUserId' => $userId,
            'isSupervisor' => $isSupervisor,
        ], 'layouts/admin');
    }

    private function canCreate(int $userId, ?string $role, bool $isSupervisor): bool
    {
        if (!MovimentacaoPessoal::userCanCreate($role, $isSupervisor)) {
            return false;
        }
        if (MovimentacaoPessoal::userCanEditRh($role, $isSupervisor)) {
            return true;
        }
        $dependencies = MovimentacaoPessoal::formDependencies($userId);
        $access = $dependencies['current_access'] ?? null;
        return is_array($access) && (int)($access['is_gestor'] ?? 0) === 1;
    }

    private function defaultFormValues(): array
    {
        return [
            'tipo_movimentacao' => '',
            'data_solicitacao' => date('d/m/Y'),
            'gestor_solicitante_usuario_id' => '',
            'setor_id' => '',
            'colaborador_id' => '',
            'matricula_snapshot' => '',
            'cargo_atual_id' => '',
            'tempo_cargo_meses' => 0,
            'tempo_empresa_meses' => 0,
            'tempo_cargo_label' => '',
            'tempo_empresa_label' => '',
            'salario_atual_snapshot' => '',
            'novo_cargo_id' => '',
            'nova_area_setor_id' => '',
            'novo_salario' => '',
            'percentual_aumento' => '',
            'data_prevista_mudanca' => '',
            'justificativa' => '',
            'entregas_ultimos_6_meses' => '',
            'resultados_atingidos' => '',
            'avaliacao_desempenho_id' => '',
            'pronto_proximo_nivel' => '',
            'competencias_tecnicas' => '',
            'competencias_comportamentais' => '',
            'pontos_desenvolvimento' => '',
            'aumento_mensal' => '',
            'impacto_anual' => '',
            'existe_orcamento_aprovado' => '',
            'posicao_atual_sera' => '',
            'existe_candidato_interno' => '',
            'necessita_recrutamento_externo' => '',
            'existe_risco_perda' => '',
            'impacto_nao_aprovado' => '',
            'status_fluxo' => 'rascunho',
            'gestor_assinado_em' => '',
            'rh_assinado_em' => '',
            'data_decisao_br' => '',
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
