/*
 * Carta Proposta (admin) — "Copiar mensagem" (botão ao lado do texto já preparado/registrado).
 * A CSP do Portal é `script-src 'self'`, então fica num arquivo próprio em vez de onclick= inline.
 */
(() => {
  document.querySelectorAll('[data-copiar-destino]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const alvo = document.getElementById(btn.getAttribute('data-copiar-destino'));
      if (!alvo) return;
      const texto = alvo.value || alvo.textContent || '';
      const rotuloOriginal = btn.textContent;
      const avisar = (ok) => {
        btn.textContent = ok ? 'Copiado!' : 'Não foi possível copiar';
        setTimeout(() => { btn.textContent = rotuloOriginal; }, 2000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(() => avisar(true), () => avisar(false));
        return;
      }
      // Fallback sem Clipboard API (contexto não seguro/navegador antigo).
      try {
        alvo.select();
        document.execCommand('copy');
        avisar(true);
      } catch (e) {
        avisar(false);
      }
    });
  });
})();
