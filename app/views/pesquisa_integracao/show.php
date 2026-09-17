<?php
$encontrada = $encontrada ?? false;
$concluida = $concluida ?? false;

$criteriosSatisfacao = [
    'nota_clareza' => 'Clareza das informações apresentadas durante a integração',
    'nota_acolhimento' => 'Qualidade da recepção e acolhimento',
    'nota_normas' => 'Compreensão das normas, processos e orientações da empresa',
    'nota_utilidade' => 'Utilidade das informações recebidas para iniciar suas atividades',
    'nota_satisfacao_geral' => 'Satisfação geral com o processo de integração',
];
?>
<div class="mx-auto max-w-md px-4 py-6">
  <?php if (!$encontrada): ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Link inválido</h1>
      <p class="mt-2 text-sm text-gray-600">Não encontramos esta pesquisa de integração. Verifique se o link foi copiado corretamente.</p>
    </div>
  <?php elseif ($concluida): ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Obrigado pela sua avaliação</h1>
      <p class="mt-2 text-sm text-gray-600">Sua opinião nos ajuda a melhorar o processo de integração de novos colaboradores.</p>
    </div>
  <?php else: ?>
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <h1 class="text-lg font-semibold text-ctpblue">Como foi sua experiência de integração?</h1>

      <?php if (!empty($error)): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
      <?php endif; ?>

      <form action="<?= $base ?>/integracao/<?= Security::e($token) ?>" method="post" class="mt-5 space-y-6">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

        <div>
          <span class="block text-sm font-medium text-gray-800">De 0 a 10, o quanto você recomendaria a experiência de integração da empresa para um novo colaborador?</span>
          <div class="mt-2 grid grid-cols-6 gap-2 sm:grid-cols-11" role="radiogroup" aria-label="Nota de recomendação, de 0 a 10">
            <?php for ($n = 0; $n <= 10; $n++): ?>
              <label class="flex flex-col items-center gap-1 rounded border border-gray-200 py-2 text-sm text-gray-700 has-[:checked]:border-ctgreen has-[:checked]:bg-green-50">
                <input type="radio" name="nota_nps" value="<?= $n ?>" required class="h-4 w-4">
                <span><?= $n ?></span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="mt-1 flex justify-between text-xs text-gray-400">
            <span>Não recomendaria</span>
            <span>Recomendaria muito</span>
          </div>
        </div>

        <?php foreach ($criteriosSatisfacao as $campo => $rotulo): ?>
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
        <p class="text-xs text-gray-400">1 = Muito insatisfeito · 2 = Insatisfeito · 3 = Neutro · 4 = Satisfeito · 5 = Muito satisfeito</p>

        <div>
          <label class="block text-sm font-medium text-gray-800">Quer deixar algum comentário ou sugestão sobre sua integração?</label>
          <textarea name="comentarios" rows="4" maxlength="2000" class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" placeholder="Opcional"></textarea>
        </div>

        <button type="submit" class="w-full rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Enviar avaliação</button>
      </form>
    </div>
  <?php endif; ?>
</div>
