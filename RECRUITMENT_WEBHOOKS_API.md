# Webhooks de Recrutamento

## Diagnóstico

O fluxo do Kanban de recrutamento ja utilizava:

- `pipeline_stages`
- `candidaturas.stage_id`
- `pipeline_movements`
- `candidatura_historico`

A nova implementacao adiciona um mecanismo interno de eventos com fila persistida para webhooks, sem substituir o pipeline atual e sem remover regras legadas.

## Solução

### Evento interno

Quando uma candidatura muda de etapa pelo Kanban ou pela tela administrativa, o sistema:

1. Atualiza a etapa da candidatura
2. Registra a movimentacao em `pipeline_movements`
3. Registra o historico em `candidatura_historico`
4. Enfileira um evento em `webhook_events`
5. Tenta entregar o webhook HTTP imediatamente
6. Mantem reenvio manual e processamento posterior da fila

### Tabelas novas

- `recruitment_webhook_settings`
- `webhook_events`
- `candidatura_stage_metadata`

### Compatibilidade futura

A arquitetura foi separada em:

- `RecruitmentPipelineService`
- `RecruitmentEventDispatcher`
- `RecruitmentWebhookDeliveryService`
- `RecruitmentWebhookHttpClient`

Essa separacao facilita integracao com:

- n8n
- Evolution API
- Microsoft Teams
- Slack
- ERPs e middleware HTTP

## Código

### Endpoint externo configuravel por tenant

Cada tenant pode definir sua URL de destino na tela administrativa:

- `Admin > Webhooks do recrutamento`

O sistema tambem possui um escopo padrao para vagas sem empresa vinculada.

### Payload padrao

```json
{
  "event": "candidate_stage_changed",
  "tenant_id": 1,
  "candidate_id": 123,
  "candidate_name": "Joao Silva",
  "candidate_email": "joao@email.com",
  "candidate_phone": "5567999999999",
  "job_id": 15,
  "job_title": "Auxiliar Administrativo",
  "previous_stage": "Triagem RH",
  "new_stage": "Entrevista RH",
  "changed_by": "Maria RH",
  "changed_at": "2026-06-16T10:30:00-04:00"
}
```

### Campos condicionais por etapa

#### Entrevista RH / Entrevista Gestor

```json
{
  "interview_date": "2026-06-20",
  "interview_time": "14:30:00",
  "interview_location": "Sala 2 - Matriz",
  "interview_link": "https://meet.exemplo.com/abc"
}
```

#### Testes

```json
{
  "test_name": "Teste Comportamental",
  "deadline": "2026-06-22"
}
```

#### Admissão

```json
{
  "admission_date": "2026-06-30",
  "admission_notes": "Enviar documentos admissionais ate 25/06."
}
```

## API de saída

### Método

- `POST`

### Content-Type

- `application/json`

### Regras de entrega

- sucesso: qualquer resposta `2xx`
- falha: respostas fora de `2xx` ou erro de rede
- reenvio: manual pela interface administrativa
- fila: eventos ficam persistidos em `webhook_events`

## Interface administrativa

Funcionalidades disponiveis:

- habilitar e desabilitar webhooks
- configurar URL por tenant
- configurar fallback padrao
- visualizar historico da fila
- reenviar eventos com falha
- processar pendencias manualmente

## Melhorias adicionais

Sugestoes futuras:

- assinatura HMAC por tenant
- headers customizados por tenant
- worker assíncrono dedicado
- dead-letter queue
- backoff exponencial
- filtros e exportacao do historico de webhooks

## Riscos possíveis

- vagas legadas sem empresa usam o tenant padrao configurado
- integracoes externas lentas ou indisponiveis geram falha de entrega, mas nao bloqueiam a movimentacao do Kanban
- se a URL do tenant estiver vazia com webhook habilitado, o evento fica marcado como falho para auditoria
