-- Migration versionada: cadastro mestre organizacional RH Madeplant
-- Data: 2026-06-03
-- Objetivo: criar estrutura de empresas, setores, cargos e colaboradores,
--           alem de popular o banco com os registros mestres solicitados.
-- Compatibilidade: MariaDB 10.6+ e MySQL 8+

-- 1. Estrutura de empresas
CREATE TABLE IF NOT EXISTS `empresas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_empresas_nome` (`nome`),
  UNIQUE KEY `uk_empresas_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Estrutura de setores
CREATE TABLE IF NOT EXISTS `setores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(140) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setores_nome` (`nome`),
  UNIQUE KEY `uk_setores_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Estrutura de cargos
CREATE TABLE IF NOT EXISTS `cargos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cargos_nome` (`nome`),
  UNIQUE KEY `uk_cargos_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Estrutura de colaboradores
-- empresa_id e setor_id permanecem opcionais porque esses vinculos nao foram informados.
CREATE TABLE IF NOT EXISTS `colaboradores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(180) NOT NULL,
  `slug` VARCHAR(200) NOT NULL,
  `cargo_id` INT NOT NULL,
  `empresa_id` INT NULL,
  `setor_id` INT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_colaboradores_slug` (`slug`),
  KEY `idx_colaboradores_cargo_id` (`cargo_id`),
  KEY `idx_colaboradores_empresa_id` (`empresa_id`),
  KEY `idx_colaboradores_setor_id` (`setor_id`),
  CONSTRAINT `fk_colaboradores_cargo` FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_colaboradores_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_colaboradores_setor` FOREIGN KEY (`setor_id`) REFERENCES `setores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

START TRANSACTION;

-- 5. Carga de empresas
INSERT INTO `empresas` (`nome`, `slug`, `ativo`) VALUES
  ('MADEPLANT FLORESTAL LTDA', 'madeplant-florestal-ltda', 1),
  ('MADEPLANT TRANSPORTES', 'madeplant-transportes', 1),
  ('MADEPLANT CSC', 'madeplant-csc', 1),
  ('PROSPECTA SERVICOS', 'prospecta-servicos', 1)
ON DUPLICATE KEY UPDATE
  `nome` = VALUES(`nome`),
  `ativo` = VALUES(`ativo`);

-- 6. Carga de setores
INSERT INTO `setores` (`nome`, `slug`, `ativo`) VALUES
  ('CONTABILIDADE', 'contabilidade', 1),
  ('CONTROLADORIA', 'controladoria', 1),
  ('FACILITES', 'facilites', 1),
  ('FATURAMENTO', 'faturamento', 1),
  ('FINANCEIRO', 'financeiro', 1),
  ('FISCAL', 'fiscal', 1),
  ('LOGÍSTICA', 'logistica', 1),
  ('MANUTENÇÃO', 'manutencao', 1),
  ('PRODUÇÃO', 'producao', 1),
  ('RH/DP/SST', 'rh-dp-sst', 1),
  ('SUPRIMENTOS', 'suprimentos', 1),
  ('TI', 'ti', 1)
ON DUPLICATE KEY UPDATE
  `nome` = VALUES(`nome`),
  `ativo` = VALUES(`ativo`);

-- 7. Carga de cargos
INSERT INTO `cargos` (`nome`, `slug`, `ativo`) VALUES
  ('ALMOXARIFE', 'almoxarife', 1),
  ('ANALISTA ADMINISTRATIVO', 'analista-administrativo', 1),
  ('ANALISTA CONTABIL', 'analista-contabil', 1),
  ('ANALISTA DE CONTROLADORIA', 'analista-de-controladoria', 1),
  ('ANALISTA DE DP', 'analista-de-dp', 1),
  ('ANALISTA DE FATURAMENTO', 'analista-de-faturamento', 1),
  ('ANALISTA DE LOGISTICA', 'analista-de-logistica', 1),
  ('ANALISTA DE PCP', 'analista-de-pcp', 1),
  ('ANALISTA DE RH', 'analista-de-rh', 1),
  ('ANALISTA DE SUPRIMENTOS', 'analista-de-suprimentos', 1),
  ('ANALISTA FINANCEIRO', 'analista-financeiro', 1),
  ('ASSISITENTE DE RH', 'assisitente-de-rh', 1),
  ('ASSISTENTE ADMINISTRATIVO', 'assistente-administrativo', 1),
  ('ASSISTENTE CONTABIL', 'assistente-contabil', 1),
  ('ASSISTENTE DE ALMOXARIFADO', 'assistente-de-almoxarifado', 1),
  ('ASSISTENTE DE FATURAMENTO', 'assistente-de-faturamento', 1),
  ('ASSISTENTE DE PCP', 'assistente-de-pcp', 1),
  ('ASSISTENTE FINANCEIRO', 'assistente-financeiro', 1),
  ('ASSISTENTE FISCAL', 'assistente-fiscal', 1),
  ('AUX. DE SERVIÇOS GERAIS', 'aux-de-servicos-gerais', 1),
  ('AUXILIAR DE COZINHA', 'auxiliar-de-cozinha', 1),
  ('AUXILIAR SERVICOS GERAIS', 'auxiliar-servicos-gerais', 1),
  ('CALDEIREIRO', 'caldeireiro', 1),
  ('CONTADOR', 'contador', 1),
  ('CONTROLLER', 'controller', 1),
  ('COORDENADOR DE RH', 'coordenador-de-rh', 1),
  ('COZINHEIRA', 'cozinheira', 1),
  ('DIRETOR ADMINISTRATIVO', 'diretor-administrativo', 1),
  ('DIRETOR GERAL', 'diretor-geral', 1),
  ('ENCARREGADA FISCAL', 'encarregada-fiscal', 1),
  ('ENCARREGADO DE FATURAMENTO', 'encarregado-de-faturamento', 1),
  ('ENCARREGADO FACILITES', 'encarregado-facilites', 1),
  ('ENCARREGADO TI', 'encarregado-ti', 1),
  ('FRENTISTA', 'frentista', 1),
  ('GERENTE DE MANUTENCAO', 'gerente-de-manutencao', 1),
  ('GERENTE DE OPERACAO', 'gerente-de-operacao', 1),
  ('GERENTE GERAL', 'gerente-geral', 1),
  ('LIDER DE OPERAÇÃO FLORESTAL', 'lider-de-operacao-florestal', 1),
  ('LIDER DE SERVICOS DE LIMPEZA', 'lider-de-servicos-de-limpeza', 1),
  ('MECANICO', 'mecanico', 1),
  ('MOTORISTA DE CARRETA', 'motorista-de-carreta', 1),
  ('MOTORISTA DE COMBOIO', 'motorista-de-comboio', 1),
  ('OPERADOR DE MAQUINA FLORESTAL', 'operador-de-maquina-florestal', 1),
  ('PINTOR DE VEICULOS', 'pintor-de-veiculos', 1),
  ('PORTEIRO', 'porteiro', 1),
  ('PROGRAMADOR DE MANUTENÇÃO', 'programador-de-manutencao', 1),
  ('SERVENTE DE REFLORESTAMENTO', 'servente-de-reflorestamento', 1),
  ('SERVICOS GERAIS', 'servicos-gerais', 1),
  ('SOLDADOR', 'soldador', 1),
  ('SUPERVISOR DE FROTA', 'supervisor-de-frota', 1),
  ('SUPERVISOR DE MANUTENCAO', 'supervisor-de-manutencao', 1),
  ('SUPERVISOR DE OPERACAO', 'supervisor-de-operacao', 1),
  ('SUPERVISOR DE SUPRIMENTOS', 'supervisor-de-suprimentos', 1),
  ('SUPERVISOR DE TI', 'supervisor-de-ti', 1),
  ('SUPERVISORA FINANCEIRO', 'supervisora-financeiro', 1),
  ('SUPERVISOR FINANCEIRO', 'supervisor-financeiro', 1),
  ('TECNICO EM SEGURANÇA DO TRABALHO', 'tecnico-em-seguranca-do-trabalho', 1),
  ('TECNICO MEC. EM AUTOMACAO', 'tecnico-mec-em-automacao', 1)
ON DUPLICATE KEY UPDATE
  `nome` = VALUES(`nome`),
  `ativo` = VALUES(`ativo`);

-- 8. Carga de colaboradores com vinculo obrigatorio ao cargo
INSERT INTO `colaboradores` (`nome`, `slug`, `cargo_id`, `empresa_id`, `setor_id`, `ativo`)
SELECT seed.nome, seed.slug, cargos.id, NULL, NULL, 1
FROM (
  SELECT 'ADAO BOEIRA DE OLIVEIRA' AS nome, 'adao-boeira-de-oliveira' AS slug, 'SUPERVISOR DE OPERACAO' AS cargo_nome
  UNION ALL
  SELECT 'CARLOS JARBAS ARCE VIEIRA' AS nome, 'carlos-jarbas-arce-vieira' AS slug, 'ENCARREGADO FACILITES' AS cargo_nome
  UNION ALL
  SELECT 'CELSO LUIZ MELLO CORREA' AS nome, 'celso-luiz-mello-correa' AS slug, 'DIRETOR GERAL' AS cargo_nome
  UNION ALL
  SELECT 'FABIAN MOLINAS' AS nome, 'fabian-molinas' AS slug, 'LIDER DE OPERAÇÃO FLORESTAL' AS cargo_nome
  UNION ALL
  SELECT 'FABIANE FREITAS MENDONCA' AS nome, 'fabiane-freitas-mendonca' AS slug, 'COORDENADOR DE RH' AS cargo_nome
  UNION ALL
  SELECT 'FABIANO MACEDO DE LIMA' AS nome, 'fabiano-macedo-de-lima' AS slug, 'LIDER DE OPERAÇÃO FLORESTAL' AS cargo_nome
  UNION ALL
  SELECT 'FABIO JUNIOR MORENO KUKIEL' AS nome, 'fabio-junior-moreno-kukiel' AS slug, 'GERENTE DE MANUTENCAO' AS cargo_nome
  UNION ALL
  SELECT 'FABIO OZUNA LIMA' AS nome, 'fabio-ozuna-lima' AS slug, 'SUPERVISOR DE TI' AS cargo_nome
  UNION ALL
  SELECT 'GUSTAVO COTTA LOBO LEITE' AS nome, 'gustavo-cotta-lobo-leite' AS slug, 'GERENTE GERAL' AS cargo_nome
  UNION ALL
  SELECT 'HELCIO JOSE DE OLIVEIRA' AS nome, 'helcio-jose-de-oliveira' AS slug, 'SUPERVISOR DE OPERACAO' AS cargo_nome
  UNION ALL
  SELECT 'JOAO MORENO RODRIGUES' AS nome, 'joao-moreno-rodrigues' AS slug, 'SUPERVISOR DE MANUTENCAO' AS cargo_nome
  UNION ALL
  SELECT 'KARINA SERPA DA SILVA' AS nome, 'karina-serpa-da-silva' AS slug, 'CONTADOR' AS cargo_nome
  UNION ALL
  SELECT 'LUDIMILA DAIANY CRISTALDO DE LIMA' AS nome, 'ludimila-daiany-cristaldo-de-lima' AS slug, 'ENCARREGADA FISCAL' AS cargo_nome
  UNION ALL
  SELECT 'MARCOS MACIEL SALAU' AS nome, 'marcos-maciel-salau' AS slug, 'LIDER DE OPERAÇÃO FLORESTAL' AS cargo_nome
  UNION ALL
  SELECT 'MARLI RECALCATI' AS nome, 'marli-recalcati' AS slug, 'SUPERVISORA FINANCEIRO' AS cargo_nome
  UNION ALL
  SELECT 'MATHEUS ARANDA MOREIRA' AS nome, 'matheus-aranda-moreira' AS slug, 'ENCARREGADO DE FATURAMENTO' AS cargo_nome
  UNION ALL
  SELECT 'ODAIR GONCALVES DA SILVA' AS nome, 'odair-goncalves-da-silva' AS slug, 'LIDER DE OPERAÇÃO FLORESTAL' AS cargo_nome
  UNION ALL
  SELECT 'ODAIR PEDRO TONIN' AS nome, 'odair-pedro-tonin' AS slug, 'GERENTE DE OPERACAO' AS cargo_nome
  UNION ALL
  SELECT 'ORLANDO CRUZ CARDOSO' AS nome, 'orlando-cruz-cardoso' AS slug, 'SUPERVISOR DE MANUTENCAO' AS cargo_nome
  UNION ALL
  SELECT 'PAULO DE SOUZA DANTAS FILHO' AS nome, 'paulo-de-souza-dantas-filho' AS slug, 'SUPERVISOR FINANCEIRO' AS cargo_nome
  UNION ALL
  SELECT 'PAULO ROBERTO CAMPOS CORREA' AS nome, 'paulo-roberto-campos-correa' AS slug, 'SUPERVISOR DE SUPRIMENTOS' AS cargo_nome
  UNION ALL
  SELECT 'RENAN GONÇALVES DE MORAES SIMÕES' AS nome, 'renan-goncalves-de-moraes-simoes' AS slug, 'DIRETOR ADMINISTRATIVO' AS cargo_nome
  UNION ALL
  SELECT 'ROBSON MASSATO SAHEKI' AS nome, 'robson-massato-saheki' AS slug, 'LIDER DE OPERAÇÃO FLORESTAL' AS cargo_nome
  UNION ALL
  SELECT 'RODRIGO SOARES DE AZEVEDO' AS nome, 'rodrigo-soares-de-azevedo' AS slug, 'CONTROLLER' AS cargo_nome
) AS seed
INNER JOIN `cargos` ON `cargos`.`nome` = seed.cargo_nome
ON DUPLICATE KEY UPDATE
  `nome` = VALUES(`nome`),
  `cargo_id` = VALUES(`cargo_id`),
  `empresa_id` = VALUES(`empresa_id`),
  `setor_id` = VALUES(`setor_id`),
  `ativo` = VALUES(`ativo`);

COMMIT;

-- 9. Validacao pos-carga
SELECT 'empresas' AS entidade, COUNT(*) AS total FROM `empresas`
UNION ALL
SELECT 'setores' AS entidade, COUNT(*) AS total FROM `setores`
UNION ALL
SELECT 'cargos' AS entidade, COUNT(*) AS total FROM `cargos`
UNION ALL
SELECT 'colaboradores' AS entidade, COUNT(*) AS total FROM `colaboradores`;
