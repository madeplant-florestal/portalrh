<?php

/**
 * Bloco 2 — Correções RH no People Analytics (2026-10).
 *
 * Prova que:
 *   - Headcount por Empresa / Turnover por Empresa / Desligamentos por Empresa EXCLUEM as 3
 *     empresas pedidas pelo RH (FOREST SERVICES LTDA = 0001, MC REFLORESTADORA EIRELI = 0003,
 *     CELSO LUIZ MELLO CORREA = 0004 — códigos oficiais de colaboradores_metadados.codigo_empresa,
 *     nunca filtro textual) — SÓ nessas 3 visualizações, por escolha explícita (confirmada via
 *     pergunta ao usuário nesta rodada);
 *   - Headcount Atual, Turnover Geral e Desligamentos (totais) continuam somando TODAS as
 *     empresas, incluindo as 3 excluídas — nenhum dado foi apagado, só a quebra "por Empresa";
 *   - dashboard_multi_line_chart(): a margem inferior cresceu sem alterar a escala/posição dos
 *     pontos (altura útil do gráfico idêntica a antes) — o rótulo de valor "abaixo do ponto" fica
 *     com folga segura em relação ao nome do mês, nunca sobrepondo;
 *   - dashboard_grouped_columns(): com rótulos rotacionados (Colaboradores por Setor), a largura
 *     mínima do contêiner cresce com o número de categorias — mais categorias, mais espaço por
 *     rótulo, sempre dentro de um wrapper com rolagem horizontal PRÓPRIA (nunca a página toda);
 *     sem rotação, o comportamento (560px fixos) continua idêntico a antes (Admissões ×
 *     Desligamentos e as demais telas que reaproveitam o helper não mudam em nada).
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/../../app/views/admin/partials/chart-helpers.php';

SchemaManager::ensure();

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
$criados = ['metadados_identificadores' => []];

$setFixture = 'ZZB2' . $suffix;
$empControle = 'ZZB2' . $suffix . 'C'; // empresa NÃO excluída, usada como controle

try {
    $mkMetadados = static function (
        string $codigoPessoa,
        ?DateTimeImmutable $admissao,
        ?DateTimeImmutable $demissao,
        bool $ativo,
        string $codigoEmpresa,
        string $codigoSetor
    ) use ($pdo, &$criados, $suffix): void {
        $identificador = 'ZZB2_' . $suffix . '_' . $codigoPessoa;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                nome, admissao, demissao, codigo_setor, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $identificador, $codigoEmpresa, 'ZZU' . $suffix, 'ZZC' . $codigoPessoa,
            $codigoPessoa, 'ZZB2 Fixture ' . $codigoPessoa,
            $admissao?->format('Y-m-d'), $demissao?->format('Y-m-d'), $codigoSetor,
            $ativo ? 1 : 0, 'zzb2-teste',
        ]);
        $criados['metadados_identificadores'][] = $identificador;
    };

    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-30 days');
    $fim = $hoje;

    // ================================================================= Headcount por Empresa

    $p1 = 'ZZP' . $suffix . '1';
    $p2 = 'ZZP' . $suffix . '2';
    $p3 = 'ZZP' . $suffix . '3';

    // P1: ativo na empresa EXCLUÍDA (0001 = FOREST SERVICES LTDA).
    $mkMetadados($p1, $hoje->modify('-400 days'), null, true, '0001', $setFixture);
    // P2: ativo na empresa de CONTROLE (não excluída) — deve continuar aparecendo normalmente.
    $mkMetadados($p2, $hoje->modify('-400 days'), null, true, $empControle, $setFixture);
    // P3: ativo na SEGUNDA empresa excluída (0004 = CELSO LUIZ MELLO CORREA).
    $mkMetadados($p3, $hoje->modify('-400 days'), null, true, '0004', $setFixture);

    $service = new PeopleAnalyticsService();
    // Filtro por Setor isola este cenário de qualquer dado real já existente nas empresas 0001/0003/0004.
    $painel = $service->montarPainel(['codigo_setor' => $setFixture], $inicio, $fim);

    $codigosNoHeadcountPorEmpresa = array_column($painel['headcount_por_empresa'], 'codigo');
    $check(!in_array('0001', $codigosNoHeadcountPorEmpresa, true), '(1) Headcount por Empresa NÃO lista a empresa 0001 (FOREST SERVICES LTDA)');
    $check(!in_array('0004', $codigosNoHeadcountPorEmpresa, true), '(1) Headcount por Empresa NÃO lista a empresa 0004 (CELSO LUIZ MELLO CORREA)');
    $check(in_array($empControle, $codigosNoHeadcountPorEmpresa, true), '(1) Headcount por Empresa CONTINUA listando a empresa de controle (não excluída)');

    $check($painel['headcount']['atual'] === 3, '(2) Headcount Atual (total) = 3 — as 2 empresas excluídas CONTINUAM contando no total, só somem da quebra por Empresa');

    $somaHeadcountPorEmpresa = array_sum(array_column($painel['headcount_por_empresa'], 'quantidade'));
    $check($somaHeadcountPorEmpresa === 1, '(2) Soma das barras de Headcount por Empresa = 1 (só a empresa de controle) — diverge do card Headcount Atual DE PROPÓSITO, conforme decisão confirmada');

    // ================================================================= Turnover/Desligamentos por Empresa

    $p4 = 'ZZP' . $suffix . '4';
    $p5 = 'ZZP' . $suffix . '5';

    // P4: desligado dentro do período, empresa EXCLUÍDA (0003 = MC REFLORESTADORA EIRELI).
    $mkMetadados($p4, $hoje->modify('-400 days'), $hoje->modify('-5 days'), false, '0003', $setFixture);
    // P5: desligado dentro do período, empresa de CONTROLE.
    $mkMetadados($p5, $hoje->modify('-400 days'), $hoje->modify('-6 days'), false, $empControle, $setFixture);

    $painel2 = $service->montarPainel(['codigo_setor' => $setFixture], $inicio, $fim);

    $codigosNoDesligamentosPorEmpresa = array_column($painel2['desligamentos_por_empresa'], 'codigo');
    $codigosNoTurnoverPorEmpresa = array_column($painel2['turnover']['por_empresa'], 'codigo');
    $check(!in_array('0003', $codigosNoDesligamentosPorEmpresa, true), '(3) Desligamentos por Empresa NÃO lista a empresa 0003 (MC REFLORESTADORA EIRELI)');
    $check(!in_array('0003', $codigosNoTurnoverPorEmpresa, true), '(3) Turnover por Empresa NÃO lista a empresa 0003 (MC REFLORESTADORA EIRELI)');
    $check(in_array($empControle, $codigosNoDesligamentosPorEmpresa, true), '(3) Desligamentos por Empresa CONTINUA listando a empresa de controle');

    $check($painel2['desligamentos']['periodo'] === 2, '(4) Desligamentos (total do período) = 2 — o desligamento da empresa excluída CONTINUA contando no total');

    // ================================================================= Chart helpers — geometria

    $labels = ['Jan', 'Fev', 'Mar'];
    $svgLinha = dashboard_multi_line_chart($labels, [
        ['label' => 'Atual', 'color' => '#A9B885', 'values' => [0.0, 5.0, 2.0]],
        ['label' => 'Comparativo', 'color' => '#3B4822', 'values' => [8.0, 3.0, 9.0]],
    ], '%', 1, 'Teste geometria');
    $check(str_contains($svgLinha, 'viewBox="0 0 720 300"'), '(5) dashboard_multi_line_chart(): altura do SVG cresceu de 280 para 300 (mais respiro abaixo do gráfico)');

    preg_match_all('/<text x="[\d.]+" y="([\d.]+)"[^>]*font-size="11"[^>]*>Jan<\/text>/', $svgLinha, $mesMatch);
    preg_match_all('/<text x="[\d.]+" y="([\d.]+)"[^>]*font-size="10" font-weight="600"[^>]*>0,0%<\/text>/', $svgLinha, $valorMatch);
    $check($mesMatch[1] !== [] && $valorMatch[1] !== [], '(5) Consegui extrair a posição Y do rótulo do mês "Jan" e do valor "0,0%" (ponto no mínimo da escala, pior caso de sobreposição)');
    if ($mesMatch[1] !== [] && $valorMatch[1] !== []) {
        $yMes = (float)$mesMatch[1][0];
        $yValor = (float)$valorMatch[1][0];
        $check(($yMes - $yValor) >= 20.0, '(5) Folga vertical entre o rótulo do mês e o valor do ponto mais baixo (pior caso) é de pelo menos 20px — antes da correção era ~6px');
    }

    $svgColunasRotacionado = dashboard_grouped_columns(
        array_fill(0, 12, 'SETOR TESTE LONGO'),
        [['label' => 'Atual', 'color' => '#A9B885', 'values' => array_fill(0, 12, 5)]],
        'Teste colunas rotacionadas',
        ['rotacionar_eixo_x' => true]
    );
    $check(str_contains($svgColunasRotacionado, 'style="min-width: 840.00px"'), '(6) dashboard_grouped_columns() com 12 categorias rotacionadas: largura mínima = max(560, 12*70) = 840px');

    $svgColunasPoucasCategorias = dashboard_grouped_columns(
        ['A', 'B'],
        [['label' => 'Atual', 'color' => '#A9B885', 'values' => [1, 2]]],
        'Teste poucas categorias rotacionadas',
        ['rotacionar_eixo_x' => true]
    );
    $check(str_contains($svgColunasPoucasCategorias, 'style="min-width: 560.00px"'), '(6) Com poucas categorias (2), a largura mínima NUNCA fica menor que o piso de 560px');

    $svgColunasSemRotacao = dashboard_grouped_columns($labels, [
        ['label' => 'Admissões', 'color' => '#A9B885', 'values' => [1, 2, 3]],
    ], 'Teste sem rotação');
    $check(str_contains($svgColunasSemRotacao, 'style="min-width: 560.00px"'), '(6) Sem rotação (ex.: Admissões × Desligamentos), a largura mínima continua EXATAMENTE 560px — nenhuma mudança visual para quem não usa rótulos rotacionados');
    $check(str_contains($svgColunasSemRotacao, '<div class="overflow-x-auto">'), '(6) Rolagem horizontal interna (do cartão do gráfico, nunca da página) continua presente em ambos os modos');

    echo "PEOPLE_ANALYTICS_BLOCO2_RH_OK\n";
} finally {
    foreach ($criados['metadados_identificadores'] as $identificador) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE identificador = ?')->execute([$identificador]);
    }

    if ($falhas !== []) {
        fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
        exit(1);
    }
}
