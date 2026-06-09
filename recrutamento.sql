-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: rhmadeplant
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `rhmadeplant`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `rhmadeplant` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `rhmadeplant`;

--
-- Table structure for table `auditoria_usuarios`
--

DROP TABLE IF EXISTS `auditoria_usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditoria_usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_usuario_id` int DEFAULT NULL,
  `target_usuario_id` int DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_auditoria_actor` (`actor_usuario_id`),
  KEY `fk_auditoria_target` (`target_usuario_id`),
  CONSTRAINT `fk_auditoria_actor` FOREIGN KEY (`actor_usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_auditoria_target` FOREIGN KEY (`target_usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auditoria_usuarios`
--

LOCK TABLES `auditoria_usuarios` WRITE;
/*!40000 ALTER TABLE `auditoria_usuarios` DISABLE KEYS */;
INSERT INTO `auditoria_usuarios` VALUES (1,1,1,'supervisor_ensured','Usuário supervisor garantido','186.219.223.142','2026-03-17 20:37:00'),(2,1,1,'admin_password_change','Alteração administrativa de senha','191.11.143.146','2026-04-09 00:41:55'),(3,1,1,'admin_password_change','Alteração administrativa de senha','127.0.0.1','2026-04-09 13:36:29'),(4,1,1,'blocked_user_delete','Tentativa de excluir supervisor','127.0.0.1','2026-04-09 13:36:32'),(5,1,1,'admin_password_change','Alteração administrativa de senha','127.0.0.1','2026-04-09 16:09:18'),(6,1,1,'blocked_user_delete','Tentativa de excluir supervisor','127.0.0.1','2026-04-09 16:09:20'),(7,1,1,'admin_password_change','Alteração administrativa de senha','127.0.0.1','2026-04-09 16:54:01'),(8,1,1,'blocked_user_delete','Tentativa de excluir supervisor','127.0.0.1','2026-04-09 16:54:04'),(9,1,1,'admin_password_change','Alteração administrativa de senha','127.0.0.1','2026-06-03 19:40:46'),(10,1,1,'blocked_user_delete','Tentativa de excluir supervisor','127.0.0.1','2026-06-03 19:40:48');
/*!40000 ALTER TABLE `auditoria_usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `beneficios`
--

DROP TABLE IF EXISTS `beneficios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `beneficios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descricao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `parceiro` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `beneficios`
--

LOCK TABLES `beneficios` WRITE;
/*!40000 ALTER TABLE `beneficios` DISABLE KEYS */;
INSERT INTO `beneficios` VALUES (1,'Caju Multibenefícios','','','97c31e0bfd9df2bfbdb96f810b486dc8.png',1,'2026-03-17 20:40:16'),(2,'Unimed','','','5be723d09750403f5b542d285c5674fc.png',1,'2026-03-19 21:01:50'),(3,'Hapvida','','','7c18bbba2fec5e2685a240ab61432088.png',1,'2026-03-19 21:03:05'),(4,'B-day','','','019d4bd824997f8639c5f70e8fda609e.png',1,'2026-03-19 21:09:43'),(5,'Ginástica Laboral','','','1ef254056cfa362eeaf914db86529688.png',1,'2026-03-19 21:11:24'),(6,'Amistê','','','caab3d345581b091b0ba88a4c2a04817.png',0,'2026-03-19 21:21:29'),(7,'Lilium','','','ec9b124fe53e44e573191559633e9415.png',1,'2026-03-19 21:21:48'),(8,'Dress Code','','','c8a3d710ac0da0cb89001625dff9de58.png',1,'2026-03-19 21:22:08'),(9,'Programa de Desenvolvimento ao Colaborador','','','0e1e860271eebdde98415cca75718123.png',1,'2026-03-19 21:22:43'),(10,'Indicação de Talentos','','','7c747210581148209aedbc0fccc1e0b1.png',1,'2026-03-19 21:22:59'),(11,'Indicação de Empresas','','','c7bec2666efa5c5a6a755b54ec29e344.png',1,'2026-03-19 21:23:19'),(12,'Premiação por Desempenho','','','4a64cfc1abf00e0cce5ab39c8e88c76a.png',1,'2026-03-19 21:23:46');
/*!40000 ALTER TABLE `beneficios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `candidatura_historico`
--

DROP TABLE IF EXISTS `candidatura_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidatura_historico` (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidatura_id` int NOT NULL,
  `status_anterior` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status_novo` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `observacoes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_hist_candidatura` (`candidatura_id`),
  KEY `fk_hist_usuario` (`usuario_id`),
  CONSTRAINT `fk_hist_candidatura` FOREIGN KEY (`candidatura_id`) REFERENCES `candidaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `candidatura_historico`
--

LOCK TABLES `candidatura_historico` WRITE;
/*!40000 ALTER TABLE `candidatura_historico` DISABLE KEYS */;
INSERT INTO `candidatura_historico` VALUES (1,1,'Desconhecido','Rejeitado','Mudança de etapa via Pipeline',1,'2026-03-20 12:18:26'),(2,1,'Rejeitado','Entrevista','Mudança de etapa via Pipeline',1,'2026-03-20 12:23:40'),(3,1,'Entrevista','Rejeitado','Mudança de etapa via Pipeline',1,'2026-03-20 12:24:12'),(4,1,'Rejeitado','Triagem','Mudança de etapa via Pipeline',1,'2026-03-20 15:20:18'),(5,1,'Triagem','Rejeitado','Mudança de etapa via Pipeline',1,'2026-03-20 15:20:56'),(6,1,'Rejeitado','Entrevista','Mudança de etapa via Pipeline',1,'2026-03-20 18:30:57'),(7,1,'Entrevista','Rejeitado','Mudança de etapa via Pipeline',1,'2026-03-20 20:24:52'),(8,2,'Desconhecido','Rejeitado','Mudança de etapa via Pipeline',1,'2026-03-24 12:19:35'),(9,3,'Desconhecido','Triagem','Mudança de etapa via Pipeline',1,'2026-03-24 20:23:12'),(10,2,'novo','novo','Candidato fora do perfil exigido pela vaga.',1,'2026-03-24 20:38:30'),(11,2,'novo','novo','Candidato fora do perfil exigido pela vaga.',1,'2026-03-24 20:39:12'),(12,1,'novo','novo','Candidato fora do perfil exigido pela vaga.',1,'2026-03-24 20:39:42'),(13,4,'Desconhecido','Triagem','Mudança de etapa via Pipeline',1,'2026-03-26 20:01:50'),(14,4,'Triagem','Novo','Mudança de etapa via Pipeline',1,'2026-03-26 20:02:19'),(15,3,'Triagem','Novo','Mudança de etapa via Pipeline',1,'2026-04-01 14:33:59'),(16,6,'Desconhecido','Rejeitado','Mudança de etapa via Pipeline',1,'2026-04-01 14:52:16'),(17,7,'Desconhecido','Novo','Mudança de etapa via Pipeline',1,'2026-04-01 17:29:17'),(18,5,'Desconhecido','Novo','Mudança de etapa via Pipeline',1,'2026-04-01 17:29:37'),(19,8,'Desconhecido','Novo','Mudança de etapa via Pipeline',1,'2026-04-02 12:16:12'),(20,7,'Novo','Triagem','Mudança de etapa via Pipeline',1,'2026-04-02 15:32:34'),(21,7,'novo','novo','Análise de currículo',1,'2026-04-02 15:32:34'),(22,7,'Triagem','Entrevista','Mudança de etapa via Pipeline',1,'2026-04-02 19:24:24'),(23,11,'Desconhecido','Triagem','Mudança de etapa via Pipeline',1,'2026-04-02 22:11:45'),(24,11,'Triagem','Entrevista','Mudança de etapa via Pipeline',1,'2026-04-02 22:12:04'),(25,11,'novo','novo','dfjglkdgn',1,'2026-04-02 22:12:04'),(26,8,'Novo','Triagem','Mudança de etapa via Pipeline',1,'2026-04-07 20:35:36'),(27,3,'Novo','Triagem','Mudança de etapa via Pipeline',1,'2026-04-07 20:35:40'),(28,12,'Desconhecido','Triagem','Mudança de etapa via Pipeline',1,'2026-04-08 20:53:40'),(29,12,'novo','novo','Verificação inicial dos requisitos',1,'2026-04-08 20:53:40'),(30,12,'Triagem','Entrevista','Mudança de etapa via Pipeline',1,'2026-04-08 20:54:09'),(31,13,'Desconhecido','Triagem','Mudança de etapa via Pipeline',1,'2026-04-09 00:01:49'),(32,13,'novo','novo','Atende os requisitos',1,'2026-04-09 00:02:14'),(33,13,'Triagem','Entrevista','Mudança de etapa via Pipeline',1,'2026-04-09 00:02:44'),(34,13,'novo','novo','Entrevista agendada para o dia 02/05/2026',1,'2026-04-09 00:02:44'),(39,4,'Novo','Triagem','Mudança de etapa via Pipeline',1,'2026-06-03 20:20:30');
/*!40000 ALTER TABLE `candidatura_historico` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `candidaturas`
--

DROP TABLE IF EXISTS `candidaturas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidaturas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vaga_id` int NOT NULL,
  `nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `telefone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cpf` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cargo_pretendido` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `experiencia` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'novo',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `stage_id` int DEFAULT NULL,
  `observacoes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `indicacao_colaborador` tinyint(1) NOT NULL DEFAULT '0',
  `indicacao_colaborador_nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `indicacao_data_contratacao` datetime DEFAULT NULL,
  `indicacao_data_fim_experiencia` date DEFAULT NULL,
  `indicacao_pagamento_realizado` tinyint(1) NOT NULL DEFAULT '0',
  `indicacao_data_pagamento` date DEFAULT NULL,
  `indicacao_pagamento_registrado_em` datetime DEFAULT NULL,
  `indicacao_valor_comissao` decimal(10,2) DEFAULT NULL,
  `indicacao_metodo_pagamento` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `indicacao_pagamento_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pendente',
  PRIMARY KEY (`id`),
  UNIQUE KEY `cpf` (`cpf`),
  KEY `fk_cand_vaga` (`vaga_id`),
  KEY `idx_candidaturas_stage_id` (`stage_id`),
  CONSTRAINT `fk_cand_stage` FOREIGN KEY (`stage_id`) REFERENCES `pipeline_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cand_vaga` FOREIGN KEY (`vaga_id`) REFERENCES `vagas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `candidaturas`
--

LOCK TABLES `candidaturas` WRITE;
/*!40000 ALTER TABLE `candidaturas` DISABLE KEYS */;
INSERT INTO `candidaturas` VALUES (1,1,'Fabio Ozuna','fozuna@gmail.com','67993256260','81229046100','ANALISTA DE DEPARTAMENTO PESSOAL','10 anos','Fabio_Ozuna_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-03-17_20-41-36.pdf','novo','2026-03-17 20:41:37',6,'Candidato fora do perfil exigido pela vaga.',0,NULL,NULL,NULL,0,NULL,'2026-03-25 18:14:41',NULL,NULL,'pendente'),(2,1,'CLEVER RENAN','cleverrenan17@gmail.com','67984622737','06877219112','ANALISTA DE DEPARTAMENTO PESSOAL','Sou formado em Processos Gerenciais pela UFMS e construí minha carreira com foco em organização, estratégia e resultado. Tenho experiência em atendimento ao público e rotinas administrativas em empresas como BTCC, LIG10 e no setor de Qualidade do Hospital Cassems, onde atuei com elaboração de planilhas gerenciais, organização de processos e controle documental, sempre buscando eficiência e melhoria contínua.\r\n\r\nNa FAPEC, atuando na aplicação de provas para o Detran, desenvolvi ainda mais minha comunicação, postura profissional e capacidade de liderança em ambientes de responsabilidade e pressão.\r\n\r\nMeu perfil é analítico, estratégico e orientado a metas.','CLEVER_RENAN_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-03-24_03-12-39.pdf','novo','2026-03-24 03:12:39',6,'Candidato fora do perfil exigido pela vaga.',0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(3,1,'Daniele Assis Tuneca','danytuneca@hotmail.com','67992340853','02421102154','ANALISTA DE DEPARTAMENTO PESSOAL','..','Daniele_Assis_Tuneca_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-03-24_20-13-38.pdf','novo','2026-03-24 20:13:38',2,NULL,0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(4,1,'Julia Luizi Apodaca Penha','julialapenha@gmail.com','67992490224','09635224184','ANALISTA DE DEPARTAMENTO PESSOAL','Atuação nas rotinas de Departamento Pessoal, incluindo admissão, demissão, controle de ponto, folha de pagamento e encargos trabalhistas. Apoio na apuração de impostos, organização de documentos e cumprimento de prazos legais.','Julia_Luizi_Apodaca_Penha_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-03-26_17-52-47.pdf','novo','2026-03-26 17:52:47',2,NULL,0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(5,1,'Giovanna Cuttier','giovannacuttier@gmail.com','67999304609','07154782145','ANALISTA DE DEPARTAMENTO PESSOAL','Experiência em rotinas de Departamento Pessoal, gestão de folha, encargos sociais, eSocial e obrigações acessórias, com atendimento a empresas de diversos segmentos e foco em organização e cumprimento de prazos.','Giovanna_Cuttier_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-04-01_14-32-58.pdf','novo','2026-04-01 14:32:58',1,NULL,0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(6,2,'Pedro Henrique de Arruda Oliveira de Arruda','po170104@gmail.com','67999999999','06310917188','ANALISTA FISCAL','Estudante de Ciências Contábeis com grande disposição para o aprendizado, visando o desenvolvimento profissional no setor contábil ou fiscal.','Pedro_Henrique_de_Arruda_Oliveira_de_Arruda_ANALISTA_FISCAL_2026-04-01_14-51-41.pdf','novo','2026-04-01 14:51:41',6,NULL,1,'Maria Luiza',NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(7,1,'Jéssica Dos Santos Dias','jessicadias201546@gmail.com','67999968789','06842443148','ANALISTA DE DEPARTAMENTO PESSOAL','Sou uma pessoa resolutiva, possuo experiência tanto na área hospitalar quanto na área administrativa. Possuo conhecimentos avançados em inventários de medicamentos e materiais hospitalares. Em minha trajetória meu maior objetivo é aprimorar meus conhecimentos sempre agregando ao time.','Jssica_Dos_Santos_Dias_ANALISTA_DE_DEPARTAMENTO_PESSOAL_2026-04-01_17-28-21.pdf','novo','2026-04-01 17:28:21',3,'Análise de currículo',0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(8,3,'Helena Chuver','helenaschuver@gmail.com','67991699831','02598275179','ANALISTA CONTÁBIL','Tenho bastante experiência na área , conforme está descriminado no meu currículo.','Helena_Chuver_ANALISTA_CONTBIL_2026-04-01_21-50-00.pdf','novo','2026-04-01 21:50:00',2,NULL,0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(11,3,'Fabio Ozuna','fozuna@gmail.com','67993256260','00890105154','ANALISTA CONTÁBIL','Experiência em rotinas de Departamento Pessoal, gestão de folha, encargos sociais, eSocial e obrigações acessórias, com atendimento a empresas de diversos segmentos e foco em organização e cumprimento de prazos.','Fabio_Ozuna_ANALISTA_CONTBIL_2026-04-02_22-51-51.pdf','novo','2026-04-02 20:51:51',3,'dfjglkdgn',0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(12,2,'Fabio Ozuna','fozuna@gmail.com','67993256260','07085552174','ANALISTA FISCAL','Varios anos trabalhando na área','Fabio_Ozuna_ANALISTA_FISCAL_2026-04-08_20-52-20.pdf','novo','2026-04-08 20:52:20',3,'Verificação inicial dos requisitos',1,'Maria da Silva',NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente'),(13,2,'Coritiano da Silva','fozuna@gmail.com','67993256260','71554580153','ANALISTA FISCAL','jydjydjdgdgfdgdgfdgf','Coritiano_da_Silva_ANALISTA_FISCAL_2026-04-09_00-01-15.pdf','novo','2026-04-09 00:01:15',3,'Entrevista agendada para o dia 02/05/2026',1,'José da Silva',NULL,NULL,0,NULL,NULL,NULL,NULL,'pendente');
/*!40000 ALTER TABLE `candidaturas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cargos`
--

DROP TABLE IF EXISTS `cargos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cargos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(160) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cargos_nome` (`nome`),
  UNIQUE KEY `uk_cargos_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cargos`
--

LOCK TABLES `cargos` WRITE;
/*!40000 ALTER TABLE `cargos` DISABLE KEYS */;
INSERT INTO `cargos` VALUES (1,'ALMOXARIFE','almoxarife',1,'2026-06-03 20:55:13'),(2,'ANALISTA ADMINISTRATIVO','analista-administrativo',1,'2026-06-03 20:55:13'),(3,'ANALISTA CONTABIL','analista-contabil',1,'2026-06-03 20:55:13'),(4,'ANALISTA DE CONTROLADORIA','analista-de-controladoria',1,'2026-06-03 20:55:13'),(5,'ANALISTA DE DP','analista-de-dp',1,'2026-06-03 20:55:13'),(6,'ANALISTA DE FATURAMENTO','analista-de-faturamento',1,'2026-06-03 20:55:13'),(7,'ANALISTA DE LOGISTICA','analista-de-logistica',1,'2026-06-03 20:55:13'),(8,'ANALISTA DE PCP','analista-de-pcp',1,'2026-06-03 20:55:13'),(9,'ANALISTA DE RH','analista-de-rh',1,'2026-06-03 20:55:13'),(10,'ANALISTA DE SUPRIMENTOS','analista-de-suprimentos',1,'2026-06-03 20:55:13'),(11,'ANALISTA FINANCEIRO','analista-financeiro',1,'2026-06-03 20:55:13'),(12,'ASSISITENTE DE RH','assisitente-de-rh',1,'2026-06-03 20:55:13'),(13,'ASSISTENTE ADMINISTRATIVO','assistente-administrativo',1,'2026-06-03 20:55:13'),(14,'ASSISTENTE CONTABIL','assistente-contabil',1,'2026-06-03 20:55:13'),(15,'ASSISTENTE DE ALMOXARIFADO','assistente-de-almoxarifado',1,'2026-06-03 20:55:13'),(16,'ASSISTENTE DE FATURAMENTO','assistente-de-faturamento',1,'2026-06-03 20:55:13'),(17,'ASSISTENTE DE PCP','assistente-de-pcp',1,'2026-06-03 20:55:13'),(18,'ASSISTENTE FINANCEIRO','assistente-financeiro',1,'2026-06-03 20:55:13'),(19,'ASSISTENTE FISCAL','assistente-fiscal',1,'2026-06-03 20:55:13'),(20,'AUX. DE SERVIÇOS GERAIS','aux-de-servicos-gerais',1,'2026-06-03 20:55:13'),(21,'AUXILIAR DE COZINHA','auxiliar-de-cozinha',1,'2026-06-03 20:55:13'),(22,'AUXILIAR SERVICOS GERAIS','auxiliar-servicos-gerais',1,'2026-06-03 20:55:13'),(23,'CALDEIREIRO','caldeireiro',1,'2026-06-03 20:55:13'),(24,'CONTADOR','contador',1,'2026-06-03 20:55:13'),(25,'CONTROLLER','controller',1,'2026-06-03 20:55:13'),(26,'COORDENADOR DE RH','coordenador-de-rh',1,'2026-06-03 20:55:13'),(27,'COZINHEIRA','cozinheira',1,'2026-06-03 20:55:13'),(28,'DIRETOR ADMINISTRATIVO','diretor-administrativo',1,'2026-06-03 20:55:13'),(29,'DIRETOR GERAL','diretor-geral',1,'2026-06-03 20:55:13'),(30,'ENCARREGADA FISCAL','encarregada-fiscal',1,'2026-06-03 20:55:13'),(31,'ENCARREGADO DE FATURAMENTO','encarregado-de-faturamento',1,'2026-06-03 20:55:13'),(32,'ENCARREGADO FACILITES','encarregado-facilites',1,'2026-06-03 20:55:13'),(33,'ENCARREGADO TI','encarregado-ti',1,'2026-06-03 20:55:13'),(34,'FRENTISTA','frentista',1,'2026-06-03 20:55:13'),(35,'GERENTE DE MANUTENCAO','gerente-de-manutencao',1,'2026-06-03 20:55:13'),(36,'GERENTE DE OPERACAO','gerente-de-operacao',1,'2026-06-03 20:55:13'),(37,'GERENTE GERAL','gerente-geral',1,'2026-06-03 20:55:13'),(38,'LIDER DE OPERAÇÃO FLORESTAL','lider-de-operacao-florestal',1,'2026-06-03 20:55:13'),(39,'LIDER DE SERVICOS DE LIMPEZA','lider-de-servicos-de-limpeza',1,'2026-06-03 20:55:13'),(40,'MECANICO','mecanico',1,'2026-06-03 20:55:13'),(41,'MOTORISTA DE CARRETA','motorista-de-carreta',1,'2026-06-03 20:55:13'),(42,'MOTORISTA DE COMBOIO','motorista-de-comboio',1,'2026-06-03 20:55:13'),(43,'OPERADOR DE MAQUINA FLORESTAL','operador-de-maquina-florestal',1,'2026-06-03 20:55:13'),(44,'PINTOR DE VEICULOS','pintor-de-veiculos',1,'2026-06-03 20:55:13'),(45,'PORTEIRO','porteiro',1,'2026-06-03 20:55:13'),(46,'PROGRAMADOR DE MANUTENÇÃO','programador-de-manutencao',1,'2026-06-03 20:55:13'),(47,'SERVENTE DE REFLORESTAMENTO','servente-de-reflorestamento',1,'2026-06-03 20:55:13'),(48,'SERVICOS GERAIS','servicos-gerais',1,'2026-06-03 20:55:13'),(49,'SOLDADOR','soldador',1,'2026-06-03 20:55:13'),(50,'SUPERVISOR DE FROTA','supervisor-de-frota',1,'2026-06-03 20:55:13'),(51,'SUPERVISOR DE MANUTENCAO','supervisor-de-manutencao',1,'2026-06-03 20:55:13'),(52,'SUPERVISOR DE OPERACAO','supervisor-de-operacao',1,'2026-06-03 20:55:13'),(53,'SUPERVISOR DE SUPRIMENTOS','supervisor-de-suprimentos',1,'2026-06-03 20:55:13'),(54,'SUPERVISOR DE TI','supervisor-de-ti',1,'2026-06-03 20:55:13'),(55,'SUPERVISORA FINANCEIRO','supervisora-financeiro',1,'2026-06-03 20:55:13'),(56,'SUPERVISOR FINANCEIRO','supervisor-financeiro',1,'2026-06-03 20:55:13'),(57,'TECNICO EM SEGURANÇA DO TRABALHO','tecnico-em-seguranca-do-trabalho',1,'2026-06-03 20:55:13'),(58,'TECNICO MEC. EM AUTOMACAO','tecnico-mec-em-automacao',1,'2026-06-03 20:55:13');
/*!40000 ALTER TABLE `cargos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `colaboradores`
--

DROP TABLE IF EXISTS `colaboradores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `colaboradores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `cargo_id` int NOT NULL,
  `empresa_id` int DEFAULT NULL,
  `setor_id` int DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_colaboradores_slug` (`slug`),
  KEY `idx_colaboradores_cargo_id` (`cargo_id`),
  KEY `idx_colaboradores_empresa_id` (`empresa_id`),
  KEY `idx_colaboradores_setor_id` (`setor_id`),
  CONSTRAINT `fk_colaboradores_cargo` FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_colaboradores_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_colaboradores_setor` FOREIGN KEY (`setor_id`) REFERENCES `setores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `colaboradores`
--

LOCK TABLES `colaboradores` WRITE;
/*!40000 ALTER TABLE `colaboradores` DISABLE KEYS */;
INSERT INTO `colaboradores` VALUES (1,'ADAO BOEIRA DE OLIVEIRA','adao-boeira-de-oliveira',52,NULL,NULL,1,'2026-06-03 20:55:13'),(2,'CARLOS JARBAS ARCE VIEIRA','carlos-jarbas-arce-vieira',32,NULL,NULL,1,'2026-06-03 20:55:13'),(3,'CELSO LUIZ MELLO CORREA','celso-luiz-mello-correa',29,NULL,NULL,1,'2026-06-03 20:55:13'),(4,'FABIAN MOLINAS','fabian-molinas',38,NULL,NULL,1,'2026-06-03 20:55:13'),(5,'FABIANE FREITAS MENDONCA','fabiane-freitas-mendonca',26,NULL,NULL,1,'2026-06-03 20:55:13'),(6,'FABIANO MACEDO DE LIMA','fabiano-macedo-de-lima',38,NULL,NULL,1,'2026-06-03 20:55:13'),(7,'FABIO JUNIOR MORENO KUKIEL','fabio-junior-moreno-kukiel',35,NULL,NULL,1,'2026-06-03 20:55:13'),(8,'FABIO OZUNA LIMA','fabio-ozuna-lima',54,NULL,NULL,1,'2026-06-03 20:55:13'),(9,'GUSTAVO COTTA LOBO LEITE','gustavo-cotta-lobo-leite',37,NULL,NULL,1,'2026-06-03 20:55:13'),(10,'HELCIO JOSE DE OLIVEIRA','helcio-jose-de-oliveira',52,NULL,NULL,1,'2026-06-03 20:55:13'),(11,'JOAO MORENO RODRIGUES','joao-moreno-rodrigues',51,NULL,NULL,1,'2026-06-03 20:55:13'),(12,'KARINA SERPA DA SILVA','karina-serpa-da-silva',24,NULL,NULL,1,'2026-06-03 20:55:13'),(13,'LUDIMILA DAIANY CRISTALDO DE LIMA','ludimila-daiany-cristaldo-de-lima',30,NULL,NULL,1,'2026-06-03 20:55:13'),(14,'MARCOS MACIEL SALAU','marcos-maciel-salau',38,NULL,NULL,1,'2026-06-03 20:55:13'),(15,'MARLI RECALCATI','marli-recalcati',55,NULL,NULL,1,'2026-06-03 20:55:13'),(16,'MATHEUS ARANDA MOREIRA','matheus-aranda-moreira',31,NULL,NULL,1,'2026-06-03 20:55:13'),(17,'ODAIR GONCALVES DA SILVA','odair-goncalves-da-silva',38,NULL,NULL,1,'2026-06-03 20:55:13'),(18,'ODAIR PEDRO TONIN','odair-pedro-tonin',36,NULL,NULL,1,'2026-06-03 20:55:13'),(19,'ORLANDO CRUZ CARDOSO','orlando-cruz-cardoso',51,NULL,NULL,1,'2026-06-03 20:55:13'),(20,'PAULO DE SOUZA DANTAS FILHO','paulo-de-souza-dantas-filho',56,NULL,NULL,1,'2026-06-03 20:55:13'),(21,'PAULO ROBERTO CAMPOS CORREA','paulo-roberto-campos-correa',53,NULL,NULL,1,'2026-06-03 20:55:13'),(22,'RENAN GONÇALVES DE MORAES SIMÕES','renan-goncalves-de-moraes-simoes',28,NULL,NULL,1,'2026-06-03 20:55:13'),(23,'ROBSON MASSATO SAHEKI','robson-massato-saheki',38,NULL,NULL,1,'2026-06-03 20:55:13'),(24,'RODRIGO SOARES DE AZEVEDO','rodrigo-soares-de-azevedo',25,NULL,NULL,1,'2026-06-03 20:55:13');
/*!40000 ALTER TABLE `colaboradores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empresas`
--

DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(160) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_empresas_nome` (`nome`),
  UNIQUE KEY `uk_empresas_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empresas`
--

LOCK TABLES `empresas` WRITE;
/*!40000 ALTER TABLE `empresas` DISABLE KEYS */;
INSERT INTO `empresas` VALUES (1,'MADEPLANT FLORESTAL LTDA','madeplant-florestal-ltda',1,'2026-06-03 20:55:13'),(2,'MADEPLANT TRANSPORTES','madeplant-transportes',1,'2026-06-03 20:55:13'),(3,'MADEPLANT CSC','madeplant-csc',1,'2026-06-03 20:55:13'),(4,'PROSPECTA SERVICOS','prospecta-servicos',1,'2026-06-03 20:55:13');
/*!40000 ALTER TABLE `empresas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `indicacao_pagamento_auditoria`
--

DROP TABLE IF EXISTS `indicacao_pagamento_auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `indicacao_pagamento_auditoria` (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidatura_id` int NOT NULL,
  `data_anterior` date DEFAULT NULL,
  `data_nova` date NOT NULL,
  `motivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `usuario_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ind_pag_cand` (`candidatura_id`),
  KEY `fk_ind_pag_user` (`usuario_id`),
  CONSTRAINT `fk_ind_pag_cand` FOREIGN KEY (`candidatura_id`) REFERENCES `candidaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ind_pag_user` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `indicacao_pagamento_auditoria`
--

LOCK TABLES `indicacao_pagamento_auditoria` WRITE;
/*!40000 ALTER TABLE `indicacao_pagamento_auditoria` DISABLE KEYS */;
INSERT INTO `indicacao_pagamento_auditoria` VALUES (1,1,NULL,'2026-03-25','Registro inicial do pagamento',1,'2026-03-25 18:14:41');
/*!40000 ALTER TABLE `indicacao_pagamento_auditoria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notas_recrutador`
--

DROP TABLE IF EXISTS `notas_recrutador`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_recrutador` (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidatura_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `nota` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_nota_cand` (`candidatura_id`),
  KEY `fk_nota_user` (`usuario_id`),
  CONSTRAINT `fk_nota_cand` FOREIGN KEY (`candidatura_id`) REFERENCES `candidaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nota_user` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notas_recrutador`
--

LOCK TABLES `notas_recrutador` WRITE;
/*!40000 ALTER TABLE `notas_recrutador` DISABLE KEYS */;
/*!40000 ALTER TABLE `notas_recrutador` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `token_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_password_reset_usuario` (`usuario_id`),
  CONSTRAINT `fk_password_reset_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pipeline_movements`
--

DROP TABLE IF EXISTS `pipeline_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pipeline_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidatura_id` int NOT NULL,
  `stage_anterior_id` int DEFAULT NULL,
  `stage_novo_id` int NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mov_cand` (`candidatura_id`),
  KEY `fk_mov_stage_ant` (`stage_anterior_id`),
  KEY `fk_mov_stage_new` (`stage_novo_id`),
  KEY `fk_mov_user` (`usuario_id`),
  CONSTRAINT `fk_mov_cand` FOREIGN KEY (`candidatura_id`) REFERENCES `candidaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mov_stage_ant` FOREIGN KEY (`stage_anterior_id`) REFERENCES `pipeline_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mov_stage_new` FOREIGN KEY (`stage_novo_id`) REFERENCES `pipeline_stages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mov_user` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pipeline_movements`
--

LOCK TABLES `pipeline_movements` WRITE;
/*!40000 ALTER TABLE `pipeline_movements` DISABLE KEYS */;
INSERT INTO `pipeline_movements` VALUES (1,1,NULL,6,1,'2026-03-20 12:18:26'),(2,1,6,3,1,'2026-03-20 12:23:40'),(3,1,3,6,1,'2026-03-20 12:24:12'),(4,1,6,2,1,'2026-03-20 15:20:18'),(5,1,2,6,1,'2026-03-20 15:20:56'),(6,1,6,3,1,'2026-03-20 18:30:57'),(7,1,3,6,1,'2026-03-20 20:24:52'),(8,2,NULL,6,1,'2026-03-24 12:19:35'),(9,3,NULL,2,1,'2026-03-24 20:23:12'),(10,4,NULL,2,1,'2026-03-26 20:01:50'),(11,4,2,1,1,'2026-03-26 20:02:19'),(12,3,2,1,1,'2026-04-01 14:33:59'),(13,6,NULL,6,1,'2026-04-01 14:52:16'),(14,7,NULL,1,1,'2026-04-01 17:29:17'),(15,5,NULL,1,1,'2026-04-01 17:29:37'),(16,8,NULL,1,1,'2026-04-02 12:16:12'),(17,7,1,2,1,'2026-04-02 15:32:34'),(18,7,2,3,1,'2026-04-02 19:24:24'),(19,11,NULL,2,1,'2026-04-02 22:11:45'),(20,11,2,3,1,'2026-04-02 22:12:04'),(21,8,1,2,1,'2026-04-07 20:35:36'),(22,3,1,2,1,'2026-04-07 20:35:40'),(23,12,NULL,2,1,'2026-04-08 20:53:40'),(24,12,2,3,1,'2026-04-08 20:54:09'),(25,13,NULL,2,1,'2026-04-09 00:01:49'),(26,13,2,3,1,'2026-04-09 00:02:44'),(31,4,1,2,1,'2026-06-03 20:20:30');
/*!40000 ALTER TABLE `pipeline_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pipeline_stages`
--

DROP TABLE IF EXISTS `pipeline_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pipeline_stages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `ordem` int NOT NULL DEFAULT '0',
  `cor` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '#cccccc',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pipeline_stages`
--

LOCK TABLES `pipeline_stages` WRITE;
/*!40000 ALTER TABLE `pipeline_stages` DISABLE KEYS */;
INSERT INTO `pipeline_stages` VALUES (1,'Novo',1,'#3b82f6','2026-03-19 20:03:04'),(2,'Triagem',2,'#f59e0b','2026-03-19 20:03:04'),(3,'Entrevista',3,'#8b5cf6','2026-03-19 20:03:04'),(4,'Proposta',4,'#10b981','2026-03-19 20:03:04'),(5,'Contratado',5,'#059669','2026-03-19 20:03:04'),(6,'Rejeitado',6,'#ef4444','2026-03-19 20:03:04');
/*!40000 ALTER TABLE `pipeline_stages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requisitos`
--

DROP TABLE IF EXISTS `requisitos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vaga_id` int NOT NULL,
  `descricao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `obrigatorio` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_req_vaga` (`vaga_id`),
  CONSTRAINT `fk_req_vaga` FOREIGN KEY (`vaga_id`) REFERENCES `vagas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requisitos`
--

LOCK TABLES `requisitos` WRITE;
/*!40000 ALTER TABLE `requisitos` DISABLE KEYS */;
/*!40000 ALTER TABLE `requisitos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setores`
--

DROP TABLE IF EXISTS `setores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(140) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(160) COLLATE utf8mb4_general_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setores_nome` (`nome`),
  UNIQUE KEY `uk_setores_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setores`
--

LOCK TABLES `setores` WRITE;
/*!40000 ALTER TABLE `setores` DISABLE KEYS */;
INSERT INTO `setores` VALUES (1,'CONTABILIDADE','contabilidade',1,'2026-06-03 20:55:13'),(2,'CONTROLADORIA','controladoria',1,'2026-06-03 20:55:13'),(3,'FACILITES','facilites',1,'2026-06-03 20:55:13'),(4,'FATURAMENTO','faturamento',1,'2026-06-03 20:55:13'),(5,'FINANCEIRO','financeiro',1,'2026-06-03 20:55:13'),(6,'FISCAL','fiscal',1,'2026-06-03 20:55:13'),(7,'LOGÍSTICA','logistica',1,'2026-06-03 20:55:13'),(8,'MANUTENÇÃO','manutencao',1,'2026-06-03 20:55:13'),(9,'PRODUÇÃO','producao',1,'2026-06-03 20:55:13'),(10,'RH/DP/SST','rh-dp-sst',1,'2026-06-03 20:55:13'),(11,'SUPRIMENTOS','suprimentos',1,'2026-06-03 20:55:13'),(12,'TI','ti',1,'2026-06-03 20:55:13');
/*!40000 ALTER TABLE `setores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `senha_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('admin','rh','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'viewer',
  `is_supervisor` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `last_password_reset_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Fabio Ozuna','fabio.ozuna@madeplant.com.br','$2y$10$b5qeLyTpYx3tKJ7WtTb73.629RM45P55wLflJNHWdJpnvx.cPtZNq','admin',1,'2026-03-17 17:13:34','2026-06-03 15:49:30','2026-03-17 17:13:34');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vagas`
--

DROP TABLE IF EXISTS `vagas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vagas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descricao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `requisitos` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `area` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `local` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vagas`
--

LOCK TABLES `vagas` WRITE;
/*!40000 ALTER TABLE `vagas` DISABLE KEYS */;
INSERT INTO `vagas` VALUES (1,'ANALISTA DE DEPARTAMENTO PESSOAL','-> Atuar nas rotinas de departamento pessoal de empresas enquadradas nos Regimes (Simples Nacional, Lucro Real e Lucro Presumido).\r\n\r\n-> Processamento de folha, férias, rescisões, acompanhamento de afastamentos, Sefip, DCTF WEB, e E-social, entre outras atividades pertinentes ao cargo.','-> Conhecimento no sistema Domínio\r\n\r\n-> Experiência mínima de 1 ano em escritório de contabilidade','Departamento Pessoal','Campo Grande',1,'2026-03-17 20:39:06'),(2,'ANALISTA FISCAL','-> Apuração fiscal das empresas de Regime Lucro Real e Presumido.\r\n\r\n-> Apuração dos impostos municipais, estaduais e federais, entrega de obrigações acessórias (EFD Fiscal., EFD Contábil, EFD REinf, DCTF), entre outras atividades pertinentes ao cargo.','-> Conhecimento no sistema Domínio\r\n\r\n-> Experiência mínima de 1 ano em escritório de contabilidade','Fiscal','Campo Grande',1,'2026-03-19 20:48:48'),(3,'ANALISTA CONTÁBIL','-> Atuar nas rotinas contábeis das empresas enquadradas nos regimes (Simples Nacional, Lucro Presumido e Lucro Real), lançamentos e análise de demonstrações contábeis, fechamento de balanço, conciliação de fornecedores, bancos, clientes, obrigações acessórias, entre outras atividades pertinentes ao cargo.','-> Conhecimento no sistema Domínio\r\n\r\n-> Experiência mínima de 1 ano em escritório de contabilidade\r\n\r\n-> Ser inscrito no CRC ou cursando superior em Ciências Contábeis','Contábil','Campo Grande',1,'2026-03-19 20:49:58');
/*!40000 ALTER TABLE `vagas` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
