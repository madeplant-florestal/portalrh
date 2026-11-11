-- Diagnóstico (SOMENTE LEITURA): registros que dependem de contratos excluídos analiticamente.
--
-- Para cada contrato presente em colaboradores_metadados_exclusoes_analiticas (casado pela CHAVE NATURAL
-- empresa+unidade+contrato — nunca por id interno, CPF ou nome), conta quantos registros de cada módulo
-- apontam para ele:
--   * DIRETO   — a tabela guarda o contrato (metadados_id / colaborador_metadados_id);
--   * INDIRETO — a tabela guarda um colaborador LOCAL (tabela `colaboradores`) cuja `metadados_id`
--                aponta para o contrato excluído (Solicitação de Vaga, Movimentação de Pessoal, etc.).
--
-- Não altera nada: só SELECT e variáveis de sessão (SET @x / SET SESSION group_concat_max_len) e PREPARE
-- de SELECT gerado. Não cria tabela temporária, não usa DDL/DML. Pode ser executado inteiro, em
-- MySQL 8.x ou MariaDB 11.x, por um usuário com permissão de leitura (inclusive INFORMATION_SCHEMA).
--
-- Cada tabela/coluna só entra na contagem se REALMENTE existir no banco consultado (a lista é filtrada
-- pelo INFORMATION_SCHEMA); o que não existe aparece no relatório "existência dos alvos" com existe = 0
-- — nunca gera erro de coluna/tabela inexistente. `ids_amostra` mostra até 300 caracteres de ids (para
-- localizar os registros); é NULL nas tabelas sem coluna `id`.
--
-- Resultado: 5 conjuntos de resultado, na ordem:
--   (1) tabelas-base presentes;
--   (2) contratos excluídos (chave natural, id interno ATUAL do contrato, identificador, motivo);
--   (3) existência de cada alvo (tabela.coluna) — 1 = existe, 0 = ausente neste banco;
--   (4) CONTAGEM de dependentes por tabela/coluna (só quem existe) — o que importa;
--   (5) colaboradores LOCAIS ligados a contratos excluídos (detalhe).
-- Se (4) vier vazio, nenhuma tabela mapeada tem dependente: nada a preservar além das próprias exclusões.

SET SESSION group_concat_max_len = 1000000;

-- ---------------------------------------------------------------------------------------------
-- (1) Tabelas-base necessárias (esperado: 3 linhas)
-- ---------------------------------------------------------------------------------------------
SELECT t.TABLE_NAME AS tabela_base, 'presente' AS situacao
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_NAME IN ('colaboradores_metadados', 'colaboradores_metadados_exclusoes_analiticas', 'colaboradores')
ORDER BY t.TABLE_NAME;

-- ---------------------------------------------------------------------------------------------
-- (2) Contratos excluídos, pela chave natural (o id interno é só o valor ATUAL deste banco)
-- ---------------------------------------------------------------------------------------------
SELECT e.id AS exclusao_id, e.codigo_empresa, e.codigo_unidade, e.numero_contrato,
       m.id AS contrato_id_atual, m.identificador, LEFT(e.motivo, 80) AS motivo,
       e.excluido_por_usuario_id, e.created_at
FROM colaboradores_metadados_exclusoes_analiticas e
LEFT JOIN colaboradores_metadados m
  ON m.codigo_empresa = e.codigo_empresa AND m.codigo_unidade = e.codigo_unidade AND m.numero_contrato = e.numero_contrato
ORDER BY e.id;

-- ---------------------------------------------------------------------------------------------
-- (3) Existência de cada alvo mapeado (existe = 0 → a tabela/coluna não existe neste banco)
-- ---------------------------------------------------------------------------------------------
SELECT p.tipo, p.tabela, p.coluna,
       EXISTS (
         SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND CONVERT(c.TABLE_NAME USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(p.tabela USING utf8mb4) COLLATE utf8mb4_general_ci
           AND CONVERT(c.COLUMN_NAME USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(p.coluna USING utf8mb4) COLLATE utf8mb4_general_ci
       ) AS existe
FROM (
  SELECT 'direto' AS tipo, 'colaboradores' AS tabela, 'metadados_id' AS coluna
  UNION ALL SELECT 'direto', 'avaliacoes_experiencia', 'metadados_id'
  UNION ALL SELECT 'direto', 'avaliacoes_desempenho', 'metadados_id'
  UNION ALL SELECT 'direto', 'entrevistas_desligamento', 'metadados_id'
  UNION ALL SELECT 'direto', 'entrevista_desligamento_liberacoes', 'metadados_id'
  UNION ALL SELECT 'direto', 'feedbacks', 'metadados_id'
  UNION ALL SELECT 'direto', 'pdis', 'metadados_id'
  UNION ALL SELECT 'direto', 'pesquisas_integracao', 'metadados_id'
  UNION ALL SELECT 'direto', 'usuarios', 'colaborador_metadados_id'
  UNION ALL SELECT 'indireto', 'pesquisas_integracao', 'colaborador_id'
  UNION ALL SELECT 'indireto', 'solicitacoes_vaga', 'colaborador_substituido_id'
  UNION ALL SELECT 'indireto', 'solicitacoes_vaga', 'gestor_solicitante_colaborador_id'
  UNION ALL SELECT 'indireto', 'solicitacoes_vaga', 'lider_imediato_colaborador_id'
  UNION ALL SELECT 'indireto', 'solicitacoes_vaga', 'nome_contratado_colaborador_id'
  UNION ALL SELECT 'indireto', 'movimentacoes_pessoal', 'colaborador_id'
  UNION ALL SELECT 'indireto', 'movimentacoes_pessoal', 'gestor_solicitante_colaborador_id'
  UNION ALL SELECT 'indireto', 'usuario_colaboradores', 'colaborador_id'
  UNION ALL SELECT 'indireto', 'usuario_colaboradores', 'lider_colaborador_id'
  UNION ALL SELECT 'indireto', 'colaborador_avaliacoes', 'colaborador_id'
) p
ORDER BY p.tipo, p.tabela, p.coluna;

-- ---------------------------------------------------------------------------------------------
-- (4) CONTAGEM de dependentes. As consultas são GERADAS a partir do INFORMATION_SCHEMA, só para as
--     tabelas/colunas que existem (os nomes vêm do próprio catálogo, nunca de texto digitado).
-- ---------------------------------------------------------------------------------------------

-- 4a. vínculos DIRETOS (coluna guarda o id do contrato em colaboradores_metadados)
SET @sql_direto := (
  SELECT GROUP_CONCAT(
    CONCAT(
      'SELECT ''', c.TABLE_NAME, ''' AS tabela, ''', c.COLUMN_NAME, ''' AS coluna, ''direto'' AS tipo, COUNT(*) AS registros, ',
      IF(i.COLUMN_NAME IS NULL, 'NULL', 'LEFT(GROUP_CONCAT(x.`id` ORDER BY x.`id` SEPARATOR '',''), 300)'), ' AS ids_amostra ',
      'FROM `', c.TABLE_NAME, '` x ',
      'INNER JOIN colaboradores_metadados m ON m.id = x.`', c.COLUMN_NAME, '` ',
      'INNER JOIN colaboradores_metadados_exclusoes_analiticas e ',
      'ON e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato'
    )
    SEPARATOR ' UNION ALL '
  )
  FROM INFORMATION_SCHEMA.COLUMNS c
  LEFT JOIN INFORMATION_SCHEMA.COLUMNS i
    ON i.TABLE_SCHEMA = c.TABLE_SCHEMA AND i.TABLE_NAME = c.TABLE_NAME AND i.COLUMN_NAME = 'id'
  WHERE c.TABLE_SCHEMA = DATABASE()
    AND (c.TABLE_NAME, c.COLUMN_NAME) IN (
      ('colaboradores', 'metadados_id'),
      ('avaliacoes_experiencia', 'metadados_id'),
      ('avaliacoes_desempenho', 'metadados_id'),
      ('entrevistas_desligamento', 'metadados_id'),
      ('entrevista_desligamento_liberacoes', 'metadados_id'),
      ('feedbacks', 'metadados_id'),
      ('pdis', 'metadados_id'),
      ('pesquisas_integracao', 'metadados_id'),
      ('usuarios', 'colaborador_metadados_id')
    )
);

-- 4b. vínculos INDIRETOS (coluna guarda o id de um colaborador LOCAL; o contrato vem de colaboradores.metadados_id)
SET @sql_indireto := (
  SELECT GROUP_CONCAT(
    CONCAT(
      'SELECT ''', c.TABLE_NAME, ''' AS tabela, ''', c.COLUMN_NAME, ''' AS coluna, ''indireto (via colaboradores local)'' AS tipo, COUNT(*) AS registros, ',
      IF(i.COLUMN_NAME IS NULL, 'NULL', 'LEFT(GROUP_CONCAT(x.`id` ORDER BY x.`id` SEPARATOR '',''), 300)'), ' AS ids_amostra ',
      'FROM `', c.TABLE_NAME, '` x ',
      'INNER JOIN colaboradores lc ON lc.id = x.`', c.COLUMN_NAME, '` ',
      'INNER JOIN colaboradores_metadados m ON m.id = lc.metadados_id ',
      'INNER JOIN colaboradores_metadados_exclusoes_analiticas e ',
      'ON e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato'
    )
    SEPARATOR ' UNION ALL '
  )
  FROM INFORMATION_SCHEMA.COLUMNS c
  LEFT JOIN INFORMATION_SCHEMA.COLUMNS i
    ON i.TABLE_SCHEMA = c.TABLE_SCHEMA AND i.TABLE_NAME = c.TABLE_NAME AND i.COLUMN_NAME = 'id'
  WHERE c.TABLE_SCHEMA = DATABASE()
    AND (c.TABLE_NAME, c.COLUMN_NAME) IN (
      ('pesquisas_integracao', 'colaborador_id'),
      ('solicitacoes_vaga', 'colaborador_substituido_id'),
      ('solicitacoes_vaga', 'gestor_solicitante_colaborador_id'),
      ('solicitacoes_vaga', 'lider_imediato_colaborador_id'),
      ('solicitacoes_vaga', 'nome_contratado_colaborador_id'),
      ('movimentacoes_pessoal', 'colaborador_id'),
      ('movimentacoes_pessoal', 'gestor_solicitante_colaborador_id'),
      ('usuario_colaboradores', 'colaborador_id'),
      ('usuario_colaboradores', 'lider_colaborador_id'),
      ('colaborador_avaliacoes', 'colaborador_id')
    )
    -- `colaboradores.metadados_id` precisa existir para o vínculo indireto fazer sentido
    AND EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS b
      WHERE b.TABLE_SCHEMA = DATABASE() AND b.TABLE_NAME = 'colaboradores' AND b.COLUMN_NAME = 'metadados_id'
    )
);

-- 4c. executa a união gerada (sem alvos existentes → devolve uma linha explicativa)
SET @sql_dependentes := CONCAT_WS(' UNION ALL ', @sql_direto, @sql_indireto);
SET @sql_dependentes := IF(
  @sql_dependentes IS NULL OR @sql_dependentes = '',
  'SELECT ''(nenhum alvo mapeado existe neste banco)'' AS tabela, NULL AS coluna, NULL AS tipo, 0 AS registros, NULL AS ids_amostra',
  CONCAT('SELECT * FROM (', @sql_dependentes, ') r ORDER BY r.tipo, r.tabela, r.coluna')
);
PREPARE stmt_dependentes FROM @sql_dependentes;
EXECUTE stmt_dependentes;
DEALLOCATE PREPARE stmt_dependentes;

-- ---------------------------------------------------------------------------------------------
-- (5) Detalhe: colaboradores LOCAIS ligados a contratos excluídos (quando existir `colaboradores.metadados_id`)
-- ---------------------------------------------------------------------------------------------
SET @sql_locais := IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS b WHERE b.TABLE_SCHEMA = DATABASE() AND b.TABLE_NAME = 'colaboradores' AND b.COLUMN_NAME = 'metadados_id'),
  'SELECT lc.id AS colaborador_local_id, lc.nome AS colaborador_local, lc.metadados_id, m.identificador AS contrato_excluido
   FROM colaboradores lc
   INNER JOIN colaboradores_metadados m ON m.id = lc.metadados_id
   INNER JOIN colaboradores_metadados_exclusoes_analiticas e
     ON e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato
   ORDER BY lc.id',
  'SELECT ''(colaboradores.metadados_id não existe neste banco)'' AS colaborador_local_id, NULL AS colaborador_local, NULL AS metadados_id, NULL AS contrato_excluido'
);
PREPARE stmt_locais FROM @sql_locais;
EXECUTE stmt_locais;
DEALLOCATE PREPARE stmt_locais;
