const { test, expect } = require('@playwright/test');

const entryPath = process.env.ENTRY_PATH || '/';

async function getAppBase(page) {
  await page.goto(entryPath, { waitUntil: 'domcontentloaded' });
  const appBase = await page.locator('meta[name="app-base"]').getAttribute('content');
  return (appBase || '').replace(/\/$/, '');
}

async function login(page, appBase) {
  await page.goto(`${appBase}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('E-mail').fill(process.env.ADMIN_EMAIL || 'fabio.ozuna@madeplant.com.br');
  await page.getByLabel('Senha').fill(process.env.ADMIN_PASSWORD || '23082524');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await page.waitForLoadState('domcontentloaded');
  await expect(page).toHaveURL(new RegExp(`${appBase}/admin`));
}

test('regressão visual: login e áreas principais', async ({ page }) => {
  const appBase = await getAppBase(page);

  await page.goto(entryPath, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveScreenshot('login-root.png', { fullPage: true });

  await page.goto(`${appBase}/admin/login`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveScreenshot('login-public.png', { fullPage: true });

  await login(page, appBase);
  await expect(page).toHaveScreenshot('admin-dashboard.png', { fullPage: true });

  await page.goto(`${appBase}/admin/vagas`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveScreenshot('admin-vagas-index.png', { fullPage: true });

  await page.goto(`${appBase}/admin/candidaturas`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveScreenshot('admin-candidaturas-index.png', { fullPage: true });

  await page.goto(`${appBase}/admin/pipeline`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveScreenshot('admin-pipeline.png', { fullPage: true });
});

