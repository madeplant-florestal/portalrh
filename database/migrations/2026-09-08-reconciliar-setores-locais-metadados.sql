-- Migration: 2026-09-08-reconciliar-setores-locais-metadados.sql
-- Objetivo:
--   Fase 5.2.1 — reconciliação MANUAL, explícita e controlada de 3 setores locais já existentes
--   com seus códigos oficiais do METADADOS (RHMADEPLANT). O dry-run real de Setores
--   (CatalogoMetadadosSyncService::planejar contra RHSETORES, ver roadmap-tecnico.md) mostrou 12
--   setores oficiais: 9 ADOTAR_EXISTENTE (nome local idêntico à descrição oficial) e 3
--   INSERIR_NOVA. Desses 3, três têm equivalente local claro cujo nome é abreviado/diferente — e
--   a política de adoção automática exige correspondência EXATA de nome normalizado (nunca
--   aproximação, nunca fuzzy). A decisão de vinculá-los foi tomada e aprovada FORA do algoritmo:
--   esta migration a aplica sem introduzir fuzzy matching nem regra Madeplant-específica dentro de
--   `planejar()`.
--
--   Mapeamento aprovado (preserva os id locais e todas as FKs que apontam para eles —
--   colaboradores, movimentacoes_pessoal, solicitacoes_vaga, cargo_setores, centros_custo):
--     setores.id = 10 ('RH/DP/SST') -> codigo_setor = '1' ('RECURSOS HUMANOS')
--     setores.id = 7  ('LOGISTICA') -> codigo_setor = '6' ('LOGISTICA E TRANSPORTES')
--     setores.id = 12 ('TI')        -> codigo_setor = '9' ('TECNOLOGIA DA INFORMACAO')
--
--   Requer a migration 2026-09-08-setores-cargos-metadados.sql aplicada antes (coluna
--   `codigo_setor`).
--
--   Garantias (procedure com transação única + EXIT HANDLER que faz ROLLBACK e RESIGNAL):
--     1. aborta se qualquer um dos id 10/7/12 não existir;
--     2. aborta se qualquer um deles já tiver codigo_setor DIFERENTE do aprovado
--        (se já tiver o código aprovado, é no-op — idempotente);
--     3. aborta se qualquer código '1'/'6'/'9' já pertencer a OUTRO id;
--     4. só escreve nas linhas ainda SEM código (idempotência real);
--     5. NÃO altera id, nome, slug, empresa_id, ativo nem nenhuma FK;
--     6. NÃO apaga registros.
--
--   `origem_metadados = 'RHMADEPLANT'` é preenchido (declara a origem oficial da linha — é
--   exatamente o que a reconciliação afirma). `sincronizado_em` e `descricao_oficial` NÃO são
--   preenchidos de propósito: nenhuma sincronização de dados ocorreu ainda — a descrição oficial
--   e o carimbo de sincronização são responsabilidade da primeira sincronização real.
--
--   Após esta migration, `planejar()` para '1'/'6'/'9' devolve ATUALIZAR_EXISTENTE
--   (descricao_oficial local ainda NULL, diferente da oficial) — nunca INSERIR_NOVA, nunca
--   ADOTAR_EXISTENTE. Os 4 setores locais legados que continuam sem código (PRODUCAO,
--   ADMINISTRATIVO, MANUTENCAO PROSPECTA, ADMINISTRATIVO PROSPECTA) permanecem como
--   LOCAL_SEM_CORRESPONDENCIA_OFICIAL — esperado, serão tratados quando as dependências migrarem
--   para os catálogos oficiais.
--
--   Aplicação: via cliente que respeita DELIMITER (phpMyAdmin/mysql CLI), como as demais migrations
--   com procedure do projeto.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — aplicada em 10/09/2026, depois de
--   2026-09-08-setores-cargos-metadados.sql. Nenhuma instrução abaixo foi alterada depois da aplicação.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_reconciliar_setores_locais_metadados $$

CREATE PROCEDURE sp_reconciliar_setores_locais_metadados()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- 1. os 3 registros locais esperados existem
    IF (SELECT COUNT(*) FROM setores WHERE id IN (10, 7, 12)) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: um dos registros locais esperados (setores.id 10, 7 ou 12) nao existe.';
    END IF;

    -- 2. nenhum deles ja tem um codigo_setor DIFERENTE do aprovado (idempotente se ja for o alvo)
    IF EXISTS (
        SELECT 1 FROM setores
        WHERE (id = 10 AND codigo_setor IS NOT NULL AND codigo_setor <> '1')
           OR (id = 7  AND codigo_setor IS NOT NULL AND codigo_setor <> '6')
           OR (id = 12 AND codigo_setor IS NOT NULL AND codigo_setor <> '9')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: um dos registros locais (id 10/7/12) ja possui codigo_setor diferente do aprovado.';
    END IF;

    -- 3. nenhum dos codigos-alvo pertence a OUTRO id
    IF EXISTS (
        SELECT 1 FROM setores
        WHERE (codigo_setor = '1' AND id <> 10)
           OR (codigo_setor = '6' AND id <> 7)
           OR (codigo_setor = '9' AND id <> 12)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: o codigo oficial 1, 6 ou 9 ja esta atribuido a outro setor.';
    END IF;

    -- 4. aplica SOMENTE nas linhas ainda sem codigo (idempotencia real)
    UPDATE setores SET codigo_setor = '1', origem_metadados = 'RHMADEPLANT'
        WHERE id = 10 AND codigo_setor IS NULL;
    UPDATE setores SET codigo_setor = '6', origem_metadados = 'RHMADEPLANT'
        WHERE id = 7 AND codigo_setor IS NULL;
    UPDATE setores SET codigo_setor = '9', origem_metadados = 'RHMADEPLANT'
        WHERE id = 12 AND codigo_setor IS NULL;

    COMMIT;

    -- estado final dos 3 registros reconciliados (para conferencia)
    SELECT id, codigo_setor, nome, slug, empresa_id, ativo, descricao_oficial, situacao_metadados,
           origem_metadados, sincronizado_em
    FROM setores WHERE id IN (10, 7, 12) ORDER BY id;
END $$

CALL sp_reconciliar_setores_locais_metadados() $$

DROP PROCEDURE IF EXISTS sp_reconciliar_setores_locais_metadados $$

DELIMITER ;
