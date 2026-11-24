// Etapa 3.2C.1 — experiência do Gestor nas listagens de Vagas e Candidaturas contra a APLICAÇÃO REAL (login, layout, HTML renderizado).
// Requer servidor (BASE_URL, padrão http://127.0.0.1:8010: `php -S 127.0.0.1:8010 -t public`) e banco de DESENVOLVIMENTO; cria dados ZZRS* e
// os remove ao final. Não roda contra produção. Um login por usuário por projeto (o login tem limite de tentativas por IP).
const base = require('@playwright/test');
const { expect } = base;
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const prefixo = 'zzrs' + Math.random().toString(36).slice(2, 8);
const fixture = (acao) => execFileSync('php', [path.join(raiz, 'tests/php/fixture_recrutamento_navegador.php'), acao, prefixo], { encoding: 'utf8' });
let dados;
const cookiesPor = {};

const test = base.test;
test.describe.configure({ mode: 'serial' });

async function login(browser, baseURL, projectUse, email) {
  const ctx = await browser.newContext({ ...projectUse, baseURL });
  const pg = await ctx.newPage();
  await pg.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await pg.locator('input[name=email]').fill(email);
  await pg.locator('input[name=password]').fill(dados.senha);
  await pg.locator('button[type=submit]').click();
  await pg.waitForURL((u) => !u.pathname.includes('/admin/login'), { timeout: 30000 });
  const cookies = (await ctx.storageState()).cookies;
  await ctx.close();
  return cookies;
}

test.beforeAll(async ({ browser, baseURL }, testInfo) => {
  dados = JSON.parse(fixture('criar').trim().split(String.fromCharCode(10)).pop());
  for (const chave of ['gestor', 'gestorSemSetor', 'gestorSemPerm', 'rh', 'viewer']) {
    cookiesPor[chave] = await login(browser, baseURL, testInfo.project.use, dados.emails[chave]);
  }
});
test.afterAll(() => { fixture('limpar'); });

/** Página já autenticada como o usuário `chave`, com coleta de erros de console/JS. */
async function como(browser, baseURL, projectUse, chave) {
  const ctx = await browser.newContext({ ...projectUse, baseURL });
  await ctx.addCookies(cookiesPor[chave]);
  const page = await ctx.newPage();
  const erros = [];
  page.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') erros.push('console: ' + m.text()); });
  return { page, ctx, erros };
}
const visivel = async (page, texto) => (await page.getByText(texto, { exact: false }).locator('visible=true').count()) > 0;

test('Gestor: listagem de candidaturas só do escopo, filtros visíveis, sem dados pessoais nem ações', async ({ browser, baseURL }, testInfo) => {
  const { page, ctx, erros } = await como(browser, baseURL, testInfo.project.use, 'gestor');
  const resp = await page.goto('/admin/candidaturas?busca=Propria');
  expect(resp.status()).toBe(200);
  await expect(page.locator('[data-testid="filtros-candidaturas-setorial"]')).toBeVisible();
  for (const nome of ['vaga_id', 'setor_id', 'stage_id', 'busca', 'data_de', 'data_ate']) {
    await expect(page.locator(`[name="${nome}"]`)).toHaveCount(1);
  }
  await expect(page.locator('[data-testid="total-candidaturas"]')).toContainText('24 candidatura(s) encontrada(s)');
  expect(await visivel(page, 'Pagina22 Propria')).toBeTruthy();
  expect(await visivel(page, 'Carla Externa')).toBeFalsy();
  const html = await page.content();
  for (const proibido of [dados.candidatoPropriaEmail, '11987654321', '(11) 98765-4321', 'CargoSecretoZZ', 'ExperienciaSecretaZZ', 'zz.pdf', 'E-mail', 'Telefone']) {
    expect(html, `não deve conter ${proibido}`).not.toContain(proibido);
  }
  expect(await page.locator('a[href*="/admin/candidaturas/"]').count()).toBe(0);
  expect(await page.locator('a[href*="/download"], a[href*="/documentos"], a[href$="/admin/pipeline"]').locator('visible=true').count()).toBe(0);
  await expect(page.getByRole('link', { name: 'Ver Kanban' })).toHaveCount(0);
  // o filtro de setor lista só os setores gerenciados; o de vaga só as vagas do escopo
  const setores = await page.locator('select[name="setor_id"] option').allTextContents();
  expect(setores.join('|')).toContain(`ZZRS Setor1 ${prefixo}`);
  expect(setores.join('|')).not.toContain(`ZZRS Setor3 ${prefixo}`);
  const vagas = await page.locator('select[name="vaga_id"] option').allTextContents();
  expect(vagas.join('|')).not.toContain(dados.vagas.v3);
  expect(vagas.join('|')).not.toContain(dados.vagas.vSem);
  // um candidato de cada vaga do escopo (setor 1 e setor 2) aparece pela busca; o do setor 3 e o sem setor, nunca
  for (const [busca, aparece] of [['Alice', true], ['Bruno', true], ['Carla', false], ['Dario', false]]) {
    await page.goto('/admin/candidaturas?busca=' + busca);
    expect(await visivel(page, busca), busca).toBe(aparece);
  }
  expect(erros).toEqual([]);
  await ctx.close();
});

test('Gestor: filtros, busca e paginação funcionam dentro do escopo (e mobile mostra cartões)', async ({ browser, baseURL }, testInfo) => {
  const { page, ctx, erros } = await como(browser, baseURL, testInfo.project.use, 'gestor');
  await page.goto('/admin/candidaturas');
  await page.locator('select[name="vaga_id"]').selectOption({ label: dados.vagas.v1 });
  await page.locator('input[name="busca"]').fill('Alice');
  await page.getByRole('button', { name: 'Filtrar' }).click();
  await expect(page.locator('[data-testid="total-candidaturas"]')).toContainText('1 candidatura(s)');
  expect(await visivel(page, 'Alice Propria')).toBeTruthy();
  expect(await visivel(page, 'Bruno Propria')).toBeFalsy();
  // forçar vaga de outro setor pela URL não revela nada
  await page.goto(`/admin/candidaturas?vaga_id=999999&setor_id=999999`);
  await expect(page.locator('[data-testid="vazio-candidaturas"]')).toBeVisible();
  expect(await visivel(page, 'Carla Externa')).toBeFalsy();
  // paginação
  await page.goto(`/admin/candidaturas?busca=Propria`);
  await expect(page.locator('[data-testid="paginacao-candidaturas"]')).toBeVisible();
  await page.getByRole('link', { name: 'Próxima' }).click();
  await expect(page.locator('[data-testid="paginacao-candidaturas"]')).toContainText('Página 2 de 2');
  await expect(page).toHaveURL(/page=2/);
  expect(await visivel(page, 'Carla Externa')).toBeFalsy();
  if ((page.viewportSize()?.width ?? 1366) < 768) {
    await expect(page.locator('.responsive-card').first()).toBeVisible();
    await expect(page.locator('table').first()).toBeHidden();
  }
  expect(erros).toEqual([]);
  await ctx.close();
});

test('Gestor: Vagas lista só as do escopo, sem Editar/Excluir/Publicar/Nova vaga', async ({ browser, baseURL }, testInfo) => {
  const { page, ctx, erros } = await como(browser, baseURL, testInfo.project.use, 'gestor');
  const resp = await page.goto('/admin/vagas');
  expect(resp.status()).toBe(200);
  expect(await visivel(page, dados.vagas.v1)).toBeTruthy();
  expect(await visivel(page, dados.vagas.v2)).toBeTruthy();
  expect(await visivel(page, dados.vagas.v3)).toBeFalsy();
  expect(await visivel(page, dados.vagas.vSem)).toBeFalsy();
  const html = await page.content();
  for (const proibido of ['/admin/vagas/editar', '/admin/vagas/novo', '/excluir', '/publicar', '/admin/solicitacoes-vaga/']) {
    expect(html, `não deve conter ${proibido}`).not.toContain(proibido);
  }
  await expect(page.getByRole('button', { name: /Editar|Excluir|Publicar/ })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /Nova vaga|Editar/ })).toHaveCount(0);
  expect(erros).toEqual([]);
  await ctx.close();
});

test('Gestor: navegação não oferece Pipeline nem Indicações e acesso direto às rotas bloqueadas dá 403', async ({ browser, baseURL }, testInfo) => {
  const { page, ctx } = await como(browser, baseURL, testInfo.project.use, 'gestor');
  await page.goto('/admin/candidaturas');
  expect(await page.locator('a[href$="/admin/pipeline"]:not([aria-disabled="true"])').count()).toBe(0);
  expect(await page.locator('a[href$="/admin/indicacoes"]:not([aria-disabled="true"])').count()).toBe(0);
  for (const rota of [`/admin/candidaturas/${dados.candidatoPropriaId}`, `/admin/candidaturas/${dados.candidatoPropriaId}/download`, `/admin/candidaturas/${dados.candidatoPropriaId}/documentos/1`, '/admin/pipeline', '/admin/vagas/novo', `/admin/vagas/editar/${dados.vaga1Id}`, '/admin/indicacoes']) {
    const r = await page.goto(rota);
    expect(r.status(), rota).toBe(403);
    expect(await page.content()).not.toContain(dados.candidatoPropriaEmail);
  }
  await ctx.close();
});

test('Gestor sem setores: listas vazias; Gestor sem permissão: 403', async ({ browser, baseURL }, testInfo) => {
  let t = await como(browser, baseURL, testInfo.project.use, 'gestorSemSetor');
  expect((await t.page.goto('/admin/candidaturas')).status()).toBe(200);
  await expect(t.page.locator('[data-testid="vazio-candidaturas"]')).toBeVisible();
  await expect(t.page.locator('[data-testid="total-candidaturas"]')).toContainText('0 candidatura(s)');
  await t.page.goto('/admin/vagas');
  expect(await visivel(t.page, 'ZZRS Vaga')).toBeFalsy();
  await t.ctx.close();
  t = await como(browser, baseURL, testInfo.project.use, 'gestorSemPerm');
  expect((await t.page.goto('/admin/candidaturas')).status()).toBe(403);
  expect((await t.page.goto('/admin/vagas')).status()).toBe(403);
  await t.ctx.close();
});

test('RH e Viewer preservados', async ({ browser, baseURL }, testInfo) => {
  let t = await como(browser, baseURL, testInfo.project.use, 'rh');
  expect((await t.page.goto('/admin/candidaturas?busca=Externa')).status()).toBe(200);
  const html = await t.page.content();
  expect(html).toContain('Carla Externa');
  expect(html).toContain(dados.candidatoExternoEmail);
  await expect(t.page.getByRole('link', { name: 'Ver Kanban' })).toBeVisible();
  expect(await t.page.locator('a[href*="/admin/candidaturas/"]').count()).toBeGreaterThan(0);
  await t.ctx.close();
  t = await como(browser, baseURL, testInfo.project.use, 'viewer');
  expect((await t.page.goto('/admin/candidaturas')).status()).toBe(403);
  expect((await t.page.goto('/admin/vagas')).status()).toBe(200);
  expect(await visivel(t.page, dados.vagas.v3)).toBeTruthy(); // Viewer: lista global como sempre
  await t.ctx.close();
});
