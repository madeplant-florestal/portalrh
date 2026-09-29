<?php
/**
 * People Analytics — Dashboard Executivo Central do RH (Etapa 1: 2026-09; Etapa 2 — People
 * Analytics interativo (click-to-filter, filtros acumulativos, listagem contextual), 2026-09).
 * Consolida Headcount/Turnover/Admissões/Desligamentos/Vagas com comparativos de período e os
 * principais gráficos do Dashboard de Turnover, sob a fórmula oficial de Turnover
 * (PeopleAnalyticsService::montarPainel() / RhIndicadoresService::taxaTurnoverPeriodo()) —
 * Turnover = desligados do período ÷ colaboradores no período × 100 (população = colaboradores
 * que estiveram ativos em algum momento do período — nunca confundir com Headcount Atual, a
 * fotografia de agora; correção de nomenclatura de 2026-09). Nenhum cálculo é feito aqui: a view
 * só formata o que o Service já entregou pronto.
 *
 * Narrativa visual: resumo executivo (KPIs) → protagonista (Turnover Geral) → tendência
 * (evolução/admissões×desligamentos) → visão comparativa (empresa/setor) → visão segmentada
 * (setor/sexo/motivo) → complementares → listagem contextual de Colaboradores.
 *
 * Bloco de resultado (tudo abaixo da área de filtros do topo) mora em
 * partials/dashboard/resultado.php — mesmo partial usado por
 * AdminController::dadosDashboard() (endpoint JSON da interatividade, §25 da correção de 2026-09)
 * via `include`, para o front trocar via fetch sem duplicar nenhuma regra/cálculo em JavaScript
 * (§9). Gráficos: SVG/HTML puro via partials/chart-helpers.php — sem biblioteca externa.
 * Clique-to-filter: assets/people-analytics-interativo.js.
 */
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$fmtData = static fn(DateTimeImmutable $d): string => $d->format('d/m/Y');
?>
<div class="space-y-5">

  <?= ui_modulo_topo($base, 'indicadores', 'people-analytics', [
      'titulo' => 'People Analytics',
      'descricao' => 'Dashboard executivo do RH — Headcount, Turnover, Admissões, Desligamentos e Vagas',
  ]) ?>

  <!-- A — Filtros: período, empresa, setor e comparativo -->
  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <form method="get" class="flex flex-wrap items-center gap-2">
        <select name="periodo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <?php foreach ($periodos as $chave => $label): ?>
            <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="comparativo" data-autosubmit="1" aria-label="Comparar com" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <?php foreach ($comparativos as $chave => $label): ?>
            <option value="<?= Security::e($chave) ?>" <?= $comparativoSelecionado === $chave ? 'selected' : '' ?>>Comparar: <?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="empresa" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todas as empresas</option>
          <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
            <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="setor" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todos os setores</option>
          <?php foreach ($opcoesFiltro['setores'] as $setor): ?>
            <option value="<?= Security::e($setor['codigo_setor']) ?>" <?= $filtrosSelecionados['setor'] === $setor['codigo_setor'] ? 'selected' : '' ?>><?= Security::e($setor['nome'] ?? $setor['codigo_setor']) ?></option>
          <?php endforeach; ?>
          <!-- Sentinela ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO — "Setor não
               informado" precisa ser selecionável, nunca escondido (§17 da correção de 2026-09). -->
          <option value="__sem_setor__" <?= $filtrosSelecionados['setor'] === '__sem_setor__' ? 'selected' : '' ?>>Setor não informado</option>
        </select>
        <!-- Preserva mês/ano/intervalo personalizado ao trocar empresa/setor/comparativo pelo formulário acima. -->
        <input type="hidden" name="mes" value="<?= Security::e($periodoParams['mes']) ?>">
        <input type="hidden" name="ano" value="<?= Security::e($periodoParams['ano']) ?>">
        <input type="hidden" name="data_inicio" value="<?= Security::e($periodoParams['data_inicio']) ?>">
        <input type="hidden" name="data_fim" value="<?= Security::e($periodoParams['data_fim']) ?>">
      </form>
      <p class="text-[11px] text-text-muted">Última atualização do METADADOS<br><strong class="text-[12px] font-semibold text-text-secondary"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></strong></p>
    </div>

    <details class="mt-2.5" <?= in_array($periodoSelecionado, ['mes', 'ano_especifico', 'personalizado'], true) ? 'open' : '' ?>>
      <summary class="cursor-pointer text-xs font-semibold text-primary-700">Período específico ou personalizado</summary>
      <div class="mt-2 flex flex-wrap gap-3">
        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="mes">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">Mês<br>
            <?php
              $nomesMeses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
                  7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
            ?>
            <select name="mes" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
              <?php foreach ($nomesMeses as $m => $nomeMes): ?>
                <option value="<?= $m ?>" <?= (int)$periodoParams['mes'] === $m ? 'selected' : '' ?>><?= Security::e($nomeMes) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-[11px] text-text-secondary">Ano<br>
            <input type="number" name="ano" min="2015" max="2100" value="<?= Security::e($periodoParams['ano'] !== '' ? $periodoParams['ano'] : date('Y')) ?>" class="w-20 rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar mês</button>
        </form>

        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="ano_especifico">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">Ano específico<br>
            <input type="number" name="ano" min="2015" max="2100" value="<?= Security::e($periodoParams['ano'] !== '' ? $periodoParams['ano'] : date('Y')) ?>" class="w-24 rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar ano</button>
        </form>

        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="personalizado">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">De<br>
            <input type="date" name="data_inicio" value="<?= Security::e($periodoParams['data_inicio']) ?>" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <label class="text-[11px] text-text-secondary">Até<br>
            <input type="date" name="data_fim" value="<?= Security::e($periodoParams['data_fim']) ?>" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar intervalo</button>
        </form>
      </div>
    </details>

    <p class="mt-2.5 border-t border-border pt-2 text-[11px] leading-snug text-text-secondary">
      <strong class="text-text-primary"><?= Security::e($fmtData($periodoInicio)) ?> a <?= Security::e($fmtData($periodoFim)) ?></strong>
      <?php if ($painel !== null): ?>
        · comparando com <strong class="text-text-primary"><?= Security::e($comparativos[$comparativoSelecionado]) ?></strong> (<?= Security::e($fmtData($painel['comparativo']['periodo']['inicio'])) ?> a <?= Security::e($fmtData($painel['comparativo']['periodo']['fim'])) ?>)
      <?php endif; ?>
      · Turnover = desligados ÷ colaboradores no período × 100 · Empresa/Setor usam códigos oficiais do METADADOS
    </p>
    <?php if ($painel !== null && $painel['vagas']['empresa_sem_correspondencia']): ?>
      <p class="mt-1 text-[11px] font-medium text-warning">A Empresa selecionada ainda não tem correspondência no catálogo local de Empresas — Vagas Abertas fica zerada em vez de mostrar o total geral.</p>
    <?php endif; ?>
  </section>

  <?php include __DIR__ . '/partials/dashboard/resultado.php'; ?>
</div>
<?php ui_script_pagina('people-analytics-interativo.js'); // JS da Etapa 2 (click-to-filter); tag de script real fica a cargo do layout, nunca inline aqui ?>
