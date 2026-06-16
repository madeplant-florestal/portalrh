<?php
class AdminCargosController extends AdminCatalogosController
{
    private CargoSetorService $cargoSetorService;

    public function __construct()
    {
        parent::__construct();
        $this->cargoSetorService = new CargoSetorService();
    }

    public function index(): void
    {
        $this->renderIndex('cargos');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $this->renderForm([
            'item' => null,
            'error' => '',
            'flashError' => '',
            'flashSuccess' => '',
            'linkedSetores' => [],
            'availableSetores' => [],
            'formAction' => '/admin/cargos/novo',
        ]);
    }

    public function store(): void
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
            CadastroOrganizacional::create('cargos', $data);
            redirect('/admin/cargos?ok=' . urlencode('Cargo cadastrado com sucesso.'));
        } catch (Throwable $e) {
            $this->renderForm([
                'item' => $data,
                'error' => $this->normalizeError($e, 'cargos'),
                'flashError' => '',
                'flashSuccess' => '',
                'linkedSetores' => [],
                'availableSetores' => [],
                'formAction' => '/admin/cargos/novo',
            ]);
        }
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $cargoId = (int)$id;

        try {
            $relationData = $this->cargoSetorService->dadosTelaCargo($cargoId);
        } catch (Throwable $e) {
            http_response_code(404);
            echo 'Cargo não encontrado';
            return;
        }

        $this->renderForm([
            'item' => $relationData['cargo'],
            'error' => '',
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'linkedSetores' => $relationData['linkedSetores'],
            'availableSetores' => $relationData['availableSetores'],
            'formAction' => '/admin/cargos/editar/' . $cargoId,
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $cargoId = (int)$id;
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $current = CadastroOrganizacional::find('cargos', $cargoId);
        if (!$current) {
            http_response_code(404);
            echo 'Cargo não encontrado';
            return;
        }

        $data = [
            'id' => $cargoId,
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'slug' => Security::sanitizeString($_POST['slug'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        try {
            CadastroOrganizacional::update('cargos', $cargoId, $data);
            redirect('/admin/cargos/editar/' . $cargoId . '?ok=' . urlencode('Cargo atualizado com sucesso.'));
        } catch (Throwable $e) {
            $relationData = $this->cargoSetorService->dadosTelaCargo($cargoId);
            $this->renderForm([
                'item' => $data,
                'error' => $this->normalizeError($e, 'cargos'),
                'flashError' => '',
                'flashSuccess' => '',
                'linkedSetores' => $relationData['linkedSetores'],
                'availableSetores' => $relationData['availableSetores'],
                'formAction' => '/admin/cargos/editar/' . $cargoId,
            ]);
        }
    }

    public function delete(string $id): void
    {
        $this->handleDelete('cargos', (int)$id);
    }

    private function renderForm(array $data): void
    {
        $this->view->render('admin/cargos/form', [
            'meta' => CadastroOrganizacional::meta('cargos'),
            'table' => 'cargos',
            'routeBase' => '/admin/cargos',
            'formAction' => $data['formAction'],
            'item' => $data['item'],
            'error' => $data['error'] ?? '',
            'flashError' => $data['flashError'] ?? '',
            'flashSuccess' => $data['flashSuccess'] ?? '',
            'csrf' => Security::csrfToken(),
            'linkedSetores' => $data['linkedSetores'] ?? [],
            'availableSetores' => $data['availableSetores'] ?? [],
        ], 'layouts/admin');
    }
}
