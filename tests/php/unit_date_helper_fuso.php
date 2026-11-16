<?php

/**
 * Unitário — política temporal (Frente D): UTC persistido, America/Campo_Grande apresentado, conversão EXPLÍCITA.
 * Roda o mesmo conjunto sob vários fusos padrão do PHP para provar independência do servidor.
 * Não toca no banco.
 */

require __DIR__ . '/../../app/core/DateHelper.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

foreach (['UTC', 'Europe/Berlin', 'America/Campo_Grande', 'Asia/Tokyo'] as $tzServidor) {
    date_default_timezone_set($tzServidor);
    echo "-- fuso padrão do PHP: {$tzServidor}\n";
    $L = static fn(?string $v, string $o = DateHelper::ORIGEM_UTC, string $f = 'd/m/Y H:i') => DateHelper::formatLocal($v, $f, $o);

    // 1-3: cadastro às 09h30 em Campo Grande = 13h30 UTC
    $check($L('2026-11-13 13:30:00') === '13/11/2026 09:30', "[{$tzServidor}] 13:30 UTC aparece como 09:30 em Campo Grande");
    $local = new DateTimeImmutable('2026-11-13 09:30:00', new DateTimeZone('America/Campo_Grande'));
    $check(DateHelper::paraUtcSql($local) === '2026-11-13 13:30:00', "[{$tzServidor}] 09:30 locais persistem como 13:30:00 UTC");
    $check(DateHelper::formatLocal($local) === '13/11/2026 09:30', "[{$tzServidor}] objeto já em Campo Grande não é convertido de novo (sem conversão dupla)");
    $check(DateHelper::formatLocal(DateHelper::paraLocal('2026-11-13 13:30:00')) === '13/11/2026 09:30', "[{$tzServidor}] converter o resultado de novo não desloca mais 4 h");

    // virada de dia / mês / ano
    $check($L('2026-11-14 01:00:00') === '13/11/2026 21:00', "[{$tzServidor}] virada de dia: 01:00 UTC é 21:00 do dia anterior");
    $check($L('2026-12-01 02:00:00') === '30/11/2026 22:00', "[{$tzServidor}] virada de mês: 01/12 02:00 UTC é 30/11 22:00");
    $check($L('2027-01-01 03:00:00') === '31/12/2026 23:00', "[{$tzServidor}] virada de ano: 01/01/2027 03:00 UTC é 31/12/2026 23:00");
    $check($L('2026-11-13 03:59:59', DateHelper::ORIGEM_UTC, 'd/m') === '12/11', "[{$tzServidor}] 03:59:59 UTC ainda é o dia 12 local");
    $check($L('2026-11-13 04:00:00', DateHelper::ORIGEM_UTC, 'd/m') === '13/11', "[{$tzServidor}] 04:00:00 UTC é 00:00 do dia 13 local");

    // origens
    $check($L('2026-11-13 13:30:00', DateHelper::ORIGEM_PHP_LEGADO) === '13/11/2026 09:30', "[{$tzServidor}] PHP legado (UTC comprovado) converte igual");
    $check($L('2026-11-13 09:30:00', DateHelper::ORIGEM_CIVIL) === '13/11/2026 09:30', "[{$tzServidor}] origem civil não desloca (agendamento)");
    $check($L('2026-11-13T13:30:00') === '13/11/2026 09:30', "[{$tzServidor}] aceita separador T");
    $check($L(null) === '' && $L('') === '' && $L('abc') === '' && $L('2026-11-13') === '', "[{$tzServidor}] nulo, vazio, lixo e data sem hora → ''");
    $lancou = false;
    try {
        DateHelper::paraLocal('2026-11-13 13:30:00', 'inventada');
    } catch (InvalidArgumentException) {
        $lancou = true;
    }
    $check($lancou, "[{$tzServidor}] origem desconhecida é recusada (nunca deduzida)");

    // ISO / evidência
    $check(DateHelper::iso8601Local('2026-11-13 13:30:00') === '2026-11-13T09:30:00-04:00', "[{$tzServidor}] ISO-8601 local com offset");
    $check(DateHelper::iso8601Utc('2026-11-13 13:30:00') === '2026-11-13T13:30:00+00:00', "[{$tzServidor}] ISO-8601 em UTC");
    $check(DateHelper::formatLocalComFuso('2026-11-13 13:30:00') === '13/11/2026 09:30 (horário de Campo Grande, UTC-04:00)', "[{$tzServidor}] evidência com fuso explícito");
    $check(DateHelper::formatUtc('2026-11-13 13:30:00') === '13/11/2026 13:30:00 UTC', "[{$tzServidor}] equivalente em UTC");

    // limites do dia local em UTC (filtros por dia)
    $check(DateHelper::limitesDiaLocalEmUtc('2026-11-13') === ['2026-11-13 04:00:00', '2026-11-14 03:59:59'], "[{$tzServidor}] dia 13 local = 04:00:00 UTC até 03:59:59 UTC do dia seguinte");
    $check(DateHelper::limitesDiaLocalEmUtc('2026-12-31') === ['2026-12-31 04:00:00', '2027-01-01 03:59:59'], "[{$tzServidor}] limites na virada de ano");
    $check(DateHelper::limitesDiaLocalEmUtc('xx') === null, "[{$tzServidor}] data inválida → null");

    // ordenação cronológica e duração são preservadas pela conversão
    $a = DateHelper::paraLocal('2026-11-13 23:00:00');
    $b = DateHelper::paraLocal('2026-11-14 01:00:00');
    $check($a < $b && ($b->getTimestamp() - $a->getTimestamp()) === 7200, "[{$tzServidor}] ordem e duração (2 h) preservadas atravessando a meia-noite UTC");

    // agoraUtc independe do fuso do PHP
    $check(DateHelper::agoraUtc()->getTimezone()->getName() === 'UTC' && abs(strtotime(DateHelper::agoraUtcSql() . ' UTC') - time()) <= 2, "[{$tzServidor}] agoraUtcSql() é o instante atual em UTC");
    $hoje = DateHelper::hojeCivilLocal();
    $check($hoje->format('H:i:s') === '00:00:00' && $hoje->getTimezone()->getName() === 'America/Campo_Grande', "[{$tzServidor}] 'hoje' civil é de Campo Grande");
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — DateHelper (política temporal).\n";
