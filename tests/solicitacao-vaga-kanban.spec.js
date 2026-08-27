const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const entryPath = process.env.ENTRY_PATH || '/';
const fixtureScript = path.join(__dirname, 'php', 'fixture_solicitacao_vaga_kanban.php');

async function getAppBase(page) {
  await page.goto(entryPath, { waitUntil: 'domcontentloaded' });
  const appBase = await page.locator('meta[name="app-base"]').getAttribute('content');
  return (appBase || '').replace(/\/$/, '');
}

async function login(page, appBase) {
  await page.goto(`${appBase}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name=email]').fill(process.env.ADMIN_EMAIL || 'fabio.ozuna@madeplant.com.br');
  await page.locator('input[name=password]').fill(process.env.ADMIN_PASSWORD || '23082524');
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 }),
    page.locator('button[type=submit]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

function runFixture(mode, ...args) {
  const output = execFileSync('php', [fixtureScript, mode, ...args.map(String)], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  });
  return JSON.parse(output);
}

async function dragCardToColumn(page, card, column) {
  const cardHandle = await card.elementHandle();
  const columnHandle = await column.elementHandle();

  if (!cardHandle || !columnHandle) {
    throw new Error('Não foi possível localizar os elementos do drag-and-drop.');
  }

  await page.evaluate(([cardEl, columnEl]) => {
    const dataTransfer = new DataTransfer();
    cardEl.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer }));
    columnEl.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }));
    columnEl.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
    cardEl.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer }));
  }, [cardHandle, columnHandle]);
}

test.describe('kanban de solicitacoes de vaga', () => {
  test('exibe as 6 colunas lado a lado com rolagem horizontal (nao empilha verticalmente)', async ({ page }) => {
    const appBase = await getAppBase(page);
    await login(page, appBase);

    await page.goto(`${appBase}/admin/solicitacoes-vaga/kanban`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Kanban de Solicitações de Vaga' })).toBeVisible();

    const columns = page.locator('[data-sv-kanban-board-column="1"]');
    await expect(columns).toHaveCount(6);

    const boxes = [];
    for (let i = 0; i < 6; i += 1) {
      boxes.push(await columns.nth(i).boundingBox());
    }
    boxes.forEach((box) => expect(box).not.toBeNull());

    // Mesma linha (y aproximadamente igual) e x crescente = layout em grade horizontal, não pilha vertical.
    const firstY = boxes[0].y;
    boxes.forEach((box) => expect(Math.abs(box.y - firstY)).toBeLessThan(5));
    for (let i = 1; i < boxes.length; i += 1) {
      expect(boxes[i].x).toBeGreaterThan(boxes[i - 1].x);
    }

    const board = page.locator('.sv-kanban-board');
    const overflow = await board.evaluate((el) => el.scrollWidth > el.clientWidth + 1);
    expect(overflow).toBeTruthy();
  });

  test('move um cartao entre colunas via drag-and-drop e persiste apos reload', async ({ page }) => {
    const fixture = runFixture('create');
    try {
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/solicitacoes-vaga/kanban`, { waitUntil: 'domcontentloaded' });
      const card = page.locator(`[data-sol-id="${fixture.solicitacao_id}"]`);
      await expect(card).toBeVisible();

      const targetColumn = page.locator('[data-sv-kanban-column="1"][data-stage-slug="em-recrutamento"]');
      const moveResponse = page.waitForResponse((response) =>
        response.url().includes('/api/solicitacoes-vaga/move') && response.request().method() === 'POST'
      );
      await dragCardToColumn(page, card, targetColumn);
      expect((await moveResponse).ok()).toBeTruthy();

      await page.reload({ waitUntil: 'domcontentloaded' });
      const reloadedTarget = page.locator('[data-sv-kanban-column="1"][data-stage-slug="em-recrutamento"]');
      await expect(reloadedTarget.locator(`[data-sol-id="${fixture.solicitacao_id}"]`)).toBeVisible();
    } finally {
      runFixture('cleanup', fixture.solicitacao_id);
    }
  });

  test('cancelamento sem motivo e rejeitado; com motivo persiste apos reload', async ({ page }) => {
    const fixture = runFixture('create');
    try {
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/solicitacoes-vaga/kanban`, { waitUntil: 'domcontentloaded' });
      const card = page.locator(`[data-sol-id="${fixture.solicitacao_id}"]`);
      await expect(card).toBeVisible();

      const canceladaColumn = page.locator('[data-sv-kanban-column="1"][data-stage-slug="cancelada"]');

      let requestFired = false;
      const onRequest = (req) => {
        if (req.url().includes('/api/solicitacoes-vaga/move')) requestFired = true;
      };
      page.on('request', onRequest);

      await dragCardToColumn(page, card, canceladaColumn);
      const modal = page.locator('[data-sv-stage-modal="1"]');
      await expect(modal).toBeVisible();

      await modal.locator('[data-sv-stage-modal-confirm="1"]').click();
      await expect(modal.locator('[data-sv-stage-modal-error="1"]')).toBeVisible();
      expect(requestFired).toBeFalsy();
      page.off('request', onRequest);

      await modal.locator('[data-sv-stage-modal-field="motivo_cancelamento"]').fill('Vaga não é mais necessária (teste E2E)');
      const moveResponse = page.waitForResponse((response) => response.url().includes('/api/solicitacoes-vaga/move'));
      await modal.locator('[data-sv-stage-modal-confirm="1"]').click();
      expect((await moveResponse).ok()).toBeTruthy();
      await expect(modal).toBeHidden();

      await page.reload({ waitUntil: 'domcontentloaded' });
      const reloadedTarget = page.locator('[data-sv-kanban-column="1"][data-stage-slug="cancelada"]');
      await expect(reloadedTarget.locator(`[data-sol-id="${fixture.solicitacao_id}"]`)).toBeVisible();
    } finally {
      runFixture('cleanup', fixture.solicitacao_id);
    }
  });

  test('fechamento move o cartao apos confirmacao', async ({ page }) => {
    const fixture = runFixture('create');
    try {
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/solicitacoes-vaga/kanban`, { waitUntil: 'domcontentloaded' });
      const card = page.locator(`[data-sol-id="${fixture.solicitacao_id}"]`);
      await expect(card).toBeVisible();

      page.once('dialog', (dialog) => dialog.accept());

      const fechadaColumn = page.locator('[data-sv-kanban-column="1"][data-stage-slug="fechada"]');
      const moveResponse = page.waitForResponse((response) => response.url().includes('/api/solicitacoes-vaga/move'));
      await dragCardToColumn(page, card, fechadaColumn);
      expect((await moveResponse).ok()).toBeTruthy();

      await page.reload({ waitUntil: 'domcontentloaded' });
      const reloadedTarget = page.locator('[data-sv-kanban-column="1"][data-stage-slug="fechada"]');
      await expect(reloadedTarget.locator(`[data-sol-id="${fixture.solicitacao_id}"]`)).toBeVisible();
    } finally {
      runFixture('cleanup', fixture.solicitacao_id);
    }
  });
});
