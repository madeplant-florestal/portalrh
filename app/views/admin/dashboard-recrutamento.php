<?php
/**
 * Dashboard de Recrutamento e Seleção. Container fluido (sem max-w-* artificial — ver correção
 * de cache-busting/largura da Tela de Usuários) e mesmo padrão visual de cards/barras de
 * admin/indicadores-rh.php (reaproveita dashboard_bar_row() de partials/chart-helpers.php).
 *
 * Convenção de indisponibilidade: todo indicador que a Service não conseguiu calcular chega aqui
 * como `null` (nunca `0`) — a view escolhe a legenda certa ("Dados insuficientes" para amostra
 * zero, "Dados ainda não disponíveis" para fonte estruturalmente inexistente ainda).
 */
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

// Fechamento local (não função global) para não colidir se a view for incluída mais de uma vez
// na mesma requisição — mesmo cuidado de nomes globais já registrado em chart-helpers.php.
$drsValorOuInsuficiente = static function (?float $valor, string $sufixo = ''): string {
    return $valor === null ? 'Dados insuficientes' : number_format($valor, 1, ',', '.') . $sufixo;
};
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'recrutamento', 'dashboard', [
      'titulo' => 'Dashboard de Recrutamento e Seleção',
      'descricao' => 'Volume, velocidade, conversão e qualidade do processo seletivo',
  ]) ?>
  <a href="<?= $base ?>/vagas" target="_blank" rel="noopener"
     class="inline-flex items-center gap-1.5 text-ds-label font-semibold text-primary-700 hover:text-primary-800">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5L10 14M9 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-3"/></svg>
    Ver página pública de vagas
  </a>

  <?php if ($erro !== null): ?>
    <section class="responsive-panel ring-1 ring-danger/30 bg-danger/10">
      <p class="text-sm font-semibold text-danger"><?= Security::e($erro) ?></p>
    </section>
  <?php else: ?>

  <section class="responsive-panel ring-1 ring-border">
    <form method="get" class="flex flex-wrap gap-2">
      <select name="periodo" data-autosubmit="1" class="rounded-xl border border-border bg-white px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <?php foreach ($periodos as $chave => $label): ?>
          <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="empresa" data-autosubmit="1" class="rounded-xl border border-border bg-white px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todas as empresas</option>
        <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
          <option value="<?= Security::e((string)$empresa['id']) ?>" <?= $filtrosSelecionados['empresa'] === (string)$empresa['id'] ? 'selected' : '' ?>><?= Security::e($empresa['nome']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="vaga" data-autosubmit="1" class="rounded-xl border border-border bg-white px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todas as vagas</option>
        <?php foreach ($opcoesFiltro['vagas'] as $vaga): ?>
          <option value="<?= Security::e((string)$vaga['id']) ?>" <?= $filtrosSelecionados['vaga'] === (string)$vaga['id'] ? 'selected' : '' ?>><?= Security::e($vaga['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <p class="mt-2 text-xs text-text-secondary">Período: <?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?> · Empresa e Vaga não se aplicam aos indicadores de Efetivação/Turnover/Desligamento na experiência (fonte é o quadro oficial do METADADOS, sem vínculo direto com vaga/candidatura).</p>
  </section>

  <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Anúncios Ativos</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['vagas']['anuncios_ativos'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-text-secondary">publicados agora<?= $painel['vagas']['anuncios_manuais'] > 0 ? ' · ' . (int)$painel['vagas']['anuncios_manuais'] . ' de cadastro manual' : '' ?></p>
    </div>
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Posições Solicitadas</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['vagas']['posicoes_solicitadas'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-text-secondary">em <?= (int)$painel['vagas']['solicitacoes_com_anuncio'] ?> solicitação(ões) aprovada(s) com anúncio ativo<?= $painel['vagas']['aguardando_publicacao'] > 0 ? ' · ' . (int)$painel['vagas']['aguardando_publicacao'] . ' aprovada(s) aguardando publicação' : '' ?></p>
    </div>
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Vagas Fechadas</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['vagas']['fechadas_no_periodo'], 0, ',', '.') ?></p>
      <p class="mt-1 text-xs text-text-secondary">no período selecionado</p>
    </div>
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Tempo Médio de Contratação</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= $drsValorOuInsuficiente($painel['tempo_contratacao']['media_dias'], ' dias') ?></p>
      <p class="mt-1 text-xs text-text-secondary">inscrição → 1ª entrada em Admissão (<?= (int)$painel['tempo_contratacao']['amostra'] ?> candidato(s))</p>
    </div>
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Taxa de Efetivação</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= $drsValorOuInsuficiente($painel['qualidade']['efetivacao']['efetivacao']['percentual'], '%') ?></p>
      <p class="mt-1 text-xs text-text-secondary">após marco de 90 dias (<?= (int)$painel['qualidade']['efetivacao']['efetivacao']['elegiveis'] ?> elegível/is)</p>
    </div>
    <div class="responsive-panel ring-1 ring-border">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Turnover até 90 dias</p>
      <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['qualidade']['efetivacao']['turnover_precoce']['percentual_precoce'], 1, ',', '.') ?>%</p>
      <p class="mt-1 text-xs text-text-secondary"><?= (int)$painel['qualidade']['efetivacao']['turnover_precoce']['total_desligamentos'] ?> desligamento(s) no período</p>
    </div>
  </section>

  <?php if ($painel['vagas']['por_cargo'] !== []): ?>
  <section class="responsive-panel ring-1 ring-border">
    <div class="mb-3">
      <h2 class="text-base font-bold text-text-primary">Vagas abertas por cargo</h2>
      <p class="text-xs text-text-secondary">Situação atual. Posições vêm da Solicitação de Vaga aprovada; anúncios de cadastro manual não têm quantidade informada. Posições preenchidas ainda não são exibidas: não há registro confiável de contratação efetiva.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">
            <th class="py-2 pr-4">Cargo / função</th>
            <th class="py-2 pr-4 text-right">Anúncios ativos</th>
            <th class="py-2 text-right">Posições solicitadas</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <?php foreach ($painel['vagas']['por_cargo'] as $linhaCargo): ?>
            <tr>
              <td class="py-2 pr-4 text-text-primary"><?= Security::e($linhaCargo['cargo']) ?></td>
              <td class="py-2 pr-4 text-right text-text-primary"><?= number_format($linhaCargo['anuncios'], 0, ',', '.') ?></td>
              <td class="py-2 text-right text-text-primary"><?= $linhaCargo['posicoes'] === null ? 'não informada' : number_format($linhaCargo['posicoes'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    <article class="responsive-panel ring-1 ring-border">
      <div class="mb-3">
        <h2 class="text-base font-bold text-text-primary">Funil de Recrutamento</h2>
        <p class="text-xs text-text-secondary">Candidatos da coorte do período que tiveram passagem real por cada etapa (pipeline_movements)</p>
      </div>
      <?php if ($painel['total_candidaturas_cohort'] === 0): ?>
        <p class="py-6 text-center text-sm text-text-secondary">Nenhuma candidatura no período/filtros selecionados.</p>
      <?php else: ?>
        <?php $maxFunil = $painel['funil'][0]['quantidade'] ?: 1; ?>
        <div class="space-y-3">
          <?php foreach ($painel['funil'] as $etapa): ?>
            <?= dashboard_bar_row($etapa['label'], $etapa['quantidade'], $maxFunil, (string)$etapa['quantidade'], 'bg-primary-700') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="responsive-panel ring-1 ring-border">
      <div class="mb-3">
        <h2 class="text-base font-bold text-text-primary">Tempo Médio por Etapa</h2>
        <p class="text-xs text-text-secondary">Só passagens concluídas (entrada → próxima movimentação) entram na média</p>
      </div>
      <div class="space-y-4">
        <?php foreach ($painel['tempo_por_etapa'] as $etapa): ?>
          <div>
            <div class="mb-1 flex items-center justify-between text-[13px]">
              <span class="font-medium text-text-primary"><?= Security::e($etapa['label']) ?></span>
              <span class="font-semibold text-text-primary"><?= $drsValorOuInsuficiente($etapa['media_dias'], ' dias') ?></span>
            </div>
            <p class="text-xs text-text-secondary"><?= (int)$etapa['amostra_concluida'] ?> passagem(ns) concluída(s) · <?= (int)$etapa['atualmente_na_etapa'] ?> candidato(s) atualmente nesta etapa</p>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="grid grid-cols-1">
    <article class="responsive-panel ring-1 ring-border">
      <div class="mb-3">
        <h2 class="text-base font-bold text-text-primary">Qualidade da Contratação</h2>
        <p class="text-xs text-text-secondary">Fonte oficial: quadro sincronizado do METADADOS e Pesquisa de Experiência do Candidato</p>
      </div>
      <div class="mb-4 grid grid-cols-2 gap-3">
        <div class="rounded-xl bg-surface-secondary p-3">
          <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Efetivação após experiência</p>
          <p class="mt-1 text-lg font-bold text-text-primary"><?= $drsValorOuInsuficiente($painel['qualidade']['efetivacao']['efetivacao']['percentual'], '%') ?></p>
          <p class="text-xs text-text-secondary"><?= (int)$painel['qualidade']['efetivacao']['efetivacao']['efetivados'] ?> de <?= (int)$painel['qualidade']['efetivacao']['efetivacao']['elegiveis'] ?> elegível/is</p>
        </div>
        <div class="rounded-xl bg-surface-secondary p-3">
          <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Desligamento na experiência</p>
          <p class="mt-1 text-lg font-bold text-text-primary"><?= $drsValorOuInsuficiente($painel['qualidade']['efetivacao']['desligamento_experiencia']['percentual'], '%') ?></p>
          <p class="text-xs text-text-secondary"><?= (int)$painel['qualidade']['efetivacao']['desligamento_experiencia']['desligados'] ?> de <?= (int)$painel['qualidade']['efetivacao']['desligamento_experiencia']['elegiveis'] ?> elegível/is</p>
        </div>
      </div>

      <?php $pesquisa = $painel['qualidade']['pesquisa_experiencia']; ?>
      <div class="border-t border-border pt-4">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Pesquisa de Experiência do Candidato</p>
        <?php if ($pesquisa['respostas'] === 0): ?>
          <p class="text-sm text-text-secondary">Dados insuficientes — nenhuma pesquisa respondida no período selecionado.</p>
        <?php else: ?>
          <div class="mb-3 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-text-primary"><?= $drsValorOuInsuficiente($pesquisa['consolidada']) ?></span>
            <span class="text-xs text-text-secondary">nota média consolidada (0–5) · <?= (int)$pesquisa['respostas'] ?> resposta(s)</span>
          </div>
          <div class="grid grid-cols-3 gap-3 text-center text-sm">
            <div>
              <p class="font-bold text-text-primary"><?= $drsValorOuInsuficiente($pesquisa['clareza']) ?></p>
              <p class="text-xs text-text-secondary">Clareza</p>
            </div>
            <div>
              <p class="font-bold text-text-primary"><?= $drsValorOuInsuficiente($pesquisa['tempo_retorno']) ?></p>
              <p class="text-xs text-text-secondary">Tempo de retorno</p>
            </div>
            <div>
              <p class="font-bold text-text-primary"><?= $drsValorOuInsuficiente($pesquisa['atendimento']) ?></p>
              <p class="text-xs text-text-secondary">Atendimento</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </article>
  </section>

  <?php endif; ?>
</div>
