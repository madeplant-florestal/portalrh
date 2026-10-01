<?php
/**
 * Bloco "Gerar/Adicionar ao PDI" — COMPARTILHADO entre Avaliação de Experiência, Feedback e Avaliação de
 * Desempenho (Etapa 7). Espera no escopo: $documentoTipo ('avaliacao_experiencia'|'feedback'|
 * 'avaliacao_desempenho'), $documentoId, $concluida (bool — só documento concluído pode alimentar PDI,
 * §25), $podeGerarPdi (bool — Authorization::temPermissao('pdi.gerenciar')), $pdisRelacionados (saída de
 * PdiRepository::pdisPorOrigem()), $base.
 *
 * Nunca cria nada aqui: o botão só leva à tela intermediária de seleção (AdminPdisController::origem()),
 * onde o gestor/RH confirma explicitamente o que vira PDI (§3).
 */
?>
<section class="rounded-ds-lg border border-border bg-white p-4">
  <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">PDI</h3>
  <p class="mt-1 text-xs text-text-secondary">Avaliações e feedbacks identificam necessidades de desenvolvimento que podem ser acompanhadas por meio de um PDI — a decisão de gerar ou não é sempre do gestor/RH.</p>

  <?php if ($pdisRelacionados !== []): ?>
    <ul class="mt-3 space-y-1.5">
      <?php foreach ($pdisRelacionados as $p): ?>
        <li class="text-sm"><a href="<?= $base ?>/admin/pdis/<?= (int)$p['id'] ?>" class="font-semibold text-primary-700 hover:underline">PDI relacionado #<?= (int)$p['id'] ?></a> <span class="text-text-secondary">— <?= Security::e(PdiService::STATUS[$p['status']] ?? $p['status']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($concluida && $podeGerarPdi): ?>
    <a href="<?= $base ?>/admin/pdis/origem?tipo=<?= Security::e($documentoTipo) ?>&ref=<?= (int)$documentoId ?>" class="mt-3 inline-block <?= ui_btn('secundario') ?>">Gerar/Adicionar ao PDI</a>
  <?php elseif (!$concluida): ?>
    <p class="mt-3 text-xs text-text-muted">Disponível depois que o documento for concluído.</p>
  <?php endif; ?>
</section>
