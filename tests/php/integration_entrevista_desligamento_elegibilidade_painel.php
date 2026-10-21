<?php

/**
 * Integração — correção 2026-10: `EntrevistaDesligamentoRepository::contarElegiveis()` lançava
 * `SQLSTATE[42S22]: Unknown column 'edl.id'` porque `condicoesElegiveis()` passou a referenciar
 * `edl.id`/`edl.cancelada_em` (liberação manual, OR com desligamento oficial) sem que esta query
 * tivesse o `LEFT JOIN entrevista_desligamento_liberacoes edl` correspondente — `listarElegiveis()`
 * nunca teve esse problema porque usa `SELECT_CONTRATO`, que já inclui o JOIN.
 *
 * Efeito em produção: `EntrevistaDesligamentoService::montarPainel()` chama `contarElegiveis()`
 * incondicionalmente (para `elegiveis_total`) — a exceção propagava até
 * `AdminEntrevistaDesligamentoController::index()`, que a capturava e mostrava a mensagem genérica
 * de erro com TODAS as abas zeradas, mesmo havendo desligados reais e elegíveis (ex.: contrato 272,
 * Raphael Mendes Lazaro Correa, desligado 2 dias antes "hoje" no caso relatado).
 *
 * A regra de elegibilidade em si (`condicoesElegiveis()` em SQL e `elegibilidade()` no Service) já
 * estava correta — OR entre desligamento oficial e liberação manual, nunca AND. Esta correção é
 * só a query de contagem que faltava o JOIN.
 *
 * Casos cobertos (pedido da correção):
 *   A) desligado recente, dentro do período, SEM liberação manual -> elegível;
 *   B) ativo (sem demissão), COM liberação manual ativa -> elegível (liberação nunca substitui o
 *      desligamento oficial, mas também nunca o impede — é um segundo caminho válido);
 *   C) ativo, SEM liberação -> NÃO elegível;
 *   D) já tem Entrevista criada -> não aparece de novo em Elegíveis;
 *   E) desligado fora do período de 90 dias -> some do filtro de 90 dias, aparece sem filtro;
 *   F) `montarPainel()` nunca lança exceção e as contagens refletem os dados reais.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados' => [], 'liberacoes' => [], 'entrevistas' => []];
$emp = 'ZE' . $suffix;
$busca = 'ZZEDP_' . $suffix;
$D = static fn(int $diasAtras): string => (new DateTimeImmutable('today'))->modify('-' . $diasAtras . ' days')->format('Y-m-d');
$agora = new DateTimeImmutable('today');

$mk = static function (string $nome, string $adm, ?string $dem, ?string $motivo) use ($pdo, &$criados, $suffix, $emp, $busca): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZEDP_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, 'ZU' . $suffix, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null,
        $busca . ' ' . $nome, 'ZZEDP Empresa ' . $suffix, 'ZZEDP Unidade',
        'ZZEDP Cargo', 'ZZEDPCG' . $suffix, $adm, $dem, $motivo, $motivo === null ? null : 'Motivo ' . $motivo, $dem === null ? 1 : 0, 'zzedp-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$repo = new EntrevistaDesligamentoRepository();
$service = new EntrevistaDesligamentoService($repo);
$criterios = EntrevistaDesligamentoService::criteriosElegibilidade($agora);

try {
    // ---- A: desligado 2 dias atrás, sem liberação manual ----------------------------------------
    $cA = $mk('Caso A Recente', $D(400), $D(2), '021');

    // ---- B: ativo (sem demissão), COM liberação manual ativa ------------------------------------
    $cB = $mk('Caso B Liberado', $D(400), null, null);
    $repo->liberar($cB, 1, (new DateTimeImmutable())->format('Y-m-d H:i:s'));
    $criados['liberacoes'][] = $cB;

    // ---- C: ativo, sem liberação ------------------------------------------------------------------
    $cC = $mk('Caso C Ativo', $D(400), null, null);

    // ---- D: desligado recente, mas já tem Entrevista criada --------------------------------------
    $cD = $mk('Caso D Ja Tem Entrevista', $D(400), $D(5), '021');
    $service->gerar($cD, 1, $agora);
    $criados['entrevistas'][] = $cD;

    // ---- E: desligado há 200 dias (fora do filtro de 90, dentro de um filtro maior) --------------
    $cE = $mk('Caso E Antigo', $D(600), $D(200), '021');

    // =============================================================== F: montarPainel() não quebra
    $painel = null;
    $erroPainel = null;
    try {
        $painel = $service->montarPainel(['visao' => 'elegiveis', 'busca' => $busca], $agora);
    } catch (Throwable $e) {
        $erroPainel = $e->getMessage();
    }
    $check($erroPainel === null, 'F) montarPainel() não lança exceção (antes: "Unknown column \'edl.id\'"' . ($erroPainel !== null ? " — erro real: {$erroPainel}" : '') . ')');
    $check($painel !== null && is_array($painel['itens']), 'F) montarPainel() devolve itens, não null');

    // =============================================================== A/B/C/D via listarElegiveis (sem filtro de dias)
    $elegiveis = $repo->listarElegiveis($criterios, ['busca' => $busca], 50);
    $idsElegiveis = array_map(static fn(array $r): int => (int)$r['metadados_id'], $elegiveis);

    $check(in_array($cA, $idsElegiveis, true), 'A) Desligado recente (2 dias), SEM liberação manual, aparece em Elegíveis');
    $check(in_array($cB, $idsElegiveis, true), 'B) Ativo COM liberação manual ativa aparece em Elegíveis (liberação é caminho complementar, nunca bloqueio)');
    $check(!in_array($cC, $idsElegiveis, true), 'C) Ativo SEM liberação NÃO aparece em Elegíveis — "ativo" nunca é critério de elegibilidade');
    $check(!in_array($cD, $idsElegiveis, true), 'D) Contrato que já tem Entrevista criada não aparece de novo em Elegíveis');
    $check(in_array($cE, $idsElegiveis, true), 'E) Desligado há 200 dias aparece quando não há filtro de período');

    // ---- contarElegiveis() não lança mais, e a contagem é consistente com a lista ----------------
    $totalSemFiltro = null;
    $erroContagem = null;
    try {
        $totalSemFiltro = $repo->contarElegiveis($criterios, ['busca' => $busca]);
    } catch (Throwable $e) {
        $erroContagem = $e->getMessage();
    }
    $check($erroContagem === null, 'contarElegiveis() não lança exceção' . ($erroContagem !== null ? " — erro real: {$erroContagem}" : ''));
    $check($totalSemFiltro === count($elegiveis), 'contarElegiveis() concorda exatamente com listarElegiveis() (mesmo WHERE, agora com o mesmo JOIN)');

    // ---- E (período): com filtro de 90 dias, o Caso E (200 dias) desaparece; A/B continuam -------
    $elegiveis90 = $repo->listarElegiveis($criterios, ['busca' => $busca, 'dias' => 90], 50);
    $ids90 = array_map(static fn(array $r): int => (int)$r['metadados_id'], $elegiveis90);
    $check(!in_array($cE, $ids90, true), 'E) Com filtro de 90 dias, desligamento de 200 dias atrás NÃO aparece');
    $check(in_array($cA, $ids90, true), 'E) Com filtro de 90 dias, desligamento de 2 dias atrás (Caso A) continua aparecendo');
    $check($repo->contarElegiveis($criterios, ['busca' => $busca, 'dias' => 90]) === count($elegiveis90), 'E) contarElegiveis() com filtro de dias também concorda com a lista');

    // ---- regra de elegibilidade em PHP (Service), mesma consistência ------------------------------
    $contratoA = $repo->buscarContrato($cA);
    $contratoB = $repo->buscarContrato($cB);
    $contratoC = $repo->buscarContrato($cC);
    $check(EntrevistaDesligamentoService::elegibilidade($contratoA, $agora)['elegivel'] === true, 'A) elegibilidade() concorda: desligado oficial = elegível');
    $check(EntrevistaDesligamentoService::elegibilidade($contratoB, $agora)['elegivel'] === true, 'B) elegibilidade() concorda: liberação manual ativa = elegível mesmo sem desligamento');
    $check(EntrevistaDesligamentoService::elegibilidade($contratoC, $agora)['elegivel'] === false, 'C) elegibilidade() concorda: ativo sem liberação = não elegível');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nENTREVISTA_DESLIGAMENTO_ELEGIBILIDADE_PAINEL_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['entrevistas'] as $mid) {
        $pdo->prepare('DELETE FROM entrevistas_desligamento_fatores WHERE entrevista_id IN (SELECT id FROM entrevistas_desligamento WHERE metadados_id = ?)')->execute([$mid]);
        $pdo->prepare('DELETE FROM entrevistas_desligamento WHERE metadados_id = ?')->execute([$mid]);
    }
    foreach ($criados['liberacoes'] as $mid) {
        $pdo->prepare('DELETE FROM entrevista_desligamento_liberacoes WHERE metadados_id = ?')->execute([$mid]);
    }
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
}

exit($exit ?? 0);
