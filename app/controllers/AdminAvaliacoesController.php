<?php
class AdminAvaliacoesController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'colaborador_id' => ctype_digit((string)($_GET['colaborador_id'] ?? '')) ? (int)$_GET['colaborador_id'] : null,
        ];

        $this->view->render('admin/avaliacoes/index', [
            'avaliacoes' => AvaliacaoDesempenho::all($filters),
            'filters' => $filters,
            'colaboradorOptions' => AvaliacaoDesempenho::colaboradorOptions(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $this->view->render('admin/avaliacoes/form', [
            'csrf' => Security::csrfToken(),
            'avaliacao' => null,
            'colaboradorOptions' => AvaliacaoDesempenho::colaboradorOptions(),
            'error' => '',
        ], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $payload = [
            'colaborador_id' => $_POST['colaborador_id'] ?? '',
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'nota' => Security::sanitizeString($_POST['nota'] ?? ''),
            'periodo_referencia' => Security::sanitizeString($_POST['periodo_referencia'] ?? ''),
            'resumo' => Security::sanitizeString($_POST['resumo'] ?? ''),
        ];
        $result = AvaliacaoDesempenho::create($payload);
        if (!($result['ok'] ?? false)) {
            $this->view->render('admin/avaliacoes/form', [
                'csrf' => Security::csrfToken(),
                'avaliacao' => $payload,
                'colaboradorOptions' => AvaliacaoDesempenho::colaboradorOptions(),
                'error' => $result['error'] ?? 'Falha ao cadastrar avaliação.',
            ], 'layouts/admin');
            return;
        }

        redirect('/admin/avaliacoes?ok=' . urlencode('Avaliação cadastrada com sucesso.'));
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $avaliacao = AvaliacaoDesempenho::find((int)$id);
        if (!$avaliacao) {
            http_response_code(404);
            echo 'Avaliação não encontrada.';
            return;
        }

        $this->view->render('admin/avaliacoes/form', [
            'csrf' => Security::csrfToken(),
            'avaliacao' => $avaliacao,
            'colaboradorOptions' => AvaliacaoDesempenho::colaboradorOptions(),
            'error' => '',
        ], 'layouts/admin');
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $payload = [
            'id' => (int)$id,
            'colaborador_id' => $_POST['colaborador_id'] ?? '',
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'nota' => Security::sanitizeString($_POST['nota'] ?? ''),
            'periodo_referencia' => Security::sanitizeString($_POST['periodo_referencia'] ?? ''),
            'resumo' => Security::sanitizeString($_POST['resumo'] ?? ''),
        ];
        $result = AvaliacaoDesempenho::update((int)$id, $payload);
        if (!($result['ok'] ?? false)) {
            $this->view->render('admin/avaliacoes/form', [
                'csrf' => Security::csrfToken(),
                'avaliacao' => $payload,
                'colaboradorOptions' => AvaliacaoDesempenho::colaboradorOptions(),
                'error' => $result['error'] ?? 'Falha ao atualizar avaliação.',
            ], 'layouts/admin');
            return;
        }

        redirect('/admin/avaliacoes?ok=' . urlencode('Avaliação atualizada com sucesso.'));
    }

    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        AvaliacaoDesempenho::delete((int)$id);
        redirect('/admin/avaliacoes?ok=' . urlencode('Avaliação excluída com sucesso.'));
    }
}
