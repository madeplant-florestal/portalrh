<?php
/**
 * People Analytics — Tela Inicial / Dashboard principal. Redesenho visual aprovado (mockup +
 * tokens do Claudinho Design), escopo exclusivo desta tela — nenhuma regra de negócio nova, só
 * apresentação. Bloco F (Nova UI): AppShell V2 com topo padrão (ui_modulo_topo, aba People Analytics) e classes de cor mapeadas para os
 * tokens do Design System (as cores de série da rosca ficam como hex: atributos SVG que precisam casar com a legenda).
 *
 * Gráficos: SVG/HTML puro via partials/chart-helpers.php (dashboard_bar_row/dashboard_donut/dashboard_vertical_bars) — sem biblioteca externa.
 * Container fluido (sem max-w-* artificial).
 */
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$pa = [
    'brand50' => '#F2F4EC', 'brand100' => '#E4E9D6', 'brand200' => '#A9B885', 'brand300' => '#819158',
    'brand400' => '#566B41', 'brand500' => '#3B4822', 'brand600' => '#2E3919', 'brand700' => '#232B13',
    'ink' => '#2B2E22', 'inkSoft' => '#5B5F4E', 'border' => '#E2DFD0', 'danger' => '#B23B3B',
];
?>
<div class="space-y-4">

  <?= ui_modulo_topo($base, 'indicadores', 'people-analytics', [
      'titulo' => 'People Analytics',
      'descricao' => 'Turnover & indicadores de pessoas — Portal RH Madeplant',
  ]) ?>
  <p class="text-ds-caption text-text-secondary">Última atualização do METADADOS: <strong class="text-text-primary"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></strong></p>
  <?php if ($erro !== null): ?>
    <section class="rounded-ds-lg border border-danger/30 bg-danger/10 p-4">
      <p class="text-sm font-semibold text-danger"><?= Security::e($erro) ?></p>
    </section>
  <?php else: ?>

  <!-- Filtros -->
  <section class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
    <form method="get" class="flex flex-wrap gap-2">
      <select name="periodo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <?php foreach ($periodos as $chave => $label): ?>
          <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
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
      </select>
    </form>
    <p class="mt-1.5 text-[11px] leading-snug text-text-secondary">Período: <?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?> · Empresa/Setor usam os códigos oficiais do METADADOS · Vagas Abertas/Fechadas não respeitam o filtro de Setor (o módulo de Recrutamento ainda não tem essa dimensão)</p>
    <?php if ($painel['vagas']['empresa_sem_correspondencia']): ?>
      <p class="mt-1 text-[11px] font-medium text-warning">A Empresa selecionada ainda não tem correspondência no catálogo local de Empresas — Vagas Abertas/Fechadas ficam zeradas em vez de mostrar o total geral.</p>
    <?php endif; ?>
  </section>

  <!-- Faixa superior: dois grupos com pesos visuais diferentes — indicadores numéricos simples
       compactos (~40% da largura em desktop) e indicadores visuais de Turnover em destaque
       (~60%, dividido ao meio entre Turnover Geral e Composição dos Desligamentos). Números
       simples não competem visualmente com análises de composição. -->
  <section class="flex flex-col gap-4 xl:flex-row">

    <!-- GRUPO 1 — indicadores numéricos compactos, lista com divisórias em vez de 5 cards -->
    <div class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting xl:w-[38%] xl:flex-shrink-0">
      <div class="divide-y divide-border">
        <div class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
          <span class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Headcount Atual</span>
          <span class="flex items-baseline gap-1.5 text-right">
            <span class="text-xl font-bold leading-none text-text-primary"><?= number_format($painel['headcount']['atual'], 0, ',', '.') ?></span>
            <span class="text-[10px] text-text-secondary">contratos</span>
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
          <span class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Vagas Abertas</span>
          <span class="flex items-baseline gap-1.5 text-right">
            <span class="text-xl font-bold leading-none text-text-primary"><?= number_format($painel['vagas']['abertas'], 0, ',', '.') ?></span>
            <span class="text-[10px] text-text-secondary"><?= $painel['vagas']['empresa_sem_correspondencia'] ? 'sem correspondência' : 'em processo' ?></span>
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
          <span class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Vagas Fechadas</span>
          <span class="flex items-baseline gap-1.5 text-right">
            <span class="text-xl font-bold leading-none text-text-primary"><?= number_format($painel['vagas']['fechadas_no_periodo'], 0, ',', '.') ?></span>
            <span class="text-[10px] text-text-secondary"><?= $painel['vagas']['empresa_sem_correspondencia'] ? 'sem correspondência' : 'no período' ?></span>
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
          <span class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Admissões no Período</span>
          <span class="flex items-baseline gap-1.5 text-right">
            <span class="text-xl font-bold leading-none text-text-primary"><?= number_format($painel['admissoes']['periodo'], 0, ',', '.') ?></span>
            <span class="text-[10px] text-text-secondary">contratos</span>
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
          <span class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Desligamentos no Período</span>
          <span class="flex items-baseline gap-1.5 text-right">
            <span class="text-xl font-bold leading-none text-text-primary"><?= number_format($painel['desligamentos']['periodo'], 0, ',', '.') ?></span>
            <span class="text-[10px] text-text-secondary">eventos</span>
          </span>
        </div>
      </div>
    </div>

    <!-- GRUPO 2 — Turnover em destaque: Turnover Geral (gauge) + Composição dos Desligamentos -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:flex-1">
      <?php
        $turnoverGeralPct = $painel['turnover']['geral_percentual'];
        $restanteGauge = max(0.0, 100 - $turnoverGeralPct);
        $segmentosGauge = [
            ['label' => 'Turnover', 'value' => $turnoverGeralPct, 'color' => $pa['brand500']],
            ['label' => 'Restante', 'value' => $restanteGauge, 'color' => $pa['brand100']],
        ];
      ?>
      <div class="flex flex-col items-center justify-center rounded-ds-lg border border-border bg-surface p-4 text-center shadow-resting">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Turnover Geral</p>
        <div class="relative mt-2 h-[120px] w-[120px]">
          <?= dashboard_donut($segmentosGauge, 120, 16) ?>
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-2xl font-bold text-text-primary"><?= number_format($turnoverGeralPct, 1, ',', '.') ?>%</span>
          </div>
        </div>
        <p class="mt-2 text-[11px] text-text-secondary">headcount médio do período</p>
      </div>

      <!-- Composição dos Desligamentos — rosca representa % do TOTAL DE DESLIGAMENTOS do período
           (nunca % da taxa de Turnover); as fatias somam 100% dos desligamentos reais, nunca
           força Voluntário+Involuntário=100% quando existem "Outros". -->
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Composição dos Desligamentos</p>
        <?php
          $totalDeslPeriodo = $painel['desligamentos']['periodo'];
          $tv = $painel['turnover']['voluntario'];
          $ti = $painel['turnover']['involuntario'];
          $to = $painel['turnover']['outros'];
        ?>
        <?php if ($totalDeslPeriodo === 0): ?>
          <p class="mt-3 text-[11px] text-text-secondary">Sem desligamentos no período.</p>
        <?php else: ?>
          <?php
            $segmentosRosca = [];
            if ($tv['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Voluntário', 'value' => $tv['participacao_desligamentos'], 'color' => $pa['brand500']]; }
            if ($ti['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Involuntário', 'value' => $ti['participacao_desligamentos'], 'color' => $pa['brand200']]; }
            if ($to['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Outros', 'value' => $to['participacao_desligamentos'], 'color' => $pa['inkSoft']]; }
          ?>
          <div class="mt-3 flex items-center gap-4">
            <div class="w-[120px] flex-shrink-0"><?= dashboard_donut($segmentosRosca, 120, 16) ?></div>
            <ul class="space-y-1.5 text-sm text-text-primary">
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

  <!-- Requisito explícito do RH: Headcount por Empresa e Turnover por Empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
      <h2 class="text-sm font-bold text-text-primary">Headcount por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Contratos ativos, por Empresa oficial do METADADOS</p>
      <?php if ($painel['headcount_por_empresa'] === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum contrato ativo para os filtros selecionados.</p>
      <?php else: ?>
        <?php
          $headcountEmpresaItems = array_map(static function (array $item): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['quantidade'],
                  'display' => number_format($item['quantidade'], 0, ',', '.'),
              ];
          }, $painel['headcount_por_empresa']);
        ?>
        <div class="mt-2"><?= dashboard_vertical_bars($headcountEmpresaItems, 'bg-primary-600') ?></div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
      <h2 class="text-sm font-bold text-text-primary">Turnover por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Mesma metodologia do Turnover Geral, calculada por Empresa · mesma ordem do Headcount por Empresa acima</p>
      <?php if ($painel['turnover']['por_empresa'] === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum contrato para os filtros selecionados.</p>
      <?php else: ?>
        <?php
          $turnoverEmpresaItems = array_map(static function (array $item): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['taxa'],
                  'display' => number_format($item['taxa'], 1, ',', '.') . '%',
              ];
          }, $painel['turnover']['por_empresa']);
        ?>
        <div class="mt-2"><?= dashboard_vertical_bars($turnoverEmpresaItems, 'bg-primary-400') ?></div>
        <p class="mt-1.5 text-[11px] text-text-secondary">Sem limite de Turnover oficial configurado hoje — todas as barras usam a mesma cor institucional, nenhuma taxa é destacada automaticamente como crítica.</p>
      <?php endif; ?>
    </article>
  </section>

  <!-- Requisito explícito do RH: Desligamentos por Empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
      <h2 class="text-sm font-bold text-text-primary">Desligamentos por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Eventos de desligamento no período, por Empresa</p>
      <?php if ($painel['desligamentos_por_empresa'] === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum desligamento no período para os filtros selecionados.</p>
      <?php else: ?>
        <div class="mt-2 space-y-2">
          <?php $maxDeslEmpresa = max(array_column($painel['desligamentos_por_empresa'], 'desligamentos')) ?: 1; ?>
          <?php foreach ($painel['desligamentos_por_empresa'] as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['desligamentos'], $maxDeslEmpresa, (string)$item['desligamentos'], 'bg-primary-700') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
      <h2 class="text-sm font-bold text-text-primary">Turnover por Faixa Etária</h2>
      <p class="text-[11px] text-text-secondary">Desligamentos do período, por idade na data da rescisão</p>
      <?php if ($painel['turnover']['faixa_etaria'] === null): ?>
        <p class="mt-2 text-sm text-text-secondary">Dados insuficientes — nenhum desligamento classificável por idade no período selecionado.</p>
      <?php else: ?>
        <div class="mt-2 space-y-2">
          <?php $maxFaixa = max(array_column($painel['turnover']['faixa_etaria']['faixas'], 'quantidade')) ?: 1; ?>
          <?php foreach ($painel['turnover']['faixa_etaria']['faixas'] as $faixa): ?>
            <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixa, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Turnover por Sexo — mesma metodologia do Turnover Geral, segmentada por RHPESSOAS.SEXO -->
  <section class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
    <h2 class="text-sm font-bold text-text-primary">Turnover por Sexo</h2>
    <p class="text-[11px] text-text-secondary">Mesma metodologia do Turnover Geral (desligamentos ÷ headcount médio do período), segmentada pelo sexo oficial do METADADOS</p>
    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2<?= $painel['turnover']['genero']['nao_informado'] !== null ? ' lg:grid-cols-3' : '' ?>">
      <?php foreach ([['label' => 'Masculino', 'dado' => $painel['turnover']['genero']['masculino']], ['label' => 'Feminino', 'dado' => $painel['turnover']['genero']['feminino']]] as $bloco): ?>
        <div class="rounded-ds-md bg-background p-2 text-center">
          <p class="text-[11px] font-semibold text-text-secondary"><?= Security::e($bloco['label']) ?></p>
          <p class="mt-1 text-xl font-bold text-text-primary"><?= number_format($bloco['dado']['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary">Headcount médio: <?= number_format($bloco['dado']['headcount_medio'], 1, ',', '.') ?> · <?= (int)$bloco['dado']['desligamentos'] ?> desligamento(s)</p>
        </div>
      <?php endforeach; ?>
      <?php if ($painel['turnover']['genero']['nao_informado'] !== null): ?>
        <?php $naoInformado = $painel['turnover']['genero']['nao_informado']; ?>
        <div class="rounded-ds-md bg-background p-2 text-center">
          <p class="text-[11px] font-semibold text-text-secondary">Não informado</p>
          <p class="mt-1 text-xl font-bold text-text-primary"><?= number_format($naoInformado['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary">Headcount médio: <?= number_format($naoInformado['headcount_medio'], 1, ',', '.') ?> · <?= (int)$naoInformado['desligamentos'] ?> desligamento(s)</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Colaboradores por Setor, Integrações, NPS, Avaliação de Experiência -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
      <h2 class="text-sm font-bold text-text-primary">Colaboradores por Setor</h2>
      <p class="text-[11px] text-text-secondary">Contratos ativos, agrupados pelo Setor oficial do METADADOS</p>
      <?php if ($painel['colaboradores_por_setor'] === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum contrato ativo para os filtros selecionados.</p>
      <?php else: ?>
        <div class="mt-2 space-y-2">
          <?php $maxSetor = max(array_column($painel['colaboradores_por_setor'], 'quantidade')) ?: 1; ?>
          <?php foreach (array_slice($painel['colaboradores_por_setor'], 0, 12) as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['quantidade'], $maxSetor, (string)$item['quantidade'], $item['label'] === 'Setor não informado' ? 'bg-text-muted' : 'bg-primary-600') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
        <h2 class="text-sm font-bold text-text-primary">Integrações</h2>
        <p class="text-[11px] text-text-secondary">Realizadas no período</p>
        <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format($painel['integracao']['realizadas_periodo'], 0, ',', '.') ?></p>
        <div class="mt-2 border-t border-border pt-2">
          <p class="text-[11px] text-text-secondary">NPS Integração</p>
          <?php if ($painel['nps_integracao']['amostra'] === 0): ?>
            <p class="mt-0.5 text-sm text-text-secondary">Dados insuficientes</p>
          <?php else: ?>
            <p class="mt-0.5 text-xl font-bold text-text-primary"><?= number_format($painel['nps_integracao']['nps'], 1, ',', '.') ?></p>
            <p class="text-[11px] text-text-secondary"><?= (int)$painel['nps_integracao']['amostra'] ?> resposta(s)</p>
          <?php endif; ?>
        </div>
      </article>

      <article class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
        <h2 class="text-sm font-bold text-text-primary">Experiência</h2>
        <p class="text-[11px] text-text-secondary">Avaliação do período de 90 dias</p>
        <div class="mt-1.5 grid grid-cols-2 gap-2 text-center">
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-success"><?= (int)$painel['avaliacao_experiencia']['realizadas'] ?></p>
            <p class="text-[11px] text-text-secondary">Avaliações realizadas</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-warning"><?= (int)$painel['avaliacao_experiencia']['pendentes'] ?></p>
            <p class="text-[11px] text-text-secondary">Avaliações pendentes</p>
          </div>
        </div>
        <?php if ((int)$painel['avaliacao_experiencia']['realizadas'] === 0 && (int)$painel['avaliacao_experiencia']['pendentes'] === 0): ?>
          <p class="mt-1.5 text-[11px] text-text-secondary">O controle interno de RH em Solicitação de Vaga ainda não foi utilizado para nenhuma contratação.</p>
        <?php endif; ?>
      </article>
    </div>
  </section>

  <!-- Dados ainda não integrados -->
  <section class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
    <h2 class="text-xs font-bold uppercase tracking-wide text-text-secondary">Dados ainda não integrados</h2>
    <div class="mt-2 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
      <div class="rounded-ds-md bg-background p-2 text-center text-text-secondary">Banco de Horas<br><span class="text-[11px]">Dado ainda não integrado</span></div>
      <div class="rounded-ds-md bg-background p-2 text-center text-text-secondary">Horas Extras<br><span class="text-[11px]">Dado ainda não integrado</span></div>
      <div class="rounded-ds-md bg-background p-2 text-center text-text-secondary">Férias Programadas<br><span class="text-[11px]">Dado ainda não integrado</span></div>
      <div class="rounded-ds-md bg-background p-2 text-center text-text-secondary">Férias a Vencer<br><span class="text-[11px]">Dado ainda não integrado</span></div>
    </div>
    <p class="mt-1.5 text-[11px] text-text-secondary">Dependem de fontes ainda não integradas ao Portal.</p>
  </section>

  <?php endif; ?>
</div>
