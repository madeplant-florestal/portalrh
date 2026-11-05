<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Correção de 2026-11 (RH) — RhIndicadoresService::serieMensalPeriodo() nunca pode contar, no mês
 * CORRENTE (em andamento), um desligamento/admissão com data posterior a `$fim`. Antes desta
 * correção, o último mês da série reexpandia para o calendário cheio independente de `$fim`,
 * contando eventos com data futura como já ocorridos — causa exata da divergência relatada pelo
 * RH entre "Turnover Geral" (usa `$fim` direto) e "Admissões × Desligamentos"/"Evolução Mensal"
 * (usavam serieMensalPeriodo() sem o corte). Tudo aqui é static sobre arrays já carregados — sem
 * banco, sem depender da data real do relógio (controla "hoje" via `$fim`, nunca `new
 * DateTimeImmutable('today')`).
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$S = RhIndicadoresService::class;

function contratoFuturo(array $overrides = []): array
{
    return array_merge([
        'codigo_empresa' => '0001', 'empresa' => 'Empresa Teste', 'codigo_setor' => 'S1', 'sexo' => 'M',
        'admissao' => '2025-01-01', 'demissao' => null, 'motivo_rescisao_codigo' => null, 'identificador' => null,
    ], $overrides);
}

try {
    // ---- cenário do RH: mês corrente parcial (hoje simulado = 05/11/2026) ----------------------
    $hojeSimulado = new DateTimeImmutable('2026-11-05');
    $robsonFuturo = contratoFuturo(['demissao' => '2026-11-18', 'identificador' => 'ROBSON']); // desligamento ainda não ocorreu
    $desligamentoJaOcorrido = contratoFuturo(['demissao' => '2026-11-03', 'identificador' => 'JA_OCORREU']); // antes de hoje, no mesmo mês
    $admissaoFutura = contratoFuturo(['admissao' => '2026-11-20', 'identificador' => 'ADMISSAO_FUTURA']); // admissão ainda não ocorreu

    $serieParcial = $S::serieMensalPeriodo(
        [$robsonFuturo, $desligamentoJaOcorrido, $admissaoFutura],
        new DateTimeImmutable('2026-11-01'),
        $hojeSimulado,
    );
    $assert(count($serieParcial) === 1, '(mês corrente) Filtro de um único mês gera 1 ponto na série');
    $assert($serieParcial[0]['desligamentos'] === 1, '(mês corrente) Só o desligamento JÁ OCORRIDO (03/11) conta — o de 18/11 (futuro em relação a 05/11) NUNCA entra');
    $assert($serieParcial[0]['admissoes'] === 0, '(mês corrente) Admissão com data futura (20/11) também não conta — mesma regra, sem assimetria entre admissão e desligamento');

    // ---- controle: desligamento no passado, dentro do mesmo mês, continua contando normalmente -
    $serieSoPassado = $S::serieMensalPeriodo([$desligamentoJaOcorrido], new DateTimeImmutable('2026-11-01'), $hojeSimulado);
    $assert($serieSoPassado[0]['desligamentos'] === 1, '(controle) Desligamento já ocorrido dentro do mês corrente continua contando — o corte não vira "mês atual = zero"');

    // ---- virada de mês / consulta histórica: quando "hoje" avança além do desligamento, ele passa
    // a ser contabilizado normalmente na sua própria competência, sem qualquer alteração no registro.
    $hojeDepoisDaVirada = new DateTimeImmutable('2026-12-02'); // novembro já encerrado
    $serieNovembroCompleto = $S::serieMensalPeriodo(
        [$robsonFuturo],
        new DateTimeImmutable('2026-11-01'),
        new DateTimeImmutable('2026-11-30'), // consulta histórica de um mês já encerrado
        null,
        null,
        null,
    );
    $assert($serieNovembroCompleto[0]['desligamentos'] === 1, '(virada de mês) Depois que a data real chega, o desligamento é contabilizado normalmente em novembro — nenhuma alteração no registro, só o tempo passou');
    unset($hojeDepoisDaVirada); // só documenta a intenção do cenário; a função não lê o relógio real

    // ---- consultas históricas não são limitadas indevidamente pelo dia do mês "hoje" -----------
    // Um período de vários meses já totalmente encerrados (jan a out/2026) não pode ter nenhum dos
    // meses truncado: só o ÚLTIMO mês da série pode ser afetado pelo corte, nunca os anteriores.
    $desligamentoFimDeOutubro = contratoFuturo(['demissao' => '2026-10-31', 'identificador' => 'FIM_OUTUBRO']);
    $serieHistorica = $S::serieMensalPeriodo(
        [$desligamentoFimDeOutubro],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-10-31'),
    );
    $assert(count($serieHistorica) === 10, '(histórico) Período de 10 meses completos gera 10 pontos, nenhum removido');
    $assert($serieHistorica[9]['desligamentos'] === 1, '(histórico) O último dia do último mês do período (31/10, já passado) continua contando — o corte só age quando o mês excede $fim, nunca quando coincide com ele');

    // ---- a série COMPARATIVA (serieMensalComparativa, $forcarQuantidadeMeses) nunca é truncada:
    // ali "$fim" é só um parâmetro posicional sem significado de limite (sempre um período já
    // encerrado no passado) — o corte desta correção não pode alcançá-la.
    $desligamentoNoComparativo = contratoFuturo(['demissao' => '2025-11-18', 'identificador' => 'COMPARATIVO']);
    $comparativa = $S::serieMensalComparativa(
        [$robsonFuturo, $desligamentoNoComparativo],
        new DateTimeImmutable('2026-11-01'),
        $hojeSimulado, // mês corrente parcial
        new DateTimeImmutable('2025-11-01'), // mesmo mês do ano anterior, já totalmente encerrado
    );
    $assert(count($comparativa['atual']) === 1 && $comparativa['atual'][0]['desligamentos'] === 0, '(comparativa) Série atual continua truncando o mês corrente (05/11) — o desligamento futuro de 18/11/2026 continua excluído, mesma garantia de cima');
    $assert(count($comparativa['comparativo']) === 1 && $comparativa['comparativo'][0]['desligamentos'] === 1, '(comparativa) Série comparativa (nov/2025, já encerrada) NÃO é truncada — conta o desligamento de 18/11/2025 inteiro, prova de que o corte não vaza para a série comparativa');

    echo "UNIT_RH_INDICADORES_DESLIGAMENTOS_FUTUROS_OK\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[FALHOU] ' . $e->getMessage() . "\n");
    exit(1);
}
