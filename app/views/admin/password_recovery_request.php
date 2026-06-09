<?php
?>
<div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
  <div class="w-full max-w-md bg-white shadow-lg rounded-lg p-8">
    <h2 class="text-2xl font-semibold text-ctpblue text-center mb-6">Recuperar senha</h2>
    <?php if (!empty($error)): ?>
      <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg"><?= Security::e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="mb-4 bg-ctlight border border-ctdark text-white px-4 py-3 rounded-lg"><?= Security::e($success) ?></div>
    <?php endif; ?>
    <form action="<?= $base ?>/admin/forgot-password" method="post" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
        <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
      </div>
      <button type="submit" class="w-full bg-ctgreen text-white py-2 rounded-lg hover:bg-ctdark">Enviar link de redefinição</button>
      <div class="text-center">
        <a href="<?= $base ?>/admin/login" class="text-sm text-ctpblue hover:text-ctgreen">Voltar para login</a>
      </div>
    </form>
  </div>
</div>
