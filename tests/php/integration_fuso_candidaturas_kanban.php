<?php

/**
 * Integração — Frente D / D1: candidaturas, Kanban e histórico. UTC persistido, Campo Grande apresentado.
 * Prova: sessão do banco em UTC; instante gravado às 13:30 UTC aparece como 09:30 (candidatura, movimentação do
 * Kanban e histórico de comunicação); ordenação cronológica; filtros por dia local (cohort do dashboard); e que
 * nenhuma view formata instantes sem conversão (cobertura estática).
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
$movs = [];
$coms = [];
try {
    // sessão do banco em UTC (política) e PHP em UTC (bootstrap) — em qualquer servidor
    $check($pdo->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'sessão do banco fixada em UTC (+00:00)');
    $check(date_default_timezone_get() === 'UTC', 'fuso padrão do PHP fixado em UTC pelo bootstrap');

    $empresa = $fx->empresaOficial();
    $vaga = $fx->vaga('ZZFZ vaga', null);
    $pdo->prepare('UPDATE vagas SET empresa_id = ? WHERE id = ?')->execute([$empresa, $vaga]);

    // (1-3) candidatura às 09h30 de Campo Grande = 13:30 UTC gravada na coluna TIMESTAMP
    $cand = $fx->candidatura($vaga, 'ZZFZ Candidato 0930');
    $pdo->prepare('UPDATE candidaturas SET created_at = ? WHERE id = ?')->execute(['2026-11-13 13:30:00', $cand]);
    $lido = (string)$pdo->query("SELECT created_at FROM candidaturas WHERE id = {$cand}")->fetchColumn();
    $check($lido === '2026-11-13 13:30:00', 'TIMESTAMP gravado como 13:30 UTC é lido como 13:30:00 (sessão UTC, sem deslocamento)');
    $check(DateHelper::formatLocal($lido) === '13/11/2026 09:30', 'candidatura exibida como 09:30 para o RH');
    $check(Candidatura::find($cand) !== null && DateHelper::formatLocal((string)Candidatura::find($cand)['created_at']) === '13/11/2026 09:30', 'Candidatura::find → exibição 09:30');

    // Kanban: movimentações em instantes que atravessam a meia-noite UTC
    $mov1 = $fx->inserirGenerico('pipeline_movements', ['candidatura_id' => $cand, 'created_at' => '2026-11-13 23:30:00']);
    $mov2 = $fx->inserirGenerico('pipeline_movements', ['candidatura_id' => $cand, 'created_at' => '2026-11-14 01:15:00']);
    $movs = [$mov1, $mov2];
    $rows = $pdo->query("SELECT id, created_at FROM pipeline_movements WHERE candidatura_id = {$cand} ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    $exib = array_map(static fn($r) => DateHelper::formatLocal($r['created_at']), $rows);
    $check($exib === ['13/11/2026 19:30', '13/11/2026 21:15'], 'histórico do Kanban: 23:30 e 01:15 UTC viram 19:30 e 21:15 do MESMO dia local (virada de dia UTC)');
    $check((strtotime($rows[1]['created_at'] . ' UTC') - strtotime($rows[0]['created_at'] . ' UTC')) === 6300, 'duração entre movimentações = 1 h 45 min (6300 s) preservada');
    $check($rows[0]['id'] === (string)$mov1 || (int)$rows[0]['id'] === $mov1, 'ordenação cronológica preservada');

    // histórico de comunicação (TIMESTAMP + DATETIME via NOW())
    $com = $fx->inserirGenerico('comunicacoes', ['candidatura_id' => $cand, 'created_at' => '2026-11-13 14:00:00']);
    $coms[] = $com;
    $pdo->prepare('UPDATE comunicacoes SET enviada_em = ? WHERE id = ?')->execute(['2026-11-13 14:05:00', $com]);
    $c = $pdo->query("SELECT created_at, enviada_em FROM comunicacoes WHERE id = {$com}")->fetch(PDO::FETCH_ASSOC);
    $check(DateHelper::formatLocal($c['created_at']) === '13/11/2026 10:00' && DateHelper::formatLocal($c['enviada_em']) === '13/11/2026 10:05', 'comunicação: criada 10:00 e enviada 10:05 locais');

    // NOW() do banco é UTC: diferença de NOW() para o relógio do PHP em UTC é desprezível (mesma referência)
    $agoraBanco = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $check(abs(strtotime($agoraBanco . ' UTC') - strtotime(DateHelper::agoraUtcSql() . ' UTC')) <= 3, 'NOW() do banco e agoraUtc() do PHP são o mesmo instante (sem desvio)');

    // (13) cohort do dashboard por dia LOCAL: 02:00 UTC do dia 14 = 22:00 do dia 13 local → pertence ao dia 13
    $cTarde = $fx->candidatura($vaga, 'ZZFZ Candidato 22h');
    $pdo->prepare('UPDATE candidaturas SET created_at = ? WHERE id = ?')->execute(['2026-11-14 02:00:00', $cTarde]);
    $cBorda = $fx->candidatura($vaga, 'ZZFZ Candidato 00h');
    $pdo->prepare('UPDATE candidaturas SET created_at = ? WHERE id = ?')->execute(['2026-11-14 04:00:00', $cBorda]); // 00:00 do dia 14 local
    $cAntes = $fx->candidatura($vaga, 'ZZFZ Candidato 23h dia 12');
    $pdo->prepare('UPDATE candidaturas SET created_at = ? WHERE id = ?')->execute(['2026-11-13 03:59:59', $cAntes]); // 23:59:59 do dia 12 local
    $repo = new RecrutamentoIndicadoresRepository();
    $dia13 = array_map(static fn($r) => (int)$r['id'], $repo->buscarCandidaturasCohort(new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-13'), $empresa, null));
    $check(in_array($cand, $dia13, true) && in_array($cTarde, $dia13, true), 'dia 13 local inclui 13:30 UTC (09:30) e 02:00 UTC do dia 14 (22:00 local)');
    $check(!in_array($cBorda, $dia13, true) && !in_array($cAntes, $dia13, true), 'dia 13 local exclui 04:00 UTC do dia 14 (00:00 do dia 14) e 03:59:59 UTC do dia 13 (dia 12 local)');
    $dia14 = array_map(static fn($r) => (int)$r['id'], $repo->buscarCandidaturasCohort(new DateTimeImmutable('2026-11-14'), new DateTimeImmutable('2026-11-14'), $empresa, null));
    $check(in_array($cBorda, $dia14, true) && !in_array($cTarde, $dia14, true), 'dia 14 local começa exatamente em 04:00:00 UTC');
    $mes = array_map(static fn($r) => (int)$r['id'], $repo->buscarCandidaturasCohort(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), $empresa, null));
    $check(count(array_intersect($mes, [$cand, $cTarde, $cBorda, $cAntes])) === 4, 'mês inteiro inclui todos');

    // cobertura estática: nenhuma view formata instante com date()+strtotime() (mostraria a hora UTC crua)
    $ruins = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../app/views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        if ($arq->getExtension() !== 'php') {
            continue;
        }
        $txt = (string)file_get_contents($arq->getPathname());
        if (preg_match("/date\\('[^']*H:i[^']*',\\s*strtotime\\(/", $txt)) {
            $ruins[] = str_replace('\\', '/', substr($arq->getPathname(), strlen(realpath(__DIR__ . '/../../app/views')) + 1));
        }
    }
    $check($ruins === [], 'nenhuma view formata instante com date()+strtotime() (hora UTC crua)' . ($ruins !== [] ? ': ' . implode(', ', $ruins) : ''));
} finally {
    if ($movs !== []) {
        $pdo->exec('DELETE FROM pipeline_movements WHERE id IN (' . implode(',', array_map('intval', $movs)) . ')');
    }
    if ($coms !== []) {
        $pdo->exec('DELETE FROM comunicacoes WHERE id IN (' . implode(',', array_map('intval', $coms)) . ')');
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — D1 candidaturas/Kanban (fuso).\n";
