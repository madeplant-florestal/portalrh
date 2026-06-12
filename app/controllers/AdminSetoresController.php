<?php
class AdminSetoresController extends AdminCatalogosController
{
    public function index(): void
    {
        $this->renderIndex('setores');
    }

    public function create(): void
    {
        $this->renderCreate('setores');
    }

    public function store(): void
    {
        $this->handleStore('setores');
    }

    public function edit(string $id): void
    {
        $this->renderEdit('setores', (int)$id);
    }

    public function update(string $id): void
    {
        $this->handleUpdate('setores', (int)$id);
    }

    public function delete(string $id): void
    {
        $this->handleDelete('setores', (int)$id);
    }
}
