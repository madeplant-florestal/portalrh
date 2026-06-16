<?php
class AdminCargoSetoresController extends Controller
{
    private CargoSetorService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new CargoSetorService();
    }

    public function storeByCargo(string $cargoId): void
    {
        Auth::requireRole(['admin', 'rh']);
        $cargoId = (int)$cargoId;

        if (!$this->isValidCsrf()) {
            return;
        }

        try {
            $count = $this->service->vincularSetoresAoCargo($cargoId, (array)($_POST['setor_ids'] ?? []));
            redirect('/admin/cargos/editar/' . $cargoId . '?ok=' . urlencode($count . ' setor(es) vinculado(s) com sucesso.'));
        } catch (Throwable $e) {
            redirect('/admin/cargos/editar/' . $cargoId . '?erro=' . urlencode($e->getMessage()));
        }
    }

    public function destroyByCargo(string $cargoId, string $setorId): void
    {
        Auth::requireRole(['admin', 'rh']);
        $cargoId = (int)$cargoId;
        $setorId = (int)$setorId;

        if (!$this->isValidCsrf()) {
            return;
        }

        try {
            $this->service->desvincularCargoSetor($cargoId, $setorId);
            redirect('/admin/cargos/editar/' . $cargoId . '?ok=' . urlencode('Vínculo removido com sucesso.'));
        } catch (Throwable $e) {
            redirect('/admin/cargos/editar/' . $cargoId . '?erro=' . urlencode($e->getMessage()));
        }
    }

    public function storeBySetor(string $setorId): void
    {
        Auth::requireRole(['admin', 'rh']);
        $setorId = (int)$setorId;

        if (!$this->isValidCsrf()) {
            return;
        }

        try {
            $count = $this->service->vincularCargosAoSetor($setorId, (array)($_POST['cargo_ids'] ?? []));
            redirect('/admin/setores/editar/' . $setorId . '?ok=' . urlencode($count . ' cargo(s) vinculado(s) com sucesso.'));
        } catch (Throwable $e) {
            redirect('/admin/setores/editar/' . $setorId . '?erro=' . urlencode($e->getMessage()));
        }
    }

    public function destroyBySetor(string $setorId, string $cargoId): void
    {
        Auth::requireRole(['admin', 'rh']);
        $setorId = (int)$setorId;
        $cargoId = (int)$cargoId;

        if (!$this->isValidCsrf()) {
            return;
        }

        try {
            $this->service->desvincularCargoSetor($cargoId, $setorId);
            redirect('/admin/setores/editar/' . $setorId . '?ok=' . urlencode('Vínculo removido com sucesso.'));
        } catch (Throwable $e) {
            redirect('/admin/setores/editar/' . $setorId . '?erro=' . urlencode($e->getMessage()));
        }
    }

    private function isValidCsrf(): bool
    {
        if (Security::csrfCheck($_POST['csrf'] ?? '')) {
            return true;
        }

        http_response_code(400);
        echo 'CSRF inválido';
        return false;
    }
}
