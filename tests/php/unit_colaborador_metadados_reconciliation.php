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

// Extra — colisão genérica: dois colaboradores locais com o MESMO CPF (duplicidade conhecida da
// base local — a mesma pessoa com duas linhas de contrato) resolvendo ao único candidato do
// espelho viram CONFLITO, mesmo sem nenhum id fixo em comum além do CPF. Cobre o cenário real
// encontrado na Fase 3.4 (dois colaboradores locais, mesmo CPF, um único contrato sincronizado
// no espelho) sem depender de nenhum id específico.
$cpfDuplicadoLocal = '22233344455';
$mirrorUnico = mirrorRow(['id' => 300, 'cpf' => $cpfDuplicadoLocal, 'admissao' => '2019-04-01']);

// Ambos SEGURA (mesma admissão local, coincidência plausível quando o cadastro local duplicou o
// contrato) — nenhum dos dois pode ser aplicado automaticamente.
$colisaoSeguraA = localRow(['id' => 11, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$colisaoSeguraB = localRow(['id' => 12, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$resultadosColisaoSegura = $service->analyze([$colisaoSeguraA, $colisaoSeguraB], [$mirrorUnico]);
$assert($resultadosColisaoSegura[0]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão SEGURA x SEGURA no mesmo metadados_id deveria virar CONFLITO (colaborador 1).');
$assert($resultadosColisaoSegura[1]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão SEGURA x SEGURA no mesmo metadados_id deveria virar CONFLITO (colaborador 2).');

// Um SEGURA (admissão bate) e outro PROVAVEL (admissão não bate) para o mesmo candidato único —
// colisão mista também precisa virar CONFLITO nos dois lados, não só bloquear o SEGURA.
$colisaoMistaSegura = localRow(['id' => 13, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$colisaoMistaProvavel = localRow(['id' => 14, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2017-01-10']);
$resultadosColisaoMista = $service->analyze([$colisaoMistaSegura, $colisaoMistaProvavel], [$mirrorUnico]);
$assert($resultadosColisaoMista[0]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão SEGURA x PROVAVEL deveria virar CONFLITO no lado SEGURA.');
$assert($resultadosColisaoMista[1]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão SEGURA x PROVAVEL deveria virar CONFLITO no lado PROVAVEL.');

// Colisão de 3 (generalização N >= 2, não só pares) — todos os disputantes viram CONFLITO.
$colisaoTriplaA = localRow(['id' => 15, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$colisaoTriplaB = localRow(['id' => 16, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$colisaoTriplaC = localRow(['id' => 17, 'cpf' => $cpfDuplicadoLocal, 'data_admissao' => '2019-04-01']);
$resultadosColisaoTripla = $service->analyze([$colisaoTriplaA, $colisaoTriplaB, $colisaoTriplaC], [$mirrorUnico]);
foreach ($resultadosColisaoTripla as $i => $r) {
    $assert($r['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, "Extra: colisão tripla deveria virar CONFLITO em todos os disputantes (índice {$i}).");
}

// Colisão entre um vínculo JÁ existente (JA_VINCULADO) e uma correspondência nova (SEGURA) para o
// mesmo metadados_id — cenário futuro (Fase 5) de readmissão parcialmente vinculada; os dois lados
// precisam virar CONFLITO, não só o lado novo.
$mirrorJaVinculado = mirrorRow(['id' => 400, 'cpf' => '33344455566', 'admissao' => '2021-02-01']);
$colaboradorJaVinculado = localRow(['id' => 18, 'cpf' => '33344455566', 'data_admissao' => '2021-02-01', 'metadados_id' => 400]);
$colaboradorNovoMesmoAlvo = localRow(['id' => 19, 'cpf' => '33344455566', 'data_admissao' => '2021-02-01']);
$resultadosColisaoMistaVinculo = $service->analyze([$colaboradorJaVinculado, $colaboradorNovoMesmoAlvo], [$mirrorJaVinculado]);
$assert($resultadosColisaoMistaVinculo[0]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão JA_VINCULADO x SEGURA deveria virar CONFLITO no lado já vinculado.');
$assert($resultadosColisaoMistaVinculo[1]['classificacao'] === ColaboradorMetadadosReconciliationService::CONFLITO, 'Extra: colisão JA_VINCULADO x SEGURA deveria virar CONFLITO no lado novo.');

// Negativo — dois colaboradores com CPFs (e candidatos) DIFERENTES nunca deveriam se afetar; sem
// falso positivo por causa da nova checagem.
$resultadosSemColisao = $service->analyze(
    [localRow(['id' => 20, 'cpf' => '11122233344']), localRow(['id' => 21, 'cpf' => '99988877766'])],
    [mirrorRow(['id' => 500, 'cpf' => '11122233344']), mirrorRow(['id' => 501, 'cpf' => '99988877766'])]
);
$assert($resultadosSemColisao[0]['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Extra: sem colisão real, colaborador 1 deveria continuar SEGURA.');
$assert($resultadosSemColisao[1]['classificacao'] === ColaboradorMetadadosReconciliationService::SEGURA, 'Extra: sem colisão real, colaborador 2 deveria continuar SEGURA.');

// Negativo — AMBIGUA/SEM_CORRESPONDENCIA nunca têm metadados_id_candidato preenchido, então nunca
// deveriam ser agrupadas pela checagem de colisão (regressão contra falso positivo por null).
$resultadosSemCandidato = $service->analyze(
    [localRow(['id' => 22, 'cpf' => '00000000000']), localRow(['id' => 23, 'cpf' => '00000000001'])],
    [mirrorRow(['id' => 600, 'cpf' => '77788899900'])]
);
$assert($resultadosSemCandidato[0]['classificacao'] === ColaboradorMetadadosReconciliationService::SEM_CORRESPONDENCIA, 'Extra: sem CPF em comum, deveria continuar SEM_CORRESPONDENCIA (nenhuma colisão espúria).');
$assert($resultadosSemCandidato[1]['classificacao'] === ColaboradorMetadadosReconciliationService::SEM_CORRESPONDENCIA, 'Extra: sem CPF em comum, deveria continuar SEM_CORRESPONDENCIA (nenhuma colisão espúria).');

// Extra — normalizeCpf preserva zero à esquerda e rejeita tamanho inválido.
$assert(ColaboradorMetadadosReconciliationService::normalizeCpf('011.222.333-44') === '01122233344', 'Extra: normalizeCpf deveria preservar zero à esquerda e remover formatação.');
$assert(ColaboradorMetadadosReconciliationService::normalizeCpf('123') === null, 'Extra: CPF com menos de 11 dígitos deveria ser inválido (null).');

echo "OK unit_colaborador_metadados_reconciliation\n";
