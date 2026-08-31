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

// Caso 5 (Fase 3.5 — aplicação complementar) — JA_VINCULADO presente no cenário, SEM colidir com
// nenhum metadados_id do novo plano, NÃO bloqueia. A mera presença de vínculos já existentes é o
// estado normal de uma aplicação incremental (ex.: 378 já vinculados + 4 novos seguros) — nunca
// motivo de bloqueio por si só.
$resultadosComJaVinculado = array_merge($resultados, [reconciliacaoResultado(6, $C::JA_VINCULADO, 106)]);
$planoComJaVinculado = $service->buildPlanFromReconciliation($resultadosComJaVinculado);
$assert(count($planoComJaVinculado) === 1, 'Caso 5: JA_VINCULADO nunca deveria entrar no plano (buildPlanFromReconciliation já filtra).');
$validacaoJaVinculado = $service->validatePlan($planoComJaVinculado, $resultadosComJaVinculado);
$assert($validacaoJaVinculado['ok'] === true, 'Caso 5: JA_VINCULADO sem colisão de metadados_id não deveria bloquear a aplicação complementar: ' . implode('; ', $validacaoJaVinculado['errors']));

// Caso 5b (Fase 3.5) — JA_VINCULADO cujo metadados_id COLIDE com um item do novo plano bloqueia —
// essa é a invariante real que protege vínculos já existentes (nenhum pode ser sobrescrito).
$resultadosComColisaoJaVinculado = array_merge($resultados, [reconciliacaoResultado(7, $C::JA_VINCULADO, 101)]); // 101 = mesmo metadados_id do colaborador 1 (SEGURA)
$planoComColisaoJaVinculado = $service->buildPlanFromReconciliation($resultadosComColisaoJaVinculado);
$validacaoColisaoJaVinculado = $service->validatePlan($planoComColisaoJaVinculado, $resultadosComColisaoJaVinculado);
$assert($validacaoColisaoJaVinculado['ok'] === false, 'Caso 5b: plano tentando usar um metadados_id que já pertence a um JA_VINCULADO deveria bloquear.');

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

// Caso 6 (Fase 3.5) — cenário com TODOS já vinculados (nenhum SEGURA restante, simulando o estado
// depois que uma aplicação complementar esgota os candidatos) produz plano vazio e válido, sem
// exigir nenhuma escrita.
$resultadosTodosVinculados = [
    reconciliacaoResultado(30, $C::JA_VINCULADO, 900),
    reconciliacaoResultado(31, $C::JA_VINCULADO, 901),
    reconciliacaoResultado(32, $C::JA_VINCULADO, 902),
    reconciliacaoResultado(33, $C::SEM_CORRESPONDENCIA),
    reconciliacaoResultado(34, $C::CONFLITO, 903),
];
$planoTodosVinculados = $service->buildPlanFromReconciliation($resultadosTodosVinculados);
$assert($planoTodosVinculados === [], 'Caso 6: com todos já vinculados/sem correspondência/conflito, o plano deveria ficar vazio.');
$validacaoTodosVinculados = $service->validatePlan($planoTodosVinculados, $resultadosTodosVinculados);
$assert($validacaoTodosVinculados['ok'] === true, 'Caso 6: plano vazio depois de esgotar os candidatos seguros deveria validar OK, sem exigir escrita.');

echo "OK unit_colaborador_metadados_link_plan\n";
