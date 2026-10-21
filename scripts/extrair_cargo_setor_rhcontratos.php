<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Extração SOMENTE LEITURA de RHCONTRATOS.CARGO x RHCONTRATOS.SETOR (METADADOS, SQL Server) —
 * correção 2026-10 (caso recorrente: setor MANUTENÇÃO/código 12 sem nenhuma relação na matriz
 * local, RHQUADROLOTCARGO confirmada não utilizável na Madeplant — ver
 * database/migrations/2026-09-14-cargo-setores-metadados.sql).
 *
 * Mesma fonte/critério da carga inicial de 2026-09-14 (histórico COMPLETO — ativos e desligados;
 * uma combinação que já existiu oficialmente permanece válida para reabertura futura de vaga),
 * mas como script RE-EXECUTÁVEL em vez de um seed estático escrito uma vez só: cobre setores que
 * não existiam (ou não tinham cargo registrado) na primeira extração.
 *
 * Roda DENTRO da rede Madeplant (mesma máquina/rede que já executa
 * scripts/sync_metadados_producao.php / scripts/diagnostico_metadados_setores_cargos.php — onde o
 * driver pdo_sqlsrv e a rota até o SQL Server existem). NÃO roda no ambiente de dev (sem driver).
 *
 * Executa APENAS SELECT — nenhum INSERT/UPDATE/DELETE/MERGE/DDL, nem no SQL Server nem em lugar
 * nenhum. Só EXTRAI e grava um JSON em storage/imports/ — a aplicação na matriz oficial
 * (`cargo_setores_metadados`) é um passo SEPARADO, feito por
 * scripts/materializar_cargo_setor_metadados.php, rodando onde o MySQL do Portal (dev ou
 * produção) for alcançável — nunca nesta mesma máquina/execução.
 *
 * Uso:  php scripts/extrair_cargo_setor_rhcontratos.php
 */

try {
    $pdo = MetadadosDatabase::conn();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Nao foi possivel conectar ao METADADOS: ' . $e->getMessage() . PHP_EOL
        . 'Rode este script na maquina interna (com pdo_sqlsrv e rota ate o SQL Server).' . PHP_EOL);
    exit(1);
}

$stmt = $pdo->query(
    "SELECT DISTINCT CARGO AS codigo_cargo, SETOR AS codigo_setor
     FROM RHCONTRATOS
     WHERE CARGO IS NOT NULL AND LTRIM(RTRIM(CARGO)) <> ''
       AND SETOR IS NOT NULL AND LTRIM(RTRIM(SETOR)) <> ''
     ORDER BY SETOR, CARGO"
);
$pares = array_map(
    static fn (array $row): array => [
        'codigo_cargo' => trim((string)$row['codigo_cargo']),
        'codigo_setor' => trim((string)$row['codigo_setor']),
    ],
    $stmt->fetchAll(PDO::FETCH_ASSOC)
);

$destino = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
    . 'cargo-setor-rhcontratos-' . date('Ymd-His') . '.json';
@mkdir(dirname($destino), 0775, true);
@file_put_contents($destino, json_encode($pares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fwrite(STDERR, 'Origem: ' . MetadadosDatabase::sourceLabel() . PHP_EOL);
fwrite(STDERR, 'Combinacoes distintas Cargo x Setor encontradas: ' . count($pares) . PHP_EOL);
fwrite(STDERR, 'Arquivo salvo em: ' . $destino . PHP_EOL);
fwrite(STDERR, 'Proximo passo (em outra maquina, onde o MySQL do Portal e alcancavel):' . PHP_EOL);
fwrite(STDERR, '  php scripts/materializar_cargo_setor_metadados.php "' . $destino . '"' . PHP_EOL);

echo json_encode($pares, JSON_UNESCAPED_UNICODE) . PHP_EOL;
