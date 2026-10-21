<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Diagnóstico SOMENTE LEITURA das camadas MySQL da sincronização de colaboradores:
 *   B  — colaboradores_metadados (espelho do METADADOS)
 *   C  — colaboradores (extensão/roster local legado, fonte da tela /admin/colaboradores)
 *   +  — histórico metadados_sync_execucoes
 *   +  — comparação A×B se receber o arquivo de chaves do RHMADEPLANT (ver
 *        scripts/diagnostico_colaboradores_rhmadeplant.php --dump-chaves)
 *
 * NÃO escreve nada. NÃO expõe CPF/nome/salário/dados bancários — só contagens e chaves técnicas
 * `(codigo_empresa, codigo_unidade, numero_contrato)` (que são códigos, não PII).
 *
 * Rodar no ambiente onde o MySQL do Portal é acessível (produção via cPanel, ou o MySQL usado
 * para o diagnóstico). Ver README de uso no relatório.
 *
 * Uso:
 *   php scripts/diagnostico_colaboradores_mysql.php
 *   php scripts/diagnostico_colaboradores_mysql.php --nome=FABIANE
 *   php scripts/diagnostico_colaboradores_mysql.php --fonte-chaves=storage/imports/diag-rhmadeplant-chaves-XXXX.json
 */

function um(PDO $pdo, string $sql): array
{
    try {
        return ['ok' => true, 'v' => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}

$opts = getopt('', ['nome:', 'fonte-chaves:']);
$pdo = Database::conn();
$rel = [
    'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'observacao' => 'Somente leitura. Sem PII.',
    'banco' => (string)(Config::get()['database']['dsn'] ?? ''),
];

// ===================== CAMADA B — colaboradores_metadados =====================
$rel['B_colaboradores_metadados'] = um($pdo, "
    SELECT
        COUNT(*)                                                                   AS total,
        SUM(ativo = 1)                                                             AS ativos,
        SUM(ativo = 0)                                                             AS desligados,
        SUM(ativo IS NULL)                                                         AS ativo_nulo,
        COUNT(DISTINCT cpf)                                                        AS cpfs_distintos,
        SUM(cpf IS NULL OR cpf = '')                                               AS sem_cpf,
        COUNT(DISTINCT codigo_pessoa)                                              AS pessoas_distintas,
        COUNT(DISTINCT CONCAT(codigo_empresa,'|',codigo_unidade,'|',numero_contrato)) AS chaves_oficiais_distintas,
        COUNT(DISTINCT codigo_empresa)                                             AS empresas,
        COUNT(DISTINCT CONCAT(codigo_empresa,'|',codigo_unidade))                  AS unidades,
        COUNT(DISTINCT codigo_setor)                                               AS setores_codigo,
        COUNT(DISTINCT codigo_cargo)                                               AS cargos_codigo,
        COUNT(DISTINCT setor)                                                      AS setores_texto,
        COUNT(DISTINCT cargo)                                                      AS cargos_texto,
        MIN(sincronizado_em)                                                       AS sincronizado_em_min,
        MAX(sincronizado_em)                                                       AS sincronizado_em_max,
        SUM(numero_contrato IS NULL OR numero_contrato = '')                       AS sem_numero_contrato
    FROM colaboradores_metadados
");
$rel['B_origem'] = um($pdo, "SELECT origem_metadados, COUNT(*) AS c FROM colaboradores_metadados GROUP BY origem_metadados");
$rel['B_origem_lista'] = (function () use ($pdo) {
    try {
        return $pdo->query("SELECT origem_metadados AS origem, COUNT(*) AS c FROM colaboradores_metadados GROUP BY origem_metadados ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['erro' => $e->getMessage()];
    }
})();
$rel['B_chave_duplicada'] = um($pdo, "
    SELECT COUNT(*) AS chaves_com_duplicidade FROM (
        SELECT codigo_empresa, codigo_unidade, numero_contrato
        FROM colaboradores_metadados GROUP BY 1,2,3 HAVING COUNT(*) > 1
    ) x
");
$rel['B_origem_diferente_rhmadeplant'] = um($pdo, "SELECT COUNT(*) AS n FROM colaboradores_metadados WHERE origem_metadados <> 'RHMADEPLANT'");
$rel['B_sinais_rhteste'] = um($pdo, "SELECT COUNT(*) AS n FROM colaboradores_metadados WHERE origem_metadados = 'RHTESTE' OR origem_metadados LIKE '%TESTE%'");

// ===================== CAMADA C — colaboradores =====================
$rel['C_colaboradores'] = um($pdo, "
    SELECT
        COUNT(*)                          AS total,
        SUM(ativo = 1)                    AS ativos,
        SUM(ativo = 0)                    AS inativos,
        SUM(metadados_id IS NOT NULL)     AS com_metadados_id,
        SUM(metadados_id IS NULL)         AS sem_metadados_id,
        SUM(empresa_id IS NOT NULL)       AS com_empresa,
        SUM(setor_id IS NOT NULL)         AS com_setor,
        COUNT(DISTINCT cargo_id)          AS cargos_distintos
    FROM colaboradores
");
$rel['C_metadados_id_duplicado'] = um($pdo, "
    SELECT COUNT(*) AS n FROM (SELECT metadados_id FROM colaboradores WHERE metadados_id IS NOT NULL GROUP BY metadados_id HAVING COUNT(*) > 1) x
");
$rel['C_link_orfao'] = um($pdo, "
    SELECT COUNT(*) AS n FROM colaboradores c
    LEFT JOIN colaboradores_metadados m ON m.id = c.metadados_id
    WHERE c.metadados_id IS NOT NULL AND m.id IS NULL
");
$rel['C_vinculado_a_espelho_ativo'] = um($pdo, "
    SELECT COUNT(*) AS n FROM colaboradores c INNER JOIN colaboradores_metadados m ON m.id = c.metadados_id WHERE m.ativo = 1
");
$rel['C_vinculado_a_espelho_desligado'] = um($pdo, "
    SELECT COUNT(*) AS n FROM colaboradores c INNER JOIN colaboradores_metadados m ON m.id = c.metadados_id WHERE m.ativo = 0
");
$rel['C_espelho_sem_correspondente_local'] = um($pdo, "
    SELECT COUNT(*) AS n FROM colaboradores_metadados m LEFT JOIN colaboradores c ON c.metadados_id = m.id WHERE c.id IS NULL
");
$rel['C_espelho_ATIVO_sem_correspondente_local'] = um($pdo, "
    SELECT COUNT(*) AS n FROM colaboradores_metadados m LEFT JOIN colaboradores c ON c.metadados_id = m.id WHERE c.id IS NULL AND m.ativo = 1
");

// ===================== metadados_sync_execucoes =====================
$temExec = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metadados_sync_execucoes'")->fetchColumn() > 0;
if ($temExec) {
    $rel['sync_execucoes_total'] = (int)$pdo->query("SELECT COUNT(*) FROM metadados_sync_execucoes")->fetchColumn();
    try {
        $rel['sync_ultimas_colaboradores'] = $pdo->query("
            SELECT id, gatilho, dimensao, status, origem, registros_recebidos, inseridos, atualizados, inalterados, erros,
                   iniciado_em, concluido_em, correlacao_id
            FROM metadados_sync_execucoes
            WHERE dimensao = 'colaboradores' OR dimensao IS NULL
            ORDER BY id DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rel['sync_ultimas_colaboradores'] = ['erro' => $e->getMessage()];
    }
    $rel['sync_ultima_valida'] = (function () use ($pdo) {
        try {
            return $pdo->query("
                SELECT id, dimensao, status, origem, registros_recebidos, inseridos, atualizados, inalterados, erros, concluido_em
                FROM metadados_sync_execucoes
                WHERE status IN ('sucesso','sucesso_com_erros') AND concluido_em IS NOT NULL
                ORDER BY concluido_em DESC, id DESC LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return ['erro' => $e->getMessage()];
        }
    })();
} else {
    $rel['sync_execucoes'] = 'tabela metadados_sync_execucoes nao existe neste banco';
}

// ===================== FABIANE (ou --nome) — SEM PII =====================
if (isset($opts['nome']) && trim((string)$opts['nome']) !== '') {
    $nome = '%' . trim((string)$opts['nome']) . '%';

    $stB = $pdo->prepare("SELECT id, ativo, (cpf IS NOT NULL AND cpf <> '') AS tem_cpf, (demissao IS NOT NULL) AS tem_demissao,
                                 CONCAT(codigo_empresa,'-',codigo_unidade,'-',numero_contrato) AS chave
                          FROM colaboradores_metadados WHERE nome LIKE ?");
    $stB->execute([$nome]);
    $emB = $stB->fetchAll(PDO::FETCH_ASSOC);

    $stC = $pdo->prepare("SELECT id, ativo, (metadados_id IS NOT NULL) AS tem_link, metadados_id FROM colaboradores WHERE nome LIKE ?");
    $stC->execute([$nome]);
    $emC = $stC->fetchAll(PDO::FETCH_ASSOC);

    $rel['pessoa_diagnostico'] = [
        'termo' => trim((string)$opts['nome']),
        'no_espelho' => count($emB) > 0,
        'ocorrencias_espelho' => array_map(static fn ($r) => [
            'chave' => $r['chave'],
            'situacao' => (int)$r['ativo'] === 1 ? 'ativo' : ((int)$r['ativo'] === 0 ? 'desligado' : 'indefinido'),
            'tem_cpf' => (bool)$r['tem_cpf'],
            'tem_demissao' => (bool)$r['tem_demissao'],
        ], $emB),
        'em_colaboradores_local' => count($emC) > 0,
        'ocorrencias_local' => array_map(static fn ($r) => [
            'situacao' => (int)$r['ativo'] === 1 ? 'ativo' : 'inativo',
            'tem_metadados_id' => (bool)$r['tem_link'],
        ], $emC),
        'aparece_na_tela_admin_colaboradores' => count($emC) > 0,
        'causa_provavel' => count($emC) === 0 && count($emB) > 0
            ? 'Existe no espelho oficial mas NAO tem linha em `colaboradores` — a tela le so `colaboradores`.'
            : (count($emC) > 0 ? 'Esta em `colaboradores`, deveria aparecer na tela (verifique filtros/paginacao).' : 'Nao encontrada em nenhuma camada MySQL.'),
    ];
}

// ===================== COMPARAÇÃO A×B (opcional) =====================
if (isset($opts['fonte-chaves']) && is_file($opts['fonte-chaves'])) {
    $fonte = json_decode((string)file_get_contents($opts['fonte-chaves']), true);
    if (is_array($fonte) && isset($fonte['chaves']) && is_array($fonte['chaves'])) {
        // fonte: [{e,u,c,a}] onde a = 1 ativo / 0 desligado
        $fonteMap = [];
        foreach ($fonte['chaves'] as $k) {
            $fonteMap[$k['e'] . '|' . $k['u'] . '|' . $k['c']] = (int)($k['a'] ?? -1);
        }
        $espMap = [];
        foreach ($pdo->query("SELECT codigo_empresa, codigo_unidade, numero_contrato, ativo FROM colaboradores_metadados") as $r) {
            $espMap[$r['codigo_empresa'] . '|' . $r['codigo_unidade'] . '|' . $r['numero_contrato']] = (int)$r['ativo'];
        }
        $naFonteNaoNoEspelho = array_diff_key($fonteMap, $espMap);
        $noEspelhoNaoNaFonte = array_diff_key($espMap, $fonteMap);
        $divergenciaSituacao = [];
        foreach ($fonteMap as $k => $a) {
            if (isset($espMap[$k]) && $a !== -1 && $a !== $espMap[$k]) {
                $divergenciaSituacao[] = $k;
            }
        }
        $rel['comparacao_A_x_B'] = [
            'fonte_total' => count($fonteMap),
            'espelho_total' => count($espMap),
            'presentes_nos_dois' => count(array_intersect_key($fonteMap, $espMap)),
            'na_fonte_ausentes_no_espelho' => count($naFonteNaoNoEspelho),
            'no_espelho_ausentes_na_fonte_atual' => count($noEspelhoNaoNaFonte),
            'divergencia_situacao_ativo_desligado' => count($divergenciaSituacao),
            'exemplos_na_fonte_ausentes_no_espelho' => array_slice(array_keys($naFonteNaoNoEspelho), 0, 15),
            'exemplos_no_espelho_ausentes_na_fonte' => array_slice(array_keys($noEspelhoNaoNaFonte), 0, 15),
            'exemplos_divergencia_situacao' => array_slice($divergenciaSituacao, 0, 15),
            'fonte_gerada_em' => $fonte['gerado_em'] ?? null,
        ];
    } else {
        $rel['comparacao_A_x_B'] = 'arquivo de chaves invalido';
    }
} else {
    $rel['comparacao_A_x_B'] = 'nao executada — passe --fonte-chaves=<arquivo do script rhmadeplant>';
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$destino = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . 'diag-colaboradores-mysql-' . date('Ymd-His') . '.json';
@mkdir(dirname($destino), 0775, true);
@file_put_contents($destino, $json);
echo $json . PHP_EOL;
fwrite(STDERR, PHP_EOL . 'Salvo em: ' . $destino . PHP_EOL);
