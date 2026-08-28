<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_colaborador_metadados_sync (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
$tableExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados'"
)->fetchColumn();
if ($tableExists === 0) {
    echo "SKIP integration_colaborador_metadados_sync (migration 2026-08-27-colaboradores-metadados.sql nao aplicada)\n";
    exit(0);
}
$hasSalarioCargo = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'salario_atual'"
)->fetchColumn();
if ($hasSalarioCargo === 0) {
    echo "SKIP integration_colaborador_metadados_sync (migration 2026-08-27-colaboradores-metadados-salario-cargo.sql nao aplicada)\n";
    exit(0);
}

// Este teste valida applyRows() — a metade da sincronização que independe de SQL Server/pdo_sqlsrv.
// fetchSourceRows() não é testável neste ambiente (driver ausente, ver auditoria da sprint).
$service = new MetadadosSyncService();
$sufixo = (string)time() . (string)random_int(100, 999);
$empresa = 'EMP' . $sufixo;
$unidade = 'UNI' . $sufixo;

$contrato1 = [
    'identificador' => "$empresa-$unidade-001",
    'codigo_empresa' => $empresa,
    'codigo_unidade' => $unidade,
    'numero_contrato' => '001',
    'codigo_pessoa' => 'PES' . $sufixo,
    'cpf' => '11122233344',
    'nome' => 'Colaborador Teste Sync',
    'empresa' => 'Empresa Teste',
    'nascimento' => '1990-05-10',
    'admissao' => '2020-01-01',
    'cargo' => 'Analista',
    'demissao' => '2022-06-30',
    'motivo_rescisao_codigo' => '01',
    'motivo_rescisao_descricao' => 'Pedido de demissão',
    'unidade' => 'Unidade Teste',
    'setor' => 'Setor Teste',
    'centro_custo' => 'CC-001',
    'ativo' => 0,
    'salario_atual' => '3500.00',
    'data_inicio_cargo' => '2020-01-01',
    'atualizado_em_origem' => null,
];

// Contrato 2: mesma pessoa (mesmo codigo_pessoa/cpf), readmitida — numero_contrato diferente.
$contrato2 = $contrato1;
$contrato2['identificador'] = "$empresa-$unidade-002";
$contrato2['numero_contrato'] = '002';
$contrato2['demissao'] = null;
$contrato2['motivo_rescisao_codigo'] = null;
$contrato2['motivo_rescisao_descricao'] = null;
$contrato2['ativo'] = 1;
$contrato2['admissao'] = '2024-01-15';

try {
    // 1) Primeira sincronização: dois contratos novos → 2 inserted.
    $summary = $service->applyRows([$contrato1, $contrato2]);
    $assert($summary['inserted'] === 2, 'Falha: deveria inserir os 2 contratos (readmissão preservada).');
    $assert($summary['errors'] === 0, 'Falha: nao deveria haver erros na primeira sincronizacao.');

    $repo = new ColaboradorMetadadosRepository($pdo);
    $row1 = $repo->findByVinculo($empresa, $unidade, '001');
    $row2 = $repo->findByVinculo($empresa, $unidade, '002');
    $assert($row1 !== null && $row2 !== null, 'Falha: os dois contratos deveriam existir como linhas separadas.');
    $assert($row1['cpf'] === $row2['cpf'], 'Falha: readmissão deveria manter o mesmo CPF em contratos diferentes.');
    $assert((int)$row1['ativo'] === 0 && (int)$row2['ativo'] === 1, 'Falha: ativo deveria refletir cada contrato independentemente.');
    $assert((float)$row1['salario_atual'] === 3500.00, 'Falha: salario_atual deveria ter sido persistido na inserção.');
    $assert($row1['data_inicio_cargo'] === '2020-01-01', 'Falha: data_inicio_cargo deveria ter sido persistida na inserção.');

    // 2) Rodar de novo com os mesmos dados → nada muda (idempotente).
    $summary2 = $service->applyRows([$contrato1, $contrato2]);
    $assert($summary2['unchanged'] === 2, 'Falha: rodar com os mesmos dados nao deveria gerar UPDATE.');
    $assert($summary2['inserted'] === 0, 'Falha: nao deveria inserir de novo.');

    // 3) Mudar um campo do contrato 1 (ex.: cargo mudou na origem) → deve gerar update, só nele.
    $contrato1Alterado = $contrato1;
    $contrato1Alterado['cargo'] = 'Analista Sênior';
    $summary3 = $service->applyRows([$contrato1Alterado, $contrato2]);
    $assert($summary3['updated'] === 1, 'Falha: deveria atualizar so o contrato 1.');
    $assert($summary3['unchanged'] === 1, 'Falha: contrato 2 deveria permanecer unchanged.');
    $row1Atualizado = $repo->findByVinculo($empresa, $unidade, '001');
    $assert($row1Atualizado['cargo'] === 'Analista Sênior', 'Falha: cargo deveria ter sido atualizado.');

    // 4) Linha inválida (sem nome) não derruba o lote inteiro — é contada em errors.
    $contratoInvalido = $contrato1;
    $contratoInvalido['numero_contrato'] = '003';
    $contratoInvalido['identificador'] = "$empresa-$unidade-003";
    $contratoInvalido['nome'] = '';
    $summary4 = $service->applyRows([$contratoInvalido, $contrato2]);
    $assert($summary4['errors'] === 1, 'Falha: linha sem nome deveria contar como erro.');
    $assert($summary4['unchanged'] === 1, 'Falha: o restante do lote deveria continuar processando normalmente.');
    $assert($repo->findByVinculo($empresa, $unidade, '003') === null, 'Falha: linha invalida nao deveria ter sido persistida.');

    // 5) Alteração salarial: mesmo contrato, salario_atual diferente → updated, novo valor persistido.
    $contrato1NovoSalario = $contrato1Alterado;
    $contrato1NovoSalario['salario_atual'] = '4200.00';
    $summary5 = $service->applyRows([$contrato1NovoSalario, $contrato2]);
    $assert($summary5['updated'] === 1, 'Falha: mudança de salário deveria gerar update.');
    $assert($summary5['unchanged'] === 1, 'Falha: contrato 2 não deveria ser afetado pela mudança de salário do contrato 1.');
    $row1NovoSalario = $repo->findByVinculo($empresa, $unidade, '001');
    $assert((float)$row1NovoSalario['salario_atual'] === 4200.00, 'Falha: novo salário deveria ter sido persistido.');

    // 5b) Rodar de novo com o mesmo salário → idempotente, sem update espúrio por formatação decimal.
    $summary5b = $service->applyRows([$contrato1NovoSalario, $contrato2]);
    $assert($summary5b['updated'] === 0, 'Falha: repetir o mesmo salario_atual não deveria gerar update (comparação numérica, não textual).');
    $assert($summary5b['unchanged'] === 2, 'Falha: os dois contratos deveriam estar unchanged após repetir o mesmo lote.');

    // 6) Alteração de início no cargo: mesmo contrato, data_inicio_cargo diferente → updated.
    $contrato1NovaData = $contrato1NovoSalario;
    $contrato1NovaData['data_inicio_cargo'] = '2025-02-01';
    $summary6 = $service->applyRows([$contrato1NovaData, $contrato2]);
    $assert($summary6['updated'] === 1, 'Falha: mudança de data_inicio_cargo deveria gerar update.');
    $row1NovaData = $repo->findByVinculo($empresa, $unidade, '001');
    $assert($row1NovaData['data_inicio_cargo'] === '2025-02-01', 'Falha: nova data_inicio_cargo deveria ter sido persistida.');
    $assert($row1NovaData['admissao'] === $contrato1NovaData['admissao'], 'Falha (sanity check): admissao não deveria ter sido afetada pela mudança de data_inicio_cargo.');

    // 7) Transição de valor preenchido para NULL deve ser detectada como mudança (e vice-versa).
    $contrato1SalarioNulo = $contrato1NovaData;
    $contrato1SalarioNulo['salario_atual'] = null;
    $summary7 = $service->applyRows([$contrato1SalarioNulo, $contrato2]);
    $assert($summary7['updated'] === 1, 'Falha: salario_atual passando de preenchido para null deveria gerar update.');
    $row1SalarioNulo = $repo->findByVinculo($empresa, $unidade, '001');
    $assert($row1SalarioNulo['salario_atual'] === null, 'Falha: salario_atual deveria ter sido persistido como null.');

    $summary7b = $service->applyRows([$contrato1NovaData, $contrato2]);
    $assert($summary7b['updated'] === 1, 'Falha: salario_atual voltando de null para preenchido também deveria gerar update.');
} finally {
    $pdo->prepare('DELETE FROM colaboradores_metadados WHERE codigo_empresa = ? AND codigo_unidade = ?')
        ->execute([$empresa, $unidade]);
}

echo "OK integration_colaborador_metadados_sync\n";
