<?php
class SetorService
{
    private SetorRepository $setorRepository;
    private EmpresaRepository $empresaRepository;
    private SetorValidator $validator;

    public function __construct(
        ?SetorRepository $setorRepository = null,
        ?EmpresaRepository $empresaRepository = null,
        ?SetorValidator $validator = null
    ) {
        $this->setorRepository = $setorRepository ?? new SetorRepository();
        $this->empresaRepository = $empresaRepository ?? new EmpresaRepository();
        $this->validator = $validator ?? new SetorValidator($this->empresaRepository);
    }

    public function paginateAdmin(array $filters, int $page = 1, int $perPage = 15): array
    {
        return $this->setorRepository->paginateAdmin($filters, $page, $perPage);
    }

    public function exportDataset(array $filters): array
    {
        return $this->setorRepository->exportDataset($filters);
    }

    public function reportSummary(array $filters): array
    {
        return $this->setorRepository->reportSummary($filters);
    }

    public function companyOptions(): array
    {
        return $this->empresaRepository->activeOptions();
    }

    public function companyFilterOptions(): array
    {
        return $this->empresaRepository->allOptions();
    }

    public function create(array $input): int
    {
        $request = new SetorRequest($input);
        $data = $this->validator->validate($request);
        $pdo = $this->setorRepository->connection();
        $pdo->beginTransaction();

        try {
            $id = $this->setorRepository->create($data);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function update(int $id, array $input): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Setor inválido para atualização.');
        }

        if (!$this->find($id)) {
            throw new InvalidArgumentException('Setor não encontrado.');
        }

        $request = new SetorRequest($input);
        $data = $this->validator->validate($request);
        $pdo = $this->setorRepository->connection();
        $pdo->beginTransaction();

        try {
            $result = $this->setorRepository->update($id, $data);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function find(int $id): ?array
    {
        return $this->setorRepository->find($id);
    }

    public function legacyWithoutEmpresaCount(): int
    {
        return $this->setorRepository->countLegacyWithoutEmpresa();
    }

    public function listByEmpresa(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('Empresa inválida para consulta.');
        }
        if (!$this->empresaRepository->exists($empresaId)) {
            throw new InvalidArgumentException('Empresa não encontrada para consulta.');
        }

        return $this->setorRepository->listByEmpresa($empresaId);
    }

    public function sanitizeLegacyWithoutEmpresa(int $empresaId): int
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('Selecione uma empresa válida para o saneamento.');
        }
        if (!$this->empresaRepository->exists($empresaId)) {
            throw new InvalidArgumentException('A empresa selecionada para saneamento não foi encontrada.');
        }
        if (!$this->empresaRepository->isActive($empresaId)) {
            throw new InvalidArgumentException('A empresa selecionada para saneamento precisa estar ativa.');
        }

        $pdo = $this->setorRepository->connection();
        $pdo->beginTransaction();

        try {
            $count = $this->setorRepository->sanitizeLegacyWithoutEmpresa($empresaId);
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
