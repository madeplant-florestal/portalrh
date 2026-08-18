# Webhooks de Recrutamento

## Contexto

RH Madeplant não é um SaaS multi-tenant — é usado internamente por um único grupo
empresarial, com várias empresas cadastradas na mesma base. Por isso a integração de
webhooks usa **uma única configuração global** (URL + segredo), válida para todo o
recrutamento. A empresa vinculada à vaga/candidato é enviada no payload apenas como
**contexto informativo** — nunca determina URL, segredo ou configuração.

## Fluxo

```text
Movimentação no Kanban
    ↓
Atualização da etapa da candidatura (transação)
    ↓
Registro em pipeline_movements e candidatura_historico (mesma transação)
    ↓
Criação do evento em webhook_events (mesma transação)
    ↓
Commit
    ↓
Envio HTTP para a URL global do n8n (fora da transação)
    ↓
Registro do resultado (sucesso/falha) em webhook_events
```

O envio nunca bloqueia a movimentação do candidato — se a entrega falhar, o evento fica
registrado como pendente/falho e pode ser reenviado manualmente ou pela fila.

## Tabelas

- `recruitment_webhook_global_settings` — configuração única (linha fixa `id = 1`):
  `enabled`, `webhook_url`, `webhook_secret_encrypted` (cifrado com `Cipher`, AES-256-CBC).
- `webhook_events` — fila/histórico de eventos: `event_id` (identificador externo estável,
  preservado em reenvios), `empresa_id` (contexto, sem FK), `movement_id` (referência à
  movimentação de pipeline que originou o evento), `event_type`, `payload_json`,
  `webhook_url`, `status`, `response_code`, `response_body`, `last_error`, `retry_count`,
  `created_at`, `processed_at`, `next_retry_at`.
- `pipeline_stages.slug` — identificador estável de etapa (`triagem-rh`,
  `entrevista-rh`, `entrevista-gestor`, `testes`, `aprovado`, `admissao`,
  `banco-de-talentos`, `reprovado`, `nova-inscricao`) — usado no lugar do nome textual,
  que pode ser renomeado.
- `recruitment_webhook_settings` (tabela antiga, por empresa) — **em desuso**, preservada
  apenas para auditoria/rollback da migração para configuração global. Não é mais lida
  pelo código novo.

## Evento implementado

### `recrutamento.candidato.etapa_alterada`

Disparado sempre que uma candidatura muda de etapa no Kanban (`RecruitmentPipelineService::moveCandidateToStage`).

```json
{
  "event_id": "evt_9f1c2a...",
  "event_type": "recrutamento.candidato.etapa_alterada",
  "occurred_at": "2026-07-10T08:30:00-04:00",
  "empresa": {
    "id": 4,
    "nome": "MADEPLANT FLORESTAL LTDA"
  },
  "candidato": {
    "id": 152,
    "nome": "João da Silva",
    "telefone": "5567999999999",
    "email": "joao@email.com"
  },
  "vaga": {
    "id": 25,
    "titulo": "Operador de Máquinas"
  },
  "etapa": {
    "anterior": { "id": 2, "codigo": "triagem-rh", "nome": "Triagem RH" },
    "atual": { "id": 3, "codigo": "entrevista-rh", "nome": "Entrevista RH" }
  },
  "dados_adicionais": {
    "data_entrevista": null,
    "horario_entrevista": null,
    "local_entrevista": null,
    "link_entrevista": null,
    "prazo_documentos": null,
    "observacoes_admissao": null,
    "nome_teste": null,
    "prazo_teste": null
  },
  "responsavel": {
    "id": 8,
    "nome": "Usuário responsável"
  }
}
```

`empresa` é `null` quando a vaga não tem empresa vinculada — isso nunca bloqueia o evento.

`dados_adicionais` traz sempre as 8 chaves (nulas por padrão); são preenchidas conforme a
etapa de destino:
- `entrevista-rh` / `entrevista-gestor`: `data_entrevista`, `horario_entrevista`,
  `local_entrevista`, `link_entrevista`.
- `admissao`: `prazo_documentos`, `observacoes_admissao`.
- `testes`: `nome_teste`, `prazo_teste`.

O motivo interno de reprovação nunca é incluído em nenhum payload (decisão de produto).

### `recrutamento.candidatura.criada`

Disparado exclusivamente quando um candidato conclui a inscrição pública
(`HomeController::candidatar`). Responsabilidade única: notificar o RH sobre uma nova
candidatura — **nunca** é disparado por movimentação de Kanban, e o evento
`recrutamento.candidato.etapa_alterada` nunca dispara este e-mail (ver `RecruitmentEventDispatcher`).

```json
{
  "event_id": "evt_9f1c2a...",
  "event_type": "recrutamento.candidatura.criada",
  "occurred_at": "2026-07-13T08:30:00-04:00",
  "protocolo": "202607-0085",
  "empresa": {
    "id": 4,
    "nome": "MADEPLANT FLORESTAL LTDA"
  },
  "candidato": {
    "id": 85,
    "nome": "João da Silva",
    "telefone": "5567999999999",
    "email": "joao@email.com"
  },
  "vaga": {
    "id": 25,
    "titulo": "Operador de Máquinas"
  },
  "link_candidatura": "https://rhmadeplant.com.br/admin/candidaturas/85"
}
```

`protocolo` é gerado no backend (`Candidatura::formatProtocol`, formato `AAAAMM-XXXX`, mês/ano
de `created_at` + id sequencial) — o n8n nunca deve reconstruir esse valor. `link_candidatura`
usa `Config::app()['base_url']` (a mesma configuração central de domínio já usada em
`Mailer::notifyUserPasswordChanged`), portanto nunca aponta para `rhmadeplant.test` em produção.
`empresa` é `null` nas mesmas condições do evento de etapa (vaga sem empresa vinculada).

Este evento usa exatamente a mesma infraestrutura de entrega do `etapa_alterada` — fila
(`webhook_events`), assinatura HMAC, retentativa — sem implementação paralela. A notificação por
e-mail ao RH passou a ser responsabilidade exclusiva do n8n a partir deste evento; o envio nativo
via `mail()` (`Mailer::notifyHR`) foi removido deste fluxo.

### `recrutamento.webhook.teste`

Disparado manualmente pelo botão "Testar webhook" na tela administrativa. Não usa dados
reais de candidatos:

```json
{
  "event_id": "evt_...",
  "event_type": "recrutamento.webhook.teste",
  "occurred_at": "2026-07-10T08:30:00-04:00",
  "test": true,
  "message": "Teste de integração realizado com sucesso."
}
```

## Eventos planejados (próximas etapas, ainda não implementados)

- `recrutamento.entrevista.agendada`
- `recrutamento.candidato.aprovado`
- `recrutamento.candidato.reprovado`
- `recrutamento.admissao.iniciada`

## Segurança

- Assinatura **HMAC-SHA256** sobre `timestamp.payload_json`, com o segredo global.
- Headers enviados em toda requisição: `X-Webhook-Event`, `X-Webhook-Id`,
  `X-Webhook-Timestamp`, `X-Webhook-Signature: sha256=<hash>`.
- Segredo: gerado/regenerado pela interface, cifrado em repouso, exibido em texto puro
  **apenas uma vez** (no momento da geração, via flash de sessão — nunca por querystring
  ou log). `Logger` redige automaticamente qualquer chave de contexto contendo `secret`.
- Proteção contra **SSRF** (`RecruitmentWebhookUrlGuard`): bloqueia URLs cujo host resolva
  para IP privado/loopback/link-local/reservado (IPv4 e IPv6), tanto ao salvar a URL
  quanto no momento do envio (defesa contra DNS rebinding). Para testar contra um n8n
  rodando na própria rede local, defina `security.webhook_allow_private_targets => true`
  em `app/config/local.php` (nunca em produção).

## Idempotência e reenvio

- Cada evento tem um `event_id` gerado uma única vez na criação da linha em
  `webhook_events` — reenvios (`retryEvent`) reaproveitam a mesma linha e, portanto, o
  mesmo `event_id`, permitindo que o n8n identifique e descarte duplicatas.
- Reenvio manual pela interface (`Reenviar`), ou em lote (`Processar fila pendente`).

## Interface administrativa (`/admin/recruitment-webhooks`)

- **Configuração da integração**: ativar/desativar, URL, segredo (gerar/regenerar,
  nunca exibido depois de salvo), botão "Testar webhook".
- **Situação da fila**: contadores de pendentes/entregues/falhas, último processamento
  com sucesso, botão para processar a fila pendente.
- **Histórico recente**: evento, empresa (contexto), candidato, etapas, status,
  tentativas, processado em, reenviar.

## Migração da configuração por empresa (histórica)

A modelagem anterior guardava uma configuração por empresa (`recruitment_webhook_settings`,
`scope_key`/`empresa_id`). A migração para configuração global é feita por
`scripts/migrate_recruitment_webhook_to_global.php`, que:

- não escolhe uma URL arbitrariamente se houver mais de uma configurada;
- interrompe e imprime a comparação (sem expor segredos) quando há ambiguidade;
- copia a URL/segredo apenas quando há exatamente uma configuração de origem sem conflito.

A tabela antiga não é removida automaticamente — permanece para auditoria, com remoção
proposta como mudança destrutiva separada e futura.
