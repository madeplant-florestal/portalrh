const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const adminJsModule = require('../../public/assets/admin.js');
const { resolveSolicitanteSetorState, resolveCentrosCustoParaSetor, resolveCargosParaSetor } = adminJsModule;

// Sprint Solicitação de Vaga — matriz oficial Cargo x Setor (espelho técnico do METADADOS,
// `cargo_setores_metadados`): Setor selecionado -> só os Cargos oficialmente vinculados àquele
// Setor aparecem; Setor sem nenhum Cargo vinculado bloqueia o campo (nunca cai para todos os
// Cargos). O Setor da vaga vem do contexto de Setores do Usuário solicitante (usuario_setores).

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

// ---------------------------------------------------------------------------
// Cargo depende do Setor via a matriz oficial (cargo_setores_metadados): cada Setor só expõe os
// Cargos oficialmente vinculados a ele no METADADOS; Setor sem nenhum vínculo -> lista vazia
// (bloqueio na UI), nunca um fallback para o catálogo completo de Cargos.
const cargosPorSetor = {
  4: [
    { id: 200, nome: 'TECNICO DE INFORMATICA', salario_min: 2000, salario_max: 3000, requires_machine_description: false },
    { id: 201, nome: 'ANALISTA DE TI', salario_min: 3000, salario_max: 5000, requires_machine_description: false },
  ],
  6: [],
};
assert.deepEqual(resolveCargosParaSetor(cargosPorSetor, '4'), cargosPorSetor[4]);
assert.deepEqual(resolveCargosParaSetor(cargosPorSetor, 4), cargosPorSetor[4]);
assert.deepEqual(resolveCargosParaSetor(cargosPorSetor, '6'), []);
assert.deepEqual(resolveCargosParaSetor(cargosPorSetor, '999'), [], 'Setor sem entrada na matriz -> lista vazia, não erro');
assert.deepEqual(resolveCargosParaSetor(null, '4'), []);
assert.deepEqual(resolveCargosParaSetor(undefined, ''), []);

// ---------------------------------------------------------------------------
// Regressão travada (correção de direção 2026-09-14): a versão anterior (Etapa 2 / hotfix
// 2026-09-11) tratava Cargo como catálogo GLOBAL independente do Setor. Essa regra foi revertida
// — o nome/mensagem antigos abaixo NUNCA podem voltar, e `resolveCargosParaSetor` precisa
// continuar exportado como a função vigente de Cargo-por-Setor.
assert.equal(typeof resolveCargosParaSetor, 'function', 'resolveCargosParaSetor precisa estar exportado (regra vigente: Cargo depende do Setor)');
assert.equal('filterSolicitacaoCargosBySetor' in adminJsModule, false, 'filterSolicitacaoCargosBySetor (nome antigo, pré-Etapa-2) não pode voltar a ser exportado');
assert.equal('resolveSolicitacaoCargoState' in adminJsModule, false, 'resolveSolicitacaoCargoState (nome antigo, pré-Etapa-2) não pode voltar a ser exportado');

const stringsProibidas = [
  'filterSolicitacaoCargosBySetor',
  'resolveSolicitacaoCargoState',
  'Nenhum cargo disponível para este setor',
  'Selecione uma área/departamento primeiro',
];
for (const arquivo of ['../../assets/admin.js', '../../public/assets/admin.js']) {
  const conteudo = fs.readFileSync(path.join(__dirname, arquivo), 'utf8');
  for (const proibida of stringsProibidas) {
    assert.equal(conteudo.includes(proibida), false, `${arquivo} não pode conter "${proibida}" (implementação antiga de Cargo por Setor)`);
  }
}

// Os dois artefatos (assets/admin.js e public/assets/admin.js) precisam ficar byte-idênticos —
// não há build step que os sincronize automaticamente.
assert.equal(
  fs.readFileSync(path.join(__dirname, '../../assets/admin.js'), 'utf8'),
  fs.readFileSync(path.join(__dirname, '../../public/assets/admin.js'), 'utf8'),
  'assets/admin.js e public/assets/admin.js precisam ser idênticos'
);

console.log('OK unit solicitacao-vaga-form');
