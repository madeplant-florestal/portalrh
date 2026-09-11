# Roadmap Técnico — RH Madeplant

> Consultar para planejamento técnico ou ao tocar um módulo em transição (migração incremental,
> Kanban de Solicitações de Vaga, integração com o METADADOS). Reflete trabalho observado, sem
> objetivos especulativos.

## Resumo — o que está concluído vs. o que é decisão futura em aberto

**Concluído e estável** (não precisa de decisão nova para continuar existindo): migração
Repository/Service em Setor/CargoSetor/parte de Empresa e webhooks; consolidação de schema de
`colaboradores` (2026-06-23/06-25); import XLSX de colaboradores; webhooks de recrutamento;
relacionamento Cargo×Setor; Kanban de Solicitações de Vaga; integração METADADOS Fases 1 a 3.4
(consumo + espelho + ponte estrutural + reconciliação real + aplicação dos 378 vínculos seguros),
camada analítica de RH (Fase 4, `/admin/indicadores-rh`), a missão corretiva de pureza da base
analítica (saneamento dos 6 contratos de `RHTESTE` + proteção `origem_metadados` contra nova
mistura de origem), a sincronização operacional Etapa 1 (histórico `metadados_sync_execucoes` +
botão "Atualizar dados" no Dashboard) e a Fase 5.1A da Estrutura Organizacional (Empresas e
Unidades como dimensões oficiais sincronizadas + códigos oficiais de setor/cargo/centro de custo
no espelho) — leia a seção dedicada abaixo para detalhes e para o que **ainda não** foi feito
dentro dessa integração.

**Decisão técnica futura, ainda em aberto** (exige conversa dedicada antes de agir): consolidação
definitiva de `schema.sql`/`recrutamento.sql`/migrations em uma única fonte; migração do restante
dos models legados para Repository/Service (sem prazo, módulo a módulo); aplicação dos 4 vínculos
seguros liberados pelo saneamento e saneamento dos 2 `CONFLITO`/24 `SEM_CORRESPONDENCIA`
remanescentes; migração de telas legadas para a nova fonte e descontinuação do cadastro duplicado;
resolução do risco de `usuario_colaboradores.colaborador_id` sob readmissão.

## Migração incremental para Repository/Service/DTO

Já iniciada em `Setor`/`CargoSetor`/parte de `Empresa`, nos webhooks de recrutamento e na
integração METADADOS. O restante dos módulos (Colaborador, SolicitacaoVaga, MovimentacaoPessoal,
Candidatura, Vaga, Beneficio, AvaliacaoDesempenho, User) segue no padrão legado. Sem prazo para
migrar o restante — trate módulo a módulo, só quando uma mudança de negócio real justificar tocar
naquele módulo.

## Consolidação de schema

Trabalho recente em normalizar dados de `colaboradores` (migrations `2026-06-23` e `2026-06-25`):
campos `codigo`, `cpf`, `data_inicio_cargo`, `ativo` foram alvo de backfill determinístico e
atômico com auditoria. A causa raiz (múltiplas fontes de schema, ver `arquitetura.md`) permanece;
qualquer trabalho futuro de consolidação de `schema.sql`/`recrutamento.sql`/migrations deve ser
tratado como mudança de infraestrutura de dados, com aprovação isolada (ver `checklists.md`).

## Importação de colaboradores via XLSX

Já implementada (`COLABORADORES_XLSX_IMPORT.md`, `SpreadsheetXlsxReader`,
`CollaboratorSpreadsheetImportService`), com regras específicas de reingresso ("rehire rules")
cobertas por teste de integração dedicado.

## Webhooks de recrutamento

Já implementada (`RECRUITMENT_WEBHOOKS_API.md`) para notificar sistemas externos sobre eventos do
pipeline de recrutamento, com retry de eventos falhos (`retryEvent`) e processamento assíncrono via
endpoint `process-pending` chamado manualmente/por cron externo (não há worker/queue interno).

## Relacionamento Cargo × Setor

Já implementado (`CARGO_SETORES_FEATURE.md`) com padrão Repository/Service completo — use como
referência de "melhor exemplo" da geração nova ao propor um módulo novo.

## Kanban de Solicitações de Vaga (sprint 2026-08-25)

Segundo Kanban do sistema, acompanhando a situação **operacional** da vaga solicitada pelo gestor
(`Em aprovação/Aprovada/Em recrutamento/Em processo seletivo/Fechada/Cancelada`) — deliberadamente
independente do Kanban de Recrutamento e Seleção (`AdminPipelineController`/`PipelineStage`/
`Candidatura`), que acompanha candidatos. Implementado como um segundo stack completo, não como
generalização do primeiro: `SolicitacaoVagaStage` (catálogo de etapas, tabela
`solicitacao_vaga_stages`), `SolicitacaoVagaPipelineService` (movimentação com
lock/transação/validação, tabela `solicitacao_vaga_kanban_historico`) e
`SolicitacaoVagaStageValidator` (campos obrigatórios por etapa — hoje só `motivo_cancelamento` na
etapa `cancelada`), além de um bloco próprio em `assets/admin.js` (`initSolicitacaoVagaKanban`,
seletores `data-sv-kanban-*`) que não toca em `initKanban()`. A coluna
`solicitacoes_vaga.situacao_kanban_id` é **desacoplada de propósito** de `status_fluxo` (que
continua exclusivo do fluxo de aprovação líder/RH já existente): nenhuma automação sincroniza as
duas hoje — a movimentação no Kanban é sempre manual via drag-and-drop. Layout horizontal via
classes próprias `.sv-kanban-board`/`.sv-kanban-column-shell` (nunca reaproveitar `.kanban-board`
diretamente, para não acoplar aos dois Kanbans). Ver migration
`2026-08-25-solicitacao-vaga-kanban.sql` para o backfill determinístico aplicado aos registros
existentes (mapeamento `status_fluxo → situacao_kanban_id`, com histórico explícito para os casos
migrados para `cancelada`, sem inventar motivo de negócio). A relação entre `solicitacoes_vaga` e
`vagas`/`candidaturas` continua não implementada por decisão explícita desta sprint — arquitetura
deixada preparada, não forçada.

## Contexto organizacional do usuário (sprint 2026-09-10, Etapa 1)

`usuarios` passou a carregar o contexto organizacional além da identidade/autorização:

- **`usuarios.cargo_id`** — FK `→ cargos(id) ON DELETE SET NULL`, nullable. É o **Cargo principal
  oficial** do usuário (1 só, sem cargo por setor nesta fase). Nunca guarda `codigo_cargo` solto.
- **`usuario_setores`** — N:N usuário ↔ setores de atuação. Colunas: `usuario_id`, `setor_id`,
  `principal TINYINT(1)`, `origem ENUM('METADADOS','MANUAL')`, `created_at`/`updated_at`.
  `UNIQUE(usuario_id, setor_id)`, FK usuário `ON DELETE CASCADE`, FK setor `ON DELETE RESTRICT`.
- Migration `2026-09-10-usuarios-contexto-organizacional.sql` (+ rollback). Compatível MySQL 8.4 /
  MariaDB 11.8. Aplicada em produção em 10/09/2026 (puramente estrutural — baseline de 29 usuários
  inalterado, `usuario_setores` criada vazia, nenhum backfill). Código publicado na sequência.

Regras (todas na camada de aplicação — `UsuarioContextoOrganizacionalService`, geração nova):

- **Com vínculo `colaborador_metadados_id`**: Cargo e Setor principal são **herdados** dos códigos
  oficiais do contrato (`colaboradores_metadados.codigo_cargo → cargos.codigo_cargo`,
  `codigo_setor → setores.codigo_setor`). Enquanto o vínculo existir, Cargo e — se o contrato
  informa Setor — Setor principal ficam **somente leitura**. Sem `codigo_setor` no contrato: **sem
  inferência** (nem CC, nem nome, nem fuzzy); RH escolhe o principal manualmente e a tela mostra
  "Setor não informado no METADADOS".
- **Sem vínculo**: RH/Admin define Cargo e Setores manualmente, aceitando **só registros oficiais**
  (`codigo_* IS NOT NULL` — via `CatalogoMetadadosRepository::listarOficiais()`/`oficialPorId()`).
  Os 22 cargos / 4 setores legados nunca aparecem nos seletores nem são aceitos no backend.
- **1 Setor principal por usuário** — garantido no serviço, dentro da transação (nada de índice
  único parcial: suporte divergente MySQL/MariaDB).
- **`origem`**: o principal herdado do contrato é `METADADOS`; qualquer setor concedido por RH é
  `MANUAL`. `aplicarContextoDoVinculo()` (chamado por `User::vincularMetadados`) sincroniza só a
  linha `METADADOS` e **nunca destrói** linhas `MANUAL`. `aoDesvincular()` reetiqueta as linhas
  `METADADOS → MANUAL` (nada é apagado; `usuarios.cargo_id` é mantido) e libera a edição — só
  como consequência de uma ação **confirmada explicitamente** pelo admin (`confirmar_desvinculo=1`
  exigido no controller; a tela mostra aviso + `confirm()`). Não há coluna de origem para
  `cargo_id`; após o desvínculo a tela deixa de exibir "Herdado do METADADOS".
- Não há automação periódica de re-sincronização do contexto (modelagem preparada, execução não).
- Sem backfill em massa nesta etapa. Sem filtro global por Setor ainda (só estrutura + dados).

Débito de collation da Fase 5.2 (`utf8mb4_general_ci` × `utf8mb4_uca1400_ai_ci`) permanece
registrado; a resolução do contexto compara valores já lidos do espelho contra o catálogo via
parâmetro vinculado (coercível), então não dispara `#1267`.

Tela: bloco "Contexto organizacional" em `app/views/admin/usuarios/show.php` (Cargo principal /
Setor principal / Setores adicionais), ícones SVG inline no padrão do `layouts/admin.php`. Rota
`POST /admin/usuarios/{id}/contexto-organizacional` — **admin + RH** desde a Etapa 2 (ver abaixo).
`pode_solicitar_vaga` e `aprovador_usuario_id` seguem intocados e independentes de cargo/setor.

## Solicitação de Vaga adaptada ao Contexto Organizacional (sprint 2026-09-11, Etapa 2)

Com a Etapa 1 publicada, a Solicitação de Vaga passou a usar o contexto organizacional do usuário
em vez de `usuario_colaboradores`/`cargo_setores`:

- **Solicitante**: usuário comum é sempre a própria sessão — qualquer `solicitante_usuario_id` no
  POST é **ignorado silenciosamente** (nunca erro que confirme a tentativa). Admin/RH/supervisor
  pode abrir em nome de outro usuário elegível (ativo + `pode_solicitar_vaga=1`, ou ele próprio
  Admin/RH/supervisor) — `SolicitacaoVaga::resolveSolicitanteUsuarioId()`. O aprovador da 1ª etapa
  (`resolveApprover()`) passou a ser resolvido pelo **solicitante**, nunca mais pelo ator.
- **Setor da vaga**: vem de `usuario_setores` do solicitante (`resolverSetorSolicitacao()`), nunca
  de uma lista global nem de `usuario_colaboradores`. 0 Setores bloqueia a criação com mensagem
  clara; 1 Setor resolve automaticamente (ignora o que vier no POST); vários exigem escolha entre
  os autorizados. Todo `usuario_setores.setor_id` já é oficial por construção (Etapa 1).
- **Cargo da vaga**: catálogo oficial do METADADOS
  (`CatalogoMetadadosRepository::oficialPorId()`), **independente** do Setor e do Cargo do
  solicitante — decisão explícita da Etapa 2: **sem gate `cargo_setores`** (removido de
  `validateForSubmission()`; `SolicitacaoVaga::cargoBelongsToSetor()`/`setorHasAvailableCargos()`
  foram apagados). A tabela/feature `cargo_setores` continua existindo para outros usos
  (`AdminCargoSetoresController`), só deixou de ser consultada aqui.
- **Centro de Custo**: `solicitacoes_vaga.centro_custo_id` ficou `NULL`-ável (migration
  `2026-09-11-solicitacoes-vaga-centro-custo-opcional.sql`, dev only). Depende só do Setor
  resolvido (`resolverCentroCusto()`): Setor sem Centro de Custo cadastrado → `NULL` aceito sem
  bloqueio (é o caso de todos os Setores oficiais hoje); Setor com um ou mais cadastrados →
  seleção continua obrigatória e restrita àquele Setor. `findAccessible()` passou a fazer
  `LEFT JOIN centros_custo` (era `INNER JOIN`).
- **Kanban**: `allForKanban()` trocou `INNER JOIN colaboradores` (gestor legado) por `LEFT JOIN` +
  `COALESCE(g.nome, su.nome)` — mesmo padrão já usado em `allForUser()`/`findAccessible()`. Sem
  isso, solicitações novas (gestor legado sempre `NULL`) sumiam do Kanban. Nenhuma outra regra do
  Kanban (estágios, `status_fluxo`, `situacao_kanban_id`, histórico) foi alterada.
- **Auditoria "solicitante × criador"**: decisão explícita — reaproveitar
  `solicitacao_vaga_auditoria` (`actor_usuario_id` no evento `created`) em vez de criar
  `criado_por_usuario_id`. `actor_usuario_id` é sempre quem executou a operação;
  `solicitacoes_vaga.solicitante_usuario_id` é sempre quem é o solicitante de negócio — quando
  Admin/RH cria em nome de outro, os dois divergem; no auto-atendimento, coincidem.
- **RH ganhou acesso ao bloco de Contexto Organizacional** em `AdminUsuariosController` (`show`,
  `vincularMetadados`, `updateContextoOrganizacional`, `buscarMetadados` → `admin`+`rh`) para poder
  corrigir a ausência de Setor de um solicitante. O resto do CRUD de usuários (perfil, status,
  senha, exclusão) continua **admin-only** — decisão explícita para não expandir a ACL de RH além
  do necessário. A view usa uma flag `isAdminAtor` para esconder as seções fora do escopo de RH.
- `formDependencies()` ganhou chaves aditivas (`cargos_oficiais`, `elegiveis_solicitantes`,
  `pode_escolher_solicitante`, `solicitante_contexto`) — as chaves antigas (`setores`, `cargos`
  com `setor_ids`) **não foram alteradas nem removidas**: continuam alimentando o filtro do Kanban
  (`AdminSolicitacoesVagaKanbanController`), que não foi tocado nesta etapa.
- Endpoint novo: `GET /admin/solicitacoes-vaga/solicitante-contexto/{usuarioId}` (admin/rh) —
  JSON com Cargo/Setores/Centros de Custo do solicitante escolhido, usado via `fetch()` quando
  Admin/RH troca o solicitante no formulário (mesmo padrão do autocomplete de vínculo METADADOS).

## Integração com METADADOS (sistema oficial de RH/DP, SQL Server)

Decisão: o METADADOS passa a ser a fonte oficial de dados de colaboradores; o Portal RH deixará
**gradualmente** de manter cadastro duplicado, mas `colaboradores` **não foi removida nem
alterada**. Plano de transição em 5 fases (consumir + espelhar; comparar; mapear vínculos; migrar
telas; descontinuar cadastro duplicado só quando não houver mais dependência crítica). **Regra
arquitetural definitiva, válida para todas as fases: a conexão com o METADADOS é somente leitura.**
`MetadadosDatabase`/`MetadadosSyncService::fetchSourceRows()` executam exclusivamente um `SELECT`
(a constante `QUERY`); nenhum INSERT/UPDATE/DELETE/MERGE/TRUNCATE/ALTER/CREATE/DROP/EXEC é ou deve
ser emitido contra o SQL Server do METADADOS — todas as gravações da sincronização acontecem só no
MySQL local. Se corrigir um dado oficial for necessário, a correção é feita no METADADOS pela
equipe responsável, nunca pelo Portal.

**Fase 1 — consumo + espelho.** Tabela espelho de **leitura** `colaboradores_metadados` (uma linha
por CONTRATO, não por pessoa — readmissão gera nova linha, nunca sobrescreve; chave técnica
`codigo_empresa + codigo_unidade + numero_contrato`, nunca CPF isolado — ver migration
`2026-08-27-colaboradores-metadados.sql`), `MetadadosDatabase` (conexão SQL Server dedicada, só
usada pela sincronização, nunca em request normal do Portal), `MetadadosSyncService` (upsert
idempotente, nunca faz `DELETE` de vínculo histórico) + `ColaboradorMetadadosRepository`, e
`scripts/sync_metadados_colaboradores.php` (CLI, agendado externamente — mesmo padrão dos webhooks
de recrutamento e do import XLSX). `MetadadosSyncService::fetchSourceRows()` (lê do SQL Server) e
`::applyRows()` (upsert em MySQL) são deliberadamente separados para a lógica de upsert ser
testável sem depender do driver/conectividade — ver
`tests/php/integration_colaborador_metadados_sync.php`. Nenhuma FK nova aponta para
`colaboradores_metadados` nesta fase. Risco arquitetural já identificado e registrado para as
próximas fases: `usuario_colaboradores` tem `UNIQUE KEY` em `colaborador_id` (1 usuário de login ↔
1 colaborador) — isso não sobrevive a readmissão sem uma camada de vínculo
`colaborador_local ↔ colaboradores_metadados` (endereçada parcialmente na Fase 3.2, ver abaixo, mas
`usuario_colaboradores` em si continua intocado).

**Fase 2 — validação real (2026-08-27).** Validada contra `RHTESTE` (SQL Server real):
conectividade, chave técnica `EMPRESA+UNIDADE+CONTRATO` sem duplicidade, `RHPESSOAS.CPF` como
`varchar(11)` (preserva zero à esquerda), JOIN de `RHCENTROSCUSTO1` corrigido para casar só por
`CENTROCUSTO1` (sem `UNIDADE`), e `setor = NULL` mantido fiel à origem quando a origem não tem o
dado. Idempotência real confirmada. **Pendência de infraestrutura, não implementada**: a validação
usou a credencial `sa` do SQL Server só temporariamente — antes de produção é obrigatório criar um
usuário dedicado ao Portal RH com privilégio exclusivo de `SELECT` nas tabelas `RHCONTRATOS`,
`RHPESSOAS`, `RHEMPRESAS`, `RHUNIDADES`, `RHCARGOS`, `RHSETORES`, `RHCENTROSCUSTO1`,
`RHMOTIVOSRESCISOES` — infraestrutura do SQL Server, exige autorização própria.

**Fase 3 — auditoria (2026-08-27/28).** Todos os 7 JOINs da query revalidados contra o banco real
`RHMADEPLANT` (727 contratos) com 100% de correspondência técnica — nenhuma correção pendente. A
tabela espelho ganhou `salario_atual` (de `RHCONTRATOS.SALARIOCONTRATUAL` — escolhido em vez de
`SALARIOMES`, numericamente idêntico em produção, por representar semanticamente o salário-base
contratual, nunca total recebido no mês) e `data_inicio_cargo` (de
`RHCONTRATOS.DATAULTALTCARGO`, sem fallback para `admissao` — são conceitos diferentes quando há
promoção/mudança de cargo). Ver migration `2026-08-27-colaboradores-metadados-salario-cargo.sql`.
Histórico salarial e histórico de cargo continuam fora de escopo — o espelho reflete só o estado
atual do vínculo. **Nota de compatibilidade**: o MySQL 8.4.3 deste ambiente não aceita
`ADD COLUMN IF NOT EXISTS`/`DROP COLUMN IF EXISTS` (ver `padroes-codigo.md`). A auditoria completa
de dependências de `colaboradores` concluiu que `colaboradores.id` já representa CONTRATO, não
pessoa (CPFs duplicados na base local, cada um com datas de admissão/demissão não sobrepostas) — o
mesmo grão de `colaboradores_metadados`, o que facilita a reconciliação.

**Fase 3.2 — ponte estrutural + relatório de reconciliação (2026-08-28).** `colaboradores` passa a
representar o vínculo local estável e ganha `colaboradores.metadados_id` (migration
`2026-08-28-colaboradores-metadados-id.sql`) — relação 0..1 ↔ 1 com `colaboradores_metadados.id`,
`UNIQUE` (permite múltiplos `NULL`), `ON DELETE RESTRICT` (nunca `CASCADE` — o espelho não deve
apagar um colaborador local silenciosamente). `colaboradores.id` **nunca** é substituído pelo id do
espelho; continua sendo a referência das 9 FKs existentes, inalteradas.
`ColaboradorMetadadosReconciliationService` (`app/services/`) só analisa e classifica
(`CORRESPONDENCIA_SEGURA`/`_PROVAVEL`/`AMBIGUA`/`SEM_CORRESPONDENCIA`/`JA_VINCULADO`/`CONFLITO`) —
**nunca escreve `metadados_id`**. CPF nunca decide o vínculo sozinho (readmissão): a hierarquia é
CPF → data de admissão → data de demissão → nascimento como validação (nascimento claramente
divergente sempre vira `CONFLITO`, mesmo com CPF+admissão batendo); nunca escolhe "o mais recente"
nem "o ativo" automaticamente quando há ambiguidade.
`scripts/reconciliar_colaboradores_metadados.php` é somente leitura por padrão, sem flag de
aplicação; gera relatório detalhado em CSV com CPF mascarado (só os últimos 4 dígitos) em
`storage/reconciliation/` (fora do Git), sem salário/dados bancários.

**Fase 3.3 — primeira carga real + reconciliação real (2026-08-28).** Sincronização real dos 727
contratos de `RHMADEPLANT` executada e validada (idempotência confirmada). Reconciliação real
rodada contra os 408 colaboradores locais: resultado de referência — 380 `CORRESPONDENCIA_SEGURA`,
0 `CORRESPONDENCIA_PROVAVEL`, 4 `AMBIGUA` (2 candidatos cada, readmissão), 24
`SEM_CORRESPONDENCIA` (CPF local ausente/inválido), 0 `CONFLITO`, 0 `JA_VINCULADO`. Bug de
relatório corrigido nesta fase: o ramo `AMBIGUA` de `ColaboradorMetadadosReconciliationService`
usava o operador `+` entre arrays, que preserva o valor do lado esquerdo — `quantidade_candidatos`
ficava travado em `0` mesmo com candidatos reais; corrigido para `array_merge()` (impacto era só
diagnóstico, nenhuma classificação mudou). **`colaboradores.metadados_id` continua 100% `NULL`** —
nenhum vínculo foi aplicado em nenhuma fase até aqui.

**Fase 3.4 — colisão global de `metadados_id` + aplicação real dos 378 vínculos seguros
(2026-08-31).** `flagDuplicateLinks()` generalizado: antes só detectava colisão entre resultados
`JA_VINCULADO`; passou a agrupar por `metadados_id_candidato` entre `SEGURA`/`PROVAVEL`/
`JA_VINCULADO` juntos — cobre o caso real de CPF duplicado na base local resolvendo ao mesmo
único candidato do espelho (2 colaboradores promovidos a `CONFLITO`, nunca escolhido vencedor).
`LinkService::apply()` corrigido para validar integridade **escopada ao próprio plano**, nunca
mais comparando o total global de `colaboradores.metadados_id` preenchidos — a checagem antiga
quebrava qualquer aplicação incremental depois da primeira. Aplicados os 378 vínculos
`CORRESPONDENCIA_SEGURA` reais (`colaboradores.metadados_id` preenchido, 378 distintos, 0
órfãos); 30 permanecem sem vínculo (2 `CONFLITO`, 4 `AMBIGUA` à época, 24
`SEM_CORRESPONDENCIA`) — deliberadamente não tratados nesta fase.

**Fase 4 — camada analítica de RH (2026-08-31).** `RhIndicadoresRepository`/`RhIndicadoresService`
+ dashboard `/admin/indicadores-rh`, alimentados exclusivamente por `colaboradores_metadados`
(nunca `colaboradores`, nunca SQL Server em tempo real). Dicionário completo de fórmulas/
qualidade/limitações em `indicadores-rh.md`.

**Missão corretiva — pureza da base analítica (2026-08-31).** Auditoria pós-Fase-4 encontrou
733 contratos no espelho contra os 727 oficiais de `RHMADEPLANT` auditados na Fase 3. Causa raiz
determinística (sem depender de CPF/nome/heurística de ativo): 6 contratos sincronizados nas
Fases 1/2/3.1 a partir de `RHTESTE` (quando `local.php` apontava para lá) nunca tiveram sua chave
técnica tocada pelo upsert real da Fase 3.3 contra `RHMADEPLANT` — provado pelos próprios logs de
sincronização (`storage/imports/metadados-sync-2026082*.json`: dos 40 contratos de `RHTESTE`,
34 foram encontrados/atualizados pela sincronização real — 33 updated + 1 unchanged —, sobrando
exatamente 6 nunca tocados). Confirmado que nenhum dos 378 vínculos reais apontava para esses 6;
removidos em transação única após snapshot técnico (`storage/reconciliation/saneamento-metadados-
rhteste-*.json`, sem PII). Espelho voltou a 727/192/535, batendo exatamente com a auditoria
oficial da Fase 3. Efeito colateral esperado e verificado: 4 colaboradores locais que antes
reconciliavam como `AMBIGUA` (CPF batendo com 2 candidatos no espelho) passaram a `SEGURA` — um
dos 2 candidatos "extras" era um dos 6 contratos de `RHTESTE` removidos, criando ambiguidade
artificial; esses 4 novos vínculos seguros **não foram aplicados** nesta missão (fora de escopo,
requer nova autorização explícita).

**Proteção arquitetural contra nova mistura de origem**: `colaboradores_metadados` ganhou a coluna
`origem_metadados` (migration `2026-08-31-colaboradores-metadados-origem.sql`, backfill dos 727
como `RHMADEPLANT`), preenchida a partir de `MetadadosDatabase::sourceLabel()` (o `Database=` do
DSN ativo em `local.php`/`build.php` — nunca um valor digitado à parte, para nunca divergir da
conexão real). `MetadadosSyncService::applyRows()` agora chama `originConflict()` **antes** de
escrever qualquer linha: se o espelho já contém uma origem diferente da desta sincronização, a
sincronização inteira é recusada (nenhuma escrita parcial) a menos que
`scripts/sync_metadados_colaboradores.php --permitir-origem-mista` seja passado explicitamente.
Isso não impede o uso de `RHTESTE` em desenvolvimento — um espelho que só conhece `RHTESTE` nunca
gera conflito consigo mesmo; o conflito só existe quando origens genuinamente diferentes tentam
coexistir sem decisão explícita.

**Fase 4 (produção) — sincronização segura RHMADEPLANT → Portal RH sem SQL Server exposto
(2026-08-31).** Diagnóstico: migrations nunca rodam automaticamente em deploy (nem `.cpanel.yml`
nem `deploy.ps1`/`scripts/deploy_quick.ps1` executam SQL — só copiam arquivos; o único setup de
schema é o instalador web de primeira instalação); produção tinha as 5 migrations do METADADOS
aplicadas manualmente mas `colaboradores_metadados` vazia, e — hospedagem cPanel compartilhada —
sem rota de rede até o SQL Server interno (`SRVCIGAMDB`) nem `pdo_sqlsrv`. Arquitetura implementada
para não exigir isso: um **sender** (`scripts/sync_metadados_producao.php`), rodando dentro da
rede Madeplant, reaproveita `MetadadosSyncService::fetchSourceRows()` (mesmo SELECT/normalização
de sempre) e envia o lote assinado por **HTTPS + HMAC-SHA256** (`MetadadosSyncSignature`, mesmo
esquema conceitual dos webhooks de recrutamento — timestamp + corpo, `hash_equals()`, janela de
replay configurável) a um **endpoint receptor** novo,
`POST /internal/metadados/colaboradores/sync` (`InternalMetadadosSyncController` +
`MetadadosSyncIngestService`), fora do gate de sessão de `/admin`, sem HTML, sem GET. O receptor
nunca confia no payload só por estar autenticado: `MetadadosSyncRequestValidator` rejeita o lote
inteiro (antes de qualquer escrita) por estrutura inválida, contagem divergente ou chave lógica
duplicada; a persistência delega inteiramente a `MetadadosSyncService::applyRows()` já validado —
mesma transação única, mesmo upsert idempotente, mesma proteção `origem_metadados` contra mistura
RHTESTE/RHMADEPLANT (Fase corretiva de pureza), nada duplicado. Segredo/URL do endpoint vivem em
`metadados_sync.shared_secret`/`endpoint_url` (config, só em `local.php`, nunca no Git).

**Primeira carga real de produção — CONCLUÍDA (01/09/2026).** Origem oficial `RHMADEPLANT`, 728
contratos sincronizados (191 ativos, 537 desligados), 728 vínculos únicos. Reconciliação inicial
concluída: 385 registros locais em `colaboradores`, 385 vinculados, 0 sem correspondência, 0
conflitos, 0 ambiguidades. O dashboard `/admin/indicadores-rh` já consome `colaboradores_metadados`
em produção.

**Sincronização operacional — Etapa 1 (camada Portal RH, 01/09/2026).** Prepara a operação
contínua sem mudar a arquitetura de segurança (SQL Server continua só-leitura e inacessível ao
servidor público). Adiciona:
- Migration `2026-09-01-metadados-sync-execucoes.sql` — tabela `metadados_sync_execucoes`, uma
  linha por sincronização recebida (status, origem, contadores inseridos/atualizados/inalterados/
  erros, hash do lote, mensagem técnica sanitizada, horários). NUNCA guarda segredo, senha,
  payload, CPF, nome, salário. Puramente aditiva; aplicada manualmente em produção.
- `MetadadosSyncExecucaoRepository` — escrita/leitura do histórico + `sanitizarMensagem()`
  (mascara hex longo, trunca em 500) + `ultimaSincronizacaoValida()` (fonte do "Última
  atualização" do dashboard).
- `MetadadosSyncIngestService` — passa a registrar cada sincronização válida no histórico (sucesso/
  sucesso_com_erros) e cada falha pós-autenticação (ex.: conflito de origem) como `falha`
  sanitizada. O registro é isolado em try/catch: nunca quebra uma sincronização já aplicada. HMAC,
  replay, TLS, validação, transação e idempotência **intocados**.
- `MetadadosSyncRequestValidator` — aceita um campo **opcional** `correlacao_id` (UUID) no
  envelope; retrocompatível com os senders atuais.
- `scripts/sync_metadados_producao.php` — opção opcional `--correlacao-id` (repassada pela
  orquestração numa sincronização manual do Dashboard); sem ela o comportamento é idêntico.
- `AdminMetadadosSyncController` + rotas `POST /admin/indicadores-rh/sincronizar` e
  `GET /admin/indicadores-rh/sincronizar/status` — disparo sob demanda e polling de andamento.
  Auth de sessão + `requireRole(['admin','rh'])` + CSRF + rate-limit + trava de execução
  simultânea. O Portal só cria a linha da solicitação e aciona o **webhook da orquestração
  interna (n8n)** por HTTPS+HMAC (`metadados_sync.orchestrator_url`/`orchestrator_secret`, só em
  `local.php`) com timeout de 5s, respondendo 202 na hora. NUNCA chama `MetadadosDatabase::conn()`,
  nunca conecta ao SQL Server, nenhum segredo chega ao navegador.
- Dashboard `/admin/indicadores-rh` — cabeçalho ganha "Última atualização" e o botão "Atualizar
  dados" (estados normal/processando/sucesso/erro, anti-duplo-clique, JS inline). Sem redesenho.

**Fluxo assíncrono planejado (n8n NÃO implementado nesta etapa):** Dashboard → `POST /admin/
indicadores-rh/sincronizar` → webhook n8n (202) → n8n aciona o sender interno com `--correlacao-id`
→ sender lê RHMADEPLANT (só-leitura) e envia o lote assinado ao receiver existente → receiver
fecha a MESMA linha `metadados_sync_execucoes` pelo `correlacao_id` → polling do Dashboard vê o
status terminal e recarrega. Sincronização automática usará o mesmo pipeline (n8n Schedule
Trigger → sender → receiver), registrada no histórico com `gatilho` distinto — sem duplicar a
lógica de leitura. **Pendências de n8n:** criar o workflow (webhook + schedule) e configurar
`orchestrator_url`/`orchestrator_secret` em produção; enquanto vazios, o botão fica desabilitado.

Autorização de envio real (sender `--enviar`) continua decisão separada.

**Fase 5.1A — Estrutura Organizacional: Empresas e Unidades como dimensões oficiais (2026-09-04).**
Início da migração da Estrutura Organizacional para a Estratégia B (o METADADOS é o cadastro
mestre oficial de Empresas/Unidades/Setores/Cargos; o Portal sincroniza e usa essas dimensões em
vez de manter cadastros mestres paralelos). Escopo desta etapa: **só Empresas, Unidades e os
CÓDIGOS oficiais de setor/cargo/centro de custo** — Setores, Cargos e Centros de Custo **não**
são reconstruídos ainda (próxima fase); `setores`/`cargos`/`centros_custo`/`cargo_setores` e suas
FKs seguem intocados. Regra imutável mantida: **a conexão com o SQL Server é somente leitura** —
`{Empresa,Unidade}MetadadosSyncService::fetchSourceRows()` executam apenas `SELECT`.

- **`empresas` já é dimensão oficial sincronizada.** Migration
  `2026-09-04-empresas-unidades-metadados.sql` (aditiva): `empresas` ganha `codigo_empresa`
  (`UNIQUE`, identidade oficial = `RHEMPRESAS.EMPRESA`, nunca o nome), `razao_social`
  (`RHEMPRESAS.RAZAOSOCIAL`), `origem_metadados`, `sincronizado_em`. `id`/`nome`/`slug`/`ativo`
  **mantidos** — `id` segue sendo a referência das FKs legadas (vagas, setores, colaboradores,
  solicitacoes_vaga...), `nome`/`slug` seguem sendo a identidade de exibição legada durante a
  transição e **não são sobrescritos** pela sincronização (só `razao_social` fica em dia).
  `EmpresaMetadadosSyncService`/`EmpresaMetadadosRepository`: upsert por `codigo_empresa`, nunca
  DELETE. **Adoção única na primeira carga**: uma empresa local sem `codigo_empresa` é adotada
  (recebe o código, preservando o `id` e as FKs) se — e só se — exatamente uma empresa do
  METADADOS tem o mesmo nome normalizado (transliteração PT-BR determinística, sem depender de
  iconv/locale); 0 ou 2+ matches nunca adotam — inserem como empresa nova e reportam em `avisos`.
  Prévia com a base real (proxy RHEMPRESAS = razões sociais já em `colaboradores_metadados`, 8
  empresas): **2 adotadas** (FOREST SERVICES LTDA=0001, MADEPLANT FLORESTAL LTDA=0002), **6
  inseridas novas**, **3 locais não adotadas** (MADEPLANT TRANSPORTES id 2, MADEPLANT CSC id 3,
  PROSPECTA SERVICOS id 4 — nome não bate exato; a #2 vira quase-duplicata de "MADEPLANT
  TRANSPORTES LTDA"=0005). Essas 3 exigem decisão manual (renomear local para o oficial, ou
  vincular `codigo_empresa` à mão) antes de migrar as telas legadas — **fora do escopo desta
  fase**. `ativo` de `empresas` **não** é alterado pela sincronização (status oficial de
  RHEMPRESAS ainda não confirmado — ver pendências); empresa nova entra `ativo=1`.
- **`unidades` já é dimensão oficial sincronizada.** Tabela nova (mesma migration). Chave oficial
  `(codigo_empresa, codigo_unidade)` — **nunca** `codigo_unidade` isolado. `empresa_id` FK →
  `empresas(id)` `ON DELETE RESTRICT`, resolvido a partir de `codigo_empresa` contra as empresas
  já sincronizadas — se a empresa correspondente ainda não existe, a unidade é **erro controlado
  de integridade** (contado, reportado, não aborta o lote). Daí a ordem canônica:
  **empresas → unidades → colaboradores**. `UnidadeMetadadosSyncService`/`UnidadeMetadadosRepository`:
  upsert por chave composta, nunca DELETE. `ativo` gerenciado pela sincronização (default 1 até o
  status oficial de RHUNIDADES ser confirmado).
- **`colaboradores_metadados` guarda os códigos oficiais.** Migration
  `2026-09-04-colaboradores-metadados-codigos-oficiais.sql` (aditiva): `codigo_setor`,
  `codigo_cargo`, `codigo_centro_custo` (de `RHCONTRATOS.SETOR/CARGO/CENTROCUSTO1`, direto, sem
  JOIN novo). As colunas **textuais** `setor`/`cargo`/`centro_custo` (de `DESCRICAO40`) **continuam
  intactas** — só serão depreciadas quando as dimensões oficiais estiverem completas e as telas
  migradas. `MetadadosSyncService::QUERY` e `normalizeSourceRow()` ampliadas;
  `ColaboradorMetadadosRepository` (INSERT/UPDATE/`COMPARABLE_FIELDS`) inclui os 3 códigos; o
  payload e o `MetadadosSyncRequestValidator` de colaboradores são **retrocompatíveis** (senders
  legados que não enviam os campos → persistem NULL, sem erro).
- **Infra de sincronização reaproveitada, não duplicada.** `MetadadosSyncEnvelope` (novo, em
  `app/core/`) extrai o bloco comum HMAC + janela de replay + decode JSON — usado por
  `MetadadosSyncIngestService` (colaboradores, refatorado) e por `MetadadosDimensaoSyncIngestService`
  (empresas/unidades). Validação de forma por dimensão em `MetadadosDimensaoSyncRequestValidator`
  (o `MetadadosSyncRequestValidator` de colaboradores **não** foi tocado). Endpoints novos, mesma
  auth HMAC, fora do gate `/admin`: `POST /internal/metadados/empresas/sync` e
  `.../unidades/sync` (`InternalMetadadosSyncController::empresas/unidades`).
- **Histórico com dimensão.** Migration `2026-09-04-metadados-sync-execucoes-dimensao.sql`
  (aditiva): `metadados_sync_execucoes.dimensao` (`empresas`/`unidades`/`colaboradores`), nullable,
  backfill das linhas existentes como `colaboradores` (provado — até aqui a única sincronização
  registrada). Cada dimensão gera sua própria linha de execução; unificar por `correlacao_id` é
  concern de fase futura.
- **Sender.** `scripts/sync_metadados_producao.php` ampliado: `--dimensao=empresas|unidades|`
  `colaboradores|todas` (padrão `todas` = empresas→unidades→colaboradores nessa ordem), `--dry-run`
  explícito (é o padrão de qualquer forma), `--enviar` para envio real (**não autorizado**),
  `--correlacao-id` (em `todas`, repassado só à etapa de colaboradores — casa com o fluxo do
  Dashboard). Endpoints de empresas/unidades derivados de `metadados_sync.endpoint_url` trocando o
  segmento da dimensão; `metadados_sync.endpoints` (opcional) permite sobrescrever.
  `--dimensao=empresas --dry-run` inclui uma **prévia de reconciliação SOMENTE LEITURA**
  (`preview_reconciliacao`): para cada empresa oficial de `RHEMPRESAS`, a ação prevista
  (`ADOTAR_EXISTENTE`/`INSERIR_NOVA`/`ATUALIZAR_EXISTENTE`/`INALTERADA`/`ERRO`), a empresa local
  candidata + `empresa_local_id` + nome local, e o critério da decisão; ao final, as empresas
  locais restantes sem código (`LOCAL_SEM_CORRESPONDENCIA_OFICIAL`, com id/nome/slug/ativo). A
  prévia usa `EmpresaMetadadosSyncService::planejar()` — **exatamente** a mesma decisão que
  `applyRows()` executa (o plano é a fonte única; `applyRows()` só executa o plano), contra a
  tabela `empresas` do MySQL configurado no `local.php` da máquina onde o sender roda.
- **Não feito nesta fase (por decisão)**: reconstrução de Setores/Cargos/Centros de Custo;
  qualquer mudança em `setores`/`cargos`/`centros_custo`/`cargo_setores` ou nas FKs desses
  cadastros; reengenharia de formulários / Solicitação de Vaga / parametrização de benefícios;
  remoção de CRUDs; migração de telas legadas para consulta oficial. Migrations **não** aplicadas
  em produção nesta execução. `--enviar` **não** executado. n8n **não** configurado.
- **Pendências de validação na máquina interna (SQL Server inacessível no ambiente de dev)**: a
  query real de `RHEMPRESAS` (existe coluna oficial de status/inatividade? hoje assumimos
  `ativo=1`); a query real de `RHUNIDADES` (idem status; todas as `(EMPRESA,UNIDADE)` de
  `RHCONTRATOS` têm linha correspondente?); volume real de empresas/unidades vs.
  `metadados_sync.max_batch_size`; rodar `php scripts/sync_metadados_producao.php --dimensao=empresas`
  e `--dimensao=unidades` (dry-run) e conferir os resumos.

**Fase 5.1A.1 — reconciliação manual das 3 empresas locais abreviadas (2026-09-08).** O dry-run
real contra `RHMADEPLANT` confirmou **9 empresas oficiais** e mostrou 3 empresas locais que
claramente são empresas oficiais mas não foram adotadas automaticamente (nome local abreviado; a
adoção automática exige correspondência EXATA, nunca aproximação). Decisão aprovada, aplicada por
migration dedicada **fora do algoritmo genérico** (nada de fuzzy matching ou regra Madeplant em
`planejar()`): `2026-09-08-reconciliar-empresas-locais-metadados.sql` (+rollback) — procedure com
transação única + `EXIT HANDLER`/`RESIGNAL`, guardas por `SIGNAL` (os 3 ids existem; nenhum já tem
código diferente do aprovado; nenhum código-alvo pertence a outro id), UPDATE só nas linhas ainda
sem código (idempotente), sem tocar `id`/FKs/`nome`/`slug`/`ativo`. Mapa: `id 2 → 0005`
(MADEPLANT TRANSPORTES), `id 3 → 0007` (MADEPLANT CSC), `id 4 → 0008` (PROSPECTA SERVICOS).
Preenche `origem_metadados='RHMADEPLANT'`; **não** preenche `sincronizado_em` (nenhuma sync de
dados ocorreu — `razao_social` continua NULL até a 1ª sincronização real). Após a migration,
`planejar()` prevê: `0001/0002 ADOTAR_EXISTENTE`, `0003/0004/0006/0009 INSERIR_NOVA`,
`0005/0007/0008 ATUALIZAR_EXISTENTE`, **`locais_sem_correspondencia` vazio**. `0004` e `0006` têm
a MESMA razão social (`CELSO LUIZ MELLO CORREA`) — confirma que a identidade oficial é
exclusivamente `codigo_empresa`, nunca o nome. Ordem de aplicação em produção: esta migration
**depois** de `2026-09-04-empresas-unidades-metadados.sql`. Não aplicada em produção nesta etapa.

**Fase 5.2 — Setores e Cargos como dimensões oficiais (2026-09-08).** Identidade oficial
**confirmada por diagnóstico direto no SQL Server RHMADEPLANT** (`scripts/diagnostico_metadados_setores_cargos.php`,
somente leitura): `RHSETORES.SETOR` VARCHAR(8) e `RHCARGOS.CARGO` VARCHAR(8) são chaves **globais**
(as tabelas de catálogo **não têm** `EMPRESA` nem `UNIDADE`), sem duplicidade; 12 setores, 184
cargos; descrição oficial `DESCRICAO40`; `RHCONTRATOS.SETOR`/`CARGO` referenciam essas chaves com
0 órfãos. **Códigos são strings OPACAS** — preservados exatamente como o METADADOS entrega
(ex.: `'0120'`), nunca convertidos para inteiro, nunca com zeros à esquerda mexidos.
`RHSETORES`/`RHCARGOS` têm `ATIVADESATIVADA CHAR(1)` mas a **semântica dos valores ainda não foi
confirmada** — o Portal guarda o valor **bruto** em `situacao_metadados` e **não altera `ativo`
local** (pendência documentada; não bloqueia a fase).

- Migration `2026-09-08-setores-cargos-metadados.sql` (aditiva): `setores` e `cargos` ganham
  `codigo_setor`/`codigo_cargo VARCHAR(8) UNIQUE`, `descricao_oficial VARCHAR(40)`,
  `situacao_metadados VARCHAR(10)` (bruto), `origem_metadados`, `sincronizado_em`. `id`, `nome`,
  `slug`, `ativo`, `setores.empresa_id` (legado do Portal — **não** faz parte da identidade
  oficial; RHSETORES não tem empresa; já era NULLABLE, nenhuma alteração) e as 12 FKs para
  `setores(id)`/`cargos(id)` (colaboradores, movimentacoes_pessoal, solicitacoes_vaga,
  cargo_setores, centros_custo, cargo_beneficios, cargo_faixas_salariais) **intactas**.
  `metadados_sync_execucoes.dimensao` (VARCHAR(20), Fase 5.1A) já aceita `'setores'`/`'cargos'` —
  sem migration adicional.
- `MetadadosTexto::normalizarNome()` (novo, `app/core/`) — implementação única do normalizador de
  nome para adoção, extraída de `EmpresaMetadadosRepository` (que virou fachada delegante) e
  compartilhada com Setor/Cargo. Determinístico (mapa de acentos PT-BR), **nunca fuzzy**.
- `CatalogoMetadadosRepository` + `CatalogoMetadadosSyncService` — **uma** classe cada,
  parametrizada pela dimensão (`'setores'`/`'cargos'`), whitelist interno de tabela/coluna (padrão
  `CadastroOrganizacional`). `planejar()` só-leitura = fonte única da decisão (`ADOTAR_EXISTENTE`
  só com nome normalizado idêntico e candidato único; ambiguidade → `INSERIR_NOVA` + aviso; sem
  fuzzy); `applyRows()` executa o plano; nunca DELETE. Colisão de `nome`/`slug` na inserção:
  sufixo do código (`DESCRICAO40 (CODIGO)`); `descricao_oficial` **nunca** recebe sufixo.
- `MetadadosDimensaoSyncRequestValidator` — `DIMENSOES` ganha `setores`/`cargos` (chave `codigo`,
  descrição `descricao_oficial`); o de colaboradores segue intocado. `MetadadosDimensaoSyncIngestService`
  passa a resolver o serviço da dimensão por `switch` (`?object $syncService` injetável),
  cobrindo as 4 dimensões — empresas/unidades sem regressão.
- Endpoints `POST /internal/metadados/setores/sync` e `.../cargos/sync`
  (`InternalMetadadosSyncController::setores/cargos`), mesma auth HMAC, fora do gate `/admin`.
- Sender `sync_metadados_producao.php`: `--dimensao` aceita `setores|cargos`; ordem de `todas`
  agora **empresas → unidades → setores → cargos → colaboradores**. `previewReconciliacaoEmpresas`
  generalizada para `previewReconciliacao($dimensao)` — o dry-run de empresas/setores/cargos traz
  `preview_reconciliacao` (plano de `planejar()`). `--enviar` **não** executado.
- Colaboradores: `codigo_setor`/`codigo_cargo` no espelho já vinham da Fase 5.1A (commit
  `7a87aa8`); Fase 5.2 só confirma que não houve regressão (`integration_colaborador_metadados_sync.php`).
  Centro de Custo **não** reconstruído.
- **Não feito** (por decisão): reconstrução de Centro de Custo; qualquer mudança em
  `cargo_setores`, Solicitação de Vaga, Movimentação de Pessoal, parametrizações Portal, CRUDs ou
  Dashboard; migração de telas para consulta oficial. Migrations **não** aplicadas em produção
  nesta execução.
- **Pendências**: confirmar a semântica de `ATIVADESATIVADA` (rodar o trecho `ativadesativada_*`
  do script de diagnóstico na máquina interna) e então mapear/ativar a política de `ativo`;
  volume de cargos (184) vs. `metadados_sync.max_batch_size` (2000 — folga OK); rodar o dry-run
  real de `cargos` na máquina interna e revisar o `preview_reconciliacao` (esperado: muitos
  `INSERIR_NOVA` — 184 oficiais vs. 60 locais); decisão manual sobre `LOCAL_SEM_CORRESPONDENCIA_OFICIAL`
  antes de migrar telas.

**Fase 5.2.1 — reconciliação manual de 3 setores locais (2026-09-08).** O dry-run **real** de
Setores contra RHMADEPLANT: 12 setores oficiais, **9 `ADOTAR_EXISTENTE`**, **3 `INSERIR_NOVA`**, 7
locais sem correspondência. Dos 3 `INSERIR_NOVA`, três têm equivalente local claro (nome
abreviado/diferente, sem match exato — a adoção automática exige nome idêntico, nunca aproximação).
Decisão aprovada, aplicada por migration dedicada **fora do algoritmo genérico**:
`2026-09-08-reconciliar-setores-locais-metadados.sql` (+rollback) — procedure com transação única
+ `EXIT HANDLER`/`RESIGNAL`, guardas por `SIGNAL` (os 3 ids existem; nenhum já tem código diferente
do aprovado; nenhum código-alvo pertence a outro id), `UPDATE` só nas linhas ainda sem código
(idempotente), sem tocar `id`/`nome`/`slug`/`empresa_id`/`ativo`/FKs. Mapa: `id 10 → '1'`
(RH/DP/SST → RECURSOS HUMANOS), `id 7 → '6'` (LOGÍSTICA → LOGISTICA E TRANSPORTES), `id 12 → '9'`
(TI → TECNOLOGIA DA INFORMAÇÃO). Preenche `origem_metadados='RHMADEPLANT'`; **não** preenche
`sincronizado_em` nem `descricao_oficial` (feito pela 1ª sincronização real). Após a migration,
`planejar()` prevê: 9 `ADOTAR_EXISTENTE`, códigos `1`/`6`/`9` → `ATUALIZAR_EXISTENTE`,
**`INSERIR_NOVA = 0`**, e exatamente **4 `LOCAL_SEM_CORRESPONDENCIA_OFICIAL`** — `PRODUÇÃO` (id 9),
`ADMINISTRATIVO` (id 16), `MANUTENÇÃO PROSPECTA` (id 19), `ADMINISTRATIVO PROSPECTA` (id 20) —
deliberadamente sem código (legados do Portal, tratados quando as dependências migrarem; **sem**
equivalência automática MANUTENÇÃO PROSPECTA→MANUTENÇÃO, PRODUÇÃO→OPERAÇÃO etc., por falta de
evidência). `ATIVADESATIVADA` real: Setores `1`=12/12; Cargos `1`=180, `2`=4 (entre códigos em
contrato: `1`=137, `2`=2) — semântica ainda **não** comprovada, política conservadora mantida.
Ordem de aplicação em produção: **depois** de `2026-09-08-setores-cargos-metadados.sql`. Não
aplicada em produção nesta execução. **Cargos: reconciliação ainda não iniciada.**

**Continuam intocados/pendentes de autorização futura**: `Colaborador::updateRhData()`, import
XLSX, `usuario_colaboradores`/autenticação (risco de `UNIQUE` em `colaborador_id` sob readmissão
continua registrado, não tratado), vínculo candidato→colaborador em
`solicitacoes_vaga.nome_contratado_colaborador_id`, aplicação dos 4 vínculos seguros liberados
pelo saneamento, saneamento dos 2 `CONFLITO`/24 `SEM_CORRESPONDENCIA` restantes, configuração do
n8n (webhook de disparo + agendamento recorrente) e das chaves `metadados_sync.orchestrator_*` em
produção, migração de telas legadas para a nova fonte, e descontinuação do cadastro duplicado.

**Follow-ups abertos das Fases 5.1A / 5.1A.1 / 5.2**: aplicar em produção, nesta ordem, as
migrations — `2026-09-04-empresas-unidades-metadados.sql` → `2026-09-04-colaboradores-metadados-`
`codigos-oficiais.sql` (**antes** de qualquer deploy do código, senão a sincronização de
colaboradores quebra por coluna ausente) → `2026-09-04-metadados-sync-execucoes-dimensao.sql` →
`2026-09-08-reconciliar-empresas-locais-metadados.sql` (5.1A.1) → `2026-09-08-setores-cargos-`
`metadados.sql` (5.2, também **antes** do deploy do código da 5.2, mesmo motivo); autorizar (ou
não) o `--enviar` do sender para as novas dimensões; confirmar a semântica de `ATIVADESATIVADA`
(Setores/Cargos); próxima fase: reconstrução de Centro de Custo, migração das telas de
Empresas/Unidades/Setores/Cargos para consulta oficial, descontinuação do cadastro duplicado.
