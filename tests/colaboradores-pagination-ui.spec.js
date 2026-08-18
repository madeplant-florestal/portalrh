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

test.describe('paginacao de colaboradores', () => {
  test.skip(({ browserName, isMobile }) => browserName === 'webkit' || isMobile, 'Cobertura focada em Chromium desktop com viewports desktop e mobile.');

  for (const scenario of scenarios) {
    test(`aplica per_page padrao e navega entre paginas em ${scenario.label}`, async ({ page }) => {
      await page.setViewportSize(scenario.viewport);
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/colaboradores`, { waitUntil: 'domcontentloaded' });

      const perPageSelect = page.locator('select[name="per_page"]');
      const pageBadge = page.locator('span', { hasText: /^Página 1 de/ }).first();
      await expect(perPageSelect).toHaveValue('20');
      await expect(pageBadge).toBeVisible();

      const nextButton = page.locator('a:has-text("Próxima")').first();
      if (await nextButton.isVisible() && !(await nextButton.evaluate((node) => node.className.includes('pointer-events-none')))) {
        await nextButton.click();
        await expect(page).toHaveURL(/page=2/);
        await expect(page).toHaveURL(/per_page=20/);
      }

      await perPageSelect.selectOption('50');
      await expect(page).toHaveURL(/per_page=50/);
      await expect(page).toHaveURL(/page=1/);

      await perPageSelect.selectOption('100');
      await expect(page).toHaveURL(/per_page=100/);
      await expect(page).toHaveURL(/page=1/);
    });
  }
});
