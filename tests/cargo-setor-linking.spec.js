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

test.describe('cargo-setor linking', () => {
  test('vincula e remove setor a partir da tela de cargo', async ({ page }) => {
    const appBase = await getAppBase(page);
    await login(page, appBase);

    await page.goto(`${appBase}/admin/cargos/editar/1`, { waitUntil: 'domcontentloaded' });
    const select = page.locator('select[name="setor_ids[]"]');
    const options = select.locator('option');
    const count = await options.count();
    test.skip(count === 0, 'Nao ha setores disponiveis para vincular ao cargo 1.');

    const firstValue = await options.nth(0).getAttribute('value');
    const firstLabel = (await options.nth(0).textContent())?.trim() || '';
    await select.selectOption(firstValue || undefined);
    await page.getByRole('button', { name: 'Vincular setores' }).click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.getByText('setor(es) vinculado(s) com sucesso.')).toBeVisible();
    await expect(page.locator('text=' + firstLabel).first()).toBeVisible();

    const card = page.locator('div.rounded-xl.border.border-slate-200.p-4').filter({ hasText: firstLabel }).first();
    page.once('dialog', (dialog) => dialog.accept());
    await card.getByRole('button', { name: 'Remover vínculo' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.getByText('Vínculo removido com sucesso.')).toBeVisible();
  });

  test('vincula e remove cargo a partir da tela de setor', async ({ page }) => {
    const appBase = await getAppBase(page);
    await login(page, appBase);

    await page.goto(`${appBase}/admin/setores/editar/1`, { waitUntil: 'domcontentloaded' });
    const select = page.locator('select[name="cargo_ids[]"]');
    const options = select.locator('option');
    const count = await options.count();
    test.skip(count === 0, 'Nao ha cargos disponiveis para vincular ao setor 1.');

    const firstValue = await options.nth(0).getAttribute('value');
    const firstLabel = (await options.nth(0).textContent())?.trim() || '';
    await select.selectOption(firstValue || undefined);
    await page.getByRole('button', { name: 'Vincular cargos' }).click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.getByText('cargo(s) vinculado(s) com sucesso.')).toBeVisible();
    await expect(page.locator('text=' + firstLabel).first()).toBeVisible();

    const card = page.locator('div.rounded-xl.border.border-slate-200.p-4').filter({ hasText: firstLabel }).first();
    page.once('dialog', (dialog) => dialog.accept());
    await card.getByRole('button', { name: 'Remover vínculo' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.getByText('Vínculo removido com sucesso.')).toBeVisible();
  });
});
