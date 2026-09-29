(function () {
  'use strict';
  // Comentário obrigatório no cliente SOMENTE quando "Desenvolvimento Necessário" é marcado (§34 da
  // Etapa 4). Só UX — a obrigatoriedade real é sempre validada no backend
  // (FeedbackService::validarValores()), nunca confiada só ao JS.
  document.querySelectorAll('[data-valor-cultural]').forEach(function (bloco) {
    var radios = bloco.querySelectorAll('[data-valor-radio]');
    var textarea = bloco.querySelector('textarea');
    var aviso = bloco.querySelector('.aviso-comentario-obrigatorio');
    if (!textarea || radios.length === 0) {
      return;
    }
    function atualizar() {
      var marcado = bloco.querySelector('[data-valor-radio]:checked');
      var obrigatorio = !!marcado && marcado.value === 'desenvolvimento_necessario';
      textarea.required = obrigatorio;
      if (aviso) {
        aviso.classList.toggle('hidden', !obrigatorio);
      }
    }
    radios.forEach(function (r) { r.addEventListener('change', atualizar); });
    atualizar();
  });
})();
