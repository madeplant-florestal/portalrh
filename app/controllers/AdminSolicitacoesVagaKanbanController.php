<?php
/**
 * Kanban de Solicitações de Vaga — acompanha a situação operacional da vaga solicitada pelo
 * gestor (Em aprovação/Aprovada/Em recrutamento/Em processo seletivo/Fechada/Cancelada).
 *
 * Não confundir com AdminPipelineController (Kanban de Recrutamento e Seleção, que acompanha
 * candidatos). São domínios, tabelas e permissões independentes — ver
 * app/services/SolicitacaoVagaPipelineService.php.
 */
class AdminSolicitacoesVagaKanbanController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);

        $filters = $this->parseFilters($_GET);
        $stages = SolicitacaoVagaStage::all();
        $solicitacoes = SolicitacaoVaga::allForKanban($userId, $role, $isSupervisor, $filters);

        $kanban = [];
        foreach ($stages as $stage) {
            $kanban[$stage['id']] = ['stage' => $stage, 'items' => []];
        }
        foreach ($solicitacoes as $item) {
            $sid = $item['situacao_kanban_id'] ?? SolicitacaoVagaStage::defaultStageId();
            if (isset($kanban[$sid])) {
                $kanban[$sid]['items'][] = $item;
            } else {
                $first = array_key_first($kanban);
                if ($first !== null) {
                    $kanban[$first]['items'][] = $item;
                }
            }
        }

        $dependencies = SolicitacaoVaga::formDependencies($userId);

        $this->view->render('admin/solicitacoes_vaga/kanban', [
            'kanban' => $kanban,
            'stageCount' => count($stages),
            'setores' => $dependencies['setores'],
            'cargos' => $dependencies['cargos'],
            'gestores' => $dependencies['gestores'],
            'filters' => $filters,
            'csrf' => Security::csrfToken(),
        ], 'layouts/admin');
    }

    public function move(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (!Security::csrfCheck($input['csrf'] ?? '')) {
            http_response_code(400);
            echo json_encode(['error' => 'CSRF inválido']);
            return;
        }

        $solicitacaoId = (int)($input['solicitacao_id'] ?? 0);
        $stageId = (int)($input['stage_id'] ?? 0);
        if (!$solicitacaoId || !$stageId) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos']);
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = Auth::role();
        $isSupervisor = !empty($_SESSION['user_is_supervisor']);

        // Reaproveita a mesma checagem de acesso por linha do restante do módulo — gestor só
        // movimenta solicitações onde é solicitante ou aprovador designado.
        if (!SolicitacaoVaga::findAccessible($solicitacaoId, $userId, $role, $isSupervisor)) {
            http_response_code(404);
            echo json_encode(['error' => 'Solicitação de vaga não encontrada.']);
            return;
        }

        $checkConcurrency = array_key_exists('expected_current_stage_id', $input);
        $expectedCurrentStageId = null;
        if ($checkConcurrency && $input['expected_current_stage_id'] !== null && $input['expected_current_stage_id'] !== '') {
            $expectedCurrentStageId = (int)$input['expected_current_stage_id'];
        }

        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

        $result = SolicitacaoVaga::moveKanbanStage($solicitacaoId, $stageId, $userId, $metadata, $checkConcurrency, $expectedCurrentStageId);

        if ($result['ok'] ?? false) {
            echo json_encode(['success' => true]);
            return;
        }

        $error = (string)($result['error'] ?? 'exception');
        $message = (string)($result['message'] ?? 'Erro ao mover a solicitação.');

        switch ($error) {
            case 'validation':
                http_response_code(422);
                echo json_encode(['error' => $message, 'missing_fields' => $result['missing_fields'] ?? []]);
                return;
            case 'conflict':
                http_response_code(409);
                echo json_encode(['error' => $message]);
                return;
            case 'not_found':
                http_response_code(404);
                echo json_encode(['error' => $message]);
                return;
            default:
                http_response_code(500);
                echo json_encode(['error' => $message]);
        }
    }

    private function parseFilters(array $query): array
    {
        $filters = [];
        if (!empty($query['gestor_colaborador_id'])) {
            $filters['gestor_colaborador_id'] = (int)$query['gestor_colaborador_id'];
        }
        if (!empty($query['setor_id'])) {
            $filters['setor_id'] = (int)$query['setor_id'];
        }
        if (!empty($query['cargo_id'])) {
            $filters['cargo_id'] = (int)$query['cargo_id'];
        }
        if (!empty($query['situacao_slug'])) {
            $filters['situacao_slug'] = Security::sanitizeString((string)$query['situacao_slug']);
        }
        $dataDe = DateHelper::toDatabaseDate($query['data_de'] ?? '');
        if ($dataDe) {
            $filters['data_de'] = $dataDe;
        }
        $dataAte = DateHelper::toDatabaseDate($query['data_ate'] ?? '');
        if ($dataAte) {
            $filters['data_ate'] = $dataAte;
        }
        return $filters;
    }
}
