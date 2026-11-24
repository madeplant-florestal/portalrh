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
