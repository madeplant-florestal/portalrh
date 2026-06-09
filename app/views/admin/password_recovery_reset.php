<?php
?>
<div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
  <div class="w-full max-w-md bg-white shadow-lg rounded-lg p-8">
    <h2 class="text-2xl font-semibold text-ctpblue text-center mb-6">Redefinir senha</h2>
    <?php if (!empty($error)): ?>
      <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg"><?= Security::e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($token)): ?>
      <form action="<?= $base ?>/admin/reset-password/<?= urlencode((string)$token) ?>" method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
          <input type="password" name="password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar nova senha</label>
          <input type="password" name="password_confirm" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
        </div>
        <button type="submit" class="w-full bg-ctgreen text-white py-2 rounded-lg hover:bg-ctdark">Redefinir senha</button>
      </form>
    <?php endif; ?>
    <div class="text-center mt-4">
      <a href="<?= $base ?>/admin/login" class="text-sm text-ctpblue hover:text-ctgreen">Voltar para login</a>
    </div>
  </div>
</div>

