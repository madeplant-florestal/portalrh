<?php

/**
 * Integração — Frente D / D2: Cartas Propostas, aceites, comprovante e Exame Admissional.
 * Prova: validade da proposta até 23:59:59 do dia LOCAL (Campo Grande) com testes colados na meia-noite;
 * expiração técnica do token comparada em UTC, sem depender do fuso padrão do PHP; aceite gravado em UTC e exibido
 * em horário local com fuso e equivalente UTC; comprovante novo com identificação do fuso; agendamento do exame
 * (data civil) nunca deslocado.
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
$cartas = [];
$service = new CartaPropostaService();
$utc = new DateTimeZone('UTC');
$cg = new DateTimeZone('America/Campo_Grande');

$mkCarta = static function (string $validade, string $tokenExpira) use ($fx, &$cartas): array {
    $token = bin2hex(random_bytes(32));
    $id = $fx->inserirGenerico('cartas_propostas', [
        'status' => 'enviada', 'token_hash' => hash('sha256', $token), 'token_expira_em' => $tokenExpira,
        'validade_proposta' => $validade, 'versao' => 1, 'candidatura_id' => 900000000 + random_int(1, 99999999),
    ]);
    $cartas[] = $id;
    return [$id, $token];
};

try {
    // ---- validade: até 23:59:59 do dia LOCAL (Campo Grande), colada na meia-noite -------------------
    [$idV, $tokenV] = $mkCarta('2026-11-13', '2030-01-01 00:00:00');
    $antes = new DateTimeImmutable('2026-11-13 23:59:58', $cg);   // = 2026-11-14 03:59:58 UTC
    $depois = new DateTimeImmutable('2026-11-14 00:00:00', $cg);  // = 2026-11-14 04:00:00 UTC
    $check($antes->setTimezone($utc)->format('Y-m-d H:i:s') === '2026-11-14 03:59:58', 'premissa: 23:59:58 locais = 03:59:58 UTC do dia seguinte');
    $check($service->resolverAcessoPorToken($tokenV, $antes)['estado'] === 'formulario', '(validade) 23:59:58 do último dia local ainda aceita a proposta');
    $check($service->resolverAcessoPorToken($tokenV, $antes->modify('+1 second'))['estado'] === 'formulario', '(validade) 23:59:59 do último dia local ainda aceita');
    $check($service->resolverAcessoPorToken($tokenV, $depois)['estado'] === 'proposta_vencida', '(validade) 00:00:00 do dia seguinte local: proposta vencida');
    // o mesmo vale se o "agora" chegar já em UTC (04:00:00 UTC), em qualquer fuso padrão do PHP
    foreach (['UTC', 'Europe/Berlin', 'Asia/Tokyo'] as $tz) {
        date_default_timezone_set($tz);
        $utcAntes = new DateTimeImmutable('2026-11-14 03:59:59', $utc);
        $utcDepois = new DateTimeImmutable('2026-11-14 04:00:00', $utc);
        $ok = $service->resolverAcessoPorToken($tokenV, $utcAntes)['estado'] === 'formulario'
            && $service->resolverAcessoPorToken($tokenV, $utcDepois)['estado'] === 'proposta_vencida';
        $check($ok, "(validade) corte em 04:00:00 UTC independe do fuso padrão do PHP ({$tz})");
    }
    date_default_timezone_set('UTC');

    // ---- expiração TÉCNICA do token: instantes em UTC, sem depender do fuso do PHP ------------------
    [$idT, $tokenT] = $mkCarta('2030-12-31', '2026-11-14 12:00:00');
    foreach (['UTC', 'Europe/Berlin', 'America/Campo_Grande'] as $tz) {
        date_default_timezone_set($tz);
        $e1 = $service->resolverAcessoPorToken($tokenT, new DateTimeImmutable('2026-11-14 11:59:59', $utc))['estado'];
        $e2 = $service->resolverAcessoPorToken($tokenT, new DateTimeImmutable('2026-11-14 12:00:01', $utc))['estado'];
        $check($e1 === 'formulario' && $e2 === 'indisponivel', "(token) 12:00:00 UTC separa válido de expirado com o PHP em {$tz}");
    }
    date_default_timezone_set('UTC');

    // ---- aceite eletrônico: gravado em UTC, exibido em horário local ------------------------------
    [$idA, $tokenA] = $mkCarta('2030-12-31', '2030-01-01 00:00:00');
    $aceiteLocal = new DateTimeImmutable('2026-11-13 09:30:00', $cg);
    $r = $service->registrarDecisao($tokenA, 'aceita', null, '203.0.113.9', 'ZZFZ-UA', $aceiteLocal);
    $guardado = (string)$pdo->query("SELECT decisao_em FROM cartas_propostas WHERE id = {$idA}")->fetchColumn();
    $check(($r['ok'] ?? false) === true && $guardado === '2026-11-13 13:30:00', "(aceite) 09:30 locais ficam gravados como 13:30:00 UTC (obtido {$guardado})");
    $check(DateHelper::formatLocal($guardado, 'd/m/Y H:i', DateHelper::ORIGEM_PHP_LEGADO) === '13/11/2026 09:30', '(aceite) exibido como 09:30 para o RH');
    $check(DateHelper::formatLocalComFuso($guardado, DateHelper::ORIGEM_PHP_LEGADO) === '13/11/2026 09:30 (horário de Campo Grande, UTC-04:00)', '(aceite) identificação do fuso na exibição');

    // ---- comprovante NOVO: horário local com fuso + equivalente UTC --------------------------------
    ob_start();
    set_error_handler(static fn() => true);
    $decisao_em = $guardado;
    include __DIR__ . '/../../app/views/carta_proposta/comprovante_pdf.php';
    restore_error_handler();
    $html = (string)ob_get_clean();
    $check(str_contains($html, '13/11/2026 09:30 (horário de Campo Grande, UTC-04:00)') && str_contains($html, '13/11/2026 13:30:00 UTC'), '(comprovante) mostra o horário local com o fuso e o equivalente em UTC');

    // ---- validade padrão +7 dias usa a data civil de Campo Grande (não a do fuso do servidor) ---------
    $esperado = (new DateTimeImmutable('now', $cg))->modify('+7 days')->format('Y-m-d');
    foreach (['UTC', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tz) { // extremos: UTC+14 e UTC-11
        date_default_timezone_set($tz);
        $check(DateHelper::hojeCivilLocal()->modify('+7 days')->format('Y-m-d') === $esperado, "(validade padrão) +7 dias a partir da data de Campo Grande com o PHP em {$tz}");
    }
    date_default_timezone_set('UTC');
    $check(str_contains((string)file_get_contents(__DIR__ . '/../../app/services/CartaPropostaService.php'), "'validade_proposta' => DateHelper::hojeCivilLocal()->modify('+7 days')"), '(validade padrão) o serviço usa a data civil local');
} finally {
    // ---- exame admissional: horário agendado (civil) nunca é deslocado --------------------------------
    echo "-- exame\n";
    $check(DateHelper::formatLocal('2026-11-20 14:00:00', 'd/m/Y H:i', DateHelper::ORIGEM_CIVIL) === '20/11/2026 14:00', '(exame) horário agendado (civil) permanece 14:00, sem converter como UTC');
    $check(DateHelper::formatLocal('2026-11-13 13:30:00') === '13/11/2026 09:30', '(exame) envio gravado em UTC é exibido em horário local');
    $check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', DateHelper::agoraUtcSql()) === 1, '(exame) o carimbo de envio/preparo é gerado em UTC pelo DateHelper');
    $src = file_get_contents(__DIR__ . '/../../app/services/ExameAdmissionalService.php') . file_get_contents(__DIR__ . '/../../app/services/CartaPropostaService.php');
    $check(!str_contains($src, "(new DateTimeImmutable('now'))->format('Y-m-d H:i:s')"), 'serviços de carta/exame não gravam mais hora pelo fuso padrão do PHP');

    if ($cartas !== []) {
        $pdo->exec('DELETE FROM cartas_propostas WHERE id IN (' . implode(',', array_map('intval', $cartas)) . ')');
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — D2 cartas/aceites/exames (fuso).\n";
