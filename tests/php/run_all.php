<?php

/**
 * EXECUTOR da suíte PHP (tests/php). Não é um teste.
 *
 *   php tests/php/run_all.php                      roda tudo
 *   php tests/php/run_all.php --filtro=pdi         só os testes cujo nome contém "pdi"
 *   php tests/php/run_all.php --timeout=300        limite por teste (padrão 600 s)
 *   php tests/php/run_all.php --json=saida.json    grava o relatório estruturado
 *   php tests/php/run_all.php --limpar-antes       remove ANTES os resíduos comprovados de teste (ver lib/ResiduosTeste.php)
 *   php tests/php/run_all.php --sem-isolamento     não varre o que cada teste deixa para trás
 *   php tests/php/run_all.php --dir=caminho        outra pasta de testes (usado pelo teste do próprio executor)
 *
 * Código de saída: 0 = tudo PASSOU/IGNORADO · 1 = há FALHOU/ERRO_EXECUCAO/INCOMPLETO · 3 = só erros de AMBIENTE · 2 = uso/guarda de ambiente.
 * O sucesso NUNCA é inferido só do código de saída do PHP (ver lib/ClassificadorTeste.php).
 */

require_once __DIR__ . '/lib/ClassificadorTeste.php';
require_once __DIR__ . '/lib/ResiduosTeste.php';

$opt = ['filtro' => '', 'timeout' => 600, 'json' => '', 'limpar-antes' => false, 'sem-isolamento' => false, 'dir' => __DIR__];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--limpar-antes' || $a === '--sem-isolamento') {
        $opt[substr($a, 2)] = true;
    } elseif (preg_match('/^--(filtro|timeout|json|dir)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[1] === 'timeout' ? max(5, (int)$m[2]) : $m[2];
    } else {
        fwrite(STDERR, "Argumento desconhecido: {$a}\n");
        exit(2);
    }
}

// ---- banco (só para isolamento); a ausência do banco é erro de AMBIENTE, nunca "sucesso" -------------------------------------------------
$pdo = null;
if (!$opt['sem-isolamento']) {
    try {
        require_once __DIR__ . '/../../app/core/bootstrap.php';
        $pdo = Database::conn();
        ResiduosTeste::garantirAmbienteDeTeste($pdo);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERRO_AMBIENTE: banco de dados indisponível (' . $e->getMessage() . ")\n");
        exit(3);
    }
}

$residuoInicial = $pdo !== null ? ResiduosTeste::levantar($pdo) : [];
if ($pdo !== null && ResiduosTeste::total($residuoInicial) > 0) {
    if ($opt['limpar-antes']) {
        $removido = ResiduosTeste::remover($pdo, $residuoInicial, true);
        echo '[isolamento] resíduos comprovados de teste removidos antes da execução: ' . array_sum($removido) . " linhas\n";
        $residuoInicial = ResiduosTeste::levantar($pdo);
    } else {
        echo '[isolamento] AVISO: já existem resíduos de execuções anteriores (usuários=' . count($residuoInicial['usuarios']) . ', contratos=' . count($residuoInicial['metadados'])
            . ") — podem causar falhas de ambiente. Use --limpar-antes (ou tests/php/limpar_residuos_teste.php).\n";
    }
}

$arquivos = glob(rtrim((string)$opt['dir'], '/\\') . '/*.php') ?: [];
sort($arquivos);
$arquivos = array_values(array_filter($arquivos, static function (string $f) use ($opt): bool {
    $b = basename($f, '.php');
    return !str_starts_with($b, 'fixture_') && !in_array($b, ['run_all', 'limpar_residuos_teste'], true) && ($opt['filtro'] === '' || str_contains($b, (string)$opt['filtro']));
}));

/** @return array{codigo:int,saida:string,estourou:bool,segundos:float} */
$executar = static function (string $arquivo, int $limite): array {
    $ini = microtime(true);
    $proc = proc_open([PHP_BINARY, $arquivo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $saida = '';
    $estourou = false;
    while (true) {
        $saida .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $codigo = $st['exitcode'];
            break;
        }
        if (microtime(true) - $ini > $limite) {
            $estourou = true;
            proc_terminate($proc);
            $codigo = -1;
            break;
        }
        usleep(20000);
    }
    $saida .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $fim = proc_close($proc);
    return ['codigo' => $codigo >= 0 ? $codigo : $fim, 'saida' => $saida, 'estourou' => $estourou, 'segundos' => microtime(true) - $ini];
};

$resultados = [];
$vazamentos = [];
$contagem = [];
foreach ($arquivos as $arq) {
    $nome = basename($arq, '.php');
    $antes = $pdo !== null ? ResiduosTeste::levantar($pdo) : [];
    $r = $executar($arq, (int)$opt['timeout']);
    $c = ClassificadorTeste::classificar($r['codigo'], $r['saida'], $r['estourou']);
    $c['nome'] = $nome;
    $c['codigo'] = $r['codigo'];
    $c['segundos'] = round($r['segundos'], 1);
    if ($pdo !== null) {
        $sobra = ResiduosTeste::diferenca($antes, ResiduosTeste::levantar($pdo));
        if (ResiduosTeste::total($sobra) > 0) {
            $linhas = ResiduosTeste::remover($pdo, $sobra, true);
            $c['vazou'] = ResiduosTeste::total($sobra);
            $vazamentos[$nome] = ['registros' => ResiduosTeste::total($sobra), 'linhas_removidas' => array_sum($linhas)];
        }
    }
    $resultados[] = $c;
    $contagem[$c['status']] = ($contagem[$c['status']] ?? 0) + 1;
    echo sprintf("[%-13s] %s (%.1fs)%s\n", $c['status'], $nome, $c['segundos'], isset($c['vazou']) ? "  · vazou {$c['vazou']} registro(s) de teste (removidos pelo executor)" : '');
}

$total = count($resultados);
$problemas = array_values(array_filter($resultados, static fn(array $c): bool => !in_array($c['status'], [ClassificadorTeste::PASSOU, ClassificadorTeste::IGNORADO], true)));
echo "\n================ RESUMO ================\n";
echo "Total executado : {$total}\n";
foreach ([ClassificadorTeste::PASSOU, ClassificadorTeste::FALHOU, ClassificadorTeste::ERRO_EXECUCAO, ClassificadorTeste::ERRO_AMBIENTE, ClassificadorTeste::INCOMPLETO, ClassificadorTeste::IGNORADO] as $s) {
    echo sprintf("%-15s : %d\n", $s, $contagem[$s] ?? 0);
}
echo 'Vazamentos de dados de teste: ' . count($vazamentos) . "\n";
if ($problemas !== []) {
    echo "\n---- Não aprovados ----\n";
    foreach ($problemas as $c) {
        echo "[{$c['status']}] {$c['nome']} — {$c['motivo']}\n";
        foreach ($c['evidencias'] as $e) {
            echo '      ' . mb_substr(trim($e), 0, 200) . "\n";
        }
    }
}
if ($opt['json'] !== '') {
    file_put_contents((string)$opt['json'], json_encode(['total' => $total, 'contagem' => $contagem, 'vazamentos' => $vazamentos, 'testes' => $resultados], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$funcional = array_filter($problemas, static fn(array $c): bool => $c['status'] !== ClassificadorTeste::ERRO_AMBIENTE);
if ($funcional !== []) {
    echo "\nSUÍTE REPROVADA.\n";
    exit(1);
}
if ($problemas !== []) {
    echo "\nSUÍTE NÃO CONCLUSIVA: só erros de ambiente.\n";
    exit(3);
}
echo "\nSUÍTE APROVADA.\n";
exit(0);
