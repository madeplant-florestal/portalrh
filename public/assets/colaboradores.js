/*
 * Colaboradores — Dados RH. Movido, sem mudança de lógica, do <script> inline e do onclick="this.select()" da view rh-form
 * (a CSP do Portal é `script-src 'self'`, que bloqueia os dois). Cada bloco se protege quando os elementos não existem.
 */

// Integração: mostra os campos de data/responsável só quando o status é "Realizada"
(() => {
  const select = document.querySelector('[data-integracao-status="1"]');
  const campos = document.querySelector('[data-integracao-campos="1"]');
  if (!select || !campos) return;
  const sync = () => { campos.classList.toggle('hidden', select.value !== 'realizada'); };
  select.addEventListener('change', sync);
  sync();
})();

// Campos somente leitura com o link gerado: um clique seleciona o texto (antes onclick="this.select()")
(() => {
  document.querySelectorAll('[data-select-on-click="1"]').forEach((el) => {
    el.addEventListener('click', () => el.select());
  });
})();
