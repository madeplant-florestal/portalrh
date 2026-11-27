# Riscos Conhecidos — RH Madeplant

> Regra permanente (ver `CLAUDE.md`): riscos abaixo **nunca são corrigidos silenciosamente** —
> qualquer correção passa pelo processo de segurança (`checklists.md`), com conversa dedicada
> sobre blast radius, mesmo que a correção pareça óbvia.

Identificados na auditoria arquitetural de 2026-07-09, documentados aqui para não serem
"descobertos" e corrigidos por impulso em uma tarefa não relacionada:

1. **[Estado atual: dumps REMOVIDOS na Segurança 3.5A — itens 20 e 21; resta o HISTÓRICO do Git, Segurança 3.5B]**
   **PII real de candidatos/colaboradores commitada em `recrutamento.sql`** (ambas as cópias —
   `database/recrutamento.sql` e `recrutamento.sql` na raiz, arquivos idênticos) e presente no
   histórico do Git, que foi enviado a um remoto GitHub. Requer decisão explícita sobre como
   sanear o histórico sem quebrar o instalador que depende desse dump.
2. **Senha de supervisor em texto plano** em `app/config/config.php` / `local.php`
   (`security.supervisor_password`). É assim que o instalador cria o primeiro usuário admin —
   mudar isso tem implicações no fluxo de instalação/recuperação e não deve ser "corrigido" sem
   discutir o fluxo alternativo.
3. **`Cipher` usa chave derivada fraca como fallback** quando `security.data_encryption_key` não
   está definida na config — os dados `*_encrypted` ficam protegidos por uma chave previsível a
   partir de outros valores de config. Verificar se produção tem a chave explícita definida antes
   de assumir que os dados sensíveis estão realmente protegidos.
4. **Política temporal (Frente D, 2026-11) — RESOLVIDO, com regras permanentes.** Instantes são persistidos em UTC (bootstrap fixa o PHP em UTC; `Database::conn()` fixa a SESSÃO do banco em `+00:00`; produção já operava em UTC) e apresentados em `America/Campo_Grande` por conversão EXPLÍCITA (`DateHelper::formatLocal/paraLocal`, com origem declarada `utc`/`php_legado`/`civil`). Regra de negócio que depende do DIA civil usa `DateHelper::hojeOperacional()`/`hojeOperacionalSql()`/`diaOperacionalDe($instante)` — NUNCA `new DateTimeImmutable('today')`, `date('Y-m-d')`, `strtotime('today')` ou `CURDATE()` (entre 20h e 23h59 locais o UTC já é o dia seguinte); `tests/php/unit_data_operacional_cobertura.php` barra novas ocorrências. Contrato dos serviços: `$agora` = INSTANTE (gravado com `DateHelper::paraUtcSql`), `$hoje` = data CIVIL; datas do METADADOS/usuário não são convertidas. Colunas DATE e agendamentos permanecem civis. Dados históricos não foram alterados. Residual: `DATE()` em SQL de colunas de instante UTC não vira dia local (converter em PHP, como em `Candidatura::diasDesdeContratacao`).
5. **Exclusão analítica é dado, não deploy; cobertura ainda parcial** — identificado em 2026-11:
   as 3 exclusões de 29/10 foram gravadas só no banco de desenvolvimento e o contrato "excluído"
   reapareceu em produção (tabela existia, vazia, sem erro nenhum). A identificação passou a ser pela
   chave natural (ver `indicadores-rh.md`), mas continua valendo: ambiente novo/reconstruído exige
   reaplicar as exclusões. Desde 2026-11 a exclusão é GLOBAL (todos os módulos analíticos e
   operacionais, inclusive o cadastro local `colaboradores` e os processos que o referenciam — ver
   `indicadores-rh.md`) e protegida por teste de cobertura estático sem exceções genéricas. Registros de
   módulo e processos ligados a contrato excluído (avaliações, entrevistas, PDIs, solicitações de vaga,
   movimentações…) ficam preservados no banco, invisíveis para o RH; antes de aplicar a regra em um
   ambiente, rodar `database/diagnosticos/2026-11-11-dependencias-exclusao-analitica.sql` para saber
   se existem.

6. **Autorização do perfil `viewer` e `is_supervisor` (Etapa 2A, 2026-11) — CORRIGIDO, com regras permanentes e pendências.**
   - **Política:** o *perfil* (`usuarios.role`: admin/rh/viewer) define as capacidades gerais, a *permissão individual* define as
     operações e o *escopo setorial* (`usuario_setores_gerenciados`) define os dados. `viewer` = só dashboards/indicadores
     agregados autorizados: sem lista nominal, sem exportação nominal, sem dado financeiro detalhado, sem ação operacional.
   - **Corrigido (backend, não só UI):** People Analytics (`/admin/dashboard`, `/dados`) não consulta nem renderiza a lista nominal
     para viewer, e `/admin/dashboard/colaboradores/exportar` é admin/RH; Candidaturas (lista/detalhe), Pipeline, Indicações
     (tela + 4 APIs financeiras) e Avaliações legado (`/admin/avaliacoes`) são admin/RH — `candidaturas|pipeline|indicacoes.visualizar`
     gravadas em `usuario_permissoes` **não** concedem mais acesso (o registro continua armazenado); Indicadores de RH exige
     `indicadores_rh.visualizar` para viewer.
   - **`is_supervisor` não é bypass:** só vale junto com `role = admin` (`Auth::normalizarSupervisorDaSessao()`, chamado por
     `requireRole`, `temAcessoPorRoleOuPermissao`, `requireRoleOuPermissao`, `canAccessAdmin` e `primeiraRotaAcessivel`; sessões
     antigas são normalizadas na próxima requisição). Continua valendo como PROTEÇÃO em `User::isProtectedSupervisor/canManageUser`.
   - **Pendências conscientes (não alteradas):** (a) `SolicitacaoVaga`, `MovimentacaoPessoal` e `User` ainda leem `is_supervisor`
     direto do BANCO (≈10 pontos) para tratá-lo como RH/admin; com role rh/viewer isso ainda concede poder nesses fluxos —
     tratar na Etapa 2C junto com o perfil Gestor; (b) módulos com role+permissão (Entrevista de Desligamento operacional, PDI,
     Mensagens, Pesquisas, Avaliações de Experiência/Desempenho, Feedback, Colaboradores) continuam acessíveis a viewer **com a
     permissão**, cada um com escopo próprio — é o embrião do Gestor e não foi mexido; (c) filtros acumulados do People
     Analytics (sexo × setor × motivo) podem isolar células de 1 pessoa mesmo no agregado (reidentificação) — avaliar limite
     mínimo de amostra; (d) a lista `$excecoes` de `tests/php/unit_rotas_viewer_cobertura.php` é a dívida explícita.
   - **Cobertura:** `integration_autorizacao_etapa2a.php` (por endpoint × ator, processos separados) e
     `unit_rotas_viewer_cobertura.php` (varredura estática das rotas). Diagnóstico de produção, somente leitura:
     `database/diagnosticos/2026-11-18-autorizacao-etapa2a-impacto.sql` — rodar o BLOCO 7 ANTES do deploy.

7. **Hierarquia de autoridade e Supervisor Geral (Etapa 2A-bis, 2026-11) — CORRIGIDO, com regras permanentes.**
   - **Hierarquia:** Supervisor Geral (`role = admin` E `is_supervisor = 1`) > Administrador > RH > (Gestor, futuro) > Viewer. O flag
     isolado não concede nada; `is_supervisor = 1` fora de admin é **configuração inconsistente** (sem privilégio, só sinalizada,
     nunca corrigida automaticamente). Toda decisão de quem gerencia quem vive em **`Autoridade`** (`app/services/Autoridade.php`):
     `nivel`, `ehSupervisorGeral`, `podeGerenciar`, `podeGerenciarContexto`, `podeAtribuirRole`, `podeAlterarSupervisorGeral`,
     `flagEfetivo`. Nunca escrever `role === 'admin' || is_supervisor` em controller/model/view.
   - **Regras:** Supervisor Geral gerencia todos (inclusive outros Supervisores Gerais); Administrador gerencia todos exceto
     Supervisor Geral e não mexe no flag; RH só altera perfis rh<->viewer de contas rh/viewer (não promove a admin, não toca em
     admin/Supervisor Geral, não redefine senha); ninguém altera o próprio perfil; o perfil de um Supervisor Geral só muda depois
     de revogar o flag (operação explícita); perfil fora da whitelist é recusado (400), não vira viewer em silêncio.
   - **Corrigido:** (1) `adminChangePassword`/API de senha e Colaboradores → Acesso (senha temporária) não verificavam a conta de
     destino — Administrador podia assumir o Supervisor Geral; (2) RH promovia qualquer usuário a admin; (3) `supervisor/garantir`
     (qualquer admin) redefinia a senha do supervisor para a do arquivo de configuração — agora só Supervisor Geral e **nunca
     redefine senha de conta existente**; (4) `canManageUser` aceitava o flag de qualquer role; (5) exclusão/desativação sem proteção
     do último Supervisor Geral e sem bloqueio de autoexclusão; (6) leituras diretas do flag em `SolicitacaoVaga`/`MovimentacaoPessoal`/
     `candidatosSolicitante`.
   - **Proteções:** último Supervisor Geral ativo não é excluído, desativado nem tem o flag removido — verificação sob
     `SELECT ... FOR UPDATE` dos supervisores ativos (testada com dois processos concorrentes; sem a trava ambos venciam); ninguém
     exclui/desativa a própria conta pela via administrativa nem remove o próprio privilégio; o flag só muda por
     `/admin/usuarios/{id}/supervisor-geral` (Supervisor Geral, alvo admin, auditado) ou `garantir`; auditoria em `auditoria_usuarios`
     (`role_change`, `supervisor_grant/revoke`, `user_status_change`, `user_delete`, `admin_password_change` e os `blocked_*`).
   - **Recuperação:** se não restar nenhum Supervisor Geral ativo, a rota `garantir` (só Supervisor Geral) não pode ser usada — a
     recuperação é por SQL/instalador, fora da aplicação (decisão consciente: nenhuma rota web pode criar autoridade máxima do nada).
   - **Cobertura:** `integration_autoridade_supervisor_geral.php` (90 verificações, HTTP em processos separados, concorrência),
     `unit_autoridade_cobertura.php` (varredura estática). Diagnóstico de produção: `database/diagnosticos/2026-11-19-supervisor-geral-inconsistencias.sql`.

8. **Política central de permissões por perfil (Etapa 2B, 2026-11) — IMPLEMENTADA, com pendências de decisão.**
   - **Fonte única:** `PermissaoPerfilPolicy` (`app/services`) define, por código real de `permissoes`, o nível de cada permissão para o perfil:
     `permitida` | `transitoria_gestor` (módulo com escopo por Setor Gerenciado no backend — o "viewer gestor" de hoje, migra ao perfil Gestor)
     | `pendente_decisao` (sem escopo setorial, uso por viewer não comprovado: o já gravado segue efetivo, NOVA atribuição é recusada)
     | `incompativel` (nunca concede acesso; continua gravada). Código fora das listas = incompatível; o teste de cobertura falha se o
     catálogo ganhar código sem decisão explícita. Admin/RH: global (sem exigir linhas). Gestor: FUTURO (`PERFIL_GESTOR_ATIVO = false`, ENUM intacto).
   - **Viewer permitido (dashboards auditados):** `dashboard.visualizar`, `indicadores_rh.visualizar`, `dashboard_turnover.visualizar`,
     `dashboard_recrutamento.visualizar`, `dashboard_entrevista_desligamento.visualizar`. **Modelo recomendado automático: só
     `dashboard_recrutamento.visualizar`** — os demais NÃO têm tamanho mínimo de amostra e permitem reidentificar indivíduos por
     combinação de filtros (People Analytics: empresa × setor × sexo × motivo × mês, e a lista de opções expõe nomes de usuários
     gestores; Indicadores de RH: cargo/setor/centro de custo; Turnover: cargo com 1 ocupante; Entrevista de Desligamento: grupo com 1
     respondente expõe a resposta de uma pessoa). Continuam PERMITIDOS (não se retira acesso existente) até haver limite mínimo de
     amostra; só então entram no modelo.
   - **Integração:** `Authorization::usuarioTemPermissao` (único ponto de consulta de todos os módulos) só considera a permissão gravada
     se `PermissaoPerfilPolicy::permissaoEfetiva(role, codigo)`; `updatePermissoes` valida a seleção FINAL (existe, ativa, compatível)
     antes de gravar — recusa por inteiro, sem alteração parcial, com mensagem e auditoria `blocked_permissoes_update`. A tela do usuário
     avisa o legado incompatível/pendente. `Autoridade` continua prevalecendo (Administrador não altera permissões do Supervisor Geral).
   - **Matriz horizontal (Etapa 3):** cadastro (`create`/`store`) e edição (`show`/`updatePermissoes`) usam `PermissaoMatrizService`
     (linha = prefixo REAL do código; coluna = ação real; área só de apresentação) + `partials/matriz-permissoes.php` +
     `assets/matriz-permissoes.js` (sem lista de permissões: tudo vem de `data-perfis`, calculado pela política). `modeloRecomendado`:
     Viewer = dashboard de recrutamento; Gestor = operacional setorial SEM `solicitacao_vaga.aprovar_lider` e
     `dashboard_integracao_agregado.visualizar` (permitidas, mas só por concessão explícita); Admin/RH = vazio. Cadastro grava usuário +
     permissões em UMA transação (`Authorization::sincronizar` respeita transação aberta); `updatePermissoes` aceita `role` opcional e
     grava perfil + permissões atomicamente (mesma `Autoridade::podeAtribuirRole` de `/role`; seleção validada contra o perfil NOVO;
     `is_supervisor` nunca é lido). Legado incompatível: aparece marcado "sem efeito", nunca é reativado nem apagado sem desmarcar e salvar.
     Controles (JS): "Marcar grupo"/"marcar linha" atuam em TODAS as permissões habilitadas e aplicáveis ao perfil do escopo (inclusive as de
     transição do Leitor); "Marcar todas" fica restrito ao lote do perfil (Leitor: só dashboards — sem seleção global de operacionais);
     "Alternar linha": todas aplicáveis marcadas → limpa, senão marca todas. Botões sem alvo aplicável ficam DESABILITADOS (nunca um clique
     sem efeito) e cada ação informa o resultado. O legado incompatível nunca entra no lote.
     **Etapa 3.1:** `PermissaoPerfilPolicy` separa recomendado (`modeloRecomendado`) × concedível (`concediveis`) × bloqueado
     (`motivoBloqueio`/`categoriaBloqueioGestor`, toda permissão do catálogo tem decisão — falha em `unit_permissao_perfil_cobertura`).
     Viewer: a transição operacional só se MANTÉM onde já gravada (`podeReceberPermissao`); nova concessão é recusada.
     Auditoria completa e pendências de Recrutamento (vaga↔setor): `docs/claude/auditoria-permissoes-gestor.md`.
     Testes: `integration_matriz_permissoes.php` (PHP), `tests/matriz-permissoes.spec.js` (fixture) e `tests/matriz-permissoes-real.spec.js`
     (Playwright contra a aplicação real: `php -S 127.0.0.1:8010 -t public` + banco de desenvolvimento; não rodar junto com a suíte PHP).
   - **Efeito operacional conhecido:** viewer com `recruitment_webhooks.*` deixa de abrir Integrações; `candidaturas.*`, `pipeline.*`,
     `indicacoes.*`, catálogos (`empresas`, `setores`, `cargos`, `beneficios`, `avaliacoes`, `vagas`), `movimentacao_pessoal.*`,
     `comunicacoes`, `pesquisa_experiencia`, `manual` e `colaboradores.editar` não concedem nada ao viewer (já sem rota aberta a ele,
     exceto webhooks). Nada foi apagado de `usuario_permissoes`.
   - **PENDENTE DE DECISÃO (dependência não verificada):** `entrevista_desligamento.*`, `mensagens.*` e `pesquisa_reacao_integracao.*`
     — o backend NÃO filtra por setor (lista nominal de desligados / resultados individuais / configuração), então um viewer que as
     possua vê dado global. Não foram retiradas por falta de evidência sobre uso operacional legítimo. Rodar
     `database/diagnosticos/2026-11-20-permissoes-perfil-impacto.sql` (BLOCO 3) e decidir: migrar para RH/Gestor ou retirar.
   - **Cobertura:** `integration_permissao_perfil_politica.php` (61 verificações, HTTP em processos separados) e
     `unit_permissao_perfil_cobertura.php` (catálogo completo, listas disjuntas, gates dos dashboards, ordem validar→gravar).

9. **Módulos pendentes fechados ao viewer (Etapa 2B complementar, 2026-11) — CORRIGIDO.** `entrevista_desligamento.*`,
   `pesquisa_reacao_integracao.*` e `mensagens.*` passaram de "pendente de decisão" a **incompatíveis com o viewer**
   (`PermissaoPerfilPolicy`): o backend nega por `Authorization::usuarioTemPermissao` em todas as ações (lista, gerar, regenerar,
   cancelar, resultado individual por ID, campanhas, resultados de pesquisa, mensagens) mesmo com a permissão gravada. O dashboard
   AGREGADO da Entrevista de Desligamento tem permissão própria e segue aberto ao viewer autorizado. Registros em `usuario_permissoes`
   e dados dos módulos NÃO foram tocados. Impacto: viewers que operavam entrevistas, pesquisas de reação ou mensagens perdem esse acesso;
   quem precisar deve ser reclassificado (Admin/RH hoje; Gestor com escopo setorial no futuro) por decisão administrativa — nunca por
   privilégio global automático. Cobertura: `integration_modulos_pendentes_viewer.php` (119 verificações). Ver também o item 8.
   **Achado:** o item 8 descrevia as permissões "transitórias" como filtradas por Setor Gerenciado — isso só vale para Colaboradores e
   Avaliação de Experiência; os demais módulos usam outro critério (ver roadmap-tecnico.md, "Preparação do perfil Gestor").

10. **Perfil Gestor — estrutura (Etapa 2C.1, 2026-11) — IMPLEMENTADA; módulos operacionais ficam para a Etapa 2C.2.**
   - **Estrutura:** migration `2026-11-22-usuarios-role-gestor.sql` (+ rollback BLOQUEADO se existir conta `gestor`; nunca converte contas) inclui `gestor`
     no ENUM `usuarios.role`, preservando `NOT NULL DEFAULT 'viewer'`, charset e collation. **Ordem segura:** o código consulta o schema
     (`Autoridade::schemaSuportaGestor()`); antes da migration o Portal segue só com admin/rh/viewer e nada tenta gravar `gestor`.
   - **Resolvedor central de escopo:** `EscopoSetorial` — GLOBAL (admin/RH/Supervisor Geral), SETORES (gestor; conjunto vazio ≠ global) ou
     NENHUM; única fonte = `usuario_setores_gerenciados` (nunca `gestor_usuario_id`, `is_gestor` legado ou `usuario_setores`). Já usado por
     Colaboradores e Avaliação de Experiência. Viewer: escopo restrito só em TRANSIÇÃO (`VIEWER_LEGADO_TRANSITORIO`), para não retirar o
     acesso dos viewers-gestores atuais; na arquitetura definitiva = NENHUM (decisão da etapa de migração).
   - **Gestor hoje:** perfil conhecido, sem poder administrativo, sem privilégio por `is_supervisor`, rotas só da Central, Colaboradores
     (escopo setorial, sem salário) e Avaliação de Experiência (visualizar). Gates `requireRole` NÃO receberam `gestor` em massa (teste estático).
   - **ACHADO — CSRF não imposto em ações de escrita (pré-existente; CORRIGIDO na Etapa 2C.2A — ver item 11):**
     `AdminAvaliacoesExperienciaController` (salvar/concluir/ciencia/reabrir), `AdminAvaliacoesDesempenhoController`, `AdminFeedbacksController`
     (e, a verificar, PDI) chamam `Security::csrfCheck(...)` SEM usar o retorno — a requisição é processada mesmo com token inválido/ausente.
     Por isso `avaliacao_experiencia.avaliar` (e os demais módulos de escrita) NÃO foi liberado ao Gestor; entra na 2C.2 junto com a correção
     (trocar por `if (!Security::csrfCheck(...)) { 400 }`).
   - **Pendências da 2C.2:** Desempenho, Feedback, PDI, Solicitação de Vaga, Kanban e Integração do Colaborador (escopo ainda não setorial);
     relatório de reclassificação: `database/diagnosticos/2026-11-21-gestor-reclassificacao-relatorio.sql`. Cobertura:
     `integration_perfil_gestor_estrutura.php`, `integration_migration_role_gestor.php` (schema isolado), `unit_rotas_viewer_cobertura.php`.
11. **Escopo setorial em Avaliação de Desempenho, Feedback e PDI (Etapa 2C.2A, 2026-11) — IMPLEMENTADO; CSRF CORRIGIDO.**
   - **Regra permanente:** o acesso por LINHA é o setor ATUAL do contrato (`colaboradores_metadados.codigo_setor`, lido na hora — nunca o
     snapshot gravado no registro) ∈ Setores Gerenciados do ator (`usuario_setores_gerenciados`, via `EscopoSetorial::permiteColaborador()` /
     `sqlColaborador()`). `gestor_usuario_id` passou a ser SÓ o responsável registrado (exibição/autoria), nunca fonte de acesso. Colaborador sem
     setor = só escopo global; transferido = acompanha o setor novo; cada contrato (múltiplos contratos) é avaliado pelo seu setor; desligado = segue
     o setor do contrato (histórico consultável ao gestor do setor; regras de elegibilidade para NOVOS registros continuam valendo). Perder um setor
     retira o acesso na hora; o registro, a autoria e o responsável permanecem intactos e visíveis a Admin/RH (nada é reatribuído).
   - **Defesa em profundidade:** além de listar/detalhe/escrita, a CRIAÇÃO valida o `metadados_id` do POST contra o escopo, a busca/abertura de
     formulário de contrato é filtrada (não vazam dados do colaborador nem avaliações anteriores) e o PDI filtra "PDIs do contrato" e origens.
     Repositórios aceitam `?EscopoSetorial` (`null` = sem restrição, só uso interno/teste; os services sempre informam o escopo do ator).
   - **Permissões do Gestor (2C.2A):** `avaliacao_desempenho.visualizar/avaliar`, `feedback.visualizar/avaliar`, `pdi.visualizar/gerenciar/acompanhar`
     (somando Colaboradores e Experiência-visualizar da 2C.1). `avaliacao_experiencia.avaliar` segue fora (decisão pendente). Reabrir concluído segue
     só Admin/RH.
   - **CSRF — RESOLVIDO (era o achado do item 10):** `AdminFeedbacksController`, `AdminAvaliacoesDesempenhoController` e
     `AdminAvaliacoesExperienciaController` agora usam `exigirCsrf()` (400 + `exit`, nada processado). O PDI já impunha CSRF (`entrarPost`).
   - **IMPACTO a conferir em produção (viewers legados):** viewers que operavam esses módulos como "gestor responsável" (`gestor_usuario_id`) e
     NÃO têm Setores Gerenciados passam a ver lista vazia (a regra do Gestor definitivo é por setor). Os 9 viewers transitórios (78, 81, 83, 87,
     89, 90, 93, 95, 97): só 78, 81, 87 e 97 têm setores; os demais perdem a visão por responsável até serem reclassificados/receberem setores.
   - **Residuais:** (a) `PdiService::usuariosParaEscolha` lista todos os usuários ativos (id/nome/role) a quem tem `pdi.gerenciar` — usado para escolher
     o responsável de ações; o Gestor passa a vê-lo (exposição só de nomes, preexistente para o viewer-gestor); (b) o aviso do PDI "o gestor
     responsável não possui pdi.visualizar" descreve o responsável registrado, não o acesso (que agora é por setor); (c) `MovimentacaoPessoal` lê
     `avaliacoes_desempenho` por conta própria e continua fora do perfil Gestor. Cobertura: `integration_gestor_escopo_desempenho_feedback_pdi.php`.
12. **Solicitação de Vaga, Kanban de Vagas, Integração do Colaborador e Experiência/avaliar (Etapa 2C.2B, 2026-11) — IMPLEMENTADO, com decisões pendentes.**
   - **Solicitação/Kanban (Gestor):** acesso por LINHA = `solicitacoes_vaga.setor_id` ∈ Setores Gerenciados (`EscopoSetorial::sqlSetorId()`);
     solicitante/aprovador designado e `is_supervisor` NÃO dão acesso (fora do setor = não encontrada). O Gestor exige a permissão individual
     (`solicitacao_vaga.visualizar` p/ lista/detalhe; `.criar` p/ criar; `kanban_vagas.visualizar`/`.movimentar`; anotação = `kanban_vagas.movimentar`).
     Criação revalida o setor no backend (contexto do usuário ∩ Setores Gerenciados; `setor_id` do POST é ignorado), bem como colaborador
     substituído/gestor solicitante. Movimentação e anotação revalidam o escopo A CADA POST (`findAccessible`). Listas de apoio (setores, gestores,
     colaboradores, centros de custo, contexto do solicitante) só com o setor gerenciado. Viewer/Admin/RH: regra legada INALTERADA.
   - **NÃO liberado (decisão pendente):** aprovação de líder/RH pelo Gestor (não existe código de permissão de aprovação nem política: `requireRole`
     segue sem `gestor` em `approveLeader/approveRh`; um aprovador designado que seja Gestor NÃO consegue aprovar — o fluxo só avança por Admin/RH
     /Supervisor Geral ou por viewer designado); `solicitacao_vaga.editar/cancelar` e `kanban_vagas.editar` (sem operação implementada).
   - **Integração do Colaborador — FECHADA a Viewer e Gestor.** Resultados, Dashboard e QR são GLOBAIS (por data de integração), trazem comentários
     livres com cargo/empresa e não têm amostra mínima (setor pequeno = reidentificação de quem respondeu; o RH já havia pedido que a identidade nunca
     apareça). `integracao_colaborador.*` passou a INCOMPATÍVEL para o Viewer (antes transitória) e segue incompatível ao Gestor; registro individual
     (`updateIntegracao`/`gerarPesquisaIntegracao`) já era Admin/RH. Para liberar ao Gestor é preciso decidir: regra de amostra mínima por setor e se
     comentários entram. **Impacto:** viewers com `integracao_colaborador.*` gravada perdem a tela (permissão gravada não vale).
   - **Pipeline/Candidaturas/Indicações:** seguem só Admin/RH (testado: 403 ao Gestor).
   - **Experiência — `avaliacao_experiencia.avaliar` liberada ao Gestor** após revisão: escopo (`temAcessoAoColaborador`) em salvar/concluir/ciência/
     detalhe, permissão, CSRF (2C.2A), tipo whitelist, regras de desligado/concluído e reabrir só Admin/RH. Endurecido: o `gestor_usuario_id` enviado
     no POST agora só vale para Admin/RH (antes qualquer um podia registrar um usuário arbitrário como responsável). Resíduo: mensagem distinta
     "sem acesso" × "não encontrado" em `contratoParaAvaliar` revela a existência do contrato (sem dados).
   - **Residuais:** (a) o JSON de `/api/solicitacoes-vaga/move` só é testado no próprio processo (php://input); (b) viewers legados seguem com
     Solicitação/Kanban por solicitante/aprovador (transição); (c) `solicitacao_vaga.*`/`kanban_vagas.*` continuam "transitórias" para o Viewer.
     Cobertura: `integration_gestor_solicitacoes_kanban_integracao.php`.
13. **Aprovação de líder pelo Gestor e Dashboard agregado de Integração (Etapa 2C.2C, 2026-11) — IMPLEMENTADO.**
   - **Aprovação (`solicitacao_vaga.aprovar_lider`, nova no catálogo — migration `2026-11-23-permissoes-aprovacao-lider-e-integracao-agregada.sql` + rollback, manual):**
     o Gestor só aprova/reprova a etapa de LÍDER e SOMENTE com TODAS as condições, revalidadas em `SolicitacaoVaga::approve()` (não só no controller): permissão,
     designação formal (`solicitacao_vaga_aprovacoes.destinatario_usuario_id` = ele), Setor Gerenciado da solicitação (fora do setor = "não encontrada"), solicitação
     em `pendente_lider` e não cancelada/fechada, CSRF e auditoria (`approval_lider_imediato`). Os parâmetros de RH/supervisor são ignorados para o Gestor; etapa de RH =
     403 (rota) / recusa (model). Viewer DESIGNADO segue aprovando (compatibilidade temporária — remover só após a reclassificação controlada); Admin/RH/Supervisor Geral inalterados.
   - **Dashboard agregado (`dashboard_integracao_agregado.visualizar`, nova):** `/admin/dashboard-integracao-agregado` (+ `/dados` JSON), serviço próprio
     `DashboardIntegracaoAgregadoService`. Escopo = `usuario_setores_gerenciados` (AND obrigatório no repositório; filtros fora do escopo são descartados). Privacidade
     no backend: mínimo 5 respondentes DISTINTOS (pessoa) após os filtros; abaixo disso só "dados insuficientes"; supressão complementar de grupos (setor/mês) para o
     resíduo nunca isolar < 5; recusa de combinações de filtros cuja visão mais ampla (mês→ano→todo, empresa/unidade, setor — todas as combinações) diferenciaria < 5 pessoas;
     fluxo individual/"integrações realizadas" só com >= 5; saída de estrutura fechada (sem comentário, nome, cargo, id, data de resposta).
     O Gestor continua SEM o dashboard operacional, resultados, QR e campanhas (`integracao_colaborador.*` incompatível). Viewer: 403.
   - **Limites conhecidos (k-anonimato não é garantia absoluta):** (a) ataques com MUITAS consultas combinadas (trackers) ou com conhecimento externo sobre quem respondeu
     não são totalmente evitáveis por regra local — o guard cobre diferenças entre a visão pedida e as visões mais amplas obteníveis relaxando filtros, não toda combinação de
     consultas; recomenda-se registrar/limitar a frequência das consultas se o risco aumentar; (b) com 5 respondentes, distribuições por pergunta mostram a distribuição das
     notas (não quem deu qual); (c) a unicidade da pessoa usa `codigo_pessoa` (fallback contrato/colaborador). Cobertura: `integration_gestor_aprovacao_lider_vaga.php`,
     `integration_gestor_integracao_agregada.php`.
14. **Expiração de sessão (2026-11) — CORRIGIDA; prazo na hospedagem a CONFIRMAR em produção.**
   - **Causa dos "20 minutos":** `Security::enforceInactivityTimeout(1200)` fixo no bootstrap (a mensagem tinha o número escrito). Mas havia um SEGUNDO relógio oculto:
     `session.gc_maxlifetime` nunca era definido (padrão do PHP = 1440 s ≈ 24 min), então o coletor de sessões apagava o arquivo de qualquer sessão ociosa por ~24 min,
     mesmo que o limite lógico subisse — a pessoa cairia no login SEM mensagem. O cookie (`lifetime 0`) nunca foi a causa (cookie de sessão do navegador, não expira por tempo).
   - **Política central:** `app/core/SessionPolicy.php` (config `security.session_idle_timeout_seconds` = 28800 e `security.session_absolute_lifetime_seconds` = 86400, com limites
     de sanidade). Ociosidade 8 h renovada por QUALQUER requisição autenticada (sem ping automático); teto total 24 h desde o login (nenhuma sessão é permanente); sessão expirada
     NUNCA é renovada (é destruída no servidor); `gc_maxlifetime` é DERIVADO (teto + 1 h) e aplicado antes do `session_start`, junto de `use_strict_mode` (anti-fixação),
     `use_only_cookies` e coleta ativa (1/100). Cookie continua HttpOnly, Secure em HTTPS, SameSite=Lax, sem persistência após fechar o navegador. ID regenerado no login (inalterado).
   - **Formulários longos:** expirar durante o preenchimento exige 8 h parado na mesma tela. Agora: POST após expirar NÃO é processado e o login avisa "o envio NÃO foi salvo";
     GET volta à página (`next`, só caminho do mesmo site em /admin — sem redirecionamento aberto); chamada AJAX/API recebe 401 JSON (a tela mostra o erro, em vez de HTML de login).
     NÃO foi implementado rascunho no navegador (guardaria dados pessoais/avaliações no cliente); se o RH quiser, a opção segura é `sessionStorage` por aba, só dos campos não sensíveis,
     apagado ao enviar/sair — requer decisão. Cache: o PHP já envia `no-store` em páginas com sessão.
   - **Hospedagem (Hostinger) — confirmar:** a aplicação pede `gc_maxlifetime` por `ini_set` e usa pasta PRÓPRIA (`storage/sessions`), então cron/coletor de outras aplicações não a varre.
     O que só o servidor de produção pode provar: (1) que `ini_set` não está bloqueado; (2) que a pasta `storage/sessions` é gravável e não é limpa por rotina da hospedagem; (3) que o
     PHP-FPM não recicla sessões por outro meio. Prova: logado como Supervisor Geral, abrir `/admin/usuarios/supervisor/sessao` (JSON somente leitura) e conferir
     `prazo_garantido: true` e `session.gc_maxlifetime` = 90000; se `false`, a hospedagem bloqueou o `ini_set` — ajustar `session.gc_maxlifetime` (>= 90000) no hPanel (PHP Configuration)
     ou em `.user.ini`. A aplicação registra um aviso no log (1x por sessão) quando o servidor não aceita o valor. Cobertura: `integration_sessao_politica.php`.
15. **E-mail (SMTP/PHPMailer) e dependências Composer (2026-11) — IMPLEMENTADO; envio DESLIGADO por padrão.**
   - **Causa do não recebimento da recuperação de senha:** o `Mailer` usava `@mail()` (sem SMTP, erro suprimido, resultado ignorado, sem log) com remetente de exemplo
     (`no-reply@seu-dominio.com.br`): a hospedagem recusava/descartava e a tela sempre dizia "enviado". Agora: PHPMailer 6.12 + SMTP autenticado (TLS implícito 465 ou STARTTLS 587),
     certificado SEMPRE validado (sem opção para desligar; só CA adicional `smtp_cafile`), resultado por ETAPA (config/conexão/TLS/autenticação/remetente/destinatário/envio) e log seguro.
   - **Configuração (`mail.*`, app/config/local.php — a senha SÓ aí):** enabled, driver, from, from_name, reply_to, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, smtp_timeout, smtp_cafile.
     Os arquivos versionados vêm com `enabled = false` e SEM senha. Nada é enviado até ligar e testar.
   - **Recuperação de senha:** token de 32 bytes, só o SHA-256 no banco, uso único, 30 min, tokens anteriores invalidados — inalterados. NOVO: limite de 3 por conta e 10 por IP em 15 min
     (conta existente OU não, com a MESMA resposta; chaves só em hash). O limite por IP usa `Security::clientIp()` (aceita X-Forwarded-For como o login): atrás de proxy não confiável ele é contornável;
     o limite por conta continua valendo.
   - **Diagnóstico (Supervisor Geral):** `/admin/usuarios/supervisor/email` (configuração efetiva sem senha) e envio de teste (CSRF, 1 destinatário, texto fixo, 5/hora, auditado). "Aceito pelo SMTP" NÃO prova entrega:
     spam, SPF/DKIM do domínio e política do destinatário continuam valendo.
   - **Dompdf 3.1.5 → 3.1.6 (+ masterminds/html5 2.12.0, sabberworm/php-css-parser 9.5.0; thecodingmachine/safe removido):** corrige os 6 avisos (CVE-2026-59943/59942/59941/56722/55555/55554); `composer audit` limpo.
     O `CartaPropostaPdfService` passou a usar `chroot` próprio (vazio), sem PHP/JavaScript embutidos, além de `isRemoteEnabled=false`; o template não usa imagem nem arquivo local e escapa todo dado. PDFs gerados antes e depois: mesmas páginas, mesmo texto, mesmo tamanho.
   - **Residuais:** aceite pelo SMTP ≠ entrega; o limite por IP depende da confiabilidade do IP do cliente; a senha SMTP em `local.php` é um segredo de servidor (permissões do arquivo); contas sem e-mail válido nunca recebem avisos.
16. **Credenciais administrativas nos testes e sessões após troca de senha (2026-11-26) — CORRIGIDO no código; rotação e histórico PENDENTES.**
   - **Causa:** 11 specs Playwright usavam `process.env.ADMIN_* || '<valor fixo>'` com e-mail e senha de uma conta administrativa real; sem as variáveis, os testes
     entravam com a conta real. Os valores continuam no **histórico do Git** (e em arquivos fora dos testes, abaixo) — o código atual não os remove do passado.
   - **Correção (testes):** `tests/support/credenciais-admin.js` — só `ADMIN_EMAIL`/`ADMIN_PASSWORD` do ambiente local, sem valor padrão; sem elas o teste FALHA com
     mensagem explícita. Use uma conta de teste do banco de desenvolvimento.
   - **Causa (sessões):** a troca de senha não encerrava sessões já abertas — quem tivesse uma sessão (inclusive roubada) continuava logado até expirar (até 24 h).
   - **Correção (sessões):** no login, `Auth::establishSession` grava `credencial_impressao` = SHA-256 do `senha_hash` (nunca o hash); a cada requisição autenticada,
     `Security::enforceInactivityTimeout` → `SessionPolicy::avaliarSessaoAutenticada()` (antes `avaliarCredencial`, ver item 18) compara com o banco. Qualquer troca de senha (recuperação por e-mail, redefinição
     administrativa, senha temporária de Colaboradores → Acesso, script CLI ou SQL manual) gera um hash novo e encerra TODAS as sessões anteriores na próxima
     requisição (login com `motivo=senha`; AJAX 401; POST não processado). Usuário excluído ou falha de leitura = sessão encerrada. Sem migration (reusa `senha_hash`).
     Custo: 1 SELECT por chave primária por requisição autenticada. ~~Sessões abertas ANTES do deploy adotam a credencial vigente~~ — adoção REMOVIDA na
     Segurança 3.3 (item 18): sessão sem impressão é encerrada. Cobertura: `tests/php/integration_sessao_revogacao_senha.php`.
   - **Senha da conta exposta:** trocada em produção pelo "Esqueci minha senha" (informado pelo Fabio em 2026-11-26).
   - **Saneamento dos arquivos atuais (2026-11-26) — CONCLUÍDO:** nenhuma senha, e-mail administrativo real ou hash bcrypt nos arquivos versionados.
     `scripts/init.php` e `scripts/reset_password_cli.php` sem senha padrão: senha SÓ por variável de ambiente (`RH_ADMIN_SENHA`/`RH_NOVA_SENHA`) ou digitação no
     terminal, nunca por argumento, validada pela `PasswordPolicy`; sem ela, encerram sem alterar nada (`scripts/lib/senha_cli.php`). `reset_password_cli` não
     imprime mais o hash nem um SQL de "fallback" com o hash. `scripts/setup_full.php` só cria administrador com `SETUP_ADMIN_EMAIL`/`SETUP_ADMIN_SENHA`.
     `install.php`/`production_install.php` sem e-mail padrão; placeholder genérico na tela de login; `AUTH_PERMISSOES_FIX.md` sem credenciais.
     `security.supervisor_password` vazio em `config.php`/`config.example.php` (definir só em `local.php`); `User::ensureSupervisor` nunca cria conta com senha vazia
     (sem senha configurada, "garantir Supervisor" é recusado — a inicialização do Portal não depende desse valor). Dumps `recrutamento.sql` (raiz e `database/`):
     linha `usuarios` id 1 mantida (relacionamentos), com nome/e-mail fictícios (`.invalid`) e `senha_hash` não-bcrypt (nenhuma senha autentica).
     Cobertura: `tests/php/integration_scripts_sem_senha_padrao.php`.
   - **Pendente:** histórico do Git (os valores antigos seguem nos commits anteriores — ver estratégia no relatório de 2026-11-26; não reescrito); PII de candidatura
     no dump (item 1); `scripts/verify_password_cli.php` ainda recebe a senha como argumento (sem valor padrão). ~~Sessão de usuário DESATIVADO continua até
     expirar~~ — corrigido na Segurança 3.3 (item 18).
17. **Sessões anteriores ao deploy da revogação por senha (2026-11-26) — RESOLVIDO na Segurança 3.3 (item 18): sessões legadas são encerradas, sem adoção.** Registro original: Sessões abertas antes do commit
   9198876 não têm `credencial_impressao`; `SessionPolicy::avaliarCredencial()` as faz ADOTAR a senha vigente na 1ª requisição após o deploy. Se a senha tiver sido
   trocada ANTES dessa 1ª requisição (caso da conta exposta, trocada antes/durante o deploy), uma sessão antiga ainda viva adota a senha nova e segue válida até o
   teto de 24 h desde o login (ou 8 h de inatividade). Janela máxima: 24 h após o deploy (cada sessão antiga morre no máximo 24 h após o próprio login). Mitigação opcional,
   se desejada: encerrar todas as sessões uma vez (apagar os arquivos de `storage/sessions` no servidor) — derruba todos os usuários; decisão do Fabio.
18. **Segurança 3.3 — revogação de sessões legadas, desativação imediata e autoridade relida do banco (2026-11-27) — CORRIGIDO.**
   - **Causas:** (1) `SessionPolicy::avaliarCredencial()` ADOTAVA a senha vigente em sessões sem `credencial_impressao` (sessões anteriores a 9198876 sobreviviam a
     uma troca de senha feita antes da 1ª requisição); (2) a desativação (`usuarios.email_verified_at = NULL`) só era checada no login — a sessão aberta continuava
     até expirar (até 24 h); (3) a autoridade (`user_role`, `user_is_supervisor`) era gravada na sessão SÓ no login e lida de lá por `Auth::role()`, gates,
     `Authorization` (ator atual), navegação e dezenas de controllers/views — rebaixar um Admin ou revogar o flag de Supervisor só valia após novo login.
   - **Correção (fonte única):** `Auth::autoridadeEfetiva(cadastro)` calcula a autoridade (mesmas regras de sempre: Supervisor Geral = admin +
     flag, decidido por `Autoridade::ehSupervisorGeral`; gravação na sessão só por `Auth::aplicarAutoridadeNaSessao`, flag SOMENTE com role admin; role vazia legada → admin+supervisor só para o e-mail do supervisor configurado, senão viewer; role desconhecida = sem autoridade) e é usada no login E a
     cada requisição. `SessionPolicy::avaliarSessaoAutenticada()` roda no bootstrap (`Security::enforceInactivityTimeout`), ANTES de qualquer gate/controller/view,
     com UMA consulta (`User::estadoParaSessao`: `senha_hash, role, is_supervisor, email_verified_at, email` por chave primária — a mesma consulta que já existia, com mais colunas; 0 consultas
     adicionais): sessão sem âncoras do login (`credencial_impressao` + `conta_ativa_desde`) = LEGADA, encerrada (`motivo=seguranca`); conta desativada = encerrada
     (`motivo=desativada`); `email_verified_at` diferente do login (desativada e reativada entre requisições) = encerrada (`motivo=situacao`, exige novo login);
     senha trocada = encerrada (`motivo=senha`); usuário excluído/erro de leitura = encerrada (falha fechada, `motivo=seguranca`). Só com tudo válido, `user_role`
     e `user_is_supervisor` são REESCRITOS do banco — mudança de perfil/flag vale na mesma requisição, SEM desconectar. Nenhum controller foi alterado.
   - **GET/POST/AJAX:** sessão encerrada → GET vai ao login com a mensagem do motivo (e retorno seguro `next`); POST NÃO é processado (`perdido=1`); AJAX/API recebe
     401 JSON (`erro = sessao_expirada`, contrato do front-end preservado; `motivo` detalha). Operação não permitida pelo perfil atual → 403 do gate de sempre.
   - **Implantação:** TODA sessão aberta antes deste deploy é legada (não tem `conta_ativa_desde`) e será encerrada na próxima requisição, com a mensagem
     "atualização de segurança" — comunicar aos usuários que precisarão entrar novamente uma vez. Formulário enviado nesse instante não é processado (aviso no login).
     Não é preciso apagar `storage/sessions`.
   - **Testes:** `tests/php/integration_seguranca_33_autoridade.php` (sessões legadas/impressão incorreta/cookie reutilizado; desativação GET/POST/AJAX, login
     recusado, reativação exige novo login, excluído; Admin→Viewer, Admin→RH, RH→Gestor, Gestor→Viewer, Viewer→Gestor com e sem permissão individual, duas
     sessões, navegação; Supervisor válido, flag revogado, flag com role RH, SG rebaixado; dashboard do Viewer; inatividade). Mutações (sem reescrita de autoridade,
     adoção de legado, sem checagem de desativação) detectadas.
   - **Residuais / compatibilidades transitórias:** a regra legada "role vazia + e-mail do supervisor configurado ⇒ admin + Supervisor" foi PRESERVADA (agora
     também a cada requisição, com o mesmo resultado do login); com a coluna `role` ENUM NOT NULL ela só se aplica a dado inválido gravado fora do modo estrito, mas
     o `supervisor_email` versionado é um placeholder — avaliar removê-la (mudança de regra, requer aprovação). Viewer por setor em `EscopoSetorial` e os módulos
     abertos ao Viewer por role (Movimentações, Cadastros, hub de Avaliações) NÃO foram alterados. `user_name` da sessão continua o do login (exibição, sem efeito
     de autorização). Admin/RH que trocam o próprio perfil não existem (bloqueado), então nenhum ator perde a sessão por mudança da própria autoridade.
19. **Proteção de arquivos internos contra acesso público (2026-11-27) — CORRIGIDO no .htaccess; decisões pendentes abaixo.**
   - **Evidência:** a raiz publicada em produção é a RAIZ DO REPOSITÓRIO (deploy Git da hospedagem): `/public/assets/tailwind.css` e `/composer.json`
     respondiam 200. O `.htaccess` da raiz só encaminhava ao front controller; bloqueios existiam apenas em `app/`, `database/`, `scripts/`, `storage/`
     (`.htaccess` próprios). `.git/*` e `*.sql` já davam 403 por regra da HOSPEDAGEM (não da aplicação).
   - **Confirmado exposto em produção (200, só status consultado, conteúdo NÃO aberto):** `.cpanel.yml`, `.gitignore`, `.serena/project.yml`, `.dbg/*.ndjson`
     (logs de depuração), `composer.json/.lock`, `package.json`, `playwright.config.js`, `tailwind.config.js`, `deploy.ps1`, `CLAUDE.md`, `README.md` e
     demais `.md`, `docs/`, `vendor/` (ex.: `installed.json`), arquivos estáticos de `tests/` e **`assets/colaboradores.xlsx`** (versionado desde 2026-06-26,
     entrada do `scripts/import_colaboradores_xlsx.php` — provável PLANILHA COM DADOS PESSOAIS de colaboradores, baixável publicamente).
   - **Potencial alto (não executado em produção de propósito):** scripts PHP de `tests/php/` executáveis pela web (sem `.htaccess`, raiz sem bloqueio de
     `.php`) — vários criam/apagam usuários e dados de teste no banco; reproduzido só localmente (Apache, banco de desenvolvimento): `run_all.php` é
     executado e aborta na linha 22 por falta de `$argv`, mas testes de integração individuais não dependem de `$argv`.
   - **Instalador ABERTO (crítico, confirmado):** `/install.php` e `/public/install.php` respondiam 200 — a trava `storage/install.done` não existe na hospedagem
     e `INSTALLER_KEY` é opcional; um POST anônimo com `allow_overwrite_config=1` sobrescreveria a configuração (inclusive `local.php`) e criaria administrador.
     Bloqueado com aprovação do Fabio (2026-11-27).
   - **Correção:** `.htaccess` da raiz — `Options -Indexes` e, ANTES do front controller, `[F]` para: ocultos (`.*`, exceto `.well-known`), diretórios internos
     (`app|database|scripts|storage|tests|docs|vendor|node_modules`), extensões de dump/config/doc/planilha/log/backup/temporário
     (`sql|sqlite|db|md|lock|yml|yaml|ini|env|log|ndjson|bak|old|orig|save|swp|tmp|dist|ps1|sh|bat|cmd|xlsx|xls|csv`), arquivos de build da raiz e
     `router.php`, e `install.php`. `public/.htaccess` (tem `RewriteEngine` próprio, não herda a raiz): `install.php` bloqueado. Permanecem servidos:
     `/assets/*` (CSS, JS, imagens, `favicon.svg`), `/public/assets/*`, `/uploads/logos/*`, rotas da aplicação, APIs públicas, páginas com token e downloads
     por endpoint (todos passam pelo `index.php`). `logs.php` mantido (falha fechada: 403 sem `logging.viewer_key`).
   - **Testes:** `tests/php/unit_htaccess_protecao.php` (regras reais aplicadas com PCRE a caminhos negados/servidos; ordem antes do front controller) e
     validação HTTP real em Apache 2.4 local com a raiz no repositório: 64/64 (todos os internos 403/404, todos os permitidos servidos).
   - **Decisões pendentes:** (1) `assets/colaboradores.xlsx` — confirmar o conteúdo e, se tiver dados pessoais, removê-lo do repositório (o script de
     importação lê o arquivo do disco; pode receber o caminho por argumento) e tratar o histórico do Git; considerar que pode ter sido baixado enquanto
     esteve público. (2) Criar `storage/install.done` na hospedagem (trava nativa do instalador) e/ou remover `install.php` do deploy. (3) `.cpanel.yml`
     (outra conta cPanel; limpeza destrutiva) — remover ou corrigir. (4) `.dbg/` e `.serena/`, `.claude/` versionados — avaliar remoção do repositório.
     (5) O bloqueio de `.git`/`.sql` na produção também dependia da hospedagem; agora a aplicação garante por conta própria.
20. **Segurança 3.4 — saneamento de arquivos sensíveis e proteção do instalador no PHP (2026-11-27) — CORRIGIDO no estado atual; histórico PENDENTE.**
   - **Auditoria (só tipos e contagens; nenhum valor reproduzido):** `assets/colaboradores.xlsx` = dados REAIS (aba de colaboradores com ~404 linhas: nome,
     empresa, CPF, admissão, nascimento, cargo, demissão, motivo de rescisão; aba de entrevistas de desligamento ~131 linhas; aba colaborador→gestor);
     `storage/imports/*.json` (14 relatórios do importador, jun–jul/2026, versionados antes do `.gitignore`) = ~1.400 nomes e ~28 sequências de 11 dígitos;
     `recrutamento.sql` e `database/recrutamento.sql` (idênticos) = 11 candidaturas (nome, e-mail, telefone, CPF, experiência, arquivo de currículo,
     indicações), 35 históricos com observações, 27 movimentações, 24 nomes de colaboradores, 10 auditorias com IP; `.dbg/` = logs de depuração do front-end
     SEM dado pessoal (só `127.0.0.1` em rastros de pilha) — mantido. CPFs em `tests/`, views e JS são fixtures/placeholders.
   - **Correção:** planilha e relatórios RETIRADOS do índice (`git rm --cached`; a planilha já havia sido apagada da cópia local pelo Fabio; relatórios ficam
     no disco local e são ignorados); `.gitignore` impede `*.xlsx|xls|csv` em `assets/` e `public/assets/`. Dumps: os 6 `INSERT` com dados pessoais trocados por
     comentário — estrutura e dados organizacionais (cargos, setores, empresas, benefícios, etapas do pipeline, vagas, conta fictícia saneada) mantidos; importação
     validada num banco temporário isolado. `scripts/import_colaboradores_xlsx.php` exige `--file` (sem fallback). O teste do leitor XLSX passou a gerar uma
     planilha FICTÍCIA temporária (dependia da planilha real).
   - **Efeito no deploy (Git pull):** a planilha e os 14 relatórios versionados são APAGADOS do disco de produção no próximo deploy (não são lidos por nenhum
     código; os relatórios só são escritos). Relatórios gerados pela própria produção (não versionados) não são tocados.
   - **Instalador:** guarda no próprio PHP, comum a `/install.php` e `/public/install.php` (este só inclui aquele): `Installer::acessoWeb()` — desabilitado por
     padrão (403), habilitar exige `RH_INSTALADOR_WEB=1` (ou `security.installer_web_enabled` em `local.php`) E chave >= 32 caracteres em `INSTALLER_KEY` (ou
     `security.installer_key` em `local.php`), ambas fora do Git; POST só com a chave (tempo constante; antes era OPCIONAL); instalação concluída = sempre
     bloqueado. Defesa em profundidade: `Installer::run()` recusa execução web sem habilitação antes de qualquer escrita (a CLI `production_install.php` segue).
     Testado sem Apache (`php -S`, sem `.htaccess`): 403 nos dois caminhos; habilitado, POST sem/errado chave = 403; configs intactas.
   - **Residual:** os dados retirados continuam no HISTÓRICO do Git (commits anteriores) e no GitHub — exige decisão sobre reescrita (`git filter-repo`) e
     limpeza de caches; considerar que a planilha pode ter sido baixada enquanto esteve pública (até 46fed12). Recomendado: guardar planilhas operacionais fora
     da raiz publicada. Criar `storage/install.done` na hospedagem continua recomendado (trava nativa).
21. **Segurança 3.5A — schema consolidado e dumps legados removidos (2026-11-27) — CONCLUÍDO no estado atual; histórico = 3.5B.**
   - `recrutamento.sql` e `database/recrutamento.sql` REMOVIDOS do repositório. `database/schema.sql` passou a ser a única base de instalação:
     ganhou `pipeline_stages` (+ as 6 etapas iniciais, `INSERT IGNORE`), `pipeline_movements`, `notas_recrutador`, `requisitos` e `beneficios`
     (estrutura BASE idêntica à do dump, sobre a qual as migrations aplicam) e alinhou `candidaturas.telefone` (VARCHAR(40)) e
     `indicacao_pagamento_auditoria.created_at` (NOT NULL) à linha da produção. `candidaturas.stage_id/observacoes` seguem criados em runtime.
   - `Installer` e `scripts/setup_full.php` leem SOMENTE `database/schema.sql` (um `recrutamento.sql` que reapareça nunca é importado).
   - Homologação (`tests/php/integration_schema_instalacao.php`, bancos temporários `zz35*` apagados no fim): instalação nova (importSchema +
     SchemaManager::ensure + garantias de runtime) cria as tabelas, FKs íntegras e as etapas, sem dado de pessoa; EQUIVALÊNCIA com a base antiga —
     mesmo fluxo a partir do dump saneado (lido do Git) e TODAS as migrations aplicadas aos dois: mesmas 68/87 migrations aceitas, mesmas 72 tabelas,
     colunas e FKs. As 19 migrations recusadas falham igualmente na base antiga (pré-existente: dependem de estado de produção/ordem manual).
   - **Efeito no deploy:** o próximo `git pull` apaga os dois dumps do disco de produção (já bloqueados por HTTP; nenhum código os lê).
   - **Pendente (3.5B):** dados pessoais e credenciais antigas continuam no HISTÓRICO do Git — reescrita planejada na Segurança 3.5 (simulação
     validada; backup em `portalrh-backup-seguranca35`), aguardando autorização.
