<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$method = new ReflectionMethod(AdminCandidaturasController::class, 'buildDownloadFilename');
$method->setAccessible(true);

$case1 = $method->invoke(null, [
    'nome' => 'João da Silva',
    'vaga_titulo' => 'Desenvolvedor PHP Pleno',
]);
if ($case1 !== 'Joao_da_Silva_Desenvolvedor_PHP_Pleno.pdf') {
    fwrite(STDERR, "Falha: nome básico inesperado: {$case1}\n");
    exit(1);
}

$case2 = $method->invoke(null, [
    'nome' => 'Ana María @ Souza',
    'vaga_titulo' => 'QA / Automação Sênior',
]);
if ($case2 !== 'Ana_Maria_Souza_QA_Automacao_Senior.pdf') {
    fwrite(STDERR, "Falha: sanitização com acento/símbolo inesperada: {$case2}\n");
    exit(1);
}

$case3 = $method->invoke(null, [
    'nome' => '  Pedro   ',
    'cargo_pretendido' => '  Analista de Dados  ',
]);
if ($case3 !== 'Pedro_Analista_de_Dados.pdf') {
    fwrite(STDERR, "Falha: fallback cargo_pretendido inesperado: {$case3}\n");
    exit(1);
}

$missingFailed = false;
try {
    $method->invoke(null, [
        'nome' => '',
        'vaga_titulo' => 'DevOps',
    ]);
} catch (Throwable $e) {
    $missingFailed = true;
}
if (!$missingFailed) {
    fwrite(STDERR, "Falha: campos ausentes deveriam disparar exceção.\n");
    exit(1);
}

$invalidFailed = false;
try {
    $method->invoke(null, [
        'nome' => '***',
        'vaga_titulo' => '###',
    ]);
} catch (Throwable $e) {
    $invalidFailed = true;
}
if (!$invalidFailed) {
    fwrite(STDERR, "Falha: campos inválidos deveriam disparar exceção.\n");
    exit(1);
}

echo "OK unit_download_filename\n";
