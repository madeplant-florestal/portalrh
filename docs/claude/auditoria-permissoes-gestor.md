# Auditoria de permissões do Gestor (Etapa 3.1 — 2026-11)

> Decisão funcional: **o perfil estabelece os limites de segurança; as permissões individuais estabelecem o que o usuário pode fazer;
> o escopo (`usuario_setores_gerenciados`) estabelece quais registros ele alcança.** Modelo recomendado ≠ permissões concedíveis ≠
> atribuídas ≠ efetivas. Fonte única das regras: `PermissaoPerfilPolicy`. Nada aqui é decidido por ID/nome de usuário.

## 1. Os quatro conjuntos (Gestor)

| Conjunto | Onde vive | Conteúdo hoje |
|---|---|---|
| **Recomendado** (sugestão inicial) | `modeloRecomendado('gestor')` | 15 permissões operacionais setoriais |
| **Concedível** (o que o administrador pode atribuir) | `concediveis('gestor')` = `GESTOR_PERMITIDAS` | 17 (= recomendado + `solicitacao_vaga.aprovar_lider` + `dashboard_integracao_agregado.visualizar`) |
| **Atribuído** | `usuario_permissoes` | o que o administrador gravou (pode conter legado incompatível, sem efeito) |
| **Efetivo** | `Authorization::usuarioTemPermissao` → política + role + escopo | atribuído ∩ concedível, **e** o registro dentro dos setores gerenciados |

## 2. Metodologia

Para cada permissão do catálogo (77 códigos ativos): (a) classificação na política; (b) controller/método que a consome
(`grep` em `app/controllers`, rotas em `index.php`); (c) guarda de role do endpoint (`Auth::requireRole`) — **prova empírica**: um Gestor
com **todas** as permissões do catálogo gravadas e um setor gerenciado recebe **403** em todos os endpoints não escopados abaixo
(`integration_gestor_permissoes_individuais.php`, seção 4); (d) uso de `EscopoSetorial` (somente Colaboradores, Experiência,
Desempenho, Feedback, PDI, Solicitação de Vaga/Kanban e Dashboard de Integração agregado o usam).

## 3. Matriz (por módulo)

Legenda de classificação proposta: **liberável** (já concedível), **bloqueada** (sem consumidor/administrativa/global — não liberar),
**requer correção** (pode vir a ser liberada, mas exige mudança de backend ainda não feita).

| Módulo / ações (códigos reais) | Classif. na política (Gestor) | Endpoint → verificação | Escopo setorial no backend | Sem setores | ID de outro setor | Listagem/relatório/export | Proposta |
|---|---|---|---|---|---|---|---|
| `colaboradores.visualizar` | concedível | `AdminColaboradoresController::index/show` → `requireRole(admin,rh,gestor,viewer)` + permissão + `EscopoSetorial` | **sim** (`usuario_setores_gerenciados`) | escopo restrito e vazio (nada) | negado | listagem filtrada; export `/admin/dashboard/colaboradores/exportar` = **403** ao Gestor | **liberável** |
| `colaboradores.editar` | bloqueada (`sem_consumidor`) | edição (`editRh`) é por role Admin/RH; nenhuma ação consome a permissão | n/a | n/a | n/a | n/a | **bloqueada** (conceder não teria efeito) |
| `avaliacao_experiencia.visualizar/.avaliar` | concedível | `AdminAvaliacoesExperienciaController` (+ serviço) → `requirePermissao` + `EscopoSetorial` | **sim** | vazio | negado (testes 2C.2B) | sem export de gestor | **liberável** |
| `avaliacao_desempenho.visualizar/.avaliar` | concedível | `AdminAvaliacoesDesempenhoController` → permissão + `EscopoSetorial` no repositório | **sim** (setor ATUAL do contrato) | vazio | negado (2C.2A) | idem | **liberável**; reabrir segue Admin/RH |
| `feedback.visualizar/.avaliar` | concedível | `AdminFeedbacksController` → idem | **sim** | vazio | negado | idem | **liberável** |
| `pdi.visualizar/.gerenciar/.acompanhar` | concedível | `AdminPdisController` → idem | **sim** | vazio | negado | idem | **liberável** |
| `solicitacao_vaga.visualizar/.criar` | concedível | `AdminSolicitacoesVagaController` → `exigirPermissaoDoGestor` + escopo no model | **sim** (setor da solicitação) | vazio | negado (2C.2B) | idem | **liberável** |
| `solicitacao_vaga.aprovar_lider` | concedível (fora do modelo) | `approveLeader` → `requirePermissao` + designação formal + setor + etapa (backend) | **sim** | vazio | negado (2C.2C) | — | **liberável** (só se também for o aprovador designado) |
| `solicitacao_vaga.editar/.cancelar` | bloqueada (`sem_consumidor`) | nenhuma operação do Gestor consome | n/a | n/a | n/a | n/a | **bloqueada** |
| `kanban_vagas.visualizar/.detalhes/.movimentar` | concedível | `AdminSolicitacoesVagaKanbanController` → permissão + escopo do model | **sim** | vazio | negado (2C.2B) | idem | **liberável** |
| `kanban_vagas.editar` | bloqueada (`sem_consumidor`) | nenhuma operação consome (anotar/mover usam `.movimentar`) | n/a | n/a | n/a | n/a | **bloqueada** |
| `dashboard_integracao_agregado.visualizar` | concedível (fora do modelo) | `AdminDashboardIntegracaoAgregadoController` → permissão + `EscopoSetorial` + k>=5 | **sim** | vazio | negado | só agregado, sem comentários/respostas individuais | **liberável** |
| `candidaturas.visualizar/.editar` | bloqueada (`requer_escopo`) | `AdminCandidaturasController` → `requireRole(admin,rh)`; downloads de CV/documentos também (**403**) | **não** — a candidatura só chega ao setor por vaga → `solicitacao_vaga_id` (**opcional**) → `setor_id`; dados pessoais (CPF, e-mail, currículo) | n/a | **sem proteção** | listagem/relatórios globais | **requer correção** (ver §5) |
| `pipeline.visualizar/.movimentar` | bloqueada (`requer_escopo`) | `AdminPipelineController` → `requireRole(admin,rh)` | **não** | n/a | **sem proteção** (mover candidato de qualquer vaga) | global | **requer correção** |
| `vagas.visualizar/.criar/.editar/.excluir/.publicar` | bloqueada (`requer_escopo`) | `AdminVagasController` → `requireRole(admin,rh[,viewer])`; publicar/excluir são Admin/RH | **não** — `vagas.area` é texto livre; `solicitacao_vaga_id` é opcional | n/a | **sem proteção** | `Vaga::all()` global | **requer correção** |
| `dashboard_recrutamento.visualizar` | bloqueada (`requer_escopo`) | `AdminDashboardRecrutamentoController` → `requireRole(admin,rh,viewer)` + permissão | **não** (consultas globais) | n/a | n/a | agregados globais | **requer correção** |
| `dashboard`, `indicadores_rh`, `dashboard_turnover`, `dashboard_entrevista_desligamento` `.visualizar` | bloqueada (`global_nominal`) | `requireRole(admin,rh,viewer)` + permissão | **não**; reidentificação (ver `AUDITORIA_DASHBOARDS`) | n/a | n/a | sem amostra mínima | **bloqueada** |
| `entrevista_desligamento.*`, `integracao_colaborador.*` | bloqueada (`global_nominal`) | `requireRole(admin,rh,viewer)` + permissão | **não** (respostas/comentários globais) | n/a | n/a | global | **bloqueada** |
| `pesquisa_reacao_integracao.*`, `recruitment_webhooks.*`, `mensagens.*`, `beneficios.*`, `avaliacoes.*`, `empresas.*`, `setores.*`, `cargos.*`, `manual.visualizar` | bloqueada (`administrativa`) | `requireRole(admin,rh[,viewer])` | n/a (configuração/dado mestre corporativo) | n/a | n/a | exports de setores/indicações = **403** | **bloqueada** |
| `movimentacao_pessoal.*` | bloqueada (`requer_escopo`) | `AdminMovimentacoesPessoalController` → `requireRole(admin,rh,viewer)` | **não** (documento nominal com salário e assinatura de RH) | n/a | n/a | global | **requer correção** |
| `indicacoes.*` | bloqueada (`global_nominal`) | `requireRole(admin,rh)` | **não** (pagamento de comissão) | n/a | n/a | export = **403** | **bloqueada** |
| `comunicacoes.visualizar`, `pesquisa_experiencia.visualizar` | bloqueada (`requer_escopo`) | lidas só dentro de `AdminCandidaturasController` (Admin/RH) | **não** | n/a | n/a | n/a | depende de Candidaturas |

## 4. Resultado

* **Permissões que passaram a ser concedíveis ao Gestor nesta etapa: nenhuma nova.** O conjunto concedível já era o das 17 com
  escopo setorial comprovado (2C.1–2C.2C). A flexibilização desta etapa é **estrutural**: o modelo recomendado (15) é subconjunto
  estrito das concedíveis (17); o administrador combina livremente dentro delas, e a matriz deixa isso explícito (grupo/linha/todas
  marcam todas as concedíveis, "Restaurar modelo" volta ao conservador).
* **Evidência de que o restante não pode ser "só marcado":** conceder qualquer permissão bloqueada a um Gestor **não abre nada** (o endpoint
  exige role Admin/RH) — liberar de verdade exige **mudar o controller e filtrar por setor**, o que não é um ajuste pontual.
* **Viewer:** novas permissões operacionais de transição deixaram de poder ser concedidas (`podeReceberPermissao`); as já gravadas
  continuam **efetivas** (nada foi apagado/reclassificado). O caminho para acesso operacional novo é o perfil Gestor.

## 5. Pendências (decisão/trabalho necessários antes de liberar — NÃO liberadas)

1. **Vaga ↔ setor** (**base estrutural entregue na Etapa 3.2A**: `vagas.setor_id`, ver `vagas-setor-responsavel.md`; ainda NÃO libera permissões). `vagas.area` é texto livre e `vagas.solicitacao_vaga_id` é opcional; candidaturas só herdam setor por essa cadeia. Decisão
   necessária: (a) tornar o vínculo obrigatório para vagas novas (migration + backfill das existentes, a executar manualmente) **ou**
   (b) restringir o Gestor às vagas **com** solicitação e tratar as sem vínculo como "sem setor" (invisíveis ao Gestor).
2. **Candidaturas/Pipeline/Kanban de candidatos:** reaproveitar `EscopoSetorial` em consultas, detalhes por ID, movimentação, anexos (CV e
   documentos), comunicações e pesquisa; abrir `requireRole` ao `gestor` somente nesses métodos. É reescrita de módulo — fora do escopo.
3. **Dashboard de Recrutamento por setor:** consultas agregadas por setor da solicitação com amostra mínima.
4. **Movimentação de Pessoal:** documento nominal com salário e assinatura de RH — exige decisão de negócio sobre o que o Gestor pode ver.
5. Dashboards globais (`dashboard`, Turnover, Indicadores, Entrevista de Desligamento): risco de reidentificação sem amostra mínima.

## 6. Testes

`integration_gestor_permissoes_individuais.php` (permissão a permissão, endpoints diretos, combinações, bloqueadas forjadas, Viewer,
setores, hierarquia), `integration_matriz_permissoes.php`, `unit_permissao_perfil_cobertura.php` (todo código do catálogo tem decisão),
`tests/matriz-permissoes*.spec.js` (Playwright).
