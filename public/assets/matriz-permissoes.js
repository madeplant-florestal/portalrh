/*
 * Matriz horizontal de permissões (Etapa 3) — cadastro e edição de usuários.
 * NÃO contém regra de autorização nem listas de permissões: tudo vem de `data-perfis` (JSON montado no servidor por
 * PermissaoMatrizService a partir da PermissaoPerfilPolicy). Este arquivo só reflete esse estado nas checkboxes e contadores.
 * O backend revalida a seleção inteira (perfil, id, ativa, compatível, autoridade) — desabilitar uma checkbox aqui nunca é a barreira.
 */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-matriz="1"]');
  if (!raiz) return;

  var perfis;
  try { perfis = JSON.parse(raiz.getAttribute('data-perfis') || '{}'); } catch (e) { perfis = {}; }
  var edicao = raiz.getAttribute('data-edicao') === '1';
  var seletor = document.getElementById(raiz.getAttribute('data-seletor-perfil') || '');
  var perfil = raiz.getAttribute('data-perfil') || '';
  var celulas = Array.prototype.slice.call(raiz.querySelectorAll('[data-matriz-celula="1"]'));
  var contador = raiz.querySelector('[data-matriz-contador]');
  var totalTexto = raiz.querySelector('[data-matriz-total-texto]');
  var avisoGlobal = raiz.querySelector('[data-matriz-aviso-global]');
  var avisoViewer = raiz.querySelector('[data-matriz-aviso-viewer]');
  var avisoLegado = raiz.querySelector('[data-matriz-aviso-legado]');
  var listaLegado = raiz.querySelector('[data-matriz-legado-lista]');
  var baseline = snapshot();
  var perfilAnterior = seletor ? seletor.value : perfil;

  function idDe(c) { return parseInt(c.getAttribute('data-perm-id'), 10); }
  function conjunto(lista) { var o = {}; (lista || []).forEach(function (i) { o[i] = true; }); return o; }
  function dados(role) { return perfis[role] || { global: false, rotulo: role, aplicaveis: [], lote: [], transicao: [], modelo: [] }; }

  function snapshot() {
    return celulas.filter(function (c) { return c.checked && !c.disabled; }).map(idDe).sort(function (a, b) { return a - b; }).join(',');
  }
  function sujo() { return snapshot() !== baseline; }

  function marcador(c, d, aplicavel, ehTransicao, recomendada) {
    var span = c.parentNode.querySelector('[data-matriz-marcador="1"]');
    if (!span) return;
    span.textContent = '';
    if (!aplicavel && c.getAttribute('data-legado') !== '1') { span.textContent = '—'; return; }
    if (c.getAttribute('data-legado') === '1') { span.textContent = '⚠'; span.className = 'w-3 text-[11px] leading-none text-danger'; return; }
    span.className = 'w-3 text-[11px] leading-none';
    if (ehTransicao) { span.textContent = '◐'; span.className += ' text-warning'; return; }
    if (recomendada) { span.textContent = '★'; }
  }

  /** Reaplica habilitação das células para o perfil; `comModelo` troca a seleção pelo modelo (senão mantém o que está marcado). */
  function aplicarPerfil(role, comModelo) {
    var d = dados(role);
    var aplic = conjunto(d.aplicaveis), modelo = conjunto(d.modelo), trans = conjunto(d.transicao);
    celulas.forEach(function (c) {
      var id = idDe(c);
      var ok = !!aplic[id];
      if (comModelo) c.setAttribute('data-legado', '0'); // a troca/restauração é uma regularização explícita: legado deixa de ser marcado
      var legado = c.getAttribute('data-legado') === '1';
      if (comModelo) c.classList.remove('ring-2', 'ring-danger');
      c.disabled = !ok && !legado;
      if (!c.disabled) c.name = 'permissao_ids[]';
      if (comModelo) c.checked = ok && !!modelo[id];
      else if (!ok && !legado) c.checked = false;
      marcador(c, d, ok, !!trans[id], !!modelo[id]);
    });
    perfil = role;
    raiz.setAttribute('data-perfil', role);
    if (avisoGlobal) avisoGlobal.classList.toggle('hidden', !d.global);
    if (avisoViewer) avisoViewer.classList.toggle('hidden', role !== 'viewer');
    atualizar();
  }

  function atualizar() {
    var d = dados(perfil);
    var marcadas = celulas.filter(function (c) { return c.checked && !c.disabled; }).length;
    var habilitadas = celulas.filter(function (c) { return !c.disabled; }).length;
    // Perfil global: o número é de ATRIBUIÇÕES INDIVIDUAIS gravadas/marcadas (linhas em usuario_permissoes), não de acesso — o acesso vem do perfil.
    if (contador) contador.textContent = d.global ? marcadas + (marcadas === 1 ? ' atribuição individual' : ' atribuições individuais') : marcadas + (marcadas === 1 ? ' selecionada' : ' selecionadas');
    if (totalTexto) totalTexto.textContent = d.global ? 'gravada(s) — sem efeito prático: o acesso é global pelo perfil e não depende delas' : 'de ' + habilitadas + ' aplicáveis ao perfil';
    var legados = celulas.filter(function (c) { return c.getAttribute('data-legado') === '1' && c.checked && !c.disabled; });
    if (avisoLegado) {
      avisoLegado.classList.toggle('hidden', legados.length === 0);
      if (listaLegado) listaLegado.textContent = legados.map(function (c) { return c.getAttribute('data-codigo'); }).join(', ');
    }
    raiz.querySelectorAll('[data-matriz-acao="marcar-todas"]').forEach(function (b) { b.disabled = (d.lote || []).length === 0; });
  }

  function marcarLote(escopo, marcar) {
    var lote = conjunto(dados(perfil).lote);
    escopo.forEach(function (c) {
      if (c.disabled) return;
      if (marcar) { if (lote[idDe(c)]) c.checked = true; } else { c.checked = false; }
    });
    atualizar();
  }
  function celulasDe(el) { return Array.prototype.slice.call(el.querySelectorAll('[data-matriz-celula="1"]')); }

  raiz.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-matriz-acao]') : null;
    if (!b || !raiz.contains(b)) return;
    var acao = b.getAttribute('data-matriz-acao');
    if (acao === 'marcar-todas') marcarLote(celulas, true);
    else if (acao === 'desmarcar-todas') marcarLote(celulas, false);
    else if (acao === 'restaurar-modelo') aplicarPerfil(perfil, true);
    else if (acao === 'marcar-grupo') marcarLote(celulasDe(b.closest('tbody')), true);
    else if (acao === 'desmarcar-grupo') marcarLote(celulasDe(b.closest('tbody')), false);
    else if (acao === 'alternar-linha') {
      var linha = celulasDe(b.closest('tr'));
      var lote = conjunto(dados(perfil).lote);
      var alvo = linha.filter(function (c) { return !c.disabled && lote[idDe(c)]; });
      var todas = alvo.length > 0 && alvo.every(function (c) { return c.checked; });
      marcarLote(linha, !todas);
    }
  });
  raiz.addEventListener('change', function (ev) { if (ev.target && ev.target.getAttribute && ev.target.getAttribute('data-matriz-celula') === '1') atualizar(); });

  if (seletor) {
    seletor.addEventListener('change', function () {
      var novo = seletor.value;
      var rotulo = dados(novo).rotulo || novo;
      var msg = null;
      if (sujo()) {
        msg = 'Há alterações de permissões ainda não salvas. Trocar o perfil para ' + rotulo + ' descarta a seleção atual e aplica o modelo recomendado desse perfil. Nada é salvo até você clicar em Salvar. Continuar?';
      } else if (edicao) {
        msg = 'Trocar o perfil para ' + rotulo + ' aplica o modelo recomendado desse perfil à seleção. Nada é salvo até você clicar em Salvar. Continuar?';
      }
      if (msg !== null && !window.confirm(msg)) { seletor.value = perfilAnterior; return; }
      perfilAnterior = novo;
      aplicarPerfil(novo, true);
      baseline = snapshot();
    });
  }

  // Estado inicial: no cadastro o servidor já renderiza o modelo do perfil; na edição, o que está GRAVADO (nunca o modelo).
  atualizar();
})();
