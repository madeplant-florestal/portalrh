const { test, expect } = require('@playwright/test');

const entryPath = process.env.ENTRY_PATH || '/';

async function getAppBase(page) {
  await page.goto(entryPath, { waitUntil: 'domcontentloaded' });
  const appBase = await page.locator('meta[name="app-base"]').getAttribute('content');
  return (appBase || '').replace(/\/$/, '');
}

async function login(page, appBase) {
  await page.goto(`${appBase}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name=email]').fill(process.env.ADMIN_EMAIL || 'fabio.ozuna@madeplant.com.br');
  await page.locator('input[name=password]').fill(process.env.ADMIN_PASSWORD || '23082524');
  await page.locator('button[type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
}

async function choiceGroupMetrics(page, inputName) {
  return page.evaluate((name) => {
    const inputs = Array.from(document.querySelectorAll(`input[name="${name}"]`));
    const labels = inputs
      .map((input) => input.closest('label'))
      .filter(Boolean);
    const container = labels[0]?.parentElement;
    if (!container || labels.length === 0) {
      return null;
    }

    const rects = labels.map((label) => {
      const rect = label.getBoundingClientRect();
      return {
        top: Math.round(rect.top),
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        bottom: Math.round(rect.bottom),
        height: Math.round(rect.height),
        width: Math.round(rect.width),
      };
    });

    let hasOverlap = false;
    for (let i = 0; i < rects.length; i += 1) {
      for (let j = i + 1; j < rects.length; j += 1) {
        const a = rects[i];
        const b = rects[j];
        const intersects = !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
        if (intersects) {
          hasOverlap = true;
        }
      }
    }

    const computed = window.getComputedStyle(container);
    const uniqueRows = [...new Set(rects.map((rect) => rect.top))];

    return {
      flexDirection: computed.flexDirection,
      rowCount: uniqueRows.length,
      minHeight: Math.min(...rects.map((rect) => rect.height)),
      hasOverlap,
      itemCount: rects.length,
    };
  }, inputName);
}

async function gridMetrics(page, selector) {
  return page.evaluate((target) => {
    const element = document.querySelector(target);
    if (!element) {
      return null;
    }
    const styles = window.getComputedStyle(element);
    return {
      columns: styles.gridTemplateColumns.split(' ').filter(Boolean).length,
    };
  }, selector);
}

async function hasHorizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

const scenarios = [
  {
    label: 'desktop',
    viewport: { width: 1366, height: 900 },
    expectedFlexDirection: 'row',
    expectedColumns: 3,
  },
  {
    label: 'mobile',
    viewport: { width: 390, height: 844 },
    expectedFlexDirection: 'column',
    expectedColumns: 1,
  },
];

const formCases = [
  {
    url: '/admin/solicitacoes-vaga/nova',
    title: 'Nova solicitação de vaga',
    groups: ['tipo_vaga', 'tipo_contratacao', 'previsto_orcamento', 'turno', 'nivel_responsabilidade', 'urgencia'],
    inlineGridSelector: '.form-inline-grid.form-inline-grid-3',
  },
  {
    url: '/admin/movimentacoes-pessoal/nova',
    title: 'Nova movimentação de pessoal',
    groups: ['tipo_movimentacao', 'posicao_atual_sera', 'existe_risco_perda'],
    inlineGridSelector: '.grid.gap-4.md\\:grid-cols-2.xl\\:grid-cols-3',
  },
];

test.describe('layout dos formularios administrativos', () => {
  for (const scenario of scenarios) {
    for (const formCase of formCases) {
      test(`${formCase.title} em ${scenario.label}`, async ({ page }) => {
        await page.setViewportSize(scenario.viewport);
        const appBase = await getAppBase(page);
        await login(page, appBase);
        await page.goto(`${appBase}${formCase.url}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByRole('heading', { name: formCase.title })).toBeVisible();
        await expect(await hasHorizontalOverflow(page)).toBeFalsy();

        for (const groupName of formCase.groups) {
          const metrics = await choiceGroupMetrics(page, groupName);
          expect(metrics, `Grupo ${groupName} nao encontrado`).not.toBeNull();
          expect(metrics.flexDirection).toBe(scenario.expectedFlexDirection);
          expect(metrics.hasOverlap, `Grupo ${groupName} com sobreposicao`).toBeFalsy();
          expect(metrics.minHeight, `Grupo ${groupName} com area clicavel reduzida`).toBeGreaterThanOrEqual(48);
          if (scenario.label === 'desktop') {
            expect(metrics.rowCount, `Grupo ${groupName} deveria permanecer compacto no desktop`).toBeLessThanOrEqual(2);
          } else {
            expect(metrics.rowCount, `Grupo ${groupName} deveria empilhar no mobile`).toBeGreaterThanOrEqual(2);
          }
        }

        const grid = await gridMetrics(page, formCase.inlineGridSelector);
        expect(grid, 'Grid responsivo nao encontrado').not.toBeNull();
        expect(grid.columns).toBe(scenario.expectedColumns);
      });
    }
  }
});
