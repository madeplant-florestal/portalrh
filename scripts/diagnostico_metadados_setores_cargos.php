<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Diagnóstico SOMENTE LEITURA de RHSETORES e RHCARGOS no METADADOS (SQL Server) — Fase 5.2.
 *
 * Roda DENTRO da rede Madeplant (mesma máquina que já executa
 * scripts/sync_metadados_colaboradores.php / sync_metadados_producao.php), onde o driver
 * pdo_sqlsrv e a rota até o SQL Server existem. NÃO roda no ambiente de dev (sem driver).
 *
 * Executa APENAS SELECT / INFORMATION_SCHEMA — nenhum INSERT/UPDATE/DELETE/MERGE/DDL. Serve para
 * confirmar, de forma direta (sem usar colaboradores_metadados como proxy), a IDENTIDADE OFICIAL
 * de Setor e Cargo antes de implementar a sincronização dessas dimensões:
 *   - colunas, coluna de código, coluna de descrição;
 *   - existência de EMPRESA / UNIDADE (chave global x composta);
 *   - coluna de situação/ativo;
 *   - total, códigos distintos, duplicidades;
 *   - mesmo código com descrições diferentes (indício de escopo por empresa);
 *   - correspondência com RHCONTRATOS.SETOR / RHCONTRATOS.CARGO.
 *
 * Saída: JSON no stdout + arquivo em storage/imports/. Nenhum dado sensível de folha é
 * selecionado (só código + DESCRICAO40 + EMPRESA/UNIDADE nas amostras).
 *
 * Uso:  php scripts/diagnostico_metadados_setores_cargos.php
 */

function consulta(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        return ['ok' => true, 'linhas' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'erro' => $e->getMessage(), 'sql' => trim(preg_replace('/\s+/', ' ', $sql))];
    }
}

function escalar(array $resultado, string $coluna): ?string
{
    if (($resultado['ok'] ?? false) && isset($resultado['linhas'][0][$coluna])) {
        return (string)$resultado['linhas'][0][$coluna];
    }
    return null;
}

try {
    $pdo = MetadadosDatabase::conn();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Nao foi possivel conectar ao METADADOS: ' . $e->getMessage() . PHP_EOL
        . 'Rode este script na maquina interna (com pdo_sqlsrv e rota ate o SQL Server).' . PHP_EOL);
    exit(1);
}

$origem = MetadadosDatabase::sourceLabel();
$relatorio = [
    'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'origem' => $origem,
    'observacao' => 'Somente leitura. Nenhuma mutacao no SQL Server.',
];

$dimensoes = [
    'RHSETORES' => ['codigo' => 'SETOR', 'contrato_col' => 'SETOR'],
    'RHCARGOS'  => ['codigo' => 'CARGO', 'contrato_col' => 'CARGO'],
];

foreach ($dimensoes as $tabela => $cfg) {
    $cod = $cfg['codigo'];
    $cc = $cfg['contrato_col'];
    $sec = [];

    $sec['colunas'] = consulta($pdo,
        "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, ORDINAL_POSITION
         FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$tabela}' ORDER BY ORDINAL_POSITION");

    $colNomes = [];
    if ($sec['colunas']['ok'] ?? false) {
        $colNomes = array_map(static fn ($r) => strtoupper((string)$r['COLUMN_NAME']), $sec['colunas']['linhas']);
    }
    $temEmpresa = in_array('EMPRESA', $colNomes, true);
    $temUnidade = in_array('UNIDADE', $colNomes, true);
    $temDescricao40 = in_array('DESCRICAO40', $colNomes, true);
    $sec['flags'] = [
        'tem_coluna_' . strtolower($cod) => in_array($cod, $colNomes, true),
        'tem_DESCRICAO40' => $temDescricao40,
        'tem_EMPRESA' => $temEmpresa,
        'tem_UNIDADE' => $temUnidade,
    ];

    $sec['colunas_candidatas_descricao'] = array_values(array_filter(
        $colNomes,
        static fn ($c) => strpos($c, 'DESCRICAO') !== false || strpos($c, 'NOME') !== false || $c === 'TITULO'
    ));
    $sec['colunas_candidatas_situacao'] = array_values(array_filter(
        $colNomes,
        static fn ($c) => preg_match('/SITUAC|ATIV|INATIV|STATUS|DATABAIXA|DATAEXCL|BLOQUEAD/', $c) === 1
    ));

    // Fase 5.2 — semântica de ATIVADESATIVADA (ativo x desativado). Enquanto não for confirmada,
    // o Portal guarda o valor BRUTO em `situacao_metadados` e NÃO desativa registros locais.
    if (in_array('ATIVADESATIVADA', $colNomes, true)) {
        $sec['ativadesativada_valores'] = consulta($pdo,
            "SELECT ATIVADESATIVADA AS valor, COUNT(*) AS qtd FROM {$tabela}
             GROUP BY ATIVADESATIVADA ORDER BY COUNT(*) DESC");
        // quantos códigos EM USO em RHCONTRATOS caem em cada valor (ajuda a inferir a semântica)
        $sec['ativadesativada_em_uso_por_contrato'] = consulta($pdo,
            "SELECT s.ATIVADESATIVADA AS valor, COUNT(DISTINCT c.{$cfg['contrato_col']}) AS codigos_em_contratos
             FROM {$tabela} s
             LEFT JOIN RHCONTRATOS c ON c.{$cfg['contrato_col']} = s.{$cod}
             GROUP BY s.ATIVADESATIVADA ORDER BY 2 DESC");
    }

    $sec['total_linhas'] = consulta($pdo, "SELECT COUNT(*) AS total FROM {$tabela}");
    $sec['codigos_distintos'] = consulta($pdo, "SELECT COUNT(DISTINCT {$cod}) AS distintos FROM {$tabela}");
    $sec['codigo_duplicado'] = consulta($pdo,
        "SELECT {$cod} AS codigo, COUNT(*) AS qtd FROM {$tabela} GROUP BY {$cod} HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC");

    if ($temDescricao40) {
        $sec['codigo_com_descricoes_distintas'] = consulta($pdo,
            "SELECT {$cod} AS codigo, COUNT(DISTINCT DESCRICAO40) AS descricoes_distintas
             FROM {$tabela} GROUP BY {$cod} HAVING COUNT(DISTINCT DESCRICAO40) > 1 ORDER BY 2 DESC");
    }

    if ($temEmpresa) {
        $sec['chave_global_vs_composta'] = [
            'linhas_totais' => consulta($pdo, "SELECT COUNT(*) AS n FROM {$tabela}"),
            'distintos_codigo' => consulta($pdo, "SELECT COUNT(*) AS n FROM (SELECT DISTINCT {$cod} FROM {$tabela}) x"),
            'distintos_empresa_codigo' => consulta($pdo, "SELECT COUNT(*) AS n FROM (SELECT DISTINCT EMPRESA, {$cod} FROM {$tabela}) x"),
        ];
        if ($temUnidade) {
            $sec['chave_global_vs_composta']['distintos_empresa_unidade_codigo'] = consulta($pdo,
                "SELECT COUNT(*) AS n FROM (SELECT DISTINCT EMPRESA, UNIDADE, {$cod} FROM {$tabela}) x");
        }
        // mesmo codigo em empresas diferentes com descricao diferente = escopo real por empresa
        if ($temDescricao40) {
            $sec['codigo_multiempresa_descricao_divergente'] = consulta($pdo,
                "SELECT {$cod} AS codigo, COUNT(DISTINCT EMPRESA) AS empresas, COUNT(DISTINCT DESCRICAO40) AS descricoes
                 FROM {$tabela} GROUP BY {$cod}
                 HAVING COUNT(DISTINCT EMPRESA) > 1 AND COUNT(DISTINCT DESCRICAO40) > 1 ORDER BY 2 DESC");
        }
    }

    // --- correspondência com RHCONTRATOS ---
    $sec['rhcontratos'] = [
        'codigos_distintos_em_uso' => consulta($pdo,
            "SELECT COUNT(*) AS n FROM (SELECT DISTINCT {$cc} AS c FROM RHCONTRATOS
              WHERE {$cc} IS NOT NULL AND LTRIM(RTRIM({$cc})) <> '') d"),
        'codigos_em_uso_sem_correspondencia_no_catalogo' => consulta($pdo,
            "SELECT COUNT(*) AS n FROM (SELECT DISTINCT {$cc} AS c FROM RHCONTRATOS
              WHERE {$cc} IS NOT NULL AND LTRIM(RTRIM({$cc})) <> '') d
             WHERE NOT EXISTS (SELECT 1 FROM {$tabela} s WHERE s.{$cod} = d.c)"),
        'exemplos_sem_correspondencia' => consulta($pdo,
            "SELECT TOP 20 d.c AS codigo_em_contrato FROM (SELECT DISTINCT {$cc} AS c FROM RHCONTRATOS
              WHERE {$cc} IS NOT NULL AND LTRIM(RTRIM({$cc})) <> '') d
             WHERE NOT EXISTS (SELECT 1 FROM {$tabela} s WHERE s.{$cod} = d.c) ORDER BY d.c"),
    ];
    if ($temEmpresa) {
        // o par (EMPRESA do contrato, codigo) casa no catalogo? testa se a chave precisa de EMPRESA
        $sec['rhcontratos']['pares_empresa_codigo_sem_correspondencia'] = consulta($pdo,
            "SELECT COUNT(*) AS n FROM (SELECT DISTINCT EMPRESA AS e, {$cc} AS c FROM RHCONTRATOS
              WHERE {$cc} IS NOT NULL AND LTRIM(RTRIM({$cc})) <> '') d
             WHERE NOT EXISTS (SELECT 1 FROM {$tabela} s WHERE s.{$cod} = d.c AND s.EMPRESA = d.e)");
    }

    // --- amostra (sem colunas sensiveis de folha) ---
    $colsAmostra = [$cod];
    if ($temEmpresa) { $colsAmostra[] = 'EMPRESA'; }
    if ($temUnidade) { $colsAmostra[] = 'UNIDADE'; }
    if ($temDescricao40) { $colsAmostra[] = 'DESCRICAO40'; }
    $sec['amostra'] = consulta($pdo,
        'SELECT TOP 30 ' . implode(', ', $colsAmostra) . " FROM {$tabela} ORDER BY {$cod}");

    // --- veredito heuristico ---
    $total = (int)(escalar($sec['total_linhas'], 'total') ?? 0);
    $distintos = (int)(escalar($sec['codigos_distintos'], 'distintos') ?? 0);
    $dupCount = ($sec['codigo_duplicado']['ok'] ?? false) ? count($sec['codigo_duplicado']['linhas']) : null;
    $orfaosGlobais = $sec['rhcontratos']['codigos_em_uso_sem_correspondencia_no_catalogo'] ?? [];
    $orfaosGlobais = (int)(escalar($orfaosGlobais, 'n') ?? -1);

    $sec['veredito'] = [
        'coluna_codigo_esperada' => $cod . ' (do JOIN validado em MetadadosSyncService::QUERY, Fase 3)',
        'coluna_descricao_esperada' => 'DESCRICAO40',
        'codigo_parece_global' => ($dupCount === 0 && $total === $distintos && !$temEmpresa)
            ? 'SIM (sem EMPRESA, sem duplicidade)'
            : (($temEmpresa) ? 'REVISAR (tabela tem EMPRESA — ver chave_global_vs_composta)' : 'REVISAR (ha duplicidade de codigo)'),
        'rhcontratos_bate_com_catalogo' => $orfaosGlobais === 0
            ? 'SIM (0 codigos em uso sem correspondencia)'
            : "REVISAR ({$orfaosGlobais} codigos em uso sem correspondencia no catalogo)",
    ];

    $relatorio[$tabela] = $sec;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$destino = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
    . 'diagnostico-setores-cargos-' . date('Ymd-His') . '.json';
@mkdir(dirname($destino), 0775, true);
@file_put_contents($destino, $json);

echo $json . PHP_EOL;
fwrite(STDERR, PHP_EOL . 'Relatorio salvo em: ' . $destino . PHP_EOL);
