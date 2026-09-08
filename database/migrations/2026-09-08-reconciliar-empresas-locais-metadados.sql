-- Migration: 2026-09-08-reconciliar-empresas-locais-metadados.sql
-- Objetivo:
--   Fase 5.1A.1 — reconciliação MANUAL, explícita e controlada de 3 empresas locais já existentes
--   com seus códigos oficiais do METADADOS (RHMADEPLANT). A prévia de reconciliação
--   (EmpresaMetadadosSyncService::planejar, ver roadmap-tecnico.md) mostrou que essas 3 empresas
--   representam claramente empresas oficiais, mas NÃO foram adotadas automaticamente porque o nome
--   local é abreviado — e a política de adoção automática exige correspondência EXATA de nome
--   normalizado (nunca aproximação). A decisão de vinculá-las foi tomada e aprovada fora do
--   algoritmo: esta migration a aplica sem introduzir fuzzy matching nem regra Madeplant-específica
--   dentro de `planejar()`.
--
--   Mapeamento aprovado (preserva os id locais e todas as FKs que apontam para eles):
--     empresas.id = 2  ('MADEPLANT TRANSPORTES')  -> codigo_empresa = '0005'  ('MADEPLANT TRANSPORTES LTDA')
--     empresas.id = 3  ('MADEPLANT CSC')          -> codigo_empresa = '0007'  ('MADEPLANT CENTRO DE SERV COMPARTILHADOS')
--     empresas.id = 4  ('PROSPECTA SERVICOS')     -> codigo_empresa = '0008'  ('PROSPECTA SERVICOS E TRANSPORTES LTDA')
--
--   Requer a migration 2026-09-04-empresas-unidades-metadados.sql aplicada antes (coluna
--   `codigo_empresa`).
--
--   Garantias (procedure com transação única + EXIT HANDLER que faz ROLLBACK e RESIGNAL):
--     1. aborta se qualquer um dos id 2/3/4 não existir;
--     2. aborta se qualquer um deles já tiver codigo_empresa DIFERENTE do aprovado
--        (se já tiver o código aprovado, é no-op — idempotente);
--     3. aborta se qualquer código 0005/0007/0008 já pertencer a OUTRO id;
--     4. só escreve nas linhas ainda SEM código (idempotência real);
--     5. NÃO altera id, FKs, nome, slug nem ativo;
--     6. NÃO apaga registros.
--
--   `origem_metadados = 'RHMADEPLANT'` é preenchido (declara a origem oficial da linha — é
--   exatamente o que a reconciliação afirma). `sincronizado_em` NÃO é preenchido de propósito:
--   nenhuma sincronização de dados ocorreu ainda (`razao_social` continua NULL até a primeira
--   sincronização real) — carimbar `sincronizado_em` agora seria semanticamente incorreto.
--
--   Após esta migration, `planejar()` para 0005/0007/0008 devolve ATUALIZAR_EXISTENTE (razao_social
--   local ainda NULL, diferente da oficial) — nunca INSERIR_NOVA, nunca ADOTAR_EXISTENTE. A
--   primeira sincronização real preenche `razao_social`/`sincronizado_em`.
--
--   Aplicação: via cliente que respeita DELIMITER (phpMyAdmin/mysql CLI), como as demais migrations
--   com procedure do projeto. NÃO aplicar em produção nesta execução.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_reconciliar_empresas_locais_metadados $$

CREATE PROCEDURE sp_reconciliar_empresas_locais_metadados()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- 1. os 3 registros locais esperados existem
    IF (SELECT COUNT(*) FROM empresas WHERE id IN (2, 3, 4)) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: um dos registros locais esperados (empresas.id 2, 3 ou 4) nao existe.';
    END IF;

    -- 2. nenhum deles ja tem um codigo_empresa DIFERENTE do aprovado (idempotente se ja for o alvo)
    IF EXISTS (
        SELECT 1 FROM empresas
        WHERE (id = 2 AND codigo_empresa IS NOT NULL AND codigo_empresa <> '0005')
           OR (id = 3 AND codigo_empresa IS NOT NULL AND codigo_empresa <> '0007')
           OR (id = 4 AND codigo_empresa IS NOT NULL AND codigo_empresa <> '0008')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: um dos registros locais (id 2/3/4) ja possui codigo_empresa diferente do aprovado.';
    END IF;

    -- 3. nenhum dos codigos-alvo pertence a OUTRO id
    IF EXISTS (
        SELECT 1 FROM empresas
        WHERE (codigo_empresa = '0005' AND id <> 2)
           OR (codigo_empresa = '0007' AND id <> 3)
           OR (codigo_empresa = '0008' AND id <> 4)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: o codigo oficial 0005, 0007 ou 0008 ja esta atribuido a outra empresa.';
    END IF;

    -- 4. aplica SOMENTE nas linhas ainda sem codigo (idempotencia real)
    UPDATE empresas SET codigo_empresa = '0005', origem_metadados = 'RHMADEPLANT'
        WHERE id = 2 AND codigo_empresa IS NULL;
    UPDATE empresas SET codigo_empresa = '0007', origem_metadados = 'RHMADEPLANT'
        WHERE id = 3 AND codigo_empresa IS NULL;
    UPDATE empresas SET codigo_empresa = '0008', origem_metadados = 'RHMADEPLANT'
        WHERE id = 4 AND codigo_empresa IS NULL;

    COMMIT;

    -- estado final dos 3 registros reconciliados (para conferencia)
    SELECT id, codigo_empresa, nome, slug, ativo, razao_social, origem_metadados, sincronizado_em
    FROM empresas WHERE id IN (2, 3, 4) ORDER BY id;
END $$

CALL sp_reconciliar_empresas_locais_metadados() $$

DROP PROCEDURE IF EXISTS sp_reconciliar_empresas_locais_metadados $$

DELIMITER ;
