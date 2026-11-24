# Vagas × setor responsável (Etapa 3.2A — 2026-11)

Base estrutural para a futura autorização de Gestores em Recrutamento (Etapas 3.2B/3.2C). **Esta etapa não concede nenhuma permissão**:
`vagas.*`, `candidaturas.*`, `pipeline.*` seguem bloqueadas na `PermissaoPerfilPolicy` e os endpoints seguem exigindo Admin/RH.

## 1. Diagnóstico (esquema real inspecionado)

* `vagas`: sem coluna de setor; `area` varchar(100) **texto livre**; `solicitacao_vaga_id` **opcional** (UNIQUE, FK ON DELETE SET NULL);
  `empresa_id` opcional. `candidaturas.vaga_id` → `vagas.id` (FK). `vaga_beneficios`, `requisitos` também referenciam a vaga.
* `setores`: PK `id` (INT), `codigo_setor` varchar(8) UNIQUE **nullable** (setor "oficial" = tem código), `nome`/`slug` UNIQUE, `ativo`.
  O Portal **nunca apaga setores** (a sincronização METADADOS só adota/renomeia/inativa; nenhum `DELETE FROM setores` no código).
* O conceito de setor já existe e é por `setores.id`: `solicitacoes_vaga.setor_id` (NOT NULL, FK), `usuario_setores_gerenciados.setor_id`,
  `colaboradores.setor_id`. O escopo do Gestor resolve o código oficial por JOIN — compatível com um `vagas.setor_id`.
* Caminhos que **criam** vaga: (1) `AdminVagasController::store` (RH/Admin), (2) `SolicitacaoVagaPublicacaoService::gerarRascunho`
  (solicitação aprovada). Nenhum outro (`Vaga::create` só é chamado pelo controller; o resto é fixture de teste).
* API pública (`VagasPublicasApiService::montarPayload`) usa **lista branca** de campos (id, titulo, area, empresa, local, resumo, url): a nova
  coluna **não** vaza. A Carta Proposta lê `vaga['area']` (texto) — inalterada.

## 2. Modelagem escolhida

`vagas.setor_id INT NULL → setores(id) ON DELETE RESTRICT ON UPDATE CASCADE`, índice `idx_vagas_setor_id`
(`database/migrations/2026-11-24-vagas-setor-responsavel.sql` + rollback).

* **Mesma chave** do restante do Portal (nenhum segundo conceito de setor); id imutável ⇒ renomear, inativar ou reconciliar com o METADADOS
  **não perde o vínculo histórico** (testado).
* **Nullable por decisão de transição**: há vagas históricas sem setor identificável; a migration não falha por elas, **não faz UPDATE** e não
  inventa setor. A obrigatoriedade para vagas **novas** é do servidor (`VagaSetorService`). `NOT NULL` só depois da regularização total
  (consulta 4 do diagnóstico precisa retornar 0).
* Independente da origem: vale para vaga direta e vaga de solicitação, abertas ou encerradas.
* O texto `vagas.area` continua sendo apenas rótulo exibido no site; **nunca** é identidade de autorização.

## 3. Regras (servidor)

| Fluxo | Regra |
|---|---|
| Criação direta (RH/Admin) | `setor_id` obrigatório; precisa ser setor **oficial ativo** (`CatalogoMetadadosRepository('setores')`). Vazio/inexistente/inativo/sem código/forjado ⇒ recusa com mensagem; nada é criado. |
| Gerar vaga de solicitação | herda `solicitacoes_vaga.setor_id`; se a solicitação não tem setor estruturado, ou o setor não é oficial ativo ⇒ **bloqueia** (mensagem clara, nenhuma vaga) — nunca escolhe outro nem usa texto. |
| Editar vaga com setor | setor continua obrigatório; não pode ser removido. |
| Trocar setor (vaga com candidaturas) | exige a **confirmação explícita** (`confirmar_mudanca_setor`) e registra em log (e na auditoria da solicitação, se houver). |
| Vaga originada de solicitação | o setor é o da solicitação; tentar outro ⇒ recusa (sem divergência silenciosa); vaga histórica de solicitação sem setor é regularizada com o da solicitação. |
| Vaga histórica sem setor | pode ser editada **sem** setor (nada é apagado/presumido); ao receber setor (regularização manual) ele passa a ser obrigatório. |
| Setor inativado depois | a vaga mantém o vínculo e pode ser editada; o setor deixa de ser elegível para vagas **novas**. |
| Migration ainda não executada | `VagaSetorService::schemaSuportaSetor()` = false ⇒ fluxo anterior (sem exigir setor). Ordem segura: **migration → código**. |

Acesso: criação/edição/regularização seguem `requireRole(['admin','rh'])` (Gestor/Viewer recebem 403 — testado).

## 4. Vagas históricas — diagnóstico e regularização

Classes: **A** setor estruturado · **B** identificável pela solicitação (vínculo estruturado) · **C** o texto de `area` coincide com o nome de um setor
oficial (**sugestão não comprovada**) · **D** sem informação suficiente.

* Somente leitura: `database/diagnosticos/2026-11-24-vagas-setor-regularizacao.sql` ou
  `php scripts/regularizar_setor_vagas.php [--csv=arquivo.csv]` (sem CPF/currículo; só **contagem** de candidaturas).
* Aplicação sem intervenção: **somente a classe B** (origem estruturalmente confiável: `solicitacoes_vaga.setor_id`, setor oficial ativo) com
  `php scripts/regularizar_setor_vagas.php --aplicar` (simulação por padrão). **C e D nunca são aplicadas automaticamente** — regularização manual na
  edição da vaga (`/admin/vagas/editar/{id}`), onde o RH confirma o setor.
* Nada disso foi executado em produção.

## 5. Plano de implantação (manual)

1. Backup/export de `vagas` (opcional). 2. Executar a migration no phpMyAdmin (banco `u172743873_portalrh`), conferir os PASSOS 2 e 3 (0 vagas com setor).
3. Publicar o código. 4. Rodar o diagnóstico (SQL ou script) e revisar A/B/C/D. 5. (Opcional) `--aplicar` para a classe B. 6. Regularizar C/D pela tela de edição.
7. Só quando `SELECT COUNT(*) FROM vagas WHERE setor_id IS NULL` = 0 e todos os caminhos de criação forem verificados: planejar `NOT NULL` (migration própria).

## 6. Decisões pendentes / riscos

* **Divergência setor da vaga × empresa da vaga** (`vagas.empresa_id` vs `setores.empresa_id`): não imposta nesta etapa.
* **Troca de setor de vaga com candidaturas**: hoje exige confirmação + log. Decisão futura (3.2B): o que acontece com a visibilidade de quem já
  acompanhava o candidato; histórico do Kanban/documentos não são alterados (continuam presos à vaga).
* **Solicitação com setor legado/inativo** bloqueia a geração da vaga — pode exigir regularização do cadastro de setores pela operação.
* Auditoria da troca de setor de vaga **sem** solicitação fica em log de arquivo (não há tabela de auditoria de vagas).
* A nova coluna não libera acesso: as Etapas 3.2B/3.2C precisam de escopo setorial em Candidaturas/Pipeline/Kanban de candidatos antes de qualquer permissão.

## 7. Etapa 3.2B.1 — consulta setorial de Vagas e Candidaturas (somente leitura; NENHUM acesso liberado)

Camada de consulta pronta e testada; **controllers inalterados** (continuam `admin/rh[/viewer]` → Gestor 403). O religamento dos controllers ao escopo
acontece na 3.2C junto da abertura do `requireRole` (religar antes mudaria o que o Viewer vê hoje em `/admin/vagas`).

* `Vaga::all(?EscopoSetorial)` / `Vaga::find($id, ?EscopoSetorial)`: `null`/global = como antes; restrito = `v.setor_id IN (setores do escopo)`
  (`EscopoSetorial::sqlSetorId`, única fonte = `usuario_setores_gerenciados`). Vaga sem `setor_id` nunca casa; schema sem a coluna + escopo restrito = nada.
* `Candidatura::listarEscopo / contarEscopo / acharEscopo`: a candidatura pertence ao escopo **só pelo setor da sua vaga** (`vagas.setor_id`), cada linha
  = uma candidatura (sem JOIN 1:N, sem duplicação); filtros (vaga, setor, etapa, período, busca por nome com curingas LIKE escapados) são sempre `AND` com o
  escopo; fora do escopo = `null`/vazio (indistinguível de inexistente). **Projeção segura**: id, vaga, nome, status, etapa, data, setor da vaga —
  sem CPF, e-mail, telefone, currículo, indicação, observações nem metadados de etapa.
* Achados da auditoria: `candidaturas.cpf` é UNIQUE (a "mesma pessoa" em duas vagas se repete por nome/e-mail); `Candidatura::all` (usada por RH) filtra setor
  pela **solicitação** (`sv.setor_id`), não por `vagas.setor_id` — mantida para RH; o escopo novo usa só `vagas.setor_id`. Ficha global do candidato **não existe**.
* Permanece bloqueado: detalhe (`show`), download/documentos, Carta Proposta/Exame, indicação, upload, `update`, Pipeline `move`, vagas
  criar/editar/excluir/publicar. Candidatura/Pipeline/Dashboard seguem Admin/RH.
* Teste: `integration_vagas_candidaturas_escopo_setorial.php` (53 verificações; mutação do escopo derruba 23). Liberação ponta a ponta = 3.2C.

## 8. Etapa 3.2B.2 — detalhe, documentos e downloads com escopo setorial (preparação; NENHUM acesso liberado)

`CandidaturaAcessoSetorialService` (novo) é a camada pronta para a 3.2C. **Nenhum controller o chama**: todos os endpoints abaixo seguem `Auth::requireRole(['admin','rh'])` (Gestor/Viewer = 403).

### Mapa de pontos de entrada (regra atual)
| Rota | Controller::método | Dado | Guarda atual |
|---|---|---|---|
| GET `/admin/candidaturas/{id}` | AdminCandidaturasController::show | detalhe completo, histórico+observações, comunicações, carta, exame, documentos | admin/rh |
| GET `/admin/candidaturas/{id}/download` | ::download | currículo (`storage/resumes`, `pdf_path`) | admin/rh + basename/realpath |
| GET `/admin/candidaturas/{id}/documentos/{docId}` | ::downloadDocumento | `storage/candidatura_documentos` | admin/rh; doc precisa pertencer à candidatura da URL |
| GET `/admin/cartas-propostas/{id}/pdf` e `/comprovante` | AdminCartaPropostaController | `storage/cartas_propostas` | admin/rh; resolve pelo id da CARTA (não confere a candidatura da URL — não há URL de candidatura) |
| POST `.../documentos`, `.../atualizar`, `.../indicacao`, `.../carta-proposta`, `.../exame-admissional`, Pipeline `move` | escrita | — | admin/rh (fora de escopo) |
| Exame Admissional | — | não há endpoint de arquivo | — |

### Armazenamento
Arquivos em `storage/{resumes,candidatura_documentos,cartas_propostas}`, fora de `public/`, com `.htaccess` "Require all denied" em `storage/` e em cada pasta (proteção **dependente de Apache**; em outro servidor exige regra equivalente). Nomes armazenados aleatórios; sem URL pública nem cache compartilhado (entrega só por controller). `public/uploads/logos` é o único diretório público (logos).

### Autorização (service)
`autorizar` (decisão no SQL: candidatura → vaga → `vagas.setor_id` ∈ escopo) → `detalhe` (projeção segura) → `arquivo(tipo: curriculo|documento|carta_pdf|carta_comprovante)`: exige a candidatura no escopo, o arquivo vinculado A ELA (`WHERE id=? AND candidatura_id=?`), e caminho por `basename`+`realpath` dentro da pasta. `null` = inexistente ou fora do escopo. `escopoNominal()` obtém o escopo: falha/ator inexistente/role vazia = NENHUM (nunca global) e Viewer = NENHUM (sem o legado transitório). Os parâmetros de escopo são não nulos (tipo): não há "sem escopo = global". Não retorna dados de outras candidaturas do mesmo nome/e-mail (não existe ficha global; `cpf` UNIQUE).

### Classificação de campos (decisão de negócio PENDENTE para tudo que está "bloqueado")
| Campo/recurso | Gestor (projeção atual) |
|---|---|
| nome, vaga, setor da vaga, etapa, status, data, histórico de etapas (de→para, data) | **liberado na projeção** |
| cargo pretendido, experiência (texto do candidato) | bloqueado — decidir |
| e-mail, telefone | bloqueado — decidir |
| CPF | bloqueado (nunca completo sem decisão) |
| currículo, documentos, Carta Proposta, Exame Admissional | bloqueado (service pronto; sem controller) |
| observações/usuário do histórico, indicação, comunicações, pesquisa, metadados de etapa (entrevista/admissão) | bloqueado — exclusivo do RH até decisão |

### Riscos registrados
* `AdminCartaPropostaController::pdf/comprovante` resolvem por id da carta — a 3.2C deve usar `arquivo(..., 'carta_pdf', $cartaId)` com a candidatura derivada do banco, nunca da URL.
* Proteção de `storage/` depende de `.htaccess` (Apache).
* Documentos do RH (`downloadDocumento`) entregam o MIME gravado no upload; validado no upload (`Upload::saveDocumento`).
* Teste: `integration_candidatura_acesso_setorial.php` (44 verificações; mutações do escopo e do vínculo documento×candidatura são detectadas).

## 9. Etapa 3.2B.3 — movimentação do Pipeline com autorização setorial (preparação; NENHUM acesso liberado)

`PipelineMovimentacaoSetorialService::mover()` é a camada pronta para a 3.2C; `AdminPipelineController::move` **não foi alterado** (segue `requireRole(['admin','rh'])`, CSRF, JSON).

### Efeitos de uma movimentação (todos na mesma transação, em `RecruitmentPipelineService::moveCandidateToStage`)
1. `candidaturas.stage_id`; 2. `candidatura_stage_metadata` (entrevista/teste/admissão); 3. `pipeline_movements` (candidatura, etapa anterior/nova, usuário, data);
4. `candidatura_historico` (de→para + observações; "Motivo da reprovação" se Reprovado); 5. **sobrescreve `candidaturas.observacoes`** quando há texto;
6. etapa Contratado/Admissão + indicação: grava `indicacao_data_contratacao` e `..._fim_experiencia` (+90 dias) — **relógio de comissão**;
7. **evento de webhook** enfileirado e entregue na hora (payload com dados da candidatura) à integração externa; 8. Carta Proposta/Exame Admissional **não** são disparados pela movimentação (ações manuais separadas).

### Modelo de autorização de escrita
Perfil (lido do banco) → permissão **efetiva** `pipeline.movimentar` (política + atribuição; leitura nunca autoriza mover) → escopo nominal (viewer/falha = nenhum) → candidatura cuja vaga tem `vagas.setor_id` no escopo (vaga sem setor = negado; fora do escopo = "não encontrada") → transição permitida → etapa atual esperada obrigatória.
Admin/RH: fluxo global **inalterado**. Viewer e demais perfis: negados.

### Concorrência
A verificação é **repetida dentro da transação**, depois do lock da candidatura, por um guarda opcional (`$guardaSobLock`, `null` = fluxo antigo) que refaz perfil/permissão/escopo e executa `SELECT ... FOR UPDATE` na junção candidatura×vaga: trava só essas duas linhas até o commit (trocar o setor da vaga durante a gravação espera). Etapa esperada obrigatória + lock existente = repetição/corrida viram `conflict` sem gravar. Recusa = rollback antes de qualquer escrita (sem histórico/movimento/evento).

### Transições
* **Operacionais do Gestor** (decisão conservadora, a aprovar): origem **e** destino em {Entrevista Gestor, Testes}; metadados só de entrevista/teste (observações, confirmação e dados de admissão são descartados).
* **Exclusivas do RH**: Aprovado, Admissão (relógio de comissão), Reprovado (motivo interno/observações), Banco de Talentos, Nova Inscrição, Triagem RH, Entrevista RH — e retirar candidato de etapa do RH.
* Decisão de negócio pendente: se o Gestor poderá recomendar/aprovar/reprovar (alternativa: ação "recomendar" que só registra parecer, sem mover).

### Auditoria
`pipeline_movements` (usuário, candidatura, etapas, data) + `candidatura_historico` bastam para rastrear o Gestor (o perfil vem do usuário). Lacunas: não há `origem` da operação nem snapshot do setor da vaga na época — evolução opcional (coluna em `pipeline_movements`); **sem migration nesta etapa**.

### Filtros do Pipeline (divergência)
`Candidatura::all` (RH) filtra/exibe setor pela **solicitação** (`sv.setor_id`); o escopo do Gestor usa **`vagas.setor_id`** (`listarEscopo`). Diagnóstico: `database/diagnosticos/2026-11-25-pipeline-setor-divergencia.sql`. Estratégia: manter o filtro do RH como está até a 3.2C; unificar com `COALESCE(v.setor_id, sv.setor_id)` (vaga direta passa a ser reconhecida) depois de rodar o diagnóstico em produção. `Candidatura::setoresDisponiveisEscopo` restringe o filtro de setores do Gestor aos seus.

### Pontos a adaptar na 3.2C
`AdminPipelineController::index` (role + `listarEscopo`/`contarEscopo`/`Vaga::all($escopo)`/`setoresDisponiveisEscopo`, `podeMovimentar` real) e `::move` (trocar `Candidatura::updateStage` por `PipelineMovimentacaoSetorialService::mover($_SESSION user)`, mapear `forbidden/transition`→403, `not_found`→404, `validation`→422, `conflict`→409); `PermissaoPerfilPolicy` só depois, com aprovação.
Teste: `integration_pipeline_movimentacao_setorial.php` (40 verificações; 5 mutações detectadas). CSRF permanece no controller (existente).

## 10. Etapa 3.2C.1 — leitura setorial de Vagas e Candidaturas INTEGRADA e concedível (sem escrita, sem detalhe, sem documentos)

### O que foi integrado
| Rota | Controller | Gate | Gestor (permissão efetiva + escopo) |
|---|---|---|---|
| GET `/admin/vagas` | `AdminVagasController::index` | `Authorization::requireRoleOuPermissao(['admin','rh','viewer'], 'vagas.visualizar')` | lista só vagas com `vagas.setor_id` ∈ Setores Gerenciados, somente leitura (sem Editar/Excluir/Publicar/Nova vaga/link de solicitação) |
| GET `/admin/candidaturas` | `AdminCandidaturasController::index` | `Authorization::requireRoleOuPermissao(['admin','rh'], 'candidaturas.visualizar')` | view própria `index-setorial` com a projeção segura; filtros vaga/setor/etapa/período/nome, paginação (20) e contador via `listarEscopo/contarEscopo` |

Decisão central: `RecrutamentoLeituraSetorialService` → modo `legado` (Admin, RH, Supervisor Geral e, **só em Vagas**, Viewer: comportamento anterior intacto, role da sessão como sempre), `setorial` (Gestor: perfil relido do **banco**, permissão efetiva, escopo `usuario_setores_gerenciados`, jamais global; sem setores = escopo vazio) ou `negado`. Fonte do setor da vaga: **`vagas.setor_id`** (nunca `area`, nunca `solicitacoes_vaga.setor_id`, sem COALESCE).

### Permissões
Concedíveis ao Gestor passam de 17 para **19**: `+ vagas.visualizar`, `+ candidaturas.visualizar`. Ficam **fora do modelo recomendado** (continua 15) — a concessão é sempre individual, pela matriz. Continuam NÃO concedíveis: `pipeline.*`, `candidaturas.editar`, `vagas.criar/editar/excluir/publicar`, `comunicacoes.visualizar`, `pesquisa_experiencia.visualizar`, `indicacoes.*`. O Viewer não pode receber as novas (política do Viewer intacta). Navegação: Vagas = `aberto_ou_perm:vagas.visualizar`; Candidaturas = `staff_ou:candidaturas.visualizar`; Pipeline/Indicações seguem `staff`.

### Projeção do Gestor (aplicada no SQL, não na view)
id, nome, vaga, setor da vaga, etapa, status, data. Fora: CPF, e-mail, telefone, currículo, experiência, cargo pretendido, observações, indicação/comissão, metadados de entrevista, Carta, Exame, comunicações. Sem links para detalhe/PDF/documentos/Kanban nem formulários.

### Permanece bloqueado ao Gestor (403, testado com pipeline.*/candidaturas.editar/vagas.editar gravadas)
Detalhe (`show`), `download`, `downloadDocumento`, upload, `update`, `updateIndicacao`, Pipeline (`index`/`move`), Vagas `create/store/edit/update/delete/publicar`. Os serviços da 3.2B.2 (`CandidaturaAcessoSetorialService`) e 3.2B.3 (`PipelineMovimentacaoSetorialService`) seguem preparados e **não integrados** (3.2C.2).

### BLOQUEIO DE IMPLANTAÇÃO — permissões antigas já gravadas
Uma linha `vagas.visualizar`/`candidaturas.visualizar` em `usuario_permissoes` de um **Gestor** (hoje ineficaz) fica **efetiva no momento da publicação**. Antes de publicar, rodar no phpMyAdmin (banco `u172743873_portalrh`, somente leitura) `database/diagnosticos/2026-11-26-auditoria-permissoes-leitura-recrutamento.sql`:
1. consulta 1 deve retornar **0 linhas**. Se houver: **não publicar**; combinar com o RH quem deve mesmo ter a leitura e, para os demais, remover a atribuição pela tela de usuários (ação individual e auditada — nunca em massa/automática) **antes** da publicação; reexecutar até 0 (ou aprovar cada caso por escrito);
2. consulta 5 (vagas sem setor) deve retornar 0 — vaga sem `setor_id` é invisível ao Gestor;
3. consultas 3 e 4 são informativas (Gestores/Viewers com permissões que continuam ineficazes).
Se o banco de produção não tiver Gestores (ENUM `gestor` ausente/0 contas), a consulta 1 retorna vazio e não há risco.

### Pipeline — estratégia definitiva (3.2C.2)
Referência oficial = `vagas.setor_id`. O Kanban do RH ainda usa o setor da solicitação (`Candidatura::all`); diagnóstico com banco explícito: `database/diagnosticos/2026-11-26-pipeline-setor-divergencia-producao.sql`. Em 3.2C.2: migrar filtro/exibição do RH para `vagas.setor_id` (as 6 vagas de produção já foram regularizadas), integrar `listarEscopo` ao `AdminPipelineController::index` com projeção sem metadados de entrevista e só então avaliar `pipeline.visualizar`/`pipeline.movimentar` (esta usando `PipelineMovimentacaoSetorialService`). COALESCE nunca é regra de autorização.

### Plano de homologação (contas de teste controladas, sem tocar registros reais)
Criar (pela tela de usuários, por Admin) contas **novas** de teste, concedendo individualmente: (a) Gestor com 1 setor + `vagas.visualizar` + `candidaturas.visualizar`; (b) Gestor com 2+ setores com as duas; (c) Gestor com setor e SEM as permissões (esperar 403); (d) Gestor com as permissões e SEM setores (esperar listas vazias e "Nenhuma candidatura…"); (e) RH (listagem completa); (f) Viewer (Candidaturas 403, Vagas como sempre). Conferir: só vagas/candidaturas dos seus setores; sem e-mail/telefone/CPF; sem links de detalhe/PDF/Kanban/Editar; URLs diretas `/admin/candidaturas/{id}`, `/admin/pipeline`, `/admin/vagas/novo` = 403; trocar o setor de uma vaga de teste (como RH) e recarregar = acesso muda na hora; remover a permissão = 403. Não usar candidatos reais para testar a troca de setor — criar vaga/candidatura de teste e removê-las depois.

### Pendências e riscos
Detalhe/documentos/Carta/Exame e Pipeline (3.2C.2); decisão de privacidade sobre e-mail/telefone/cargo pretendido para o Gestor; `Vaga::all` devolve `v.*` ao Gestor (a view só exibe título, empresa, área, setor, local e situação; rascunhos de vaga aparecem na lista); divergência empresa × setor não imposta; o gate de Candidaturas do Viewer segue por `requireRoleOuPermissao` (permissão incompatível ⇒ 403).
Testes: `integration_recrutamento_leitura_setorial.php` (endpoints reais), `tests/recrutamento-leitura-setorial.spec.js` (Playwright), mais os testes das etapas anteriores atualizados (política 19/15).
