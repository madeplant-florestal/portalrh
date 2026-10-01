<?php
/**
 * "Gerar/Adicionar ao PDI" a partir de uma origem (Etapa 7, §14/§15) — Avaliação de Experiência,
 * Feedback ou Avaliação de Desempenho concluídos. NÃO cria nem vincula nada ao simplesmente abrir esta
 * tela (§3): a decisão é sempre do gestor/RH, confirmada ao clicar em um dos dois botões abaixo.
 *
 * Um único <form> — os dois botões usam `formaction` para apontar para o endpoint certo (criar PDI novo
 * ou vincular a um PDI existente), assim as mesmas necessidades marcadas (itens[]) valem para qualquer
 * um dos dois caminhos, sem duplicar a lista de checkboxes.
 */
require_once __DIR__ . '/_helpers.php';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
$card = 'rounded-ds-lg border border-border bg-white p-4';
?>
<div class="space-y-5">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'pdi', [
      'titulo' => 'Gerar/Adicionar ao PDI',
      'descricao' => 'Origem: ' . Security::e($rotuloOrigem) . ' — ' . Security::e($contexto['snap_nome']),
  ], [['label' => 'Gerar/Adicionar ao PDI']]) ?>

  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashErro) ?></div><?php endif; ?>

  <form method="post" action="<?= Security::e($base . '/admin/pdis/origem/criar') ?>" class="space-y-5">
    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="origem_tipo" value="<?= Security::e($origemTipo) ?>">
    <input type="hidden" name="origem_ref_id" value="<?= (int)$origemRefId ?>">

    <section class="<?= $card ?>">
      <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Necessidades identificadas na origem</h3>
      <p class="mt-1 text-xs text-text-secondary">Marque o que deve alimentar o PDI. O documento original (<?= Security::e($rotuloOrigem) ?>) não é alterado — isto só cria um vínculo de rastreabilidade.</p>
      <?php if ($contexto['itens'] === []): ?>
        <p class="mt-3 text-sm text-text-secondary">Nenhum texto de apoio preenchido nesta avaliação. Você ainda pode criar/adicionar ao PDI sem pré-selecionar necessidades.</p>
      <?php endif; ?>
      <div class="mt-3 space-y-2">
        <?php foreach ($contexto['itens'] as $item): ?>
          <label class="flex items-start gap-2 rounded-lg border border-border p-2.5 text-sm">
            <input type="checkbox" name="itens[]" value="<?= Security::e((string)$item['chave']) ?>" class="mt-0.5 h-4 w-4 accent-primary-700" checked>
            <span><?= nl2br(Security::e((string)$item['texto'])) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <section class="<?= $card ?>">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Criar novo PDI</h3>
        <p class="mt-1 text-xs text-text-secondary">Abre um PDI em rascunho com a origem registrada e as necessidades marcadas como objetivo esperado. Você completa ações, competências e prazos em seguida.</p>
        <button type="submit" formaction="<?= Security::e($base . '/admin/pdis/origem/criar') ?>" class="mt-3 <?= ui_btn('primario') ?>">Criar novo PDI a partir desta origem</button>
      </section>

      <section class="<?= $card ?>">
        <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Adicionar a um PDI existente</h3>
        <p class="mt-1 text-xs text-text-secondary">Só PDIs do mesmo colaborador, acessíveis a você.</p>
        <?php if ($pdisExistentes === []): ?>
          <p class="mt-3 text-sm text-text-secondary">Nenhum PDI existente para este colaborador no seu escopo.</p>
        <?php else: ?>
          <div class="mt-3 space-y-2">
            <?php foreach ($pdisExistentes as $p): ?>
              <label class="flex items-center justify-between gap-2 rounded-lg border border-border p-2.5 text-sm">
                <span class="flex items-center gap-2"><input type="radio" name="pdi_id" value="<?= (int)$p['id'] ?>" required class="h-4 w-4 accent-primary-700"> PDI #<?= (int)$p['id'] ?> — <?= Security::e(PdiService::STATUS[$p['status']] ?? $p['status']) ?></span>
                <a href="<?= $base ?>/admin/pdis/<?= (int)$p['id'] ?>" target="_blank" class="text-xs font-semibold text-primary-700 hover:underline">Ver →</a>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" formaction="<?= Security::e($base . '/admin/pdis/origem/vincular') ?>" class="mt-3 <?= ui_btn('secundario') ?>">Adicionar origem ao PDI selecionado</button>
        <?php endif; ?>
      </section>
    </div>
  </form>
</div>
