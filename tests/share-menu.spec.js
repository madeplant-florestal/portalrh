const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const shareUtilsJs = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'share-utils.js'), 'utf8');
const publicJs = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'public.js'), 'utf8');

const html = `
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="app-base" content="">
  <title>Vagas</title>
</head>
<body>
  <header>
    <nav>
      <button type="button" data-share-trigger="1" aria-haspopup="dialog" aria-expanded="false" aria-controls="share-menu-panel">Compartilhar</button>
      <div id="share-menu-panel" data-share-panel="1" class="hidden" role="dialog" aria-modal="false" aria-labelledby="share-menu-title">
        <h3 id="share-menu-title">Compartilhar esta página</h3>
        <a data-share-link="facebook">Facebook</a>
        <a data-share-link="linkedin">LinkedIn</a>
        <a data-share-link="twitter">Twitter</a>
        <a data-share-link="whatsapp">WhatsApp</a>
        <a data-share-link="email">E-mail</a>
        <button type="button" data-share-copy="1">Copiar link</button>
        <button type="button" data-share-native="1">Compartilhar no dispositivo</button>
        <div data-share-feedback="1" role="status" aria-live="polite"></div>
      </div>
    </nav>
  </header>
  <main><h2>Vagas disponíveis</h2></main>
</body>
</html>
`;

const preparePage = async (page) => {
  await page.setContent(html, { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ content: shareUtilsJs });
  await page.addScriptTag({ content: publicJs });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
};

test.describe('menu de compartilhamento', () => {
  test('abre dropdown e popula links com UTM', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
    });
    await preparePage(page);

    const trigger = page.locator('[data-share-trigger="1"]');
    await expect(trigger).toBeVisible();
    await trigger.click();

    const panel = page.locator('[data-share-panel="1"]');
    await expect(panel).toBeVisible();
    const panelBox = await panel.boundingBox();
    expect(panelBox && panelBox.width > 280).toBeTruthy();

    await expect(page.locator('[data-share-link="facebook"]')).toHaveAttribute('href', /utm_source%3Dfacebook/);
    await expect(page.locator('[data-share-link="linkedin"]')).toHaveAttribute('href', /utm_source%3Dlinkedin/);
    await expect(page.locator('[data-share-link="twitter"]')).toHaveAttribute('href', /utm_source%3Dtwitter/);
    await expect(page.locator('[data-share-link="whatsapp"]')).toHaveAttribute('href', /utm_source%3Dwhatsapp/);
    await expect(page.locator('[data-share-link="email"]')).toHaveAttribute('href', /utm_source%3Demail/);

    await page.locator('[data-share-native="1"]').click();
    await expect(page.locator('[data-share-feedback="1"]')).toContainText('indisponível');
  });

  test('copia link com rastreamento no fallback de clipboard', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: {
          writeText: async (text) => {
            window.__copiedText = text;
          },
        },
      });
    });
    await preparePage(page);
    await page.locator('[data-share-trigger="1"]').click();
    await page.locator('[data-share-copy="1"]').click();
    await expect(page.locator('[data-share-feedback="1"]')).toContainText('copiado com sucesso');
    const copiedText = await page.evaluate(() => window.__lastCopiedShareUrl || window.__copiedText || '');
    expect(copiedText).toContain('utm_source=link');
  });

  test('funciona em viewport móvel', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await preparePage(page);
    await page.locator('[data-share-trigger="1"]').click();
    const panel = page.locator('[data-share-panel="1"]');
    await expect(panel).toBeVisible();
    const panelBox = await panel.boundingBox();
    expect(panelBox && panelBox.width <= 382).toBeTruthy();
  });
});
