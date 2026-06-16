# Relacionamento Cargo x Setor

## Visão geral

Esta feature implementa a governança administrativa da pivot `cargo_setores` com:

- camada `repository` para acesso ao relacionamento
- camada `service` para validação de domínio e transações
- controller dedicado para vínculo e desvínculo
- telas administrativas em `Cargos` e `Setores`
- migration, seed idempotente e rollback

## Arquitetura

- `app/repositories/CargoSetorRepository.php`
  - acesso a dados com `PDO`
  - consultas de existência
  - listagem de vínculos por cargo e por setor
  - listagem de itens disponíveis para associação

- `app/services/CargoSetorService.php`
  - validações de entrada
  - impedimento de duplicidade
  - transações nas gravações
  - métodos de domínio:
    - `vincularCargoSetor()`
    - `desvincularCargoSetor()`
    - `listarSetoresPorCargo()`
    - `listarCargosPorSetor()`

- `app/controllers/AdminCargoSetoresController.php`
  - endpoints administrativos para vínculo em lote e remoção individual
  - proteção por `Auth::requireRole(['admin', 'rh'])`
  - proteção CSRF

## Rotas

- `POST /admin/cargos/{id}/setores/vincular`
- `POST /admin/cargos/{cargoId}/setores/{setorId}/desvincular`
- `POST /admin/setores/{id}/cargos/vincular`
- `POST /admin/setores/{setorId}/cargos/{cargoId}/desvincular`

## Interface administrativa

- `Cargos > Editar`
  - exibe setores já vinculados
  - permite remoção individual do vínculo
  - permite vínculo em lote via `multiselect`

- `Setores > Editar`
  - exibe cargos já vinculados
  - permite remoção individual do vínculo
  - permite vínculo em lote via `multiselect`

## Banco de dados

Arquivos gerados:

- `database/migrations/2026-06-16-cargo-setores.sql`
- `database/migrations/2026-06-16-cargo-setores-seed.sql`
- `database/migrations/2026-06-16-cargo-setores-rollback.sql`

## Ordem de deploy

1. Executar `2026-06-16-cargo-setores.sql`
2. Executar `2026-06-16-cargo-setores-seed.sql`
3. Publicar o código PHP da feature
4. Validar a edição de `Cargos` e `Setores`

## Normalização da seed

A massa solicitada continha nomes que não existem literalmente no cadastro-base atual. Para respeitar a regra de **não recriar cargos nem setores**, a seed faz normalização para os registros já existentes:

- `RECURSOS HUMANOS`, `DEPARTAMENTO PESSOAL` e `SEGURANÇA DO TRABALHO` foram consolidados em `RH/DP/SST`
- `FACILITIES` foi mapeado para `FACILITES`
- variações de senioridade como `JI`, `JII`, `PI` foram mapeadas para o cargo-base existente
- cargos sem equivalente literal foram aproximados para o slug operacional já cadastrado quando havia correspondência funcional clara

## Limitações conhecidas

- alguns nomes da solicitação original não existem 1:1 no cadastro atual, então a seed trabalha por equivalência funcional
- o rollback remove apenas os vínculos previstos na seed da feature
- a migration não recria nem altera `cargos` e `setores`, apenas garante a pivot e suas constraints
