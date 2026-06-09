const { test, expect } = require('@playwright/test');

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

test.describe('admin responsivo', () => {
  for (const viewport of viewports) {
    test(`menu e layout em ${viewport.label}px`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      const appBase = await getAppBase(page);

      await login(page, appBase);
      await page.goto(`${appBase}/admin`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`${appBase}/admin$`));
      await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

      if (viewport.width <= 768) {
        const toggle = page.locator('[data-admin-menu-toggle="1"]');
        const sidebar = page.locator('[data-admin-sidebar="1"]');
        const overlay = page.locator('[data-admin-overlay="1"]');

        await expect(toggle).toBeVisible();
        await toggle.click();
        await expect(sidebar).toHaveClass(/active/);
        await expect(overlay).toHaveClass(/open/);

        await page.getByRole('link', { name: 'Vagas', exact: true }).first().click();
        await expect(page).toHaveURL(new RegExp(`${appBase}/admin/vagas$`));
        await expect(sidebar).not.toHaveClass(/active/);

        await toggle.click();
        await expect(sidebar).toHaveClass(/active/);
        await page.evaluate(() => {
          const overlayElement = document.querySelector('[data-admin-overlay="1"]');
          overlayElement?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
        await expect(sidebar).not.toHaveClass(/active/);
        await expect(page.locator('table')).toBeHidden();
        await expect(page.getByText('Nova vaga')).toBeVisible();
      } else {
        await expect(page.locator('[data-admin-sidebar="1"]')).toBeVisible();
      }

      await expect(await hasPageOverflow(page)).toBeFalsy();

      await page.goto(`${appBase}/admin/pipeline`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Pipeline de Seleção' })).toBeVisible();
      await expect(page.locator('[data-kanban-board-column="1"]').first()).toBeVisible();
      await expect(await hasPageOverflow(page)).toBeFalsy();
    });
  }
});
