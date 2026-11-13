-- SOMENTE LEITURA. Diagnóstico de fuso horário (Frente D). Rode no MariaDB de produção e envie o resultado.
-- Objetivo: descobrir em QUE fuso cada família de timestamp foi gravada, sem alterar nada.
--  * TIMESTAMP + DEFAULT CURRENT_TIMESTAMP  -> instante correto, exibido no fuso da sessão (hoje UTC).
--  * DATETIME gravado por NOW()/CURDATE() no SQL -> fuso da sessão do banco (UTC).
--  * DATETIME gravado por PHP (date()/DateTimeImmutable) -> fuso do PHP de produção (desconhecido; o default do PHP é Europe/Berlin).

SELECT @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, @@system_time_zone AS system_tz, NOW() AS agora_db, UTC_TIMESTAMP() AS agora_utc;

-- 1) Cartas: created_at (TIMESTAMP, relógio do banco) x gerada_em/enviada_em (DATETIME, gravados pelo PHP).
--    A diferença em horas, em linhas recém-criadas, revela o fuso do PHP de produção (ex.: +1 = Berlin no inverno, 0 = UTC, -4 = Campo Grande).
SELECT id, created_at, gerada_em, enviada_em, decisao_em, token_expira_em,
       TIMESTAMPDIFF(MINUTE, created_at, gerada_em) / 60 AS horas_gerada_menos_created,
       TIMESTAMPDIFF(MINUTE, created_at, enviada_em) / 60 AS horas_enviada_menos_created
FROM cartas_propostas ORDER BY id DESC LIMIT 15;

-- 2) Exames admissionais: mesma comparação.
SELECT id, created_at, mensagem_preparada_em, enviado_em,
       TIMESTAMPDIFF(MINUTE, created_at, mensagem_preparada_em) / 60 AS horas_preparada_menos_created
FROM exames_admissionais ORDER BY id DESC LIMIT 15;

-- 3) Vagas: publicada_em (DATETIME via NOW()) e created_at (TIMESTAMP) — só para conferir a ordem de grandeza (a publicação ocorre depois da criação).
SELECT id, created_at, publicada_em, TIMESTAMPDIFF(MINUTE, created_at, publicada_em) / 60 AS horas_diff
FROM vagas WHERE publicada_em IS NOT NULL ORDER BY id DESC LIMIT 10;

-- ATENÇÃO: as consultas 4 usam CONVERT_TZ('+00:00' -> '-04:00') e só fazem sentido onde a sessão do banco está em UTC (produção).
--    No ambiente de desenvolvimento (Windows, fuso Campo Grande) o banco já grava em hora local e a conversão seria dupla.
-- 4) Últimas candidaturas e movimentações (TIMESTAMP): hora gravada em UTC; a hora local de Campo Grande = created_at - 4h.
SELECT c.id, c.created_at AS utc_gravado, CONVERT_TZ(c.created_at, '+00:00', '-04:00') AS campo_grande
FROM candidaturas c ORDER BY c.id DESC LIMIT 10;

SELECT pm.id, pm.candidatura_id, pm.created_at AS utc_gravado, CONVERT_TZ(pm.created_at, '+00:00', '-04:00') AS campo_grande
FROM pipeline_movements pm ORDER BY pm.id DESC LIMIT 10;
