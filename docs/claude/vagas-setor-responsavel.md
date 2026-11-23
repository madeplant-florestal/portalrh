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
