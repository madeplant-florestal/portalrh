<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

// --- Protocolo: AAAAMM (de created_at) + id zero-padded, sem truncar acima de 4 dígitos ---
// Movido de HomeController para Candidatura::formatProtocol() (Sprint 005): agora é reutilizado
// também pelo payload do webhook recrutamento.candidatura.criada, não só pela tela de confirmação.
$case1 = Candidatura::formatProtocol(83, '2026-07-11 09:42:00');
if ($case1 !== '202607-0083') {
    fwrite(STDERR, "Falha: protocolo com id < 4 dígitos inesperado: {$case1}\n");
    exit(1);
}

$case2 = Candidatura::formatProtocol(1427, '2026-07-11 09:42:00');
if ($case2 !== '202607-1427') {
    fwrite(STDERR, "Falha: protocolo com id de 4 dígitos inesperado: {$case2}\n");
    exit(1);
}

$case3 = Candidatura::formatProtocol(12034, '2026-07-11 09:42:00');
if ($case3 !== '202607-12034') {
    fwrite(STDERR, "Falha: protocolo com id > 4 dígitos foi truncado: {$case3}\n");
    exit(1);
}

$case4 = Candidatura::formatProtocol(5, '2025-01-03 23:59:00');
if ($case4 !== '202501-0005') {
    fwrite(STDERR, "Falha: protocolo não usou mês/ano de created_at: {$case4}\n");
    exit(1);
}

// --- Primeiro nome: trim + colapso de espaços + fallback neutro ---
$firstNameOf = new ReflectionMethod(HomeController::class, 'firstNameOf');
$firstNameOf->setAccessible(true);

$nameCase1 = $firstNameOf->invoke(null, '  Julia   Souza Lima  ');
if ($nameCase1 !== 'Julia') {
    fwrite(STDERR, "Falha: extração de primeiro nome com espaços duplicados inesperada: {$nameCase1}\n");
    exit(1);
}

$nameCase2 = $firstNameOf->invoke(null, '');
if ($nameCase2 !== 'Candidato') {
    fwrite(STDERR, "Falha: fallback de nome vazio inesperado: {$nameCase2}\n");
    exit(1);
}

$nameCase3 = $firstNameOf->invoke(null, '     ');
if ($nameCase3 !== 'Candidato') {
    fwrite(STDERR, "Falha: fallback de nome só com espaços inesperado: {$nameCase3}\n");
    exit(1);
}

// --- Data/hora: formatação sem passar por DateTime/timezone implícito ---
$dtCase1 = DateHelper::formatBrazilianDateTime('2026-07-11 09:42:00');
if ($dtCase1 !== '11/07/2026 às 09:42') {
    fwrite(STDERR, "Falha: formatação de data/hora inesperada: {$dtCase1}\n");
    exit(1);
}

$dtCase2 = DateHelper::formatBrazilianDateTime('');
if ($dtCase2 !== '') {
    fwrite(STDERR, "Falha: formatação de data/hora vazia deveria retornar string vazia: {$dtCase2}\n");
    exit(1);
}

// --- View de confirmação: frase antiga removida + novos blocos presentes ---
$view = new View();
$paramsConfirmacao = [
    'base' => '',
    'vaga' => ['titulo' => 'Analista de Departamento Pessoal'],
    'cid' => 83,
    'protocolo' => '202607-0083',
    'primeiroNome' => 'Julia',
    'dataHoraCandidatura' => '11/07/2026 às 09:42',
    'emailSent' => true,
];
$html = $view->renderPartial('home/confirm', $paramsConfirmacao);

if (strpos($html, 'Em breve o RH entrará em contato.') !== false) {
    fwrite(STDERR, "Falha: frase removida ainda presente na página de confirmação.\n");
    exit(1);
}
if (strpos($html, 'Olá, Julia!') === false) {
    fwrite(STDERR, "Falha: saudação personalizada ausente.\n");
    exit(1);
}
if (strpos($html, '202607-0083') === false) {
    fwrite(STDERR, "Falha: protocolo formatado ausente na página.\n");
    exit(1);
}
if (strpos($html, '11/07/2026 às 09:42') === false) {
    fwrite(STDERR, "Falha: data/hora da candidatura ausente na página.\n");
    exit(1);
}

// --- noindex: só quando a view pede explicitamente, sem vazar para outras páginas ---
$layoutComNoIndex = $view->renderPartial('layouts/main', array_merge($paramsConfirmacao, [
    'content' => $html,
    'noIndex' => true,
]));
if (strpos($layoutComNoIndex, 'name="robots" content="noindex,nofollow"') === false) {
    fwrite(STDERR, "Falha: meta noindex ausente quando noIndex=true.\n");
    exit(1);
}

$layoutSemNoIndex = $view->renderPartial('layouts/main', array_merge($paramsConfirmacao, [
    'content' => '<div>Vagas</div>',
]));
if (strpos($layoutSemNoIndex, 'noindex') !== false) {
    fwrite(STDERR, "Falha: meta noindex vazou para uma página que não deveria tê-la.\n");
    exit(1);
}

echo "OK unit_confirmacao_candidatura\n";
