<?php
$base = Config::app()['base_url'] ?? '';
?>
<div class="responsive-panel max-w-xl">
  <h2 class="text-xl font-semibold text-ctpblue">Cadastrar novo usuário</h2>
  <?php if (!empty($error)): ?>
    <div class="mt-3 p-3 bg-red-50 text-red-700 border border-red-200 rounded text-sm">
      <?= Security::e($error) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-3 p-3 bg-ctlight text-white border border-ctdark rounded text-sm">
      <?= Security::e($success) ?>
    </div>
  <?php endif; ?>
  <form class="mt-4 space-y-4" method="post" action="<?= $base ?>/admin/usuarios/novo">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
    <div>
      <label class="block text-sm text-gray-700">Nome</label>
      <input type="text" name="nome" class="mt-1 w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block text-sm text-gray-700">E-mail</label>
      <input type="email" name="email" class="mt-1 w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block text-sm text-gray-700">Senha</label>
      <input type="password" name="senha" class="mt-1 w-full border rounded px-3 py-2" minlength="12" required>
      <p class="mt-1 text-xs text-gray-500">Mínimo de 12 caracteres com maiúscula, minúscula, número e símbolo.</p>
    </div>
    <div>
      <label class="block text-sm text-gray-700">Perfil</label>
      <select name="role" class="mt-1 w-full border rounded px-3 py-2">
        <option value="viewer">Leitor</option>
        <option value="rh">RH</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="responsive-form-actions pt-2">
      <button type="submit" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark">Criar usuário</button>
      <a href="<?= $base ?>/admin" class="text-ctpblue hover:text-ctgreen">Voltar</a>
    </div>
  </form>
  <form class="mt-6 pt-4 border-t" method="post" action="<?= $base ?>/admin/usuarios/supervisor/garantir">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
    <h3 class="text-sm font-semibold text-gray-700 mb-2">Operação especial</h3>
    <p class="text-xs text-gray-500 mb-3">Cria ou atualiza o usuário Supervisor protegido com permissões irrestritas.</p>
    <button type="submit" class="bg-ctpblue text-white px-4 py-2 rounded hover:bg-ctdark">Garantir usuário Supervisor</button>
  </form>
</div>
