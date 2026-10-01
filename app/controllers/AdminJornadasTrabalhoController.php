<?php
class AdminJornadasTrabalhoController extends AdminCatalogosController
{
    public function index(): void
    {
        $this->renderIndex('jornadas_trabalho');
    }

    public function create(): void
    {
        $this->renderCreate('jornadas_trabalho');
    }

    public function store(): void
    {
        $this->handleStore('jornadas_trabalho');
    }

    public function edit(string $id): void
    {
        $this->renderEdit('jornadas_trabalho', (int)$id);
    }

    public function update(string $id): void
    {
        $this->handleUpdate('jornadas_trabalho', (int)$id);
    }

    public function delete(string $id): void
    {
        $this->handleDelete('jornadas_trabalho', (int)$id);
    }
}
