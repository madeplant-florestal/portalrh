# Instalação Web (sem SSH)

Este projeto possui instalador via navegador em `https://seu-dominio/install.php`.

## Antes de iniciar

- Faça upload dos arquivos do projeto no servidor.
- Garanta que o domínio aponta para a pasta `public` ou para a raiz com rewrite ativo.
- Tenha em mãos:
  - DSN do banco MySQL
  - usuário e senha do banco
  - e-mails de envio (RH e remetente)
  - credenciais do supervisor e admin inicial

## Habilitar o instalador (desabilitado por padrão — Segurança 3.4)

O instalador web vem DESABILITADO e responde 403 até que as duas condições abaixo existam, ambas FORA do Git:

- `RH_INSTALADOR_WEB=1` no ambiente do servidor, ou `'installer_web_enabled' => true` em `security` de `app/config/local.php`;
- uma chave forte (32+ caracteres) em `INSTALLER_KEY` no ambiente, ou `'installer_key'` em `security` de `app/config/local.php`.

Depois da instalação concluída (`storage/install.done`), ele fica bloqueado de qualquer forma. Remova a habilitação e a chave ao terminar.
O `.htaccess` também bloqueia `install.php`; para uma reinstalação planejada é preciso retirar essa regra temporariamente.

## Como instalar

1. Abra `https://seu-dominio/install.php`.
2. Verifique os requisitos exibidos na tela.
3. Selecione `app/config/config.php (recomendado)` no modo de configuração.
4. Preencha os campos do formulário.
5. Clique em **Instalar agora**.
6. Aguarde o log finalizar com sucesso.
7. Informe a chave do instalador (obrigatória) para liberar a execução.

## O que o instalador faz

- Valida requisitos do servidor.
- Cria `app/config/local.php` com parâmetros de produção.
- Cria arquivo de configuração no modo escolhido (`app/config/config.php` ou `app/config/local.php`).
- Conecta no banco.
- Importa `database/schema.sql`.
- Executa migrações incrementais.
- Cria usuário admin inicial (quando informado).
- Cria diretórios de runtime e logs.
- Gera lock de instalação em `storage/install.done`.
- Tenta remover automaticamente `public/install.php`.

## Segurança pós-instalação

- Se o instalador não for removido automaticamente, apague `public/install.php` manualmente.
- Troque senhas temporárias usadas na instalação.
- Mantenha `app/config/local.php` fora do versionamento.

## Diagnóstico

- Log da aplicação: `storage/logs/app-error.log`
- Logs do instalador: `storage/logs/install-AAAAMMDD-HHMMSS.log`
- Log estruturado diário (JSONL): `storage/logs/app-AAAA-MM-DD.jsonl`
- Visualizador web: `https://seu-dominio/logs.php?key=SUA_CHAVE`

## Configuração de observabilidade

- `LOG_LEVEL` define nível mínimo (`DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL`).
- `LOG_ALERT_EMAIL` recebe alertas por e-mail para erros `ERROR` e `CRITICAL`.
- `LOG_VIEWER_KEY` protege o acesso ao visualizador `logs.php`.

## Automação recomendada

- Validação pré-produção:
  - `php scripts/preflight.php`
- Empacotamento com validação:
  - `powershell -ExecutionPolicy Bypass -File scripts/deploy_quick.ps1`
