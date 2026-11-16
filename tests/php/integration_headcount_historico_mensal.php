<?php

/**
 * Integração — Headcount histórico (correção 2026-11-15). O Headcount de qualquer data é a FOTOGRAFIA do quadro nela:
 * contratos vigentes (admissão <= data, demissão nula ou >= data — o dia do desligamento é o último dia de vigência),
 * sem contar duas vezes a origem e o destino de uma transferência contínua. Antes, um mês histórico lia os contratos
 * brutos e somava origem + destino (240 no lugar do quadro real).
 *
 * Método: DELTA do painel real (PeopleAnalyticsService::montarPainel) antes/depois de inserir fixtures isoladas,
 * então os números esperados não dependem dos dados do banco.
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

$S = ColaboradorMetadadosElegibilidadeService::class;
$modoNatural = $S::modoSchema() === $S::MODO_NATURAL;
$sessaoOriginal = $_SESSION ?? [];
$fxA = new ExclusaoFixtures($pdo);
usleep(1100000); // sufixo (time()) diferente para a segunda empresa de fixture
$fxB = new ExclusaoFixtures($pdo);
$svc = new PeopleAnalyticsService();

$hoje = new DateTimeImmutable('today');
$mesAnt = $hoje->modify('first day of last month');
$d = static fn(DateTimeImmutable $base, string $mod): string => $base->modify($mod)->format('Y-m-d');
$fimMes = $mesAnt->modify('last day of this month');           // "31/10": último dia de um mês histórico
$iniMes = $mesAnt;
$headcount = static fn(DateTimeImmutable $ini, DateTimeImmutable $fim): int => $svc->montarPainel([], $ini, $fim)['headcount']['atual'];

try {
    $_SESSION = ['user_role' => 'admin', 'user_id' => 1];
    $S::reiniciarEstadoDaRequisicao();

    $pontos = [
        'fim_mes' => [$iniMes, $fimMes],
        'antes_mes' => [$iniMes->modify('-1 month'), $iniMes->modify('-1 day')],
        'dia_seguinte' => [$iniMes, $fimMes->modify('+1 day')],
        'meio_mes' => [$iniMes, $iniMes->modify('+14 days')],
        'hoje' => [$hoje->modify('first day of this month'), $hoje],
    ];
    $base = [];
    foreach ($pontos as $k => [$i, $f]) {
        $base[$k] = $headcount($i, $f);
    }

    // ---------------- contratos individuais (empresa A) ----------------
    $fxA->contrato('1', 'P1', 'ZZHM admitido antes, sem demissão', ['admissao' => $d($iniMes, '-400 days')]);
    $fxA->contrato('2', 'P2', 'ZZHM admitido durante o mês (dia 20)', ['admissao' => $d($iniMes, '+19 days')]);
    $fxA->contrato('3', 'P3', 'ZZHM admitido depois do mês', ['admissao' => $d($fimMes, '+3 days')]);
    $fxA->contrato('4', 'P4', 'ZZHM desligado antes do mês', ['admissao' => $d($iniMes, '-300 days'), 'demissao' => $d($iniMes, '-10 days'), 'ativo' => 0]);
    $fxA->contrato('5', 'P5', 'ZZHM desligado durante o mês (dia 10)', ['admissao' => $d($iniMes, '-300 days'), 'demissao' => $d($iniMes, '+9 days'), 'ativo' => 0]);
    $fxA->contrato('6', 'P6', 'ZZHM desligado no último dia do mês', ['admissao' => $d($iniMes, '-300 days'), 'demissao' => $fimMes->format('Y-m-d'), 'ativo' => 0]);
    $fxA->contrato('7', 'P7', 'ZZHM desligamento futuro', ['admissao' => $d($iniMes, '-300 days'), 'demissao' => $d($hoje, '+10 days'), 'ativo' => 0]);
    $fxA->contrato('8', 'P8', 'ZZHM sem admissão', ['admissao' => $d($iniMes, '-300 days')]);
    $pdo->prepare("UPDATE colaboradores_metadados SET admissao = NULL WHERE codigo_empresa = ? AND numero_contrato = '8'")->execute([$fxA->emp]);
    $fxA->contrato('9', 'P9', 'ZZHM pessoa com 2 contratos A', ['admissao' => $d($iniMes, '-500 days')]);
    $fxA->contrato('10', 'P9', 'ZZHM pessoa com 2 contratos B (simultâneo)', ['admissao' => $d($iniMes, '-100 days')]);
    $fxA->contrato('11', 'P11', 'ZZHM sem setor', ['admissao' => $d($iniMes, '-200 days')]);
    $pdo->prepare("UPDATE colaboradores_metadados SET codigo_setor = NULL, setor = NULL WHERE codigo_empresa = ? AND numero_contrato = '11'")->execute([$fxA->emp]);
    $fxA->contrato('12', 'P12', 'ZZHM ausente na origem (não é transferência)', ['admissao' => $d($iniMes, '-200 days')]);
    $pdo->prepare("UPDATE colaboradores_metadados SET ausente_na_origem = 1 WHERE codigo_empresa = ? AND numero_contrato = '12'")->execute([$fxA->emp]);
    if ($modoNatural) {
        $fxA->contrato('13', 'P13', 'ZZHM excluído analiticamente', ['admissao' => $d($iniMes, '-200 days')]);
        $fxA->excluir('13');
    }

    $depois = [];
    foreach ($pontos as $k => [$i, $f]) {
        $depois[$k] = $headcount($i, $f);
    }
    $delta = static fn(string $k): int => $depois[$k] - $base[$k];

    // Contas conferidas à mão (contrato → vigente?):
    //  fim do mês    : 1, 2 (admitido dia 20), 6 (desligado no último dia segue vigente), 7, 9, 10, 11, 12 (ausente: histórico mantém) = 8
    //  dia seguinte  : o contrato 6 sai = 7
    //  mês anterior (fim = dia anterior ao mês): 1, 5 (desligado só no dia 10), 6, 7, 9, 10, 11, 12 = 8
    //  intervalo até o dia 15: 1, 6, 7, 9, 10, 11, 12 = 7 (5 saiu no dia 10; 2 só entra no dia 20)
    //  hoje (ausente na origem sai; 5 e 6 já desligados): 1, 2, 7, 9, 10, 11 (+3 se já admitido) = 6 ou 7
    $check($delta('fim_mes') === 8, "(fim do mês) esperado 8; obtido {$delta('fim_mes')}");
    $check($delta('dia_seguinte') === 7, "(dia seguinte) o desligado no último dia deixa de contar: esperado 7; obtido {$delta('dia_seguinte')}");
    $check($delta('antes_mes') === 8, "(fim do mês anterior) esperado 8; obtido {$delta('antes_mes')}");
    $check($delta('meio_mes') === 7, "(intervalo personalizado até o dia 15) esperado 7; obtido {$delta('meio_mes')}");
    $esperadoHoje = 6 + (new DateTimeImmutable($d($fimMes, '+3 days')) <= $hoje ? 1 : 0);
    $check($delta('hoje') === $esperadoHoje, "(mês atual/hoje) ausente na origem sai, desligados saem: esperado {$esperadoHoje}; obtido {$delta('hoje')}");

    // admissão posterior nunca entra no mês anterior; demissão antes do mês nunca entra
    $check($delta('fim_mes') === $delta('dia_seguinte') + 1, 'a diferença entre o último dia e o dia seguinte é exatamente o contrato desligado no último dia');

    // ---------------- transferência contínua (empresa A origem, B destino) ----------------
    $cpf = '9' . substr($fxA->emp, 2) . '01';
    $antesTransf = [];
    foreach ($pontos as $k => [$i, $f]) {
        $antesTransf[$k] = $headcount($i, $f);
    }
    $fxA->contrato('20', 'P20', 'ZZHM transferido ORIGEM', ['admissao' => $d($iniMes, '-500 days'), 'cpf' => $cpf]);
    $fxB->contrato('21', 'P20', 'ZZHM transferido DESTINO', ['admissao' => $d($iniMes, '-500 days'), 'cpf' => $cpf]);
    $pdo->prepare("UPDATE colaboradores_metadados SET ausente_na_origem = 1 WHERE codigo_empresa = ? AND numero_contrato = '20'")->execute([$fxA->emp]);
    $dataTransf = $d($iniMes, '+9 days');
    $pdo->prepare("UPDATE colaboradores_metadados SET data_ultima_transferencia = ? WHERE codigo_empresa = ? AND numero_contrato = '21'")->execute([$dataTransf, $fxB->emp]);
    $pdo->prepare("UPDATE colaboradores_metadados SET data_ultima_transferencia = ? WHERE codigo_empresa = ? AND numero_contrato = '20'")->execute([$dataTransf, $fxA->emp]);

    foreach (['fim_mes', 'dia_seguinte', 'antes_mes', 'meio_mes'] as $k) {
        [$i, $f] = $pontos[$k];
        $d1 = $headcount($i, $f) - $antesTransf[$k];
        $check($d1 === 1, "(transferência contínua com data, ponto '{$k}') a pessoa conta UMA vez, não 0 nem 2; obtido {$d1}");
    }

    // sem data da transferência: origem sai; o destino carrega a admissão original → continua 1
    $pdo->prepare("UPDATE colaboradores_metadados SET data_ultima_transferencia = NULL WHERE codigo_empresa IN (?, ?) AND numero_contrato IN ('20', '21')")->execute([$fxA->emp, $fxB->emp]);
    foreach (['fim_mes', 'antes_mes'] as $k) {
        [$i, $f] = $pontos[$k];
        $d2 = $headcount($i, $f) - $antesTransf[$k];
        $check($d2 === 1, "(transferência contínua SEM data, ponto '{$k}') também conta uma vez; obtido {$d2}");
    }

    // ---------------- recontratação (rescisão real + novo contrato noutra empresa) ----------------
    $cpf2 = '8' . substr($fxA->emp, 2) . '02';
    $antesRec = $headcount($iniMes, $fimMes);
    $fxA->contrato('30', 'P30', 'ZZHM recontratado ORIGEM', ['admissao' => $d($iniMes, '-900 days'), 'demissao' => $d($iniMes, '-20 days'), 'ativo' => 0, 'cpf' => $cpf2, 'motivo' => '016']);
    $fxB->contrato('31', 'P30', 'ZZHM recontratado DESTINO', ['admissao' => $d($iniMes, '-15 days'), 'cpf' => $cpf2]);
    $check($headcount($iniMes, $fimMes) - $antesRec === 1, 'recontratação: no fim do mês só o contrato novo conta (o antigo foi desligado antes)');

    // ---------------- comparativo e consistência com o card "hoje" ----------------
    $painelMes = $svc->montarPainel([], $iniMes, $fimMes);
    $check(isset($painelMes['headcount']['comparativo']) && is_int($painelMes['headcount']['comparativo']), 'comparativo anual calculado como inteiro');
    $check(array_sum(array_column($painelMes['headcount_por_empresa'], 'quantidade')) <= $painelMes['headcount']['atual'], 'soma do Headcount por Empresa nunca excede o total (sem contrato contado duas vezes)');

    // ano completo (limitado a hoje pelo controller) e mês atual coincidem com a fotografia de hoje
    $anoCompleto = $headcount(new DateTimeImmutable($hoje->format('Y') . '-01-01'), $hoje);
    $check($anoCompleto === $headcount($hoje->modify('first day of this month'), $hoje), 'fim do intervalo = hoje: ano completo e mês atual dão a MESMA fotografia');

    // ---------------- Turnover: denominador permanece os "ativos do período" (definição congelada) ----------------
    $turn = $svc->montarPainel([], $iniMes, $fimMes)['turnover'];
    $check($turn['ativos_periodo'] >= $painelMes['headcount']['atual'], 'denominador do Turnover (ativos do período) nunca é menor que o headcount de fechamento');
} finally {
    $fxB->limpar();
    $fxA->limpar();
    $_SESSION = $sessaoOriginal;
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — Headcount histórico.\n";
