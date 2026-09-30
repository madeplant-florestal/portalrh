<?php
/**
 * Hub de "Avaliações e Desenvolvimento" (Etapa 5, 2026-09) — duas funções: NAVEGAÇÃO (cards de acesso)
 * e RESUMO GERENCIAL (contagens reais). Hierarquia conceitual aprovada: os processos de avaliação
 * (Experiência/Desempenho/Feedback) geram resultados/pontos fortes/GAPs que alimentam o PDI — o PDI
 * é a camada de DESENVOLVIMENTO resultante, não mais um card isolado no mesmo nível.
 *
 * `Avaliação de Desempenho` aponta para a implementação NATIVA (Etapa 6, /admin/avaliacoes-desempenho,
 * AvaliacaoDesempenhoService/colaboradores_metadados) — o legado /admin/avaliacoes permanece só em
 * Cadastros, sem relação de código com este hub.
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$card = 'rounded-ds-lg border border-border bg-white p-4';
$cardsProcesso = [];
if ($temExperiencia) {
    $cardsProcesso[] = ['titulo' => 'Avaliação do Período de Experiência', 'icone' => 'avaliacoes-desenvolvimento', 'href' => $base . '/admin/avaliacoes-experiencia',
        'descricao' => 'Acompanhe e realize as avaliações de 45 e 90 dias dos colaboradores em período de experiência.'];
}
if ($temDesempenho) {
    $cardsProcesso[] = ['titulo' => 'Avaliação de Desempenho', 'icone' => 'indicadores', 'href' => $base . '/admin/avaliacoes-desempenho',
        'descricao' => 'Avalie o desempenho por ciclo, com critérios estruturados, GAP e resultado final.'];
}
if ($temFeedback) {
    $cardsProcesso[] = ['titulo' => 'Feedback', 'icone' => 'mensagens', 'href' => $base . '/admin/feedbacks',
        'descricao' => 'Registre reconhecimentos, alinhamentos, acompanhamentos e oportunidades de desenvolvimento.'];
}
$cardsDesenvolvimento = [];
if ($temPdi) {
    $cardsDesenvolvimento[] = ['titulo' => 'PDI', 'icone' => 'pdi', 'href' => $base . '/admin/pdis',
        'descricao' => 'Plano de Desenvolvimento Individual — crie e acompanhe planos, ações e evolução dos colaboradores.'];
}
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'resumo', [
      'titulo' => 'Avaliações e Desenvolvimento',
      'descricao' => 'Acompanhe avaliações, feedbacks, planos de desenvolvimento e a evolução dos colaboradores.',
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>

  <?php if ($cardsProcesso !== []): ?>
    <section class="space-y-3">
      <h2 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Processos de Avaliação e Acompanhamento</h2>
      <p class="text-xs text-text-secondary">Avaliar → identificar pontos fortes, GAPs e necessidades de desenvolvimento → alimentar o PDI.</p>
      <?= ui_module_grid($cardsProcesso, 'Processos de avaliação') ?>
    </section>
  <?php endif; ?>

  <?php if ($cardsDesenvolvimento !== []): ?>
    <div class="flex items-center gap-3 pt-1">
      <span class="h-px flex-1 bg-border"></span>
      <span class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">resulta em</span>
      <span class="h-px flex-1 bg-border"></span>
    </div>
    <section class="space-y-3">
      <h2 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Desenvolvimento</h2>
      <p class="text-xs text-text-secondary">Planos de ação construídos a partir dos resultados das avaliações e feedbacks.</p>
      <?= ui_module_grid($cardsDesenvolvimento, 'Desenvolvimento') ?>
    </section>
  <?php endif; ?>

  <?php if ($cardsProcesso === [] && $cardsDesenvolvimento === []): ?>
    <div class="rounded-lg border border-border bg-background px-4 py-6 text-center text-sm text-text-secondary">Você ainda não tem acesso a nenhuma área de Avaliações e Desenvolvimento.</div>
  <?php endif; ?>

  <?php if ($temExperiencia): ?>
    <section class="<?= $card ?>">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Resumo — Avaliação do Período de Experiência</h3>
        <a href="<?= $base ?>/admin/avaliacoes-experiencia" class="text-xs font-semibold text-primary-700 hover:underline">Ver lista completa →</a>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <?php foreach (['pendente', 'vencida', 'futura', 'em_preenchimento', 'aguardando_ciencia', 'realizada'] as $st): ?>
          <a href="<?= $base ?>/admin/avaliacoes-experiencia?status=<?= $st ?>" class="rounded-lg bg-background p-3 hover:bg-surface-secondary">
            <p class="text-2xl font-bold text-text-primary"><?= (int)($contagemExperiencia[$st] ?? 0) ?></p>
            <p class="text-xs text-text-secondary"><?= Security::e(AvaliacaoExperienciaService::ROTULOS_STATUS[$st]) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($temFeedback): ?>
    <section class="<?= $card ?>">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Resumo — Feedback</h3>
        <a href="<?= $base ?>/admin/feedbacks" class="text-xs font-semibold text-primary-700 hover:underline">Ver lista completa →</a>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <a href="<?= $base ?>/admin/feedbacks?status=rascunho" class="rounded-lg bg-background p-3 hover:bg-surface-secondary">
          <p class="text-2xl font-bold text-text-primary"><?= (int)($feedbacksPorStatus['rascunho'] ?? 0) ?></p>
          <p class="text-xs text-text-secondary">Em preenchimento</p>
        </a>
        <a href="<?= $base ?>/admin/feedbacks?status=concluido" class="rounded-lg bg-background p-3 hover:bg-surface-secondary">
          <p class="text-2xl font-bold text-text-primary"><?= (int)($feedbacksPorStatus['concluido'] ?? 0) ?></p>
          <p class="text-xs text-text-secondary">Concluídos</p>
        </a>
        <div class="rounded-lg bg-background p-3">
          <p class="text-2xl font-bold text-text-primary"><?= (int)$feedbacksDesenvolvimento ?></p>
          <p class="text-xs text-text-secondary">Necessitam acompanhamento específico</p>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($temDesempenho): ?>
    <section class="<?= $card ?>">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Resumo — Avaliação de Desempenho</h3>
        <a href="<?= $base ?>/admin/avaliacoes-desempenho" class="text-xs font-semibold text-primary-700 hover:underline">Ver lista completa →</a>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <?php foreach (AvaliacaoDesempenhoService::ROTULOS_STATUS as $st => $rotuloSt): ?>
          <a href="<?= $base ?>/admin/avaliacoes-desempenho?status=<?= $st ?>" class="rounded-lg bg-background p-3 hover:bg-surface-secondary">
            <p class="text-2xl font-bold text-text-primary"><?= (int)($desempenhoPorStatus[$st] ?? 0) ?></p>
            <p class="text-xs text-text-secondary"><?= Security::e($rotuloSt) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
