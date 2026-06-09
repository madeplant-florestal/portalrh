<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$case1 = Phone::digits('(67) 99169-9831');
if ($case1 !== '67991699831') {
    fwrite(STDERR, "Falha: remoção de caracteres inesperada: {$case1}\n");
    exit(1);
}

$case2 = Phone::digits('+55 (67) 99169-9831');
if ($case2 !== '67991699831') {
    fwrite(STDERR, "Falha: remoção de +55 inesperada: {$case2}\n");
    exit(1);
}

$case3 = Phone::normalize('679999-9999');
if ($case3 !== null) {
    fwrite(STDERR, "Falha: telefone inválido deveria ser rejeitado.\n");
    exit(1);
}

$case4 = Phone::format('67991699831');
if ($case4 !== '(67) 99169-9831') {
    fwrite(STDERR, "Falha: máscara inesperada: {$case4}\n");
    exit(1);
}

$case5 = Phone::format('  abc  ');
if ($case5 !== 'abc') {
    fwrite(STDERR, "Falha: fallback inesperado: {$case5}\n");
    exit(1);
}

echo "OK unit_phone\n";
