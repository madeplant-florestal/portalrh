const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const entryPath = process.env.ENTRY_PATH || '/';
const fixtureScript = path.join(__dirname, 'php', 'fixture_pipeline_kanban_candidate.php');

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

test.describe('kanban de recrutamento e selecao', () => {
  test('move card entre colunas do fluxo principal sem perder persistencia', async ({ page }) => {
    const fixture = runFixture('create');

    try {
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/candidaturas`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Candidaturas' })).toBeVisible();

      await page.goto(`${appBase}/admin/pipeline?vaga_id=${fixture.vaga_id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Kanban de Recrutamento e Seleção' })).toBeVisible();
      await expect(page.locator('[data-kanban-board-column="1"]')).toHaveCount(9);

      const card = page.locator(`#cand-${fixture.candidatura_id}`);
      await expect(card).toBeVisible();

      const triagemColumn = page.locator('[data-kanban-column="1"]').nth(1);
      const moveToTriagem = page.waitForResponse((response) =>
        response.url().includes('/api/pipeline/move') && response.request().method() === 'POST'
      );
      await dragCardToColumn(page, card, triagemColumn);
      expect((await moveToTriagem).ok()).toBeTruthy();
      await expect(triagemColumn.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();

      await page.reload({ waitUntil: 'domcontentloaded' });
      const triagemReloaded = page.locator('[data-kanban-column="1"]').nth(1);
      await expect(triagemReloaded.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();

      const entrevistaRhColumn = page.locator('[data-kanban-column="1"]').nth(2);
      const entrevistaRhStageId = await entrevistaRhColumn.getAttribute('data-stage-id');
      const moveToEntrevistaRh = page.waitForResponse((response) =>
        response.url().includes('/api/pipeline/move') && response.request().method() === 'POST'
      );
      await dragCardToColumn(page, triagemReloaded.locator(`#cand-${fixture.candidatura_id}`), entrevistaRhColumn);
      expect((await moveToEntrevistaRh).ok()).toBeTruthy();
      await expect(entrevistaRhColumn.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();

      await page.goto(`${appBase}/admin/candidaturas/${fixture.candidatura_id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('select[name="stage_id"]')).toHaveValue(String(entrevistaRhStageId));
      await expect(page.locator('select[name="stage_id"] option:checked')).toHaveText('Entrevista RH');
    } finally {
      runFixture('cleanup', fixture.candidatura_id, fixture.vaga_id);
    }
  });
});
