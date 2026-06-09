<?php
?>
<div class="bg-white shadow rounded p-6">
  <h2 class="text-xl font-semibold text-ctpblue">Vagas Disponíveis</h2>
  <p class="text-gray-600 mt-1">Confira as oportunidades e candidate-se.</p>

  <?php if (!empty($erro)): ?>
    <div class="mt-3 p-3 rounded bg-red-50 text-red-700 text-sm"><?= Security::e($erro) ?></div>
  <?php endif; ?>

  <div class="mt-4 grid md:grid-cols-2 gap-4">
    <?php if (empty($vagas)): ?>
      <div class="text-gray-500">Nenhuma vaga ativa no momento.</div>
    <?php else: ?>
      <?php foreach ($vagas as $v): ?>
        <div class="border rounded p-4">
          <h3 class="font-medium text-ctpblue"><?= Security::e($v['titulo']) ?></h3>
          <p class="text-sm text-gray-600">Área: <?= Security::e($v['area']) ?> • Local: <?= Security::e($v['local']) ?></p>
          <p class="text-sm mt-1">Requisitos: <?= Security::e($v['requisitos']) ?></p>
          <a href="<?= $base ?>/vaga/<?= (int)$v['id'] ?>" class="mt-3 inline-block bg-ctgreen text-white px-3 py-2 rounded hover:bg-ctdark">Saiba Mais</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>