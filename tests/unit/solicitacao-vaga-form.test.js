const assert = require('node:assert/strict');
const { resolveSolicitacaoCargoState } = require('../../public/assets/admin.js');

const cargos = [
  { id: 10, nome: 'Auxiliar Administrativo', setor_ids: [1] },
  { id: 11, nome: 'Analista Financeiro', setor_ids: [1, 2] },
  { id: 12, nome: 'Supervisor de Produção', setor_ids: [3] },
];

const primeiroSetor = resolveSolicitacaoCargoState({
  cargos,
  setorId: 1,
  selectedCargoId: '',
  preserveSelection: false,
});
assert.equal(primeiroSetor.availableCargos.length, 2);
assert.deepEqual(primeiroSetor.availableCargos.map((item) => item.id), [10, 11]);
assert.equal(primeiroSetor.disabled, false);
assert.equal(primeiroSetor.selectedCargoId, '');

const trocaDeSetor = resolveSolicitacaoCargoState({
  cargos,
  setorId: 2,
  selectedCargoId: '',
  preserveSelection: false,
});
assert.equal(trocaDeSetor.availableCargos.length, 1);
assert.deepEqual(trocaDeSetor.availableCargos.map((item) => item.id), [11]);

const trocaComCargoPreSelecionado = resolveSolicitacaoCargoState({
  cargos,
  setorId: 3,
  selectedCargoId: 11,
  preserveSelection: false,
});
assert.equal(trocaComCargoPreSelecionado.selectedCargoId, '');
assert.deepEqual(trocaComCargoPreSelecionado.availableCargos.map((item) => item.id), [12]);

const setorSemCargos = resolveSolicitacaoCargoState({
  cargos,
  setorId: 99,
  selectedCargoId: '',
  preserveSelection: false,
});
assert.equal(setorSemCargos.disabled, true);
assert.equal(setorSemCargos.placeholder, 'Nenhum cargo disponível para este setor');
assert.equal(setorSemCargos.invalidMessage, 'Nenhum cargo disponível para este setor.');

console.log('OK unit solicitacao-vaga-form');
