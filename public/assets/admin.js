(() => {
  const getMeta = (name) => {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') || '' : '';
  };

  const base = getMeta('app-base').replace(/\/$/, '');
  const csrf = getMeta('csrf-token');

  const buildUrl = (path) => {
    if (!path.startsWith('/')) return base ? `${base}/${path}` : `/${path}`;
    return base ? `${base}${path}` : path;
  };

  const initAutoSubmit = () => {
    document.querySelectorAll('select[data-autosubmit="1"]').forEach((select) => {
      select.addEventListener('change', () => {
        if (select.form) {
          select.form.submit();
        }
      });
    });
  };

  const initConfirmations = () => {
    document.querySelectorAll('[data-confirm-message]').forEach((el) => {
      const handler = (ev) => {
        const msg = el.getAttribute('data-confirm-message') || 'Confirmar ação?';
        if (!window.confirm(msg)) {
          ev.preventDefault();
          ev.stopPropagation();
          return false;
        }
        return true;
      };

      if (el.tagName === 'FORM') {
        el.addEventListener('submit', handler);
      } else {
        el.addEventListener('click', handler);
      }
    });
  };

  const initAdminDrawer = () => {
    const btn = document.querySelector('.menu-toggle') || document.querySelector('[data-admin-menu-toggle="1"]');
    const sidebar = document.querySelector('[data-admin-sidebar="1"]') || document.querySelector('aside');
    const overlay = document.querySelector('[data-admin-overlay="1"]');
    const closeTriggers = Array.from(document.querySelectorAll('[data-admin-menu-close="1"]'));
    if (!btn || !sidebar || !overlay) return;

    const isMobileViewport = () => window.matchMedia('(max-width: 768px)').matches;

    const syncState = (isOpen) => {
      sidebar.classList.toggle('active', isOpen);
      overlay.classList.toggle('open', isOpen);
      document.body.classList.toggle('app-sidebar-open', isOpen);
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      sidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    };

    const open = () => {
      if (!isMobileViewport()) return;
      syncState(true);
    };

    const close = () => {
      syncState(false);
    };

    btn.addEventListener('click', () => {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        close();
      } else {
        open();
      }
    });
    overlay.addEventListener('click', close);
    closeTriggers.forEach((trigger) => {
      trigger.addEventListener('click', () => {
        if (isMobileViewport()) {
          close();
        }
      });
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        close();
      }
    });
    window.addEventListener('resize', () => {
      if (!isMobileViewport()) {
        close();
      }
    });
    close();
  };

  const initAiAnalyze = () => {
    const btn = document.querySelector('[data-ai-analyze="1"]');
    if (!btn) return;
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      window.alert('Funcionalidade de IA simulada: Candidato tem 85% de compatibilidade com a vaga.');
    });
  };

  const initKanban = () => {
    const columns = Array.from(document.querySelectorAll('[data-kanban-column="1"]'));
    if (columns.length === 0) return;

    const cards = Array.from(document.querySelectorAll('[data-kanban-card="1"]'));

    let draggedCard = null;
    let sourceColumn = null;

    const updateCounts = () => {
      columns.forEach((col) => {
        const counter = col.closest('[data-kanban-board-column="1"]')?.querySelector('[data-kanban-count="1"]');
        if (counter) {
          counter.textContent = String(col.querySelectorAll('[data-kanban-card="1"]').length);
        }
      });
    };

    const sendMove = async (candidaturaId, stageId) => {
      const res = await fetch(buildUrl('/api/pipeline/move'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          csrf,
          candidatura_id: candidaturaId,
          stage_id: stageId,
        }),
      });

      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }

      if (!res.ok || !data || !data.success) {
        throw new Error((data && data.error) || 'Falha ao mover');
      }
    };

    cards.forEach((card) => {
      card.setAttribute('draggable', 'true');

      card.addEventListener('dragstart', (ev) => {
        draggedCard = card;
        sourceColumn = card.closest('[data-kanban-column="1"]');
        card.classList.add('opacity-50');
        const candId = card.getAttribute('data-cand-id') || '';
        ev.dataTransfer?.setData('text/plain', candId);
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'move';
        }
      });

      card.addEventListener('dragend', () => {
        card.classList.remove('opacity-50');
        columns.forEach((c) => c.classList.remove('bg-gray-200'));
        draggedCard = null;
        sourceColumn = null;
      });
    });

    columns.forEach((col) => {
      col.addEventListener('dragover', (ev) => {
        ev.preventDefault();
        col.classList.add('bg-gray-200');
      });

      col.addEventListener('dragleave', (ev) => {
        if (ev.target === col) {
          col.classList.remove('bg-gray-200');
        }
      });

      col.addEventListener('drop', async (ev) => {
        ev.preventDefault();
        col.classList.remove('bg-gray-200');

        const stageId = parseInt(col.getAttribute('data-stage-id') || '0', 10);
        const candId = (ev.dataTransfer?.getData('text/plain') || '').trim() || (draggedCard?.getAttribute('data-cand-id') || '').trim();

        if (!draggedCard || !stageId || !candId) {
          return;
        }

        const prevParent = sourceColumn;
        col.appendChild(draggedCard);
        updateCounts();

        try {
          await sendMove(candId, stageId);
        } catch {
          if (prevParent) {
            prevParent.appendChild(draggedCard);
          }
          updateCounts();
          window.location.reload();
        }
      });
    });

    updateCounts();
  };

  document.addEventListener('DOMContentLoaded', () => {
    initAutoSubmit();
    initConfirmations();
    initAdminDrawer();
    initAiAnalyze();
    initKanban();
  });
})();

