<?php
class AdminEmpresasController extends AdminCatalogosController
{
    public function index(): void
    {
        $this->renderIndex('empresas');
    }

    public function create(): void
    {
        $this->renderCreate('empresas');
    }

    public function store(): void
    {
        $this->handleStore('empresas');
    }

    public function edit(string $id): void
    {
        $this->renderEdit('empresas', (int)$id);
    }

    public function update(string $id): void
    {
        $this->handleUpdate('empresas', (int)$id);
    }

    public function delete(string $id): void
    {
        $this->handleDelete('empresas', (int)$id);
    }

}
