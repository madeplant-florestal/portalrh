const { test, expect } = require('@playwright/test');

// Nova UI (AppShell V2): reescrito na publicação consolidada. A tela pós-login em /admin passou a ser a Central do
// Portal RH (cards, sem sidebar/menu de módulos); o antigo Dashboard colapsável virou /admin/dashboard (People
// Analytics). Este spec prova responsividade e ausência de sidebar/menu antigos; a navegação real (Central → módulo)
// é testada em admin-sidebar-collapse.spec.js's lugar, que foi removido por testar só a sidebar (funcionalidade que não
// existe mais).
const entryPath = process.env.ENTRY_PATH || '/';

const viewports = [
  { label: '320', width: 320, height: 800 },
  { label: '375', width: 375, height: 812 },
  { label: '414', width: 414, height: 896 },
  { label: '768', width: 768, height: 1024 },
  { label: '1024', width: 1024, height: 900 },
];

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

async function hasPageOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

test.describe('admin responsivo (AppShell V2)', () => {
  for (const viewport of viewports) {
    test(`Central e navegação em ${viewport.label}px`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      const appBase = await getAppBase(page);

      await login(page, appBase);
      await page.goto(`${appBase}/admin`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`${appBase}/admin$`));
      await expect(page.getByRole('heading', { name: 'Central do Portal RH' })).toBeVisible();

      // Sem sidebar/menu antigos em nenhum viewport: a Central nunca teve esses elementos.
      await expect(page.locator('[data-admin-sidebar="1"]')).toHaveCount(0);
      await expect(page.locator('[data-admin-menu-toggle="1"]')).toHaveCount(0);
      await expect(page.locator('[data-admin-overlay="1"]')).toHaveCount(0);
      await expect(await hasPageOverflow(page)).toBeFalsy();

      // Navegação real pela Central: o card "Recrutamento e Seleção" leva a um destino de verdade.
      const cardRecrutamento = page.getByRole('link', { name: /Recrutamento e Seleção/ });
      await expect(cardRecrutamento).toBeVisible();
      await cardRecrutamento.click();
      await page.waitForLoadState('domcontentloaded');
      await expect(await hasPageOverflow(page)).toBeFalsy();

      // People Analytics (antigo Dashboard, agora /admin/dashboard): mesma prova de responsividade.
      await page.goto(`${appBase}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'People Analytics' })).toBeVisible();
      await expect(await hasPageOverflow(page)).toBeFalsy();

      // Pipeline Kanban: colunas visíveis e sem overflow do documento em qualquer largura.
      await page.goto(`${appBase}/admin/pipeline`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Kanban de Recrutamento e Seleção' })).toBeVisible();
      await expect(page.locator('[data-kanban-board-column="1"]').first()).toBeVisible();
      await expect(await hasPageOverflow(page)).toBeFalsy();
    });
  }
});
