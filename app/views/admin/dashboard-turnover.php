<?php
/**
 * Dashboard de Turnover — seis análises sobre os contratos oficiais do METADADOS. Só apresentação:
 * todo cálculo vem de TurnoverDashboardService (fórmula oficial de RhIndicadoresService). Valores
 * disponíveis também em tabela (não dependem de hover nem só do SVG). Meses futuros aparecem como
 * "—" e ausência de base como "Sem base" — nunca 0%.
 */
require_once __DIR__ . '/partials/chart-helpers.php';

$fmtPct = static fn(?float $v): string => $v === null ? 'Sem base' : number_format($v, 1, ',', '.') . '%';
$fmtBase = static fn(float $v): string => number_format($v, 1, ',', '.');
$plural = static fn(int $n, string $s, string $p): string => $n . ' ' . ($n === 1 ? $s : $p);
$cardClasses = 'min-w-0 rounded-2xl border border-[#E2DFD0] bg-white p-3';
$corAnterior = '#A9B885';
$corAtual = '#3B4822';
?>
<div class="responsive-panel space-y-4">

  <section class="rounded-2xl bg-[#3B4822] px-6 py-3.5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold tracking-tight text-white">Dashboard de Turnover</h1>
        <p class="mt-0.5 text-sm text-[#E4E9D6]">Evolução, motivos e perfil dos desligamentos — contratos oficiais do METADADOS</p>
      </div>
      <div class="text-right">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#A9B885]">Última atualização do METADADOS</p>
        <p class="mt-0.5 text-sm font-semibold text-white"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></p>
      </div>
    </div>
  </section>

  <section class="rounded-2xl border border-[#E2DFD0] bg-white p-3">
    <form method="get" class="flex flex-wrap gap-2">
      <select name="ano" aria-label="Ano" data-autosubmit="1" class="rounded-xl border border-[#E2DFD0] bg-white px-2.5 py-1.5 text-sm font-medium text-[#2B2E22] shadow-sm outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]">
        <?php foreach (array_reverse($anos) as $a): ?>
          <option value="<?= (int)$a ?>" <?= (int)$anoSelecionado === (int)$a ? 'selected' : '' ?>><?= (int)$a ?> × <?= (int)$a - 1 ?></option>
        <?php endforeach; ?>
      </select>
      <select name="empresa" aria-label="Empresa" data-autosubmit="1" class="rounded-xl border border-[#E2DFD0] bg-white px-2.5 py-1.5 text-sm font-medium text-[#2B2E22] shadow-sm outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]">
        <option value="">Todas as empresas</option>
        <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
          <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="cargo" aria-label="Cargo" data-autosubmit="1" class="rounded-xl border border-[#E2DFD0] bg-white px-2.5 py-1.5 text-sm font-medium text-[#2B2E22] shadow-sm outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]">
        <option value="">Todos os cargos</option>
        <?php foreach ($opcoesFiltro['cargos'] as $cargo): ?>
          <option value="<?= Security::e($cargo['codigo_cargo']) ?>" <?= $filtrosSelecionados['cargo'] === $cargo['codigo_cargo'] ? 'selected' : '' ?>><?= Security::e($cargo['nome'] ?? $cargo['codigo_cargo']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($painel !== null): ?>
      <p class="mt-1.5 text-[11px] leading-snug text-[#5B5F4E]">
        Unidade: contrato · Turnover = desligamentos ÷ média do headcount (início e fim do período) × 100 ·
        Gráficos 3 a 6: <?= Security::e($painel['periodo']['inicio']->format('d/m/Y')) ?> a <?= Security::e($painel['periodo']['fim']->format('d/m/Y')) ?><?= $painel['periodo']['parcial'] ? ' (acumulado no ano até hoje)' : '' ?> ·
        Empresa e Cargo filtram todos os gráficos, nos dois anos do comparativo
      </p>
    <?php endif; ?>
  </section>

  <?php if ($erro !== null || $painel === null): ?>
    <section class="responsive-panel ring-1 ring-red-200 bg-red-50">
      <p class="text-sm font-semibold text-red-700"><?= Security::e((string)$erro) ?></p>
    </section>
  <?php else: ?>

  <?php
    $comp = $painel['comparativo'];
    $anoAtual = (int)$painel['ano'];
    $anoAnterior = (int)$painel['ano_anterior'];
    $valoresAnterior = array_map(static fn(array $m) => $m['taxa'], $comp['anterior']);
    $valoresAtual = array_map(static fn(array $m) => $m['taxa'], $comp['atual']);
    $parciaisAtual = array_map(static fn(array $m) => $m['status'] === 'parcial', $comp['atual']);
    $parcialAte = null;
    foreach ($comp['atual'] as $m) {
        if ($m['status'] === 'parcial') { $parcialAte = $m['parcial_ate']; }
    }
    $celulaMes = static function (array $m) use ($fmtPct): string {
        if ($m['status'] === 'futuro') { return '—'; }
        return $fmtPct($m['taxa']) . ' (' . $m['desligamentos'] . ' desl.)' . ($m['status'] === 'parcial' ? ' · parcial até ' . $m['parcial_ate'] : '');
    };
    $linhasComparativo = [];
    foreach ($comp['labels'] as $i => $rotulo) {
        $linhasComparativo[] = [$rotulo, $celulaMes($comp['anterior'][$i]), $celulaMes($comp['atual'][$i])];
    }

    $ad = $painel['admissoes_desligamentos'];
    $admissoes = array_map(static fn(array $m) => $m['admissoes'], $ad['meses']);
    $desligamentos = array_map(static fn(array $m) => $m['desligamentos'], $ad['meses']);
    $parciaisAd = array_map(static fn(array $m) => $m['status'] === 'parcial', $ad['meses']);
    $linhasAd = [];
    foreach ($ad['labels'] as $i => $rotulo) {
        $m = $ad['meses'][$i];
        $linhasAd[] = [$rotulo . ($m['status'] === 'parcial' ? ' (parcial até ' . $ad['parcial_ate'] . ')' : ''), $m['admissoes'] === null ? '—' : (string)$m['admissoes'], $m['desligamentos'] === null ? '—' : (string)$m['desligamentos']];
    }
  ?>

  <!-- 1 e 2 — série mensal -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Evolução Mensal do Turnover — <?= $anoAnterior ?> × <?= $anoAtual ?></h2>
      <p class="text-[11px] text-[#5B5F4E]">Turnover do mês (%), fórmula oficial; não anualizado. Mês sem ponto = ainda não ocorreu ou sem base de headcount.</p>
      <div class="mt-2">
        <?= dashboard_chart_legend(array_filter([
            ['label' => (string)$anoAnterior, 'color' => $corAnterior],
            ['label' => (string)$anoAtual, 'color' => $corAtual],
            $parcialAte !== null ? ['label' => 'Mês parcial (até ' . $parcialAte . ')', 'color' => $corAtual, 'estilo' => 'hollow'] : null,
        ])) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_multi_line_chart($comp['labels'], [
            ['label' => (string)$anoAnterior, 'color' => $corAnterior, 'values' => $valoresAnterior],
            ['label' => (string)$anoAtual, 'color' => $corAtual, 'values' => $valoresAtual, 'partial' => $parciaisAtual],
        ], '%', 1, 'Evolução mensal do turnover, ' . $anoAnterior . ' comparado a ' . $anoAtual) ?>
      </div>
      <?= dashboard_data_table('Turnover mensal ' . $anoAnterior . ' × ' . $anoAtual, ['Mês', (string)$anoAnterior, (string)$anoAtual], $linhasComparativo) ?>
    </article>

    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Admissões × Desligamentos — <?= $anoAtual ?></h2>
      <p class="text-[11px] text-[#5B5F4E]">Contratos por data de admissão e de desligamento, mês a mês.</p>
      <div class="mt-2">
        <?= dashboard_chart_legend(array_filter([
            ['label' => 'Admissões', 'color' => $corAtual],
            ['label' => 'Desligamentos', 'color' => $corAnterior],
            $ad['parcial_ate'] !== null ? ['label' => 'Mês parcial (até ' . $ad['parcial_ate'] . ')', 'color' => $corAtual, 'estilo' => 'dashed'] : null,
        ])) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_grouped_columns($ad['labels'], [
            ['label' => 'Admissões', 'color' => $corAtual, 'values' => $admissoes, 'partial' => $parciaisAd],
            ['label' => 'Desligamentos', 'color' => $corAnterior, 'values' => $desligamentos, 'partial' => $parciaisAd],
        ], 'Admissões e desligamentos por mês em ' . $anoAtual) ?>
      </div>
      <?= dashboard_data_table('Admissões e desligamentos por mês em ' . $anoAtual, ['Mês', 'Admissões', 'Desligamentos'], $linhasAd) ?>
    </article>
  </section>

  <!-- 3 e 6 — motivos e tempo de empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Desligamentos por Motivo</h2>
      <p class="text-[11px] text-[#5B5F4E]">Categorias mutuamente exclusivas · classificação provisória, aguardando validação final do RH · códigos não mapeados entram em Outros.</p>
      <?php $motivos = $painel['motivos']; ?>
      <?php if ($motivos['total'] === 0): ?>
        <p class="mt-3 text-sm text-[#5B5F4E]">Nenhum desligamento no período.</p>
      <?php else: ?>
        <?php $maxMotivo = max(array_column($motivos['categorias'], 'quantidade')) ?: 1; ?>
        <div class="mt-3 space-y-2">
          <?php foreach ($motivos['categorias'] as $c): ?>
            <?= dashboard_bar_row($c['categoria'], $c['quantidade'], $maxMotivo, $c['quantidade'] . ' · ' . number_format((float)$c['percentual'], 1, ',', '.') . '%', 'bg-[#566B41]') ?>
          <?php endforeach; ?>
        </div>
        <?php if ($motivos['detalhe_outros'] !== []): ?>
          <p class="mt-2 text-[11px] text-[#5B5F4E]">Em "Outros": <?= Security::e(implode(', ', array_map(static fn(array $d): string => $d['codigo'] . ' (' . $d['quantidade'] . ')', $motivos['detalhe_outros']))) ?></p>
        <?php endif; ?>
        <?= dashboard_data_table('Desligamentos por motivo', ['Categoria', 'Quantidade', '% do total'], array_map(static fn(array $c): array => [$c['categoria'], (string)$c['quantidade'], number_format((float)$c['percentual'], 1, ',', '.') . '%'], $motivos['categorias'])) ?>
      <?php endif; ?>
    </article>

    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Tempo de Empresa dos Desligados</h2>
      <p class="text-[11px] text-[#5B5F4E]">Dias corridos entre a admissão e o desligamento do contrato.</p>
      <?php $tempo = $painel['tempo_empresa']; ?>
      <?php if ($tempo['total_classificados'] === 0): ?>
        <p class="mt-3 text-sm text-[#5B5F4E]">Nenhum desligamento classificável no período.</p>
      <?php else: ?>
        <div class="mt-3">
          <?= dashboard_vertical_bars(array_map(static fn(array $f): array => [
              'label' => $f['label'],
              'value' => $f['quantidade'],
              'display' => $f['quantidade'] . ' · ' . number_format((float)$f['percentual'], 1, ',', '.') . '%',
          ], $tempo['faixas']), 'bg-[#566B41]') ?>
        </div>
        <?= dashboard_data_table('Tempo de empresa dos desligados', ['Faixa', 'Desligamentos', '% dos classificados'], array_map(static fn(array $f): array => [$f['label'], (string)$f['quantidade'], number_format((float)$f['percentual'], 1, ',', '.') . '%'], $tempo['faixas'])) ?>
      <?php endif; ?>
      <?php if ($tempo['nao_classificados'] > 0): ?>
        <p class="mt-2 text-[11px] text-[#5B5F4E]"><?= (int)$tempo['nao_classificados'] ?> desligamento(s) sem datas válidas de admissão/desligamento não entram nas faixas.</p>
      <?php endif; ?>
    </article>
  </section>

  <!-- 4 e 5 — cargo e empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Turnover por Cargo</h2>
      <p class="text-[11px] text-[#5B5F4E]">Cargos com desligamentos no período, por maior % de turnover. Base = headcount médio do cargo; bases pequenas geram percentuais altos.</p>
      <?php $cargos = $painel['cargos']; ?>
      <?php if ($cargos['itens'] === []): ?>
        <p class="mt-3 text-sm text-[#5B5F4E]">Nenhum desligamento no período.</p>
      <?php else: ?>
        <?php $maxCargo = max(array_map(static fn(array $i): float => (float)($i['taxa'] ?? 0), $cargos['itens'])) ?: 1; ?>
        <div class="mt-3 space-y-2">
          <?php foreach ($cargos['itens'] as $i): ?>
            <?= dashboard_bar_row($i['nome'], (float)($i['taxa'] ?? 0), $maxCargo, $fmtPct($i['taxa']) . ' · ' . $plural($i['desligamentos'], 'desligamento', 'desligamentos') . ' · base ' . $fmtBase($i['base']), 'bg-[#819158]') ?>
          <?php endforeach; ?>
        </div>
        <?php if ($cargos['total_cargos'] > $cargos['exibidos']): ?>
          <p class="mt-2 text-[11px] text-[#5B5F4E]">Exibindo os <?= (int)$cargos['exibidos'] ?> primeiros de <?= (int)$cargos['total_cargos'] ?> cargos com desligamentos.</p>
        <?php endif; ?>
        <?= dashboard_data_table('Turnover por cargo', ['Cargo', '% Turnover', 'Desligamentos', 'Base (headcount médio)'], array_map(static fn(array $i): array => [$i['nome'], $fmtPct($i['taxa']), (string)$i['desligamentos'], $fmtBase($i['base'])], $cargos['itens'])) ?>
      <?php endif; ?>
    </article>

    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-[#2B2E22]">Turnover por Empresa</h2>
      <p class="text-[11px] text-[#5B5F4E]">Mesma fórmula do Turnover Geral, calculada por Empresa oficial (código do METADADOS).</p>
      <?php $empresas = $painel['empresas']; ?>
      <?php if ($empresas['itens'] === []): ?>
        <p class="mt-3 text-sm text-[#5B5F4E]">Nenhuma empresa com base ou desligamentos no período.</p>
      <?php else: ?>
        <?php $maxEmpresa = max(array_map(static fn(array $i): float => (float)($i['taxa'] ?? 0), $empresas['itens'])) ?: 1; ?>
        <div class="mt-3 space-y-2">
          <?php foreach ($empresas['itens'] as $i): ?>
            <?= dashboard_bar_row($i['nome'], (float)($i['taxa'] ?? 0), $maxEmpresa, $fmtPct($i['taxa']) . ' · ' . $plural($i['desligamentos'], 'desligamento', 'desligamentos') . ' · base ' . $fmtBase($i['base']), 'bg-[#3B4822]') ?>
          <?php endforeach; ?>
        </div>
        <?= dashboard_data_table('Turnover por empresa', ['Empresa', '% Turnover', 'Desligamentos', 'Base (headcount médio)'], array_map(static fn(array $i): array => [$i['nome'], $fmtPct($i['taxa']), (string)$i['desligamentos'], $fmtBase($i['base'])], $empresas['itens'])) ?>
      <?php endif; ?>
    </article>
  </section>

  <?php endif; ?>
</div>
