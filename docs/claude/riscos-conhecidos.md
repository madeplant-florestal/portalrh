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
