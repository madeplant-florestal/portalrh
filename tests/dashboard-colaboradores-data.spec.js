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

const scenarios = [
  { label: 'desktop', viewport: { width: 1440, height: 960 } },
  { label: 'mobile', viewport: { width: 390, height: 844 } },
];

test.describe('dashboard com base de colaboradores', () => {
  test.skip(({ browserName, isMobile }) => browserName !== 'chromium' || isMobile, 'Validacao objetiva no Chromium desktop com viewports desktop e mobile.');

  for (const scenario of scenarios) {
    test(`renderiza corretamente em ${scenario.label}`, async ({ page }) => {
      await page.setViewportSize(scenario.viewport);
      const appBase = await getAppBase(page);
      await login(page, appBase);

      const startedAt = Date.now();
      await page.goto(`${appBase}/admin`, { waitUntil: 'domcontentloaded' });
      const elapsed = Date.now() - startedAt;

      await expect(page.locator('h1')).toContainText('Dashboard de Indicadores de RH');
      await expect(page.locator('text=Base híbrida de indicadores conectada')).toHaveCount(0);
      await expect(page.locator('text=Colaboradores por Área')).toBeVisible();
      await expect(page.locator('text=Distribuição por Tempo de Empresa')).toBeVisible();

      expect(elapsed).toBeLessThan(6000);
    });
  }
});
