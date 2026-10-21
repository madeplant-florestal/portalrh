<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Diagnóstico SOMENTE LEITURA da CAMADA A — o SQL Server oficial RHMADEPLANT.
 *
 * Executa APENAS SELECT (e a query canônica do sincronizador via
 * MetadadosSyncService::fetchSourceRows()). NENHUM INSERT/UPDATE/DELETE/MERGE/DDL/procedure.
 * NÃO expõe CPF nem nome — só contagens e chaves técnicas (EMPRESA/UNIDADE/CONTRATO são códigos).
 *
 * Roda DENTRO da rede Madeplant (mesma máquina que executa scripts/sync_metadados_*.php), com
 * pdo_sqlsrv e rota até o SQL Server. Fonte: o `Database=` do DSN em app/config/local.php — deve
 * ser RHMADEPLANT, nunca RHTESTE (o script recusa se não for RHMADEPLANT, a menos que
 * --permitir-outra-origem seja passado).
 *
 * Além das métricas, grava um arquivo de CHAVES (sem PII) em storage/imports/ para a comparação
 * A×B feita por scripts/diagnostico_colaboradores_mysql.php --fonte-chaves=<arquivo>.
 *
 * Uso (PHP do Laragon no ambiente interno):
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts\diagnostico_colaboradores_rhmadeplant.php
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts\diagnostico_colaboradores_rhmadeplant.php --nome=FABIANE
 */

function consultaSql(PDO $pdo, string $sql): array
{
    try {
        return ['ok' => true, 'v' => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'erro' => $e->getMessage(), 'sql' => trim(preg_replace('/\s+/', ' ', $sql))];
    }
}

$opts = getopt('', ['nome:', 'permitir-outra-origem']);

try {
    $pdo = MetadadosDatabase::conn();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Nao foi possivel conectar ao METADADOS: ' . $e->getMessage() . PHP_EOL
        . 'Rode este script na maquina interna, com o PHP do Laragon (pdo_sqlsrv + rota ao SQL Server).' . PHP_EOL);
    exit(1);
}

$origem = MetadadosDatabase::sourceLabel();
if (strcasecmp($origem, 'RHMADEPLANT') !== 0 && !isset($opts['permitir-outra-origem'])) {
    fwrite(STDERR, "A origem ativa e '{$origem}', nao 'RHMADEPLANT'. Ajuste app/config/local.php ou use --permitir-outra-origem." . PHP_EOL);
    exit(1);
}

$rel = [
    'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'origem' => $origem,
    'observacao' => 'Somente leitura. Sem PII.',
];

// ===================== CAMADA A — RHCONTRATOS / RHPESSOAS =====================
$rel['A_contratos'] = consultaSql($pdo, "
    SELECT
        COUNT(*)                                                    AS total_contratos,
        SUM(CASE WHEN ctr.DATARESCISAO IS NULL THEN 1 ELSE 0 END)   AS ativos,
        SUM(CASE WHEN ctr.DATARESCISAO IS NOT NULL THEN 1 ELSE 0 END) AS desligados,
        COUNT(DISTINCT ctr.EMPRESA)                                 AS empresas,
        COUNT(DISTINCT (CONVERT(varchar(20), ctr.EMPRESA) + '-' + CONVERT(varchar(20), ctr.UNIDADE))) AS unidades,
        COUNT(DISTINCT NULLIF(LTRIM(RTRIM(CONVERT(varchar(40), ctr.SETOR))), '')) AS setores_utilizados,
        COUNT(DISTINCT NULLIF(LTRIM(RTRIM(CONVERT(varchar(40), ctr.CARGO))), '')) AS cargos_utilizados,
        COUNT(DISTINCT (CONVERT(varchar(20), ctr.EMPRESA) + '-' + CONVERT(varchar(20), ctr.PESSOA))) AS pessoas_distintas,
        CONVERT(char(10), MAX(ctr.DATAADMISSAO), 23)                AS admissao_mais_recente,
        CONVERT(char(10), MAX(ctr.DATARESCISAO), 23)                AS demissao_mais_recente
    FROM RHCONTRATOS ctr
");
$rel['A_cpf'] = consultaSql($pdo, "
    SELECT
        COUNT(DISTINCT pes.CPF)                                                      AS cpfs_distintos,
        SUM(CASE WHEN pes.CPF IS NULL OR LTRIM(RTRIM(pes.CPF)) = '' THEN 1 ELSE 0 END) AS contratos_sem_cpf
    FROM RHCONTRATOS ctr
    LEFT JOIN RHPESSOAS pes ON pes.EMPRESA = ctr.EMPRESA AND pes.PESSOA = ctr.PESSOA
");
$rel['A_chave_duplicada'] = consultaSql($pdo, "
    SELECT COUNT(*) AS chaves_empresa_unidade_contrato_duplicadas FROM (
        SELECT ctr.EMPRESA, ctr.UNIDADE, ctr.CONTRATO
        FROM RHCONTRATOS ctr GROUP BY ctr.EMPRESA, ctr.UNIDADE, ctr.CONTRATO HAVING COUNT(*) > 1
    ) x
");

// ===================== Query CANÔNICA do sincronizador =====================
try {
    $rows = (new MetadadosSyncService())->fetchSourceRows();
    $rel['A_query_canonica'] = [
        'linhas_retornadas' => count($rows),
        'ativos' => count(array_filter($rows, static fn ($r) => (int)($r['ativo'] ?? 0) === 1)),
        'desligados' => count(array_filter($rows, static fn ($r) => (int)($r['ativo'] ?? 1) === 0)),
        'observacao' => 'Estas linhas sao exatamente o que a sincronizacao envia para colaboradores_metadados. '
            . 'Se linhas_retornadas < total_contratos, a query canonica (INNER JOIN RHPESSOAS/RHEMPRESAS) esta omitindo contratos.',
    ];

    // --- arquivo de CHAVES para a comparacao A×B (codigos, sem PII) ---
    $chaves = [];
    foreach ($rows as $r) {
        $chaves[] = [
            'e' => (string)($r['codigo_empresa'] ?? ''),
            'u' => (string)($r['codigo_unidade'] ?? ''),
            'c' => (string)($r['numero_contrato'] ?? ''),
            'a' => array_key_exists('ativo', $r) && $r['ativo'] !== null ? (int)$r['ativo'] : -1,
        ];
    }
    $arqChaves = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
        . 'diag-rhmadeplant-chaves-' . date('Ymd-His') . '.json';
    @mkdir(dirname($arqChaves), 0775, true);
    @file_put_contents($arqChaves, json_encode([
        'gerado_em' => $rel['gerado_em'],
        'origem' => $origem,
        'total' => count($chaves),
        'chaves' => $chaves,
    ], JSON_UNESCAPED_SLASHES));
    $rel['A_arquivo_chaves'] = $arqChaves;
} catch (\Throwable $e) {
    $rel['A_query_canonica'] = ['erro' => $e->getMessage()];
}

// ===================== FABIANE (ou --nome) — SEM PII =====================
if (isset($opts['nome']) && trim((string)$opts['nome']) !== '') {
    $termo = trim((string)$opts['nome']);
    try {
        $st = $pdo->prepare("
            SELECT
                (CONVERT(varchar(20), ctr.EMPRESA) + '-' + CONVERT(varchar(20), ctr.UNIDADE) + '-' + CONVERT(varchar(20), ctr.CONTRATO)) AS chave,
                CASE WHEN ctr.DATARESCISAO IS NULL THEN 1 ELSE 0 END AS ativo,
                CASE WHEN pes.CPF IS NULL OR LTRIM(RTRIM(pes.CPF)) = '' THEN 0 ELSE 1 END AS tem_cpf
            FROM RHPESSOAS pes
            INNER JOIN RHCONTRATOS ctr ON ctr.EMPRESA = pes.EMPRESA AND ctr.PESSOA = pes.PESSOA
            WHERE pes.NOME LIKE ?
        ");
        $st->execute(['%' . $termo . '%']);
        $oc = $st->fetchAll(PDO::FETCH_ASSOC);
        $rel['pessoa_diagnostico'] = [
            'termo' => $termo,
            'encontrada_no_rhmadeplant' => count($oc) > 0,
            'ocorrencias' => array_map(static fn ($r) => [
                'chave' => $r['chave'],
                'situacao' => (int)$r['ativo'] === 1 ? 'ativo' : 'desligado',
                'tem_cpf' => (int)$r['tem_cpf'] === 1,
            ], $oc),
        ];
    } catch (\Throwable $e) {
        $rel['pessoa_diagnostico'] = ['termo' => $termo, 'erro' => $e->getMessage()];
    }
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$destino = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . 'diag-rhmadeplant-' . date('Ymd-His') . '.json';
@mkdir(dirname($destino), 0775, true);
@file_put_contents($destino, $json);
echo $json . PHP_EOL;
fwrite(STDERR, PHP_EOL . 'Relatorio salvo em: ' . $destino . PHP_EOL);
