// Etapa 3 — comportamento REAL do JavaScript da matriz de permissões num navegador (sem servidor/login): o HTML vem do partial real
// (tests/php/fixture_matriz_html.php) e o CSS é o compilado do Portal. A regra de autorização é da política PHP (coberta por
// tests/php/integration_matriz_permissoes.php); aqui se prova que o JS apenas REFLETE o que o servidor entregou.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const html = (perfil, edicao, gravados = '') =>
  execFileSync('php', [path.join(raiz, 'tests/php/fixture_matriz_html.php'), perfil, edicao, gravados], { encoding: 'utf8' });

async function abrir(page, perfil, edicao = '0', gravados = '') {
  await page.setContent(html(perfil, edicao, gravados));
  await page.addStyleTag({ path: path.join(raiz, 'public/assets/tailwind.css') });
  await page.addScriptTag({ path: path.join(raiz, 'assets/matriz-permissoes.js') });
}
const marcadas = (page) =>
  page.locator('[data-matriz-celula="1"]:checked:not(:disabled)').evaluateAll((els) => els.map((e) => e.getAttribute('data-codigo')).sort());
const cel = (page, codigo) => page.locator(`[data-codigo="${codigo}"]`);

test('cadastro Viewer: modelo aplicado; Marcar todas só nos dashboards; Desmarcar todas; Restaurar modelo', async ({ page }) => {
  await abrir(page, 'viewer');
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  expect(await marcadas(page)).toEqual([
    'dashboard.visualizar', 'dashboard_entrevista_desligamento.visualizar', 'dashboard_recrutamento.visualizar',
    'dashboard_turnover.visualizar', 'indicadores_rh.visualizar',
  ]);
  await expect(cel(page, 'pdi.gerenciar')).not.toBeChecked(); // transição do Viewer: nunca em lote
  await expect(cel(page, 'empresas.criar')).toBeDisabled();
  await expect(page.locator('[data-matriz-contador]')).toHaveText('5 selecionadas');
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  expect(await marcadas(page)).toEqual([]);
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
});

test('marcação por grupo atua nas permissões habilitadas; grupo sem permissão concedível fica desabilitado (Leitor: sem novas operacionais)', async ({ page }) => {
  await abrir(page, 'viewer');
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  await page.locator('[data-matriz-area="indicadores"]').getByRole('button', { name: 'Marcar grupo' }).click();
  expect((await marcadas(page)).length).toBe(5);
  await page.locator('[data-matriz-area="indicadores"]').getByRole('button', { name: 'Limpar grupo' }).click();
  expect(await marcadas(page)).toEqual([]);
  // Etapa 3.1: o Leitor não recebe NOVAS permissões operacionais — o grupo de avaliações não tem nada concedível
  await expect(page.locator('[data-matriz-area="avaliacoes"]').getByRole('button', { name: 'Marcar grupo' })).toBeDisabled();
  await expect(page.locator('[data-matriz-area="administracao"]').getByRole('button', { name: 'Marcar grupo' })).toBeDisabled();
  await expect(cel(page, 'pdi.gerenciar')).toBeDisabled();
});

test('Gestor: concedíveis ≠ modelo — Marcar grupo/todas marcam as concedíveis fora do modelo; bloqueadas desabilitadas com motivo; Restaurar volta ao modelo', async ({ page }) => {
  await abrir(page, 'gestor');
  const modelo = await marcadas(page);
  expect(modelo).not.toContain('solicitacao_vaga.aprovar_lider');
  await expect(cel(page, 'solicitacao_vaga.aprovar_lider')).toBeEnabled();
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  await page.locator('[data-matriz-area="recrutamento"]').getByRole('button', { name: 'Marcar grupo' }).click();
  expect(await marcadas(page)).toEqual([
    'kanban_vagas.detalhes', 'kanban_vagas.movimentar', 'kanban_vagas.visualizar',
    'solicitacao_vaga.aprovar_lider', 'solicitacao_vaga.criar', 'solicitacao_vaga.visualizar',
  ]); // todas as concedíveis da área, inclusive a fora do modelo; nenhuma de Vagas/Candidaturas/Pipeline
  for (const bloqueada of ['candidaturas.visualizar', 'pipeline.movimentar', 'vagas.criar', 'solicitacao_vaga.editar', 'colaboradores.editar']) {
    await expect(cel(page, bloqueada)).toBeDisabled();
  }
  await expect(cel(page, 'candidaturas.visualizar').locator('xpath=ancestor::label')).toHaveAttribute('title', /Não concedível ao perfil: Candidaturas/);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  expect((await marcadas(page)).length).toBe(modelo.length + 2); // modelo + aprovar líder + dashboard agregado
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect(await marcadas(page)).toEqual(modelo);
  await expect(page.locator('[data-matriz-aviso-gestor]')).toBeVisible();
});

test('troca de perfil no cadastro: aplica o modelo; com ajuste manual pede confirmação e o cancelamento preserva tudo', async ({ page }) => {
  await abrir(page, 'viewer');
  await page.selectOption('#role', 'gestor');
  const gestor = await marcadas(page);
  expect(gestor.length).toBeGreaterThan(5);
  expect(gestor).not.toContain('solicitacao_vaga.aprovar_lider');
  await expect(cel(page, 'empresas.criar')).toBeDisabled();
  await cel(page, 'solicitacao_vaga.aprovar_lider').check();
  let mensagem = '';
  page.once('dialog', async (d) => { mensagem = d.message(); await d.dismiss(); });
  await page.selectOption('#role', 'viewer');
  expect(mensagem).toContain('não salvas');
  await expect(page.locator('#role')).toHaveValue('gestor');
  await expect(cel(page, 'solicitacao_vaga.aprovar_lider')).toBeChecked(); // seleção manual preservada
  page.once('dialog', (d) => d.accept());
  await page.selectOption('#role', 'viewer');
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
});

test('perfil global (RH/Admin): aviso, nenhuma checkbox habilitada, Marcar todas inoperante', async ({ page }) => {
  await abrir(page, 'viewer');
  await page.selectOption('#role', 'rh');
  await expect(page.locator('[data-matriz-aviso-global]')).toBeVisible();
  expect(await page.locator('[data-matriz-celula="1"]:not(:disabled)').count()).toBe(0);
  await expect(page.getByRole('button', { name: 'Marcar todas', exact: true })).toBeDisabled();
});

test('edição: abre com o gravado, sinaliza o legado incompatível, não o reativa e exige confirmação para trocar de perfil', async ({ page }) => {
  await abrir(page, 'viewer', '1', 'pdi.visualizar,empresas.criar');
  expect(await marcadas(page)).toEqual(['empresas.criar', 'pdi.visualizar']);
  await expect(page.locator('[data-matriz-aviso-legado]')).toBeVisible();
  await expect(page.locator('[data-matriz-legado-lista]')).toHaveText('empresas.criar');
  await expect(cel(page, 'empresas.criar')).toBeEnabled(); // para poder desmarcar (regularização explícita)
  await cel(page, 'empresas.criar').uncheck();
  await expect(page.locator('[data-matriz-aviso-legado]')).toBeHidden();
  await cel(page, 'empresas.criar').check();
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  await expect(cel(page, 'empresas.criar')).not.toBeChecked(); // Marcar todas não reativa o legado
  let confirmou = false;
  page.once('dialog', async (d) => { confirmou = true; await d.dismiss(); });
  await page.selectOption('#role', 'gestor');
  expect(confirmou).toBe(true);
  await expect(page.locator('#role')).toHaveValue('viewer');
});

test('teclado e responsividade: checkboxes com nome acessível; a tabela rola dentro do contêiner no celular', async ({ page }) => {
  await abrir(page, 'viewer');
  const semNome = await page.locator('[data-matriz-celula="1"]').evaluateAll((els) => els.filter((e) => !(e.getAttribute('aria-label') || '').includes('—')).length);
  expect(semNome).toBe(0);
  await cel(page, 'dashboard.visualizar').focus();
  await page.keyboard.press('Space');
  await expect(cel(page, 'dashboard.visualizar')).toBeChecked();
  await page.setViewportSize({ width: 375, height: 800 });
  const m = await page.evaluate(() => {
    const regiao = document.querySelector('[role="region"]');
    return { rola: regiao.scrollWidth > regiao.clientWidth, paginaRola: document.documentElement.scrollWidth > window.innerWidth + 1 };
  });
  expect(m.rola).toBe(true);
  expect(m.paginaRola).toBe(false);
});

test('perfil global na edição: contador fala em atribuições individuais gravadas, não em selecionadas', async ({ page }) => {
  await abrir(page, 'admin', '1', 'pdi.visualizar,dashboard.visualizar,empresas.criar');
  await expect(page.locator('[data-matriz-contador]')).toHaveText('3 atribuições individuais');
  await expect(page.locator('[data-matriz-total-texto]')).toContainText('acesso é global pelo perfil');
  page.once('dialog', (d) => d.accept()); // na edição a troca de perfil sempre pede confirmação
  await page.selectOption('#role', 'viewer');
  await expect(page.locator('[data-matriz-contador]')).toHaveText(/selecionada/);
});
