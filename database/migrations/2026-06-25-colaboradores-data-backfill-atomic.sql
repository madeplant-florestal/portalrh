-- Migration: 2026-06-25-colaboradores-data-backfill-atomic.sql
-- Objetivo:
--   Executar uma atualizacao atomica e auditavel dos dados de colaboradores
--   com foco em normalizacao e backfill deterministico dos campos abaixo:
--   - codigo
--   - cpf
--   - data_inicio_cargo
--   - ativo
--
-- Regras de seguranca aplicadas:
--   1. Usa transacao unica para os updates de dados.
--   2. Aborta se houver orfaos relacionais em empresa, cargo ou setor.
--   3. Aborta se houver data_demissao menor que data_admissao.
--   4. Aborta se o estado final gerar duplicidade de codigo dentro da mesma empresa.
--   5. Registra auditoria old/new por registro alterado.
--   6. Registra falhas em tabela de log de erros.
--   7. Forca utf8mb4_unicode_ci nas comparacoes textuais para evitar
--      conflitos com bases legadas que ainda estejam em utf8mb4_bin
--      ou utf8mb4_general_ci.
--
-- Atualizacoes aplicadas somente quando necessarias:
--   - codigo recebe matricula quando codigo estiver vazio
--   - codigo tecnico e gerado por id quando codigo e matricula estiverem vazios
--   - cpf e normalizado para somente digitos
--   - cpf com comprimento invalido apos normalizacao vira NULL
--   - data_inicio_cargo recebe data_admissao quando estiver NULL
--   - ativo recebe 0 para desligados e 1 para registros sem data_demissao
--
-- Execucao recomendada:
--   1. Rodar primeiro em homologacao.
--   2. Revisar os result sets retornados ao final do CALL.
--   3. Auditar a tabela colaboradores_data_fix_auditoria antes da producao.

CREATE TABLE IF NOT EXISTS colaboradores_data_fix_auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    execution_uuid CHAR(36) NOT NULL,
    colaborador_id INT NOT NULL,
    empresa_id INT NULL,
    cargo_id INT NULL,
    setor_id INT NULL,
    old_codigo VARCHAR(30) NULL,
    new_codigo VARCHAR(30) NULL,
    old_cpf VARCHAR(30) NULL,
    new_cpf VARCHAR(11) NULL,
    old_data_inicio_cargo DATE NULL,
    new_data_inicio_cargo DATE NULL,
    old_ativo TINYINT(1) NOT NULL,
    new_ativo TINYINT(1) NOT NULL,
    update_reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    KEY idx_colaboradores_data_fix_auditoria_exec (execution_uuid),
    KEY idx_colaboradores_data_fix_auditoria_colaborador (colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS colaboradores_data_fix_error_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    execution_uuid CHAR(36) NOT NULL,
    stage_name VARCHAR(80) NOT NULL,
    sqlstate_code VARCHAR(10) NULL,
    mysql_errno INT NULL,
    error_message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_colaboradores_data_fix_error_exec (execution_uuid),
    KEY idx_colaboradores_data_fix_error_stage (stage_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS sp_colaboradores_data_backfill_atomic;

DELIMITER $$

CREATE PROCEDURE sp_colaboradores_data_backfill_atomic()
BEGIN
    DECLARE v_execution_uuid CHAR(36) DEFAULT UUID();
    DECLARE v_stage VARCHAR(80) DEFAULT 'start';
    DECLARE v_rows_to_update INT DEFAULT 0;
    DECLARE v_rows_updated INT DEFAULT 0;
    DECLARE v_has_rows INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 @p_sqlstate = RETURNED_SQLSTATE, @p_errno = MYSQL_ERRNO, @p_message = MESSAGE_TEXT;
        ROLLBACK;

        INSERT INTO colaboradores_data_fix_error_log (
            execution_uuid,
            stage_name,
            sqlstate_code,
            mysql_errno,
            error_message
        ) VALUES (
            v_execution_uuid,
            v_stage,
            @p_sqlstate,
            @p_errno,
            @p_message
        );

        RESIGNAL;
    END;

    SET @colaboradores_data_fix_execution_uuid := v_execution_uuid;

    START TRANSACTION;

    SET v_stage = 'validate_fk_empresa';
    IF EXISTS (
        SELECT 1
        FROM colaboradores c
        LEFT JOIN empresas e ON e.id = c.empresa_id
        WHERE c.empresa_id IS NOT NULL
          AND e.id IS NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existem colaboradores com empresa_id sem correspondencia em empresas.';
    END IF;

    SET v_stage = 'validate_fk_cargo';
    IF EXISTS (
        SELECT 1
        FROM colaboradores c
        LEFT JOIN cargos cg ON cg.id = c.cargo_id
        WHERE c.cargo_id IS NOT NULL
          AND cg.id IS NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existem colaboradores com cargo_id sem correspondencia em cargos.';
    END IF;

    SET v_stage = 'validate_fk_setor';
    IF EXISTS (
        SELECT 1
        FROM colaboradores c
        LEFT JOIN setores s ON s.id = c.setor_id
        WHERE c.setor_id IS NOT NULL
          AND s.id IS NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existem colaboradores com setor_id sem correspondencia em setores.';
    END IF;

    SET v_stage = 'validate_dates';
    IF EXISTS (
        SELECT 1
        FROM colaboradores c
        WHERE c.data_admissao IS NOT NULL
          AND c.data_demissao IS NOT NULL
          AND c.data_demissao < c.data_admissao
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existem colaboradores com data_demissao menor que data_admissao.';
    END IF;

    SET v_stage = 'build_preview';
    DROP TEMPORARY TABLE IF EXISTS tmp_colaboradores_data_fix;
    CREATE TEMPORARY TABLE tmp_colaboradores_data_fix (
        colaborador_id INT NOT NULL PRIMARY KEY,
        empresa_id INT NULL,
        cargo_id INT NULL,
        setor_id INT NULL,
        old_codigo VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        new_codigo VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        old_cpf VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        new_cpf VARCHAR(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        old_data_inicio_cargo DATE NULL,
        new_data_inicio_cargo DATE NULL,
        old_ativo TINYINT(1) NOT NULL,
        new_ativo TINYINT(1) NOT NULL,
        update_reason VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
    );

    INSERT INTO tmp_colaboradores_data_fix (
        colaborador_id,
        empresa_id,
        cargo_id,
        setor_id,
        old_codigo,
        new_codigo,
        old_cpf,
        new_cpf,
        old_data_inicio_cargo,
        new_data_inicio_cargo,
        old_ativo,
        new_ativo,
        update_reason
    )
    SELECT
        c.id AS colaborador_id,
        c.empresa_id,
        c.cargo_id,
        c.setor_id,
        c.codigo AS old_codigo,
        CASE
            WHEN NULLIF(TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                THEN TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            WHEN NULLIF(TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                THEN TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            ELSE CONCAT('COL', LPAD(c.id, 6, '0'))
        END AS new_codigo,
        c.cpf AS old_cpf,
        CASE
            WHEN NULLIF(TRIM(CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NULL THEN NULL
            WHEN CHAR_LENGTH(c.cpf_digits) = 11 THEN c.cpf_digits
            ELSE NULL
        END AS new_cpf,
        c.data_inicio_cargo AS old_data_inicio_cargo,
        CASE
            WHEN c.data_inicio_cargo IS NULL AND c.data_admissao IS NOT NULL THEN c.data_admissao
            ELSE c.data_inicio_cargo
        END AS new_data_inicio_cargo,
        c.ativo AS old_ativo,
        CASE
            WHEN c.data_demissao IS NOT NULL THEN 0
            WHEN c.data_admissao IS NOT NULL THEN 1
            ELSE c.ativo
        END AS new_ativo,
        TRIM(BOTH ';' FROM CONCAT(
            CASE
                WHEN NULLIF(TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NULL
                     AND NULLIF(TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                THEN ';codigo preenchido a partir da matricula'
                WHEN NULLIF(TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NULL
                     AND NULLIF(TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NULL
                THEN ';codigo tecnico gerado a partir do id'
                ELSE ''
            END,
            CASE
                WHEN NULLIF(TRIM(CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                     AND NOT (
                        (CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=>
                        ((CASE
                            WHEN CHAR_LENGTH(c.cpf_digits) = 11 THEN c.cpf_digits
                            ELSE NULL
                        END) COLLATE utf8mb4_unicode_ci)
                     )
                THEN ';cpf normalizado'
                ELSE ''
            END,
            CASE
                WHEN NULLIF(TRIM(CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                     AND CHAR_LENGTH(c.cpf_digits) <> 11
                THEN ';cpf invalido convertido para NULL'
                ELSE ''
            END,
            CASE
                WHEN c.data_inicio_cargo IS NULL AND c.data_admissao IS NOT NULL THEN ';data_inicio_cargo preenchida com data_admissao'
                ELSE ''
            END,
            CASE
                WHEN c.ativo <> CASE
                        WHEN c.data_demissao IS NOT NULL THEN 0
                        WHEN c.data_admissao IS NOT NULL THEN 1
                        ELSE c.ativo
                    END
                THEN ';status ativo alinhado com datas de admissao/demissao'
                ELSE ''
            END
        )) AS update_reason
    FROM (
        SELECT
            base.*,
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(TRIM(COALESCE(base.cpf, '')), '.', ''),
                                '-', ''),
                            '/', ''),
                        ' ', ''),
                    '(', ''),
                ')', ''),
            CHAR(9), '') AS cpf_digits
        FROM colaboradores base
    ) c
    LEFT JOIN empresas e ON e.id = c.empresa_id
    LEFT JOIN cargos cg ON cg.id = c.cargo_id
    LEFT JOIN setores s ON s.id = c.setor_id
    WHERE (
            NOT (
                (CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=> (
                    CASE
                        WHEN NULLIF(TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                            THEN TRIM(CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                        WHEN NULLIF(TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
                            THEN TRIM(CONVERT(c.matricula USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                        ELSE CONCAT('COL', LPAD(c.id, 6, '0'))
                    END COLLATE utf8mb4_unicode_ci
                )
            )
         OR NOT (
                (CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=> (
                    CASE
                        WHEN NULLIF(TRIM(CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NULL THEN NULL
                        WHEN CHAR_LENGTH(c.cpf_digits) = 11 THEN c.cpf_digits
                        ELSE NULL
                    END COLLATE utf8mb4_unicode_ci
                )
            )
         OR NOT (
                c.data_inicio_cargo <=> CASE
                    WHEN c.data_inicio_cargo IS NULL AND c.data_admissao IS NOT NULL THEN c.data_admissao
                    ELSE c.data_inicio_cargo
                END
            )
         OR c.ativo <> CASE
                WHEN c.data_demissao IS NOT NULL THEN 0
                WHEN c.data_admissao IS NOT NULL THEN 1
                ELSE c.ativo
            END
    );

    SET v_has_rows = (SELECT COUNT(*) FROM tmp_colaboradores_data_fix);

    SET v_stage = 'validate_final_company_code_duplicates';
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT
                COALESCE(c.empresa_id, 0) AS company_key,
                COALESCE(t.new_codigo, c.codigo) AS final_codigo,
                COUNT(*) AS total_rows
            FROM colaboradores c
            LEFT JOIN tmp_colaboradores_data_fix t ON t.colaborador_id = c.id
            WHERE NULLIF(TRIM(CONVERT(COALESCE(t.new_codigo, c.codigo) USING utf8mb4) COLLATE utf8mb4_unicode_ci), '') IS NOT NULL
            GROUP BY COALESCE(c.empresa_id, 0), COALESCE(t.new_codigo, c.codigo)
            HAVING COUNT(*) > 1
        ) dup
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A atualizacao proposta gera codigo duplicado dentro da mesma empresa.';
    END IF;

    IF v_has_rows = 0 THEN
        COMMIT;

        SELECT
            v_execution_uuid AS execution_uuid,
            0 AS preview_rows,
            0 AS updated_rows,
            'Nenhum registro elegivel para atualizacao.' AS execution_message;
    ELSE
        SET v_rows_to_update = v_has_rows;

        SET v_stage = 'persist_audit';
        INSERT INTO colaboradores_data_fix_auditoria (
            execution_uuid,
            colaborador_id,
            empresa_id,
            cargo_id,
            setor_id,
            old_codigo,
            new_codigo,
            old_cpf,
            new_cpf,
            old_data_inicio_cargo,
            new_data_inicio_cargo,
            old_ativo,
            new_ativo,
            update_reason
        )
        SELECT
            v_execution_uuid,
            colaborador_id,
            empresa_id,
            cargo_id,
            setor_id,
            old_codigo,
            new_codigo,
            old_cpf,
            new_cpf,
            old_data_inicio_cargo,
            new_data_inicio_cargo,
            old_ativo,
            new_ativo,
            update_reason
        FROM tmp_colaboradores_data_fix;

        SET v_stage = 'apply_updates';
        UPDATE colaboradores c
        INNER JOIN tmp_colaboradores_data_fix t ON t.colaborador_id = c.id
        LEFT JOIN empresas e ON e.id = c.empresa_id
        LEFT JOIN cargos cg ON cg.id = c.cargo_id
        LEFT JOIN setores s ON s.id = c.setor_id
        SET
            c.codigo = t.new_codigo,
            c.cpf = t.new_cpf,
            c.data_inicio_cargo = t.new_data_inicio_cargo,
            c.ativo = t.new_ativo
        WHERE (c.empresa_id IS NULL OR e.id IS NOT NULL)
          AND (c.cargo_id IS NULL OR cg.id IS NOT NULL)
          AND (c.setor_id IS NULL OR s.id IS NOT NULL)
          AND (
                NOT (
                    (CONVERT(c.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=>
                    (CONVERT(t.new_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                )
             OR NOT (
                    (CONVERT(c.cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=>
                    (CONVERT(t.new_cpf USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                )
             OR NOT (c.data_inicio_cargo <=> t.new_data_inicio_cargo)
             OR c.ativo <> t.new_ativo
          );

        SET v_rows_updated = ROW_COUNT();

        SET v_stage = 'mark_audit_applied';
        UPDATE colaboradores_data_fix_auditoria
        SET applied_at = NOW()
        WHERE execution_uuid = v_execution_uuid;

        COMMIT;

        SELECT
            v_execution_uuid AS execution_uuid,
            v_rows_to_update AS preview_rows,
            v_rows_updated AS updated_rows,
            'Atualizacao concluida com sucesso.' AS execution_message;

        SELECT
            a.execution_uuid,
            a.colaborador_id,
            a.empresa_id,
            a.cargo_id,
            a.setor_id,
            a.old_codigo,
            a.new_codigo,
            a.old_cpf,
            a.new_cpf,
            a.old_data_inicio_cargo,
            a.new_data_inicio_cargo,
            a.old_ativo,
            a.new_ativo,
            a.update_reason,
            a.created_at,
            a.applied_at
        FROM colaboradores_data_fix_auditoria a
        WHERE a.execution_uuid = v_execution_uuid
        ORDER BY a.colaborador_id ASC;
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_colaboradores_data_fix;
END $$

DELIMITER ;

CALL sp_colaboradores_data_backfill_atomic();

DROP PROCEDURE IF EXISTS sp_colaboradores_data_backfill_atomic;

-- Consultas recomendadas apos o CALL:
-- 1. Resumo do ultimo lote:
--    SELECT *
--    FROM colaboradores_data_fix_auditoria
--    WHERE execution_uuid = @colaboradores_data_fix_execution_uuid
--    ORDER BY colaborador_id;
--
-- 2. Erros capturados:
--    SELECT *
--    FROM colaboradores_data_fix_error_log
--    WHERE execution_uuid = @colaboradores_data_fix_execution_uuid
--    ORDER BY id DESC;
--
-- 3. Conferencia de integridade pos-update:
--    SELECT COUNT(*) AS invalid_date_rows
--    FROM colaboradores
--    WHERE data_admissao IS NOT NULL
--      AND data_demissao IS NOT NULL
--      AND data_demissao < data_admissao;
--
--    SELECT empresa_id, codigo, COUNT(*) AS total_rows
--    FROM colaboradores
--    WHERE codigo IS NOT NULL
--      AND TRIM(codigo) <> ''
--    GROUP BY empresa_id, codigo
--    HAVING COUNT(*) > 1;
