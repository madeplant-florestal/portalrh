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

test.describe('setores com empresa', () => {
  test('carrega select de empresa e valida obrigatoriedade visual', async ({ page }) => {
    const appBase = await getAppBase(page);
    await login(page, appBase);
    await page.goto(`${appBase}/admin/setores/novo`, { waitUntil: 'domcontentloaded' });

    const empresaSelect = page.locator('select[name="empresa_id"]');
    await expect(empresaSelect).toBeVisible();
    await expect(empresaSelect).toHaveAttribute('required', '');

    const optionsCount = await empresaSelect.locator('option').count();
    expect(optionsCount).toBeGreaterThan(1);

    await page.locator('input[name="nome"]').fill('SETOR UI TESTE');
    const validity = await empresaSelect.evaluate((el) => el.checkValidity());
    expect(validity).toBeFalsy();
  });

  test('mantem empresa pre-selecionada na edicao', async ({ page }) => {
    const setorId = process.env.TEST_SETOR_ID;
    const empresaId = process.env.TEST_EMPRESA_ID;
    test.skip(!setorId || !empresaId, 'Dados temporários do teste de edição não foram fornecidos.');

    const appBase = await getAppBase(page);
    await login(page, appBase);
    await page.goto(`${appBase}/admin/setores/editar/${setorId}`, { waitUntil: 'domcontentloaded' });

    const empresaSelect = page.locator('select[name="empresa_id"]');
    await expect(empresaSelect).toHaveValue(String(empresaId));
  });
});
