<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Nova fórmula oficial de Turnover do People Analytics (2026-09): desligados do período / ativos
 * do período × 100 — RhIndicadoresService::ativosNoPeriodo()/taxaTurnoverPeriodo()/
 * turnoverPorDimensaoPeriodo()/serieMensalPeriodo()/periodoMesmoIntervaloAnoAnterior()/
 * periodoImediatamenteAnterior(). Tudo aqui é static sobre arrays já carregados — sem banco.
 * taxaTurnover()/turnoverPorDimensao() (headcount médio, Indicadores de RH) continuam intocados,
 * cobertos por unit_rh_indicadores_service.php.
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$S = RhIndicadoresService::class;

function contratoPeriodo(array $overrides = []): array
{
    return array_merge([
        'codigo_empresa' => '0001',
        'empresa' => 'Empresa Teste',
        'codigo_setor' => 'S1',
        'sexo' => 'M',
        'admissao' => '2026-01-01',
        'demissao' => null,
        'motivo_rescisao_codigo' => null,
    ], $overrides);
}

try {
    $inicio = new DateTimeImmutable('2026-01-01');
    $fim = new DateTimeImmutable('2026-01-31');

    // ---- Sobreposição (contratoSobrepoePeriodo/ativosNoPeriodo) — os 5 cenários do brief --------
    $admitidoAntesDesligadoDentro = contratoPeriodo(['admissao' => '2025-06-01', 'demissao' => '2026-01-15']);
    $admitidoDentroAindaAtivo = contratoPeriodo(['admissao' => '2026-01-10', 'demissao' => null]);
    $admitidoEDesligadoDentro = contratoPeriodo(['admissao' => '2026-01-05', 'demissao' => '2026-01-20']);
    $encerradoAntesDoInicio = contratoPeriodo(['admissao' => '2025-01-01', 'demissao' => '2025-12-31']);
    $admitidoDepoisDoFim = contratoPeriodo(['admissao' => '2026-02-01', 'demissao' => null]);

    $assert($S::contratoSobrepoePeriodo($admitidoAntesDesligadoDentro, $inicio, $fim) === true, 'Sobreposição: admitido antes e desligado DENTRO do período participa.');
    $assert($S::contratoSobrepoePeriodo($admitidoDentroAindaAtivo, $inicio, $fim) === true, 'Sobreposição: admitido dentro e ainda ativo participa.');
    $assert($S::contratoSobrepoePeriodo($admitidoEDesligadoDentro, $inicio, $fim) === true, 'Sobreposição: admitido e desligado inteiramente DENTRO do período participa (nem sempre ativo nas pontas).');
    $assert($S::contratoSobrepoePeriodo($encerradoAntesDoInicio, $inicio, $fim) === false, 'Sobreposição: contrato encerrado ANTES do início do período não participa.');
    $assert($S::contratoSobrepoePeriodo($admitidoDepoisDoFim, $inicio, $fim) === false, 'Sobreposição: contrato admitido DEPOIS do fim do período não participa.');

    $todos = [$admitidoAntesDesligadoDentro, $admitidoDentroAindaAtivo, $admitidoEDesligadoDentro, $encerradoAntesDoInicio, $admitidoDepoisDoFim];
    $ativos = $S::ativosNoPeriodo($todos, $inicio, $fim);
    $assert(count($ativos) === 3, 'ativosNoPeriodo(): só os 3 contratos que sobrepõem o período entram na população.');

    // Fronteira exata: demissao === inicio e admissao === fim participam (inclusive nas duas pontas).
    $demissaoNoInicio = contratoPeriodo(['admissao' => '2025-01-01', 'demissao' => '2026-01-01']);
    $admissaoNoFim = contratoPeriodo(['admissao' => '2026-01-31', 'demissao' => null]);
    $assert($S::contratoSobrepoePeriodo($demissaoNoInicio, $inicio, $fim) === true, 'Fronteira: demissao exatamente no início do período participa (inclusive).');
    $assert($S::contratoSobrepoePeriodo($admissaoNoFim, $inicio, $fim) === true, 'Fronteira: admissao exatamente no fim do período participa (inclusive).');
    $umDiaAntes = contratoPeriodo(['admissao' => '2024-01-01', 'demissao' => '2025-12-31']);
    $umDiaDepois = contratoPeriodo(['admissao' => '2026-02-01', 'demissao' => null]);
    $assert($S::contratoSobrepoePeriodo($umDiaAntes, $inicio, $fim) === false, 'Fronteira: demissao um dia antes do início não participa.');
    $assert($S::contratoSobrepoePeriodo($umDiaDepois, $inicio, $fim) === false, 'Fronteira: admissao um dia depois do fim não participa.');

    // ---- Nova fórmula: 100 ativos, 10 desligamentos -> 10,0% ------------------------------------
    $assert($S::taxaTurnoverPeriodo(10, 100) === 10.0, 'Nova fórmula: 10 desligados / 100 ativos × 100 = 10,0%.');
    $assert($S::taxaTurnoverPeriodo(0, 0) === 0.0, 'Nova fórmula: sem ativos no período, nunca divide por zero — resultado 0.0.');
    $assert($S::taxaTurnoverPeriodo(5, 0) === 0.0, 'Nova fórmula: ativos 0 com desligamentos > 0 continua 0.0 (nunca divisão por zero).');

    // ---- Segmentação: Empresa/Setor/Sexo usam denominadores PRÓPRIOS ----------------------------
    $contratosSegmentacao = [
        contratoPeriodo(['codigo_empresa' => 'EMP_A', 'codigo_setor' => 'SET_A', 'sexo' => 'M', 'admissao' => '2025-01-01', 'demissao' => '2026-01-10']),
        contratoPeriodo(['codigo_empresa' => 'EMP_A', 'codigo_setor' => 'SET_A', 'sexo' => 'M', 'admissao' => '2025-01-01', 'demissao' => null]),
        contratoPeriodo(['codigo_empresa' => 'EMP_A', 'codigo_setor' => 'SET_A', 'sexo' => 'M', 'admissao' => '2025-01-01', 'demissao' => null]),
        contratoPeriodo(['codigo_empresa' => 'EMP_B', 'codigo_setor' => 'SET_B', 'sexo' => 'F', 'admissao' => '2025-01-01', 'demissao' => null]),
        contratoPeriodo(['codigo_empresa' => 'EMP_B', 'codigo_setor' => null, 'sexo' => 'F', 'admissao' => '2025-01-01', 'demissao' => '2026-01-20']),
    ];
    $porEmpresa = $S::turnoverPorDimensaoPeriodo($contratosSegmentacao, 'codigo_empresa', $inicio, $fim);
    $indexado = [];
    foreach ($porEmpresa as $linha) { $indexado[$linha['label']] = $linha; }
    $assert($indexado['EMP_A']['ativos_periodo'] === 3, 'Segmentação por Empresa: EMP_A tem denominador PRÓPRIO (3 ativos), não o total geral (5).');
    $assert($indexado['EMP_A']['desligamentos'] === 1, 'Segmentação por Empresa: EMP_A tem 1 desligamento no período.');
    $assert($indexado['EMP_A']['taxa'] === $S::taxaTurnoverPeriodo(1, 3), 'Segmentação por Empresa: taxa de EMP_A usa a MESMA fórmula, só com a população do grupo.');
    $assert($indexado['EMP_B']['ativos_periodo'] === 2, 'Segmentação por Empresa: EMP_B tem denominador próprio (2 ativos).');
    $assert($indexado['EMP_B']['desligamentos'] === 1, 'Segmentação por Empresa: EMP_B tem 1 desligamento no período.');

    $porSetor = $S::turnoverPorDimensaoPeriodo($contratosSegmentacao, 'codigo_setor', $inicio, $fim);
    $indexadoSetor = [];
    foreach ($porSetor as $linha) { $indexadoSetor[$linha['label']] = $linha; }
    $assert(($indexadoSetor[RhIndicadoresService::NAO_INFORMADO]['ativos_periodo'] ?? null) === 1, 'Segmentação por Setor: codigo_setor nulo cai em NAO_INFORMADO, nunca descartado nem inferido.');

    $porSexo = $S::turnoverPorDimensaoPeriodo($contratosSegmentacao, 'sexo', $inicio, $fim);
    $indexadoSexo = [];
    foreach ($porSexo as $linha) { $indexadoSexo[$linha['label']] = $linha; }
    $assert($indexadoSexo['M']['ativos_periodo'] === 3 && $indexadoSexo['M']['desligamentos'] === 1, 'Segmentação por Sexo: M usa denominador próprio (3 ativos, 1 desligamento).');
    $assert($indexadoSexo['F']['ativos_periodo'] === 2 && $indexadoSexo['F']['desligamentos'] === 1, 'Segmentação por Sexo: F usa denominador próprio (2 ativos, 1 desligamento).');

    // ---- Comparação: mesmo período do ano anterior -----------------------------------------------
    [$compAnoInicio, $compAnoFim] = $S::periodoMesmoIntervaloAnoAnterior(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-06-30'));
    $assert($compAnoInicio->format('Y-m-d') === '2025-01-01', 'Comparativo ano anterior: início vira 01/01/2025.');
    $assert($compAnoFim->format('Y-m-d') === '2025-06-30', 'Comparativo ano anterior: fim vira 30/06/2025.');

    // ---- Comparação: período imediatamente anterior (exemplo do brief: Jan-Jun/2026 -> Jul-Dez/2025)
    [$compAntInicio, $compAntFim] = $S::periodoImediatamenteAnterior(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-06-30'));
    $assert($compAntInicio->format('Y-m-d') === '2025-07-01', 'Comparativo imediatamente anterior (período alinhado a mês): início vira 01/07/2025.');
    $assert($compAntFim->format('Y-m-d') === '2025-12-31', 'Comparativo imediatamente anterior (período alinhado a mês): fim vira 31/12/2025.');

    // Período personalizado (não alinhado a mês) usa a mesma quantidade de dias corridos.
    [$compCustomInicio, $compCustomFim] = $S::periodoImediatamenteAnterior(new DateTimeImmutable('2026-03-15'), new DateTimeImmutable('2026-03-24'));
    $assert($compCustomFim->format('Y-m-d') === '2026-03-14', 'Comparativo imediatamente anterior (intervalo personalizado): fim termina um dia antes do início selecionado.');
    $assert($compCustomInicio->format('Y-m-d') === '2026-03-05', 'Comparativo imediatamente anterior (intervalo personalizado): mesma quantidade de dias corridos (10) imediatamente antes.');

    // ---- serieMensalPeriodo(): um dataset único alimentando Evolução Mensal e Admissões×Desligamentos
    $contratosSerie = [
        contratoPeriodo(['admissao' => '2025-12-01', 'demissao' => null]),
        contratoPeriodo(['admissao' => '2026-01-10', 'demissao' => null]),
        contratoPeriodo(['admissao' => '2025-11-01', 'demissao' => '2026-01-15']),
    ];
    $serie = $S::serieMensalPeriodo($contratosSerie, new DateTimeImmutable('2025-12-01'), new DateTimeImmutable('2026-01-31'));
    $assert(count($serie) === 2, 'serieMensalPeriodo(): 2 meses calendário completos entre dez/2025 e jan/2026.');
    $assert($serie[0]['ativos_periodo'] === 2, 'serieMensalPeriodo(): dezembro tem 2 ativos (os 2 admitidos até então).');
    $assert($serie[0]['admissoes'] === 1, 'serieMensalPeriodo(): dezembro tem 1 admissão.');
    $assert($serie[1]['ativos_periodo'] === 3, 'serieMensalPeriodo(): janeiro tem 3 ativos (o 3º sobrepõe apesar de desligar dia 15).');
    $assert($serie[1]['admissoes'] === 1 && $serie[1]['desligamentos'] === 1, 'serieMensalPeriodo(): janeiro tem 1 admissão e 1 desligamento.');
    $assert($serie[1]['taxa'] === $S::taxaTurnoverPeriodo(1, 3), 'serieMensalPeriodo(): taxa de cada mês usa a nova fórmula (ativos do período do próprio mês).');

    echo "OK unit_rh_indicadores_service_periodo\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
