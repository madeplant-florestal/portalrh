<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_catalogo_metadados_sync_ingest (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
};

$pdo = Database::conn();
$temSetorCodigo = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setores' AND COLUMN_NAME = 'codigo_setor'"
)->fetchColumn();
if ($temSetorCodigo === 0) {
    echo "SKIP integration_catalogo_metadados_sync_ingest (migration 2026-09-08-setores-cargos-metadados.sql nao aplicada)\n";
    exit(0);
}
$execTableExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metadados_sync_execucoes'"
)->fetchColumn() > 0;
$execMaxIdInicial = $execTableExists
    ? (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM metadados_sync_execucoes")->fetchColumn()
    : 0;

$segredo = 'segredo-teste-' . bin2hex(random_bytes(4));
$config = ['shared_secret' => $segredo, 'replay_window_seconds' => 300, 'max_batch_size' => 5000];
$sfx = substr((string)time(), -5) . random_int(100, 999);

function assinarLote(array $payload, string $segredo, ?string $ts = null): array
{
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = $ts ?? (string)time();
    return ['corpo' => $corpo, 'headers' => [
        MetadadosSyncSignature::HEADER_TIMESTAMP => $ts,
        MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar($ts, $corpo, $segredo),
    ]];
}

$limpar = static function () use ($pdo, $execTableExists, $execMaxIdInicial): void {
    $pdo->exec("DELETE FROM setores WHERE nome LIKE 'ZZ ING %' OR slug LIKE 'zz-ing-%'");
    $pdo->exec("DELETE FROM cargos WHERE nome LIKE 'ZZ ING %' OR slug LIKE 'zz-ing-%'");
    if ($execTableExists) {
        $pdo->prepare('DELETE FROM metadados_sync_execucoes WHERE id > ?')->execute([$execMaxIdInicial]);
    }
};
$limpar();

$falha = null;
try {
    foreach (['setores' => 'codigo_setor', 'cargos' => 'codigo_cargo'] as $dim => $col) {
        $tab = $dim;
        $ingest = new MetadadosDimensaoSyncIngestService($dim);
        $repo = new CatalogoMetadadosRepository($dim, $pdo);

        // Códigos com zero à esquerda e outros formatos opacos.
        $codOpaco = '0' . substr($sfx, 0, 3);      // ex.: 0123
        $codOpaco2 = '00' . substr($sfx, 3, 2);    // ex.: 0045

        $lote = [
            'versao' => '1', 'origem_metadados' => 'RHMADEPLANT',
            'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'total' => 2,
            'registros' => [
                ['codigo' => $codOpaco, 'descricao_oficial' => 'ZZ ING OPACO ' . $sfx, 'situacao_oficial' => 'A'],
                ['codigo' => $codOpaco2, 'descricao_oficial' => 'ZZ ING OPACO2 ' . $sfx, 'situacao_oficial' => 'D'],
            ],
        ];
        $a1 = assinarLote($lote, $segredo);
        $r1 = $ingest->receberLote($a1['corpo'], $a1['headers'], $config);
        $assert($r1['http_status'] === 200, "[{$dim}] lote valido -> 200.");
        $assert($r1['body']['dimensao'] === $dim && $r1['body']['inseridos'] === 2, "[{$dim}] 2 registros inseridos.");

        // CRÍTICO: o código opaco sobreviveu payload -> validator -> ingest -> repository -> MySQL,
        // exatamente igual, sem conversão numérica nem perda de zero à esquerda.
        $reg = $repo->findByCodigo($codOpaco);
        $assert($reg !== null, "[{$dim}] registro com codigo opaco '{$codOpaco}' persistido.");
        $assert($reg[$col] === $codOpaco, "[{$dim}] codigo '{$codOpaco}' preservado LITERALMENTE (veio '" . $reg[$col] . "').");
        $assert($reg[$col] !== ltrim($codOpaco, '0'), "[{$dim}] zeros a esquerda NAO foram removidos.");
        $assert($reg['situacao_metadados'] === 'A', "[{$dim}] situacao bruta 'A' persistida.");
        $assert($repo->findByCodigo($codOpaco2)['situacao_metadados'] === 'D', "[{$dim}] situacao bruta 'D' persistida.");

        // resposta nunca vaza o segredo
        $assert(strpos(json_encode($r1['body']), $segredo) === false, "[{$dim}] resposta nao contem o segredo.");

        // histórico com dimensao correta
        if ($execTableExists) {
            $d = $pdo->query("SELECT dimensao FROM metadados_sync_execucoes WHERE id > {$execMaxIdInicial} ORDER BY id DESC LIMIT 1")->fetchColumn();
            $assert($d === $dim, "[{$dim}] historico registra dimensao={$dim}.");
        }

        // idempotência
        $a2 = assinarLote($lote, $segredo);
        $r2 = $ingest->receberLote($a2['corpo'], $a2['headers'], $config);
        $assert($r2['body']['inalterados'] === 2 && $r2['body']['inseridos'] === 0, "[{$dim}] reenviar = inalterado.");

        // HMAC inválido -> 401
        $ts = (string)time();
        $r3 = $ingest->receberLote($a2['corpo'], [
            MetadadosSyncSignature::HEADER_TIMESTAMP => $ts,
            MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar($ts, $a2['corpo'], 'errado'),
        ], $config);
        $assert($r3['http_status'] === 401, "[{$dim}] assinatura errada -> 401.");

        // payload inválido (descricao_oficial vazia) -> 400
        $loteRuim = $lote;
        $loteRuim['registros'][0]['descricao_oficial'] = '';
        $a4 = assinarLote($loteRuim, $segredo);
        $r4 = $ingest->receberLote($a4['corpo'], $a4['headers'], $config);
        $assert($r4['http_status'] === 400, "[{$dim}] descricao_oficial vazia -> 400.");

        // chave duplicada no lote -> 400
        $loteDup = $lote;
        $loteDup['registros'] = [$lote['registros'][0], $lote['registros'][0]];
        $a5 = assinarLote($loteDup, $segredo);
        $r5 = $ingest->receberLote($a5['corpo'], $a5['headers'], $config);
        $assert($r5['http_status'] === 400, "[{$dim}] codigo duplicado no lote -> 400.");
    }

    // Regressão: empresas/unidades continuam funcionando pelo mesmo ingest genérico.
    $ingestE = new MetadadosDimensaoSyncIngestService('empresas');
    $loteE = [
        'versao' => '1', 'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 1,
        'registros' => [['codigo_empresa' => 'ZZING' . $sfx, 'razao_social' => 'ZZ ING EMPRESA REGRESSAO ' . $sfx . ' LTDA']],
    ];
    $aE = assinarLote($loteE, $segredo);
    $rE = $ingestE->receberLote($aE['corpo'], $aE['headers'], $config);
    $assert($rE['http_status'] === 200 && $rE['body']['dimensao'] === 'empresas', 'Regressao: empresas continua ok.');
    $pdo->exec("DELETE FROM empresas WHERE codigo_empresa = 'ZZING{$sfx}' OR nome LIKE 'ZZ ING EMPRESA REGRESSAO %'");

    echo "OK integration_catalogo_metadados_sync_ingest\n";
} catch (Throwable $e) {
    $falha = $e->getMessage();
} finally {
    $limpar();
    $pdo->exec("DELETE FROM empresas WHERE nome LIKE 'ZZ ING EMPRESA REGRESSAO %'");
}

if ($falha !== null) {
    fwrite(STDERR, $falha . PHP_EOL);
    exit(1);
}
