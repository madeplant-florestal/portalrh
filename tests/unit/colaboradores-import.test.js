const assert = require('node:assert/strict');
const {
  validateCollaboratorImportFile,
  summarizeCollaboratorImportResult,
} = require('../../public/assets/admin.js');

const validXlsx = validateCollaboratorImportFile({ name: 'colaboradores.xlsx' });
assert.equal(validXlsx.ok, true);
assert.equal(validXlsx.extension, 'xlsx');

const validCsv = validateCollaboratorImportFile({ name: 'colaboradores.csv' });
assert.equal(validCsv.ok, true);
assert.equal(validCsv.extension, 'csv');

const invalidFile = validateCollaboratorImportFile({ name: 'colaboradores.pdf' });
assert.equal(invalidFile.ok, false);
assert.equal(invalidFile.error, 'Formato invalido. Utilize um arquivo .xlsx ou .csv.');

const summary = summarizeCollaboratorImportResult({
  ok: true,
  message: 'Importacao concluida com sucesso.',
  summary: {
    processed: 10,
    inserted: 8,
    updated: 2,
    rejected: 1,
    ignored_blank_rows: 1,
    catalog_companies_created: 2,
    catalog_roles_created: 3,
  },
  warnings: ['CPF repetido tratado como historico.'],
  rejected_records: [{ row_number: 5, causes: ['COD duplicado para a mesma empresa na planilha'] }],
});

assert.equal(summary.tone, 'warning');
assert.equal(summary.badge, 'Processado');
assert.equal(summary.message, 'Importacao concluida com sucesso.');
assert.ok(summary.details.includes('Processados: 10'));
assert.ok(summary.details.includes('Empresas criadas automaticamente: 2'));
assert.ok(summary.details.some((detail) => detail.includes('Linha 5: COD duplicado para a mesma empresa na planilha')));

console.log('OK unit colaboradores-import');
