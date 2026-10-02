<?php
/**
 * People Analytics — bloco de RESULTADO (Etapa 2, correção de 2026-09: click-to-filter, filtros
 * acumulativos e listagem contextual). Extraído de admin/dashboard.php para ser reutilizado tanto
 * no carregamento normal da página (via `include`, mesmo escopo de variáveis) quanto no endpoint
 * `AdminController::dadosDashboard()` (via `View::renderPartial()`, devolvendo o HTML pronto para
 * o front trocar via fetch) — nunca duas implementações da mesma tela. Espera no escopo: $painel,
 * $erro, $listagem, $opcoesFiltro, $filtrosSelecionados (empresa/setor), $filtrosInterativos
 * (sexo/motivo_categoria/contexto_lista/mes_evento), $comparativos, $comparativoSelecionado.
 *
 * Interatividade: clique em elementos de gráfico (Empresa/Setor/Sexo/Motivo/Admissões/
 * Desligamentos) marcados com data-pa-dimensao/data-pa-valor (ver chart-helpers.php,
 * dashboard_clique_attrs()) — o JavaScript (assets/people-analytics-interativo.js) só lê esses
 * atributos, monta a query string e busca o HTML atualizado; nenhum cálculo de indicador
 * acontece no cliente (§9 da correção).
 */
require_once __DIR__ . '/../chart-helpers.php';
// Nota para o Tailwind JIT (content só varre *.php, não assets/*.js): a classe "pa-atualizando"
// é adicionada/removida via JS (people-analytics-interativo.js) durante o fetch — precisa
// aparecer aqui, literalmente, para o build não descartar a regra de assets/tailwind-input.css.

$pa = [
    'brand50' => '#F2F4EC', 'brand100' => '#E4E9D6', 'brand200' => '#A9B885', 'brand300' => '#819158',
    'brand400' => '#566B41', 'brand500' => '#3B4822', 'brand600' => '#2E3919', 'brand700' => '#232B13',
    'ink' => '#2B2E22', 'inkSoft' => '#5B5F4E', 'border' => '#E2DFD0', 'danger' => '#B23B3B',
];

$fmtN = static fn($v): string => number_format((float)$v, 0, ',', '.');
$fmtPct1 = static fn($v): string => number_format((float)$v, 1, ',', '.') . '%';

$fmtVariacao = static function (int $variacaoAbsoluta, ?float $variacaoPercentual, string $unidade = ''): string {
    $seta = $variacaoAbsoluta > 0 ? '↑' : ($variacaoAbsoluta < 0 ? '↓' : '→');
    $abs = ($variacaoAbsoluta > 0 ? '+' : '') . number_format($variacaoAbsoluta, 0, ',', '.') . $unidade;
    $pct = $variacaoPercentual === null ? '' : ' (' . ($variacaoPercentual > 0 ? '+' : '') . number_format($variacaoPercentual, 1, ',', '.') . '%)';
    return $seta . ' ' . $abs . $pct;
};
$fmtVariacaoPP = static function (float $pp): string {
    $seta = $pp > 0 ? '↑' : ($pp < 0 ? '↓' : '→');
    return $seta . ' ' . ($pp > 0 ? '+' : '') . number_format($pp, 1, ',', '.') . ' p.p.';
};

$secaoDivisor = static function (string $rotulo) use ($pa): string {
    return '<div class="flex items-center gap-3 pt-1">'
        . '<span class="text-[11px] font-bold uppercase tracking-wider" style="color:' . Security::e($pa['brand600']) . '">' . Security::e($rotulo) . '</span>'
        . '<span class="h-px flex-1 bg-border"></span>'
        . '</div>';
};

$estadoVazio = static function (string $mensagem, string $icone = 'users'): string {
    return '<div class="mt-3 flex flex-col items-center justify-center gap-2 rounded-ds-md border border-dashed border-border bg-background py-7 text-center">'
        . '<svg class="h-7 w-7 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor">' . dashboard_icon($icone) . '</svg>'
        . '<p class="max-w-[220px] text-[12px] text-text-secondary">' . Security::e($mensagem) . '</p>'
        . '</div>';
};

$filtrosInterativos = $filtrosInterativos ?? ['sexo' => '', 'motivo_categoria' => '', 'contexto_lista' => 'ativos', 'mes_evento' => ''];
$opcoesFiltro = $opcoesFiltro ?? ['empresas' => [], 'setores' => []];
?>
<div id="pa-resultado" class="space-y-5" data-pa-container="1"
     data-pa-periodo="<?= Security::e($periodoSelecionado ?? '12m') ?>"
     data-pa-mes="<?= Security::e($periodoParams['mes'] ?? '') ?>"
     data-pa-ano="<?= Security::e($periodoParams['ano'] ?? '') ?>"
     data-pa-data-inicio="<?= Security::e($periodoParams['data_inicio'] ?? '') ?>"
     data-pa-data-fim="<?= Security::e($periodoParams['data_fim'] ?? '') ?>"
     data-pa-comparativo="<?= Security::e($comparativoSelecionado ?? 'ano_anterior') ?>"
     data-pa-empresa="<?= Security::e($filtrosSelecionados['empresa'] ?? '') ?>"
     data-pa-setor="<?= Security::e($filtrosSelecionados['setor'] ?? '') ?>"
     data-pa-sexo="<?= Security::e($filtrosInterativos['sexo'] ?? '') ?>"
     data-pa-motivo="<?= Security::e($filtrosInterativos['motivo_categoria'] ?? '') ?>"
     data-pa-contexto-lista="<?= Security::e($filtrosInterativos['contexto_lista'] ?? 'ativos') ?>"
     data-pa-mes-evento="<?= Security::e($filtrosInterativos['mes_evento'] ?? '') ?>"
     data-pa-pagina="<?= Security::e((string)($listagem['page'] ?? 1)) ?>"
     data-pa-por-pagina="<?= Security::e((string)($listagem['per_page'] ?? 20)) ?>">

  <!-- Chips de filtros interativos ativos (§5) + Limpar filtros (§6) -->
  <?php
    $chipsAtivos = [];
    if (($filtrosSelecionados['empresa'] ?? '') !== '') {
        $nomeEmpresa = $filtrosSelecionados['empresa'];
        foreach ($opcoesFiltro['empresas'] as $e) {
            if ($e['codigo_empresa'] === $filtrosSelecionados['empresa']) { $nomeEmpresa = $e['empresa'] ?? $nomeEmpresa; break; }
        }
        $chipsAtivos[] = ['dimensao' => 'empresa', 'label' => 'Empresa: ' . $nomeEmpresa];
    }
    if (($filtrosSelecionados['setor'] ?? '') !== '') {
        if ($filtrosSelecionados['setor'] === ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO) {
            $nomeSetor = 'Setor não informado';
        } else {
            $nomeSetor = $filtrosSelecionados['setor'];
            foreach ($opcoesFiltro['setores'] as $s) {
                if ($s['codigo_setor'] === $filtrosSelecionados['setor']) { $nomeSetor = $s['nome'] ?? $nomeSetor; break; }
            }
        }
        $chipsAtivos[] = ['dimensao' => 'setor', 'label' => 'Setor: ' . $nomeSetor];
    }
    if (($filtrosInterativos['sexo'] ?? '') !== '') {
        $nomeSexo = ['M' => 'Masculino', 'F' => 'Feminino', 'nao_informado' => 'Não informado'][$filtrosInterativos['sexo']] ?? $filtrosInterativos['sexo'];
        $chipsAtivos[] = ['dimensao' => 'sexo', 'label' => 'Sexo: ' . $nomeSexo];
    }
    if (($filtrosInterativos['motivo_categoria'] ?? '') !== '') {
        $chipsAtivos[] = ['dimensao' => 'motivo', 'label' => 'Motivo: ' . $filtrosInterativos['motivo_categoria']];
    }
    if (($filtrosInterativos['contexto_lista'] ?? 'ativos') !== 'ativos') {
        $rotuloContexto = $filtrosInterativos['contexto_lista'] === 'desligados' ? 'Desligados no período' : 'Admitidos no período';
        $chipsAtivos[] = ['dimensao' => 'contexto_lista', 'label' => 'Listagem: ' . $rotuloContexto];
    }
    if (($filtrosInterativos['mes_evento'] ?? '') !== '') {
        $chipsAtivos[] = ['dimensao' => 'mes_evento', 'label' => 'Mês: ' . $filtrosInterativos['mes_evento']];
    }
  ?>
  <div class="flex flex-wrap items-center gap-2" data-pa-chips="1">
    <?php foreach ($chipsAtivos as $chip): ?>
      <span class="pa-filtro-chip" data-pa-chip-dimensao="<?= Security::e($chip['dimensao']) ?>">
        <?= Security::e($chip['label']) ?>
        <button type="button" data-pa-remover-chip="<?= Security::e($chip['dimensao']) ?>" aria-label="Remover filtro <?= Security::e($chip['label']) ?>">×</button>
      </span>
    <?php endforeach; ?>
    <?php if ($chipsAtivos !== []): ?>
      <button type="button" data-pa-limpar-filtros="1" class="text-[11px] font-medium text-text-secondary underline decoration-dotted hover:text-text-primary">Limpar filtros</button>
    <?php endif; ?>
  </div>

  <?php if ($erro !== null): ?>
    <section class="rounded-ds-lg border border-danger/30 bg-danger/10 p-4">
      <p class="text-sm font-semibold text-danger"><?= Security::e($erro) ?></p>
    </section>
  <?php else: ?>

  <!-- B — Faixa executiva de KPIs -->
  <?php
    $kpis = [
        [
            'icone' => 'users', 'label' => 'Headcount Atual', 'valor' => $fmtN($painel['headcount']['atual']),
            'variacao' => $fmtVariacao($painel['headcount']['variacao_absoluta'], $painel['headcount']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'refresh', 'label' => 'Turnover Geral', 'valor' => $fmtPct1($painel['turnover']['geral_percentual']),
            'variacao' => $fmtVariacaoPP($painel['turnover']['comparativo']['variacao_pontos_percentuais']), 'comparativo' => true,
        ],
        [
            'icone' => 'calendar', 'label' => 'Admissões', 'valor' => $fmtN($painel['admissoes']['periodo']),
            'variacao' => $fmtVariacao($painel['admissoes']['variacao_absoluta'], $painel['admissoes']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'exit', 'label' => 'Desligamentos', 'valor' => $fmtN($painel['desligamentos']['periodo']),
            'variacao' => $fmtVariacao($painel['desligamentos']['variacao_absoluta'], $painel['desligamentos']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'target', 'label' => 'Vagas Abertas', 'valor' => $fmtN($painel['vagas']['abertas']),
            'variacao' => $painel['vagas']['empresa_sem_correspondencia'] ? 'sem correspondência' : ($fmtN($painel['vagas']['fechadas_no_periodo']) . ' fechadas no período'), 'comparativo' => false,
        ],
    ];
  ?>
  <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
    <?php foreach ($kpis as $kpi): ?>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-elevated">
        <div class="flex items-center gap-2.5">
          <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-ds-md bg-primary-50 text-primary-700">
            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= dashboard_icon($kpi['icone']) ?></svg>
          </span>
          <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary"><?= Security::e($kpi['label']) ?></p>
        </div>
        <p class="mt-3 text-ds-kpi text-text-primary"><?= Security::e($kpi['valor']) ?></p>
        <p class="mt-2 border-t border-border pt-2 text-[11px] text-text-secondary"><?= Security::e($kpi['variacao']) ?><?php if ($kpi['comparativo']): ?> <span class="text-text-muted">vs. comparativo</span><?php endif; ?></p>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- C — Turnover Geral executivo: número + comparação + composição (bloco protagonista) -->
  <section class="rounded-ds-lg border border-primary-100 bg-surface p-5 shadow-elevated">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-ds-h3 text-text-primary">Turnover Geral</h2>
      <p class="text-[11px] text-text-secondary">Desligados ÷ colaboradores no período · <?= $fmtN($painel['turnover']['ativos_periodo']) ?> colaboradores no período</p>
    </div>
    <div class="mt-3 flex flex-col gap-5 lg:flex-row lg:items-center">
      <div class="flex-shrink-0 rounded-ds-md bg-primary-50 px-6 py-4 text-center lg:w-[240px]">
        <p class="text-[56px] font-extrabold leading-none text-primary-700"><?= Security::e($fmtPct1($painel['turnover']['geral_percentual'])) ?></p>
        <p class="mt-2 text-[12px] font-medium text-text-secondary"><?= Security::e($fmtVariacaoPP($painel['turnover']['comparativo']['variacao_pontos_percentuais'])) ?> vs. <?= Security::e($fmtPct1($painel['turnover']['comparativo']['percentual'])) ?></p>
        <p class="mt-1 text-[11px] text-text-muted"><?= $fmtN($painel['desligamentos']['periodo']) ?> desligamento(s)</p>
      </div>
      <div class="hidden h-24 w-px bg-border lg:block"></div>
      <?php
        $totalDeslPeriodo = $painel['desligamentos']['periodo'];
        $tv = $painel['turnover']['voluntario'];
        $ti = $painel['turnover']['involuntario'];
        $to = $painel['turnover']['outros'];
      ?>
      <div class="flex-1">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Composição dos Desligamentos</p>
        <?php if ($totalDeslPeriodo === 0): ?>
          <p class="mt-2 text-[11px] text-text-secondary">Sem desligamentos no período.</p>
        <?php else: ?>
          <?php
            $segmentosRosca = [];
            if ($tv['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Voluntário', 'value' => $tv['participacao_desligamentos'], 'color' => $pa['brand500']]; }
            if ($ti['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Involuntário', 'value' => $ti['participacao_desligamentos'], 'color' => $pa['brand200']]; }
            if ($to['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Outros', 'value' => $to['participacao_desligamentos'], 'color' => $pa['inkSoft']]; }
          ?>
          <div class="mt-2.5 flex items-center gap-5">
            <div class="w-[104px] flex-shrink-0"><?= dashboard_donut($segmentosRosca, 104, 15) ?></div>
            <ul class="space-y-2 text-sm text-text-primary">
              <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['brand500']) ?>"></span><span><strong><?= number_format($tv['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$tv['eventos'] ?> Voluntário</span></li>
              <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['brand200']) ?>"></span><span><strong><?= number_format($ti['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$ti['eventos'] ?> Involuntário</span></li>
              <?php if ($to['eventos'] > 0): ?>
                <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['inkSoft']) ?>"></span><span><strong><?= number_format($to['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$to['eventos'] ?> Outros</span></li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php
    $serieAtual = $painel['evolucao_mensal']['atual'];
    $serieComp = $painel['evolucao_mensal']['comparativo'];
    $nPontos = max(count($serieAtual), count($serieComp));
    $labelsEvolucao = array_pad(array_column($serieAtual, 'label'), $nPontos, '');
    $pad = static fn(array $col, int $n): array => array_pad($col, $n, null);
    $taxaAtual = $pad(array_column($serieAtual, 'taxa'), $nPontos);
    $taxaComp = $pad(array_column($serieComp, 'taxa'), $nPontos);

    // Tooltip da Evolução Mensal (correção de nomenclatura de 2026-09): nunca "ativos" como
    // denominador — mostra Desligamentos e "Colaboradores no mês" (mesmos números já calculados
    // em $serieAtual/$serieComp, nenhum cálculo novo). "Colaboradores no mês" porque cada ponto É
    // um mês (mais específico que "no período", que descreve o intervalo inteiro).
    $extraTooltipMensal = static fn(array $serie): array => array_map(
        static fn(array $ponto): string => 'Desligamentos: ' . number_format($ponto['desligamentos'], 0, ',', '.') . ' · Colaboradores no mês: ' . number_format($ponto['ativos_periodo'], 0, ',', '.'),
        $serie
    );
    $extraAtual = $extraTooltipMensal($serieAtual);
    $extraComp = $extraTooltipMensal($serieComp);

    // Mês de evento por ponto da série (Etapa 2, §18/§19) — reconstrói a MESMA sequência mês a mês
    // que RhIndicadoresService::serieMensalPeriodo() usa internamente (a partir do 1º dia do mês
    // de início do período, +1 mês por ponto), nunca lida como string a partir do rótulo já
    // formatado ("jan/26"). Clicar numa barra de Admissões/Desligamentos usa esse mês como
    // `mes_evento` da listagem — nunca move o período GLOBAL do dashboard.
    $mesesEventoSerie = [];
    $cursorMesEvento = $painel['periodo']['inicio']->modify('first day of this month');
    for ($k = 0; $k < count($serieAtual); $k++) {
        $mesesEventoSerie[] = $cursorMesEvento->format('Y-m');
        $cursorMesEvento = $cursorMesEvento->modify('+1 month');
    }
    $cliqueSerieAdmDesl = [];
    foreach ($mesesEventoSerie as $i => $mesEv) {
        $cliqueSerieAdmDesl[$i] = [
            0 => ['dimensao' => 'contexto_lista', 'valor' => 'admitidos', 'extra' => ['mes-evento' => $mesEv],
                  'ativo' => $filtrosInterativos['contexto_lista'] === 'admitidos' && $filtrosInterativos['mes_evento'] === $mesEv],
            1 => ['dimensao' => 'contexto_lista', 'valor' => 'desligados', 'extra' => ['mes-evento' => $mesEv],
                  'ativo' => $filtrosInterativos['contexto_lista'] === 'desligados' && $filtrosInterativos['mes_evento'] === $mesEv],
        ];
    }
  ?>

  <!-- D e E — Evolução Mensal e Admissões × Desligamentos -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Evolução Mensal do Turnover</h2>
      <p class="text-[11px] text-text-secondary">Período selecionado × comparativo, por mês</p>
      <div class="mt-2">
        <?= dashboard_chart_legend([
            ['label' => 'Período selecionado', 'color' => $pa['brand500']],
            ['label' => 'Comparativo', 'color' => $pa['brand200']],
        ]) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_multi_line_chart($labelsEvolucao, [
            ['label' => 'Período selecionado', 'color' => $pa['brand500'], 'values' => $taxaAtual, 'extra' => $extraAtual],
            ['label' => 'Comparativo', 'color' => $pa['brand200'], 'values' => $taxaComp, 'extra' => $extraComp],
        ], '%', 1, 'Evolução mensal do turnover, período selecionado comparado ao comparativo') ?>
      </div>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Admissões × Desligamentos</h2>
      <p class="text-[11px] text-text-secondary">Contratos por mês, no período selecionado · clique numa barra para ver a listagem daquele mês</p>
      <div class="mt-2">
        <?= dashboard_chart_legend([
            ['label' => 'Admissões', 'color' => $pa['brand500']],
            ['label' => 'Desligamentos', 'color' => $pa['brand200']],
        ]) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_grouped_columns(array_column($serieAtual, 'label'), [
            ['label' => 'Admissões', 'color' => $pa['brand500'], 'values' => array_column($serieAtual, 'admissoes')],
            ['label' => 'Desligamentos', 'color' => $pa['brand200'], 'values' => array_column($serieAtual, 'desligamentos')],
        ], 'Admissões e desligamentos por mês no período selecionado', ['clique_serie' => $cliqueSerieAdmDesl]) ?>
      </div>
    </article>
  </section>

  <!-- Visão comparativa: Empresa e Setor (comparável lado a lado) -->
  <?= $secaoDivisor('Visão comparativa') ?>

  <!-- Headcount por Empresa e Turnover por Empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Headcount por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Colaboradores ativos atualmente · clique numa empresa para filtrar a página</p>
      <?php if ($painel['headcount_por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum contrato ativo para os filtros selecionados.') ?>
      <?php else: ?>
        <?php
          $headcountEmpresaItems = array_map(static function (array $item) use ($filtrosSelecionados): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['quantidade'],
                  'display' => number_format($item['quantidade'], 0, ',', '.'),
                  'clique' => ['dimensao' => 'empresa', 'valor' => $item['codigo'], 'ativo' => $filtrosSelecionados['empresa'] === $item['codigo']],
              ];
          }, $painel['headcount_por_empresa']);
        ?>
        <div class="mt-2.5"><?= dashboard_vertical_bars($headcountEmpresaItems, 'bg-primary-600') ?></div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Cada Empresa com sua própria população · mesma ordem do Headcount acima</p>
      <?php if ($painel['turnover']['por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.', 'refresh') ?>
      <?php else: ?>
        <?php
          $turnoverEmpresaItems = array_map(static function (array $item) use ($filtrosSelecionados): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['taxa'],
                  'display' => number_format($item['taxa'], 1, ',', '.') . '%',
                  'clique' => ['dimensao' => 'empresa', 'valor' => $item['codigo'], 'ativo' => $filtrosSelecionados['empresa'] === $item['codigo']],
              ];
          }, $painel['turnover']['por_empresa']);
        ?>
        <div class="mt-2.5"><?= dashboard_vertical_bars($turnoverEmpresaItems, 'bg-primary-400') ?></div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Visão segmentada: Setor (Turnover + Comparativo lado a lado), Sexo, Motivo -->
  <?= $secaoDivisor('Visão segmentada') ?>

  <section class="grid grid-cols-1 gap-4 items-stretch xl:grid-cols-2">
    <article class="flex flex-col rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Setor</h2>
      <p class="text-[11px] text-text-secondary">Cada Setor com sua própria população · "Setor não informado" nunca é omitido · clique para filtrar</p>
      <?php if ($painel['turnover']['por_setor'] === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.', 'refresh') ?>
      <?php else: ?>
        <?php $maxSetorTurnover = max(array_column($painel['turnover']['por_setor'], 'taxa')) ?: 1; ?>
        <div class="mt-3 space-y-2 overflow-y-auto" style="max-height:340px">
          <?php foreach ($painel['turnover']['por_setor'] as $item): ?>
            <?php $valorSetorClique = $item['codigo'] === '' ? ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO : $item['codigo']; ?>
            <?= dashboard_bar_row(
                $item['label'], $item['taxa'], $maxSetorTurnover,
                number_format($item['taxa'], 1, ',', '.') . '% · ' . $item['desligamentos'] . ' deslig. · ' . $item['ativos_periodo'] . ' colaboradores no período',
                $item['codigo'] === '' ? 'bg-text-muted' : 'bg-primary-600',
                ['dimensao' => 'setor', 'valor' => $valorSetorClique, 'ativo' => $filtrosSelecionados['setor'] === $valorSetorClique]
            ) ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="flex flex-col rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Colaboradores por Setor — Comparativo</h2>
      <p class="text-[11px] text-text-secondary">Colaboradores que estiveram ativos em algum momento do período · clique numa coluna para filtrar</p>
      <?php
        $porSetorComp = $painel['colaboradores_por_setor_comparativo'];
        $limiteSetorComp = 12;
        $porSetorCompExibir = array_slice($porSetorComp, 0, $limiteSetorComp);
        $naoInformadoComp = null;
        foreach ($porSetorComp as $l) {
            if ($l['codigo'] === '') { $naoInformadoComp = $l; break; }
        }
        if ($naoInformadoComp !== null && !in_array($naoInformadoComp, $porSetorCompExibir, true)) {
            array_splice($porSetorCompExibir, -1, 1, [$naoInformadoComp]);
        }
        $cliqueCategoriaSetor = [];
        foreach ($porSetorCompExibir as $i => $item) {
            $valorSetor = $item['codigo'] === '' ? ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO : $item['codigo'];
            $cliqueCategoriaSetor[$i] = ['dimensao' => 'setor', 'valor' => $valorSetor, 'ativo' => $filtrosSelecionados['setor'] === $valorSetor];
        }
      ?>
      <?php if ($porSetorComp === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.') ?>
      <?php else: ?>
        <div class="mt-2">
          <?= dashboard_chart_legend([
              ['label' => 'Período selecionado', 'color' => $pa['brand500']],
              ['label' => 'Comparativo', 'color' => $pa['brand200']],
          ]) ?>
        </div>
        <div class="mt-1">
          <?php /* Ajuste visual (2026-10, pedido do RH): barras horizontais agrupadas — nomes de
                   Setor por extenso, sem rotação/truncamento; o card cresce verticalmente em vez
                   de exigir scroll horizontal. Mesma fonte de dados/clique de antes. */ ?>
          <?= dashboard_grouped_bar_rows(array_column($porSetorCompExibir, 'label'), [
              ['label' => 'Período selecionado', 'color' => $pa['brand500'], 'values' => array_column($porSetorCompExibir, 'atual')],
              ['label' => 'Comparativo', 'color' => $pa['brand200'], 'values' => array_column($porSetorCompExibir, 'comparativo')],
          ], ['clique_categoria' => $cliqueCategoriaSetor]) ?>
        </div>
        <?php if (count($porSetorComp) > count($porSetorCompExibir)): ?>
          <p class="mt-1.5 text-[11px] text-text-muted">Exibindo os <?= count($porSetorCompExibir) ?> principais de <?= count($porSetorComp) ?> setores · "Setor não informado" sempre incluído.</p>
        <?php endif; ?>
      <?php endif; ?>
    </article>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Turnover por Sexo</h2>
    <p class="text-[11px] text-text-secondary">Mesma metodologia do Turnover Geral, segmentada pelo sexo oficial do METADADOS · clique para filtrar</p>
    <?php $genero = $painel['turnover']['genero']; ?>
    <div class="mt-2.5 grid grid-cols-1 gap-2.5 sm:grid-cols-2<?= $genero['nao_informado'] !== null ? ' lg:grid-cols-3' : '' ?>">
      <?php foreach ([['label' => 'Masculino', 'valor' => 'M', 'dado' => $genero['masculino']], ['label' => 'Feminino', 'valor' => 'F', 'dado' => $genero['feminino']]] as $bloco): ?>
        <?php $cliqueSexo = ['dimensao' => 'sexo', 'valor' => $bloco['valor'], 'ativo' => $filtrosInterativos['sexo'] === $bloco['valor']]; ?>
        <div<?= dashboard_clique_attrs($cliqueSexo, $bloco['label']) ?> class="rounded-ds-md bg-background p-3.5 text-center<?= dashboard_clique_class($cliqueSexo) ?>">
          <p class="text-[11px] font-semibold text-text-secondary"><?= Security::e($bloco['label']) ?></p>
          <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format($bloco['dado']['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary"><?= (int)$bloco['dado']['desligamentos'] ?> desligamento(s) · <?= (int)$bloco['dado']['ativos_periodo'] ?> colaboradores no período</p>
          <?php if ($bloco['dado']['comparativo_taxa'] !== null): ?>
            <p class="mt-1 text-[11px] text-text-muted"><?= Security::e($fmtVariacaoPP(round($bloco['dado']['taxa'] - $bloco['dado']['comparativo_taxa'], 1))) ?> vs. <?= number_format($bloco['dado']['comparativo_taxa'], 1, ',', '.') ?>%</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($genero['nao_informado'] !== null): ?>
        <?php $naoInformado = $genero['nao_informado']; ?>
        <?php $cliqueSexoNI = ['dimensao' => 'sexo', 'valor' => 'nao_informado', 'ativo' => $filtrosInterativos['sexo'] === 'nao_informado']; ?>
        <div<?= dashboard_clique_attrs($cliqueSexoNI, 'Não informado') ?> class="rounded-ds-md bg-background p-3.5 text-center<?= dashboard_clique_class($cliqueSexoNI) ?>">
          <p class="text-[11px] font-semibold text-text-secondary">Não informado</p>
          <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format($naoInformado['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary"><?= (int)$naoInformado['desligamentos'] ?> desligamento(s) · <?= (int)$naoInformado['ativos_periodo'] ?> colaboradores no período</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Desligamentos por Motivo</h2>
    <p class="text-[11px] text-text-secondary">Mesma classificação do Dashboard de Turnover · códigos não mapeados entram em Outros · clique para filtrar a listagem</p>
    <?php $motivos = $painel['desligamentos_por_motivo']; ?>
    <?php if ($motivos['total'] === 0): ?>
      <?= $estadoVazio('Nenhum desligamento no período.', 'exit') ?>
    <?php else: ?>
      <?php $maxMotivo = max(array_column($motivos['categorias'], 'quantidade')) ?: 1; ?>
      <div class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 lg:grid-cols-2">
        <?php foreach ($motivos['categorias'] as $c): ?>
          <?= dashboard_bar_row(
              $c['categoria'], $c['quantidade'], $maxMotivo,
              $c['quantidade'] . ' · ' . number_format((float)$c['percentual'], 1, ',', '.') . '%', 'bg-primary-600',
              ['dimensao' => 'motivo', 'valor' => $c['categoria'], 'ativo' => $filtrosInterativos['motivo_categoria'] === $c['categoria']]
          ) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Recrutamento e Seleção: visão executiva (Etapa 3, 2026-09) — reaproveita INTEGRALMENTE
       RecrutamentoIndicadoresService::montarPainel() via PeopleAnalyticsService::montarRecrutamentoExecutivo();
       nenhum cálculo de Funil/Tempo por Etapa/Vagas é duplicado aqui. Só Empresa e período se
       aplicam (Setor/Sexo/Motivo não têm relação segura com Recrutamento). -->
  <?= $secaoDivisor('Recrutamento e Seleção') ?>

  <?php $rec = $painel['recrutamento']; ?>
  <?php if (!$rec['disponivel']): ?>
    <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <?= $estadoVazio('A Empresa selecionada ainda não tem correspondência no catálogo local de Recrutamento — indicadores de Recrutamento ficam indisponíveis para este filtro.', 'refresh') ?>
    </section>
  <?php else: ?>
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
      <div class="rounded-ds-lg border border-border bg-surface p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Vagas Abertas</p>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($rec['vagas']['abertas']) ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Vagas Fechadas</p>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($rec['vagas']['fechadas_no_periodo']) ?></p>
        <p class="text-[11px] text-text-secondary">no período</p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Candidatos no Processo</p>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($rec['candidatos_cohort']) ?></p>
        <p class="text-[11px] text-text-secondary">coorte do período</p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Admitidos via Recrutamento</p>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($rec['admitidos']) ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Tempo Médio de Contratação</p>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $rec['tempo_contratacao']['media_dias'] === null ? 'Dados insuficientes' : number_format($rec['tempo_contratacao']['media_dias'], 1, ',', '.') . ' dias' ?></p>
      </div>
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <h2 class="text-ds-h3 text-text-primary">Funil de Recrutamento</h2>
        <p class="text-[11px] text-text-secondary">Candidatos da coorte do período com passagem real por cada etapa</p>
        <?php if ($rec['funil'] === [] || $rec['candidatos_cohort'] === 0): ?>
          <?= $estadoVazio('Nenhuma candidatura no período/filtros selecionados.', 'refresh') ?>
        <?php else: ?>
          <?php $maxFunilPa = $rec['funil'][0]['quantidade'] ?: 1; ?>
          <div class="mt-3 space-y-2.5">
            <?php foreach ($rec['funil'] as $etapa): ?>
              <?= dashboard_bar_row($etapa['label'], $etapa['quantidade'], $maxFunilPa, (string)$etapa['quantidade'], 'bg-primary-600') ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <h2 class="text-ds-h3 text-text-primary">Tempo Médio por Etapa</h2>
        <p class="text-[11px] text-text-secondary">Só passagens concluídas (entrada → próxima movimentação) entram na média</p>
        <?php $mediasEtapaPa = array_map(static fn(array $e): float => $e['media_dias'] ?? 0.0, $rec['tempo_por_etapa']); ?>
        <?php $maxTempoPa = $mediasEtapaPa !== [] ? (max($mediasEtapaPa) ?: 1) : 1; ?>
        <div class="mt-3 space-y-2.5">
          <?php foreach ($rec['tempo_por_etapa'] as $etapa): ?>
            <?= dashboard_bar_row(
                $etapa['label'], $etapa['media_dias'] ?? 0.0, $maxTempoPa,
                $etapa['media_dias'] === null ? 'Dados insuficientes' : number_format($etapa['media_dias'], 1, ',', '.') . ' dias',
                'bg-primary-400'
            ) ?>
          <?php endforeach; ?>
        </div>
      </article>
    </section>
  <?php endif; ?>

  <!-- Entrevistas de Desligamento: visão executiva (Etapa 3, 2026-09) — reaproveita INTEGRALMENTE
       DashboardEntrevistaDesligamentoService::montarPainel(). "Motivo apontado na entrevista" é a
       RESPOSTA do ex-colaborador — nunca confundir com "Desligamentos por Motivo" acima, que é o
       motivo FORMAL do RHCONTRATOS (§16 da Etapa 3). Só o período se aplica: "Unidade" deste
       dashboard é mais fina que o filtro global de Empresa (empresa+unidade física), sem
       correspondência segura — fica sempre consolidado, todas as unidades. -->
  <?= $secaoDivisor('Entrevistas de Desligamento') ?>

  <?php $ed = $painel['entrevista_desligamento']['executivo']; ?>
  <?php $edMotivos = $painel['entrevista_desligamento']['motivos']; ?>
  <p class="text-[11px] text-text-secondary">Consolidado de todas as unidades (filtro de Empresa não se aplica aqui — ver Dashboard da Entrevista de Desligamento para o detalhamento por Unidade/Cargo)</p>
  <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-ds-lg border border-border bg-surface p-3.5">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Desligamentos</p>
      <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($ed['desligamentos']) ?></p>
    </div>
    <div class="rounded-ds-lg border border-border bg-surface p-3.5">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Entrevistas Geradas</p>
      <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($ed['geradas']) ?></p>
    </div>
    <div class="rounded-ds-lg border border-border bg-surface p-3.5">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Entrevistas Respondidas</p>
      <p class="mt-1 text-xl font-bold text-text-primary"><?= $fmtN($ed['respondidas']) ?></p>
      <p class="text-[11px] text-text-secondary"><?= $ed['taxa_resposta'] === null ? 'Sem base' : number_format($ed['taxa_resposta'], 1, ',', '.') . '% de taxa de resposta' ?></p>
    </div>
    <div class="rounded-ds-lg border border-border bg-surface p-3.5">
      <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">eNPS</p>
      <p class="mt-1 text-xl font-bold text-text-primary"><?= $ed['enps']['valor'] === null ? 'Sem base' : (($ed['enps']['valor'] > 0 ? '+' : '') . number_format($ed['enps']['valor'], 1, ',', '.')) ?></p>
      <p class="text-[11px] text-text-secondary"><?= (int)$ed['enps']['n'] ?> resposta(s)</p>
    </div>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Motivo apontado na entrevista</h2>
    <p class="text-[11px] text-text-secondary">Resposta do ex-colaborador na Entrevista de Desligamento — diferente do motivo formal da rescisão (ver "Desligamentos por Motivo" acima)</p>
    <?php if ($edMotivos['total'] === 0): ?>
      <?= $estadoVazio('Nenhum motivo declarado no período.', 'exit') ?>
    <?php else: ?>
      <?php $maxMotivoEd = max(array_column($edMotivos['itens'], 'quantidade')) ?: 1; ?>
      <div class="mt-3 space-y-2">
        <?php foreach ($edMotivos['itens'] as $m): ?>
          <?= dashboard_bar_row($m['rotulo'], $m['quantidade'], $maxMotivoEd, $m['quantidade'] . ' · ' . number_format($m['percentual'], 1, ',', '.') . '%', 'bg-primary-500') ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Complementares: faixa etária, empresa, onboarding, dados pendentes -->
  <?= $secaoDivisor('Dados complementares') ?>

  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Desligamentos por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Eventos de desligamento no período</p>
      <?php if ($painel['desligamentos_por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum desligamento no período para os filtros selecionados.', 'exit') ?>
      <?php else: ?>
        <div class="mt-2.5 space-y-2">
          <?php $maxDeslEmpresa = max(array_column($painel['desligamentos_por_empresa'], 'desligamentos')) ?: 1; ?>
          <?php foreach ($painel['desligamentos_por_empresa'] as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['desligamentos'], $maxDeslEmpresa, (string)$item['desligamentos'], 'bg-primary-700') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Faixa Etária</h2>
      <p class="text-[11px] text-text-secondary">Desligamentos do período, por idade na rescisão</p>
      <?php if ($painel['turnover']['faixa_etaria'] === null): ?>
        <?= $estadoVazio('Dados insuficientes — nenhum desligamento classificável por idade no período.', 'clock') ?>
      <?php else: ?>
        <div class="mt-2.5 space-y-2">
          <?php $maxFaixa = max(array_column($painel['turnover']['faixa_etaria']['faixas'], 'quantidade')) ?: 1; ?>
          <?php foreach ($painel['turnover']['faixa_etaria']['faixas'] as $faixa): ?>
            <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixa, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Avaliações e Desenvolvimento (Etapa 9, 2026-09): Experiência 45/90, Desempenho, Feedback, PDI
       e Cobertura de Desenvolvimento — fonte real (avaliacoes_experiencia/avaliacoes_desempenho/
       feedbacks/pdis/pdi_origens), nunca o legado solicitacoes_vaga.avaliacao_90_dias. Densidade
       contida (3-5 KPIs/subseção, 1-2 gráficos fortes) — o detalhe operacional completo de cada
       domínio fica nos módulos próprios (/admin/pdis, /admin/avaliacoes-desenvolvimento, etc.). -->
  <?= $secaoDivisor('Avaliações e Desenvolvimento') ?>

  <?php $ad = $painel['avaliacoes_desenvolvimento']; ?>
  <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Avaliação de Experiência (45/90 dias)</h2>
      <div class="mt-2.5 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $fmtN(($ad['experiencia']['realizadas_por_tipo']['45'] ?? 0) + ($ad['experiencia']['realizadas_por_tipo']['90'] ?? 0)) ?></p>
          <p class="text-[11px] text-text-secondary">Realizadas no período</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-warning"><?= $fmtN($ad['experiencia']['pendentes']) ?></p>
          <p class="text-[11px] text-text-secondary">Pendentes</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-danger"><?= $fmtN($ad['experiencia']['vencidas']) ?></p>
          <p class="text-[11px] text-text-secondary">Vencidas</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $fmtN($ad['experiencia']['aguardando_ciencia']) ?></p>
          <p class="text-[11px] text-text-secondary">Aguardando ciência</p>
        </div>
      </div>
      <p class="mt-2 text-[11px] text-text-secondary">Realizadas no período: <?= $fmtN($ad['experiencia']['realizadas_por_tipo']['45'] ?? 0) ?> do tipo 45 dias · <?= $fmtN($ad['experiencia']['realizadas_por_tipo']['90'] ?? 0) ?> do tipo 90 dias.</p>
      <?php if ($ad['experiencia']['pareceres'] === []): ?>
        <?= $estadoVazio('Nenhuma avaliação de experiência concluída no período.', 'book') ?>
      <?php else: ?>
        <?php $rotulosParecer = ['apto_efetivacao' => 'Apto à efetivação', 'efetivacao_acompanhamento' => 'Efetivação c/ acompanhamento', 'prorrogacao_experiencia' => 'Prorrogação', 'nao_recomendado' => 'Não recomendado']; ?>
        <?php $maxParecer = max($ad['experiencia']['pareceres']) ?: 1; ?>
        <div class="mt-3 space-y-2">
          <?php foreach ($ad['experiencia']['pareceres'] as $parecer => $qtd): ?>
            <?= dashboard_bar_row($rotulosParecer[$parecer] ?? $parecer, $qtd, $maxParecer, (string)$qtd, 'bg-primary-500') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Avaliação de Desempenho</h2>
      <div class="mt-2.5 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $fmtN($ad['desempenho']['concluidas']) ?></p>
          <p class="text-[11px] text-text-secondary">Concluídas no período</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $ad['desempenho']['media_nota_atual'] === null ? '—' : number_format($ad['desempenho']['media_nota_atual'], 1, ',', '.') ?></p>
          <p class="text-[11px] text-text-secondary">Nota atual média</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $ad['desempenho']['media_nota_esperada'] === null ? '—' : number_format($ad['desempenho']['media_nota_esperada'], 1, ',', '.') ?></p>
          <p class="text-[11px] text-text-secondary">Nota esperada média</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-warning"><?= $ad['desempenho']['gap_medio'] === null ? '—' : number_format($ad['desempenho']['gap_medio'], 1, ',', '.') ?></p>
          <p class="text-[11px] text-text-secondary">GAP médio</p>
        </div>
      </div>
      <?php if ($ad['desempenho']['resultados'] === []): ?>
        <?= $estadoVazio('Nenhuma avaliação de desempenho concluída no período.', 'target') ?>
      <?php else: ?>
        <?php $rotulosResultado = ['supera_expectativas' => 'Supera expectativas', 'atende_expectativas' => 'Atende expectativas', 'atende_parcialmente' => 'Atende parcialmente', 'nao_atende' => 'Não atende']; ?>
        <?php $maxResultado = max($ad['desempenho']['resultados']) ?: 1; ?>
        <div class="mt-3 space-y-2">
          <?php foreach ($ad['desempenho']['resultados'] as $resultado => $qtd): ?>
            <?= dashboard_bar_row($rotulosResultado[$resultado] ?? $resultado, $qtd, $maxResultado, (string)$qtd, 'bg-primary-400') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Feedback</h2>
      <div class="mt-2.5 grid grid-cols-2 gap-2 text-center">
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $fmtN(array_sum($ad['feedback']['por_tipo'])) ?></p>
          <p class="text-[11px] text-text-secondary">Concluídos no período</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-warning"><?= $fmtN($ad['feedback']['desenvolvimento_necessario']) ?></p>
          <p class="text-[11px] text-text-secondary">Com "Desenvolvimento Necessário"</p>
        </div>
      </div>
      <?php if ($ad['feedback']['valores_desenvolvimento_necessario'] === []): ?>
        <?= $estadoVazio('Nenhum valor cultural marcado como "Desenvolvimento Necessário" no período.', 'smile') ?>
      <?php else: ?>
        <?php $rotulosValor = ['respeito' => 'Respeito', 'honestidade' => 'Honestidade', 'lealdade' => 'Lealdade', 'etica' => 'Ética', 'ousadia' => 'Ousadia', 'coragem' => 'Coragem']; ?>
        <?php $maxValor = max(array_column($ad['feedback']['valores_desenvolvimento_necessario'], 'n')) ?: 1; ?>
        <p class="mt-3 text-[11px] text-text-secondary">Valores culturais mais incidentes em "Desenvolvimento Necessário"</p>
        <div class="mt-1.5 space-y-2">
          <?php foreach ($ad['feedback']['valores_desenvolvimento_necessario'] as $v): ?>
            <?= dashboard_bar_row($rotulosValor[$v['valor']] ?? $v['valor'], (int)$v['n'], $maxValor, (string)$v['n'], 'bg-warning') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">PDI — Plano de Desenvolvimento Individual</h2>
      <div class="mt-2.5 grid grid-cols-2 gap-2 text-center">
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-text-primary"><?= $fmtN($ad['pdi']['ativos']) ?></p>
          <p class="text-[11px] text-text-secondary">Ativos (em andamento)</p>
        </div>
        <div class="rounded-ds-md bg-background p-2">
          <p class="text-xl font-bold text-success"><?= $fmtN($ad['pdi']['concluidos_periodo']) ?></p>
          <p class="text-[11px] text-text-secondary">Concluídos no período</p>
        </div>
      </div>
      <p class="mt-3 text-[11px] text-text-secondary">Vínculos de origem (um PDI pode ter mais de uma origem — cada vínculo conta na sua própria categoria)</p>
      <?php $vinculos = $ad['pdi']['vinculos_origem']; $totalVinculos = array_sum($vinculos); ?>
      <?php if ($totalVinculos === 0): ?>
        <?= $estadoVazio('Nenhum PDI vinculado a uma Avaliação/Feedback ainda.', 'academic') ?>
      <?php else: ?>
        <?php $rotulosOrigem = ['avaliacao_experiencia' => 'Avaliação de Experiência', 'feedback' => 'Feedback', 'avaliacao_desempenho' => 'Avaliação de Desempenho', 'manual' => 'Manual']; ?>
        <div class="mt-1.5 space-y-2">
          <?php foreach ($vinculos as $origem => $qtd): ?>
            <?php if ($qtd > 0): ?>
              <?= dashboard_bar_row($rotulosOrigem[$origem] ?? $origem, $qtd, max($vinculos), (string)$qtd, 'bg-primary-600') ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Cobertura de Desenvolvimento</h2>
    <p class="text-[11px] text-text-secondary">Entre os documentos concluídos no período com sinal real de necessidade de desenvolvimento (parecer/resultado/GAP), quantos já geraram um PDI vinculado — nunca uma taxa sobre toda a população de colaboradores.</p>
    <?php $cob = $ad['cobertura_desenvolvimento']; ?>
    <?php if ($cob['total']['elegiveis'] === 0): ?>
      <?= $estadoVazio('Nenhum documento com sinal de necessidade de desenvolvimento no período.', 'smile') ?>
    <?php else: ?>
      <div class="mt-2.5 grid grid-cols-1 gap-2 sm:grid-cols-4">
        <div class="rounded-ds-md bg-background p-2 text-center sm:col-span-1">
          <p class="text-xl font-bold text-text-primary"><?= $cob['total']['percentual'] === null ? '—' : number_format($cob['total']['percentual'], 1, ',', '.') . '%' ?></p>
          <p class="text-[11px] text-text-secondary"><?= $fmtN($cob['total']['vinculados']) ?> de <?= $fmtN($cob['total']['elegiveis']) ?> documentos elegíveis já viraram PDI</p>
        </div>
        <div class="sm:col-span-3 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-sm font-bold text-text-primary"><?= $fmtN($cob['experiencia']['vinculados']) ?>/<?= $fmtN($cob['experiencia']['elegiveis']) ?></p>
            <p class="text-[11px] text-text-secondary">Experiência</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-sm font-bold text-text-primary"><?= $fmtN($cob['feedback']['vinculados']) ?>/<?= $fmtN($cob['feedback']['elegiveis']) ?></p>
            <p class="text-[11px] text-text-secondary">Feedback</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-sm font-bold text-text-primary"><?= $fmtN($cob['desempenho']['vinculados']) ?>/<?= $fmtN($cob['desempenho']['elegiveis']) ?></p>
            <p class="text-[11px] text-text-secondary">Desempenho</p>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- Integração/Onboarding + NPS (Etapa 9, 2026-09): reaproveita INTEGRALMENTE
       DashboardIntegracaoService::montarPainel() — mesma fórmula de NPS do Dashboard de Integração
       próprio, nenhum cálculo paralelo. Sem filtro de Unidade/Gestor nesta tela (fora do escopo). -->
  <?= $secaoDivisor('Integração / Onboarding') ?>

  <?php $io = $painel['integracao_onboarding']; ?>
  <?php if ($io['amostra'] === 0 && $io['integracoes_realizadas'] === 0): ?>
    <?= $estadoVazio('Nenhuma integração realizada nem resposta de pesquisa no período/filtros selecionados.', 'users') ?>
  <?php else: ?>
    <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting lg:col-span-1">
        <h2 class="text-ds-h3 text-text-primary">NPS de Integração</h2>
        <div class="mt-3 flex items-center justify-center">
          <?= dashboard_donut([
              ['label' => 'Promotores', 'value' => $io['nps']['promotores'], 'color' => '#16a34a'],
              ['label' => 'Neutros', 'value' => $io['nps']['neutros'], 'color' => '#d97706'],
              ['label' => 'Detratores', 'value' => $io['nps']['detratores'], 'color' => '#dc2626'],
          ]) ?>
        </div>
        <p class="mt-2 text-center text-2xl font-bold text-text-primary"><?= $io['nps']['valor'] === null ? '—' : (($io['nps']['valor'] > 0 ? '+' : '') . number_format($io['nps']['valor'], 1, ',', '.')) ?></p>
        <p class="text-center text-[11px] text-text-secondary">
          <?= $fmtN($io['amostra']) ?> resposta(s)
          <?php if ($io['nps']['variacao_pontos'] !== null): ?>
            · <?= ($io['nps']['variacao_pontos'] >= 0 ? '+' : '') . number_format($io['nps']['variacao_pontos'], 1, ',', '.') ?> pontos vs. comparativo
          <?php endif; ?>
        </p>
      </article>

      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting lg:col-span-2">
        <h2 class="text-ds-h3 text-text-primary">Integrações, respostas e satisfação</h2>
        <p class="text-[11px] text-text-secondary">Conceitos distintos: Integrações Realizadas (processo de onboarding concluído) × Pesquisas Respondidas (quem respondeu a pesquisa) × Taxa de Resposta (só do fluxo individual, geradas vs. respondidas)</p>
        <div class="mt-2.5 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-text-primary"><?= $fmtN($io['integracoes_realizadas']) ?></p>
            <p class="text-[11px] text-text-secondary">Integrações realizadas</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-text-primary"><?= $fmtN($io['amostra']) ?></p>
            <p class="text-[11px] text-text-secondary">Pesquisas respondidas</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-text-primary"><?= $io['satisfacao_geral']['media'] === null ? '—' : number_format($io['satisfacao_geral']['media'], 1, ',', '.') ?></p>
            <p class="text-[11px] text-text-secondary">Satisfação geral (1–5) · <?= $fmtN($io['satisfacao_geral']['n']) ?> resposta(s)</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-text-primary"><?= $io['fluxo_individual']['taxa_resposta'] === null ? 'Sem base' : number_format($io['fluxo_individual']['taxa_resposta'], 1, ',', '.') . '%' ?></p>
            <p class="text-[11px] text-text-secondary">Taxa de resposta — fluxo individual (<?= $fmtN($io['fluxo_individual']['respondidas']) ?> de <?= $fmtN($io['fluxo_individual']['geradas']) ?> geradas)</p>
          </div>
        </div>
        <?php if (count($io['mensal']['labels']) >= 2): ?>
          <p class="mt-3 text-[11px] text-text-secondary">Evolução do NPS por mês</p>
          <div class="mt-1.5"><?= dashboard_multi_line_chart(
              $io['mensal']['labels'],
              [['label' => 'NPS', 'color' => '#2563eb', 'values' => $io['mensal']['nps']]],
              '', 1, 'NPS de Integração por mês', ['min' => -100, 'max' => 100]
          ) ?></div>
        <?php endif; ?>
      </article>
    </section>
  <?php endif; ?>

  <!-- Colaboradores — listagem operacional, agora CONTEXTUAL (Etapa 2, §16/§17): a população
       muda conforme o contexto de interação (Ativos/Admitidos no período/Desligados no período),
       sempre respeitando os filtros interativos acumulados (empresa/setor/sexo/motivo). -->
  <?= $secaoDivisor('Colaboradores') ?>

  <?php
    $contextoLista = $filtrosInterativos['contexto_lista'] ?? 'ativos';
    $rotuloModo = ['ativos' => 'Colaboradores ativos', 'admitidos' => 'Admitidos no período', 'desligados' => 'Desligados no período'][$contextoLista] ?? 'Colaboradores ativos';
    $recapFiltros = [];
    if (($filtrosSelecionados['empresa'] ?? '') !== '' && isset($nomeEmpresa)) { $recapFiltros[] = 'Empresa: ' . $nomeEmpresa; }
    if (($filtrosSelecionados['setor'] ?? '') !== '' && isset($nomeSetor)) { $recapFiltros[] = 'Setor: ' . $nomeSetor; }
    if (($filtrosInterativos['sexo'] ?? '') !== '') {
        $recapFiltros[] = 'Sexo: ' . (['M' => 'Masculino', 'F' => 'Feminino', 'nao_informado' => 'Não informado'][$filtrosInterativos['sexo']] ?? $filtrosInterativos['sexo']);
    }
    if (($filtrosInterativos['motivo_categoria'] ?? '') !== '') { $recapFiltros[] = 'Motivo: ' . $filtrosInterativos['motivo_categoria']; }
    if (($filtrosInterativos['mes_evento'] ?? '') !== '') { $recapFiltros[] = 'Mês: ' . $filtrosInterativos['mes_evento']; }
  ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-ds-h3 text-text-primary"><?= Security::e($rotuloModo) ?></h2>
        <p class="text-[11px] text-text-secondary">
          <?= $fmtN($listagem['total']) ?> colaborador(es)<?= $recapFiltros !== [] ? ' · ' . Security::e(implode(' · ', $recapFiltros)) : '' ?>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <form method="get" class="flex items-center gap-1.5" data-pa-form-por-pagina="1">
          <?php
          $listagemCamposOcultos = [
              'periodo' => $periodoSelecionado, 'mes' => $periodoParams['mes'], 'ano' => $periodoParams['ano'],
              'data_inicio' => $periodoParams['data_inicio'], 'data_fim' => $periodoParams['data_fim'],
              'comparativo' => $comparativoSelecionado,
              'empresa' => $filtrosSelecionados['empresa'], 'setor' => $filtrosSelecionados['setor'],
              'sexo' => $filtrosInterativos['sexo'], 'motivo' => $filtrosInterativos['motivo_categoria'],
              'contexto_lista' => $filtrosInterativos['contexto_lista'], 'mes_evento' => $filtrosInterativos['mes_evento'],
          ];
          ?>
          <?php foreach ($listagemCamposOcultos as $campo => $valor): ?>
            <input type="hidden" name="<?= Security::e($campo) ?>" value="<?= Security::e($valor) ?>">
          <?php endforeach; ?>
          <label class="text-[11px] text-text-secondary" for="por_pagina">Por página</label>
          <select id="por_pagina" name="por_pagina" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
            <option value="20" <?= $listagem['per_page'] === 20 ? 'selected' : '' ?>>20</option>
            <option value="50" <?= $listagem['per_page'] === 50 ? 'selected' : '' ?>>50</option>
          </select>
        </form>
        <?php
        $listagemExportParams = array_filter($listagemCamposOcultos, static fn($v) => $v !== '');
        ?>
        <a href="/admin/dashboard/colaboradores/exportar?<?= http_build_query($listagemExportParams) ?>"
           class="inline-flex items-center gap-1.5 rounded-ds-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary">
          Exportar CSV
        </a>
      </div>
    </div>

    <?php if ($listagem['items'] === []): ?>
      <?= $estadoVazio('Nenhum colaborador para os filtros selecionados.') ?>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-ds-md border border-border">
        <table class="w-full min-w-[980px] text-sm">
          <thead class="bg-surface-secondary">
            <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
              <th class="px-3 py-2">Nome</th>
              <th class="px-3 py-2">Empresa</th>
              <th class="px-3 py-2">Unidade</th>
              <th class="px-3 py-2">Setor</th>
              <th class="px-3 py-2">Cargo</th>
              <th class="px-3 py-2">Sexo</th>
              <th class="px-3 py-2">Admissão</th>
              <th class="px-3 py-2">Situação</th>
              <th class="px-3 py-2">Tempo de Empresa</th>
              <th class="px-3 py-2">Centro de Custo</th>
              <th class="px-3 py-2">Gestor Imediato</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <?php foreach ($listagem['items'] as $colab): ?>
              <?php
              $situacaoClasse = match ($colab['situacao']) {
                  'Ativo' => 'bg-success/10 text-success',
                  'Transferido' => 'bg-primary-100 text-primary-700',
                  default => 'bg-danger/10 text-danger',
              };
              ?>
              <tr class="hover:bg-surface-secondary">
                <td class="px-3 py-2 font-medium text-text-primary"><?= Security::e($colab['nome']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['empresa']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['unidade']) ?></td>
                <td class="px-3 py-2 text-text-secondary" title="<?= Security::e($colab['setor']) ?>"><?= Security::e($colab['setor']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['cargo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['sexo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['admissao']) ?></td>
                <td class="px-3 py-2"><span class="inline-flex rounded-ds-sm px-2 py-0.5 text-[11px] font-semibold <?= $situacaoClasse ?>"><?= Security::e($colab['situacao']) ?></span></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['tempo_empresa']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['centro_custo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['gestor_imediato']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($listagem['pages'] > 1): ?>
        <?php
        $listagemParamsBase = array_filter(array_merge($listagemCamposOcultos, ['por_pagina' => $listagem['per_page']]), static fn($v) => $v !== '' && $v !== null);
        $listagemPaginaAtual = $listagem['page'];
        $listagemTotalPaginas = $listagem['pages'];
        $listagemPrevParams = array_merge($listagemParamsBase, ['pagina' => max(1, $listagemPaginaAtual - 1)]);
        $listagemNextParams = array_merge($listagemParamsBase, ['pagina' => min($listagemTotalPaginas, $listagemPaginaAtual + 1)]);
        $listagemJanelaInicio = max(1, $listagemPaginaAtual - 2);
        $listagemJanelaFim = min($listagemTotalPaginas, $listagemPaginaAtual + 2);
        ?>
        <div class="mt-3 flex flex-col gap-3 border-t border-dashed border-border pt-3 md:flex-row md:items-center md:justify-between" data-pa-paginacao="1">
          <div class="text-[11px] text-text-secondary">Página <?= $listagemPaginaAtual ?> de <?= $listagemTotalPaginas ?> · <?= $listagem['per_page'] ?> por página</div>
          <div class="flex flex-wrap items-center gap-1.5">
            <a href="?<?= http_build_query($listagemPrevParams) ?>" data-pa-pagina="<?= max(1, $listagemPaginaAtual - 1) ?>" class="inline-flex min-h-[36px] items-center justify-center rounded-ds-md border border-border px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary <?= $listagemPaginaAtual <= 1 ? 'pointer-events-none opacity-50' : '' ?>">Anterior</a>
            <?php for ($p = $listagemJanelaInicio; $p <= $listagemJanelaFim; $p++): ?>
              <?php $listagemNumParams = array_merge($listagemParamsBase, ['pagina' => $p]); ?>
              <a href="?<?= http_build_query($listagemNumParams) ?>" data-pa-pagina="<?= $p ?>" class="inline-flex min-h-[36px] min-w-[36px] items-center justify-center rounded-ds-md border px-2.5 py-1.5 text-sm font-semibold transition <?= $p === $listagemPaginaAtual ? 'border-primary-700 bg-primary-700 text-white' : 'border-border text-text-secondary hover:bg-surface-secondary' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="?<?= http_build_query($listagemNextParams) ?>" data-pa-pagina="<?= min($listagemTotalPaginas, $listagemPaginaAtual + 1) ?>" class="inline-flex min-h-[36px] items-center justify-center rounded-ds-md border border-border px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary <?= $listagemPaginaAtual >= $listagemTotalPaginas ? 'pointer-events-none opacity-50' : '' ?>">Próxima</a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Dados ainda não integrados — rodapé discreto, sem protagonismo visual -->
  <section class="rounded-ds-md border border-dashed border-border/70 bg-surface-secondary/40 px-3.5 py-2.5">
    <p class="text-[10px] font-semibold uppercase tracking-wide text-text-muted">Dados ainda não integrados ao Portal</p>
    <p class="mt-0.5 text-[11px] text-text-muted">Banco de Horas · Horas Extras · Férias Programadas · Férias a Vencer</p>
  </section>

  <?php endif; ?>
</div>
