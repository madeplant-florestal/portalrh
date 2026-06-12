<?php
class AdminCargosController extends AdminCatalogosController
{
    public function index(): void
    {
        $this->renderIndex('cargos');
    }

    public function create(): void
    {
        $this->renderCreate('cargos');
    }

    public function store(): void
    {
        $this->handleStore('cargos');
    }

    public function edit(string $id): void
    {
        $this->renderEdit('cargos', (int)$id);
    }

    public function update(string $id): void
    {
        $this->handleUpdate('cargos', (int)$id);
    }

    public function delete(string $id): void
    {
        $this->handleDelete('cargos', (int)$id);
    }
}
