# Riscos Conhecidos — RH Madeplant

> Regra permanente (ver `CLAUDE.md`): riscos abaixo **nunca são corrigidos silenciosamente** —
> qualquer correção passa pelo processo de segurança (`checklists.md`), com conversa dedicada
> sobre blast radius, mesmo que a correção pareça óbvia.

Identificados na auditoria arquitetural de 2026-07-09, documentados aqui para não serem
"descobertos" e corrigidos por impulso em uma tarefa não relacionada:

1. **PII real de candidatos/colaboradores commitada em `recrutamento.sql`** (ambas as cópias —
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
