const { test, expect } = require('@playwright/test');

const entryPath = process.env.ENTRY_PATH || '/';

const scenarios = [
  { label: 'compacto-1180', width: 1180, height: 820, expectedCollapsed: true },
  { label: 'tablet-1024', width: 1024, height: 1366, expectedCollapsed: true },
  { label: 'notebook-13', width: 1280, height: 800, expectedCollapsed: false },
  { label: 'notebook-14', width: 1366, height: 768, expectedCollapsed: false },
  { label: 'notebook-15-6', width: 1920, height: 1080, expectedCollapsed: false },
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

async function hasHorizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

async function getColor(locator) {
  return locator.evaluate((el) => getComputedStyle(el).color);
}

test.describe('sidebar administrativo colapsavel', () => {
  for (const scenario of scenarios) {
    test(`comportamento em ${scenario.label}`, async ({ page }) => {
      await page.setViewportSize({ width: scenario.width, height: scenario.height });
      const appBase = await getAppBase(page);

      await login(page, appBase);
      await page.goto(`${appBase}/admin`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`${appBase}/admin$`));

      const shell = page.locator('[data-admin-shell="1"]');
      const sidebar = page.locator('[data-admin-sidebar="1"]');
      const content = page.locator('[data-admin-content="1"]');
      const collapseToggle = page.locator('[data-admin-sidebar-collapse-toggle="1"]');
      const logo = page.locator('.sidebar-brand-mark');
      const dashboardLink = page.getByRole('link', { name: 'Dashboard', exact: true });
      const dashboardIcon = dashboardLink.locator('svg').first();
      const candidaturasLink = page.getByRole('link', { name: 'Candidaturas', exact: true });
      const candidaturasIcon = candidaturasLink.locator('svg').first();
      const collapseIcon = collapseToggle.locator('svg').first();

      await expect(sidebar).toBeVisible();
      await expect(collapseToggle).toBeVisible();
      await expect(logo).toBeVisible();
      await expect(page.locator('.sidebar-brand-copy')).toHaveCount(0);
      await expect(await sidebar.evaluate((el) => getComputedStyle(el).position)).toBe('fixed');
      await expect(await getColor(dashboardIcon)).toBe('rgb(77, 55, 38)');
      await expect(await getColor(collapseIcon)).toBe('rgb(77, 55, 38)');

      await candidaturasLink.hover();
      await expect(await getColor(candidaturasIcon)).toBe('rgb(77, 55, 38)');

      await dashboardLink.focus();
      await expect(await getColor(dashboardIcon)).toBe('rgb(77, 55, 38)');

      const initialCollapsed = await shell.evaluate((el) => el.classList.contains('app-sidebar-collapsed'));
      expect(initialCollapsed).toBe(scenario.expectedCollapsed);

      const initialMargin = await content.evaluate((el) => parseFloat(getComputedStyle(el).marginLeft));
      await collapseToggle.click();
      await page.waitForTimeout(180);

      const toggledCollapsed = await shell.evaluate((el) => el.classList.contains('app-sidebar-collapsed'));
      expect(toggledCollapsed).toBe(!scenario.expectedCollapsed);

      const toggledMargin = await content.evaluate((el) => parseFloat(getComputedStyle(el).marginLeft));
      expect(toggledMargin).not.toBe(initialMargin);

      await page.getByRole('link', { name: 'Vagas', exact: true }).first().click();
      await expect(page).toHaveURL(new RegExp(`${appBase}/admin/vagas$`));
      await expect(page.getByText('Nova vaga')).toBeVisible();

      await page.goto(`${appBase}/admin/colaboradores`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Colaboradores', exact: true })).toBeVisible();
      await expect(await hasHorizontalOverflow(page)).toBeFalsy();
    });
  }
});
