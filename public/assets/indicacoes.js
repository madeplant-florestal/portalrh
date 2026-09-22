/*
 * Programa de Indicações — modais "Registrar pagamento" e "Editar data de pagamento". Movido, sem mudança de lógica, do
 * <script> inline da view (a CSP do Portal é `script-src 'self'`, que bloqueia scripts inline). Seguro se os elementos não existirem.
 */
(() => {
  const modal = document.getElementById('pagamento-modal');
  const input = document.getElementById('payment-date-input');
  const error = document.getElementById('payment-date-error');
  const btnCancel = document.getElementById('payment-cancel');
  const btnConfirm = document.getElementById('payment-confirm');
  const paymentMethodInput = document.getElementById('payment-method-input');
  const editModal = document.getElementById('pagamento-edit-modal');
  const editDateInput = document.getElementById('payment-edit-date-input');
  const editReasonInput = document.getElementById('payment-edit-reason-input');
  const editError = document.getElementById('payment-edit-error');
  const editCancel = document.getElementById('payment-edit-cancel');
  const editConfirm = document.getElementById('payment-edit-confirm');
  if (!modal || !editModal || !input || !editDateInput || !editReasonInput || !btnCancel || !btnConfirm || !editCancel || !editConfirm || !error || !editError || !paymentMethodInput) return;
  const modalPanel = modal ? modal.querySelector('.ind-modal-panel') : null;
  const editModalPanel = editModal ? editModal.querySelector('.ind-modal-panel') : null;
  let activeForm = null;
  let activeEditForm = null;
  let lastFocusElement = null;

  const close = () => {
    modal.classList.add('hidden');
    modal.classList.remove('is-open');
    activeForm = null;
    error.classList.add('hidden');
    if (lastFocusElement) lastFocusElement.focus();
  };

  const closeEdit = () => {
    editModal.classList.add('hidden');
    editModal.classList.remove('is-open');
    activeEditForm = null;
    editError.classList.add('hidden');
    if (lastFocusElement) lastFocusElement.focus();
  };

  const open = (form) => {
    activeForm = form;
    lastFocusElement = document.activeElement;
    input.value = '';
    paymentMethodInput.value = '';
    error.classList.add('hidden');
    modal.classList.remove('hidden');
    modal.classList.add('is-open');
    setTimeout(() => (modalPanel || input).focus(), 10);
  };

  const openEdit = (form) => {
    activeEditForm = form;
    lastFocusElement = document.activeElement;
    editDateInput.value = '';
    editReasonInput.value = '';
    editError.classList.add('hidden');
    editModal.classList.remove('hidden');
    editModal.classList.add('is-open');
    setTimeout(() => (editModalPanel || editDateInput).focus(), 10);
  };

  const maskDate = (value) => {
    const only = value.replace(/\D/g, '').slice(0, 8);
    const p1 = only.slice(0, 2);
    const p2 = only.slice(2, 4);
    const p3 = only.slice(4, 8);
    if (only.length <= 2) return p1;
    if (only.length <= 4) return `${p1}/${p2}`;
    return `${p1}/${p2}/${p3}`;
  };

  const validateDate = (br) => {
    if (!/^\d{2}\/\d{2}\/\d{4}$/.test(br)) return 'Informe uma data válida no formato DD/MM/AAAA.';
    const [d, m, y] = br.split('/').map(Number);
    const dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return 'Data inválida.';
    const today = new Date();
    today.setHours(0,0,0,0);
    dt.setHours(0,0,0,0);
    if (dt > today) return 'A data de pagamento não pode ser futura.';
    const min = new Date(today);
    min.setDate(min.getDate() - 90);
    if (dt < min) return 'A data de pagamento não pode ser superior a 90 dias no passado.';
    return '';
  };

  input.addEventListener('input', () => {
    input.value = maskDate(input.value);
  });
  editDateInput.addEventListener('input', () => {
    editDateInput.value = maskDate(editDateInput.value);
  });

  document.querySelectorAll('[data-pagamento-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-pagamento-open');
      const form = document.querySelector(`form[data-pagamento-form="${id}"]`);
      if (!form) return;
      open(form);
    });
  });
  document.querySelectorAll('[data-pagamento-edit-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-pagamento-edit-open');
      const form = document.querySelector(`form[data-pagamento-edit-form="${id}"]`);
      if (!form) return;
      openEdit(form);
    });
  });

  btnCancel.addEventListener('click', close);
  editCancel.addEventListener('click', closeEdit);
  modal.addEventListener('click', (e) => {
    if (e.target === modal) close();
  });
  editModal.addEventListener('click', (e) => {
    if (e.target === editModal) closeEdit();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (modal.classList.contains('is-open')) close();
      if (editModal.classList.contains('is-open')) closeEdit();
    }
  });

  btnConfirm.addEventListener('click', () => {
    if (!activeForm) return;
    const formRef = activeForm;
    const value = (input.value || '').trim();
    const invalid = validateDate(value);
    if (invalid) {
      error.textContent = invalid;
      error.classList.remove('hidden');
      input.focus();
      return;
    }
    const target = formRef.querySelector('[data-payment-date-hidden]');
    const methodTarget = formRef.querySelector('[data-payment-method-hidden]');
    const methodValue = (paymentMethodInput.value || '').trim();
    if (!target || !methodTarget) return;
    if (!methodValue) {
      error.textContent = 'Informe o método de pagamento.';
      error.classList.remove('hidden');
      paymentMethodInput.focus();
      return;
    }
    target.value = value;
    methodTarget.value = methodValue;
    close();
    formRef.submit();
  });
  editConfirm.addEventListener('click', () => {
    if (!activeEditForm) return;
    const formRef = activeEditForm;
    const dateValue = (editDateInput.value || '').trim();
    const reasonValue = (editReasonInput.value || '').trim();
    const invalid = validateDate(dateValue);
    if (invalid) {
      editError.textContent = invalid;
      editError.classList.remove('hidden');
      editDateInput.focus();
      return;
    }
    if (!reasonValue) {
      editError.textContent = 'Informe o motivo da alteração.';
      editError.classList.remove('hidden');
      editReasonInput.focus();
      return;
    }
    const targetDate = formRef.querySelector('[data-payment-edit-date-hidden]');
    const targetReason = formRef.querySelector('[data-payment-edit-reason-hidden]');
    if (!targetDate || !targetReason) return;
    targetDate.value = dateValue;
    targetReason.value = reasonValue;
    closeEdit();
    formRef.submit();
  });
})();
