// Etapa 3 (correção) — controles da matriz de permissões contra a APLICAÇÃO REAL: login, layout app-shell, scripts carregados pelo
// layout (com ?v=), HTML real de /admin/usuarios/novo e /admin/usuarios/{id}. Requer servidor (BASE_URL, padrão http://127.0.0.1:8010:
// `php -S 127.0.0.1:8010 -t public`) e banco de DESENVOLVIMENTO; cria usuários ZZNAV* e os remove ao final. Não roda contra produção.
const base = require('@playwright/test');
const { expect } = base;
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const prefixo = 'zznav' + Math.random().toString(36).slice(2, 8);
const fixture = (acao) => execFileSync('php', [path.join(raiz, 'tests/php/fixture_usuarios_navegador.php'), acao, prefixo], { encoding: 'utf8' });
let dados;
let cookies = [];

// Um login por projeto (não por teste): o login tem limite de tentativas por IP, e 45 logins seguidos seriam barrados.
const test = base.test.extend({
  context: async ({ context }, use) => {
    if (cookies.length) await context.addCookies(cookies);
    await use(context);
  },
});

test.describe.configure({ mode: 'serial' });
test.beforeAll(async ({ browser, baseURL }, testInfo) => {
  dados = JSON.parse(fixture('criar').trim().split(String.fromCharCode(10)).pop());
  // Mesmas opções do projeto (user agent, viewport): a sessão do Portal é amarrada ao navegador que fez o login.
  const ctx = await browser.newContext({ ...testInfo.project.use, baseURL });
  const pg = await ctx.newPage();
  await pg.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await pg.locator('input[name=email]').fill(dados.email);
  await pg.locator('input[name=password]').fill(dados.senha);
  await pg.locator('button[type=submit]').click();
  await pg.waitForURL((u) => !u.pathname.includes('/admin/login'), { timeout: 30000 }); // o login só termina quando redireciona
  cookies = (await ctx.storageState()).cookies;
  await ctx.close();
});
test.afterAll(() => { fixture('limpar'); });

/** Sessão já autenticada (cookies do beforeAll); só liga a coleta de erros de console/JS. */
async function entrar(page) {
  const erros = [];
  page.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') erros.push('console: ' + m.text()); });
  return erros;
}
const marcadas = (page) =>
  page.locator('[data-matriz-celula="1"]:checked:not(:disabled)').evaluateAll((els) => els.map((e) => e.getAttribute('data-codigo')).sort());
const cel = (page, codigo) => page.locator(`[data-codigo="${codigo}"]`);
const area = (page, chave) => page.locator(`[data-matriz-area="${chave}"]`);
// O que REALMENTE seria enviado no formulário (inputs habilitados e marcados, com name).
const enviados = (page) =>
  page.locator('form:has([data-matriz="1"])').evaluate((f) => new FormData(f).getAll('permissao_ids[]').map(Number).sort((a, b) => a - b));
const idsMarcados = (page) =>
  page.locator('[data-matriz-celula="1"]:checked:not(:disabled)').evaluateAll((els) => els.map((e) => Number(e.getAttribute('data-perm-id'))).sort((a, b) => a - b));
/** O layout real referencia o JS com ?v=<versão>, ele responde 200 e REALMENTE executou (o contador é reescrito pelo JS: "1 selecionada"). */
async function jsCarregadoEExecutou(page, esperado) {
  const src = await page.locator('script[src*="matriz-permissoes.js"]').first().getAttribute('src');
  expect(src).toMatch(/\/assets\/matriz-permissoes\.js\?v=\d+$/);
  expect((await page.request.get(src)).status()).toBe(200);
  await expect(page.locator('[data-matriz-contador]')).toHaveText(esperado);
}

test('cadastro: o layout carrega o JS (200, com ?v=), sem erros de console, e o HTML real casa com os seletores', async ({ page }) => {
  const erros = await entrar(page);
  await page.goto('/admin/usuarios/novo');
  await jsCarregadoEExecutou(page, '1 selecionada');
  await expect(page.locator('[data-matriz="1"]')).toHaveCount(1);
  for (const acao of ['marcar-todas', 'desmarcar-todas', 'restaurar-modelo']) {
    expect(await page.locator(`[data-matriz-acao="${acao}"]`).count()).toBeGreaterThan(0);
  }
  expect(await page.locator('[data-matriz-acao="marcar-grupo"]').count()).toBe(5);
  expect(await page.locator('[data-matriz-acao="alternar-linha"]').count()).toBeGreaterThan(10);
  expect(erros).toEqual([]);
});

test('cadastro de Viewer: Marcar todas, Desmarcar todas, grupo, linha, Restaurar modelo; valores enviados = estado visual; nada é salvo', async ({ page }) => {
  await entrar(page);
  await page.goto('/admin/usuarios/novo');
  const url = page.url();
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  expect((await marcadas(page)).length).toBe(5);
  expect((await enviados(page)).length).toBe(5);
  await expect(page.locator('[data-matriz-contador]')).toHaveText('5 selecionadas');
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  expect(await marcadas(page)).toEqual([]);
  expect(await enviados(page)).toEqual([]);
  await area(page, 'indicadores').getByRole('button', { name: 'Marcar grupo' }).click();
  expect((await marcadas(page)).length).toBe(5);
  await area(page, 'indicadores').getByRole('button', { name: 'Limpar grupo' }).click();
  expect(await marcadas(page)).toEqual([]);
  await page.locator('[data-matriz-linha="dashboard_turnover"]').locator('[data-matriz-acao="alternar-linha"]').click();
  expect(await marcadas(page)).toEqual(['dashboard_turnover.visualizar']);
  await page.locator('[data-matriz-linha="dashboard_turnover"]').locator('[data-matriz-acao="alternar-linha"]').click();
  expect(await marcadas(page)).toEqual([]);
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
  expect(page.url()).toBe(url); // nenhum botão submeteu o formulário
  expect(await page.locator('[data-matriz-acao]').evaluateAll((bs) => bs.every((b) => b.type === 'button'))).toBe(true);
});

test('cadastro de Gestor: o modelo vem marcado; Marcar grupo/linha/todas atuam nas permissões do Gestor; módulos globais seguem desabilitados', async ({ page }) => {
  await entrar(page);
  await page.goto('/admin/usuarios/novo');
  await page.selectOption('#role', 'gestor');
  const modelo = await marcadas(page);
  expect(modelo.length).toBeGreaterThan(5);
  expect(modelo).not.toContain('solicitacao_vaga.aprovar_lider');
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  expect(await marcadas(page)).toEqual([]);
  await area(page, 'avaliacoes').getByRole('button', { name: 'Marcar grupo' }).click();
  expect(await marcadas(page)).toEqual(['avaliacao_desempenho.avaliar', 'avaliacao_desempenho.visualizar', 'avaliacao_experiencia.avaliar', 'avaliacao_experiencia.visualizar', 'feedback.avaliar', 'feedback.visualizar', 'pdi.acompanhar', 'pdi.gerenciar', 'pdi.visualizar']);
  await area(page, 'avaliacoes').getByRole('button', { name: 'Limpar grupo' }).click();
  await page.locator('[data-matriz-linha="pdi"]').locator('[data-matriz-acao="alternar-linha"]').click();
  expect(await marcadas(page)).toEqual(['pdi.acompanhar', 'pdi.gerenciar', 'pdi.visualizar']);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  const todas = await marcadas(page);
  expect(todas).toContain('solicitacao_vaga.aprovar_lider'); // permitida ao Gestor pela política
  expect(todas).not.toContain('empresas.criar');
  await expect(cel(page, 'empresas.criar')).toBeDisabled();
  expect((await enviados(page)).length).toBe(todas.length);
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect(await marcadas(page)).toEqual(modelo);
});

test('cadastro: Admin/RH têm controles inativos; trocar para Viewer com seleção manual pede confirmação', async ({ page }) => {
  await entrar(page);
  await page.goto('/admin/usuarios/novo');
  await page.selectOption('#role', 'admin');
  expect(await page.locator('[data-matriz-celula="1"]:not(:disabled)').count()).toBe(0);
  await expect(page.getByRole('button', { name: 'Marcar todas', exact: true })).toBeDisabled();
  await expect(area(page, 'indicadores').getByRole('button', { name: 'Marcar grupo' })).toBeDisabled();
  expect(await marcadas(page)).toEqual([]);
  await page.selectOption('#role', 'viewer');
  await cel(page, 'dashboard.visualizar').check();
  let msg = '';
  page.once('dialog', async (d) => { msg = d.message(); await d.dismiss(); });
  await page.selectOption('#role', 'rh');
  expect(msg).toContain('não salvas');
  await expect(page.locator('#role')).toHaveValue('viewer');
  await expect(cel(page, 'dashboard.visualizar')).toBeChecked();
});

test('edição de Viewer: abre com o gravado; legado incompatível não é reativado; controles atuam; nada é salvo', async ({ page }) => {
  await entrar(page);
  await page.goto(`/admin/usuarios/${dados.viewer}`);
  await jsCarregadoEExecutou(page, '2 selecionadas');
  expect(await marcadas(page)).toEqual(['empresas.criar', 'pdi.visualizar']);
  await expect(page.locator('[data-matriz-aviso-legado]')).toBeVisible();
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  expect(await marcadas(page)).toEqual([]);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  expect(await marcadas(page)).not.toContain('empresas.criar');
  expect((await marcadas(page)).length).toBe(5);
  await area(page, 'indicadores').getByRole('button', { name: 'Limpar grupo' }).click();
  await area(page, 'indicadores').getByRole('button', { name: 'Marcar grupo' }).click();
  expect((await marcadas(page)).length).toBe(5);
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect(await marcadas(page)).toEqual(['dashboard_recrutamento.visualizar']);
  expect(await enviados(page)).toEqual(await idsMarcados(page));
  // nada foi salvo: recarregar traz o gravado de novo
  await page.reload();
  expect(await marcadas(page)).toEqual(['empresas.criar', 'pdi.visualizar']);
});

test('edição de Gestor: abre com o gravado; Marcar grupo/linha/todas e Restaurar modelo; troca de perfil confirma', async ({ page }) => {
  await entrar(page);
  await page.goto(`/admin/usuarios/${dados.gestor}`);
  expect(await marcadas(page)).toEqual(['colaboradores.visualizar']);
  await area(page, 'avaliacoes').getByRole('button', { name: 'Marcar grupo' }).click();
  expect((await marcadas(page)).length).toBe(10);
  await page.locator('[data-matriz-linha="pdi"]').locator('[data-matriz-acao="alternar-linha"]').click(); // todas marcadas -> desmarca a linha
  expect(await marcadas(page)).not.toContain('pdi.gerenciar');
  await page.locator('[data-matriz-linha="pdi"]').locator('[data-matriz-acao="alternar-linha"]').click();
  expect(await marcadas(page)).toContain('pdi.gerenciar');
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  await expect(cel(page, 'empresas.criar')).toBeDisabled();
  await page.getByRole('button', { name: 'Restaurar modelo do perfil' }).click();
  expect((await marcadas(page)).length).toBeGreaterThan(10);
  let confirmou = false;
  page.once('dialog', async (d) => { confirmou = true; await d.dismiss(); });
  await page.selectOption('#matriz-perfil', 'viewer');
  expect(confirmou).toBe(true);
  await expect(page.locator('#matriz-perfil')).toHaveValue('gestor');
});

test('edição de Admin e de RH: atribuições gravadas aparecem como tal e o lote é inoperante', async ({ page }) => {
  await entrar(page);
  await page.goto(`/admin/usuarios/${dados.admin}`);
  await expect(page.locator('[data-matriz-contador]')).toHaveText('2 atribuições individuais');
  await expect(page.getByRole('button', { name: 'Marcar todas', exact: true })).toBeDisabled();
  await expect(area(page, 'indicadores').getByRole('button', { name: 'Marcar grupo' })).toBeDisabled();
  await expect(page.locator('[data-matriz-contador]')).toHaveText('2 atribuições individuais');
  await page.goto(`/admin/usuarios/${dados.rh}`);
  await expect(page.locator('[data-matriz-contador]')).toHaveText('0 atribuições individuais');
});

test('Viewer: grupo e linha marcam TODAS as permissões habilitadas do escopo (inclusive as de transição); Marcar todas continua só nos dashboards', async ({ page }) => {
  await entrar(page);
  await page.goto('/admin/usuarios/novo');
  await page.getByRole('button', { name: 'Desmarcar todas' }).click();
  await area(page, 'avaliacoes').getByRole('button', { name: 'Marcar grupo' }).click();
  expect(await marcadas(page)).toEqual(['avaliacao_desempenho.avaliar', 'avaliacao_desempenho.visualizar', 'avaliacao_experiencia.avaliar', 'avaliacao_experiencia.visualizar', 'feedback.avaliar', 'feedback.visualizar', 'pdi.acompanhar', 'pdi.gerenciar', 'pdi.visualizar']);
  await expect(page.locator('[data-matriz-feedback]')).toContainText('9 permissões marcadas no grupo. Nada foi salvo.');
  expect((await enviados(page)).length).toBe(9);
  await area(page, 'avaliacoes').getByRole('button', { name: 'Limpar grupo' }).click();
  expect(await marcadas(page)).toEqual([]);
  const linhaSolic = page.locator('[data-matriz-linha="solicitacao_vaga"] [data-matriz-acao="alternar-linha"]');
  await expect(linhaSolic).toHaveText('marcar linha');
  await linhaSolic.click();
  expect(await marcadas(page)).toEqual(['solicitacao_vaga.cancelar', 'solicitacao_vaga.criar', 'solicitacao_vaga.editar', 'solicitacao_vaga.visualizar']); // aprovar_lider é incompatível com o Leitor: fica de fora
  await expect(cel(page, 'solicitacao_vaga.aprovar_lider')).toBeDisabled();
  await expect(linhaSolic).toHaveText('limpar linha');
  await linhaSolic.click();
  expect(await marcadas(page)).toEqual([]);
  await page.getByRole('button', { name: 'Marcar todas', exact: true }).click();
  expect((await marcadas(page)).length).toBe(5); // lote do Leitor: só dashboards
  // grupo sem nenhuma permissão aplicável ao perfil: botão desabilitado (sem no-op silencioso)
  await expect(area(page, 'administracao').getByRole('button', { name: 'Marcar grupo' })).toBeDisabled();
});

test('perfil global na edição: Marcar todas, Marcar grupo e linha ficam desabilitados (nada de clique sem efeito)', async ({ page }) => {
  await entrar(page);
  await page.goto(`/admin/usuarios/${dados.admin}`);
  for (const acao of ['marcar-todas', 'marcar-grupo', 'alternar-linha']) {
    const botoes = page.locator(`[data-matriz-acao="${acao}"]`);
    expect(await botoes.evaluateAll((bs) => bs.every((b) => b.disabled))).toBe(true);
  }
  await expect(page.getByRole('button', { name: 'Desmarcar todas' })).toBeEnabled(); // regularização explícita continua possível
});
