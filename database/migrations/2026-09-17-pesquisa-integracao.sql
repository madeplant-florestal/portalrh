-- Migration: 2026-09-17-pesquisa-integracao.sql
-- Objetivo:
--   Sprint "Fundação do Dashboard de Integração". Cria `pesquisas_integracao`: a pesquisa
--   respondida pelo colaborador após a Integração (onboarding) ser registrada como concluída no
--   Portal — fonte oficial futura de NPS da Integração / Satisfação Média / Taxa de Participação.
--
--   NÃO é a mesma coisa que `pesquisas_experiencia` (processo seletivo, candidato, ligada a
--   `candidatura_id`) — essa continua com sua finalidade atual, intocada. `pesquisas_integracao`
--   é ligada diretamente a `colaboradores.id` (identidade estrutural correta: a integração é um
--   dado operacional do Portal sobre um COLABORADOR, nunca sobre uma candidatura/CPF/e-mail).
--
--   Vínculo canônico: `colaborador_id` (FK -> colaboradores.id). Explicitamente NÃO exige
--   `colaboradores.metadados_id` — a definição oficial de "integração realizada" continua sendo
--   `colaboradores.integracao_status = 'realizada'` (dado operacional do Portal), independente de
--   o colaborador já ter (ou não) contrato oficial resolvido no METADADOS. Quando existir
--   `colaboradores.metadados_id`, o Dashboard futuro chega a Empresa/Setor oficiais via
--   `colaboradores_metadados.codigo_empresa`/`codigo_setor` — por isso esta tabela NÃO duplica
--   Empresa/Setor/Cargo como texto (evita divergência entre o snapshot da pesquisa e o cadastro
--   oficial, que pode ser corrigido/sincronizado depois).
--
--   Idempotência = 1 pesquisa por EVENTO de integração, não por colaborador para sempre:
--   `UNIQUE KEY (colaborador_id, integracao_data_relacionada)`. `integracao_data_relacionada` é um
--   SNAPSHOT de `colaboradores.integracao_data` no momento em que a pesquisa foi gerada — se a
--   integração for corrigida/refeita para uma data diferente no futuro, isso conta como um novo
--   evento e permite uma nova pesquisa, SEM apagar ou reescrever a pesquisa antiga (que preserva
--   sua resposta histórica intacta, se já respondida). Reforçado por este UNIQUE KEY como defesa
--   em profundidade contra corrida (mesmo padrão de `pesquisas_experiencia`/`password_resets`).
--
--   Sem coluna de "status" redundante: o estado (criada / aguardando resposta / respondida) é
--   inteiramente derivável de `created_at` + `respondida_em IS NULL` — mesma convenção já usada em
--   `pesquisas_experiencia`, evita workflow/enum desnecessário.
--
--   Pergunta NPS (0-10): nota ORIGINAL sempre persistida em `nota_nps` — a classificação
--   Promotor/Neutro/Detrator e o cálculo do NPS (%Promotores - %Detratores) são SEMPRE calculados
--   a partir de `nota_nps`, nunca persistidos (evita divergência se a regra de classificação for
--   ajustada no futuro).
--
--   5 perguntas de satisfação (1-5): Clareza das informações, Qualidade da recepção/acolhimento,
--   Compreensão de normas/processos, Utilidade das informações, Satisfação geral — todas
--   nullable até a resposta (mesmo padrão de `pesquisas_experiencia`), validadas 1-5 só na
--   camada de aplicação (sem CHECK — mesmo motivo já registrado em migrations anteriores:
--   suporte a CHECK diverge entre MySQL 8.4.x dev e MariaDB 11.8.9-log produção).
--
--   Acesso público via TOKEN: só o HASH (`token_hash`, sha256) fica armazenado — mesmo padrão de
--   segurança de `PasswordReset`/`PesquisaExperiencia` (token bruto nunca gravado, aleatório via
--   `random_bytes(32)`, nunca derivado de CPF/ID/telefone/e-mail).
--
--   Compatibilidade: MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção). `CREATE TABLE IF NOT
--   EXISTS` idempotente.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push.

CREATE TABLE IF NOT EXISTS pesquisas_integracao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  colaborador_id INT NOT NULL,
  integracao_data_relacionada DATE NOT NULL,
  token_hash CHAR(64) NOT NULL,
  nota_nps TINYINT NULL,
  nota_clareza TINYINT NULL,
  nota_acolhimento TINYINT NULL,
  nota_normas TINYINT NULL,
  nota_utilidade TINYINT NULL,
  nota_satisfacao_geral TINYINT NULL,
  comentarios TEXT NULL,
  respondida_em DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pesquisas_integracao_evento (colaborador_id, integracao_data_relacionada),
  UNIQUE KEY uk_pesquisas_integracao_token (token_hash),
  KEY idx_pesquisas_integracao_colaborador (colaborador_id),
  CONSTRAINT fk_pesquisas_integracao_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
