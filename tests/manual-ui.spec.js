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

test('manual admin exige autenticação', async ({ page }) => {
  const appBase = await getAppBase(page);
  await page.context().clearCookies();
  await page.goto(`${appBase}/admin/manual`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`${appBase}/login$`));
});

test('manual autenticado exibe conteúdo e mantém layout', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name.includes('iphone'), 'Fluxo autenticado mobile depende de layout lateral global fora do escopo desta página.');
  const appBase = await getAppBase(page);
  await login(page, appBase);
  await page.goto(`${appBase}/admin/manual`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`${appBase}/admin/manual$`));

  await expect(page.locator('h1').first()).toContainText('Manual de Uso');
  await expect(page.getByRole('heading', { name: 'Sobre o sistema' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Sobre a empresa' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Versão e atualização' })).toBeVisible();
  await expect(page.getByRole('link', { name: /99325-6260/i }).first()).toBeVisible();
  if (!testInfo.project.name.includes('iphone')) {
    const hasHorizontalOverflow = await page.evaluate(() => {
      const container = document.querySelector('.max-w-6xl');
      if (!container) return true;
      return container.scrollWidth > container.clientWidth + 1;
    });
    expect(hasHorizontalOverflow).toBeFalsy();
  }
});
