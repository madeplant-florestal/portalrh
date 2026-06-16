<?php
class SetorValidator
{
    private EmpresaRepository $empresaRepository;

    public function __construct(?EmpresaRepository $empresaRepository = null)
    {
        $this->empresaRepository = $empresaRepository ?? new EmpresaRepository();
    }

    public function validate(SetorRequest $request): SetorData
    {
        $nome = trim($request->nome);
        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome do setor.');
        }

        $slug = trim($request->slug);
        if ($slug === '') {
            $slug = $this->slugify($nome);
        } else {
            $slug = $this->slugify($slug);
        }

        if ($slug === '') {
            throw new InvalidArgumentException('Nao foi possivel gerar um identificador valido para o setor.');
        }

        if ($request->empresaId === null || $request->empresaId <= 0) {
            throw new InvalidArgumentException('Selecione uma empresa válida para o setor.');
        }

        if (!$this->empresaRepository->exists($request->empresaId)) {
            throw new InvalidArgumentException('A empresa selecionada não foi encontrada.');
        }

        if (!$this->empresaRepository->isActive($request->empresaId)) {
            throw new InvalidArgumentException('A empresa selecionada está inativa e não pode ser vinculada.');
        }

        return new SetorData($nome, $slug, $request->ativo, $request->empresaId);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
