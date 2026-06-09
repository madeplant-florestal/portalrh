const { test, expect } = require('@playwright/test');

const entryPath = process.env.ENTRY_PATH || '/';

async function getAppBase(page) {
  await page.goto(entryPath, { waitUntil: 'domcontentloaded' });
  const appBase = await page.locator('meta[name="app-base"]').getAttribute('content');
  return (appBase || '').replace(/\/$/, '');
}

async function loginAdmin(page, appBase) {
  await page.goto(`${appBase}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name=email]').fill(process.env.ADMIN_EMAIL || 'fabio.ozuna@madeplant.com.br');
  await page.locator('input[name=password]').fill(process.env.ADMIN_PASSWORD || '23082524');
  await page.locator('button[type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
  await expect(page).toHaveURL(new RegExp(`${appBase}/admin$`));
}

test('admin em vagas públicas permanece no fluxo público ao clicar em Vagas', async ({ page }) => {
  const appBase = await getAppBase(page);
  await loginAdmin(page, appBase);

  await page.goto(`${appBase}/vagas`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: 'Vagas', exact: true }).click();

  await expect(page).toHaveURL(new RegExp(`${appBase}/vagas$`));
  await expect(page.getByRole('heading', { name: 'Vagas Disponíveis' })).toBeVisible();
});

test('acesso à raiz redireciona para /login', async ({ page }) => {
  const appBase = await getAppBase(page);
  await page.context().clearCookies();

  await page.goto(`${appBase}/`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`${appBase}/login$`));
  await expect(page.getByText('Acesso ao Painel')).toBeVisible();
});

test('usuário não autenticado é bloqueado da área admin e redirecionado para /login', async ({ page }) => {
  const appBase = await getAppBase(page);
  await page.context().clearCookies();

  await page.goto(`${appBase}/admin/vagas`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`${appBase}/login$`));
  await expect(page.getByText('Acesso ao Painel')).toBeVisible();
});

test('rotas públicas de vagas permanecem acessíveis sem autenticação', async ({ page }) => {
  const appBase = await getAppBase(page);
  await page.context().clearCookies();

  await page.goto(`${appBase}/vagas`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`${appBase}/vagas$`));
  await expect(page.getByRole('heading', { name: 'Vagas Disponíveis' })).toBeVisible();
});
