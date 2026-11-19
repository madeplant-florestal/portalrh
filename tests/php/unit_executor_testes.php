<?php

/**
 * Teste DO PRÓPRIO EXECUTOR (tests/php/run_all.php + lib/ClassificadorTeste.php): prova que nenhuma das formas de falso sucesso
 * identificadas (exceção engolida pelo tratador, "[FALHOU]" com código 0, encerramento prematuro por exit de controller) volta a passar,
 * que o código de saída do executor reflete reprovações e que o bootstrap sai com código ≠ 0 numa exceção não tratada no CLI.
 */

require_once __DIR__ . '/lib/ClassificadorTeste.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};
$C = 'ClassificadorTeste';

// ---- 1. classificador (puro) ------------------------------------------------------------------------------------------------------------------------
$check($C::classificar(0, "  [ok] a\n  [ok] b\n\nPDI_OK\n")['status'] === $C::PASSOU, '(classificador) saída íntegra com linha de conclusão = PASSOU');
$check($C::classificar(0, "  [ok] a\n  [FALHOU] b\nOK\n")['status'] === $C::FALHOU, '(classificador) "[FALHOU]" com código 0 = FALHOU (falso sucesso barrado)');
$check($C::classificar(1, "  [ok] a\n")['status'] === $C::FALHOU, '(classificador) código de saída ≠ 0 sem outras marcas = FALHOU');
$check($C::classificar(0, "Erro 500\n\nRuntimeException: x\n")['status'] === $C::ERRO_EXECUCAO, '(classificador) exceção engolida pelo tratador ("Erro 500") com código 0 = ERRO_EXECUCAO');
$check($C::classificar(255, "PHP Fatal error:  Uncaught Error: x\n")['status'] === $C::ERRO_EXECUCAO, '(classificador) erro fatal = ERRO_EXECUCAO');
$check($C::classificar(0, "  [ok] a\n  [ok] b\nAcesso negado")['status'] === $C::INCOMPLETO, '(classificador) terminar em "Acesso negado" (exit de controller) = INCOMPLETO, nunca sucesso');
$check($C::classificar(0, '')['status'] === $C::INCOMPLETO, '(classificador) saída vazia com código 0 = INCOMPLETO');
$check($C::classificar(1, "Erro ao conectar ao banco de dados: SQLSTATE[HY000] [2002]\n")['status'] === $C::ERRO_AMBIENTE, '(classificador) banco indisponível = ERRO_AMBIENTE (distinto de falha funcional)');
$check($C::classificar(0, "Erro 500\n\nRuntimeException: Sincronização bloqueada: o espelho colaboradores_metadados já contém registros de outra origem — zzed-teste (3)\n")['status'] === $C::ERRO_AMBIENTE, '(classificador) resíduo de outra origem = ERRO_AMBIENTE');
$check($C::classificar(0, "SKIP x (base local fora do estado esperado)\n")['status'] === $C::IGNORADO, '(classificador) SKIP explícito = IGNORADO');
$check($C::classificar(-1, "  [ok] a\n", true)['status'] === $C::ERRO_EXECUCAO, '(classificador) tempo excedido = ERRO_EXECUCAO');
$check($C::classificar(0, "  [ok] (página) renderiza sem Warning/Notice\nFEEDBACK_OK\n")['status'] === $C::PASSOU, '(classificador) a palavra "Warning" dentro de uma descrição de verificação não reprova');

// ---- 2. executor de ponta a ponta com testes sintéticos --------------------------------------------------------------------------------------------
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_executor_' . bin2hex(random_bytes(4));
mkdir($base . DIRECTORY_SEPARATOR . 'ruim', 0777, true);
mkdir($base . DIRECTORY_SEPARATOR . 'bom', 0777, true);
$escreve = static function (string $dir, string $nome, string $php) use ($base): void {
    file_put_contents($base . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $nome . '.php', "<?php\n" . $php . "\n");
};
$escreve('bom', 'integration_a', 'echo "  [ok] x\n"; echo "\nA_OK\n";');
$escreve('bom', 'integration_b', 'echo "SKIP b (sem base)\n";');
$escreve('ruim', 'integration_ok', 'echo "  [ok] x\n"; echo "\nOK — tudo\n";');
$escreve('ruim', 'integration_engolida', 'set_exception_handler(function ($e) { echo "Erro 500\n\n"; echo $e->getMessage(); }); throw new RuntimeException("boom");');
$escreve('ruim', 'integration_falhou_exit0', 'echo "  [FALHOU] algo\n"; echo "OK\n"; exit(0);');
$escreve('ruim', 'integration_prematuro', 'echo "  [ok] a\n"; http_response_code(403); echo "Acesso negado"; exit;');
$escreve('ruim', 'integration_exit1', 'echo "  [ok] a\n"; exit(1);');
$escreve('ruim', 'integration_ambiente', 'echo "Erro ao conectar ao banco de dados: x\n"; exit(1);');
$rodar = static function (string $dir) use ($base): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/run_all.php', '--sem-isolamento', '--dir=' . $base . DIRECTORY_SEPARATOR . $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
try {
    [$rc, $out] = $rodar('bom');
    $check($rc === 0 && str_contains($out, 'SUÍTE APROVADA') && str_contains($out, 'Total executado : 2') && preg_match("/IGNORADO\s*: 1/", $out), '(executor) suíte íntegra: código 0, total 2, 1 ignorado e relatório de totais');
    [$rc, $out] = $rodar('ruim');
    $status = static function (string $nome) use ($out): string {
        return preg_match('/^\[(\w+)\s*\] ' . preg_quote($nome, '/') . ' /m', $out, $m) ? $m[1] : '?';
    };
    $check($status('integration_ok') === 'PASSOU' && $status('integration_engolida') === 'ERRO_EXECUCAO' && $status('integration_falhou_exit0') === 'FALHOU' && $status('integration_prematuro') === 'INCOMPLETO' && $status('integration_exit1') === 'FALHOU' && $status('integration_ambiente') === 'ERRO_AMBIENTE', '(executor) cada forma de falso sucesso recebe o status correto; só o teste íntegro passa');
    $check($rc === 1 && str_contains($out, 'SUÍTE REPROVADA') && str_contains($out, 'Total executado : 6') && preg_match('/PASSOU\s*: 1/', $out), '(executor) com reprovações o executor sai com código 1 e NÃO declara a suíte aprovada');
    // só erro de ambiente => código 3 (não conclusivo), não 0
    @unlink($base . '/ruim/integration_engolida.php');
    @unlink($base . '/ruim/integration_falhou_exit0.php');
    @unlink($base . '/ruim/integration_prematuro.php');
    @unlink($base . '/ruim/integration_exit1.php');
    [$rc3, $out3] = $rodar('ruim');
    $check($rc3 === 3 && str_contains($out3, 'SUÍTE NÃO CONCLUSIVA'), '(executor) só erro de ambiente: código 3 (não conclusivo), nunca 0');
} finally {
    foreach (glob($base . '/*/*.php') ?: [] as $f) {
        @unlink($f);
    }
    foreach (['ruim', 'bom'] as $d) {
        @rmdir($base . DIRECTORY_SEPARATOR . $d);
    }
    @rmdir($base);
}

// ---- 3. bootstrap: exceção não tratada no CLI não termina com 0 ----------------------------------------------------------------------------------------
$script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_boot_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($script, "<?php\nrequire " . var_export(realpath(__DIR__ . '/../../app/core/bootstrap.php'), true) . ";\nthrow new RuntimeException('nao tratada');\n");
$p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$saidaBoot = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
$rcBoot = proc_close($p);
@unlink($script);
$check($rcBoot !== 0 && (str_contains($saidaBoot, 'Erro 500') || str_contains($saidaBoot, 'Erro interno')), '(bootstrap) exceção não tratada no CLI sai com código ≠ 0 (o tratador não mascara mais como sucesso)');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — executor de testes.\n";
