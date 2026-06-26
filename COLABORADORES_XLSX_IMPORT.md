# Importacao de Colaboradores via XLSX e CSV

## Objetivo

Importar a aba `Ativos x Desligados` do arquivo `assets/colaboradores.xlsx` ou arquivos `.csv` equivalentes para a tabela `colaboradores`, com:

- validacao estrutural do arquivo
- validacao de regras de negocio
- tratamento de duplicidades
- criacao controlada de `empresas` e `cargos` ausentes
- insercao/atualizacao transacional
- relatorio JSON detalhado em `storage/imports`

## Mapeamento de colunas

| Aba Excel | Destino no banco |
| --- | --- |
| `COD` | `colaboradores.codigo` e `colaboradores.matricula` |
| `COLABORADOR` | `colaboradores.nome` |
| `EMPRESA` | `empresas.nome` -> `colaboradores.empresa_id` |
| `CPF` | `colaboradores.cpf` |
| `ADMISSÃO` | `colaboradores.data_admissao` |
| `NASC.` | `colaboradores.data_nascimento` |
| `CARGO` | `cargos.nome` -> `colaboradores.cargo_id` |
| `DEMISSÃO` | `colaboradores.data_demissao` |
| `MOTIVO RESCISÃO` | `colaboradores.motivo_rescisao` |

Observacoes:

- `ativo = 1` quando `DEMISSÃO` estiver vazio
- `ativo = 0` quando `DEMISSÃO` estiver preenchido
- `data_inicio_cargo` recebe inicialmente a mesma data de `data_admissao` em novos registros
- `setor_id` e `salario_atual` sao preservados em atualizacoes e ficam `NULL` em insercoes, quando nao houver fonte na planilha

## Regras de validacao

- arquivo `.xlsx` ou `.csv` precisa existir e ser legivel
- no caso de `.xlsx`, a aba `Ativos x Desligados` precisa existir
- cabecalhos obrigatorios precisam estar presentes
- `COD`, `COLABORADOR`, `EMPRESA`, `CPF`, `ADMISSÃO`, `NASC.` e `CARGO` sao obrigatorios por linha
- `CPF` precisa ser valido
- `ADMISSÃO` e `NASC.` precisam ser datas validas
- `DEMISSÃO` nao pode ser anterior a `ADMISSÃO`
- `MOTIVO RESCISÃO` e obrigatorio quando houver `DEMISSÃO`
- `COD` duplicado so gera rejeicao quando ocorrer para a mesma empresa
- `CPF` repetido e permitido quando representar historico de recontratacao
- o upsert identifica o registro prioritariamente por `empresa + codigo`, com apoio de `cpf + data_admissao`
- conflito de identificacao entre os criterios de busca no banco interrompe a transacao

## Execucao

Pela interface administrativa:

1. acessar `Admin > Colaboradores`
2. clicar em `Importar colaboradores`
3. selecionar um arquivo `.xlsx` ou `.csv`
4. aguardar o processamento e revisar o resumo exibido na propria tela
5. atualizar a pagina para refletir os novos totais da listagem

Pela linha de comando:

Validacao de homologacao sem persistir:

```bash
php scripts/import_colaboradores_xlsx.php --dry-run
```

Validacao apenas do arquivo e das linhas:

```bash
php scripts/import_colaboradores_xlsx.php --validate-only
```

Importacao real:

```bash
php scripts/import_colaboradores_xlsx.php
```

Arquivo e aba customizados:

```bash
php scripts/import_colaboradores_xlsx.php --file="c:/laragon/www/rhmadeplant/assets/colaboradores.xlsx" --sheet="Ativos x Desligados"
```

## Relatorio

Cada execucao gera um JSON em `storage/imports/colaboradores-import-AAAAMMDD-HHMMSS.json` com:

- total processado
- total de linhas em branco ignoradas
- total valido
- total rejeitado
- total inserido
- total atualizado
- empresas criadas
- cargos criados
- erros e avisos detalhados
- lista de registros rejeitados com causa

## Testes recomendados

Antes de producao:

1. rodar `--validate-only`
2. rodar `--dry-run` em homologacao conectada ao banco
3. revisar o JSON em `storage/imports`
4. executar a importacao real somente apos aprovacao dos dados
