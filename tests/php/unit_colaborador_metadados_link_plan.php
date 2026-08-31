<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

// Todos os dados são fictícios. buildPlanFromReconciliation()/validatePlan()/planHash() nunca
// tocam banco — só apply()/revert()/validateAgainstDatabase() tocam, e não são chamados aqui.
$service = new ColaboradorMetadadosLinkService();

function reconciliacaoResultado(int $colaboradorId, string $classificacao, ?int $metadadosId = null): array
{
    return [
        'colaborador_id' => $colaboradorId,
        'classificacao' => $classificacao,
        'metadados_id_candidato' => $metadadosId,
        'quantidade_candidatos' => $metadadosId !== null ? 1 : 0,
        'motivo_classificacao' => 'fixture de teste',
    ];
}

$C = ColaboradorMetadadosReconciliationService::class;

// Caso 1/2/3/4 — só CORRESPONDENCIA_SEGURA entra no plano; as demais são excluídas.
$resultados = [
    reconciliacaoResultado(1, $C::SEGURA, 101),
    reconciliacaoResultado(2, $C::PROVAVEL, 102),
    reconciliacaoResultado(3, $C::AMBIGUA),
    reconciliacaoResultado(4, $C::SEM_CORRESPONDENCIA),
    reconciliacaoResultado(5, $C::CONFLITO, 105),
];
$plano = $service->buildPlanFromReconciliation($resultados);
$assert(count($plano) === 1, 'Caso 1-4: o plano deveria conter só 1 item (o SEGURA).');
$assert($plano[0]['colaborador_id'] === 1 && $plano[0]['metadados_id'] === 101, 'Caso 1: item do plano deveria ser o colaborador 1 / metadados 101.');
foreach ($plano as $item) {
    $assert($item['classificacao'] === $C::SEGURA, 'Caso 2-4: nenhum item fora de SEGURA deveria estar no plano.');
}

$validacaoOk = $service->validatePlan($plano, $resultados);
$assert($validacaoOk['ok'] === true, 'Caso 1-4: plano só com SEGURA e sem JA_VINCULADO deveria validar OK: ' . implode('; ', $validacaoOk['errors']));

// Caso 5 — JA_VINCULADO em qualquer lugar do cenário bloqueia a primeira aplicação inteira.
$resultadosComJaVinculado = array_merge($resultados, [reconciliacaoResultado(6, $C::JA_VINCULADO, 106)]);
$planoComJaVinculado = $service->buildPlanFromReconciliation($resultadosComJaVinculado);
$validacaoJaVinculado = $service->validatePlan($planoComJaVinculado, $resultadosComJaVinculado);
$assert($validacaoJaVinculado['ok'] === false, 'Caso 5: presença de JA_VINCULADO deveria bloquear a validação do plano.');

// Caso 6 — dois colaboradores apontando para o mesmo metadados_id bloqueiam o plano.
$resultadosMetadadosDuplicado = [
    reconciliacaoResultado(10, $C::SEGURA, 500),
    reconciliacaoResultado(11, $C::SEGURA, 500),
];
$planoMetadadosDuplicado = $service->buildPlanFromReconciliation($resultadosMetadadosDuplicado);
$validacaoMetadadosDuplicado = $service->validatePlan($planoMetadadosDuplicado, $resultadosMetadadosDuplicado);
$assert($validacaoMetadadosDuplicado['ok'] === false, 'Caso 6: metadados_id duplicado no plano deveria bloquear a validação.');

// Caso 7 — mesmo colaborador_id duplicado no plano bloqueia (construção manual, não ocorre via
// buildPlanFromReconciliation em uso normal, mas validatePlan precisa detectar de qualquer forma).
$planoColaboradorDuplicado = [
    ['colaborador_id' => 20, 'metadados_id' => 600, 'classificacao' => $C::SEGURA, 'motivo_classificacao' => 'x'],
    ['colaborador_id' => 20, 'metadados_id' => 601, 'classificacao' => $C::SEGURA, 'motivo_classificacao' => 'x'],
];
$validacaoColaboradorDuplicado = $service->validatePlan($planoColaboradorDuplicado, $planoColaboradorDuplicado);
$assert($validacaoColaboradorDuplicado['ok'] === false, 'Caso 7: colaborador_id duplicado no plano deveria bloquear a validação.');

// Caso 8 — hash do plano é determinístico independentemente da ordem original dos itens.
$planoOrdemA = [
    ['colaborador_id' => 3, 'metadados_id' => 303],
    ['colaborador_id' => 1, 'metadados_id' => 101],
    ['colaborador_id' => 2, 'metadados_id' => 202],
];
$planoOrdemB = [
    ['colaborador_id' => 1, 'metadados_id' => 101],
    ['colaborador_id' => 2, 'metadados_id' => 202],
    ['colaborador_id' => 3, 'metadados_id' => 303],
];
$hashA = ColaboradorMetadadosLinkService::planHash($planoOrdemA);
$hashB = ColaboradorMetadadosLinkService::planHash($planoOrdemB);
$assert($hashA === $hashB, 'Caso 8: hash deveria ser igual independente da ordem original dos itens.');
$assert(strlen($hashA) === 64, 'Caso 8: hash deveria ser SHA-256 (64 caracteres hex).');

// Extra — plano vazio é uma entrada válida (nenhum SEGURA no cenário) e não deveria travar validatePlan.
$validacaoVazia = $service->validatePlan([], []);
$assert($validacaoVazia['ok'] === true, 'Extra: plano vazio, sem JA_VINCULADO, deveria validar OK.');

echo "OK unit_colaborador_metadados_link_plan\n";
