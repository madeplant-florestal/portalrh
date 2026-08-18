# Backfill atomico de colaboradores

## Objetivo

Aplicar uma atualizacao unica e atomica nos dados da tabela `colaboradores`, com auditoria old/new, validacoes relacionais e rollback automatico em caso de falha.

Arquivo SQL:

- `database/migrations/2026-06-25-colaboradores-data-backfill-atomic.sql`

## O que o script atualiza

- Preenche `codigo` com `matricula` quando `codigo` estiver vazio
- Gera `codigo` tecnico baseado no `id` quando `codigo` e `matricula` estiverem vazios
- Normaliza `cpf` para somente digitos
- Converte `cpf` invalido para `NULL` quando o comprimento final nao for `11`
- Preenche `data_inicio_cargo` com `data_admissao` quando aplicavel
- Alinha `ativo` com `data_demissao` e `data_admissao` somente quando ha base suficiente para isso

## Validacoes antes do update

O script aborta a transacao quando encontrar:

- `empresa_id` sem correspondencia em `empresas`
- `cargo_id` sem correspondencia em `cargos`
- `setor_id` sem correspondencia em `setores`
- `data_demissao < data_admissao`
- duplicidade final de `codigo` dentro da mesma `empresa`

## Auditoria

Os registros alterados ficam documentados em:

- `colaboradores_data_fix_auditoria`

Campos principais:

- `execution_uuid`
- `colaborador_id`
- `old_codigo` / `new_codigo`
- `old_cpf` / `new_cpf`
- `old_data_inicio_cargo` / `new_data_inicio_cargo`
- `old_ativo` / `new_ativo`
- `update_reason`

Falhas ficam registradas em:

- `colaboradores_data_fix_error_log`

## Incidente de collation

Durante a manutencao foi identificado o erro:

- `#1267 - Combinação ilegal de collations (utf8mb4_bin,NONE) e (utf8mb4_unicode_ci,COERCIBLE) para operação '<>'`

Causa raiz:

- comparacoes textuais da migration usavam colunas legadas e expressoes/literais com collations diferentes
- em bases antigas, parte do schema podia estar em `utf8mb4_bin`
- a migration ja criava estruturas auxiliares em `utf8mb4_unicode_ci`
- isso tornava comparacoes como `<>` suscetiveis a conflito de collation

Correcao aplicada:

- as comparacoes textuais passaram a usar `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci`
- os campos textuais da tabela temporaria passaram a ser criados explicitamente em `utf8mb4_unicode_ci`
- as verificacoes de diferenca textual foram reescritas para comparacoes null-safe e consistentes

Validacao tecnica:

- `tests/php/integration_colaboradores_data_backfill_collation.php`

## Homologacao

1. Executar o SQL em homologacao.
2. Revisar o result set retornado pelo `CALL`.
3. Revisar a auditoria do lote:

```sql
SELECT *
FROM colaboradores_data_fix_auditoria
WHERE execution_uuid = @colaboradores_data_fix_execution_uuid
ORDER BY colaborador_id;
```

4. Validar se nao sobraram inconsistencias:

```sql
SELECT COUNT(*) AS invalid_date_rows
FROM colaboradores
WHERE data_admissao IS NOT NULL
  AND data_demissao IS NOT NULL
  AND data_demissao < data_admissao;

SELECT empresa_id, codigo, COUNT(*) AS total_rows
FROM colaboradores
WHERE codigo IS NOT NULL
  AND TRIM(codigo) <> ''
GROUP BY empresa_id, codigo
HAVING COUNT(*) > 1;
```

## Producao

Executar somente depois da homologacao aprovada. Como o script grava auditoria old/new por `execution_uuid`, o lote aplicado fica rastreavel para conferencia posterior.
