const assert = require('node:assert/strict');
const { resolveSolicitanteSetorState, resolveCentrosCustoParaSetor } = require('../../public/assets/admin.js');

// Sprint Solicitação de Vaga — Etapa 2 (Contexto Organizacional dos Usuários): Cargo e Setor são
// selecionados de forma independente (sem gate cargo_setores); o Setor vem do contexto de Setores
// do Usuário solicitante (usuario_setores).

// 0 Setores -> bloqueia.
const semSetor = resolveSolicitanteSetorState([]);
assert.equal(semSetor.bloqueado, true);
assert.deepEqual(semSetor.opcoes, []);
assert.equal(semSetor.valorInicial, '');

// 1 Setor -> automático (não bloqueado, valor inicial já preenchido).
const umSetor = resolveSolicitanteSetorState([{ id: 7, nome: 'LOGISTICA', principal: true }]);
assert.equal(umSetor.bloqueado, false);
assert.equal(umSetor.opcoes.length, 1);
assert.equal(umSetor.valorInicial, '7');

// Vários Setores -> não bloqueado, usuário escolhe (sem valor pré-definido).
const variosSetores = resolveSolicitanteSetorState([
  { id: 1, nome: 'RECURSOS HUMANOS', principal: true },
  { id: 9, nome: 'TECNOLOGIA DA INFORMACAO', principal: false },
]);
assert.equal(variosSetores.bloqueado, false);
assert.equal(variosSetores.opcoes.length, 2);
assert.equal(variosSetores.valorInicial, '');

// Entrada inválida (não-array) é tratada como lista vazia -> bloqueado.
assert.equal(resolveSolicitanteSetorState(undefined).bloqueado, true);
assert.equal(resolveSolicitanteSetorState(null).bloqueado, true);

// Centro de Custo depende só do Setor (nunca do Cargo).
const centrosPorSetor = {
  1: [{ id: 100, codigo: 'CC-001', nome: 'RH Matriz' }],
  9: [],
};
assert.deepEqual(resolveCentrosCustoParaSetor(centrosPorSetor, '1'), [{ id: 100, codigo: 'CC-001', nome: 'RH Matriz' }]);
assert.deepEqual(resolveCentrosCustoParaSetor(centrosPorSetor, 1), [{ id: 100, codigo: 'CC-001', nome: 'RH Matriz' }]);
assert.deepEqual(resolveCentrosCustoParaSetor(centrosPorSetor, '9'), []);
assert.deepEqual(resolveCentrosCustoParaSetor(centrosPorSetor, '999'), []);
assert.deepEqual(resolveCentrosCustoParaSetor(null, '1'), []);
assert.deepEqual(resolveCentrosCustoParaSetor(undefined, ''), []);

console.log('OK unit solicitacao-vaga-form');
