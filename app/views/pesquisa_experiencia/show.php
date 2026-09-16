<?php
$encontrada = $encontrada ?? false;
$concluida = $concluida ?? false;

$criterios = [
    'nota_clareza' => 'Clareza das informações',
    'nota_tempo_retorno' => 'Tempo de retorno',
    'nota_atendimento' => 'Atendimento recebido',
];
?>
<div class="mx-auto max-w-md px-4 py-6">
  <?php if (!$encontrada): ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Link inválido</h1>
      <p class="mt-2 text-sm text-gray-600">Não encontramos esta pesquisa de experiência. Verifique se o link foi copiado corretamente.</p>
    </div>
  <?php elseif ($concluida): ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Obrigado pela sua avaliação</h1>
      <p class="mt-2 text-sm text-gray-600">Sua opinião nos ajuda a melhorar nossos processos seletivos.</p>
    </div>
  <?php else: ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Como você avalia sua experiência em nosso processo seletivo?</h1>

      <?php if (!empty($error)): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
      <?php endif; ?>

      <form action="<?= $base ?>/experiencia/<?= Security::e($token) ?>" method="post" class="mt-5 space-y-6">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

        <?php foreach ($criterios as $campo => $rotulo): ?>
          <div>
            <span class="block text-sm font-medium text-gray-800"><?= Security::e($rotulo) ?></span>
            <div class="mt-2 flex items-center gap-3" role="radiogroup" aria-label="<?= Security::e($rotulo) ?>">
              <?php for ($n = 1; $n <= 5; $n++): ?>
                <label class="flex flex-col items-center gap-1 text-sm text-gray-600">
                  <input type="radio" name="<?= $campo ?>" value="<?= $n ?>" required class="h-4 w-4">
                  <span>⭐ <?= $n ?></span>
                </label>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div>
          <label class="block text-sm font-medium text-gray-800">Comentários</label>
          <textarea name="comentarios" rows="4" maxlength="2000" class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" placeholder="Opcional"></textarea>
        </div>

        <button type="submit" class="w-full rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Enviar avaliação</button>
      </form>
    </div>
  <?php endif; ?>
</div>
