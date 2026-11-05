<?php

/**
 * Integração — reprodução real (via colaboradores_metadados + PeopleAnalyticsService::
 * montarPainel()) do caso relatado pelo RH em 05/11/2026: um contrato com `demissao` cadastrada
 * para uma data ainda não chegada, dentro do mês corrente, fazia "Admissões × Desligamentos"
 * (evolucao_mensal/admissoes_desligamentos, RhIndicadoresService::serieMensalPeriodo()) contar 1
 * desligamento no mês corrente enquanto o card "Turnover Geral" (mesmo painel, mesmo filtro de um
 * único mês) mostrava 0 — ambos devem concordar.
 *
 * Fixtures ZZDF-* no espelho `colaboradores_metadados`, com limpeza em `finally`. Usa o "hoje" real
 * do relógio (não simulado) — a data da virada de mês de unit_rh_indicadores_service_desligamentos_
 * futuros.php já cobre o cenário de forma 100% determinística; este teste é a confirmação end-to-
 * end com o Service real.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados' => []];
$empresa = 'ZE' . $suffix;

$mk = static function (string $nome, string $admissao, ?string $demissao) use ($pdo, &$criados, $suffix, $empresa): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZDF_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $empresa, 'ZU' . $suffix, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null,
        'ZZDF ' . $nome . ' ' . $suffix, 'ZZDF Empresa ' . $suffix, 'ZZDF Unidade', 'ZZDF Cargo', 'ZZDFCG' . $suffix,
        $admissao, $demissao, $demissao !== null ? '003' : null, $demissao !== null ? 'Pedido de Demissão sem Justa Causa' : null,
        $demissao === null ? 1 : 0, 'zzdf-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

try {
    $hoje = new DateTimeImmutable('today');
    $ultimoDiaDoMes = $hoje->modify('last day of this month');
    if ($ultimoDiaDoMes <= $hoje) {
        echo "  [ok] (edge case) Hoje é o último dia do mês — não há 'data futura dentro do mesmo mês' possível; cenário coberto de forma determinística em unit_rh_indicadores_service_desligamentos_futuros.php, nada a reproduzir aqui hoje.\n";
    } else {
        $demissaoFutura = $ultimoDiaDoMes->format('Y-m-d'); // garante futuro relativo a hoje, dentro do mesmo mês
        $mk('Robson Simulado', '2025-08-30', $demissaoFutura);

        $inicioMes = $hoje->modify('first day of this month');
        $service = new PeopleAnalyticsService();
        $painel = $service->montarPainel(['codigo_empresa' => $empresa], $inicioMes, $hoje);

        $ultimoMesEvolucao = end($painel['evolucao_mensal']['atual']);
        $check($painel['desligamentos']['periodo'] === 0, '(Turnover Geral / Desligamentos) Contrato com demissão futura (' . $demissaoFutura . ') não é contado como desligamento efetivado hoje');
        $check($ultimoMesEvolucao['desligamentos'] === 0, '(Evolução Mensal / Admissões × Desligamentos) O mesmo mês, na mesma série, também não conta o desligamento futuro — antes da correção, aqui dava 1');
        $check($painel['desligamentos']['periodo'] === $ultimoMesEvolucao['desligamentos'], '(coerência) Turnover Geral e Admissões × Desligamentos concordam exatamente para o mesmo período — a divergência relatada pelo RH não se repete');
    }

    // ---- controle: um desligamento já ocorrido no passado continua aparecendo nos dois lados ----
    $dataPassada = (new DateTimeImmutable('today'))->modify('-400 days')->format('Y-m-d');
    // Demissão = hoje (nunca "hoje - N dias", que poderia cair no mês anterior perto da virada do
    // mês e invalidar o teste): continua sendo "já ocorrida" (demissao <= fim), sempre no mesmo mês.
    $demissaoPassada = (new DateTimeImmutable('today'))->format('Y-m-d');
    $mk('Desligado Hoje', $dataPassada, $demissaoPassada);

    $hoje2 = new DateTimeImmutable('today');
    $service2 = new PeopleAnalyticsService();
    $painel2 = $service2->montarPainel(['codigo_empresa' => $empresa], $hoje2->modify('first day of this month'), $hoje2);
    $ultimoMes2 = end($painel2['evolucao_mensal']['atual']);
    $check($painel2['desligamentos']['periodo'] >= 1, '(controle) Desligamento já ocorrido (hoje) é contado em Turnover Geral/Desligamentos');
    $check($ultimoMes2['desligamentos'] >= 1, '(controle) O mesmo desligamento já ocorrido também é contado em Admissões × Desligamentos — o corte não vira "mês atual sempre zero"');
    $check($painel2['desligamentos']['periodo'] === $ultimoMes2['desligamentos'], '(coerência) Com um desligamento real e nenhum futuro, os dois indicadores continuam idênticos');

    echo "\nPEOPLE_ANALYTICS_DESLIGAMENTO_FUTURO_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nPEOPLE_ANALYTICS_DESLIGAMENTO_FUTURO_OK\n";
