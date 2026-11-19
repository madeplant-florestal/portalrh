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
  var avisoGestor = raiz.querySelector('[data-matriz-aviso-gestor]');
  var avisoLegado = raiz.querySelector('[data-matriz-aviso-legado]');
  var listaLegado = raiz.querySelector('[data-matriz-legado-lista]');
  var feedback = raiz.querySelector('[data-matriz-feedback]');
  var baseline = snapshot();
  var perfilAnterior = seletor ? seletor.value : perfil;

  function idDe(c) { return parseInt(c.getAttribute('data-perm-id'), 10); }
  function conjunto(lista) { var o = {}; (lista || []).forEach(function (i) { o[i] = true; }); return o; }
  function dados(role) { return perfis[role] || { global: false, rotulo: role, aplicaveis: [], lote: [], transicao: [], modelo: [] }; }

  /** Células que o PERFIL ATUAL aceita (política) e que estão habilitadas: alvo de marcar grupo/linha. O legado incompatível nunca entra. */
  function marcaveis(lista) {
    var aplic = conjunto(dados(perfil).aplicaveis);
    return lista.filter(function (c) { return !c.disabled && aplic[idDe(c)]; });
  }
  function celulasDe(el) { return Array.prototype.slice.call(el.querySelectorAll('[data-matriz-celula="1"]')); }
  function avisar(texto) { if (feedback) feedback.textContent = texto; }

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
      var rotuloCel = c.closest('label');
      if (rotuloCel) {
        var motivo = !ok && d.motivos ? d.motivos[id] : '';
        rotuloCel.title = c.getAttribute('data-codigo') + (motivo ? ' — Não concedível ao perfil: ' + motivo : '');
      }
    });
    perfil = role;
    raiz.setAttribute('data-perfil', role);
    if (avisoGlobal) avisoGlobal.classList.toggle('hidden', !d.global);
    if (avisoViewer) avisoViewer.classList.toggle('hidden', role !== 'viewer');
    if (avisoGestor) avisoGestor.classList.toggle('hidden', role !== 'gestor');
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
    var loteSet = conjunto(d.lote);
    raiz.querySelectorAll('[data-matriz-acao="marcar-todas"]').forEach(function (b) {
      b.disabled = marcaveis(celulas).filter(function (c) { return loteSet[idDe(c)]; }).length === 0;
    });
    // Marcar grupo / linha: só habilitados quando o perfil aceita ao menos uma permissão do escopo (senão o clique seria um no-op silencioso).
    raiz.querySelectorAll('[data-matriz-acao="marcar-grupo"]').forEach(function (b) {
      var vazio = marcaveis(celulasDe(b.closest('tbody'))).length === 0;
      b.disabled = vazio;
      b.title = vazio ? 'Nenhuma permissão deste grupo se aplica ao perfil selecionado' : '';
    });
    raiz.querySelectorAll('[data-matriz-acao="alternar-linha"]').forEach(function (b) {
      var alvo = marcaveis(celulasDe(b.closest('tr')));
      var todas = alvo.length > 0 && alvo.every(function (c) { return c.checked; });
      b.disabled = alvo.length === 0;
      b.title = alvo.length === 0 ? 'Nenhuma ação deste módulo se aplica ao perfil selecionado' : '';
      b.textContent = todas ? 'limpar linha' : 'marcar linha';
    });
  }

  /** Marca `alvo` (já filtrado) ou desmarca `escopo`; devolve quantas células mudaram de estado. */
  function aplicar(alvo, marcar) {
    var mudou = 0;
    alvo.forEach(function (c) { if (c.checked !== marcar) { c.checked = marcar; mudou++; } });
    atualizar();
    return mudou;
  }
  function editaveis(lista) { return lista.filter(function (c) { return !c.disabled; }); }

  raiz.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-matriz-acao]') : null;
    if (!b || !raiz.contains(b) || b.disabled) return;
    var acao = b.getAttribute('data-matriz-acao');
    var d = dados(perfil), lote = conjunto(d.lote), n;
    if (acao === 'marcar-todas') {
      // Marcar todas = só o LOTE do perfil (permissões plenas pela política). Para o Leitor, as operacionais de transição ficam fora.
      n = aplicar(marcaveis(celulas).filter(function (c) { return lote[idDe(c)]; }), true);
      avisar(n + (n === 1 ? ' permissão marcada' : ' permissões marcadas') + '. Nada foi salvo.');
    } else if (acao === 'desmarcar-todas') {
      n = aplicar(editaveis(celulas), false);
      avisar(n + (n === 1 ? ' permissão desmarcada' : ' permissões desmarcadas') + '. Nada foi salvo.');
    } else if (acao === 'restaurar-modelo') {
      aplicarPerfil(perfil, true);
      avisar('Modelo recomendado do perfil restaurado (' + (d.modelo || []).length + '). Nada foi salvo.');
    } else if (acao === 'marcar-grupo') {
      n = aplicar(marcaveis(celulasDe(b.closest('tbody'))), true);
      avisar(n + (n === 1 ? ' permissão marcada' : ' permissões marcadas') + ' no grupo. Nada foi salvo.');
    } else if (acao === 'desmarcar-grupo') {
      n = aplicar(editaveis(celulasDe(b.closest('tbody'))), false);
      avisar(n + (n === 1 ? ' permissão desmarcada' : ' permissões desmarcadas') + ' no grupo. Nada foi salvo.');
    } else if (acao === 'alternar-linha') {
      // Alternar linha (comportamento único): se TODAS as ações aplicáveis da linha já estão marcadas, desmarca-as; senão marca TODAS as aplicáveis.
      var alvo = marcaveis(celulasDe(b.closest('tr')));
      var todas = alvo.length > 0 && alvo.every(function (c) { return c.checked; });
      n = aplicar(alvo, !todas);
      avisar(n + (n === 1 ? ' permissão ' : ' permissões ') + (todas ? 'desmarcada' + (n === 1 ? '' : 's') : 'marcada' + (n === 1 ? '' : 's')) + ' na linha. Nada foi salvo.');
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
