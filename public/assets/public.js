(() => {
  const getMeta = (name) => {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') || '' : '';
  };

  const base = getMeta('app-base').replace(/\/$/, '');

  const buildUrl = (path) => {
    if (!path.startsWith('/')) return base ? `${base}/${path}` : `/${path}`;
    return base ? `${base}${path}` : path;
  };

  const fallbackGenerateTrackedUrl = (currentHref, source, medium = 'social', campaign = 'vagas') => {
    try {
      const parsed = new URL(currentHref);
      parsed.searchParams.set('utm_source', source);
      parsed.searchParams.set('utm_medium', medium);
      parsed.searchParams.set('utm_campaign', campaign);
      return parsed.toString();
    } catch {
      return '';
    }
  };

  const fallbackBuildShareLinks = (currentHref, title) => {
    const safeTitle = String(title || 'Vagas disponíveis');
    const facebookUrl = fallbackGenerateTrackedUrl(currentHref, 'facebook');
    const linkedinUrl = fallbackGenerateTrackedUrl(currentHref, 'linkedin');
    const twitterUrl = fallbackGenerateTrackedUrl(currentHref, 'twitter');
    const whatsappUrl = fallbackGenerateTrackedUrl(currentHref, 'whatsapp');
    const emailUrl = fallbackGenerateTrackedUrl(currentHref, 'email', 'email');
    return {
      facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(facebookUrl)}`,
      linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(linkedinUrl)}`,
      twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(safeTitle)}&url=${encodeURIComponent(twitterUrl)}`,
      whatsapp: `https://wa.me/?text=${encodeURIComponent(`${safeTitle} ${whatsappUrl}`)}`,
      email: `mailto:?subject=${encodeURIComponent(safeTitle)}&body=${encodeURIComponent(`Confira esta página: ${emailUrl}`)}`,
      direct: fallbackGenerateTrackedUrl(currentHref, 'link', 'copy'),
      native: fallbackGenerateTrackedUrl(currentHref, 'native', 'share_api'),
    };
  };

  const initShareMenu = () => {
    const trigger = document.querySelector('[data-share-trigger="1"]');
    const panel = document.querySelector('[data-share-panel="1"]');
    if (!trigger || !panel) return;

    const feedback = panel.querySelector('[data-share-feedback="1"]');
    const copyBtn = panel.querySelector('[data-share-copy="1"]');
    const nativeBtn = panel.querySelector('[data-share-native="1"]');
    const links = panel.querySelectorAll('[data-share-link]');
    const titleEl = document.querySelector('h2');
    const pageTitle = titleEl ? titleEl.textContent || document.title : document.title;

    const shareUtils = window.ShareUtils || {
      generateTrackedUrl: fallbackGenerateTrackedUrl,
      buildShareLinks: fallbackBuildShareLinks,
    };

    const linkMap = shareUtils.buildShareLinks(window.location.href, pageTitle);

    links.forEach((linkEl) => {
      const platform = linkEl.getAttribute('data-share-link') || '';
      if (linkMap[platform]) {
        linkEl.setAttribute('href', linkMap[platform]);
      }
    });

    const setFeedback = (message, isError = false) => {
      if (!feedback) return;
      feedback.textContent = message;
      feedback.classList.toggle('text-red-600', isError);
      feedback.classList.toggle('text-gray-600', !isError);
    };

    const openPanel = () => {
      panel.classList.remove('hidden');
      trigger.setAttribute('aria-expanded', 'true');
      panel.style.left = '';
      panel.style.right = '0px';
      const rect = panel.getBoundingClientRect();
      const viewportPadding = 8;
      if (rect.right > (window.innerWidth - viewportPadding)) {
        const overflowRight = rect.right - window.innerWidth + viewportPadding;
        panel.style.right = `${overflowRight}px`;
      }
      if (rect.left < viewportPadding) {
        panel.style.left = `${viewportPadding}px`;
        panel.style.right = 'auto';
      }
      setFeedback('');
    };

    const closePanel = () => {
      panel.classList.add('hidden');
      trigger.setAttribute('aria-expanded', 'false');
    };

    const copyFallback = (text) => {
      const input = document.createElement('input');
      input.value = text;
      document.body.appendChild(input);
      input.select();
      let ok = false;
      try {
        ok = document.execCommand('copy');
      } catch {
        ok = false;
      }
      document.body.removeChild(input);
      return ok;
    };

    trigger.addEventListener('click', () => {
      const isOpen = trigger.getAttribute('aria-expanded') === 'true';
      if (isOpen) {
        closePanel();
      } else {
        openPanel();
      }
    });

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        closePanel();
      }
    });

    document.addEventListener('click', (ev) => {
      if (!panel.contains(ev.target) && !trigger.contains(ev.target)) {
        closePanel();
      }
    });

    if (copyBtn) {
      copyBtn.addEventListener('click', async () => {
        const target = linkMap.direct || shareUtils.generateTrackedUrl(window.location.href, 'link', 'copy');
        if (!target) {
          setFeedback('Não foi possível gerar o link de compartilhamento.', true);
          return;
        }
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(target);
          } else if (!copyFallback(target)) {
            throw new Error('copy_failed');
          }
          window.__lastCopiedShareUrl = target;
          setFeedback('Link copiado com sucesso.');
        } catch {
          setFeedback('Falha ao copiar o link neste navegador.', true);
        }
      });
    }

    if (nativeBtn) {
      nativeBtn.addEventListener('click', async () => {
        const target = linkMap.native || shareUtils.generateTrackedUrl(window.location.href, 'native', 'share_api');
        if (!target) {
          setFeedback('Não foi possível gerar o link de compartilhamento.', true);
          return;
        }
        try {
          if (!navigator.share) {
            setFeedback('Compartilhamento nativo indisponível neste navegador.', true);
            return;
          }
          await navigator.share({
            title: pageTitle,
            text: pageTitle,
            url: target,
          });
          setFeedback('Compartilhamento concluído.');
          closePanel();
        } catch {
          setFeedback('Não foi possível concluir o compartilhamento nativo.', true);
        }
      });
    }
  };

  const initCpfValidation = () => {
    const cpfInput = document.querySelector('[data-cpf-input="1"]');
    if (!cpfInput) return;

    const cpfError = document.querySelector('[data-cpf-error="exists"]');
    const cpfInvalid = document.querySelector('[data-cpf-error="invalid"]');

    let checkTimeout = null;

    const isValidCPF = (cpf) => {
      const digits = String(cpf || '').replace(/\D/g, '');
      if (digits.length !== 11) return false;
      if (/^(\d)\1{10}$/.test(digits)) return false;

      let sum = 0;
      for (let i = 0, w = 10; i < 9; i += 1, w -= 1) {
        sum += parseInt(digits[i], 10) * w;
      }
      let rest = sum % 11;
      const d1 = rest < 2 ? 0 : 11 - rest;
      if (parseInt(digits[9], 10) !== d1) return false;

      sum = 0;
      for (let i = 0, w = 11; i < 10; i += 1, w -= 1) {
        sum += parseInt(digits[i], 10) * w;
      }
      rest = sum % 11;
      const d2 = rest < 2 ? 0 : 11 - rest;
      return parseInt(digits[10], 10) === d2;
    };

    const setHidden = (el, hidden) => {
      if (!el) return;
      if (hidden) {
        el.classList.add('hidden');
      } else {
        el.classList.remove('hidden');
      }
    };

    const checkCpfExists = async (cpfDigits) => {
      const res = await fetch(buildUrl('/api/check-cpf'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ cpf: cpfDigits }),
      });

      if (!res.ok) {
        throw new Error('invalid');
      }
      return res.json();
    };

    cpfInput.addEventListener('input', () => {
      let value = String(cpfInput.value || '').replace(/\D/g, '');
      if (value.length <= 11) {
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
      }
      cpfInput.value = value;

      if (checkTimeout) {
        window.clearTimeout(checkTimeout);
        checkTimeout = null;
      }

      const digits = value.replace(/\D/g, '');
      if (digits.length === 11) {
        if (!isValidCPF(digits)) {
          setHidden(cpfInvalid, false);
          setHidden(cpfError, true);
          cpfInput.setCustomValidity('CPF inválido');
          return;
        }

        setHidden(cpfInvalid, true);
        cpfInput.setCustomValidity('');

        checkTimeout = window.setTimeout(async () => {
          try {
            const data = await checkCpfExists(digits);
            if (data && data.exists) {
              setHidden(cpfError, false);
              cpfInput.setCustomValidity('CPF já cadastrado');
            } else {
              setHidden(cpfError, true);
              cpfInput.setCustomValidity('');
            }
          } catch {
            setHidden(cpfInvalid, false);
            setHidden(cpfError, true);
            cpfInput.setCustomValidity('CPF inválido');
          }
        }, 500);

        return;
      }

      setHidden(cpfInvalid, true);
      setHidden(cpfError, true);
      cpfInput.setCustomValidity('');
    });
  };

  document.addEventListener('DOMContentLoaded', () => {
    initShareMenu();
    initCpfValidation();
  });
})();

