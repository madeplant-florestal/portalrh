<?php
abstract class AdminCatalogosController extends Controller
{
    protected function renderIndex(string $table): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];
        $meta = CadastroOrganizacional::meta($table);

        $this->view->render('admin/catalogos/index', [
            'meta' => $meta,
            'table' => $table,
            'routeBase' => '/admin/' . $table,
            'items' => CadastroOrganizacional::all($table, $filters),
            'summary' => CadastroOrganizacional::summary($table),
            'filters' => $filters,
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    protected function renderCreate(string $table, ?array $item = null, string $error = ''): void
    {
        Auth::requireRole(['admin', 'rh']);
        $meta = CadastroOrganizacional::meta($table);

        $this->view->render('admin/catalogos/form', [
            'meta' => $meta,
            'table' => $table,
            'routeBase' => '/admin/' . $table,
            'formAction' => '/admin/' . $table . '/novo',
            'item' => $item,
            'error' => $error,
            'csrf' => Security::csrfToken(),
        ], 'layouts/admin');
    }

    protected function handleStore(string $table): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $data = [
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'slug' => Security::sanitizeString($_POST['slug'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        try {
            CadastroOrganizacional::create($table, $data);
            redirect('/admin/' . $table . '?ok=' . urlencode(CadastroOrganizacional::meta($table)['singular'] . ' cadastrado com sucesso.'));
        } catch (Throwable $e) {
            $this->renderCreate($table, $data, $this->normalizeError($e, $table));
        }
    }

    protected function renderEdit(string $table, int $id, string $error = '', ?array $item = null): void
    {
        Auth::requireRole(['admin', 'rh']);
        $meta = CadastroOrganizacional::meta($table);
        $item = $item ?? CadastroOrganizacional::find($table, $id);

        if (!$item) {
            http_response_code(404);
            echo $meta['singular'] . ' não encontrado';
            return;
        }

        $this->view->render('admin/catalogos/form', [
            'meta' => $meta,
            'table' => $table,
            'routeBase' => '/admin/' . $table,
            'formAction' => '/admin/' . $table . '/editar/' . $id,
            'item' => $item,
            'error' => $error,
            'csrf' => Security::csrfToken(),
        ], 'layouts/admin');
    }

    protected function handleUpdate(string $table, int $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        if (!CadastroOrganizacional::find($table, $id)) {
            http_response_code(404);
            echo CadastroOrganizacional::meta($table)['singular'] . ' não encontrado';
            return;
        }

        $data = [
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'slug' => Security::sanitizeString($_POST['slug'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        try {
            CadastroOrganizacional::update($table, $id, $data);
            redirect('/admin/' . $table . '?ok=' . urlencode(CadastroOrganizacional::meta($table)['singular'] . ' atualizado com sucesso.'));
        } catch (Throwable $e) {
            $data['id'] = $id;
            $this->renderEdit($table, $id, $this->normalizeError($e, $table), $data);
        }
    }

    protected function handleDelete(string $table, int $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $meta = CadastroOrganizacional::meta($table);
        if (!CadastroOrganizacional::find($table, $id)) {
            http_response_code(404);
            echo $meta['singular'] . ' não encontrado';
            return;
        }

        if (!CadastroOrganizacional::delete($table, $id)) {
            redirect('/admin/' . $table . '?erro=' . urlencode($meta['singular'] . ' possui colaboradores vinculados e não pode ser excluído.'));
        }

        redirect('/admin/' . $table . '?ok=' . urlencode($meta['singular'] . ' excluído com sucesso.'));
    }

    protected function normalizeError(Throwable $e, string $table): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Não foi possível salvar o cadastro.';
        }

        if (stripos($message, 'duplicate') !== false || stripos($message, 'unique') !== false) {
            return CadastroOrganizacional::meta($table)['singular'] . ' já cadastrado com esse nome ou identificador.';
        }

        return $message;
    }
}
