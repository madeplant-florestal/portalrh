<?php
class SetorData
{
    public string $nome;
    public string $slug;
    public int $ativo;
    public ?int $empresaId;

    public function __construct(string $nome, string $slug, int $ativo, ?int $empresaId)
    {
        $this->nome = $nome;
        $this->slug = $slug;
        $this->ativo = $ativo;
        $this->empresaId = $empresaId;
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'slug' => $this->slug,
            'ativo' => $this->ativo,
            'empresa_id' => $this->empresaId,
        ];
    }
}
