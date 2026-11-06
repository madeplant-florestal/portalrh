<?php

/**
 * Administração do Agendamento do Exame Admissional — admin/rh, mesmo padrão de acesso de
 * AdminCartaPropostaController (sem regra de setor nova).
 */
class AdminExameAdmissionalController extends Controller
{
    /**
     * Cria (ou reaproveita) o agendamento e vai direto para a edição. Gate "Carta Proposta aceita"
     * é checado no Service.
     *
     * O parâmetro PRECISA se chamar $id (mesmo motivo de AdminCartaPropostaController::criar()):
     * Router despacha por nome do grupo nomeado da rota ({id}) via argumentos NOMEADOS.
     */
    public function criar(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = (new ExameAdmissionalService())->obterOuCriar((int)$id, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao criar o agendamento do exame admissional.'));
            return;
        }
        redirect('/admin/exames-admissionais/' . $resultado['id'] . '/editar');
    }

    public function editar(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $repository = new ExameAdmissionalRepository();
        $exame = $repository->buscarPorId((int)$id);
        if ($exame === null) { http_response_code(404); echo 'Agendamento não encontrado'; return; }

        $candidatura = Candidatura::find((int)$exame['candidatura_id']);
        $this->view->render('admin/exame_admissional/editar', [
            'exame' => $exame,
            'candidatura' => $candidatura,
            'historico' => $repository->historico((int)$exame['id']),
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
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = (new ExameAdmissionalService())->atualizarDados((int)$id, $this->dadosDoFormulario(), $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao salvar.'));
            return;
        }
        redirect('/admin/exames-admissionais/' . (int)$id . '/editar');
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
        // Salva as últimas edições do formulário antes de preparar (o RH pode ter ajustado algo na
        // mesma tela, no mesmo submit) — mesmo padrão de AdminCartaPropostaController::emitir().
        $salvou = (new ExameAdmissionalService())->atualizarDados((int)$id, $this->dadosDoFormulario(), $usuarioId);
        if (!($salvou['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($salvou['error'] ?? 'Falha ao salvar.'));
            return;
        }

        $resultado = (new ExameAdmissionalService())->prepararMensagem((int)$id, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao preparar a mensagem.'));
            return;
        }
        redirect('/admin/exames-admissionais/' . (int)$id . '/editar');
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
        $resultado = (new ExameAdmissionalService())->confirmarEnvio((int)$id, $usuarioId);
        if (!($resultado['ok'] ?? false)) {
            http_response_code(422);
            echo Security::e((string)($resultado['error'] ?? 'Falha ao confirmar o envio.'));
            return;
        }
        redirect('/admin/exames-admissionais/' . (int)$id . '/editar');
    }

    private function dadosDoFormulario(): array
    {
        return [
            'clinica' => Security::sanitizeString($_POST['clinica'] ?? ''),
            'endereco' => Security::sanitizeString($_POST['endereco'] ?? ''),
            'data_exame' => trim((string)($_POST['data_exame'] ?? '')),
            'horario' => trim((string)($_POST['horario'] ?? '')),
            'telefone_contato' => Security::sanitizeString($_POST['telefone_contato'] ?? ''),
        ];
    }
}
