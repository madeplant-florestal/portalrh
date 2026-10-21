<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';
// require_once (não a execução direta): o guard no fim do script real evita disparar a
// sincronização aqui — só expõe cronMetadadosUuidV4() e cronMetadadosExecutarSubprocesso().
require_once __DIR__ . '/../../scripts/cron_sincronizar_metadados_diario.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhmadeplant_test_cron_' . bin2hex(random_bytes(4));
mkdir($tmpDir);
$fixturas = [];

$criarFixtura = static function (string $nome, string $codigoPhp) use ($tmpDir, &$fixturas): string {
    $caminho = $tmpDir . DIRECTORY_SEPARATOR . $nome;
    file_put_contents($caminho, "<?php\n" . $codigoPhp);
    $fixturas[] = $caminho;
    return $caminho;
};

try {
    // --- Caso 1: uuidV4 sempre no formato RFC 4122 v4 ---------------------------------------
    for ($i = 0; $i < 20; $i++) {
        $uuid = cronMetadadosUuidV4();
        $assert(
            (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid),
            "Caso 1: '{$uuid}' deveria ser um UUID v4 válido."
        );
    }

    // --- Caso 2: subprocesso com sucesso (exit 0) é capturado corretamente ------------------
    $sucesso = $criarFixtura('sucesso.php', 'echo json_encode(["ok" => true]); exit(0);');
    $r2 = cronMetadadosExecutarSubprocesso(PHP_BINARY, $sucesso, [], 10);
    $assert($r2['exit_code'] === 0, 'Caso 2: exit_code deveria ser 0.');
    $assert(trim($r2['stdout']) === '{"ok":true}', 'Caso 2: stdout deveria ser o JSON impresso pela fixtura.');
    $assert($r2['matou_por_timeout'] === false, 'Caso 2: não deveria ter matado por timeout.');

    // --- Caso 3: subprocesso com falha (exit != 0) é refletido no retorno, não escondido ----
    $falha = $criarFixtura('falha.php', 'fwrite(STDERR, "deu ruim"); exit(1);');
    $r3 = cronMetadadosExecutarSubprocesso(PHP_BINARY, $falha, [], 10);
    $assert($r3['exit_code'] === 1, 'Caso 3: exit_code deveria ser 1.');
    $assert(strpos($r3['stderr'], 'deu ruim') !== false, 'Caso 3: stderr deveria ter sido capturado.');
    $assert($r3['matou_por_timeout'] === false, 'Caso 3: não deveria ter matado por timeout (terminou sozinho).');

    // --- Caso 4: argumentos são repassados ao subprocesso -----------------------------------
    $ecoArgs = $criarFixtura('eco_args.php', 'echo json_encode(array_slice($argv, 1)); exit(0);');
    $r4 = cronMetadadosExecutarSubprocesso(PHP_BINARY, $ecoArgs, ['--enviar', '--dimensao=todas'], 10);
    $assert(trim($r4['stdout']) === '["--enviar","--dimensao=todas"]', 'Caso 4: argumentos deveriam chegar intactos ao subprocesso.');

    // --- Caso 5: subprocesso travado é morto pelo timeout, nunca tratado como sucesso -------
    $travado = $criarFixtura('travado.php', 'sleep(30); echo json_encode(["ok" => true]); exit(0);');
    $inicio = microtime(true);
    $r5 = cronMetadadosExecutarSubprocesso(PHP_BINARY, $travado, [], 1);
    $duracao = microtime(true) - $inicio;
    $assert($r5['matou_por_timeout'] === true, 'Caso 5: deveria ter sido marcado como morto por timeout.');
    $assert($duracao < 10.0, "Caso 5: deveria ter sido morto perto do timeout de 1s, levou {$duracao}s.");

    echo "OK unit_cron_sincronizar_metadados_subprocesso\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($fixturas as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
