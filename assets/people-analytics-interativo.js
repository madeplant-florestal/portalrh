/**
 * People Analytics — interatividade da Etapa 2 (click-to-filter, filtros acumulativos, listagem
 * contextual), correção de 2026-09. Vanilla JS, sem framework/bundler — mesma stack do projeto.
 *
 * Nunca recalcula Turnover/Headcount/Admissões/Desligamentos aqui (§9 da correção): só lê os
 * atributos data-pa-* já renderizados pelo backend (chart-helpers.php::dashboard_clique_attrs()),
 * monta a query string e busca o HTML pronto em GET /admin/dashboard/dados
 * (AdminController::dadosDashboard(), que reaproveita o MESMO partial
 * admin/partials/dashboard/resultado.php usado no carregamento normal da página — nunca uma
 * segunda implementação da tela).
 *
 * Estado: vive inteiramente nos atributos data-pa-* do próprio #pa-resultado, renderizados pelo
 * servidor a cada resposta — nunca um objeto JS paralelo que possa dessincronizar do que a tela
 * mostra (§8 da correção: fonte única de verdade). Sem query string, sem localStorage (§7).
 */
(function () {
  'use strict';

  function getContainer() {
    return document.getElementById('pa-resultado');
  }

  function readState(container) {
    var d = container.dataset;
    return {
      periodo: d.paPeriodo || '12m',
      mes: d.paMes || '',
      ano: d.paAno || '',
      data_inicio: d.paDataInicio || '',
      data_fim: d.paDataFim || '',
      comparativo: d.paComparativo || 'ano_anterior',
      empresa: d.paEmpresa || '',
      setor: d.paSetor || '',
      sexo: d.paSexo || '',
      motivo: d.paMotivo || '',
      contexto_lista: d.paContextoLista || 'ativos',
      mes_evento: d.paMesEvento || '',
      pagina: d.paPagina || '1',
      por_pagina: d.paPorPagina || '20',
    };
  }

  function buildQuery(state) {
    var params = new URLSearchParams();
    Object.keys(state).forEach(function (key) {
      var value = state[key];
      if (value !== '' && value !== null && typeof value !== 'undefined') {
        params.set(key, value);
      }
    });
    return params.toString();
  }

  // Mantém os <select> do formulário de filtros do topo (Empresa/Setor) sincronizados com o
  // valor EFETIVO depois de um clique — a mesma dimensão é compartilhada de propósito (§21 da
  // correção): clique substitui temporariamente o valor do dropdown, nunca os dois coexistem.
  function sincronizarFormularioTopo(state) {
    var empresaSel = document.querySelector('select[name="empresa"]');
    var setorSel = document.querySelector('select[name="setor"]');
    if (empresaSel && empresaSel.value !== state.empresa) empresaSel.value = state.empresa;
    if (setorSel && setorSel.value !== state.setor) setorSel.value = state.setor;
  }

  function mostrarErro(mensagem) {
    var container = getContainer();
    if (!container || !container.parentNode) return;
    var el = document.getElementById('pa-erro-interativo');
    if (!el) {
      el = document.createElement('div');
      el.id = 'pa-erro-interativo';
      el.className = 'mb-3 rounded-ds-md border border-danger/30 bg-danger/10 px-3 py-2 text-[12px] font-medium text-danger';
      container.parentNode.insertBefore(el, container);
    }
    el.textContent = mensagem;
    el.style.display = '';
  }

  function limparErro() {
    var el = document.getElementById('pa-erro-interativo');
    if (el) el.style.display = 'none';
  }

  // Uma única chamada por interação (§42): o backend devolve o bloco de resultado inteiro já
  // renderizado (mesmo partial da carga normal da página), o front só troca o innerHTML.
  function atualizar(patch) {
    var container = getContainer();
    if (!container) return;
    var state = readState(container);
    Object.keys(patch).forEach(function (chave) {
      state[chave] = patch[chave];
    });

    container.classList.add('pa-atualizando');

    fetch('/admin/dashboard/dados?' + buildQuery(state), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (resposta) {
        if (!resposta.ok) {
          throw new Error('http-' + resposta.status);
        }
        return resposta.json();
      })
      .then(function (dados) {
        if (!dados || dados.ok !== true || typeof dados.html !== 'string') {
          throw new Error(dados && dados.mensagem ? dados.mensagem : 'Resposta inválida');
        }
        var atual = getContainer();
        if (!atual || !atual.parentNode) return;
        var temp = document.createElement('div');
        temp.innerHTML = dados.html;
        var novoContainer = temp.firstElementChild;
        if (!novoContainer) return;
        atual.parentNode.replaceChild(novoContainer, atual);
        limparErro();
        sincronizarFormularioTopo(readState(novoContainer));
      })
      .catch(function () {
        // Preserva o estado visual anterior (§41) — nunca zera KPIs nem limpa a página.
        container.classList.remove('pa-atualizando');
        mostrarErro('Não foi possível atualizar o painel agora. Os dados exibidos podem estar desatualizados — tente novamente.');
      });
  }

  function alternarFiltro(dimensao, valor, mesEvento) {
    var container = getContainer();
    if (!container) return;
    var state = readState(container);

    // contexto_lista (Admissões/Desligamentos por mês) tem semântica própria: clicar de novo na
    // MESMA barra volta para o modo padrão "ativos" — nunca fica sem um contexto válido.
    if (dimensao === 'contexto_lista') {
      var mesAlvo = mesEvento || '';
      var jaAtivo = state.contexto_lista === valor && state.mes_evento === mesAlvo;
      atualizar(jaAtivo
        ? { contexto_lista: 'ativos', mes_evento: '', pagina: '1' }
        : { contexto_lista: valor, mes_evento: mesAlvo, pagina: '1' });
      return;
    }

    var jaSelecionado = state[dimensao] === valor;
    var patch = { pagina: '1' };
    patch[dimensao] = jaSelecionado ? '' : valor;
    atualizar(patch);
  }

  document.addEventListener('click', function (evento) {
    var elementoClique = evento.target.closest('[data-pa-dimensao]');
    if (elementoClique) {
      evento.preventDefault();
      alternarFiltro(
        elementoClique.getAttribute('data-pa-dimensao'),
        elementoClique.getAttribute('data-pa-valor'),
        elementoClique.getAttribute('data-pa-mes-evento')
      );
      return;
    }

    var chipRemover = evento.target.closest('[data-pa-remover-chip]');
    if (chipRemover) {
      evento.preventDefault();
      var dimensaoChip = chipRemover.getAttribute('data-pa-remover-chip');
      if (dimensaoChip === 'contexto_lista' || dimensaoChip === 'mes_evento') {
        atualizar({ contexto_lista: 'ativos', mes_evento: '', pagina: '1' });
      } else {
        var patchChip = { pagina: '1' };
        patchChip[dimensaoChip] = '';
        atualizar(patchChip);
      }
      return;
    }

    if (evento.target.closest('[data-pa-limpar-filtros]')) {
      evento.preventDefault();
      atualizar({ empresa: '', setor: '', sexo: '', motivo: '', contexto_lista: 'ativos', mes_evento: '', pagina: '1' });
      return;
    }

    var linkPagina = evento.target.closest('[data-pa-pagina]');
    if (linkPagina && !linkPagina.classList.contains('pointer-events-none')) {
      evento.preventDefault();
      atualizar({ pagina: linkPagina.getAttribute('data-pa-pagina') });
    }
  });

  // Ativação por teclado (Enter/Espaço) para os elementos de gráfico clicáveis — role="button" +
  // tabindex="0" já dão foco (§24); navegador não ativa esses elementos sozinho com Enter/Espaço
  // por não serem <button>/<a> nativos.
  document.addEventListener('keydown', function (evento) {
    if (evento.key !== 'Enter' && evento.key !== ' ') return;
    var elementoClique = evento.target.closest('[data-pa-dimensao]');
    if (!elementoClique) return;
    evento.preventDefault();
    alternarFiltro(
      elementoClique.getAttribute('data-pa-dimensao'),
      elementoClique.getAttribute('data-pa-valor'),
      elementoClique.getAttribute('data-pa-mes-evento')
    );
  });

  document.addEventListener('change', function (evento) {
    if (evento.target && evento.target.name === 'por_pagina' && evento.target.closest('[data-pa-form-por-pagina]')) {
      evento.preventDefault();
      atualizar({ por_pagina: evento.target.value, pagina: '1' });
    }
  });
})();
