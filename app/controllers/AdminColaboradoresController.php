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
        ], 'layouts/admin');
    }
}
