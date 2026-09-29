<?php
/**
 * Resumo da área "Avaliações e Desenvolvimento" (§55 da Etapa 4, 2026-09). Cards executivos ligando
 * para as listas reais — nenhum número novo é calculado aqui (tudo vem pronto do controller). Ainda
 * não integrado ao People Analytics (§65) nem à Central do Portal (§64).
 */
require_once APP_PATH . '/views/partials/ui-shell.php';
$card = 'rounded-ds-lg border border-border bg-white p-4';
?>
<div class="space-y-5">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Avaliações e Desenvolvimento']]) ?>
  <?= ui_page_header([
      'titulo' => 'Avaliações e Desenvolvimento',
      'descricao' => 'Avaliação do Período de Experiência (45/90 dias), Feedback e, futuramente, PDI e Avaliação de Desempenho — um só domínio.',
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>

  <?php if ($temExperiencia): ?>
    <section class="<?= $card ?>">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Avaliação do Período de Experiência</h3>
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
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Feedback</h3>
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

  <?php if (!$temExperiencia && !$temFeedback): ?>
    <div class="rounded-lg border border-border bg-background px-4 py-6 text-center text-sm text-text-secondary">Você ainda não tem acesso a nenhuma área de Avaliações e Desenvolvimento.</div>
  <?php endif; ?>
</div>
