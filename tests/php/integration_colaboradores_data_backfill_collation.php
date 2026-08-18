<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();

$stmt = $pdo->query(
    "SELECT COLUMN_NAME, COLLATION_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'colaboradores'
       AND COLUMN_NAME IN ('codigo', 'matricula', 'cpf')
     ORDER BY COLUMN_NAME"
);
$collations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

foreach (['codigo', 'matricula', 'cpf'] as $column) {
    if (!isset($collations[$column])) {
        throw new RuntimeException("Coluna {$column} nao encontrada em colaboradores.");
    }
}

$reproduced1267 = false;
try {
    $pdo->query(
        "SELECT CASE
            WHEN sample.bin_value <> ('abc' COLLATE utf8mb4_unicode_ci) THEN 1
            ELSE 0
         END AS mismatch_flag
         FROM (
            SELECT CAST('abc' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS bin_value
         ) sample"
    );
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '1267') !== false) {
        $reproduced1267 = true;
    } else {
        throw $e;
    }
}

$fixedStmt = $pdo->query(
    "SELECT CASE
        WHEN NOT (
            (CONVERT(sample.bin_value USING utf8mb4) COLLATE utf8mb4_unicode_ci) <=>
            ('abc' COLLATE utf8mb4_unicode_ci)
        ) THEN 1
        ELSE 0
     END AS mismatch_flag
     FROM (
        SELECT CAST('abc' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS bin_value
     ) sample"
);
$fixedResult = $fixedStmt->fetch(PDO::FETCH_ASSOC);
if (!isset($fixedResult['mismatch_flag']) || (int)$fixedResult['mismatch_flag'] !== 0) {
    throw new RuntimeException('A comparacao corrigida por COLLATE nao retornou o resultado esperado.');
}

$actualQuery = "
    SELECT COUNT(*) AS total_candidates
    FROM (
        SELECT c.id
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
        )
        LIMIT 10
    ) candidates";

$actualStmt = $pdo->query($actualQuery);
$actualResult = $actualStmt->fetch(PDO::FETCH_ASSOC);
if (!isset($actualResult['total_candidates'])) {
    throw new RuntimeException('A consulta corrigida nao retornou resultado na base real.');
}

echo 'COLLATION_CHECK_COLUMNS=' . json_encode($collations, JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'COLLATION_1267_REPRODUCED=' . ($reproduced1267 ? 'yes' : 'no') . PHP_EOL;
echo 'COLLATION_FIXED_QUERY_OK=' . $actualResult['total_candidates'] . PHP_EOL;
