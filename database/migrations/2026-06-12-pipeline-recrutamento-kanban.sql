-- Migration: padroniza o Kanban de recrutamento e selecao
-- Objetivo:
-- 1. Alinhar pipeline_stages ao fluxo canonico de R&S
-- 2. Preservar compatibilidade com ambientes que ainda usam nomes legados
-- 3. Nao remover estagios extras eventualmente existentes

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_pipeline_recrutamento_kanban $$
CREATE PROCEDURE sp_ensure_pipeline_recrutamento_kanban()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pipeline_stages'
    ) THEN
        UPDATE pipeline_stages
           SET nome = 'Nova Inscrição', ordem = 1, cor = '#3b82f6'
         WHERE LOWER(nome) IN ('novo', 'nova inscrição', 'nova inscricao');

        UPDATE pipeline_stages
           SET nome = 'Triagem RH', ordem = 2, cor = '#f59e0b'
         WHERE LOWER(nome) IN ('triagem', 'triagem rh');

        UPDATE pipeline_stages
           SET nome = 'Entrevista RH', ordem = 3, cor = '#8b5cf6'
         WHERE LOWER(nome) IN ('entrevista', 'entrevista rh');

        UPDATE pipeline_stages
           SET nome = 'Aprovado', ordem = 6, cor = '#1d4ed8'
         WHERE LOWER(nome) IN ('proposta', 'aprovado');

        UPDATE pipeline_stages
           SET nome = 'Admissão', ordem = 7, cor = '#059669'
         WHERE LOWER(nome) IN ('contratado', 'admissão', 'admissao');

        UPDATE pipeline_stages
           SET nome = 'Reprovado', ordem = 9, cor = '#ef4444'
         WHERE LOWER(nome) IN ('rejeitado', 'reprovado');

        IF NOT EXISTS (SELECT 1 FROM pipeline_stages WHERE LOWER(nome) = 'entrevista gestor') THEN
            INSERT INTO pipeline_stages (nome, ordem, cor) VALUES ('Entrevista Gestor', 4, '#6366f1');
        ELSE
            UPDATE pipeline_stages SET ordem = 4, cor = '#6366f1' WHERE LOWER(nome) = 'entrevista gestor';
        END IF;

        IF NOT EXISTS (SELECT 1 FROM pipeline_stages WHERE LOWER(nome) = 'testes') THEN
            INSERT INTO pipeline_stages (nome, ordem, cor) VALUES ('Testes', 5, '#0ea5e9');
        ELSE
            UPDATE pipeline_stages SET ordem = 5, cor = '#0ea5e9' WHERE LOWER(nome) = 'testes';
        END IF;

        IF NOT EXISTS (SELECT 1 FROM pipeline_stages WHERE LOWER(nome) = 'banco de talentos') THEN
            INSERT INTO pipeline_stages (nome, ordem, cor) VALUES ('Banco de Talentos', 8, '#7c3aed');
        ELSE
            UPDATE pipeline_stages SET ordem = 8, cor = '#7c3aed' WHERE LOWER(nome) = 'banco de talentos';
        END IF;
    END IF;
END $$

CALL sp_ensure_pipeline_recrutamento_kanban() $$
DROP PROCEDURE IF EXISTS sp_ensure_pipeline_recrutamento_kanban $$

DELIMITER ;
