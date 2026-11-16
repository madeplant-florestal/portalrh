<?php

/**
 * Integração — Frente D4: o DIA OPERACIONAL (Campo Grande) comanda a vigência dos contratos, o Headcount atual, a listagem
 * de ativos/desligados e os períodos de dashboard, com relógio controlado — inclusive entre 20h e 23h59 locais, quando o
 * UTC já está no dia seguinte. Carimbos de instante continuam em UTC.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

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

$fx = new ExclusaoFixtures($pdo);
$cg = new DateTimeZone('America/Campo_Grande');
$svc = new PeopleAnalyticsService();
$repo = new ColaboradorMetadadosConsultaRepository();
$ctrl = (new ReflectionClass('AdminController'))->newInstanceWithoutConstructor();
$resolver = new ReflectionMethod($ctrl, 'resolverPeriodo');
$resolver->setAccessible(true);

$painel = static function (DateTimeImmutable $ini, DateTimeImmutable $fim) use ($svc): int {
    return $svc->montarPainel([], $ini, $fim)['headcount']['atual'];
};

try {
    $S = ColaboradorMetadadosElegibilidadeService::class;
    $_SESSION = ['user_role' => 'admin', 'user_id' => 1];
    $S::reiniciarEstadoDaRequisicao();

    $relogios = [
        'dia15_21h30' => new DateTimeImmutable('2026-11-15 21:30:00', $cg),   // UTC: 16/11 01:30
        'dia16_00h00' => new DateTimeImmutable('2026-11-16 00:00:00', $cg),   // UTC: 16/11 04:00
    ];

    // baseline por relógio (antes das fixtures)
    $base = [];
    foreach ($relogios as $k => $inst) {
        DateHelper::fixarRelogioParaTeste($inst);
        [$ini, $fim] = $resolver->invoke($ctrl, 'mes_atual');
        $base[$k] = $painel($ini, $fim);
    }

    // contratos: desligado em 15/11 (último dia de vigência), vigente sem demissão, admitido em 16/11, desligado em 14/11
    $fx->contrato('1', 'P1', 'ZZDO demissao 15/11', ['admissao' => '2025-01-10', 'demissao' => '2026-11-15', 'ativo' => 0]);
    $fx->contrato('2', 'P2', 'ZZDO vigente', ['admissao' => '2025-01-10']);
    $fx->contrato('3', 'P3', 'ZZDO admitido 16/11', ['admissao' => '2026-11-16']);
    $fx->contrato('4', 'P4', 'ZZDO demissao 14/11', ['admissao' => '2025-01-10', 'demissao' => '2026-11-14', 'ativo' => 0]);

    // --- 15/11 às 21h30 locais (UTC já é 16/11): "hoje" ainda é 15/11 ---
    DateHelper::fixarRelogioParaTeste($relogios['dia15_21h30']);
    [$ini, $fim] = $resolver->invoke($ctrl, 'mes_atual');
    $check($fim->format('Y-m-d') === '2026-11-15', 'período "mês atual" termina em 15/11 às 21h30 locais (não em 16/11)');
    [, $fimAno] = $resolver->invoke($ctrl, 'ano_atual');
    $check($fimAno->format('Y-m-d') === '2026-11-15', 'período "ano atual" termina em 15/11');
    [, $fimMesFuturo] = $resolver->invoke($ctrl, 'mes', ['ano' => '2026', 'mes' => '11']);
    $check($fimMesFuturo->format('Y-m-d') === '2026-11-15', 'mês específico (atual) limitado ao dia operacional 15/11');
    [, $fimPers] = $resolver->invoke($ctrl, 'personalizado', ['data_inicio' => '2026-11-01', 'data_fim' => '2026-12-31']);
    $check($fimPers->format('Y-m-d') === '2026-11-15', 'intervalo personalizado com fim futuro é limitado a 15/11');
    $check($painel($ini, $fim) - $base['dia15_21h30'] === 2, 'Headcount atual (15/11 21h30): vigente + desligado EM 15/11 (último dia) = +2');
    $ativos = array_column($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos']), 'nome');
    $deslig = array_column($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'desligados']), 'nome');
    $check(count($ativos) === 2 && in_array('ZZDO demissao 15/11', $ativos, true) && in_array('ZZDO vigente', $ativos, true), 'listagem de ativos (15/11 21h30): inclui quem sai em 15/11 e exclui o admitido em 16/11');
    $check($deslig === ['ZZDO demissao 14/11'], 'listagem de desligados (15/11 21h30): só o desligado em 14/11');
    $check($repo->summary([$fx->codigoSetorPadrao ?? 'ZXST'])['ativos'] >= 2, 'summary()[ativos] usa o mesmo dia operacional');

    // --- 16/11 às 00h00 locais: o dia virou ---
    DateHelper::fixarRelogioParaTeste($relogios['dia16_00h00']);
    [$ini, $fim] = $resolver->invoke($ctrl, 'mes_atual');
    $check($fim->format('Y-m-d') === '2026-11-16', 'período "mês atual" passa a terminar em 16/11 à meia-noite local');
    $check($painel($ini, $fim) - $base['dia16_00h00'] === 2, 'Headcount atual (16/11 00h00): vigente + admitido em 16/11 = +2; desligado em 15/11 já saiu');
    $ativos = array_column($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos']), 'nome');
    $deslig = array_column($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'desligados']), 'nome');
    $check(!in_array('ZZDO demissao 15/11', $ativos, true) && in_array('ZZDO admitido 16/11', $ativos, true), 'listagem de ativos (16/11 00h00): sai o desligado em 15/11 e entra o admitido em 16/11');
    $check(in_array('ZZDO demissao 15/11', $deslig, true) && in_array('ZZDO demissao 14/11', $deslig, true) && count($deslig) === 2, 'listagem de desligados (16/11 00h00): 15/11 e 14/11');

    // --- cartão e listagem coerentes no mesmo relógio ---
    DateHelper::fixarRelogioParaTeste($relogios['dia15_21h30']);
    $check(count($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos'])) === $repo->paginateExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos'], 1, 25)['total'], 'contador, paginação e exportação coerentes no dia operacional');

    // instantes continuam em UTC
    $check(DateHelper::agoraUtcSql() === '2026-11-16 01:30:00', 'carimbo de instante às 21h30 locais = 01:30:00 UTC do dia 16 (persistência em UTC)');
} finally {
    DateHelper::fixarRelogioParaTeste(null);
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — D4 vigência e períodos no dia operacional.\n";
