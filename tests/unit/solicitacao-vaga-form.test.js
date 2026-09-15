const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const adminJsModule = require('../../public/assets/admin.js');
const { resolveSolicitanteSetorState, resolveCentrosCustoParaSetor, resolveCargosParaSetor, resolveCargosComFallback, deveResincronizarContextoDoSolicitante } = adminJsModule;

// Sprint Solicitação de Vaga — matriz oficial Cargo x Setor (espelho técnico do METADADOS,
// `cargo_setores_metadados`): Setor selecionado -> só os Cargos oficialmente vinculados àquele
// Setor aparecem. Setor sem NENHUM Cargo vinculado: usuário comum é bloqueado; Admin/RH/supervisor
// master ATOR ganha um fallback administrativo (correção 2026-09-14, §3) para o catálogo completo
// de Cargos oficiais — nunca para usuário comum, nunca incluindo Cargo legado. O Setor da vaga vem
// do contexto de Setores do Usuário solicitante (usuario_setores).

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
// Cargos oficialmente vinculados a ele no METADADOS; Setor sem nenhum vínculo -> lista vazia (a
// decisão de fallback administrativo vive em resolveCargosComFallback, abaixo — esta função pura
// nunca decide fallback sozinha).
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
// Fallback administrativo (correção 2026-09-14, §3): Setor sem NENHUMA relação na matriz não
// bloqueia Admin/RH/supervisor master ATOR — libera o catálogo completo de Cargos oficiais só
// para aquela solicitação. Usuário comum (podeFallback=false) continua bloqueado.
const cargosFallback = [
  { id: 900, nome: 'MECANICO', salario_min: 2000, salario_max: 3000, requires_machine_description: false },
  { id: 901, nome: 'SOLDADOR', salario_min: 2200, salario_max: 3200, requires_machine_description: false },
];

// Setor COM matriz -> usa a matriz, ignora o fallback mesmo se disponível.
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '4', true, cargosFallback),
  { cargos: cargosPorSetor[4], usaFallback: false }
);

// Setor SEM matriz + ator pode fallback + catálogo de fallback não vazio -> usa o fallback.
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '6', true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true }
);

// Setor SEM matriz + usuário comum (podeFallback=false) -> continua bloqueado, sem fallback.
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '6', false, cargosFallback),
  { cargos: [], usaFallback: false }
);

// Setor SEM matriz + ator pode fallback, mas catálogo de fallback vazio -> continua bloqueado.
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '6', true, []),
  { cargos: [], usaFallback: false }
);

// Setor sem entrada nenhuma na matriz (nunca visto) se comporta como Setor vazio.
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '999', true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true }
);

// ---------------------------------------------------------------------------
// Investigação "BUG 2 isolado na camada JavaScript" (2026-09-14): formas alternativas de
// "Setor sem matriz" que o backend PODE, em tese, produzir — chave ausente, `undefined`, `null`,
// `[]` explícito — todas precisam ser tratadas como matriz ausente (nunca como "matriz existe
// mas está vazia" de um jeito que trave o fallback).
const cargosPorSetorFormasVazias = {
  '6': [],
  '7': undefined,
  '8': null,
};
for (const setorId of ['6', '7', '8', '999']) {
  assert.deepEqual(
    resolveCargosComFallback(cargosPorSetorFormasVazias, setorId, true, cargosFallback),
    { cargos: cargosFallback, usaFallback: true },
    `Setor ${setorId} sem matriz (forma: ${JSON.stringify(cargosPorSetorFormasVazias[setorId])}) -> fallback administrativo`
  );
  assert.deepEqual(
    resolveCargosComFallback(cargosPorSetorFormasVazias, setorId, false, cargosFallback),
    { cargos: [], usaFallback: false },
    `Setor ${setorId} sem matriz + usuário comum -> bloqueio, nunca fallback`
  );
}

// Estrutura REAL obtida diretamente de SolicitacaoVaga::contextoOrganizacionalSolicitante() para
// uma fixture Fabiane-like com RH (matriz não verificada aqui) + um Setor "MANUTENÇÃO" (codigo_setor
// '12') sem nenhuma linha em cargo_setores_metadados — dump literal do JSON gerado pelo backend,
// não uma forma fabricada à mão. As chaves vêm como STRING (JSON não tem chave numérica).
const cargosPorSetorReal = { '964': [], '963': [] };
const setorManutencaoIdReal = '963';
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetorReal, setorManutencaoIdReal, true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true },
  'Estrutura real (dump do backend) para MANUTENÇÃO sem matriz -> fallback administrativo'
);

// Ator administrativo + troca de solicitante: podeFallback/cargosFallback são constantes do ATOR
// (nunca recalculadas ao trocar solicitante) — chamar resolveCargosComFallback duas vezes com
// cargosPorSetor DIFERENTES (simulando dois solicitantes distintos) mas os MESMOS podeFallback/
// cargosFallback precisa continuar habilitando o fallback nas duas.
const cargosPorSetorSolicitanteA = { '10': [] };
const cargosPorSetorSolicitanteB = { '20': [] };
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetorSolicitanteA, '10', true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true },
  'ator admin + solicitante A + Setor sem matriz -> fallback'
);
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetorSolicitanteB, '20', true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true },
  'mesmo ator (podeFallback/cargosFallback inalterados) + solicitante B diferente + Setor sem matriz -> fallback continua disponível'
);

// Troca de Setor: sem matriz -> fallback; com matriz -> matriz prevalece (nunca mistura as duas
// respostas nem preserva o resultado da chamada anterior).
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '6', true, cargosFallback),
  { cargos: cargosFallback, usaFallback: true },
  'troca de Setor (1): sem matriz -> fallback'
);
assert.deepEqual(
  resolveCargosComFallback(cargosPorSetor, '4', true, cargosFallback),
  { cargos: cargosPorSetor['4'], usaFallback: false },
  'troca de Setor (2): Setor com matriz -> matriz prevalece, cargo anterior (fallback) não vaza'
);

// ---------------------------------------------------------------------------
// Correção "contexto inicial incorreto" (2026-09-14, validado em produção com cache desativado):
// reproduz literalmente o caso real — payload/contexto inicial de um usuário, <select> de
// Solicitante restaurado pelo navegador em OUTRO valor, sem 'change'. `deveResincronizarContexto
// DoSolicitante` decide SE é preciso buscar de novo (Cenários 1-3 da investigação); quem busca
// continua sendo sempre `carregarContextoDoSolicitante()` — token de sequência, retry e bloqueio
// em falha persistente são preservados sem alteração nesta correção.

// Cenário 1 — payload/contexto = Fabio (usuario_id=1), <select> restaurado em Fabiane ("76"):
// precisa detectar a divergência e recarregar o usuário 76.
assert.equal(
  deveResincronizarContextoDoSolicitante('76', 1),
  true,
  'Cenário 1: payload Fabio (usuario_id=1) + select restaurado em Fabiane (76) -> precisa resincronizar'
);

// Cenário 2 — payload/contexto = Fabiane (76), <select> também em Fabiane (76): já sincronizados,
// nenhuma requisição adicional.
assert.equal(
  deveResincronizarContextoDoSolicitante('76', '76'),
  false,
  'Cenário 2: payload Fabiane (76) + select Fabiane (76) -> já sincronizados, sem requisição adicional'
);

// Cenário 3 — payload/contexto = Fabiane (76), <select> restaurado em Fabio ("1"): precisa
// detectar a divergência e recarregar o usuário 1 (o valor do <select> sempre prevalece).
assert.equal(
  deveResincronizarContextoDoSolicitante('1', 76),
  true,
  'Cenário 3: payload Fabiane (76) + select restaurado em Fabio (1) -> precisa resincronizar para carregar o Fabio'
);

// <select> sem valor (Setor bloqueado / nenhum candidato elegível) -> nada para resincronizar.
assert.equal(
  deveResincronizarContextoDoSolicitante('', 1),
  false,
  '<select> sem valor -> nada para resincronizar'
);

// Caso normal (sem restauração do navegador): payload e select já concordam desde o início.
assert.equal(
  deveResincronizarContextoDoSolicitante('1', 1),
  false,
  'payload e select já concordam desde o início -> sem requisição adicional'
);

// Cenários 4-6 (troca rápida com token de sequência, retry em falha, bloqueio em falha
// persistente) dependem de fetch/DOM reais e continuam cobertos pela MESMA implementação de
// `carregarContextoDoSolicitante()` já testada/validada em produção nesta sprint — esta correção
// não a modifica, apenas passa a chamá-la também na inicialização (via
// `deveResincronizarContextoDoSolicitante`), não só no evento 'change'. Sem jsdom neste projeto,
// a mecânica de fetch/DOM não é unit-testável em isolamento (mesma convenção já adotada para o
// restante da inicialização do formulário).

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
