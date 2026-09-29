(function () {
  'use strict';
  // Torna a Justificativa do parecer final obrigatória no cliente quando o parecer NÃO é "Apto para
  // efetivação" (§19 da Etapa 4). Só UX — a obrigatoriedade real é sempre validada no backend
  // (AvaliacaoExperienciaService::concluir()), nunca confiada só ao JS.
  var radios = document.querySelectorAll('[data-parecer-opcao]');
  var textarea = document.getElementById('parecer_justificativa');
  if (!textarea || radios.length === 0) {
    return;
  }
  function atualizar() {
    var marcado = document.querySelector('[data-parecer-opcao]:checked');
    textarea.required = !!marcado && marcado.value !== 'apto_efetivacao';
  }
  radios.forEach(function (r) { r.addEventListener('change', atualizar); });
})();
