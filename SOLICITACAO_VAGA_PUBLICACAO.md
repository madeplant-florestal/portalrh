# Fluxo operacional: Solicitação de Vaga → Publicação (2026-09-08)

Coloca em uso real o processo **líder solicita → aprovação → Kanban → vaga pública**, com
rastreabilidade ponta a ponta. Não recria formulário, máquina de estados nem Kanban existentes —
adiciona o mínimo em torno deles.

## 1. Autorização (regra do Portal, nunca inferida do METADADOS)

`usuario_colaboradores` ganhou **`pode_solicitar_vaga TINYINT(1) NOT NULL DEFAULT 0`**
(migration `2026-09-08-usuario-colaboradores-pode-solicitar-vaga.sql`, backfill `= is_gestor`
para a linha existente).

- `is_gestor` = "é líder" (roteamento de aprovação + escopo de setor na solicitação — **intocado**).
- `pode_solicitar_vaga` = autorização **explícita** para abrir Solicitação de Vaga. Conceitos
  separados: um líder pode não poder solicitar.

Gate (dois níveis, ambos no backend):
- `AdminSolicitacoesVagaController::canCreate()` — RH/admin sempre; senão exige
  `current_access.ativo = 1 AND current_access.pode_solicitar_vaga = 1`.
- `SolicitacaoVaga::validateForSubmission()` — o gestor da solicitação precisa de `is_gestor = 1`,
  `setor_id` do setor selecionado **e** `pode_solicitar_vaga = 1` (vale também quando o RH abre em
  nome do líder). `SolicitacaoVaga::formDependencies()['gestores']` já filtra por
  `pode_solicitar_vaga = 1`.

O solicitante persistido (`solicitacoes_vaga.solicitante_usuario_id`) é **sempre** o usuário
autenticado (parâmetro do backend), nunca um id do formulário — comportamento pré-existente,
agora coberto por teste.

## 2. Administração do acesso do líder

`/admin/colaboradores/{id}/acesso` (`AdminColaboradoresController::acesso` / `updateAcesso`,
admin+RH; criação/vínculo de usuário e redefinição de senha só admin). Permite:
vincular (ou criar) o usuário de login (`role = viewer` + senha temporária forte exibida uma vez),
ligar `usuario_colaboradores` (via `UsuarioColaboradorRepository`), setar `is_gestor` /
`pode_solicitar_vaga` / `is_rh` / `lider_colaborador_id`, e ativar/desativar o acesso (o vínculo e
o histórico são **preservados** — nunca DELETE). Ação "Acesso" adicionada por linha em
`/admin/colaboradores`.

`/admin/colaboradores` (index) passou a exigir **admin/RH** (removido `viewer`) — a listagem
expõe salário individual. Não havia `viewer` ativo; zero regressão.

## 3. Geração da vaga pública (rascunho → publicação)

`vagas` ganhou (migration `2026-09-08-vagas-solicitacao-vaga-id.sql`):
- `solicitacao_vaga_id INT NULL` — FK → `solicitacoes_vaga(id)` `ON DELETE SET NULL`, **UNIQUE**
  (uma solicitação origina no máximo uma vaga → idempotência).
- `publicada_em DATETIME NULL` — carimbo de publicação (responde "quando foi publicada?").

**Gatilho determinístico**: quando `SolicitacaoVaga::approve()` leva `status_fluxo` a `aprovada`
(aprovação do RH concluída), o hook chama `SolicitacaoVagaPublicacaoService::gerarRascunho()`:
- **best-effort** — se falhar, a aprovação NÃO é revertida; RH tem o botão "Gerar rascunho da
  vaga" na tela da solicitação (`POST /admin/solicitacoes-vaga/{id}/gerar-vaga`, admin/RH);
- **idempotente** — `UNIQUE(solicitacao_vaga_id)` + checagem prévia; nunca cria uma segunda vaga;
- gera a vaga com **`ativo = 0`** (rascunho, invisível ao público — `Vaga::allActive()` filtra
  `ativo = 1`).

Conteúdo do rascunho (semente editável, **nunca** publicada sem revisão do RH): `titulo` = cargo,
`area` = setor, `local`/`empresa_id` = empresa do setor, `descricao` = "entregas esperadas" da
solicitação (decriptada), `requisitos` = escolaridade + nível + formação + experiência. Nunca
expõe solicitante, aprovações, salário nem observações internas.

**Publicação**: `POST /admin/vagas/{id}/publicar` (`AdminVagasController::publicar`, admin/RH) →
`SolicitacaoVagaPublicacaoService::publicar()` → `ativo = 1`, `publicada_em = NOW()`. Botão
"Publicar" na lista `/admin/vagas` para qualquer vaga `ativo = 0`; badge "Rascunho" para
`ativo = 0 AND publicada_em IS NULL AND solicitacao_vaga_id IS NOT NULL`. `Vaga::update()`
(edição manual) também carimba `publicada_em` ao ativar.

## 4. Rastreabilidade / auditoria

- **Quem/quando solicitou** → `solicitacoes_vaga.solicitante_usuario_id` + `created_at` +
  `solicitacao_vaga_auditoria` (evento `created`).
- **Aprovações** → `solicitacao_vaga_aprovacoes` (aprovador, `aprovado_em`, `assinatura_hash`) +
  auditoria (`approval_lider_imediato`, `approval_rh`).
- **Vaga originada** → `vagas.solicitacao_vaga_id` (bidirecional) + auditoria
  `vaga_rascunho_gerada` / `vaga_publicada` (via `SolicitacaoVaga::registrarEventoAuditoria`,
  fachada pública sobre `logAudit`).

## 5. Cadastro manual de vaga

`/admin/vagas/novo` mantido (15 vagas legadas + candidaturas apontando), restrito a admin/RH, com
aviso na tela de que é **exceção**. Líderes (viewer) não têm acesso a `/admin/vagas` para criar —
só usam Solicitação de Vaga.

## 6. Fora de escopo desta sprint (pendências)

- **Papel `viewer` amplo**: um líder-viewer ainda vê `/admin` (dashboard), `/admin/vagas` (lista),
  `/admin/movimentacoes-pessoal`, `/admin/indicadores-rh`. Nada de salário individual (colaboradores
  já restrito), mas recomenda-se revisar o escopo do `viewer` agora que ele é usado para líderes —
  ou criar um papel `lider` dedicado. A navegação lateral mostra links que resultam em 403.
- **Escopo organizacional do líder**: hoje o líder só abre solicitação para o próprio setor
  (`validateForSubmission`: `gestor.setor_id === setor_id`). Restrição fina por líder fica para
  parametrização futura.
- **Centro de Custo**: o cadastro atual (`centros_custo`) atende a solicitação; integração oficial
  com o METADADOS continua adiada.
- **Webhook de "vaga publicada"**: não implementado (o "recrutamento" aqui é o Kanban de
  solicitações + o pipeline de candidaturas, ambos já existentes).
- **Comunicação da senha temporária**: exibida uma vez na tela (canal seguro é responsabilidade do
  admin). O líder troca depois em "Esqueci minha senha".
