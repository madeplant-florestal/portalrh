<?php
$isActive = !empty($user->email_verified_at);
?>
<div class="responsive-panel max-w-2xl">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Detalhes do usuário</h2>
    <a href="<?= $base ?>/admin/usuarios" class="text-ctpblue hover:text-ctgreen">Voltar</a>
  </div>

  <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
    <div>
      <div class="text-gray-500">Nome completo</div>
      <div class="font-medium text-gray-900"><?= Security::e($user->nome) ?></div>
    </div>
    <div>
      <div class="text-gray-500">E-mail</div>
      <div class="font-medium text-gray-900"><?= Security::e($user->email) ?></div>
    </div>
    <div>
      <div class="text-gray-500">Permissão</div>
      <div class="font-medium text-gray-900"><?= Security::e(strtoupper($user->role)) ?></div>
    </div>
    <div>
      <div class="text-gray-500">Status</div>
      <span class="ct-badge mt-1 <?= $isActive ? 'ct-badge-active' : 'ct-badge-inactive' ?>">
        <?= $isActive ? 'Ativo' : 'Inativo' ?>
      </span>
    </div>
    <div>
      <div class="text-gray-500">Data de cadastro</div>
      <div class="font-medium text-gray-900"><?= !empty($user->created_at) ? date('d/m/Y H:i', strtotime((string)$user->created_at)) : '-' ?></div>
    </div>
    <div>
      <div class="text-gray-500">Último reset de senha</div>
      <div class="font-medium text-gray-900"><?= !empty($user->last_password_reset_at) ? date('d/m/Y H:i', strtotime((string)$user->last_password_reset_at)) : '-' ?></div>
    </div>
  </div>

  <div class="responsive-form-actions mt-6">
    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/status" method="post">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
      <button class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm">
        <?= $isActive ? 'Desativar usuário' : 'Ativar usuário' ?>
      </button>
    </form>
    <button type="button" id="open-password-modal" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm">
      Alterar Senha
    </button>
  </div>
</div>
<div id="password-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="w-full max-w-md rounded bg-white p-6 shadow">
    <div class="responsive-header">
      <h3 class="text-lg font-semibold text-ctpblue">Alterar senha do usuário</h3>
      <button type="button" id="close-password-modal" class="px-4 py-2 rounded border text-sm text-gray-600 hover:bg-gray-50">Fechar</button>
    </div>
    <form id="password-change-form" class="mt-4 space-y-3">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label class="block text-sm font-medium text-gray-700">Nova senha</label>
        <input type="password" name="new_password" required minlength="12" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Digite a nova senha">
      </div>
      <div class="text-sm text-gray-500">Tem certeza que deseja alterar a senha deste usuário?</div>
      <div class="responsive-form-actions justify-end pt-2">
        <button type="button" id="cancel-password-modal" class="px-4 py-2 rounded border text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
        <button type="submit" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm">Confirmar alteração</button>
      </div>
    </form>
  </div>
</div>
<script>
var openPasswordModalButton = document.getElementById('open-password-modal');
var closePasswordModalButton = document.getElementById('close-password-modal');
var cancelPasswordModalButton = document.getElementById('cancel-password-modal');
var passwordModal = document.getElementById('password-modal');
var passwordChangeForm = document.getElementById('password-change-form');

function openPasswordModal() {
  passwordModal.classList.remove('hidden');
  passwordModal.classList.add('flex');
}

function closePasswordModal() {
  passwordModal.classList.remove('flex');
  passwordModal.classList.add('hidden');
}

openPasswordModalButton.addEventListener('click', openPasswordModal);
closePasswordModalButton.addEventListener('click', closePasswordModal);
cancelPasswordModalButton.addEventListener('click', closePasswordModal);

passwordModal.addEventListener('click', function (event) {
  if (event.target === passwordModal) {
    closePasswordModal();
  }
});

passwordChangeForm.addEventListener('submit', async function (event) {
  event.preventDefault();
  var newPasswordInput = passwordChangeForm.querySelector('input[name="new_password"]');
  var csrfInput = passwordChangeForm.querySelector('input[name="csrf"]');
  var newPassword = newPasswordInput.value;
  var csrf = csrfInput.value;
  if (!newPassword) {
    alert('Informe a nova senha.');
    return;
  }
  if (!window.confirm('Tem certeza que deseja alterar a senha deste usuário?')) {
    return;
  }
  try {
    var response = await fetch('<?= $base ?>/api/admin/usuarios/<?= (int)$user->id ?>/password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf: csrf, new_password: newPassword })
    });
    var data = await response.json();
    if (!response.ok || !data.ok) {
      alert(data.error || 'Não foi possível alterar a senha.');
      return;
    }
    newPasswordInput.value = '';
    closePasswordModal();
    alert(data.message || 'Senha alterada com sucesso.');
  } catch (error) {
    alert('Erro de comunicação com o servidor.');
  }
});
</script>
