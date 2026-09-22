<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$avaliacao = $avaliacao ?? [];
?>
<div class="space-y-4 max-w-3xl">
  <?= ui_modulo_topo($base, 'cadastros', 'avaliacoes', ['titulo' => isset($avaliacao['id']) ? 'Editar avaliação' : 'Nova avaliação', 'descricao' => 'Registre avaliações formais para alimentar os fluxos internos do RH.'], [['label' => isset($avaliacao['id']) ? 'Editar avaliação' : 'Nova avaliação']]) ?>
  <div class="responsive-panel">
  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= isset($avaliacao['id']) ? '/admin/avaliacoes/editar/' . (int)$avaliacao['id'] : '/admin/avaliacoes/novo' ?>" method="post" class="mt-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

    <div>
      <label class="block text-sm font-medium text-text-primary">Colaborador *</label>
      <select name="colaborador_id" class="mt-1 w-full rounded border px-3 py-2" required>
        <option value="">Selecione</option>
        <?php foreach ($colaboradorOptions as $item): ?>
          <option value="<?= (int)$item['id'] ?>" <?= (int)($avaliacao['colaborador_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>>
            <?= Security::e($item['nome'] . (!empty($item['matricula']) ? ' - ' . $item['matricula'] : '') . ' - ' . $item['cargo_nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-text-primary">Título da avaliação *</label>
        <input type="text" name="titulo" value="<?= Security::e((string)($avaliacao['titulo'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Período de referência *</label>
        <input type="text" name="periodo_referencia" value="<?= Security::e((string)($avaliacao['periodo_referencia'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 1º semestre de 2026" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Nota</label>
        <input type="text" name="nota" value="<?= Security::e((string)($avaliacao['nota'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="0 a 10">
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Resumo</label>
      <textarea name="resumo" rows="5" class="mt-1 w-full rounded border px-3 py-2"><?= Security::e((string)($avaliacao['resumo'] ?? '')) ?></textarea>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Salvar avaliação</button>
      <a href="<?= $base ?>/admin/avaliacoes" class="text-sm font-medium text-primary-700 hover:text-primary-700">Cancelar</a>
    </div>
  </form>
</div>
</div>
