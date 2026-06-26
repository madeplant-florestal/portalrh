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

async function chooseImportFile(page, input, file) {
  await input.setInputFiles(file);
  await input.dispatchEvent('change');
}

const scenarios = [
  { label: 'desktop', viewport: { width: 1440, height: 960 } },
  { label: 'mobile', viewport: { width: 390, height: 844 } },
];

test.describe('importacao de colaboradores pela interface', () => {
  test.skip(({ browserName, isMobile }) => browserName === 'webkit' || isMobile, 'Fluxo validado nos projetos desktop com viewports desktop e mobile.');

  for (const scenario of scenarios) {
    test(`executa o fluxo visual de importacao em ${scenario.label}`, async ({ page }) => {
      await page.setViewportSize(scenario.viewport);
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.route('**/admin/colaboradores/importar', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 3000));
        await route.fulfill({
          status: 200,
          contentType: 'application/json; charset=utf-8',
          body: JSON.stringify({
            ok: true,
            message: 'Importacao concluida com sucesso. 2 inseridos, 1 atualizados e 0 rejeitados.',
            summary: {
              processed: 3,
              ignored_blank_rows: 0,
              inserted: 2,
              updated: 1,
              rejected: 0,
              catalog_companies_created: 1,
              catalog_roles_created: 1,
            },
            warnings: [],
            rejected_records: [],
          }),
        });
      });

      await page.goto(`${appBase}/admin/colaboradores`, { waitUntil: 'domcontentloaded' });

      const button = page.locator('[data-colaboradores-import-trigger="1"]');
      const input = page.locator('[data-colaboradores-import-input="1"]');
      const feedback = page.locator('[data-colaboradores-import-feedback="1"]');
      const empresasIcon = page.locator('a[aria-label="Empresas"]').first();
      const setoresIcon = page.locator('a[aria-label="Setores"]').first();
      const cargosIcon = page.locator('a[aria-label="Cargos"]').first();
      const avaliacoesIcon = page.locator('a[aria-label="Avaliações"]').first();
      const uploadResponse = page.waitForResponse((response) =>
        response.url().includes('/admin/colaboradores/importar') && response.request().method() === 'POST'
      );

      await expect(button).toBeVisible();
      await expect(page.locator('text=Cadastros Auxiliares')).toHaveCount(0);
      await expect(page.locator('text=Gestão e Navegação')).toHaveCount(0);
      await expect(page.locator('text=Importar colaboradores')).toHaveCount(0);
      await expect(empresasIcon).toBeVisible();
      await expect(setoresIcon).toBeVisible();
      await expect(cargosIcon).toBeVisible();
      await expect(avaliacoesIcon).toBeVisible();
      await chooseImportFile(page, input, {
        name: 'colaboradores.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from('COD;COLABORADOR;EMPRESA;CPF;ADMISSÃO;NASC.;CARGO;DEMISSÃO;MOTIVO RESCISÃO\n1;Teste;Empresa;529.982.247-25;01/01/2024;01/01/1990;Cargo;;', 'utf8'),
      });

      await expect(button).toHaveAttribute('aria-busy', 'true');
      await uploadResponse;
      await expect(button).toHaveAttribute('aria-busy', 'false');
      await expect(feedback).toBeVisible();
      await expect(feedback).toContainText('Importacao concluida com sucesso');
      await expect(feedback).toContainText('Inseridos: 2');
      await expect(feedback).toContainText('Atualizados: 1');
    });
  }

  test('exibe erro imediato para formato nao suportado', async ({ page }) => {
    const appBase = await getAppBase(page);
    await login(page, appBase);
    await page.goto(`${appBase}/admin/colaboradores`, { waitUntil: 'domcontentloaded' });

    const input = page.locator('[data-colaboradores-import-input="1"]');
    const feedback = page.locator('[data-colaboradores-import-feedback="1"]');

    await chooseImportFile(page, input, {
      name: 'colaboradores.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4', 'utf8'),
    });

    await expect(feedback).toBeVisible();
    await expect(feedback).toContainText('Formato invalido. Utilize um arquivo .xlsx ou .csv.');
  });
});
