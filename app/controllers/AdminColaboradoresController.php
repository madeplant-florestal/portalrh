<?php
class AdminColaboradoresController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'cargo_id' => ctype_digit((string)($_GET['cargo_id'] ?? '')) ? (int)$_GET['cargo_id'] : null,
            'empresa_id' => ctype_digit((string)($_GET['empresa_id'] ?? '')) ? (int)$_GET['empresa_id'] : null,
            'setor_id' => ctype_digit((string)($_GET['setor_id'] ?? '')) ? (int)$_GET['setor_id'] : null,
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];

        $this->view->render('admin/colaboradores/index', [
            'colaboradores' => Colaborador::all($filters),
            'filters' => $filters,
            'summary' => Colaborador::summary(),
            'cargoOptions' => Colaborador::cargoOptions(),
            'empresaOptions' => Colaborador::empresaOptions(),
            'setorOptions' => Colaborador::setorOptions(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
    }

    public function editRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $colaborador = Colaborador::find((int)$id);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $this->view->render('admin/colaboradores/rh-form', [
            'csrf' => Security::csrfToken(),
            'colaborador' => $colaborador,
            'error' => '',
        ], 'layouts/admin');
    }

    public function updateRh(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $colaborador = Colaborador::find((int)$id);
        if (!$colaborador) {
            http_response_code(404);
            echo 'Colaborador não encontrado.';
            return;
        }

        $payload = [
            'matricula' => Security::sanitizeString($_POST['matricula'] ?? ''),
            'salario_atual' => Security::sanitizeString($_POST['salario_atual'] ?? ''),
            'data_admissao' => Security::sanitizeString($_POST['data_admissao'] ?? ''),
            'data_inicio_cargo' => Security::sanitizeString($_POST['data_inicio_cargo'] ?? ''),
        ];
        $result = Colaborador::updateRhData((int)$id, $payload);
        if (!($result['ok'] ?? false)) {
            $colaborador = array_merge($colaborador, $payload);
            $this->view->render('admin/colaboradores/rh-form', [
                'csrf' => Security::csrfToken(),
                'colaborador' => $colaborador,
                'error' => $result['error'] ?? 'Falha ao atualizar os dados de RH do colaborador.',
            ], 'layouts/admin');
            return;
        }

        redirect('/admin/colaboradores?ok=' . urlencode('Dados de RH do colaborador atualizados com sucesso.'));
    }
}
