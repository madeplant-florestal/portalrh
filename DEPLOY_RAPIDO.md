# Deploy rápido e estável (local e produção)

## Objetivo

Este fluxo simplifica o deploy para o mesmo padrão antigo: atualizar credenciais em `app/config/config.php` e subir.

## Estratégia de configuração (simples)

- Prioridade de leitura:
  1. defaults internos
  2. `app/config/config.php` (modo recomendado)
  3. `app/config/local.php` (opcional)
  4. variáveis de ambiente
- Em produção, edite apenas `app/config/config.php`.
- `app/config/local.php` continua disponível para cenários avançados.

## Passos de deploy

1. Gere pacote com validações:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/deploy_quick.ps1
```

2. Suba o ZIP para o servidor e extraia.
3. Acesse `https://seu-dominio/install.php`.
4. Preencha dados e use `config_mode = app/config/config.php`.
5. Conclua instalação.

## Checklist pré-produção

- PHP 8.1+.
- `pdo` e `pdo_mysql` ativos.
- `public/index.php`, `install.php`, `database/schema.sql` presentes.
- Conectividade com banco validada.
- Diretórios graváveis: `storage/*` e `public/uploads`.
- `LOG_VIEWER_KEY` configurada.
- `LOG_ALERT_EMAIL` configurado para alertas críticos.

## Validação pós-deploy

- Home responde: `/`
- Instalador responde: `/install.php`
- Logs protegidos: `/logs.php?key=SUA_CHAVE`
- Log JSON diário criado em `storage/logs/app-AAAA-MM-DD.jsonl`

## Observações de segurança

- Não versionar `app/config/config.php` em produção se contiver segredos.
- Se o instalador não for removido automaticamente, apague `public/install.php`.
- Nunca expor a chave do viewer de logs em links públicos.

