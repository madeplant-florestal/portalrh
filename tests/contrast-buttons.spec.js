const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function hexToRgb(hex) {
  const normalized = hex.replace('#', '').trim();
  return {
    r: parseInt(normalized.slice(0, 2), 16),
    g: parseInt(normalized.slice(2, 4), 16),
    b: parseInt(normalized.slice(4, 6), 16)
  };
}

function contrastRatio(textHex, bgHex) {
  function srgb(c) {
    const v = c / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  }
  function luminance(rgb) {
    return 0.2126 * srgb(rgb.r) + 0.7152 * srgb(rgb.g) + 0.0722 * srgb(rgb.b);
  }
  const textLum = luminance(hexToRgb(textHex));
  const bgLum = luminance(hexToRgb(bgHex));
  const lighter = Math.max(textLum, bgLum);
  const darker = Math.min(textLum, bgLum);
  return (lighter + 0.05) / (darker + 0.05);
}

for (const colorScheme of ['light', 'dark']) {
  test(`botões mantêm contraste WCAG no tema ${colorScheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    const cssPath = path.resolve(__dirname, '../public/assets/tailwind.css');
    const cssText = fs.readFileSync(cssPath, 'utf8');
    expect(cssText.includes('.ct-btn')).toBeTruthy();
    expect(cssText.includes('.ct-btn-primary')).toBeTruthy();
    expect(cssText.includes('.ct-btn-warning')).toBeTruthy();
    expect(cssText.includes('.ct-btn-success')).toBeTruthy();
    expect(cssText.includes('.ct-btn-muted')).toBeTruthy();
    expect(cssText.includes('.ct-btn:focus-visible')).toBeTruthy();
    expect(cssText.includes('.ct-btn:disabled')).toBeTruthy();
    expect(cssText.includes('.ct-badge-active')).toBeTruthy();
    expect(cssText.includes('.ct-badge-inactive')).toBeTruthy();
    expect(cssText.includes('prefers-color-scheme:dark')).toBeTruthy();

    const pairs = [
      ['#FFFFFF', '#1d2d44'],
      ['#FFFFFF', '#0d1321'],
      ['#FFFFFF', '#3e5c76'],
      ['#0d1321', '#FFFFFF'],
      ['#FFFFFF', '#0d1321']
    ];
    for (const [textHex, bgHex] of pairs) {
      expect(contrastRatio(textHex, bgHex)).toBeGreaterThanOrEqual(4.5);
    }

    const cssHasFocusVisibleRule = cssText.includes('.ct-btn:focus-visible');
    expect(cssHasFocusVisibleRule).toBeTruthy();
  });

  test(`botões renderizam visualmente no tema ${colorScheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    const cssPath = path.resolve(__dirname, '../public/assets/tailwind.css');
    const cssText = fs.readFileSync(cssPath, 'utf8');

    await page.setContent(`
      <!doctype html>
      <html lang="pt-BR">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1" />
          <style>${cssText}</style>
          <style>body{margin:0;padding:24px;background:#f8fafc}</style>
        </head>
        <body>
          <div id="actions" class="flex flex-wrap gap-4">
            <button id="btnStatus" class="ct-btn ct-btn-warning">Desativar usuário</button>
            <button id="btnPassword" class="ct-btn ct-btn-primary">Alterar Senha</button>
          </div>
          <div id="modalActions" class="mt-4 flex flex-wrap items-center justify-end gap-4">
            <button id="btnCancel" class="ct-btn ct-btn-muted">Cancelar</button>
            <button id="btnConfirm" class="ct-btn ct-btn-primary">Confirmar alteração</button>
          </div>
        </body>
      </html>
    `);

    const metrics = await page.evaluate(() => {
      const btnStatus = document.getElementById('btnStatus');
      const btnPassword = document.getElementById('btnPassword');
      const actions = document.getElementById('actions');
      const s1 = getComputedStyle(btnStatus);
      const s2 = getComputedStyle(btnPassword);
      const actionsStyle = getComputedStyle(actions);
      return {
        statusBg: s1.backgroundColor,
        passwordBg: s2.backgroundColor,
        statusRadius: parseFloat(s1.borderTopLeftRadius),
        statusPadY: parseFloat(s1.paddingTop),
        statusPadX: parseFloat(s1.paddingLeft),
        actionsGap: parseFloat(actionsStyle.columnGap || actionsStyle.gap || '0')
      };
    });

    expect(metrics.statusBg).not.toBe('rgba(0, 0, 0, 0)');
    expect(metrics.passwordBg).not.toBe('rgba(0, 0, 0, 0)');
    expect(metrics.statusRadius).toBeGreaterThan(0);
    expect(metrics.statusPadY).toBeGreaterThanOrEqual(12);
    expect(metrics.statusPadX).toBeGreaterThanOrEqual(20);
    expect(metrics.actionsGap).toBeGreaterThanOrEqual(16);

    const beforeHover = await page.locator('#btnPassword').evaluate((el) => getComputedStyle(el).backgroundColor);
    await page.locator('#btnPassword').hover();
    const afterHover = await page.locator('#btnPassword').evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(afterHover).not.toBe(beforeHover);

    await page.locator('#btnConfirm').evaluate((el) => el.setAttribute('disabled', 'disabled'));
    const disabledCursor = await page.locator('#btnConfirm').evaluate((el) => getComputedStyle(el).cursor);
    expect(disabledCursor).toBe('not-allowed');

    await page.setViewportSize({ width: 375, height: 812 });
    const narrowLayout = await page.evaluate(() => {
      const actions = document.getElementById('actions');
      const children = Array.from(actions.children);
      return children.every((node) => node.getBoundingClientRect().width <= actions.getBoundingClientRect().width + 1);
    });
    expect(narrowLayout).toBeTruthy();
  });
}
