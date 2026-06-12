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

  const initAdminSidebar = () => {
    const shell = document.querySelector('[data-admin-shell="1"]') || document.body;
    const btn = document.querySelector('.menu-toggle') || document.querySelector('[data-admin-menu-toggle="1"]');
    const collapseBtn = document.querySelector('[data-admin-sidebar-collapse-toggle="1"]');
    const sidebar = document.querySelector('[data-admin-sidebar="1"]') || document.querySelector('aside');
    const overlay = document.querySelector('[data-admin-overlay="1"]');
    const closeTriggers = Array.from(document.querySelectorAll('[data-admin-menu-close="1"]'));
    const groups = Array.from(document.querySelectorAll('[data-sidebar-group="1"]'));
    if (!btn || !sidebar || !overlay) return;

    const MOBILE_BREAKPOINT = 768;
    const AUTO_COLLAPSE_BREAKPOINT = 1200;
    const STORAGE_KEY = 'rhmadeplant.admin.sidebar.collapsed';

    const isMobileViewport = () => window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;
    const shouldAutoCollapse = () => window.innerWidth < AUTO_COLLAPSE_BREAKPOINT;
    const readStoredCollapsed = () => {
      try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        if (value === '1') return true;
        if (value === '0') return false;
      } catch {
        return null;
      }
      return null;
    };
    const persistCollapsed = (collapsed) => {
      try {
        window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
      } catch {
        // Ignora falha de storage sem afetar a navegacao.
      }
    };

    const setCollapseButtonState = (collapsed) => {
      if (!collapseBtn) return;
      collapseBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      collapseBtn.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
      collapseBtn.setAttribute('title', collapsed ? 'Expandir menu' : 'Recolher menu');
      collapseBtn.classList.toggle('is-collapsed', collapsed);
    };

    const closeDesktopGroups = () => {
      if (isMobileViewport()) return;
      groups.forEach((group) => {
        if (!group.matches(':focus-within')) {
          group.removeAttribute('open');
        }
      });
    };

    const syncMobileState = (isOpen) => {
      sidebar.classList.toggle('active', isOpen);
      overlay.classList.toggle('open', isOpen);
      document.body.classList.toggle('app-sidebar-open', isOpen);
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      sidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      shell.classList.add('app-sidebar-mobile');
      shell.classList.remove('app-sidebar-desktop');
    };

    const syncDesktopState = (collapsed, { persist = false } = {}) => {
      shell.classList.add('app-sidebar-desktop');
      shell.classList.remove('app-sidebar-mobile');
      shell.classList.toggle('app-sidebar-collapsed', collapsed);
      sidebar.classList.remove('active');
      overlay.classList.remove('open');
      document.body.classList.remove('app-sidebar-open');
      sidebar.setAttribute('aria-hidden', 'false');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      sidebar.setAttribute('data-collapsed', collapsed ? '1' : '0');
      setCollapseButtonState(collapsed);
      if (collapsed) {
        closeDesktopGroups();
      }
      if (persist) {
        persistCollapsed(collapsed);
      }
    };

    const syncResponsiveState = ({ persist = false } = {}) => {
      if (isMobileViewport()) {
        syncMobileState(false);
        return;
      }

      const stored = readStoredCollapsed();
      const collapsed = shouldAutoCollapse() ? true : (stored === null ? false : stored);
      syncDesktopState(collapsed, { persist });
    };

    btn.addEventListener('click', () => {
      if (isMobileViewport()) {
        const isOpen = btn.getAttribute('aria-expanded') === 'true';
        syncMobileState(!isOpen);
        return;
      }

      const nextCollapsed = !shell.classList.contains('app-sidebar-collapsed');
      syncDesktopState(nextCollapsed, { persist: true });
    });

    collapseBtn?.addEventListener('click', () => {
      if (isMobileViewport()) {
        return;
      }
      const nextCollapsed = !shell.classList.contains('app-sidebar-collapsed');
      syncDesktopState(nextCollapsed, { persist: true });
    });

    overlay.addEventListener('click', () => syncMobileState(false));
    closeTriggers.forEach((trigger) => {
      trigger.addEventListener('click', () => {
        if (isMobileViewport()) {
          syncMobileState(false);
        }
      });
    });

    groups.forEach((group) => {
      const summary = group.querySelector('summary');
      if (!summary) return;

      summary.addEventListener('click', () => {
        if (isMobileViewport()) {
          return;
        }
        window.requestAnimationFrame(() => {
          groups.forEach((item) => {
            if (item !== group) {
              item.removeAttribute('open');
            }
          });
        });
      });
    });

    document.addEventListener('click', (event) => {
      if (isMobileViewport()) {
        return;
      }
      if (!sidebar.contains(event.target)) {
        closeDesktopGroups();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (isMobileViewport()) {
          syncMobileState(false);
        } else {
          closeDesktopGroups();
        }
      }
    });

    window.addEventListener('resize', () => {
      syncResponsiveState();
    });

    syncResponsiveState();
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

    const board = document.querySelector('.overflow-x-auto');
    if (board) {
      board.classList.add('snap-x');
      columns.forEach((col) => col.classList.add('snap-start'));
    }
  };

  const initInputMasks = () => {
    document.querySelectorAll('[data-mask-date="1"]').forEach((input) => {
      input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '').slice(0, 8);
        const parts = [];
        if (digits.length > 0) parts.push(digits.slice(0, 2));
        if (digits.length > 2) parts.push(digits.slice(2, 4));
        if (digits.length > 4) parts.push(digits.slice(4, 8));
        input.value = parts.join('/');
      });
    });

    document.querySelectorAll('[data-mask-money="1"]').forEach((input) => {
      input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '');
        if (!digits) {
          input.value = '';
          return;
        }
        const cents = parseInt(digits, 10) / 100;
        input.value = new Intl.NumberFormat('pt-BR', {
          style: 'currency',
          currency: 'BRL',
          minimumFractionDigits: 2,
        }).format(Number.isFinite(cents) ? cents : 0);
      });
    });
  };

  const initSolicitacaoVagaForm = () => {
    const root = document.querySelector('[data-solicitacao-vaga-form="1"]');
    const payloadNode = document.querySelector('[data-solicitacao-payload="1"]');
    if (!root) return;

    const setorSelect = root.querySelector('[data-solicitacao-setor="1"]');
    const cargoSelect = root.querySelector('[data-solicitacao-cargo="1"]');
    const gestorSelect = root.querySelector('[data-solicitacao-gestor="1"]');
    const centroSelect = root.querySelector('[data-solicitacao-centro-custo="1"]');
    const faixaLabel = root.querySelector('[data-solicitacao-faixa-label="1"]');
    const maquinaWrap = root.querySelector('[data-solicitacao-maquina-wrap="1"]');
    const maquinaInput = root.querySelector('[data-solicitacao-maquina-input="1"]');
    const substituicaoWrap = root.querySelector('[data-solicitacao-substituicao-wrap="1"]');
    const justificativaWrap = root.querySelector('[data-solicitacao-justificativa-wrap="1"]');
    const justificativaInput = root.querySelector('[data-solicitacao-justificativa="1"]');
    const motivoOutrosWrap = root.querySelector('[data-solicitacao-motivo-outros-wrap="1"]');
    const motivoOutrosInput = root.querySelector('[data-solicitacao-motivo-outros="1"]');
    const beneficioWrap = root.querySelector('[data-solicitacao-beneficios-wrap="1"]');
    if (!payloadNode || !setorSelect || !cargoSelect || !gestorSelect || !centroSelect) {
      return;
    }

    let payload = null;
    try {
      payload = JSON.parse(payloadNode.textContent || '{}');
    } catch {
      payload = null;
    }
    if (!payload) return;

    const cargos = Array.isArray(payload.cargos) ? payload.cargos : [];
    const gestores = Array.isArray(payload.gestores) ? payload.gestores : [];
    const centros = Array.isArray(payload.centros_custo) ? payload.centros_custo : [];
    const beneficiosByCargo = payload.beneficios_by_cargo || {};

    const renderOptions = (select, items, selectedValue, labelBuilder) => {
      const previous = String(selectedValue || '');
      const fragment = document.createDocumentFragment();
      const first = document.createElement('option');
      first.value = '';
      first.textContent = 'Selecione';
      fragment.appendChild(first);

      items.forEach((item) => {
        const option = document.createElement('option');
        option.value = String(item.id ?? item.colaborador_id ?? '');
        option.textContent = labelBuilder(item);
        if (String(option.value) === previous) {
          option.selected = true;
        }
        fragment.appendChild(option);
      });

      select.innerHTML = '';
      select.appendChild(fragment);
      if (previous && !Array.from(select.options).some((option) => option.value === previous)) {
        select.value = '';
      }
    };

    const toggleBooleanSection = (wrap, input, enabled) => {
      if (!wrap || !input) return;
      wrap.classList.toggle('hidden', !enabled);
      input.disabled = !enabled;
      if (!enabled) {
        input.value = '';
      }
    };

    const toggleFieldset = (wrap, enabled) => {
      if (!wrap) return;
      wrap.classList.toggle('hidden', !enabled);
      wrap.querySelectorAll('input, select, textarea').forEach((field) => {
        if (!field.hasAttribute('data-preserve-disabled')) {
          field.disabled = !enabled;
        }
        if (!enabled && (field.type === 'radio' || field.type === 'checkbox')) {
          field.checked = false;
        }
        if (!enabled && field.tagName !== 'SELECT' && field.type !== 'radio' && field.type !== 'checkbox') {
          field.value = '';
        }
      });
    };

    const updateBenefits = () => {
      if (!beneficioWrap) return;
      const cargoId = cargoSelect.value;
      const allowed = new Set(((beneficiosByCargo[cargoId] || []).map((item) => String(item.id))));
      beneficioWrap.querySelectorAll('[data-beneficio-item="1"]').forEach((item) => {
        const checkbox = item.querySelector('[data-beneficio-id]');
        if (!checkbox) return;
        const enabled = allowed.size === 0 || allowed.has(String(checkbox.value));
        item.classList.toggle('hidden', !enabled);
        checkbox.disabled = !enabled;
        if (!enabled) checkbox.checked = false;
      });
    };

    const updateMachineRequirement = () => {
      const selectedCargo = cargos.find((item) => String(item.id) === String(cargoSelect.value));
      const requiresMachine = !!selectedCargo?.requires_machine_description;
      toggleBooleanSection(maquinaWrap, maquinaInput, requiresMachine);
      if (faixaLabel) {
        if (selectedCargo && Number(selectedCargo.salario_max || 0) > 0) {
          faixaLabel.textContent = `Faixa salarial de referência: ${new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL',
          }).format(Number(selectedCargo.salario_min || 0))} a ${new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL',
          }).format(Number(selectedCargo.salario_max || 0))}`;
        } else {
          faixaLabel.textContent = '';
        }
      }
      updateBenefits();
    };

    const updateDependentSelects = () => {
      const setorId = String(setorSelect.value || '');
      renderOptions(
        cargoSelect,
        cargos.filter((item) => Array.isArray(item.setor_ids) && item.setor_ids.map(String).includes(setorId)),
        cargoSelect.value,
        (item) => item.nome
      );
      renderOptions(
        gestorSelect,
        gestores.filter((item) => String(item.setor_id) === setorId),
        gestorSelect.value,
        (item) => `${item.nome} - ${item.cargo_nome}`
      );
      renderOptions(
        centroSelect,
        centros.filter((item) => String(item.setor_id) === setorId),
        centroSelect.value,
        (item) => `${item.codigo} - ${item.nome}`
      );
      updateMachineRequirement();
    };

    const updateTipoVaga = () => {
      const selected = root.querySelector('[data-solicitacao-tipo-vaga="1"]:checked')?.value || '';
      toggleFieldset(substituicaoWrap, selected === 'substituicao');
    };

    const updateOrcamento = () => {
      const selected = root.querySelector('[data-solicitacao-orcamento="1"]:checked')?.value || '';
      toggleBooleanSection(justificativaWrap, justificativaInput, selected === '0');
    };

    const updateMotivoSaida = () => {
      const selected = root.querySelector('[data-solicitacao-motivo-saida="1"]:checked')?.value || '';
      toggleBooleanSection(motivoOutrosWrap, motivoOutrosInput, selected === 'outros');
    };

    setorSelect.addEventListener('change', updateDependentSelects);
    cargoSelect.addEventListener('change', updateMachineRequirement);
    root.querySelectorAll('[data-solicitacao-tipo-vaga="1"]').forEach((radio) => radio.addEventListener('change', updateTipoVaga));
    root.querySelectorAll('[data-solicitacao-orcamento="1"]').forEach((radio) => radio.addEventListener('change', updateOrcamento));
    root.querySelectorAll('[data-solicitacao-motivo-saida="1"]').forEach((radio) => radio.addEventListener('change', updateMotivoSaida));

    updateDependentSelects();
    updateTipoVaga();
    updateOrcamento();
    updateMotivoSaida();
  };

  const initMovimentacaoPessoalForm = () => {
    const root = document.querySelector('[data-movimentacao-pessoal-form="1"]');
    const payloadNode = document.querySelector('[data-movimentacao-payload="1"]');
    if (!root || !payloadNode) return;

    let payload = null;
    try {
      payload = JSON.parse(payloadNode.textContent || '{}');
    } catch {
      payload = null;
    }
    if (!payload) return;

    const gestores = Array.isArray(payload.gestores) ? payload.gestores : [];
    const setores = Array.isArray(payload.setores) ? payload.setores : [];
    const cargos = Array.isArray(payload.cargos) ? payload.cargos : [];
    const colaboradores = Array.isArray(payload.colaboradores) ? payload.colaboradores : [];

    const tipoInputs = root.querySelectorAll('[data-movimentacao-tipo="1"]');
    const posicaoInputs = root.querySelectorAll('[data-movimentacao-posicao="1"]');
    const riscoInputs = root.querySelectorAll('[data-movimentacao-risco="1"]');
    const gestorSelect = root.querySelector('[data-movimentacao-gestor="1"]');
    const setorSelect = root.querySelector('[data-movimentacao-setor="1"]');
    const colaboradorSelect = root.querySelector('[data-movimentacao-colaborador="1"]');
    const matriculaInput = root.querySelector('[data-movimentacao-matricula="1"]');
    const cargoAtualLabel = root.querySelector('[data-movimentacao-cargo-atual-label="1"]');
    const cargoAtualId = root.querySelector('[data-movimentacao-cargo-atual-id="1"]');
    const tempoCargo = root.querySelector('[data-movimentacao-tempo-cargo="1"]');
    const tempoEmpresa = root.querySelector('[data-movimentacao-tempo-empresa="1"]');
    const salarioAtualHidden = root.querySelector('[data-movimentacao-salario-atual="1"]');
    const salarioAtualLabel = root.querySelector('[data-movimentacao-salario-atual-label="1"]');
    const avaliacaoSelect = root.querySelector('[data-movimentacao-avaliacao="1"]');
    const novoSalarioInput = root.querySelector('[data-movimentacao-novo-salario="1"]');
    const percentualField = root.querySelector('[data-movimentacao-percentual="1"]');
    const aumentoMensalField = root.querySelector('[data-movimentacao-aumento-mensal="1"]');
    const impactoAnualField = root.querySelector('[data-movimentacao-impacto-anual="1"]');
    const novoCargoWrap = root.querySelector('[data-movimentacao-novo-cargo-wrap="1"]');
    const novaAreaWrap = root.querySelector('[data-movimentacao-nova-area-wrap="1"]');
    const substituidaWrap = root.querySelector('[data-movimentacao-substituida-wrap="1"]');
    const impactoWrap = root.querySelector('[data-movimentacao-impacto-wrap="1"]');

    const moneyFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    const renderSelectOptions = (select, items, selectedValue, placeholder, labelBuilder) => {
      if (!select) return;
      const previous = String(selectedValue || '');
      const frag = document.createDocumentFragment();
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = placeholder;
      frag.appendChild(empty);
      items.forEach((item) => {
        const option = document.createElement('option');
        option.value = String(item.id);
        option.textContent = labelBuilder(item);
        if (option.value === previous) option.selected = true;
        frag.appendChild(option);
      });
      select.innerHTML = '';
      select.appendChild(frag);
      if (previous && !Array.from(select.options).some((option) => option.value === previous)) {
        select.value = '';
      }
    };

    const parseMoney = (value) => {
      const normalized = String(value || '').replace(/[^\d,.-]/g, '');
      if (!normalized) return 0;
      const valueWithDot = normalized.includes(',') ? normalized.replace(/\./g, '').replace(',', '.') : normalized;
      const parsed = Number.parseFloat(valueWithDot);
      return Number.isFinite(parsed) ? parsed : 0;
    };

    const toggleWrap = (wrap, enabled) => {
      if (!wrap) return;
      wrap.classList.toggle('hidden', !enabled);
      wrap.querySelectorAll('input, select, textarea').forEach((field) => {
        if (field.closest('form') !== root.querySelector('form')) return;
        if (field.readOnly) return;
        if (!enabled) {
          if (field.type === 'radio' || field.type === 'checkbox') field.checked = false;
          else if (field.tagName === 'SELECT') field.value = '';
          else field.value = '';
        }
      });
    };

    const updateCalculatedValues = () => {
      const currentSalary = parseMoney(salarioAtualHidden?.value || '0');
      const newSalary = parseMoney(novoSalarioInput?.value || '0');
      if (!currentSalary || !newSalary) {
        if (percentualField) percentualField.value = '';
        if (aumentoMensalField) aumentoMensalField.value = '';
        if (impactoAnualField) impactoAnualField.value = '';
        return;
      }
      const increase = newSalary - currentSalary;
      const annual = increase * 12;
      const percent = currentSalary > 0 ? (increase / currentSalary) * 100 : 0;
      if (percentualField) percentualField.value = `${percent.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
      if (aumentoMensalField) aumentoMensalField.value = moneyFormatter.format(increase);
      if (impactoAnualField) impactoAnualField.value = moneyFormatter.format(annual);
    };

    const updateCollaborator = () => {
      const selected = colaboradores.find((item) => String(item.id) === String(colaboradorSelect?.value || ''));
      if (!selected) {
        if (matriculaInput) matriculaInput.value = '';
        if (cargoAtualLabel) cargoAtualLabel.value = '';
        if (cargoAtualId) cargoAtualId.value = '';
        if (tempoCargo) tempoCargo.value = '';
        if (tempoEmpresa) tempoEmpresa.value = '';
        if (salarioAtualHidden) salarioAtualHidden.value = '';
        if (salarioAtualLabel) salarioAtualLabel.value = '';
        renderSelectOptions(avaliacaoSelect, [], '', 'Selecione', (item) => item.titulo);
        updateCalculatedValues();
        return;
      }

      if (setorSelect && !setorSelect.value && selected.setor_id) {
        setorSelect.value = String(selected.setor_id);
      }
      if (matriculaInput) matriculaInput.value = selected.matricula || '';
      if (cargoAtualLabel) cargoAtualLabel.value = selected.cargo_nome || '';
      if (cargoAtualId) cargoAtualId.value = String(selected.cargo_id || '');
      if (tempoCargo) tempoCargo.value = selected.tempo_cargo_label || '';
      if (tempoEmpresa) tempoEmpresa.value = selected.tempo_empresa_label || '';
      if (salarioAtualHidden) salarioAtualHidden.value = String(selected.salario_atual || '');
      if (salarioAtualLabel) salarioAtualLabel.value = selected.salario_atual ? moneyFormatter.format(Number(selected.salario_atual)) : '';
      renderSelectOptions(
        avaliacaoSelect,
        Array.isArray(selected.avaliacoes) ? selected.avaliacoes : [],
        avaliacaoSelect?.value || selected.avaliacoes?.[0]?.id || '',
        'Selecione',
        (item) => `${item.titulo}${item.nota !== null && item.nota !== undefined ? ` - nota ${Number(item.nota).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 2 })}` : ''}`
      );
      updateCalculatedValues();
    };

    const updateTipoMovimentacao = () => {
      const selected = root.querySelector('[data-movimentacao-tipo="1"]:checked')?.value || '';
      if (novoCargoWrap) {
        novoCargoWrap.classList.toggle('hidden', !['promocao', 'alteracao_funcao'].includes(selected));
      }
      if (novaAreaWrap) {
        novaAreaWrap.classList.toggle('hidden', selected !== 'transferencia');
      }
    };

    const updatePosicao = () => {
      const selected = root.querySelector('[data-movimentacao-posicao="1"]:checked')?.value || '';
      if (substituidaWrap) substituidaWrap.classList.toggle('hidden', selected !== 'substituida');
    };

    const updateRisco = () => {
      const selected = root.querySelector('[data-movimentacao-risco="1"]:checked')?.value || '';
      if (impactoWrap) impactoWrap.classList.toggle('hidden', selected !== '1');
    };

    const syncSetorByGestor = () => {
      const selected = gestores.find((item) => String(item.usuario_id) === String(gestorSelect?.value || ''));
      if (selected && setorSelect && !setorSelect.value && selected.setor_id) {
        setorSelect.value = String(selected.setor_id);
      }
    };

    tipoInputs.forEach((input) => input.addEventListener('change', updateTipoMovimentacao));
    posicaoInputs.forEach((input) => input.addEventListener('change', updatePosicao));
    riscoInputs.forEach((input) => input.addEventListener('change', updateRisco));
    gestorSelect?.addEventListener('change', syncSetorByGestor);
    colaboradorSelect?.addEventListener('change', updateCollaborator);
    novoSalarioInput?.addEventListener('input', updateCalculatedValues);

    updateTipoMovimentacao();
    updatePosicao();
    updateRisco();
    syncSetorByGestor();
    updateCollaborator();
    updateCalculatedValues();
  };

  document.addEventListener('DOMContentLoaded', () => {
    initAutoSubmit();
    initConfirmations();
    initAdminSidebar();
    initInputMasks();
    initSolicitacaoVagaForm();
    initMovimentacaoPessoalForm();
    initAiAnalyze();
    initKanban();
  });
})();

