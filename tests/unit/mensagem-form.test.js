const assert = require('node:assert/strict');
const adminJsModule = require('../../public/assets/admin.js');
const { detectarPlaceholdersMensagem, renderizarPreviaMensagem } = adminJsModule;

// Módulo de Mensagens — a detecção/renderização client-side (pré-visualização na tela de edição)
// espelha exatamente a lógica de app/services/MensagemService.php; a fonte de verdade operacional
// continua sendo o backend (ver tests/php/integration_mensagens.php).

assert.equal(typeof detectarPlaceholdersMensagem, 'function', 'detectarPlaceholdersMensagem precisa estar exportado');
assert.equal(typeof renderizarPreviaMensagem, 'function', 'renderizarPreviaMensagem precisa estar exportado');

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

// Renderização substitui corretamente, preserva quebra de linha e emoji.
const previa = renderizarPreviaMensagem('📅 Data: [Data]\n🕒 Horário: [Horário]', { Data: '20/09/2026', Horário: '14h' });
assert.equal(previa.texto, '📅 Data: 20/09/2026\n🕒 Horário: 14h');
assert.deepEqual(previa.pendentes, []);

// Placeholder sem valor NUNCA é silenciado: continua escrito como [Nome] e aparece em `pendentes`.
const incompleta = renderizarPreviaMensagem('Olá, [Nome]. Local: [Local ou Link].', { Nome: 'Ana' });
assert.equal(incompleta.texto, 'Olá, Ana. Local: [Local ou Link].');
assert.deepEqual(incompleta.pendentes, ['Local ou Link']);

// Valor em branco (só espaços) conta como pendente, não como "preenchido com vazio".
const soEspacos = renderizarPreviaMensagem('Olá, [Nome].', { Nome: '   ' });
assert.deepEqual(soEspacos.pendentes, ['Nome']);
assert.equal(soEspacos.texto, 'Olá, [Nome].');

console.log('OK unit mensagem-form');
