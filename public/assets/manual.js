/*
 * Manual de Uso — busca local no conteúdo (sem backend, sem indexador). Filtra os itens de documentação (`[data-manual-item]`)
 * pelo texto digitado, comparando com o texto integral de cada item (`data-manual-texto`, normalizado sem acento). Item que
 * bate fica visível e aberto (<details open>); os que não batem ficam escondidos. Campo vazio restaura tudo ao estado original
 * (cada item volta a fechado). Também atualiza o contador de resultados e o estado "nenhum resultado" por seção.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-manual-busca-form]');
  var input = document.querySelector('[data-manual-busca]');
  var contador = document.querySelector('[data-manual-busca-contador]');
  if (!form || !input) { return; }
  var itens = Array.prototype.slice.call(document.querySelectorAll('[data-manual-item]'));
  var secoes = Array.prototype.slice.call(document.querySelectorAll('[data-manual-secao]'));

  function semAcento(texto) {
    return texto
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase();
  }

  function aplicar(termoBruto) {
    var termo = semAcento(termoBruto.trim());
    var vazio = termo === '';
    var encontrados = 0;
    itens.forEach(function (item) {
      // O atributo já vem em minúsculas do PHP, mas ainda com acentos — normaliza os dois lados antes de comparar.
      var texto = semAcento(item.getAttribute('data-manual-texto') || '');
      var bate = vazio || texto.indexOf(termo) !== -1;
      item.hidden = !bate;
      if (!vazio && bate) {
        item.open = true;
        encontrados++;
      } else if (vazio) {
        item.open = false;
      }
    });
    secoes.forEach(function (secao) {
      var algumVisivel = Array.prototype.some.call(secao.querySelectorAll('[data-manual-item]'), function (item) {
        return !item.hidden;
      });
      secao.hidden = !vazio && !algumVisivel;
    });
    if (contador) {
      contador.textContent = vazio ? '' : (encontrados === 0 ? 'Nenhum resultado para "' + termoBruto.trim() + '".' : encontrados + ' resultado(s) para "' + termoBruto.trim() + '".');
    }
  }

  input.addEventListener('input', function () { aplicar(input.value); });
  form.addEventListener('submit', function (ev) { ev.preventDefault(); aplicar(input.value); });

  var limpar = document.querySelector('[data-manual-busca-limpar]');
  if (limpar) {
    limpar.addEventListener('click', function () {
      input.value = '';
      aplicar('');
      input.focus();
    });
  }
})();
