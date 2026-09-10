<?php
$isActive = !empty($user->email_verified_at);
$podeSolicitarVaga = (int)($user->pode_solicitar_vaga ?? 0) === 1;
$aprovador = $aprovador ?? null;
$aprovadorOptions = $aprovadorOptions ?? [];
$vinculoMetadados = $vinculoMetadados ?? null;
?>
<div class="responsive-panel max-w-2xl">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Detalhes do usuário</h2>
    <a href="<?= $base ?>/admin/usuarios" class="text-ctpblue hover:text-ctgreen">Voltar</a>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

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

  <section class="mt-8 rounded-xl border border-gray-200 bg-gray-50 p-5">
    <h3 class="text-lg font-semibold text-ctpblue">Solicitação de Vagas</h3>
    <p class="mt-1 text-sm text-gray-500">
      A autorização e a hierarquia de aprovação pertencem ao usuário — não dependem de existir em Colaboradores nem no METADADOS.
      Um gestor PJ/terceiro opera normalmente sem qualquer vínculo.
    </p>

    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/vaga-acesso" method="post" class="mt-4 space-y-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <label class="flex items-center gap-3 text-sm">
        <input type="checkbox" name="pode_solicitar_vaga" value="1" <?= $podeSolicitarVaga ? 'checked' : '' ?> class="h-4 w-4 rounded border-gray-300">
        <span class="font-medium text-gray-800">Pode solicitar vaga</span>
      </label>

      <div>
        <label class="block text-sm font-medium text-gray-700">Aprovador / líder imediato</label>
        <select name="aprovador_usuario_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          <option value="">— Sem aprovador configurado</option>
          <?php foreach ($aprovadorOptions as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>" <?= (int)($user->aprovador_usuario_id ?? 0) === (int)$opt['id'] ? 'selected' : '' ?>>
              <?= Security::e($opt['nome'] . ' (' . strtoupper((string)$opt['role']) . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-xs text-gray-500">
          Usado na 1ª etapa de aprovação (líder imediato). Se vazio: usuários comuns não conseguem concluir o envio;
          RH/Admin seguem direto para a etapa de RH.
        </p>
      </div>

      <button class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm">Salvar acesso a vagas</button>
    </form>

    <div class="mt-6 border-t border-gray-200 pt-4">
      <div class="text-sm font-medium text-gray-800">Vínculo opcional com o METADADOS</div>
      <?php if ($vinculoMetadados): ?>
        <div class="mt-2 rounded-lg border border-gray-200 bg-white p-3 text-sm">
          <div class="font-semibold text-gray-900"><?= Security::e((string)$vinculoMetadados['nome']) ?></div>
          <div class="mt-1 text-gray-600">
            <?= Security::e(trim(implode(' · ', array_filter([
              (string)($vinculoMetadados['empresa'] ?? ''),
              (string)($vinculoMetadados['unidade'] ?? ''),
              (string)($vinculoMetadados['setor'] ?? ''),
              (string)($vinculoMetadados['cargo'] ?? ''),
              'Contrato ' . (string)($vinculoMetadados['numero_contrato'] ?? ''),
            ])))) ?>
            <?= (int)($vinculoMetadados['ativo'] ?? 0) === 1 ? '' : ' — contrato desligado' ?>
          </div>
          <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/metadados-vinculo" method="post" class="mt-3">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="acao" value="desvincular">
            <button class="text-sm text-red-600 hover:text-red-700">Remover vínculo</button>
          </form>
        </div>
      <?php else: ?>
        <p class="mt-1 text-xs text-gray-500">Nenhum vínculo. O usuário funciona normalmente sem vínculo (caso PJ/terceiro).</p>
        <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/metadados-vinculo" method="post" class="mt-3 space-y-2" data-metadados-vinculo="1">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <input type="hidden" name="colaborador_metadados_id" value="" data-metadados-id="1">
          <input type="text" placeholder="Buscar pessoa/contrato ativo (nome, empresa, setor, cargo)" autocomplete="off"
                 class="w-full rounded border px-3 py-2 text-sm" data-metadados-busca="1">
          <div class="hidden rounded-lg border border-gray-200 bg-white text-sm" data-metadados-resultados="1"></div>
          <div class="hidden text-xs text-gray-600" data-metadados-selecionado="1"></div>
          <button class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm" data-metadados-submit="1" disabled>Vincular contrato oficial</button>
        </form>
        <script>
        (function () {
          var form = document.querySelector('[data-metadados-vinculo="1"]');
          if (!form) return;
          var busca = form.querySelector('[data-metadados-busca="1"]');
          var lista = form.querySelector('[data-metadados-resultados="1"]');
          var hidden = form.querySelector('[data-metadados-id="1"]');
          var selecionado = form.querySelector('[data-metadados-selecionado="1"]');
          var submitBtn = form.querySelector('[data-metadados-submit="1"]');
          var timer = null;
          busca.addEventListener('input', function () {
            hidden.value = '';
            submitBtn.disabled = true;
            selecionado.classList.add('hidden');
            var q = busca.value.trim();
            window.clearTimeout(timer);
            if (q.length < 2) { lista.classList.add('hidden'); lista.innerHTML = ''; return; }
            timer = window.setTimeout(function () {
              fetch('<?= $base ?>/admin/usuarios/metadados/buscar?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                  lista.innerHTML = '';
                  (data.items || []).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'block w-full border-b border-gray-100 px-3 py-2 text-left hover:bg-gray-50';
                    btn.textContent = item.nome + ' — ' + [item.empresa, item.setor, item.cargo, 'Contrato ' + item.contrato].filter(Boolean).join(' · ');
                    btn.addEventListener('click', function () {
                      hidden.value = String(item.id);
                      selecionado.textContent = 'Selecionado: ' + btn.textContent;
                      selecionado.classList.remove('hidden');
                      lista.classList.add('hidden');
                      submitBtn.disabled = false;
                    });
                    lista.appendChild(btn);
                  });
                  lista.classList.toggle('hidden', (data.items || []).length === 0);
                })
                .catch(function () { lista.classList.add('hidden'); });
            }, 250);
          });
        })();
        </script>
      <?php endif; ?>
    </div>
  </section>

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
