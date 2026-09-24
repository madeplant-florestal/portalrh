<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_metadados_reconciliacao_ausencia (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Reconciliação de ausência (ver migration 2026-09-24-colaboradores-metadados-reconciliacao-
 * ausencia.sql) — diagnóstico dos "66 registros stale": MetadadosSyncService::applyRows() é
 * upsert puro e nunca detectava quando uma chave vigente deixava de vir num sync completo da
 * origem. Cobre os 7 cenários de reconciliação e uma simulação estrutural equivalente ao caso
 * real (N registros antigos que somem de um lote completo, sem nenhum valor mágico/hardcoded).
 * Nunca usa RHMADEPLANT real — todos os dados são fixtures descartáveis (empresa/unidade únicas
 * por execução, limpas em finally).
 */
$pdo = Database::conn();
$hasReconciliacao = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'ausente_na_origem'"
)->fetchColumn();
if ($hasReconciliacao === 0) {
    echo "SKIP integration_metadados_reconciliacao_ausencia (migration 2026-09-24-colaboradores-metadados-reconciliacao-ausencia.sql nao aplicada)\n";
    exit(0);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$service = new MetadadosSyncService();
$repo = new ColaboradorMetadadosRepository($pdo);
$consulta = new ColaboradorMetadadosConsultaRepository($pdo);
$sufixo = (string)time() . (string)random_int(100, 999);
$empresa = 'REC' . $sufixo;

$contrato = static function (string $numero, string $sufixo, string $empresa, array $overrides = []): array {
    return array_merge([
        'identificador' => "$empresa-U1-$numero",
        'codigo_empresa' => $empresa,
        'codigo_unidade' => 'U1',
        'numero_contrato' => $numero,
        'codigo_pessoa' => 'P' . $sufixo . $numero,
        'cpf' => '111222333' . str_pad($numero, 2, '0', STR_PAD_LEFT),
        'nome' => 'Reconciliacao ' . $numero . ' ' . $sufixo,
        'admissao' => '2020-01-01',
        'demissao' => null,
        'ativo' => 1,
    ], $overrides);
};

try {
    $a = $contrato('A', $sufixo, $empresa);
    $b = $contrato('B', $sufixo, $empresa);
    $c = $contrato('C', $sufixo, $empresa);

    // ---- Caso 1: lote completo com A/B/C -> ninguém ausente -----------------------------
    $service->applyRows([$a, $b, $c], 'RHMADEPLANT', false, true);
    $rowA = $repo->findByVinculo($empresa, 'U1', 'A');
    $rowB = $repo->findByVinculo($empresa, 'U1', 'B');
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $check((int)$rowA['ausente_na_origem'] === 0 && (int)$rowB['ausente_na_origem'] === 0 && (int)$rowC['ausente_na_origem'] === 0, 'Caso 1: lote completo A/B/C -> nenhum marcado ausente.');

    // ---- Caso 2: lote seguinte só com A/B -> C fica ausente -----------------------------
    $resumo2 = $service->applyRows([$a, $b], 'RHMADEPLANT', false, true);
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $check((int)$rowC['ausente_na_origem'] === 1, 'Caso 2: C sumiu do lote -> ausente_na_origem = 1.');
    $check($rowC['ausente_desde'] !== null, 'Caso 2: ausente_desde preenchido na primeira detecção.');
    $check($resumo2['ausentes_marcados'] === 1, 'Caso 2: applyRows() reporta 1 registro marcado ausente.');
    $ausenteDesdeOriginal = $rowC['ausente_desde'];

    // ---- Caso 3: lote seguinte continua só A/B -> ausente_desde NAO muda ----------------
    sleep(1); // garante que, se o bug reaparecer sobrescrevendo, o timestamp mudaria de verdade.
    $service->applyRows([$a, $b], 'RHMADEPLANT', false, true);
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $check((int)$rowC['ausente_na_origem'] === 1, 'Caso 3: C continua ausente no 2º lote sem ele.');
    $check($rowC['ausente_desde'] === $ausenteDesdeOriginal, 'Caso 3: ausente_desde não é sobrescrito enquanto continuar ausente.');

    // ---- Caso 4: C reaparece no lote -> volta a sincronizado, limpa ausente_desde -------
    $service->applyRows([$a, $b, $c], 'RHMADEPLANT', false, true);
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $check((int)$rowC['ausente_na_origem'] === 0, 'Caso 4: C reaparece no lote -> ausente_na_origem volta a 0.');
    $check($rowC['ausente_desde'] === null, 'Caso 4: ausente_desde limpo automaticamente ao reaparecer, sem ação manual.');

    // ---- Preparo comum de Casos 5-7: repovoa D (sincronizado) e o desligado, junto com
    //      A/B/C, para que cada caso controle explicitamente quem entra em cada lote. -----
    $d = $contrato('D', $sufixo, $empresa);
    // codigo_pessoa é varchar(20) — 'P'.$sufixo (numero_contrato) já usa boa parte disso, então
    // este cenário precisa de um codigo_pessoa/cpf próprios e curtos (não o padrão do helper).
    $desligado = $contrato('DESLIGADO', $sufixo, $empresa, [
        'ativo' => 0,
        'demissao' => '2021-05-10',
        'codigo_pessoa' => 'PDESL' . substr($sufixo, -6),
        'cpf' => '11122233355',
    ]);
    $resumoPreparo = $service->applyRows([$a, $b, $c, $d, $desligado], 'RHMADEPLANT', false, true);
    $check($resumoPreparo['errors'] === 0, 'Preparo Casos 5-7 (sanity): as 5 fixtures são inseridas sem nenhum erro.');

    // ---- Caso 5: lote com erro (parcial/falho) -> ninguém é marcado ausente ------------
    // Lote pretende excluir C e D, mas contém uma linha inválida (nome vazio) -> errors=1.
    $invalido = $contrato('INVALIDO', $sufixo, $empresa, ['nome' => '']); // nome vazio => validateRow() falha
    $resumo5 = $service->applyRows([$a, $b, $invalido], 'RHMADEPLANT', false, true);
    $check($resumo5['errors'] === 1, 'Caso 5 (setup): a linha inválida realmente gera 1 erro.');
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $rowD = $repo->findByVinculo($empresa, 'U1', 'D');
    $check((int)$rowC['ausente_na_origem'] === 0 && (int)$rowD['ausente_na_origem'] === 0, 'Caso 5: lote com erro nunca reconcilia — C e D continuam sincronizados mesmo fora deste lote.');
    $check($resumo5['ausentes_marcados'] === 0, 'Caso 5: applyRows() não reporta nenhuma reconciliação quando há erro no lote.');

    // ---- Caso 6: "dry-run" (reconciliarAusentes=false, default) -> ninguém marcado -----
    // Mesmo lote sem C/D, mas agora sem erro e sem pedir reconciliação -> ninguém marcado.
    $service->applyRows([$a, $b], 'RHMADEPLANT'); // sem o 4º parâmetro => false por padrão
    $rowC = $repo->findByVinculo($empresa, 'U1', 'C');
    $rowD = $repo->findByVinculo($empresa, 'U1', 'D');
    $check((int)$rowC['ausente_na_origem'] === 0 && (int)$rowD['ausente_na_origem'] === 0, 'Caso 6: sem reconciliarAusentes=true (equivalente a dry-run/chamada isolada), C e D continuam sincronizados mesmo fora do lote.');

    // ---- Caso 7: registro já desligado (histórico) que some do lote -> também é sinalizado
    // Lote completo com A/B/C/D, mas sem o desligado.
    $service->applyRows([$a, $b, $c, $d], 'RHMADEPLANT', false, true);
    $rowDesligado = $repo->findByVinculo($empresa, 'U1', 'DESLIGADO');
    $check((int)$rowDesligado['ativo'] === 0, 'Caso 7 (sanity): ativo continua 0 — ausente_na_origem nunca reescreve ativo/demissao.');
    $check($rowDesligado['demissao'] === '2021-05-10', 'Caso 7 (sanity): demissao original preservada.');
    $check((int)$rowDesligado['ausente_na_origem'] === 1, 'Caso 7: um registro já desligado também é sinalizado como ausente se sumir de um lote completo — a sinalização é ortogonal a ativo/demissao.');

    // ---- Proteção contra lote vazio (§26): nunca reconcilia com conjunto vazio --------
    $resumoVazio = $repo->reconciliarAusentes([]);
    $check($resumoVazio === ['marcados_ausentes' => 0, 'ignorado_lote_vazio' => true], 'Proteção: reconciliarAusentes([]) não marca nada e sinaliza que ignorou o lote vazio.');
    $rowA = $repo->findByVinculo($empresa, 'U1', 'A');
    $check((int)$rowA['ausente_na_origem'] === 0, 'Proteção: um lote vazio não derruba registros legítimos (A continua sincronizado).');

    // ---- Simulação estrutural equivalente aos "66 reais" (§24) -------------------------
    // N registros "antigos" (empresa/unidade próprias) que nunca mais aparecem num lote
    // completo, ao lado de M registros "atuais" que continuam vindo normalmente — sem
    // nenhum valor hardcoded (nem 66, nem 731): o mecanismo não pode depender de contagem.
    $empresaSim = 'SIM' . $sufixo;
    $antigos = [];
    for ($i = 1; $i <= 5; $i++) {
        $antigos[] = $contrato((string)$i, $sufixo, $empresaSim, ['numero_contrato' => 'OLD' . $i, 'identificador' => "$empresaSim-U9-OLD$i"]);
    }
    $atuais = [];
    for ($i = 1; $i <= 8; $i++) {
        $atuais[] = $contrato((string)$i, $sufixo, $empresaSim, ['numero_contrato' => 'NEW' . $i, 'identificador' => "$empresaSim-U9-NEW$i", 'codigo_pessoa' => 'PN' . $sufixo . $i, 'cpf' => '2223334440' . $i]);
    }
    foreach ($antigos as &$row) {
        $row['codigo_unidade'] = 'U9';
    }
    unset($row);
    foreach ($atuais as &$row) {
        $row['codigo_unidade'] = 'U9';
    }
    unset($row);

    // Lote 1: tudo presente (antigos + atuais) — como o espelho já tinha os antigos antes.
    $service->applyRows(array_merge($antigos, $atuais), 'RHMADEPLANT', false, true);
    $summaryAntesSim = $consulta->summary();

    // Lote 2 (o "sync de hoje"): só os atuais — os 5 antigos desapareceram da origem.
    $resumoSim = $service->applyRows($atuais, 'RHMADEPLANT', false, true);
    $check($resumoSim['ausentes_marcados'] === 5, 'Simulação: exatamente os 5 registros "antigos" (nunca um número fixo diferente) são detectados como ausentes.');

    $falsoPositivo = false;
    foreach ($atuais as $row) {
        $rowAtual = $repo->findByVinculo($row['codigo_empresa'], $row['codigo_unidade'], $row['numero_contrato']);
        if ((int)$rowAtual['ausente_na_origem'] !== 0) {
            $falsoPositivo = true;
        }
    }
    $check($falsoPositivo === false, 'Simulação: nenhum dos 8 registros "atuais" é marcado ausente por engano (zero falso positivo).');

    $todosAntigosAusentes = true;
    foreach ($antigos as $row) {
        $rowAntigo = $repo->findByVinculo($row['codigo_empresa'], $row['codigo_unidade'], $row['numero_contrato']);
        if ((int)$rowAntigo['ausente_na_origem'] !== 1) {
            $todosAntigosAusentes = false;
        }
    }
    $check($todosAntigosAusentes === true, 'Simulação: os 5 registros "antigos" ficam com ausente_na_origem = 1, sem exceção.');

    $summaryDepoisSim = $consulta->summary();
    $check($summaryAntesSim['ativos'] - $summaryDepoisSim['ativos'] === 5, 'Simulação: headcount vigente (ColaboradorMetadadosConsultaRepository::summary()[\'ativos\']) cai exatamente 5 após a reconciliação — nunca hardcoded, calculado pelo dado real.');
    $check($summaryDepoisSim['contratos'] === $summaryAntesSim['contratos'], 'Simulação: total histórico de contratos não muda — reconciliação nunca apaga nada.');

    // ---- Mesma simulação, agora pelo caminho de Indicadores RH / People Analytics ------
    // (RhIndicadoresRepository::buscarContratos() + RhIndicadoresService::montarPainelComContratos())
    // isolada por codigo_empresa=$empresaSim para não sofrer influência do restante da base local.
    $riRepo = new RhIndicadoresRepository($pdo);
    $contratosRi = $riRepo->buscarContratos(['codigo_empresa' => $empresaSim]);
    $hojeSim = new DateTimeImmutable('today');
    $painelHojeSim = RhIndicadoresService::montarPainelComContratos($contratosRi, $hojeSim, $hojeSim);
    // Os 8 "atuais" têm admissao 2020-01-01/demissao null -> headcount atual (vigente) = 8.
    $check($painelHojeSim['headcount_atual'] === 8, 'Simulação (Indicadores RH): headcount_atual conta só os 8 "atuais", excluindo os 5 "antigos" agora ausentes.');
    // Período totalmente histórico (2020, antes de qualquer reconciliação existir) -> os 13
    // contratos (5 antigos + 8 atuais) estavam igualmente vigentes; ausente_na_origem não pode
    // apagar essa realidade passada.
    $painelHistoricoSim = RhIndicadoresService::montarPainelComContratos($contratosRi, new DateTimeImmutable('2020-06-01'), new DateTimeImmutable('2020-06-30'));
    $check($painelHistoricoSim['headcount_atual'] === 13, 'Simulação (Indicadores RH): headcount de uma data histórica (2020-06-30) continua contando os 13 contratos, incluindo os 5 hoje ausentes — histórico preservado.');
    $check(count(RhIndicadoresService::admissoesNoPeriodo($contratosRi, new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2020-01-31'))) === 13, 'Simulação (Indicadores RH): admissões de janeiro/2020 continuam contando os 13 contratos — reconciliação não altera admissões históricas.');

    // ---- Mesma simulação pelo caminho de People Analytics (card "Headcount Atual") -----
    $paRepo = new PeopleAnalyticsRepository($pdo);
    $paService = new PeopleAnalyticsService($paRepo);
    $painelPaHoje = $paService->montarPainel(['codigo_empresa' => $empresaSim], $hojeSim, $hojeSim);
    $check($painelPaHoje['headcount']['atual'] === 8, 'Simulação (People Analytics): card Headcount Atual conta só os 8 "atuais", excluindo os 5 "antigos" agora ausentes.');
    $somaHeadcountPorEmpresaSim = array_sum(array_column($painelPaHoje['headcount_por_empresa'], 'quantidade'));
    $check($somaHeadcountPorEmpresaSim === 8, 'Simulação (People Analytics): soma das barras de Headcount por Empresa continua EXATAMENTE igual ao card Headcount Atual (8), mesmo após excluir os ausentes.');
    $check($painelPaHoje['turnover']['headcount_fim'] === 13, 'Simulação (People Analytics): turnover.headcount_fim (base do Turnover Geral) continua sobre os 13 contratos completos — Turnover nunca é alterado pela reconciliação, só o card de headcount atual.');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }

    echo "OK integration_metadados_reconciliacao_ausencia\n";
} finally {
    $pdo->prepare('DELETE FROM colaboradores_metadados WHERE codigo_empresa IN (?, ?)')
        ->execute([$empresa, $empresaSim ?? '__nenhum__']);
}
