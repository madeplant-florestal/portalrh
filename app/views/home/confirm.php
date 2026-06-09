<?php
?>
<div class="bg-white shadow rounded p-6">
  <h2 class="text-xl font-semibold text-ctgreen">Candidatura enviada!</h2>
  <p class="mt-2 text-gray-700">Obrigado por se candidatar à vaga "<?= Security::e($vaga['titulo']) ?>". Em breve o RH entrará em contato.</p>
  <p class="mt-2 text-sm text-gray-600">Protocolo: #<?= (int)$cid ?> <?= $emailSent ? '(notificação enviada ao RH)' : '(notificação pendente)' ?></p>
  <a href="<?= $base ?>/vagas" class="mt-4 inline-block bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark">Voltar para vagas</a>
</div>
