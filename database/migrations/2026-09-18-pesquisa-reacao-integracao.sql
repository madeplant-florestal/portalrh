-- Migration: 2026-09-18-pesquisa-reacao-integracao.sql
-- Objetivo:
--   Nova funcionalidade "Pesquisa de Reação — Treinamento de Integração". Instrumento DIFERENTE
--   da `pesquisas_integracao` já existente (migration 2026-09-17-pesquisa-integracao.sql) — não
--   reaproveita suas 5 perguntas nem sua tabela. Aqui: 1 CAMPANHA (link público gerado pelo RH,
--   com validade) -> N RESPOSTAS (participação anônima opcional, sem limite de respostas por
--   link).
--
--   Diferente de `pesquisas_integracao`, esta funcionalidade NÃO usa `ensureSchema()` com DDL em
--   runtime — esta migration é a ÚNICA fonte da estrutura. Nenhuma requisição HTTP cria/altera
--   tabela (decisão explícita do Fabio para este módulo novo).
--
--   Identidade organizacional: `codigo_empresa`/`codigo_setor` são os CÓDIGOS OFICIAIS do
--   METADADOS (mesma fonte/convenção já validada em PeopleAnalyticsRepository::opcoesFiltro() e
--   RhIndicadoresRepository::opcoesFiltro() — `colaboradores_metadados.codigo_empresa`/`empresa`
--   e `codigo_setor` resolvido pelo catálogo local `setores.codigo_setor`/`descricao_oficial`).
--   NUNCA os ids locais de `empresas`/`setores` (domínio de Recrutamento/vagas — outra dimensão).
--   Ambos NULL-áveis: Empresa/Setor são contexto opcional informado pelo RH na criação do link.
--
--   `*_nome_snapshot`: cópia do nome/descrição resolvidos NO MOMENTO da criação da campanha —
--   preservação histórica. Se o catálogo oficial for corrigido/renomeado depois, uma campanha
--   antiga (e suas respostas) continuam mostrando o contexto de quando foram geradas. Os CÓDIGOS
--   continuam sendo a identidade; o snapshot é só descrição textual, nunca usado em filtro/JOIN.
--
--   `data_integracao`: pertence à campanha (contexto informado pelo RH na criação), nunca ao POST
--   público — o formulário só EXIBE esse dado, nunca o recebe como entrada confiável.
--
--   Token público: só o HASH (`token_hash`, sha256 de `random_bytes(32)`) é armazenado — mesmo
--   padrão de segurança de `pesquisas_integracao`/`password_resets`. Token bruto nunca persistido,
--   mostrado ao RH somente no momento da geração.
--
--   `ativa` + `expira_em`: uma campanha aceita novas respostas enquanto existir, `ativa = 1` E
--   `expira_em > NOW()` — validado sempre no backend (nunca confia em HTML/JS), tanto no GET
--   (exibição) quanto no POST (envio).
--
--   Sem coluna de "quantidade de respostas" na campanha: sempre derivada por COUNT em
--   `respostas_pesquisa_reacao_integracao` (evita contador redundante divergente).
--
--   Pergunta NPS (0-10) e as 6 avaliações (1-5): notas ORIGINAIS sempre persistidas — classificação
--   Promotor/Neutro/Detrator e o cálculo do NPS (%Promotores - %Detratores) são SEMPRE calculados a
--   partir de `nota_nps`, nunca persistidos (evita divergência se a regra mudar no futuro).
--
--   3 perguntas abertas: todas opcionais (nunca bloqueiam o envio).
--
--   Sem CHECK constraints: validação de faixa (NPS 0-10, avaliações 1-5) só na camada de
--   aplicação — suporte a CHECK diverge entre MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção),
--   mesmo motivo já registrado em migrations anteriores (`pesquisas_integracao`).
--
--   Compatibilidade: MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção). `CREATE TABLE IF NOT
--   EXISTS` idempotente.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push (ver relatório da sprint para a ordem exata dos SQLs).

CREATE TABLE IF NOT EXISTS campanhas_pesquisa_reacao_integracao (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  token_hash              CHAR(64)     NOT NULL,
  codigo_empresa          VARCHAR(20)  NULL,
  empresa_nome_snapshot   VARCHAR(180) NULL,
  codigo_setor            VARCHAR(8)   NULL,
  setor_nome_snapshot     VARCHAR(40)  NULL,
  data_integracao         DATE         NULL,
  expira_em               DATETIME     NOT NULL,
  ativa                   TINYINT(1)   NOT NULL DEFAULT 1,
  criado_por_usuario_id   INT          NOT NULL,
  created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pesquisa_reacao_campanha_token (token_hash),
  KEY idx_pesquisa_reacao_campanha_empresa (codigo_empresa),
  KEY idx_pesquisa_reacao_campanha_setor (codigo_setor),
  KEY idx_pesquisa_reacao_campanha_usuario (criado_por_usuario_id),
  CONSTRAINT fk_pesquisa_reacao_campanha_usuario FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS respostas_pesquisa_reacao_integracao (
  id                                       INT AUTO_INCREMENT PRIMARY KEY,
  campanha_id                              INT       NOT NULL,
  nome_opcional                            VARCHAR(120) NULL,
  nota_nps                                 TINYINT   NOT NULL,
  avaliacao_historia_proposito_valores     TINYINT   NOT NULL,
  avaliacao_responsabilidades_rotina       TINYINT   NOT NULL,
  avaliacao_seguranca_saude                TINYINT   NOT NULL,
  avaliacao_relevancia_conteudos           TINYINT   NOT NULL,
  avaliacao_acolhimento                    TINYINT   NOT NULL,
  avaliacao_expectativas                   TINYINT   NOT NULL,
  mais_gostou                              TEXT      NULL,
  poderia_melhorar                         TEXT      NULL,
  informacao_faltante                      TEXT      NULL,
  respondida_em                            DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pesquisa_reacao_resposta_campanha (campanha_id),
  CONSTRAINT fk_pesquisa_reacao_resposta_campanha FOREIGN KEY (campanha_id) REFERENCES campanhas_pesquisa_reacao_integracao(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
