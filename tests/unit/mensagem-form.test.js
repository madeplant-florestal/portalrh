const assert = require('node:assert/strict');
const adminJsModule = require('../../public/assets/admin.js');
const {
  detectarPlaceholdersMensagem,
  analisarPlaceholdersMensagem,
  renderizarPreviaMensagem,
  inserirPlaceholderNoTexto,
} = adminJsModule;

// Módulo de Mensagens — a detecção/renderização/inserção client-side (tela de criação/edição)
// espelha exatamente a lógica de app/services/MensagemService.php; a fonte de verdade operacional
// continua sendo o backend (ver tests/php/integration_mensagens.php). O catálogo usado aqui é o
// mesmo conjunto de 9 chaves oficiais — nunca uma lista própria e divergente do backend.
const CATALOGO_NOMES = [
  'Nome', 'Data', 'Horário', 'Responsável', 'Nome do Gestor',
  'Local ou Link', 'Nome da Clínica', 'Endereço', 'Telefone',
];

assert.equal(typeof detectarPlaceholdersMensagem, 'function', 'detectarPlaceholdersMensagem precisa estar exportado');
assert.equal(typeof analisarPlaceholdersMensagem, 'function', 'analisarPlaceholdersMensagem precisa estar exportado');
assert.equal(typeof renderizarPreviaMensagem, 'function', 'renderizarPreviaMensagem precisa estar exportado');
assert.equal(typeof inserirPlaceholderNoTexto, 'function', 'inserirPlaceholderNoTexto precisa estar exportado');

// [Nome] é detectado.
assert.deepEqual(detectarPlaceholdersMensagem('Olá, [Nome].'), ['Nome']);

// Múltiplos placeholders, na ordem de aparição, sem duplicar repetição.
assert.deepEqual(
  detectarPlaceholdersMensagem('[Nome] tem entrevista em [Data] às [Horário]. Confirme, [Nome].'),
  ['Nome', 'Data', 'Horário']
);

// Sem placeholder -> lista vazia.
assert.deepEqual(detectarPlaceholdersMensagem('Texto sem nenhuma variável.'), []);
assert.deepEqual(detectarPlaceholdersMensagem(''), []);
assert.deepEqual(detectarPlaceholdersMensagem(null), []);

// Placeholders com espaços e acentos funcionam (regex não se limita a \w).
assert.deepEqual(detectarPlaceholdersMensagem('Exame na [Nome da Clínica], em [Local ou Link].'), ['Nome da Clínica', 'Local ou Link']);

// ---- Reconhecido x desconhecido (ajuste "Catálogo e inserção assistida") -----------------------

assert.deepEqual(
  analisarPlaceholdersMensagem('Olá, [Nome]. Exame na [Nome da Clínica].', CATALOGO_NOMES),
  { reconhecidas: ['Nome', 'Nome da Clínica'], desconhecidas: [] }
);

// Placeholder fora do catálogo é identificado separadamente — nunca misturado com reconhecidas.
assert.deepEqual(
  analisarPlaceholdersMensagem('Olá, [Primeiro Nome]. Cargo: [Cargo].', CATALOGO_NOMES),
  { reconhecidas: [], desconhecidas: ['Primeiro Nome', 'Cargo'] }
);

// Sem catálogo informado (payload ausente/inválido) -> tudo cai em desconhecidas, nunca finge reconhecer.
assert.deepEqual(analisarPlaceholdersMensagem('Olá, [Nome].'), { reconhecidas: [], desconhecidas: ['Nome'] });

// ---- Renderização/pré-visualização ---------------------------------------------------------------

// Renderização substitui corretamente, preserva quebra de linha e emoji.
const previa = renderizarPreviaMensagem('📅 Data: [Data]\n🕒 Horário: [Horário]', { Data: '20/09/2026', Horário: '14h' }, CATALOGO_NOMES);
assert.equal(previa.texto, '📅 Data: 20/09/2026\n🕒 Horário: 14h');
assert.deepEqual(previa.pendentes, []);
assert.deepEqual(previa.desconhecidas, []);

// Placeholder RECONHECIDO sem valor NUNCA é silenciado: continua escrito como [Nome] e aparece em `pendentes`.
const incompleta = renderizarPreviaMensagem('Olá, [Nome]. Local: [Local ou Link].', { Nome: 'Ana' }, CATALOGO_NOMES);
assert.equal(incompleta.texto, 'Olá, Ana. Local: [Local ou Link].');
assert.deepEqual(incompleta.pendentes, ['Local ou Link']);
assert.deepEqual(incompleta.desconhecidas, []);

// Placeholder DESCONHECIDO é tratado à parte de "pendente" — nunca a mesma categoria.
const comDesconhecido = renderizarPreviaMensagem('Olá, [Nome]. Etapa: [Primeiro Nome].', { Nome: 'Ana' }, CATALOGO_NOMES);
assert.equal(comDesconhecido.texto, 'Olá, Ana. Etapa: [Primeiro Nome].');
assert.deepEqual(comDesconhecido.pendentes, []);
assert.deepEqual(comDesconhecido.desconhecidas, ['Primeiro Nome']);

// Valor em branco (só espaços) conta como pendente, não como "preenchido com vazio".
const soEspacos = renderizarPreviaMensagem('Olá, [Nome].', { Nome: '   ' }, CATALOGO_NOMES);
assert.deepEqual(soEspacos.pendentes, ['Nome']);
assert.equal(soEspacos.texto, 'Olá, [Nome].');

// ---- Inserção assistida (clique em "Variável automática" insere no cursor) ----------------------

// Cursor posicionado no meio do texto: insere exatamente ali, preserva antes/depois.
const meio = inserirPlaceholderNoTexto('Olá, . Seja bem-vindo.', 5, 5, '[Nome]');
assert.equal(meio.texto, 'Olá, [Nome]. Seja bem-vindo.');
assert.equal(meio.cursor, 5 + '[Nome]'.length, 'cursor fica imediatamente após o placeholder inserido');

// Seleção de texto (inicio !== fim): substitui só o trecho selecionado, preserva o resto.
const substituindoSelecao = inserirPlaceholderNoTexto('Olá, FULANO. Bem-vindo.', 5, 11, '[Nome]');
assert.equal(substituindoSelecao.texto, 'Olá, [Nome]. Bem-vindo.');

// Sem posição de cursor válida (undefined/null) -> insere ao final, nunca apaga o conteúdo existente.
const semCursor = inserirPlaceholderNoTexto('Texto já digitado.', undefined, undefined, '[Data]');
assert.equal(semCursor.texto, 'Texto já digitado.[Data]');

const cursorInvalido = inserirPlaceholderNoTexto('Texto já digitado.', -5, 999, '[Data]');
assert.equal(cursorInvalido.texto, 'Texto já digitado.[Data]', 'posições fora do intervalo válido caem para o final, não corrompem o texto');

// Conteúdo vazio: insere normalmente, sem erro.
const vazio = inserirPlaceholderNoTexto('', 0, 0, '[Nome]');
assert.equal(vazio.texto, '[Nome]');
assert.equal(vazio.cursor, '[Nome]'.length);

// Inserir no início preserva o restante do texto.
const inicio = inserirPlaceholderNoTexto('resto do texto', 0, 0, '[Nome]');
assert.equal(inicio.texto, '[Nome]resto do texto');

console.log('OK unit mensagem-form');
