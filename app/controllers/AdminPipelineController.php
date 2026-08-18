<?php
class AdminPipelineController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh']);

        $vagaId = isset($_GET['vaga_id']) ? (int)$_GET['vaga_id'] : null;

        $stages = PipelineStage::all();

        $filters = [];
        if ($vagaId) {
            $filters['vaga_id'] = $vagaId;
        }
        $candidaturas = Candidatura::all($filters);

        $kanban = [];
        foreach ($stages as $stage) {
            $kanban[$stage['id']] = [
                'stage' => $stage,
                'items' => []
            ];
        }

        foreach ($candidaturas as $c) {
            $sid = $c['stage_id'] ?? PipelineStage::defaultStageId(); // Rede de segurança para candidaturas legadas sem etapa atribuída
            if (isset($kanban[$sid])) {
                $kanban[$sid]['items'][] = $c;
            } else {
                $first = array_key_first($kanban);
                $kanban[$first]['items'][] = $c;
            }
        }

        $vagas = Vaga::all();

        $this->view->render('admin/pipeline/index', [
            'kanban' => $kanban,
            'vagas' => $vagas,
            'selectedVaga' => $vagaId,
            'csrf' => Security::csrfToken(),
            'stageCount' => count($stages),
        ], 'layouts/admin');
    }

    public function move(): void
    {
        Auth::requireRole(['admin', 'rh']);

        // JSON Input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (!Security::csrfCheck($input['csrf'] ?? '')) {
            http_response_code(400);
            echo json_encode(['error' => 'CSRF inválido']);
            return;
        }

        $candidaturaId = (int)($input['candidatura_id'] ?? 0);
        $stageId = (int)($input['stage_id'] ?? 0);

        if (!$candidaturaId || !$stageId) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos']);
            return;
        }

        $checkConcurrency = array_key_exists('expected_current_stage_id', $input);
        $expectedCurrentStageId = null;
        if ($checkConcurrency && $input['expected_current_stage_id'] !== null && $input['expected_current_stage_id'] !== '') {
            $expectedCurrentStageId = (int)$input['expected_current_stage_id'];
        }

        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

        $userId = $_SESSION['user_id'] ?? null;
        $result = Candidatura::updateStage($candidaturaId, $stageId, $userId, $metadata, $checkConcurrency, $expectedCurrentStageId);

        if ($result['ok'] ?? false) {
            echo json_encode(['success' => true]);
            return;
        }

        $error = (string)($result['error'] ?? 'exception');
        $message = (string)($result['message'] ?? 'Erro ao mover candidato');

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
}
