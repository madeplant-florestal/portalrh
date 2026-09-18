<?php
/**
 * People Analytics — Tela Inicial / Dashboard principal. Container fluido (sem max-w-* artificial
 * — ver correção de largura da Tela de Usuários) e mesmo padrão visual de cards/barras dos demais
 * dashboards desta geração (reaproveita dashboard_bar_row() de partials/chart-helpers.php).
 * Organização/densidade inspiradas numa referência visual externa —
 * paleta, identidade e elementos gráficos permanecem os do Portal, nada copiado da referência.
 */
require_once __DIR__ . '/partials/chart-helpers.php';
?>
<div class="responsive-panel space-y-6">
  <section class="responsive-header">
    <div>
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">People Analytics</h1>
      <p class="mt-1 text-sm text-slate-500">Headcount, movimentação, turnover e processos de RH — consolidado a partir dos dados oficiais do Portal</p>
      <?php if ($erro === null): ?>
        <p class="mt-2 text-xs text-slate-500">
          <?php if (!empty($ultimaSincronizacao)): ?>
            Última atualização: <span class="font-semibold text-slate-700"><?= Security::e($ultimaSincronizacao) ?></span>
          <?php else: ?>
            Última atualização: <span class="font-semibold text-slate-700">—</span>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($erro !== null): ?>
    <section class="responsive-panel ring-1 ring-red-200 bg-red-50">
      <p class="text-sm font-semibold text-red-700"><?= Security::e($erro) ?></p>
    </section>
  <?php else: ?>

  <section class="responsive-panel ring-1 ring-slate-200">
    <form method="get" class="flex flex-wrap gap-2">
      <select name="periodo" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <?php foreach ($periodos as $chave => $label): ?>
          <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="empresa" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todas as empresas</option>
        <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
          <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="setor" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todos os setores</option>
        <?php foreach ($opcoesFiltro['setores'] as $setor): ?>
          <option value="<?= Security::e($setor['codigo_setor']) ?>" <?= $filtrosSelecionados['setor'] === $setor['codigo_setor'] ? 'selected' : '' ?>><?= Security::e($setor['nome'] ?? $setor['codigo_setor']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <p class="mt-2 text-xs text-slate-500">Período: <?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?> · Empresa/Setor usam os códigos oficiais do METADADOS · Vagas Abertas/Fechadas não respeitam o filtro de Setor (o módulo de Recrutamento ainda não tem essa dimensão)</p>
    <?php if ($painel['vagas']['empresa_sem_correspondencia']): ?>
      <p class="mt-1 text-xs font-medium text-amber-700">A Empresa selecionada ainda não tem correspondência no catálogo local de Empresas — Vagas Abertas/Fechadas ficam zeradas em vez de mostrar o total geral.</p>
    <?php endif; ?>
  </section>

  <!-- Primeira linha — indicadores executivos -->
  <section class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Headcount Atual</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['headcount']['atual'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-slate-500">contratos ativos hoje</p>
    </div>
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vagas Abertas</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['vagas']['abertas'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-slate-500"><?= $painel['vagas']['empresa_sem_correspondencia'] ? 'Empresa sem correspondência local' : 'situação atual' ?></p>
    </div>
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vagas Fechadas</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['vagas']['fechadas_no_periodo'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-slate-500"><?= $painel['vagas']['empresa_sem_correspondencia'] ? 'Empresa sem correspondência local' : 'no período' ?></p>
    </div>
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Admissões</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['admissoes']['periodo'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-slate-500">contratos admitidos no período</p>
    </div>
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Desligamentos</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['desligamentos']['periodo'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-slate-500">eventos no período</p>
    </div>
    <div class="responsive-panel ring-1 ring-slate-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Turnover Geral</p>
      <p class="mt-1 text-[1.75rem] font-bold leading-none text-slate-900"><?= number_format($painel['turnover']['geral_percentual'], 1, ',', '.') ?>%</p>
      <p class="mt-1 text-xs text-slate-500">headcount médio do período</p>
    </div>
  </section>

  <!-- Segunda camada — voluntário/involuntário, gênero, faixa etária -->
  <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <article class="responsive-panel ring-1 ring-slate-200">
      <h2 class="text-base font-bold text-slate-900">Turnover Voluntário × Involuntário</h2>
      <p class="text-xs text-slate-500">Mesma base de headcount do Turnover Geral — só muda quais desligamentos entram no numerador</p>
      <div class="mt-3 grid grid-cols-2 gap-3 text-center">
        <div class="rounded-xl bg-slate-50 p-3">
          <p class="text-2xl font-bold text-slate-900"><?= number_format($painel['turnover']['voluntario']['percentual'], 1, ',', '.') ?>%</p>
          <p class="text-xs text-slate-500">Voluntário (<?= (int)$painel['turnover']['voluntario']['eventos'] ?> evento(s))</p>
        </div>
        <div class="rounded-xl bg-slate-50 p-3">
          <p class="text-2xl font-bold text-slate-900"><?= number_format($painel['turnover']['involuntario']['percentual'], 1, ',', '.') ?>%</p>
          <p class="text-xs text-slate-500">Involuntário (<?= (int)$painel['turnover']['involuntario']['eventos'] ?> evento(s))</p>
        </div>
      </div>
      <p class="mt-3 text-xs text-slate-500">Desligamentos por acordo entre as partes, término de contrato de experiência/temporário e falecimento continuam contando em Desligamentos e no Turnover Geral, mas não entram em nenhum dos dois números acima — por isso Geral ≠ Voluntário + Involuntário.</p>
    </article>

    <article class="responsive-panel ring-1 ring-slate-200">
      <h2 class="text-base font-bold text-slate-900">Turnover por Gênero</h2>
      <p class="mt-1 text-xs text-slate-500">Fonte ainda não disponível</p>
      <p class="mt-3 text-sm text-slate-600">O espelho sincronizado do METADADOS (colaboradores_metadados) não possui campo de gênero/sexo hoje.</p>
    </article>

    <article class="responsive-panel ring-1 ring-slate-200">
      <h2 class="text-base font-bold text-slate-900">Turnover por Faixa Etária</h2>
      <p class="text-xs text-slate-500">Desligamentos do período, por idade na data da rescisão</p>
      <?php if ($painel['turnover']['faixa_etaria'] === null): ?>
        <p class="mt-3 text-sm text-slate-500">Dados insuficientes — nenhum desligamento classificável por idade no período selecionado.</p>
      <?php else: ?>
        <div class="mt-3 space-y-3">
          <?php $maxFaixa = max(array_column($painel['turnover']['faixa_etaria']['faixas'], 'quantidade')) ?: 1; ?>
          <?php foreach ($painel['turnover']['faixa_etaria']['faixas'] as $faixa): ?>
            <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixa, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Terceira camada — colaboradores por setor, integração, NPS, avaliação de experiência -->
  <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    <article class="responsive-panel ring-1 ring-slate-200">
      <h2 class="text-base font-bold text-slate-900">Colaboradores por Setor</h2>
      <p class="text-xs text-slate-500">Contratos ativos, agrupados pelo Setor oficial do METADADOS</p>
      <?php if ($painel['colaboradores_por_setor'] === []): ?>
        <p class="mt-3 text-sm text-slate-500">Nenhum contrato ativo para os filtros selecionados.</p>
      <?php else: ?>
        <div class="mt-3 space-y-3">
          <?php $maxSetor = max(array_column($painel['colaboradores_por_setor'], 'quantidade')) ?: 1; ?>
          <?php foreach (array_slice($painel['colaboradores_por_setor'], 0, 12) as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['quantidade'], $maxSetor, (string)$item['quantidade'], $item['label'] === 'Setor não informado' ? 'bg-slate-300' : 'bg-ctlight') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
      <article class="responsive-panel ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Integrações Realizadas</h2>
        <p class="text-xs text-slate-500">No período selecionado</p>
        <p class="mt-2 text-[1.75rem] font-bold text-slate-900"><?= number_format($painel['integracao']['realizadas_periodo'], 0, ',', '.') ?></p>
      </article>

      <article class="responsive-panel ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">NPS Integração</h2>
        <p class="text-xs text-slate-500">Respostas concluídas no período</p>
        <?php if ($painel['nps_integracao']['amostra'] === 0): ?>
          <p class="mt-2 text-sm text-slate-500">Dados insuficientes — nenhuma resposta concluída no período.</p>
        <?php else: ?>
          <p class="mt-2 text-[1.75rem] font-bold text-slate-900"><?= number_format($painel['nps_integracao']['nps'], 1, ',', '.') ?></p>
          <p class="text-xs text-slate-500"><?= (int)$painel['nps_integracao']['amostra'] ?> resposta(s) · <?= (int)$painel['nps_integracao']['promotores'] ?> promotor(es) · <?= (int)$painel['nps_integracao']['neutros'] ?> neutro(s) · <?= (int)$painel['nps_integracao']['detratores'] ?> detrator(es)</p>
        <?php endif; ?>
      </article>

      <article class="responsive-panel ring-1 ring-slate-200 sm:col-span-2">
        <h2 class="text-sm font-bold text-slate-900">Avaliação do Período de Experiência</h2>
        <p class="text-xs text-slate-500">Realizadas no período (prazo de 90 dias vencido dentro da janela) × pendentes atualmente</p>
        <div class="mt-3 grid grid-cols-2 gap-3 text-center">
          <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-2xl font-bold text-emerald-700"><?= (int)$painel['avaliacao_experiencia']['realizadas'] ?></p>
            <p class="text-xs text-slate-500">Realizadas</p>
          </div>
          <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-2xl font-bold text-amber-700"><?= (int)$painel['avaliacao_experiencia']['pendentes'] ?></p>
            <p class="text-xs text-slate-500">Pendentes (atual)</p>
          </div>
        </div>
        <?php if ((int)$painel['avaliacao_experiencia']['realizadas'] === 0 && (int)$painel['avaliacao_experiencia']['pendentes'] === 0): ?>
          <p class="mt-2 text-xs text-slate-400">O controle interno de RH em Solicitação de Vaga ainda não foi utilizado para nenhuma contratação.</p>
        <?php endif; ?>
      </article>
    </div>
  </section>

  <!-- Indicadores funcionais ainda não integrados -->
  <section class="responsive-panel ring-1 ring-slate-200">
    <h2 class="text-sm font-bold text-slate-900">Indicadores ainda não integrados ao Portal</h2>
    <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
      <div class="rounded-xl bg-slate-50 p-3 text-center text-slate-500">Banco de Horas<br><span class="text-xs">Dado ainda não integrado</span></div>
      <div class="rounded-xl bg-slate-50 p-3 text-center text-slate-500">Horas Extras<br><span class="text-xs">Dado ainda não integrado</span></div>
      <div class="rounded-xl bg-slate-50 p-3 text-center text-slate-500">Férias Programadas<br><span class="text-xs">Dado ainda não integrado</span></div>
      <div class="rounded-xl bg-slate-50 p-3 text-center text-slate-500">Férias a Vencer<br><span class="text-xs">Dado ainda não integrado</span></div>
    </div>
  </section>

  <?php endif; ?>
</div>
