-- Migration: 2026-09-10-reconciliar-cargos-locais-metadados.sql
-- Objetivo:
--   Fase 5.2 — reconciliação MANUAL, explícita e controlada de cargos locais já existentes com
--   seus códigos oficiais do METADADOS (RHCARGOS). Mesmo desenho da migration de Setores
--   (2026-09-08-reconciliar-setores-locais-metadados.sql): decisão tomada FORA do algoritmo
--   genérico de `CatalogoMetadadosSyncService::planejar()`, sem introduzir fuzzy matching.
--
--   Dry-run real de Cargos (planejar() contra RHCARGOS, 10/09/2026): 184 oficiais, 60 locais —
--   37 ADOTAR_EXISTENTE (nome local idêntico à descrição oficial normalizada), 147 INSERIR_NOVA,
--   23 LOCAL_SEM_CORRESPONDENCIA. Dos 23, a análise cargo-a-cargo concluiu:
--     - 5 não existem no METADADOS (CONTROLLER, DIRETOR GERAL, GERENTE DE MANUTENCAO,
--       GERENTE DE OPERACAO, GERENTE GERAL) -> permanecem legados sem codigo_cargo;
--     - 16 só existem no oficial COM nível de carreira (J I / P II / etc.) -> decisão do RH,
--       permanecem legados sem codigo_cargo por ora;
--     - 1 borderline de gênero (ENCARREGADA FISCAL) -> permanece legado (há candidato com nível);
--     - 1 reconciliado aqui:
--         cargos.id = 24 ('CONTADOR') -> codigo_cargo = '047' ('CONTADOR  ( A )')
--       '( A )' é apenas a forma inclusiva CONTADOR(A), não um nível/especialização distinta.
--
--   Preserva o id local 24 e TODAS as FKs que apontam para ele (colaboradores.cargo_id,
--   solicitacoes_vaga.cargo_id, movimentacoes_pessoal.cargo_atual_id/novo_cargo_id,
--   cargo_setores, cargo_beneficios, cargo_faixas_salariais).
--
--   Requer 2026-09-08-setores-cargos-metadados.sql aplicada antes (coluna `codigo_cargo`).
--
--   Códigos são STRING OPACA — '047' é gravado exatamente como o METADADOS entrega, sem conversão
--   numérica, sem remoção de zero à esquerda.
--
--   `origem_metadados = 'RHMADEPLANT'` é preenchido (declara a origem oficial da linha).
--   `descricao_oficial` e `sincronizado_em` NÃO são preenchidos de propósito — nenhuma
--   sincronização de dados ocorreu ainda; são responsabilidade da 1ª sincronização real
--   (`planejar()` para '047' devolverá ATUALIZAR_EXISTENTE, nunca ADOTAR/INSERIR).
--
--   Idempotente (procedure com transação única + EXIT HANDLER que faz ROLLBACK e RESIGNAL):
--     1. aborta se cargos.id = 24 não existir;
--     2. aborta se id 24 já tiver codigo_cargo DIFERENTE de '047' (se já for '047', no-op);
--     3. aborta se '047' já pertencer a OUTRO id;
--     4. só escreve na linha ainda SEM código;
--     5. NÃO altera id, nome, slug, ativo nem nenhuma FK; NÃO apaga registros.
--
--   Compatível com MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção) — só DML + procedure padrão
--   (SIGNAL/HANDLER/START TRANSACTION), sem recurso exclusivo de um dos dois.
--
--   Aplicação: via cliente que respeita DELIMITER (phpMyAdmin / mysql CLI), como as demais
--   migrations com procedure do projeto. NÃO aplicar em produção nesta execução.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_reconciliar_cargos_locais_metadados $$

CREATE PROCEDURE sp_reconciliar_cargos_locais_metadados()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- 1. o registro local esperado existe
    IF (SELECT COUNT(*) FROM cargos WHERE id = 24) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: cargos.id = 24 (CONTADOR) nao existe.';
    END IF;

    -- 2. id 24 nao tem um codigo_cargo DIFERENTE do aprovado (idempotente se ja for '047')
    IF EXISTS (
        SELECT 1 FROM cargos
        WHERE id = 24 AND codigo_cargo IS NOT NULL AND codigo_cargo <> '047'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: cargos.id = 24 ja possui codigo_cargo diferente de 047.';
    END IF;

    -- 3. '047' nao pertence a OUTRO id
    IF EXISTS (SELECT 1 FROM cargos WHERE codigo_cargo = '047' AND id <> 24) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Reconciliacao abortada: o codigo oficial 047 ja esta atribuido a outro cargo.';
    END IF;

    -- 4. aplica SOMENTE se ainda sem codigo (idempotencia real)
    UPDATE cargos
       SET codigo_cargo = '047', origem_metadados = 'RHMADEPLANT'
     WHERE id = 24 AND codigo_cargo IS NULL;

    COMMIT;

    -- estado final do registro reconciliado (para conferencia)
    SELECT id, codigo_cargo, nome, slug, ativo, descricao_oficial, situacao_metadados,
           origem_metadados, sincronizado_em
      FROM cargos WHERE id = 24;
END $$

CALL sp_reconciliar_cargos_locais_metadados() $$

DROP PROCEDURE IF EXISTS sp_reconciliar_cargos_locais_metadados $$

DELIMITER ;
