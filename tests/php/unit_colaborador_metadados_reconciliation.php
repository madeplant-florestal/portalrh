<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

// Todos os dados abaixo são fictícios — nenhum CPF/nome real. O service nunca toca banco quando
// usado assim (construtor sem PDO real + chamada direta a analyze()).
$service = new ColaboradorMetadadosReconciliationService();

function localRow(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'cpf' => '11122233344',
        'nome' => 'Fulano de Teste',
        'data_admissao' => '2024-01-15',
        'data_demissao' => null,
        'data_nascimento' => '1990-05-10',
        'ativo' => 1,
        'metadados_id' => null,
    ], $overrides);
}

function mirrorRow(array $overrides = []): array
{
    return array_merge([
        'id' => 100,
        'cpf' => '11122233344',
        'nome' => 'Fulano de Teste',
        'admissao' => '2024-01-15',
        'demissao' => null,
        'nascimento' => '1990-05-10',
        'ativo' => 1,
    ], $overrides);
}

// Caso 1 — correspondência segura simples.
$r1 = $service->analyze([localRow()], [mirrorRow()])[0];
$assert($r1['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Caso 1: esperado CORRESPONDENCIA_SEGURA, veio ' . $r1['classificacao']);
$assert($r1['metadados_id_candidato'] === 100, 'Caso 1: metadados_id_candidato deveria ser 100.');

// Caso 2 — readmissão distinguida pela admissão (dois contratos oficiais, local bate com o de 2024).
$mirror2020 = mirrorRow(['id' => 201, 'admissao' => '2020-01-01', 'demissao' => '2022-06-30']);
$mirror2024 = mirrorRow(['id' => 202, 'admissao' => '2024-01-15', 'demissao' => null]);
$r2 = $service->analyze([localRow(['data_admissao' => '2024-01-15'])], [$mirror2020, $mirror2024])[0];
$assert($r2['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Caso 2: esperado SEGURA, veio ' . $r2['classificacao']);
$assert($r2['metadados_id_candidato'] === 202, 'Caso 2: deveria escolher o contrato de 2024 (id 202), não o de 2020.');
$assert($r2['quantidade_candidatos'] === 2, 'Caso 2: quantidade_candidatos deveria refletir os 2 contratos do CPF.');

// Caso 3 — readmissão ambígua (local sem admissão suficiente para distinguir).
$r3 = $service->analyze([localRow(['data_admissao' => null])], [$mirror2020, $mirror2024])[0];
$assert($r3['classificacao'] === ColaboradorMetadadosReconciliationService::AMBIGUA, 'Caso 3: esperado AMBIGUA, veio ' . $r3['classificacao']);
$assert($r3['quantidade_candidatos'] === 2, 'Caso 3: quantidade_candidatos deveria ser 2 (não 0) — regressão do bug de relatório do operador "+".');

// Caso 4 — sem correspondência (CPF local não existe no espelho).
$r4 = $service->analyze([localRow(['cpf' => '99988877766'])], [mirrorRow()])[0];
$assert($r4['classificacao'] === ColaboradorMetadadosReconciliationService::SEM_CORRESPONDENCIA, 'Caso 4: esperado SEM_CORRESPONDENCIA, veio ' . $r4['classificacao']);

// Caso 5 — CPF igual, admissão divergente, único candidato → PROVAVEL (nunca SEGURA automática).
$r5 = $service->analyze([localRow(['data_admissao' => '2023-03-01'])], [mirrorRow(['admissao' => '2024-01-15'])])[0];
$assert($r5['classificacao'] === ColaboradorMetadadosReconciliationService::PROVAVEL, 'Caso 5: esperado CORRESPONDENCIA_PROVAVEL, veio ' . $r5['classificacao']);

// Caso 6 — nome divergente com CPF+admissão iguais continua SEGURA, mas registra a divergência.
$r6 = $service->analyze([localRow(['nome' => 'Nome Bem Diferente'])], [mirrorRow(['nome' => 'Fulano de Teste'])])[0];
$assert($r6['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Caso 6: esperado SEGURA mesmo com nome divergente, veio ' . $r6['classificacao']);
$assert($r6['nome_compativel'] === false, 'Caso 6: nome_compativel deveria ser false (divergência registrada).');

// Caso 7 — ativo divergente com CPF+admissão iguais continua SEGURA, registrando a divergência.
$r7 = $service->analyze([localRow(['ativo' => 0])], [mirrorRow(['ativo' => 1])])[0];
$assert($r7['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Caso 7: esperado SEGURA mesmo com situação divergente, veio ' . $r7['classificacao']);
$assert($r7['situacao_compativel'] === false, 'Caso 7: situacao_compativel deveria ser false.');

// Caso 8 — nascimento claramente incompatível, mesmo com CPF+admissão iguais → CONFLITO.
$r8 = $service->analyze([localRow(['data_nascimento' => '1990-05-10'])], [mirrorRow(['nascimento' => '1985-11-20'])])[0];
$assert($r8['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Caso 8: esperado CONFLITO por nascimento incompatível, veio ' . $r8['classificacao']);

// Caso 9 — vínculo existente válido → JA_VINCULADO.
$r9 = $service->analyze([localRow(['metadados_id' => 100])], [mirrorRow(['id' => 100])])[0];
$assert($r9['classificacao'] === ColaboradorMetadadosReconciliationService::JA_VINCULADO, 'Caso 9: esperado JA_VINCULADO, veio ' . $r9['classificacao']);

// Caso 10 — vínculo existente aponta para vínculo oficial com CPF incompatível → CONFLITO.
$r10 = $service->analyze(
    [localRow(['metadados_id' => 100, 'cpf' => '11122233344'])],
    [mirrorRow(['id' => 100, 'cpf' => '55566677788'])]
)[0];
$assert($r10['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Caso 10: esperado CONFLITO, veio ' . $r10['classificacao']);

// Extra — metadados_id aponta para um id inexistente no espelho → CONFLITO (vínculo pendurado).
$rExtraDangling = $service->analyze([localRow(['metadados_id' => 999])], [mirrorRow(['id' => 100])])[0];
$assert($rExtraDangling['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: metadados_id pendurado deveria ser CONFLITO.');

// Extra — dois colaboradores locais apontando para o mesmo metadados_id → ambos viram CONFLITO.
$duploA = localRow(['id' => 1, 'metadados_id' => 100]);
$duploB = localRow(['id' => 2, 'metadados_id' => 100]);
$resultadosDuplo = $service->analyze([$duploA, $duploB], [mirrorRow(['id' => 100])]);
$assert($resultadosDuplo[0]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: primeiro registro duplicado deveria virar CONFLITO.');
$assert($resultadosDuplo[1]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: segundo registro duplicado deveria virar CONFLITO.');

// Extra — normalizeCpf preserva zero à esquerda e rejeita tamanho inválido.
$assert(ColaboradorMetadadosReconciliationService::normalizeCpf('011.222.333-44') === '01122233344', 'Extra: normalizeCpf deveria preservar zero à esquerda e remover formatação.');
$assert(ColaboradorMetadadosReconciliationService::normalizeCpf('123') === null, 'Extra: CPF com menos de 11 dígitos deveria ser inválido (null).');

echo "OK unit_colaborador_metadados_reconciliation\n";
