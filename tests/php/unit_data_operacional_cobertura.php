<?php

/**
 * Cobertura ESTÁTICA — Frente D5: nenhuma regra de negócio de produção pode usar o dia UTC/do servidor como se fosse o
 * DIA OPERACIONAL de Campo Grande. Varre o código de produção (app/, sem vendor, comentários removidos) em busca de:
 *   new DateTime[Immutable]('today'|'yesterday'|'tomorrow'|'midnight'), date('Y-m-d') sem instante explícito,
 *   date(..., strtotime('today'|'now'|'+N …'|'-N …')), strtotime('today'|'now'|'+N …'|'-N …'), CURDATE(), CURRENT_DATE.
 * O caminho certo é DateHelper::hojeOperacional()/hojeOperacionalSql()/diaOperacionalDe(). Timestamps UTC (NOW(),
 * agoraUtc(), carimbos) NÃO são afetados. Fixtures, testes, docs e SQL de diagnóstico ficam fora da varredura.
 *
 * EXCEÇÕES justificadas (arquivo => [trecho => motivo]); o trecho precisa aparecer na linha encontrada, e uma exceção
 * que deixa de ser necessária também falha (a lista tem que continuar pequena e verdadeira).
 */

$raiz = realpath(__DIR__ . '/../../app');
$excecoes = [
    'core/Logger.php' => ["date('Y-m-d')" => 'nome do arquivo de log diário: relógio do servidor, não é regra de negócio'],
    'models/MovimentacaoPessoal.php' => ['CURDATE()' => 'rotina de manutenção/seed de dados de teste (data_admissao sintética), sem regra de negócio'],
];

$padroes = [
    'new DateTime[Immutable] relativo ao dia' => '/new\s+DateTime(?:Immutable)?\(\s*[\'"](?:today|yesterday|tomorrow|midnight)[\'"]/i',
    "date('Y-m-d') sem instante" => '/\bdate\(\s*[\'"]Y-m-d[\'"]\s*\)/',
    'date(...) com strtotime relativo' => '/\bdate\(\s*[\'"][^\'"]*[\'"]\s*,\s*strtotime\(\s*[\'"](?:today|now|tomorrow|yesterday|midnight|[+-]\d)/i',
    'strtotime relativo ao dia' => '/\bstrtotime\(\s*[\'"](?:today|now|tomorrow|yesterday|midnight|[+-]\d)/i',
    'CURDATE()/CURRENT_DATE' => '/\bCURDATE\s*\(\s*\)|\bCURRENT_DATE\b/i',
];

$achados = [];
$usadas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $arq) {
    if ($arq->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz) + 1));
    if (str_starts_with($rel, 'vendor/')) {
        continue;
    }
    // remove comentários (preservando as quebras de linha, para manter a numeração)
    $codigo = '';
    foreach (token_get_all((string)file_get_contents($arq->getPathname())) as $t) {
        if (is_array($t)) {
            $codigo .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1];
        } else {
            $codigo .= $t;
        }
    }
    foreach (explode("\n", $codigo) as $n => $linha) {
        foreach ($padroes as $nome => $re) {
            if (!preg_match($re, $linha)) {
                continue;
            }
            $liberada = false;
            foreach ($excecoes[$rel] ?? [] as $trecho => $motivo) {
                if (str_contains($linha, $trecho)) {
                    $liberada = true;
                    $usadas["{$rel}::{$trecho}"] = true;
                }
            }
            if (!$liberada) {
                $achados[] = sprintf('%s:%d [%s] %s', $rel, $n + 1, $nome, trim($linha));
            }
        }
    }
}

$falhas = [];
if ($achados !== []) {
    $falhas[] = count($achados) . " uso(s) do dia UTC/servidor como dia operacional:\n    " . implode("\n    ", $achados)
        . "\n    → use DateHelper::hojeOperacional()/hojeOperacionalSql()/diaOperacionalDe(), ou justifique em \$excecoes.";
}
foreach ($excecoes as $arquivo => $itens) {
    foreach ($itens as $trecho => $motivo) {
        if (!isset($usadas["{$arquivo}::{$trecho}"])) {
            $falhas[] = "exceção sem uso (remova): {$arquivo} => {$trecho}";
        }
    }
}
// o detector reconhece cada padrão (prova de que não está cego)
$cegos = [];
foreach (["new DateTimeImmutable('today')", "date('Y-m-d')", "strtotime('-90 days')", "date('Y-m-d', strtotime('today'))", 'CURDATE()', 'CURRENT_DATE'] as $a) {
    $achou = false;
    foreach ($padroes as $re) {
        $achou = $achou || (bool)preg_match($re, $a);
    }
    if (!$achou) {
        $cegos[] = $a;
    }
}
if ($cegos !== []) {
    $falhas[] = 'o detector não reconhece: ' . implode(', ', $cegos);
}
// e não acusa usos legítimos
foreach (["DateHelper::hojeOperacional()", "date('d/m/Y H:i', \$x)", 'NOW()', "new DateTimeImmutable('now')", "date('Y-m-d', strtotime(\$valor))"] as $ok) {
    foreach ($padroes as $re) {
        if (preg_match($re, $ok)) {
            $falhas[] = "o detector acusa um uso legítimo: {$ok}";
        }
    }
}

echo 'Exceções justificadas: ' . (count($excecoes, COUNT_RECURSIVE) - count($excecoes)) . "\n";
if ($falhas !== []) {
    fwrite(STDERR, implode("\n", $falhas) . "\n");
    exit(1);
}
echo "OK — nenhum uso do dia UTC como dia operacional em app/.\n";
