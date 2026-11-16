<?php

/**
 * Unitário — Frente D4: DATA OPERACIONAL (dia civil de America/Campo_Grande) com relógio controlado, sob vários fusos
 * padrão do PHP. Entre 20h00 e 23h59 locais o UTC já está no dia seguinte: a data operacional NÃO pode seguir o UTC,
 * enquanto os carimbos de instante continuam em UTC.
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
$cg = new DateTimeZone('America/Campo_Grande');

// [instante local em Campo Grande, data operacional esperada, data UTC correspondente]
$casos = [
    ['2026-11-15 19:59:00', '2026-11-15', '2026-11-15'],
    ['2026-11-15 20:00:00', '2026-11-15', '2026-11-16'], // UTC já virou
    ['2026-11-15 21:30:00', '2026-11-15', '2026-11-16'],
    ['2026-11-15 23:59:59', '2026-11-15', '2026-11-16'],
    ['2026-11-16 00:00:00', '2026-11-16', '2026-11-16'],
    ['2026-11-30 22:00:00', '2026-11-30', '2026-12-01'], // virada de mês
    ['2026-12-01 00:00:00', '2026-12-01', '2026-12-01'],
    ['2026-12-31 21:00:00', '2026-12-31', '2027-01-01'], // virada de ano
    ['2027-01-01 00:00:00', '2027-01-01', '2027-01-01'],
    ['2028-02-28 20:30:00', '2028-02-28', '2028-02-29'], // bissexto
];

try {
    foreach (['UTC', 'Europe/Berlin', 'America/Campo_Grande', 'Asia/Tokyo', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tzServidor) {
        date_default_timezone_set($tzServidor);
        foreach ($casos as [$local, $esperado, $dataUtc]) {
            $instante = new DateTimeImmutable($local, $cg);
            DateHelper::fixarRelogioParaTeste($instante);
            $op = DateHelper::hojeOperacionalSql();
            $check($op === $esperado, "[{$tzServidor}] {$local} (Campo Grande) → dia operacional {$esperado} (obtido {$op})");
            $check(DateHelper::agoraUtc()->format('Y-m-d') === $dataUtc, "[{$tzServidor}] {$local}: a data UTC é {$dataUtc} e NÃO é usada como dia operacional" . ($dataUtc !== $esperado ? ' (diferem aqui)' : ''));
            $check(DateHelper::agoraUtcSql() === $instante->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), "[{$tzServidor}] {$local}: carimbo de instante continua em UTC (" . DateHelper::agoraUtcSql() . ')');
            // a data operacional é comparável com datas civis comuns (mesma convenção de new DateTimeImmutable('Y-m-d'))
            $check(DateHelper::hojeOperacional() == new DateTimeImmutable($esperado) && DateHelper::hojeOperacional() >= new DateTimeImmutable($esperado), "[{$tzServidor}] {$local}: comparável com datas civis do sistema");
            $check(DateHelper::hojeCivilLocal()->format('Y-m-d') === $esperado, "[{$tzServidor}] {$local}: hojeCivilLocal() = mesmo valor");
        }
        // diaOperacionalDe: instantes arbitrários
        $check(DateHelper::diaOperacionalDe(new DateTimeImmutable('2026-11-16 03:59:59', new DateTimeZone('UTC')))->format('Y-m-d') === '2026-11-15', "[{$tzServidor}] 03:59:59 UTC ainda é dia 15 operacional");
        $check(DateHelper::diaOperacionalDe(new DateTimeImmutable('2026-11-16 04:00:00', new DateTimeZone('UTC')))->format('Y-m-d') === '2026-11-16', "[{$tzServidor}] 04:00:00 UTC é dia 16 operacional");
        // validade padrão (+7 dias) a partir do dia operacional, às 21h30 locais
        DateHelper::fixarRelogioParaTeste(new DateTimeImmutable('2026-11-15 21:30:00', $cg));
        $check(DateHelper::hojeOperacional()->modify('+7 days')->format('Y-m-d') === '2026-11-22', "[{$tzServidor}] +7 dias às 21h30 locais = 22/11 (não 23/11)");
        // mês/ano a partir do dia operacional
        DateHelper::fixarRelogioParaTeste(new DateTimeImmutable('2026-12-31 21:00:00', $cg));
        $check(DateHelper::hojeOperacional()->format('Y') === '2026' && DateHelper::hojeOperacional()->modify('first day of this month')->format('Y-m-d') === '2026-12-01', "[{$tzServidor}] 31/12 21h local ainda é 2026 e dezembro");
    }
} finally {
    DateHelper::fixarRelogioParaTeste(null);
    date_default_timezone_set('UTC');
}

$agoraReal = DateHelper::agoraUtc();
$check(abs($agoraReal->getTimestamp() - time()) <= 2, 'sem relógio fixo, agoraUtc() volta ao instante real');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — data operacional (D4).\n";
