<?php
class AdminBeneficiosController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $beneficios = Beneficio::all();
        $this->view->render('admin/beneficios/index', ['beneficios' => $beneficios], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $csrf = Security::csrfToken();
        $this->view->render('admin/beneficios/form', ['csrf' => $csrf, 'beneficio' => null], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }

        $data = [
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'parceiro' => Security::sanitizeString($_POST['parceiro'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];
        if (!$data['nome']) {
            $this->view->render('admin/beneficios/form', ['csrf' => Security::csrfToken(), 'beneficio' => $data, 'error' => 'Informe o nome do benefício']);
            return;
        }

        if (!empty($_FILES['logo']['name'] ?? '') && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $filename = Upload::saveImage($_FILES['logo'], 'logos');
                $data['logo_path'] = $filename;
            } catch (\Throwable $e) {
                $this->view->render('admin/beneficios/form', ['csrf' => Security::csrfToken(), 'beneficio' => $data, 'error' => $e->getMessage()], 'layouts/admin');
                return;
            }
        }

        Beneficio::create($data);
        redirect('/admin/beneficios');
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $beneficio = Beneficio::find((int)$id);
        if (!$beneficio) { http_response_code(404); echo 'Benefício não encontrado'; return; }
        $csrf = Security::csrfToken();
        $this->view->render('admin/beneficios/form', ['csrf' => $csrf, 'beneficio' => $beneficio], 'layouts/admin');
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }
        $existing = Beneficio::find((int)$id);
        if (!$existing) { http_response_code(404); echo 'Benefício não encontrado'; return; }

        $data = [
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'parceiro' => Security::sanitizeString($_POST['parceiro'] ?? ''),
            'logo_path' => $existing['logo_path'] ?? null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];
        if (!$data['nome']) { echo 'Nome é obrigatório'; return; }

        if (!empty($_FILES['logo']['name'] ?? '') && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $filename = Upload::saveImage($_FILES['logo'], 'logos');
                $data['logo_path'] = $filename;
            } catch (\Throwable $e) {
                $this->view->render('admin/beneficios/form', ['csrf' => Security::csrfToken(), 'beneficio' => $data, 'error' => $e->getMessage()], 'layouts/admin');
                return;
            }
        }

        if (!Beneficio::update((int)$id, $data)) { echo 'Falha ao atualizar'; return; }
        redirect('/admin/beneficios');
    }

    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }
        $existing = Beneficio::find((int)$id);
        Beneficio::delete((int)$id);
        if ($existing && !empty($existing['logo_path'])) {
            $file = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . $existing['logo_path'];
            if (is_file($file)) { @unlink($file); }
        }
        redirect('/admin/beneficios');
    }
}
