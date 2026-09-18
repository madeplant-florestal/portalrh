// Pesquisa de Integração via QR Code — scripts leves, sem dependência externa.
//  - [data-cpf-mask]    : máscara visual 000.000.000-00 (o backend normaliza e valida de novo).
//  - [data-qr-render]   : desenha o QR Code (SVG) da URL em data-qr-url, localmente, com qrcode.js
//                         (MIT, public/assets/qrcode.js). Nenhuma URL é enviada a terceiros.
//  - [data-copy-target] : copia o texto do elemento indicado.
//  - [data-print]       : window.print() (o CSS @media print da tela isola a área do QR).
(function () {
  'use strict';

  function maskCpf(value) {
    var d = String(value || '').replace(/\D/g, '').slice(0, 11);
    if (d.length > 9) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9);
    if (d.length > 6) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
    if (d.length > 3) return d.slice(0, 3) + '.' + d.slice(3);
    return d;
  }

  function init() {
    document.querySelectorAll('[data-cpf-mask]').forEach(function (input) {
      input.addEventListener('input', function () {
        input.value = maskCpf(input.value);
      });
    });

    document.querySelectorAll('[data-qr-render]').forEach(function (el) {
      var url = el.getAttribute('data-qr-url') || '';
      if (typeof qrcode !== 'function' || !url) {
        el.textContent = 'Não foi possível gerar o QR Code neste navegador.';
        return;
      }
      var qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      el.innerHTML = qr.createSvgTag({
        scalable: true,
        margin: 8,
        cellSize: 4,
        alt: 'QR Code para responder à Pesquisa de Integração',
        title: 'QR Code da Pesquisa de Integração'
      });
    });

    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = document.querySelector(btn.getAttribute('data-copy-target'));
        var text = target ? (target.value || target.textContent || '').trim() : '';
        var feedback = document.querySelector('[data-copy-feedback]');
        var done = function (ok) {
          if (feedback) feedback.textContent = ok ? 'Link copiado.' : 'Não foi possível copiar — selecione e copie manualmente.';
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else if (target && target.select) {
          target.select();
          try { done(document.execCommand('copy')); } catch (e) { done(false); }
        } else {
          done(false);
        }
      });
    });

    document.querySelectorAll('[data-print]').forEach(function (btn) {
      btn.addEventListener('click', function () { window.print(); });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
