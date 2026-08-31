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
camada analítica de RH (Fase 4, `/admin/indicadores-rh`) e a missão corretiva de pureza da base
analítica (saneamento dos 6 contratos de `RHTESTE` + proteção `origem_metadados` contra nova
mistura de origem) — leia a seção dedicada abaixo para detalhes e para o que **ainda não** foi
feito dentro dessa integração.

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

**Continuam intocados/pendentes de autorização futura**: `Colaborador::updateRhData()`, import
XLSX, `usuario_colaboradores`/autenticação (risco de `UNIQUE` em `colaborador_id` sob readmissão
continua registrado, não tratado), vínculo candidato→colaborador em
`solicitacoes_vaga.nome_contratado_colaborador_id`, aplicação dos 4 vínculos seguros liberados
pelo saneamento, saneamento dos 2 `CONFLITO`/24 `SEM_CORRESPONDENCIA` restantes, criação de
usuário SQL Server dedicado só-leitura para produção, migração de telas legadas para a nova fonte,
e descontinuação do cadastro duplicado.
