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
        ], 'layouts/app-shell');
    }

    public function show(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $c = Candidatura::find((int)$id);
        if (!$c) { http_response_code(404); echo 'Candidatura não encontrada'; return; }
        $historico = Candidatura::getHistorico((int)$id);
        $stages = PipelineStage::all();
        $csrf = Security::csrfToken();

        // Seções novas (Sprint "Histórico de Comunicação + Experiência do Candidato") são
        // permission-gated de verdade: sem a permissão, nem os dados são buscados — não é só a
        // view escondendo HTML.
        $podeVerComunicacoes = Authorization::temPermissao('comunicacoes.visualizar');
        $comunicacoes = $podeVerComunicacoes ? Comunicacao::allByCandidatura((int)$id) : [];

        $podeVerPesquisa = Authorization::temPermissao('pesquisa_experiencia.visualizar');
        $pesquisa = $podeVerPesquisa ? PesquisaExperiencia::findByCandidatura((int)$id) : null;

        $documentos = CandidaturaDocumento::allByCandidatura((int)$id);

        $this->view->render('admin/candidaturas/show', [
            'c' => $c,
            'historico' => $historico,
            'stages' => $stages,
            'csrf' => $csrf,
            'podeVerComunicacoes' => $podeVerComunicacoes,
            'comunicacoes' => $comunicacoes,
            'podeVerPesquisa' => $podeVerPesquisa,
            'pesquisa' => $pesquisa,
            'documentos' => $documentos,
            'podeGerenciarDocumentos' => in_array(Auth::role(), ['admin', 'rh'], true),
        ], 'layouts/app-shell');
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

    /**
     * Documentos de contratação (correção de 2026-11): upload de múltiplos arquivos, disponível
     * na tela de detalhamento a qualquer momento (não um gatilho de etapa — pipeline_stages é
     * dirigido por dados, sem constante "Aprovado" no código). Mesmo controle de acesso do resto
     * do módulo (admin/rh), sem regra de setor nova — ver decisão registrada na correção.
     */
    public function uploadDocumento(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $candidaturaId = (int)$id;
        $c = Candidatura::find($candidaturaId);
        if (!$c) { http_response_code(404); echo 'Candidatura não encontrada'; return; }

        $arquivos = $_FILES['documentos'] ?? null;
        if (!is_array($arquivos) || !is_array($arquivos['name'] ?? null) || count(array_filter($arquivos['name'])) === 0) {
            http_response_code(422);
            echo 'Selecione ao menos um arquivo.';
            return;
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $erros = [];
        foreach ($arquivos['name'] as $i => $nomeOriginal) {
            if ($nomeOriginal === '' || ($arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $arquivo = [
                'name' => $arquivos['name'][$i],
                'type' => $arquivos['type'][$i],
                'tmp_name' => $arquivos['tmp_name'][$i],
                'error' => $arquivos['error'][$i],
                'size' => $arquivos['size'][$i],
            ];
            try {
                $salvo = Upload::saveDocumento($arquivo);
                CandidaturaDocumento::create([
                    'candidatura_id' => $candidaturaId,
                    'nome_original' => Security::sanitizeString((string)$nomeOriginal),
                    'nome_armazenado' => $salvo['nome_armazenado'],
                    'mime' => $salvo['mime'],
                    'tamanho_bytes' => $salvo['tamanho'],
                    'enviado_por_usuario_id' => $usuarioId,
                ]);
            } catch (\Throwable $e) {
                $erros[] = Security::sanitizeString((string)$nomeOriginal) . ': ' . $e->getMessage();
            }
        }

        if ($erros !== []) {
            Logger::error('Falha ao anexar documento(s) de candidatura', ['candidatura_id' => $candidaturaId, 'erros' => $erros]);
            http_response_code(422);
            echo implode(' | ', $erros);
            return;
        }
        redirect('/admin/candidaturas/' . $candidaturaId);
    }

    public function downloadDocumento(string $id, string $docId): void
    {
        Auth::requireRole(['admin', 'rh']);
        $documento = CandidaturaDocumento::find((int)$docId);
        // Nunca confiar só no id do documento: precisa pertencer à candidatura da URL.
        if (!$documento || (int)$documento['candidatura_id'] !== (int)$id) {
            http_response_code(404); echo 'Documento não encontrado'; return;
        }
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'candidatura_documentos';
        $file = $dir . DIRECTORY_SEPARATOR . basename((string)$documento['nome_armazenado']);
        $real = realpath($file);
        $realDir = realpath($dir);
        if ($real === false || $realDir === false || strpos($real, $realDir) !== 0 || !is_file($real)) {
            http_response_code(404); echo 'Arquivo não encontrado'; return;
        }
        header('Content-Type: ' . $documento['mime']);
        header('Content-Disposition: attachment; filename="' . Security::sanitizeString((string)$documento['nome_original']) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
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
        $stageMetadata = [
            'interview_date' => Security::sanitizeString($_POST['interview_date'] ?? ''),
            'interview_time' => Security::sanitizeString($_POST['interview_time'] ?? ''),
            'interview_location' => Security::sanitizeString($_POST['interview_location'] ?? ''),
            'interview_link' => trim((string)($_POST['interview_link'] ?? '')),
            'admission_date' => Security::sanitizeString($_POST['admission_date'] ?? ''),
            'admission_notes' => Security::sanitizeString($_POST['admission_notes'] ?? ''),
            'test_name' => Security::sanitizeString($_POST['test_name'] ?? ''),
            'deadline' => Security::sanitizeString($_POST['deadline'] ?? ''),
        ];
        
        try {
            $indicacaoUpdated = Candidatura::updateIndicacaoColaborador((int)$id, $indicacaoColaborador, $indicacaoNomeColaborador);
            if (!$indicacaoUpdated) {
                http_response_code(422);
                echo 'Informe o nome do colaborador que realizou a indicação.';
                return;
            }

            $candidaturaAtual = Candidatura::find((int)$id);
            $currentStageId = (int)($candidaturaAtual['stage_id'] ?? 0);
            $isTransition = $stageId > 0 && $stageId !== $currentStageId;

            if ($isTransition) {
                // Movimentação real de etapa: validação, metadados, histórico e evento de webhook são
                // tratados como uma única operação atômica dentro de RecruitmentPipelineService — mesma
                // fonte de validação usada pelo Kanban (RecruitmentStageMetadataValidator). O envio
                // deliberado deste formulário conta como a confirmação explícita exigida por algumas etapas.
                $metadataForMove = $stageMetadata;
                $metadataForMove['observacoes'] = $observacoes;
                $metadataForMove['confirm'] = true;

                $result = Candidatura::updateStage((int)$id, $stageId, $usuarioId, $metadataForMove);
                if (!($result['ok'] ?? false)) {
                    $errorType = (string)($result['error'] ?? 'exception');
                    http_response_code($errorType === 'validation' ? 422 : ($errorType === 'conflict' ? 409 : 500));
                    echo (string)($result['message'] ?? 'Falha ao atualizar candidatura.');
                    return;
                }
            } else {
                // Sem transição de etapa: apenas atualização pontual dos metadados (capacidade já existente
                // desde a Fase 1, para editar dados da etapa atual sem mover o candidato).
                if (!Candidatura::upsertStageMetadata((int)$id, $stageMetadata)) {
                    throw new \RuntimeException('Falha ao salvar metadados da etapa da candidatura.');
                }
                if (!empty($observacoes)) {
                    $notesUpdated = Candidatura::updateStatusNotes((int)$id, $candidaturaAtual['status'] ?? 'custom', $observacoes, $usuarioId);
                    if (!$notesUpdated) {
                        throw new \RuntimeException('Falha ao salvar observações da candidatura.');
                    }
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
