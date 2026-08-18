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

      // Entrevista RH exige dados adicionais: o drop abre um modal contextual em vez de mover na hora.
      await dragCardToColumn(page, triagemReloaded.locator(`#cand-${fixture.candidatura_id}`), entrevistaRhColumn);
      const modal = page.locator('[data-stage-modal="1"]');
      await expect(modal).toBeVisible();
      // O cartão não deve ter sido movido ainda — continua na coluna de origem enquanto o modal está aberto.
      await expect(triagemReloaded.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();

      const entrevistaGroup = modal.locator('[data-stage-modal-group="entrevista"]');
      await expect(entrevistaGroup).toBeVisible();
      await entrevistaGroup.locator('[data-stage-modal-field="interview_date"]').fill('25/07/2026');
      await entrevistaGroup.locator('[data-stage-modal-field="interview_time"]').fill('14:30');
      await entrevistaGroup.locator('[data-stage-modal-field="interview_location"]').fill('Sala 2 - Matriz');

      const moveToEntrevistaRh = page.waitForResponse((response) =>
        response.url().includes('/api/pipeline/move') && response.request().method() === 'POST'
      );
      await modal.locator('[data-stage-modal-confirm="1"]').click();
      expect((await moveToEntrevistaRh).ok()).toBeTruthy();
      await expect(modal).toBeHidden();
      await expect(entrevistaRhColumn.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();

      await page.goto(`${appBase}/admin/candidaturas/${fixture.candidatura_id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('select[name="stage_id"]')).toHaveValue(String(entrevistaRhStageId));
      await expect(page.locator('select[name="stage_id"] option:checked')).toHaveText('Entrevista RH');
      await expect(page.locator('input[name="interview_location"]')).toHaveValue('Sala 2 - Matriz');
    } finally {
      runFixture('cleanup', fixture.candidatura_id, fixture.vaga_id);
    }
  });

  test('cancelar ou falhar validacao mantem o cartao na coluna original', async ({ page }) => {
    const fixture = runFixture('create');

    try {
      const appBase = await getAppBase(page);
      await login(page, appBase);

      await page.goto(`${appBase}/admin/pipeline?vaga_id=${fixture.vaga_id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('heading', { name: 'Kanban de Recrutamento e Seleção' })).toBeVisible();

      const novaInscricaoColumn = page.locator('[data-kanban-column="1"]').nth(0);
      const entrevistaRhColumn = page.locator('[data-kanban-column="1"]').nth(2);
      const card = page.locator(`#cand-${fixture.candidatura_id}`);
      await expect(card).toBeVisible();

      // Cancelar: nenhuma requisicao deve ser disparada, e o cartao permanece na coluna original.
      let requestFired = false;
      const onRequest = (req) => {
        if (req.url().includes('/api/pipeline/move')) requestFired = true;
      };
      page.on('request', onRequest);

      await dragCardToColumn(page, card, entrevistaRhColumn);
      const modal = page.locator('[data-stage-modal="1"]');
      await expect(modal).toBeVisible();
      await modal.locator('[data-stage-modal-cancel="1"]').click();
      await expect(modal).toBeHidden();
      await expect(novaInscricaoColumn.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();
      expect(requestFired).toBeFalsy();
      page.off('request', onRequest);

      // Confirmar sem preencher os campos obrigatorios: backend rejeita, cartao continua na origem.
      await dragCardToColumn(page, card, entrevistaRhColumn);
      await expect(modal).toBeVisible();
      const moveAttempt = page.waitForResponse((response) => response.url().includes('/api/pipeline/move'));
      await modal.locator('[data-stage-modal-confirm="1"]').click();
      const response = await moveAttempt;
      expect(response.status()).toBe(422);
      await expect(modal.locator('[data-stage-modal-error="1"]')).toBeVisible();
      await expect(novaInscricaoColumn.locator(`#cand-${fixture.candidatura_id}`)).toBeVisible();
    } finally {
      runFixture('cleanup', fixture.candidatura_id, fixture.vaga_id);
    }
  });
});
