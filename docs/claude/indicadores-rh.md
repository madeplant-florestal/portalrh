# Dicionário de Indicadores — Camada Analítica de RH (Fase 4)

> Consultar ao alterar `RhIndicadoresService`/`RhIndicadoresRepository`/`AdminRhIndicadoresController`
> ou a view `admin/indicadores-rh.php`, ou ao adicionar um indicador novo à camada analítica.

## Fonte de dados

Todo indicador desta camada é calculado exclusivamente a partir de `colaboradores_metadados` (o
espelho oficial do METADADOS, ver `roadmap-tecnico.md`) — nunca a partir de `colaboradores`
(tabela hub legada do Portal, usada só para cadastro/fluxos internos). A unidade básica de análise
é o **contrato** (uma linha da tabela), nunca a pessoa: uma readmissão gera múltiplos contratos
para o mesmo `codigo_pessoa`/`cpf`, e cada um é contado por sua própria vigência
(`admissao`/`demissao`), nunca deduplicado.

Os 30 colaboradores locais ainda não vinculados a `colaboradores_metadados` (2 `CONFLITO`, 4
`AMBIGUA`, 24 `SEM_CORRESPONDENCIA`, ver Fase 3.4) **não afetam** esta camada — ela não depende do
vínculo `colaboradores.metadados_id`, só da carga do espelho.

O dashboard nunca acessa o SQL Server do METADADOS em tempo real — só o espelho MySQL local, já
sincronizado. Indisponibilidade do METADADOS não derruba a tela.

## Privacidade

`RhIndicadoresRepository::buscarContratos()` nunca seleciona `cpf`, `nome`, `nascimento` ou
`salario_atual`. A camada analítica trabalha só com datas de vínculo e dimensões organizacionais
(empresa/unidade/cargo/setor/centro de custo/motivo de rescisão). Salário não é exibido nesta
primeira entrega (nem individual, nem agregado, nem ranking) — decisão explícita da Fase 4, não
uma limitação técnica.

## Qualidade dos dados observada (carga validada em 31/08/2026)

- Período: admissões de 2010-09-29 a 2026-08-25; demissões de 2020-01-07 a 2026-08-14. Volume
  relevante só a partir de ~2019 (poucas dezenas de contratos/ano antes disso).
- Nenhuma anomalia de data encontrada: 0 demissões antes da admissão, 0 no mesmo dia, 0 datas
  futuras.
- Preenchimento: `empresa`/`unidade`/`cargo` 100%; `centro_custo` 98,4%; `cpf` 99,9%;
  `motivo_rescisao_descricao` 100% dos desligados; **`setor` só 11,6%** — a maioria dos contratos
  não tem setor informado no METADADOS. Todo indicador por setor mostra "Não informado" para o
  resto, nunca descarta o registro.
- `codigo_empresa` já apareceu associado a mais de um nome de `empresa` ao longo do histórico
  (razão social alterada) — agrupamentos por empresa usam sempre `codigo_empresa` como chave
  estável, nunca o nome.

## Fórmulas

### Turnover (geral e por dimensão)

```
Turnover(%) = desligamentos_do_período ÷ média(headcount_início, headcount_fim) × 100
```

- **Numerador**: contratos com `demissao` dentro do período (inclusive nas duas pontas).
- **Denominador**: média entre o headcount no dia anterior ao início do período e o headcount no
  fim do período (mesma convenção do dashboard legado, ver
  `CollaboratorDashboardDataService::buildTurnoverSeries()` — reaproveitada aqui, generalizada
  para granularidade de contrato sobre a base oficial do METADADOS em vez da tabela `colaboradores`).
- **Tratamento de NULL**: headcount médio 0 nunca gera divisão por zero — resultado 0,0%.
- **Turnover por dimensão** (empresa/unidade/cargo/setor/centro de custo): mesma fórmula aplicada
  separadamente a cada valor do grupo (headcount e desligamentos filtrados àquele valor). Sempre
  mostrado como quantidade absoluta **e** taxa — nunca só volume, para não distorcer comparação
  entre áreas de tamanhos diferentes.
- **Limitação conhecida**: aplicada a uma janela maior que um mês (ex.: 12 meses), o número é
  cumulativo (soma os desligamentos do ano inteiro sobre um único headcount médio), por isso pode
  passar de 100% em bases pequenas com bastante rotatividade — isso é matematicamente correto pela
  fórmula, não um erro, mas não deve ser confundido com uma taxa mensal. A "evolução mensal" (ver
  abaixo) é a série comparável entre meses; o KPI "Turnover no período" é o acumulado da janela
  selecionada.

### Turnover mensal (evolução)

Mesma fórmula aplicada mês a mês dentro do período selecionado — um ponto por mês calendário,
headcount de início/fim daquele mês específico.

### Turnover precoce

```
Precoce(%) = desligamentos com (demissao - admissao) <= 90 dias ÷ total de desligamentos do período × 100
```

- Limite de 90 dias escolhido por alinhar-se ao contrato de experiência da CLT (indicador padrão
  de "atrito precoce"/adaptação) — decisão técnica documentada aqui, não uma convenção já existente
  no Portal. Ajustável em `RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS` caso o RH defina
  outro corte.
- A distribuição completa por faixa (Até 30 / 31–60 / 61–90 / 91–180 / 181–365 / Acima de 365 dias)
  fica sempre visível ao lado do headline, para granularidade.
- Desligamentos sem `admissao` ou `demissao` válida, ou com `demissao < admissao` (nenhum caso
  observado na carga atual), são ignorados no cálculo de dias — não quebram o indicador, só não
  entram em nenhuma faixa.

### Headcount em uma data D

```
Ativo em D  <=>  admissao <= D  E  (demissao IS NULL OU demissao >= D)
```

No próprio dia do desligamento o contrato ainda conta como ativo (convenção: `demissao` é a última
data trabalhada). Difere levemente do helper legado
`CollaboratorDashboardDataService::isActiveOnDate()` (que usa `demissao > D`, excluindo o próprio
dia) — a diferença nunca alterou nenhum número observado na carga real (zero casos de data de
referência coincidindo exatamente com uma `demissao`), mas fica registrada aqui para não ser
"redescoberta" como divergência no futuro.

### Motivos de rescisão

Ranking simples: contagem e percentual de `motivo_rescisao_descricao` entre os desligamentos do
período, ordenado do mais para o menos frequente. A descrição nunca é renomeada/agrupada — o valor
original do METADADOS é sempre exibido tal como veio.

### Tempo de permanência (colaboradores ativos)

Média **e** mediana dos dias entre `admissao` e a data de referência, calculadas só sobre contratos
ativos na data de referência — a mediana é reportada ao lado da média porque poucos contratos muito
antigos (dezenas de anos) distorceriam a média sozinha. Distribuição por faixa: Até 1 ano / 1 a 3
anos / 3 a 5 anos / 5 a 10 anos / Acima de 10 anos.

## Granularidade e filtros

- Granularidade: contrato individual (linha de `colaboradores_metadados`).
- Filtros disponíveis: período (presets: últimos 12/6 meses, ano atual, ano anterior), empresa
  (por `codigo_empresa`), unidade (por `codigo_unidade`), cargo, setor, centro de custo. Filtros de
  dimensão se combinam por E lógico; não há dependência cascata entre empresa→unidade nesta
  primeira versão (uma combinação sem contratos simplesmente mostra a tela vazia com aviso).
- O parâmetro de dimensão do painel "Quadro atual e turnover por dimensão" é independente dos
  filtros — permite ver a distribuição/turnover agrupados por qualquer uma das 5 dimensões sem
  reaplicar os filtros de recorte.

## Implementação

- `RhIndicadoresRepository` — única camada que toca banco; lê `colaboradores_metadados` filtrada
  por dimensão, nunca por data (o histórico completo é necessário para headcount/turnover/tempo de
  permanência).
- `RhIndicadoresService` — todo o cálculo é `static` sobre arrays já carregados (mesmo padrão de
  `ColaboradorMetadadosReconciliationService::analyze()`), testável sem banco.
- `AdminRhIndicadoresController` (`/admin/indicadores-rh`, papéis `admin`/`rh`/`viewer` — mesmo
  nível de acesso do dashboard gerencial existente em `/admin`) — resolve filtros/período do
  `$_GET`, delega ao service, nunca calcula indicador na view.
- `app/views/admin/partials/chart-helpers.php` — helpers de gráfico SVG puro (extraídos de
  `admin/dashboard.php`, reaproveitados aqui) — nenhuma biblioteca de charting nova.

## Limitações conhecidas desta primeira versão

- Sem dependência cascata empresa→unidade nos filtros.
- Sem exportação (CSV/PDF) dos indicadores.
- `setor` tem cobertura baixa (11,6%) na origem — a maior parte de qualquer corte por setor cai em
  "Não informado" até o METADADOS preencher mais contratos.
- Os 30 colaboradores locais sem vínculo continuam pendentes de reconciliação (Fase 3.4) — não
  bloqueiam esta camada, mas também não são "corrigidos" por ela.
