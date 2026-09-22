/*
 * Candidaturas — lista e detalhe. Movido, sem mudança de lógica, dos <script> inline das views (a CSP do Portal é
 * `script-src 'self'`, então scripts inline e onclick= são bloqueados). Cada bloco se protege sozinho quando os elementos da
 * tela não existem, então o arquivo é seguro em qualquer uma das duas telas.
 */

// ---- Lista: modal "Registrar indicação"
(() => {
  const modal = document.getElementById('indicacao-modal');
  const input = document.getElementById('indicacao-colaborador-input');
  const erro = document.getElementById('indicacao-colaborador-erro');
  const btnCancelar = document.getElementById('indicacao-cancelar');
  const btnConfirmar = document.getElementById('indicacao-confirmar');
  if (!modal || !input || !erro || !btnCancelar || !btnConfirmar) return; // outras telas que carregam este arquivo
  let activeForm = null;
  let activeCheck = null;

  const openModal = (form, check) => {
    activeForm = form;
    activeCheck = check;
    const hidden = form.querySelector('[data-indicacao-nome-hidden]');
    input.value = hidden && hidden.value ? hidden.value : '';
    erro.classList.add('hidden');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => input.focus(), 10);
  };

  const closeModal = (restoreCheck) => {
    if (restoreCheck && activeCheck) activeCheck.checked = false;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    activeForm = null;
    activeCheck = null;
  };

  document.querySelectorAll('[data-indicacao-check]').forEach((check) => {
    check.addEventListener('change', () => {
      const form = check.closest('form');
      if (!form) return;
      if (!check.checked) {
        const hidden = form.querySelector('[data-indicacao-nome-hidden]');
        if (hidden) hidden.value = '';
        form.submit();
        return;
      }
      openModal(form, check);
    });
  });

  btnCancelar.addEventListener('click', () => closeModal(true));
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal(true);
  });

  btnConfirmar.addEventListener('click', () => {
    if (!activeForm) return;
    const formRef = activeForm;
    const nome = (input.value || '').trim();
    if (!nome) {
      erro.classList.remove('hidden');
      input.focus();
      return;
    }
    const hidden = formRef.querySelector('[data-indicacao-nome-hidden]');
    if (hidden) hidden.value = nome;
    closeModal(false);
    formRef.submit();
  });
})();

// ---- Detalhe: caixa do colaborador que indicou
(() => {
  const check = document.getElementById('indicacao-colaborador-check');
  const box = document.getElementById('indicacao-colaborador-box');
  if (!check || !box) return;
  const sync = () => {
    if (check.checked) {
      box.classList.remove('hidden');
    } else {
      box.classList.add('hidden');
      const input = box.querySelector('input[name="indicacao_colaborador_nome"]');
      if (input) input.value = '';
    }
  };
  check.addEventListener('change', sync);
  sync();
})();

// ---- Detalhe: histórico de comunicações
(() => {
  // Detalhe: "Ver conteúdo completo" das comunicações (antes um onclick= inline) — alterna o bloco indicado em data-toggle-target.
  document.querySelectorAll('[data-toggle-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const alvo = document.getElementById(btn.getAttribute('data-toggle-target'));
      if (alvo) alvo.classList.toggle('hidden');
    });
  });
})();
