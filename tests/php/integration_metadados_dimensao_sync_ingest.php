<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_metadados_dimensao_sync_ingest (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::conn();
$temUnidades = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unidades'"
)->fetchColumn();
if ($temUnidades === 0) {
    echo "SKIP integration_metadados_dimensao_sync_ingest (migration 2026-09-04-empresas-unidades-metadados.sql nao aplicada)\n";
    exit(0);
}
$execTableExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metadados_sync_execucoes'"
)->fetchColumn() > 0;
$execMaxIdInicial = $execTableExists
    ? (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM metadados_sync_execucoes")->fetchColumn()
    : 0;

$segredo = 'segredo-teste-' . bin2hex(random_bytes(4));
$config = ['shared_secret' => $segredo, 'replay_window_seconds' => 300, 'max_batch_size' => 2000];
$sufixo = substr((string)time(), -6) . (string)random_int(10, 99);
$empA = 'ZTI1' . $sufixo;

function assinar(array $payload, string $segredo, ?string $timestamp = null): array
{
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = $timestamp ?? (string)time();
    return [
        'corpo' => $corpo,
        'headers' => [
            MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp,
            MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar($timestamp, $corpo, $segredo),
        ],
    ];
}

$limpar = static function () use ($pdo, $execTableExists, $execMaxIdInicial): void {
    $pdo->exec("DELETE FROM unidades WHERE codigo_empresa LIKE 'ZTI%'");
    $pdo->exec("DELETE FROM empresas WHERE codigo_empresa LIKE 'ZTI%'");
    if ($execTableExists) {
        $pdo->prepare('DELETE FROM metadados_sync_execucoes WHERE id > ?')->execute([$execMaxIdInicial]);
    }
};
$limpar();

$falha = null;
try {
    $empresaIngest = new MetadadosDimensaoSyncIngestService('empresas');
    $unidadeIngest = new MetadadosDimensaoSyncIngestService('unidades');

    // 1) Empresas: lote válido assinado -> 200, 1 inserida, e uma linha no histórico com dimensao='empresas'.
    $loteEmp = [
        'versao' => '1', 'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 1,
        'registros' => [['codigo_empresa' => $empA, 'razao_social' => 'ZZ INGEST EMPRESA ' . $sufixo . ' LTDA']],
    ];
    $a1 = assinar($loteEmp, $segredo);
    $r1 = $empresaIngest->receberLote($a1['corpo'], $a1['headers'], $config);
    $assert($r1['http_status'] === 200, 'Caso 1: empresas válido deveria retornar 200.');
    $assert($r1['body']['dimensao'] === 'empresas' && $r1['body']['inseridos'] === 1, 'Caso 1: 1 empresa inserida.');

    if ($execTableExists) {
        $linha = $pdo->query("SELECT dimensao, inseridos FROM metadados_sync_execucoes WHERE id > {$execMaxIdInicial} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $assert($linha['dimensao'] === 'empresas', 'Caso 1: histórico deveria registrar dimensao=empresas.');
    }

    // 2) Unidades: lote válido -> 200, 1 inserida, histórico dimensao='unidades'.
    $loteUni = [
        'versao' => '1', 'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 1,
        'registros' => [['codigo_empresa' => $empA, 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ INGEST']],
    ];
    $a2 = assinar($loteUni, $segredo);
    $r2 = $unidadeIngest->receberLote($a2['corpo'], $a2['headers'], $config);
    $assert($r2['http_status'] === 200 && $r2['body']['dimensao'] === 'unidades' && $r2['body']['inseridos'] === 1, 'Caso 2: 1 unidade inserida.');
    if ($execTableExists) {
        $linha = $pdo->query("SELECT dimensao FROM metadados_sync_execucoes WHERE id > {$execMaxIdInicial} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $assert($linha['dimensao'] === 'unidades', 'Caso 2: histórico deveria registrar dimensao=unidades.');
    }

    // 3) Idempotência: reenviar os dois lotes -> nada muda.
    $a1b = assinar($loteEmp, $segredo);
    $r1b = $empresaIngest->receberLote($a1b['corpo'], $a1b['headers'], $config);
    $assert($r1b['body']['inalterados'] === 1 && $r1b['body']['inseridos'] === 0, 'Caso 3: empresa reenviada = inalterada.');
    $a2b = assinar($loteUni, $segredo);
    $r2b = $unidadeIngest->receberLote($a2b['corpo'], $a2b['headers'], $config);
    $assert($r2b['body']['inalterados'] === 1, 'Caso 3: unidade reenviada = inalterada.');

    // 4) Assinatura inválida -> 401, nada escrito.
    $corpo4 = json_encode($loteEmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts4 = (string)time();
    $r4 = $empresaIngest->receberLote($corpo4, [
        MetadadosSyncSignature::HEADER_TIMESTAMP => $ts4,
        MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar($ts4, $corpo4, 'segredo-errado'),
    ], $config);
    $assert($r4['http_status'] === 401, 'Caso 4: assinatura errada deveria retornar 401.');

    // 5) Payload estruturalmente inválido (razao_social vazia) -> 400.
    $loteRuim = $loteEmp;
    $loteRuim['registros'][0]['razao_social'] = '';
    $a5 = assinar($loteRuim, $segredo);
    $r5 = $empresaIngest->receberLote($a5['corpo'], $a5['headers'], $config);
    $assert($r5['http_status'] === 400, 'Caso 5: razao_social vazia deveria retornar 400.');

    // 6) Unidade com empresa inexistente -> 200 com erro por linha (erro controlado, não 4xx).
    $loteOrfa = [
        'versao' => '1', 'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 1,
        'registros' => [['codigo_empresa' => 'ZTI_ORFA_' . $sufixo, 'codigo_unidade' => '9', 'descricao' => 'ORFA']],
    ];
    $a6 = assinar($loteOrfa, $segredo);
    $r6 = $unidadeIngest->receberLote($a6['corpo'], $a6['headers'], $config);
    $assert($r6['http_status'] === 200 && $r6['body']['erros'] === 1 && $r6['body']['ok'] === false, 'Caso 6: unidade órfã = erro controlado por linha, sucesso_com_erros.');

    // 7) Resposta nunca vaza segredo/assinatura.
    $assert(strpos(json_encode($r1['body']), $segredo) === false, 'Caso 7: resposta não deveria conter o segredo.');

    echo "OK integration_metadados_dimensao_sync_ingest\n";
} catch (Throwable $e) {
    $falha = $e->getMessage();
} finally {
    $limpar();
}

if ($falha !== null) {
    fwrite(STDERR, $falha . PHP_EOL);
    exit(1);
}
