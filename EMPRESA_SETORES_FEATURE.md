# Relacionamento Empresa -> Setor

## Objetivo

Implementar o vínculo estrutural `empresa_id` em `setores`, respeitando:

- empresa `1:N` setores
- sem tabela pivot
- seleção obrigatória de empresa no cadastro e na edição
- listagem com `JOIN` otimizado
- filtros, exportações e saneamento de legados

## Arquitetura

### Camadas adicionadas

- `app/dtos/SetorData.php`
- `app/requests/SetorRequest.php`
- `app/validators/SetorValidator.php`
- `app/repositories/EmpresaRepository.php`
- `app/repositories/SetorRepository.php`
- `app/services/SetorService.php`

### Controller especializado

- `app/controllers/AdminSetoresController.php`

Responsabilidades:

- paginação administrativa
- filtros por busca, status e empresa
- exportação `excel`, `csv` e `pdf`
- saneamento de setores legados sem empresa
- criação e edição com validação de empresa ativa

## Banco de dados

Arquivos gerados:

- `database/migrations/2026-06-16-setores-empresa.sql`
- `database/migrations/2026-06-16-setores-empresa-rollback.sql`

### Observação de compatibilidade

O requisito original pedia `empresa_id NOT NULL`, porém também exigia:

- compatibilidade com setores legados sem empresa
- filtro por setores sem empresa
- rotina de saneamento sem quebrar o sistema

Por isso, a implantação foi desenhada em duas fases:

1. migration retrocompatível adiciona `empresa_id` com `FK`, índice e regra `RESTRICT`
2. backend e UI passam a exigir empresa válida para novos cadastros e edições
3. setores legados podem ser saneados pelo painel administrativo
4. após saneamento total, o ambiente pode endurecer a coluna para `NOT NULL` em janela controlada

## Regras de negócio

- `empresa_id` é obrigatório para novos cadastros e edições
- empresa deve existir
- empresa deve estar ativa
- exclusão de empresa vinculada é bloqueada
- listagem de setores mostra empresa associada sem `N+1`

## Exportações

O módulo de setores passou a oferecer:

- Excel
- CSV
- PDF

Todos incluem:

- setor
- empresa
- slug
- status
- quantidade de cargos vinculados
- quantidade de colaboradores vinculados

## Saneamento legado

Quando existirem setores sem `empresa_id`, a listagem exibe aviso administrativo com ação de saneamento:

- seleção de empresa ativa
- atualização em lote apenas dos setores com `empresa_id IS NULL`

## Testes

Arquivos gerados:

- `tests/php/unit_setor_empresa_rules.php`
- `tests/php/integration_setores_empresa.php`
- `tests/setores-empresa-ui.spec.js`

## Ordem sugerida de deploy

1. executar `2026-06-16-setores-empresa.sql`
2. publicar o código PHP da feature
3. acessar `Setores` no painel
4. executar saneamento dos legados, se houver
5. validar filtros, exportações e edição
