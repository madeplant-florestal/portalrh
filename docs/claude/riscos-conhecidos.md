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
4. **Timezone padrão do PHP não é o do Brasil** — identificado em 2026-11 durante a correção de
   expiração da Carta Proposta: `date_default_timezone_get()` retorna `Europe/Berlin` neste
   ambiente (confirmado via `php -r`), não `America/Campo_Grande`. Qualquer `new
   DateTimeImmutable('string sem timezone')`/`strtotime()`/`date()` construído sem fuso explícito
   no resto do código (candidaturas, folha, indicadores, etc.) herda esse timezone — podendo
   deslocar comparações e exibições de data/hora em ~4-5h em relação ao horário real do Brasil.
   Na Carta Proposta isso foi corrigido localmente (`CartaPropostaService::resolverAcessoPorToken()`
   fixa `America/Campo_Grande` explicitamente só nessa comparação). **Não foi investigado nem
   corrigido em nenhum outro lugar do sistema** — mudar o timezone padrão globalmente (bootstrap)
   afetaria todo o Portal e exige uma auditoria dedicada antes de qualquer alteração.
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
