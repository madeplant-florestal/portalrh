/*
 * Indicadores de RH — botão "Atualizar dados" (sincronização sob demanda do METADADOS). Movido, sem mudança de lógica, do <script> inline
 * da view `indicadores-rh` (a CSP do Portal é `script-src 'self'` e o bloqueava). Endpoints e classes de estado chegam por data-attributes
 * do botão: `data-endpoint`, `data-status-endpoint`, `data-classe-base` (estado normal) e `data-classe-ok` (estado "Sincronizado").
 * As cores de feedback usam os tokens do Design System (info / danger / primary-700).
 */
(function () {
  'use strict';
  var btn = document.querySelector('[data-sync-btn]');
  if (!btn || btn.disabled) { return; }

  var label = btn.querySelector('[data-sync-label]');
  var feedback = document.querySelector('[data-sync-feedback]');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var endpoint = btn.getAttribute('data-endpoint');
  var statusEndpoint = btn.getAttribute('data-status-endpoint');
  var classeBase = btn.getAttribute('data-classe-base') || '';
  var classeOk = btn.getAttribute('data-classe-ok') || '';
  var COR_INFO = '#46618C';
  var COR_ERRO = '#B23B3B';
  var COR_OK = '#3B4822';
  var pollTimer = null;
  var deadline = 0;

  function setFeedback(text, cor) {
    if (!feedback) { return; }
    feedback.textContent = text || '';
    feedback.style.color = cor || '';
    feedback.classList.toggle('hidden', !text);
  }

  function reset(text, cor) {
    btn.disabled = false;
    label.textContent = 'Atualizar dados';
    if (text) { setFeedback(text, cor); }
  }

  function poll(correlacaoId) {
    if (Date.now() > deadline) {
      reset('A sincronização ainda está em andamento. Recarregue a página em alguns minutos para ver os dados atualizados.', COR_INFO);
      return;
    }
    fetch(statusEndpoint + '?correlacao_id=' + encodeURIComponent(correlacaoId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        var d = res.data || {};
        if (!res.ok) { reset(d.erro || 'Falha ao consultar o andamento da sincronização.', COR_ERRO); return; }
        if (!d.concluido) { pollTimer = setTimeout(function () { poll(correlacaoId); }, 4000); return; }
        if (d.sucesso) {
          label.textContent = 'Sincronizado';
          if (classeOk) { btn.className = classeOk; }
          setFeedback('Dados atualizados. Recarregando os indicadores…', COR_OK);
          setTimeout(function () { window.location.reload(); }, 1200);
          return;
        }
        reset(d.mensagem ? ('Falha na sincronização: ' + d.mensagem) : 'Falha na sincronização.', COR_ERRO);
      })
      .catch(function () { pollTimer = setTimeout(function () { poll(correlacaoId); }, 4000); });
  }

  btn.addEventListener('click', function () {
    if (btn.disabled) { return; }
    if (classeBase) { btn.className = classeBase; }
    btn.disabled = true;
    label.textContent = 'Atualizando…';
    setFeedback('Solicitação enviada. Isso pode levar alguns minutos.', COR_INFO);
    if (pollTimer) { clearTimeout(pollTimer); }

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ csrf: csrf })
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        var d = res.data || {};
        if (res.status === 202 && d.correlacao_id) {
          deadline = Date.now() + 3 * 60 * 1000;
          pollTimer = setTimeout(function () { poll(d.correlacao_id); }, 4000);
          return;
        }
        reset(d.erro || 'Não foi possível iniciar a sincronização.', COR_ERRO);
      })
      .catch(function () { reset('Erro de rede ao solicitar a sincronização.', COR_ERRO); });
  });
})();
