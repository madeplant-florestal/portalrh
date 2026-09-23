// Vitrine pública de vagas (/vagas) — busca/filtro 100% client-side sobre a lista já renderizada pelo servidor.
// Nenhuma requisição nova, nenhum parâmetro de URL, nenhuma mudança na consulta Vaga::allActive(): isto só
// esconde/mostra os <article data-vaga-card="1"> que já estão no DOM, comparando com o texto digitado e a área
// escolhida. Se o JS falhar ou não carregar, todas as vagas continuam visíveis (o filtro é aditivo, nunca a
// única forma de ver as vagas).
(() => {
  const root = document.querySelector('[data-vagas-filtro="1"]');
  const lista = document.querySelector('[data-vagas-lista="1"]');
  if (!root || !lista) return;

  const textoInput = root.querySelector('[data-vagas-filtro-texto="1"]');
  const areaSelect = root.querySelector('[data-vagas-filtro-area="1"]');
  const cards = Array.from(lista.querySelectorAll('[data-vaga-card="1"]'));
  const semResultado = document.querySelector('[data-vagas-sem-resultado="1"]');

  const aplicarFiltro = () => {
    const termo = (textoInput?.value || '').trim().toLowerCase();
    const area = areaSelect?.value || '';
    let visiveis = 0;

    cards.forEach((card) => {
      const bateTexto = termo === '' || (card.getAttribute('data-vaga-busca') || '').includes(termo);
      const bateArea = area === '' || card.getAttribute('data-vaga-area') === area;
      const mostrar = bateTexto && bateArea;
      card.hidden = !mostrar;
      if (mostrar) visiveis += 1;
    });

    if (semResultado) {
      semResultado.classList.toggle('hidden', visiveis !== 0);
    }
  };

  textoInput?.addEventListener('input', aplicarFiltro);
  areaSelect?.addEventListener('change', aplicarFiltro);
})();
