/*
 * Usuários e Acessos — detalhe do usuário. Movido, sem mudança de lógica, dos três <script> inline da view `usuarios/show`
 * (a CSP do Portal é `script-src 'self'`, que os bloqueia). O que antes vinha de PHP dentro do JS agora chega por data-attributes:
 *   - `data-busca-url`     no formulário de vínculo METADADOS (endpoint de busca);
 *   - `data-password-url`  no formulário do modal de senha (endpoint da API de troca de senha).
 * A confirmação de "Desvincular do METADADOS" virou `data-confirm-message` (tratado por admin.js). Cada bloco se protege
 * quando os elementos não existem, então o arquivo é seguro em qualquer tela.
 */

// ---- Vínculo opcional com o METADADOS: busca de contrato ativo -------------------------------------------------------------
(function () {
  var form = document.querySelector('[data-metadados-vinculo="1"]');
  if (!form) return;
  var busca = form.querySelector('[data-metadados-busca="1"]');
  var lista = form.querySelector('[data-metadados-resultados="1"]');
  var hidden = form.querySelector('[data-metadados-id="1"]');
  var selecionado = form.querySelector('[data-metadados-selecionado="1"]');
  var submitBtn = form.querySelector('[data-metadados-submit="1"]');
  var urlBusca = form.getAttribute('data-busca-url');
  if (!busca || !lista || !hidden || !selecionado || !submitBtn || !urlBusca) return;
  var timer = null;
  busca.addEventListener('input', function () {
    hidden.value = '';
    submitBtn.disabled = true;
    selecionado.classList.add('hidden');
    var q = busca.value.trim();
    window.clearTimeout(timer);
    if (q.length < 2) { lista.classList.add('hidden'); lista.innerHTML = ''; return; }
    timer = window.setTimeout(function () {
      fetch(urlBusca + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          lista.innerHTML = '';
          (data.items || []).forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'block w-full border-b border-border px-3 py-2 text-left hover:bg-surface-secondary';
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

// ---- Alterar senha do usuário (modal + chamada à API) ---------------------------------------------------------------------
(function () {
  var openPasswordModalButton = document.getElementById('open-password-modal');
  var closePasswordModalButton = document.getElementById('close-password-modal');
  var cancelPasswordModalButton = document.getElementById('cancel-password-modal');
  var passwordModal = document.getElementById('password-modal');
  var passwordChangeForm = document.getElementById('password-change-form');
  if (!openPasswordModalButton || !closePasswordModalButton || !cancelPasswordModalButton || !passwordModal || !passwordChangeForm) return;
  var urlSenha = passwordChangeForm.getAttribute('data-password-url');
  if (!urlSenha) return;

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
      var response = await fetch(urlSenha, {
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
})();
