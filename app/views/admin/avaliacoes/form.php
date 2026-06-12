<?php
$avaliacao = $avaliacao ?? [];
?>
<div class="responsive-panel max-w-3xl">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue"><?= isset($avaliacao['id']) ? 'Editar avaliação' : 'Nova avaliação' ?></h2>
      <p class="mt-1 text-sm text-gray-500">Registre avaliações formais para alimentar os fluxos internos do RH.</p>
    </div>
    <a href="<?= $base ?>/admin/avaliacoes" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Voltar</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= isset($avaliacao['id']) ? '/admin/avaliacoes/editar/' . (int)$avaliacao['id'] : '/admin/avaliacoes/novo' ?>" method="post" class="mt-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

    <div>
      <label class="block text-sm font-medium text-gray-700">Colaborador *</label>
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
        <label class="block text-sm font-medium text-gray-700">Título da avaliação *</label>
        <input type="text" name="titulo" value="<?= Security::e((string)($avaliacao['titulo'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Período de referência *</label>
        <input type="text" name="periodo_referencia" value="<?= Security::e((string)($avaliacao['periodo_referencia'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 1º semestre de 2026" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Nota</label>
        <input type="text" name="nota" value="<?= Security::e((string)($avaliacao['nota'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="0 a 10">
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Resumo</label>
      <textarea name="resumo" rows="5" class="mt-1 w-full rounded border px-3 py-2"><?= Security::e((string)($avaliacao['resumo'] ?? '')) ?></textarea>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Salvar avaliação</button>
      <a href="<?= $base ?>/admin/avaliacoes" class="text-sm font-medium text-ctpblue hover:text-ctgreen">Cancelar</a>
    </div>
  </form>
</div>
