<?php

/**
 * Integração — Frente D5: serviços que recebem um INSTANTE (`$agora`) e precisam do DIA OPERACIONAL (Campo Grande) —
 * PDI, Entrevista de Desligamento e Indicações — com relógio controlado entre 20h e 23h59 locais (UTC já no dia seguinte).
 * Contrato: `$agora` = instante (gravado em UTC); `$hoje` = data civil; datas do METADADOS não são convertidas.
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

$cg = new DateTimeZone('America/Campo_Grande');
$utc = new DateTimeZone('UTC');
$fx = new ExclusaoFixtures($pdo);
$pdis = [];
$entrevistas = [];

try {
    $_SESSION = ['user_role' => 'admin', 'user_id' => 1];
    ColaboradorMetadadosElegibilidadeService::reiniciarEstadoDaRequisicao();

    // ======================= INDICAÇÃO: dias desde a contratação (instante UTC → dia operacional) =======================
    $hoje15 = new DateTimeImmutable('2026-11-15');
    $dias = static fn(string $utcInstante, string $hojeYmd): ?int => Candidatura::diasDesdeContratacao($utcInstante, new DateTimeImmutable($hojeYmd));
    // contratada 13/11 às 22h locais = 14/11 02:00 UTC
    $check($dias('2026-11-14 02:00:00', '2026-11-15') === 2, '(indicação) contratada 13/11 às 22h locais (14/11 UTC) e hoje 15/11 → 2 dias (o DATE() em UTC daria 1)');
    $check($dias('2026-11-14 04:00:00', '2026-11-15') === 1, '(indicação) contratada 14/11 às 00h00 locais (04:00 UTC) → 1 dia');
    $check($dias('2026-11-15 01:00:00', '2026-11-15') === 1, '(indicação) contratada 14/11 às 21h locais (15/11 01:00 UTC) e hoje 15/11 → 1 dia');
    $check($dias('2026-11-15 13:00:00', '2026-11-15') === 0, '(indicação) contratada hoje às 09h locais → 0 dia');
    $check($dias('2027-01-01 02:00:00', '2027-01-02') === 2, '(indicação) virada de ano: contratada 31/12 às 22h locais (01/01 UTC), hoje 02/01 → 2 dias');
    $check($dias('2026-12-01 03:00:00', '2026-12-02') === 2, '(indicação) virada de mês: contratada 30/11 às 23h locais (01/12 UTC), hoje 02/12 → 2 dias');
    $check(Candidatura::diasDesdeContratacao(null) === null && Candidatura::diasDesdeContratacao('') === null, '(indicação) sem contratação → null');
    // independência do fuso do servidor
    foreach (['UTC', 'Europe/Berlin', 'Asia/Tokyo'] as $tz) {
        date_default_timezone_set($tz);
        $r = Candidatura::diasDesdeContratacao('2026-11-14 02:00:00', new DateTimeImmutable('2026-11-15'));
        $check($r === 2, "(indicação) mesmo resultado com o PHP em {$tz} (obtido {$r})");
    }
    date_default_timezone_set('UTC');

    // validação da data de pagamento: a data de contratação é o DIA OPERACIONAL do instante
    $validar = new ReflectionMethod('Candidatura', 'validatePaymentDate');
    $validar->setAccessible(true);
    DateHelper::fixarRelogioParaTeste(new DateTimeImmutable('2026-11-15 21:30:00', $cg));
    $cand = ['indicacao_data_contratacao' => '2026-11-14 02:00:00']; // 13/11 às 22h locais
    $check($validar->invoke(null, '2026-11-13', $cand)['ok'] === true, '(pagamento) 13/11 não é anterior à contratação (13/11 22h locais)');
    $check($validar->invoke(null, '2026-11-12', $cand)['ok'] === false, '(pagamento) 12/11 é anterior à contratação');
    $check($validar->invoke(null, '2026-11-15', $cand)['ok'] === true, '(pagamento) hoje (15/11 às 21h30 locais) não é "futuro"');
    $check($validar->invoke(null, '2026-11-16', $cand)['ok'] === false, '(pagamento) 16/11 é futuro');
    DateHelper::fixarRelogioParaTeste(null);

    // ======================= ENTREVISTA DE DESLIGAMENTO: elegibilidade por rescisão =======================
    $ed = new EntrevistaDesligamentoService();
    $c1 = $fx->contrato('1', 'P1', 'ZZD5 demissao 16/11', ['admissao' => '2025-01-10', 'demissao' => '2026-11-16', 'ativo' => 0, 'motivo' => '003']);
    $c2 = $fx->contrato('2', 'P2', 'ZZD5 demissao 15/11', ['admissao' => '2025-01-10', 'demissao' => '2026-11-15', 'ativo' => 0, 'motivo' => '003']);
    $agora2130 = (new DateTimeImmutable('2026-11-15 21:30:00', $cg))->setTimezone($utc); // como os controllers: instante em UTC (16/11 01:30)
    $agora0000 = (new DateTimeImmutable('2026-11-16 00:00:00', $cg))->setTimezone($utc);
    $r = $ed->gerar($c1, 1, $agora2130);
    $check($r['ok'] === false, '(entrevista) demissão em 16/11 ainda NÃO é efetiva às 21h30 locais de 15/11 (o UTC já diz 16/11)');
    $r = $ed->gerar($c2, 1, $agora2130);
    $check($r['ok'] === true, '(entrevista) desligamento no dia atual (15/11) torna o contrato elegível às 21h30 locais');
    if (!empty($r['id'])) {
        $entrevistas[] = (int)$r['id'];
    }
    $r = $ed->gerar($c1, 1, $agora0000);
    $check($r['ok'] === true, '(entrevista) às 00h00 locais de 16/11 o desligamento de 16/11 é efetivo e o contrato fica elegível');
    if (!empty($r['id'])) {
        $entrevistas[] = (int)$r['id'];
    }
    // o instante da operação continua em UTC no banco
    $gerada = (string)$pdo->query('SELECT gerada_em FROM entrevistas_desligamento WHERE metadados_id = ' . (int)$c2)->fetchColumn();
    $check($gerada === '2026-11-16 01:30:00', "(entrevista) gerada_em gravada em UTC (obtido {$gerada})");
    // elegibilidade pura continua aceitando a data civil (contrato `$hoje`)
    $check(EntrevistaDesligamentoService::elegibilidade(['demissao' => '2026-11-15', 'motivo_rescisao_codigo' => '003'], new DateTimeImmutable('2026-11-15'))['elegivel'] === true, '(entrevista) compatibilidade: elegibilidade($hoje civil) inalterada');
    foreach (['UTC', 'Europe/Berlin'] as $tz) {
        date_default_timezone_set($tz);
        $pdo->prepare('DELETE FROM entrevistas_desligamento WHERE metadados_id = ?')->execute([$c1]);
        $rr = $ed->gerar($c1, 1, $agora2130);
        $check($rr['ok'] === false, "(entrevista) 21h30 locais: demissão 16/11 inelegível com o PHP em {$tz}");
    }
    date_default_timezone_set('UTC');

    // ======================= PDI: abertura, conclusão e contrato desligado =======================
    $svc = new PdiService();
    $gestor = $fx->usuario('ZZD5 Gestor', 'viewer');
    $rhUser = $fx->usuario('ZZD5 RH', 'rh');
    $rh = ['id' => $rhUser, 'role' => 'rh'];
    $cDem16 = $fx->contrato('3', 'P3', 'ZZD5 PDI demissao 16/11', ['admissao' => '2025-01-10', 'demissao' => '2026-11-16', 'ativo' => 0]);
    $cVivo = $fx->contrato('4', 'P4', 'ZZD5 PDI vigente', ['admissao' => '2025-01-10']);
    $post = static fn(int $contrato, array $extra = []): array => $extra + [
        'metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestor, 'origem_tipo' => 'feedback',
        'data_abertura' => '2026-11-15', 'data_prevista_conclusao' => '2027-02-15',
        'pontos_fortes' => 'Comunicação clara', 'oportunidades_desenvolvimento' => 'Delegar tarefas', 'objetivo_esperado' => 'Assumir uma equipe',
        'competencias' => "Comunicação\nLiderança",
        'acoes' => [1 => ['descricao' => 'Curso de liderança', 'responsavel_tipo' => 'colaborador', 'prazo' => '2026-12-15']],
    ];
    $rA = $svc->criar($post($cDem16), $rh, $agora2130, '127.0.0.1'); if (!($rA['ok'] ?? false)) { fwrite(STDERR, 'ERRO PDI: ' . json_encode($rA, JSON_UNESCAPED_UNICODE) . "
"); }
    $check(($rA['ok'] ?? false) === true, '(PDI) 21h30 locais de 15/11: contrato com demissão em 16/11 ainda aceita novo PDI (dia operacional é 15/11)');
    $pdis[] = (int)($rA['id'] ?? 0);
    $rB = $svc->criar($post($cVivo), $rh, $agora2130, '127.0.0.1');
    $check(($rB['ok'] ?? false) === true, '(PDI) contrato vigente aceita PDI às 21h30 locais');
    $idB = (int)($rB['id'] ?? 0);
    $pdis[] = $idB;
    $criadoEm = (string)$pdo->query("SELECT criado_em FROM pdis WHERE id = {$idB}")->fetchColumn();
    $check($criadoEm === '2026-11-16 01:30:00', "(PDI) instante de criação gravado em UTC (obtido {$criadoEm})");

    $rC = $svc->criar($post($cDem16), $rh, $agora0000, '127.0.0.1');
    $check(($rC['ok'] ?? true) === false, '(PDI) às 00h00 locais de 16/11, contrato com demissão em 16/11 já está desligado → bloqueado');
    if (!empty($rC['id'])) {
        $pdis[] = (int)$rC['id'];
    }

    // conclusão às 21h30 locais grava a data real de conclusão como o dia operacional (15/11)
    $svc->liberar($idB, $rh, $agora2130, '127.0.0.1');
    $svc->iniciar($idB, $rh, $agora2130, '127.0.0.1');
    $rc = $svc->concluir($idB, 'objetivo_atingido', 'Concluído', $rh, $agora2130, '127.0.0.1');
    if (($rc['ok'] ?? false) === true) {
        $real = (string)$pdo->query("SELECT data_real_conclusao FROM pdis WHERE id = {$idB}")->fetchColumn();
        $check($real === '2026-11-15', "(PDI) data_real_conclusao às 21h30 locais = 15/11 (obtido {$real})");
    } else {
        echo "  [info] conclusão não executada neste cenário: " . json_encode($rc, JSON_UNESCAPED_UNICODE) . "\n";
    }
    foreach (['UTC', 'Europe/Berlin', 'Asia/Tokyo'] as $tz) {
        date_default_timezone_set($tz);
        $x = $svc->criar($post($cDem16), $rh, $agora2130, '127.0.0.1');
        $check(($x['ok'] ?? false) === true, "(PDI) 21h30 locais aceita contrato com demissão 16/11 com o PHP em {$tz}");
        if (!empty($x['id'])) {
            $pdis[] = (int)$x['id'];
        }
    }
    date_default_timezone_set('UTC');
} finally {
    DateHelper::fixarRelogioParaTeste(null);
    date_default_timezone_set('UTC');
    $ids = array_values(array_filter(array_map('intval', $pdis)));
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (['pdi_eventos' => 'pdi_id', 'pdi_acoes' => 'pdi_id', 'pdi_competencias' => 'pdi_id', 'pdi_origens' => 'pdi_id', 'pdi_acompanhamentos' => 'pdi_id'] as $t => $col) {
            try {
                $pdo->exec("DELETE FROM {$t} WHERE {$col} IN ({$in})");
            } catch (Throwable) {
            }
        }
        $pdo->exec("DELETE FROM pdis WHERE id IN ({$in})");
    }
    $pdo->exec('DELETE FROM entrevistas_desligamento WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE \'' . $fx->emp . '-%\')');
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — D5 serviços (PDI, Entrevista, Indicações) no dia operacional.\n";
