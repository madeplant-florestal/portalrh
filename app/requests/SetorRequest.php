<?php
class SetorRequest
{
    public string $nome;
    public string $slug;
    public int $ativo;
    public ?int $empresaId;

    public function __construct(array $input)
    {
        $this->nome = Security::sanitizeString($input['nome'] ?? '');
        $this->slug = Security::sanitizeString($input['slug'] ?? '');
        $this->ativo = !empty($input['ativo']) ? 1 : 0;

        $empresaId = $input['empresa_id'] ?? null;
        if ($empresaId === '' || $empresaId === null) {
            $this->empresaId = null;
        } else {
            $this->empresaId = (int)$empresaId;
        }
    }
}
