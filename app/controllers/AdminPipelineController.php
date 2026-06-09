<?php
class AdminPipelineController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh']);
        
        $vagaId = isset($_GET['vaga_id']) ? (int)$_GET['vaga_id'] : null;
        
        // Get all stages
        $stages = PipelineStage::all();
        
        // Get applications (filtered by vaga if selected)
        $filters = [];
        if ($vagaId) {
            $filters['vaga_id'] = $vagaId;
        }
        $candidaturas = Candidatura::all($filters);
        
        // Group by stage_id
        $kanban = [];
        foreach ($stages as $stage) {
            $kanban[$stage['id']] = [
                'stage' => $stage,
                'items' => []
            ];
        }
        
        // If there are candidaturas with unknown stage, put them in the first stage or separate
        foreach ($candidaturas as $c) {
            $sid = $c['stage_id'] ?? 1; // Default to first if null
            if (isset($kanban[$sid])) {
                $kanban[$sid]['items'][] = $c;
            } else {
                // Fallback for deleted stages? Put in first.
                $first = array_key_first($kanban);
                $kanban[$first]['items'][] = $c;
            }
        }
        
        $vagas = Vaga::all();
        
        $this->view->render('admin/pipeline/index', [
            'kanban' => $kanban,
            'vagas' => $vagas,
            'selectedVaga' => $vagaId,
            'csrf' => Security::csrfToken()
        ], 'layouts/admin');
    }

    public function move(): void
    {
        Auth::requireRole(['admin', 'rh']);
        
        // JSON Input
        $input = json_decode(file_get_contents('php://input'), true);
        
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
        
        $userId = $_SESSION['user_id'] ?? null;
        $success = Candidatura::updateStage($candidaturaId, $stageId, $userId);
        
        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao mover candidato']);
        }
    }
}
