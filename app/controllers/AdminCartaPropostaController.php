<?php

/**
 * Administração da Carta Proposta (Fase 1) — admin/rh, mesmo padrão de acesso do restante do
 * módulo de candidaturas/documentos (sem regra de setor nova, decisão já registrada na correção
 * de documentos de candidato aprovado).
 */
class AdminCartaPropostaController extends Controller
{
    /** Cria (ou reaproveita) o rascunho e vai direto para a edição. */
    public function criar(string $candidaturaId): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = (new CartaPropostaService())->criarOuReaproveitarRascunho((int)$candidaturaId, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao criar a Carta Proposta.'));
            return;
        }
        redirect('/admin/cartas-propostas/' . $resultado['id'] . '/editar');
    }

    public function editar(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $carta = (new CartaPropostaRepository())->buscarPorId((int)$id);
        if ($carta === null) { http_response_code(404); echo 'Carta Proposta não encontrada'; return; }

        $candidatura = Candidatura::find((int)$carta['candidatura_id']);
        $this->view->render('admin/carta_proposta/editar', [
            'carta' => $carta,
            'candidatura' => $candidatura,
            'historico' => (new CartaPropostaRepository())->listarPorCandidatura((int)$carta['candidatura_id']),
            'csrf' => Security::csrfToken(),
        ], 'layouts/app-shell');
    }

    public function atualizar(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $dados = $this->dadosDoFormulario();
        $resultado = (new CartaPropostaService())->atualizarRascunho((int)$id, $dados);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao salvar.'));
            return;
        }
        redirect('/admin/cartas-propostas/' . (int)$id . '/editar');
    }

    public function emitir(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        // Salva as últimas edições do formulário antes de emitir (o RH pode ter ajustado algo na
        // mesma tela, no mesmo submit).
        $dados = $this->dadosDoFormulario();
        (new CartaPropostaService())->atualizarRascunho((int)$id, $dados);

        $resultado = (new CartaPropostaService())->emitir((int)$id);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao emitir a Carta Proposta.'));
            return;
        }
        redirect('/admin/cartas-propostas/' . (int)$id . '/editar');
    }

    public function prepararEnvio(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = (new CartaPropostaService())->prepararEnvio((int)$id, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao preparar o envio.'));
            return;
        }
        redirect('/admin/cartas-propostas/' . (int)$id . '/editar');
    }

    public function confirmarEnvio(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = (new CartaPropostaService())->confirmarEnvio((int)$id, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao confirmar o envio.'));
            return;
        }
        redirect('/admin/cartas-propostas/' . (int)$id . '/editar');
    }

    public function pdf(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $carta = (new CartaPropostaRepository())->buscarPorId((int)$id);
        if ($carta === null || empty($carta['pdf_nome_armazenado'])) {
            http_response_code(404); echo 'PDF não encontrado'; return;
        }
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas';
        $file = $dir . DIRECTORY_SEPARATOR . basename((string)$carta['pdf_nome_armazenado']);
        $real = realpath($file);
        $realDir = realpath($dir);
        if ($real === false || $realDir === false || strpos($real, $realDir) !== 0 || !is_file($real)) {
            http_response_code(404); echo 'Arquivo não encontrado'; return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="carta-proposta-v' . (int)$carta['versao'] . '.pdf"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    private function dadosDoFormulario(): array
    {
        return [
            'nome_candidato' => Security::sanitizeString($_POST['nome_candidato'] ?? ''),
            'cargo' => Security::sanitizeString($_POST['cargo'] ?? ''),
            'setor' => Security::sanitizeString($_POST['setor'] ?? '') ?: null,
            'empresa_contratante' => Security::sanitizeString($_POST['empresa_contratante'] ?? ''),
            'salario_proposto' => (float)str_replace(',', '.', (string)($_POST['salario_proposto'] ?? '0')),
            'jornada_trabalho' => Security::sanitizeString($_POST['jornada_trabalho'] ?? ''),
            'escala_horario' => Security::sanitizeString($_POST['escala_horario'] ?? '') ?: null,
            'beneficios_texto' => trim((string)($_POST['beneficios_texto'] ?? '')) ?: null,
            'local_trabalho' => Security::sanitizeString($_POST['local_trabalho'] ?? '') ?: null,
            'previsao_inicio' => $this->dataOuNull($_POST['previsao_inicio'] ?? ''),
            'validade_proposta' => $this->dataOuNull($_POST['validade_proposta'] ?? '') ?? (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
            'observacoes' => trim((string)($_POST['observacoes'] ?? '')) ?: null,
        ];
    }

    private function dataOuNull(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '' || strtotime($valor) === false) {
            return null;
        }
        return date('Y-m-d', strtotime($valor));
    }
}
