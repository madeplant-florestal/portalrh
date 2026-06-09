<?php
class AdminCandidaturasController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $filters = [
            'vaga_id' => isset($_GET['vaga_id']) ? (int)$_GET['vaga_id'] : null,
            'stage_id' => isset($_GET['stage_id']) ? (int)$_GET['stage_id'] : null,
            'data_de' => Security::sanitizeString($_GET['data_de'] ?? ''),
            'data_ate' => Security::sanitizeString($_GET['data_ate'] ?? ''),
        ];
        $candidaturas = Candidatura::all(array_filter($filters, fn($v) => $v !== null && $v !== ''));
        $vagas = Vaga::all();
        $stages = PipelineStage::all();
        $this->view->render('admin/candidaturas/index', [
            'candidaturas' => $candidaturas,
            'vagas' => $vagas,
            'stages' => $stages,
            'filters' => $filters,
            'csrf' => Security::csrfToken(),
        ], 'layouts/admin');
    }

    public function show(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $c = Candidatura::find((int)$id);
        if (!$c) { http_response_code(404); echo 'Candidatura não encontrada'; return; }
        $historico = Candidatura::getHistorico((int)$id);
        $stages = PipelineStage::all();
        $csrf = Security::csrfToken();
        $this->view->render('admin/candidaturas/show', [
            'c' => $c, 
            'historico' => $historico, 
            'stages' => $stages,
            'csrf' => $csrf
        ], 'layouts/admin');
    }
    
    public function download(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        try {
            $c = Candidatura::find((int)$id);
            if (!$c) { http_response_code(404); echo 'Candidatura não encontrada'; return; }
            $name = basename((string)($c['pdf_path'] ?? ''));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') { http_response_code(400); echo 'Arquivo inválido.'; return; }
            $downloadName = self::buildDownloadFilename($c);
            $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'resumes';
            $file = $dir . DIRECTORY_SEPARATOR . $name;
            $real = realpath($file);
            $realDir = realpath($dir);
            if ($real === false || $realDir === false || strpos($real, $realDir) !== 0 || !is_file($real)) {
                http_response_code(404); echo 'Arquivo não encontrado'; return;
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($real));
            readfile($real);
            exit;
        } catch (\Throwable $e) {
            http_response_code(422);
            echo 'Dados insuficientes para gerar nome do currículo.';
        }
    }

    private static function buildDownloadFilename(array $candidatura): string
    {
        $nome = trim((string)($candidatura['nome'] ?? ''));
        $vaga = trim((string)($candidatura['vaga_titulo'] ?? $candidatura['cargo_pretendido'] ?? ''));
        if ($nome === '' || $vaga === '') {
            throw new \RuntimeException('Nome do candidato e vaga são obrigatórios.');
        }
        $nomeSeguro = self::sanitizeFilenamePart($nome);
        $vagaSegura = self::sanitizeFilenamePart($vaga);
        if ($nomeSeguro === '' || $vagaSegura === '') {
            throw new \RuntimeException('Nome do candidato e vaga inválidos para arquivo.');
        }
        return $nomeSeguro . '_' . $vagaSegura . '.pdf';
    }

    private static function sanitizeFilenamePart(string $value): string
    {
        $value = strtr($value, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c', 'Ñ' => 'N', 'ñ' => 'n',
        ]);
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($normalized === false) {
            $normalized = $value;
        }
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');
        $normalized = preg_replace('/_+/', '_', $normalized) ?? '';
        return $normalized;
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $observacoes = Security::sanitizeString($_POST['observacoes'] ?? '');
        $indicacaoColaborador = isset($_POST['indicacao_colaborador']) && (string)$_POST['indicacao_colaborador'] === '1';
        $indicacaoNomeColaborador = Security::sanitizeString($_POST['indicacao_colaborador_nome'] ?? '');
        $usuarioId = $_SESSION['user_id'] ?? null;
        
        try {
            $indicacaoUpdated = Candidatura::updateIndicacaoColaborador((int)$id, $indicacaoColaborador, $indicacaoNomeColaborador);
            if (!$indicacaoUpdated) {
                http_response_code(422);
                echo 'Informe o nome do colaborador que realizou a indicação.';
                return;
            }
            if ($stageId > 0) {
                $stageUpdated = Candidatura::updateStage((int)$id, $stageId, $usuarioId);
                if (!$stageUpdated) {
                    throw new \RuntimeException('Falha ao atualizar etapa da candidatura.');
                }
            }
            
            // Se houver observações, adiciona nota separada ou atualiza campo legado
            if (!empty($observacoes)) {
                // Para manter compatibilidade com historico antigo
                $c = Candidatura::find((int)$id);
                $notesUpdated = Candidatura::updateStatusNotes((int)$id, $c['status'] ?? 'custom', $observacoes, $usuarioId);
                if (!$notesUpdated) {
                    throw new \RuntimeException('Falha ao salvar observações da candidatura.');
                }
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Falha ao atualizar candidatura.';
            return;
        }

        // Redireciona usando base_url da aplicação
        redirect('/admin/candidaturas/' . (int)$id);
    }

    public function updateIndicacao(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $checked = isset($_POST['indicacao_colaborador']) && (string)$_POST['indicacao_colaborador'] === '1';
        $indicacaoNomeColaborador = Security::sanitizeString($_POST['indicacao_colaborador_nome'] ?? '');
        $ok = Candidatura::updateIndicacaoColaborador((int)$id, $checked, $indicacaoNomeColaborador);
        if (!$ok) {
            http_response_code(422);
            echo 'Informe o nome do colaborador que realizou a indicação.';
            return;
        }
        redirect('/admin/candidaturas');
    }
}
