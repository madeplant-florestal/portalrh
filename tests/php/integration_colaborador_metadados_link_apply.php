<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_colaborador_metadados_link_apply (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

// Lança exceção em vez de exit() — exit() encerra o processo imediatamente e IMPEDE a execução
// de blocos finally, deixando fixtures sintéticas órfãs no banco quando uma asserção falha no
// meio de um cenário. A exceção propaga através dos finally (que sempre limpam) até o catch
// externo, que só then encerra o processo com código de erro.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::conn();
$hasColumn = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores' AND COLUMN_NAME = 'metadados_id'"
)->fetchColumn();
if ($hasColumn === 0) {
    echo "SKIP integration_colaborador_metadados_link_apply (migration 2026-08-28-colaboradores-metadados-id.sql nao aplicada)\n";
    exit(0);
}

$cargo = $pdo->query('SELECT id FROM cargos LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$cargo) {
    echo "SKIP integration_colaborador_metadados_link_apply (nenhum cargo disponivel para fixture)\n";
    exit(0);
}
$cargoId = (int)$cargo['id'];
$sufixo = (string)time() . (string)random_int(100, 999);

/** Cria N linhas ficticias em colaboradores_metadados e retorna os ids gerados. */
function criarMirrors(PDO $pdo, string $sufixo, int $quantidade): array
{
    $ids = [];
    $stmt = $pdo->prepare(
        "INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome)
         VALUES (?,?,?,?,?,?)"
    );
    for ($i = 1; $i <= $quantidade; $i++) {
        $stmt->execute(["EMPL{$sufixo}-{$i}", "EMPL{$sufixo}", "UNIL{$sufixo}", (string)$i, "PESL{$sufixo}{$i}", "Vinculo Teste {$i}"]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

/** Cria N colaboradores ficticios (metadados_id NULL) e retorna os ids gerados. */
function criarColaboradores(PDO $pdo, int $cargoId, string $sufixo, int $quantidade): array
{
    $ids = [];
    $stmt = $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id) VALUES (?,?,?)');
    for ($i = 1; $i <= $quantidade; $i++) {
        $stmt->execute(["Colaborador Link Teste {$sufixo}-{$i}", "colaborador-link-teste-{$sufixo}-{$i}", $cargoId]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

function limpar(PDO $pdo, array $colaboradorIds, array $mirrorIds): void
{
    foreach ($colaboradorIds as $id) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$id]);
    }
    foreach ($mirrorIds as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }
}

$service = new ColaboradorMetadadosLinkService($pdo);

// Todo o corpo dos cenários fica dentro deste try — cada cenário já tem seu próprio
// try/finally de limpeza; este catch externo só é alcançado DEPOIS que o finally do cenário
// que falhou já rodou, e é o único lugar do arquivo que encerra o processo com erro.
try {
// ===== Cenário 1: aplicação válida =====
$mirrors1 = criarMirrors($pdo, $sufixo . 'a', 3);
$colabs1 = criarColaboradores($pdo, $cargoId, $sufixo . 'a', 2);
try {
    $plano = [
        ['colaborador_id' => $colabs1[0], 'metadados_id' => $mirrors1[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs1[1], 'metadados_id' => $mirrors1[1], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $validacaoBanco = $service->validateAgainstDatabase($plano);
    $assert($validacaoBanco['ok'] === true, 'Cenário 1: validação contra banco deveria passar: ' . implode('; ', $validacaoBanco['errors']));

    $resultado = $service->apply($plano);
    $assert($resultado['ok'] === true, 'Cenário 1: aplicação deveria ter sucesso: ' . ($resultado['error'] ?? ''));
    $assert($resultado['aplicados'] === 2, 'Cenário 1: deveria reportar 2 aplicados.');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs1[0]}")->fetch(PDO::FETCH_ASSOC);
    $row1 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs1[1]}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row0['metadados_id'] === $mirrors1[0], 'Cenário 1: colaborador 0 deveria estar vinculado ao mirror correto.');
    $assert((int)$row1['metadados_id'] === $mirrors1[1], 'Cenário 1: colaborador 1 deveria estar vinculado ao mirror correto.');

    // ===== Cenário "reversão" (usa o mesmo cenário 1, já aplicado) =====
    $snapshot = [
        ['colaborador_id' => $colabs1[0], 'metadados_id' => $mirrors1[0], 'metadados_id_anterior' => null],
        ['colaborador_id' => $colabs1[1], 'metadados_id' => $mirrors1[1], 'metadados_id_anterior' => null],
    ];
    $resultadoRevert = $service->revert($snapshot);
    $assert($resultadoRevert['ok'] === true, 'Reversão: deveria ter sucesso: ' . ($resultadoRevert['error'] ?? ''));
    $assert($resultadoRevert['revertidos'] === 2, 'Reversão: deveria reportar 2 revertidos.');
    $row0Depois = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs1[0]}")->fetch(PDO::FETCH_ASSOC);
    $row1Depois = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs1[1]}")->fetch(PDO::FETCH_ASSOC);
    $assert($row0Depois['metadados_id'] === null, 'Reversão: colaborador 0 deveria voltar a NULL.');
    $assert($row1Depois['metadados_id'] === null, 'Reversão: colaborador 1 deveria voltar a NULL.');

    // ===== Cenário "reversão insegura": reaplica, altera um vínculo manualmente, tenta reverter =====
    $resultadoReaplica = $service->apply($plano);
    $assert($resultadoReaplica['ok'] === true, 'Reversão insegura: reaplicação inicial deveria funcionar.');
    // Simula alteração posterior por outra via (ex.: uma segunda reconciliação já teria vinculado
    // outro registro) — usa um terceiro mirror, livre, para não colidir com o UNIQUE.
    $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ?')->execute([$mirrors1[2], $colabs1[0]]);
    $resultadoRevertInseguro = $service->revert($snapshot);
    $assert($resultadoRevertInseguro['ok'] === false, 'Reversão insegura: deveria bloquear porque o vínculo do colaborador 0 mudou depois da aplicação.');
    $valorAposTentativa = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs1[0]}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$valorAposTentativa['metadados_id'] === $mirrors1[2], 'Reversão insegura: a mudança posterior não deveria ter sido sobrescrita pela reversão bloqueada.');
} finally {
    limpar($pdo, $colabs1, $mirrors1);
}

// ===== Cenário 2: um dos colaboradores já possui metadados_id -> aplicação falha inteira, rollback total =====
$mirrors2 = criarMirrors($pdo, $sufixo . 'b', 3);
$colabs2 = criarColaboradores($pdo, $cargoId, $sufixo . 'b', 2);
try {
    // Pré-condição: colaborador 1 já está vinculado a um terceiro mirror (simula vínculo aplicado por outra via).
    $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ?')->execute([$mirrors2[2], $colabs2[1]]);

    $plano = [
        ['colaborador_id' => $colabs2[0], 'metadados_id' => $mirrors2[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs2[1], 'metadados_id' => $mirrors2[1], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $resultado = $service->apply($plano);
    $assert($resultado['ok'] === false, 'Cenário 2: aplicação deveria falhar (colaborador 1 já vinculado).');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs2[0]}")->fetch(PDO::FETCH_ASSOC);
    $assert($row0['metadados_id'] === null, 'Cenário 2: colaborador 0 NÃO deveria ter sido aplicado — rollback total esperado (aplicação item a item, primeiro item não deve persistir se o segundo falhar).');
} finally {
    limpar($pdo, $colabs2, $mirrors2);
}

// ===== Cenário 3: metadados_id duplicado no plano -> UNIQUE dispara, rollback total =====
$mirrors3 = criarMirrors($pdo, $sufixo . 'c', 1);
$colabs3 = criarColaboradores($pdo, $cargoId, $sufixo . 'c', 2);
try {
    // Validação estrutural já pegaria isso — aqui testamos a rede de segurança da própria
    // transação (UNIQUE do banco), chamando apply() diretamente com um plano inválido.
    $planoDuplicado = [
        ['colaborador_id' => $colabs3[0], 'metadados_id' => $mirrors3[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs3[1], 'metadados_id' => $mirrors3[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $resultado = $service->apply($planoDuplicado);
    $assert($resultado['ok'] === false, 'Cenário 3: aplicação com metadados_id duplicado deveria falhar (UNIQUE).');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs3[0]}")->fetch(PDO::FETCH_ASSOC);
    $assert($row0['metadados_id'] === null, 'Cenário 3: nenhuma escrita deveria ter persistido — rollback total esperado.');
} finally {
    limpar($pdo, $colabs3, $mirrors3);
}

// ===== Cenário 4: erro no meio da transação (metadados_id inexistente no terceiro item) =====
$mirrors4 = criarMirrors($pdo, $sufixo . 'd', 2);
$colabs4 = criarColaboradores($pdo, $cargoId, $sufixo . 'd', 3);
try {
    $metadadosIdInexistente = max($mirrors4) + 999999;
    $planoComErro = [
        ['colaborador_id' => $colabs4[0], 'metadados_id' => $mirrors4[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs4[1], 'metadados_id' => $mirrors4[1], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs4[2], 'metadados_id' => $metadadosIdInexistente, 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $resultado = $service->apply($planoComErro);
    $assert($resultado['ok'] === false, 'Cenário 4: aplicação deveria falhar no terceiro item (FK inexistente).');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs4[0]}")->fetch(PDO::FETCH_ASSOC);
    $row1 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs4[1]}")->fetch(PDO::FETCH_ASSOC);
    $assert($row0['metadados_id'] === null && $row1['metadados_id'] === null, 'Cenário 4: os dois primeiros itens (aplicados com sucesso antes do erro) deveriam ter sido revertidos pelo ROLLBACK — nenhuma aplicação parcial pode persistir.');
} finally {
    limpar($pdo, $colabs4, $mirrors4);
}

// ===== Cenário 5: aplicação incremental — coexiste com vínculo pré-existente fora do plano.
// Reproduz a classe de problema descoberta depois dos 378 vínculos reais: apply() não pode mais
// exigir que "total global preenchido == tamanho do plano". =====
$mirrors5 = criarMirrors($pdo, $sufixo . 'e', 3);
$colabs5 = criarColaboradores($pdo, $cargoId, $sufixo . 'e', 3);
try {
    // Pré-existente, fora do plano (simula os 378 vínculos reais já aplicados antes desta chamada).
    $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ?')->execute([$mirrors5[2], $colabs5[2]]);
    $totalGlobalAntes = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores WHERE metadados_id IS NOT NULL')->fetchColumn();

    $planoIncremental = [
        ['colaborador_id' => $colabs5[0], 'metadados_id' => $mirrors5[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs5[1], 'metadados_id' => $mirrors5[1], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $resultado = $service->apply($planoIncremental);
    $assert($resultado['ok'] === true, 'Cenário 5: aplicação incremental deveria ter sucesso mesmo com vínculo pré-existente fora do plano: ' . ($resultado['error'] ?? ''));
    $assert($resultado['aplicados'] === 2, 'Cenário 5: deveria reportar 2 aplicados (só os do plano).');
    $assert($resultado['total_vinculado_global'] === $totalGlobalAntes + 2, 'Cenário 5: total_vinculado_global é só telemetria — deve refletir pré-existente + novos, sem travar a aplicação por ser maior que o plano.');

    $rowPreExistente = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs5[2]}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$rowPreExistente['metadados_id'] === $mirrors5[2], 'Cenário 5: vínculo pré-existente (fora do plano) não deveria ter sido alterado.');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs5[0]}")->fetch(PDO::FETCH_ASSOC);
    $row1 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs5[1]}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row0['metadados_id'] === $mirrors5[0], 'Cenário 5: colaborador 0 do plano deveria estar vinculado ao mirror correto.');
    $assert((int)$row1['metadados_id'] === $mirrors5[1], 'Cenário 5: colaborador 1 do plano deveria estar vinculado ao mirror correto.');
} finally {
    limpar($pdo, $colabs5, $mirrors5);
}

// ===== Cenário 6: divergência escopada — falha num item do plano não pode afetar vínculo
// pré-existente fora dele; e (Cenário 7) depois do ROLLBACK e do finally, nenhum resíduo
// sintético desta falha pode permanecer no banco. =====
$mirrors6 = criarMirrors($pdo, $sufixo . 'f', 2);
$colabs6 = criarColaboradores($pdo, $cargoId, $sufixo . 'f', 3);
try {
    // Pré-existente, fora do plano (simula um dos 378 já aplicados).
    $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ?')->execute([$mirrors6[1], $colabs6[2]]);

    $metadadosIdInexistente6 = max($mirrors6) + 999999;
    $planoComErroEscopado = [
        ['colaborador_id' => $colabs6[0], 'metadados_id' => $mirrors6[0], 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
        ['colaborador_id' => $colabs6[1], 'metadados_id' => $metadadosIdInexistente6, 'classificacao' => ColaboradorMetadadosReconciliationService::SEGURA, 'motivo_classificacao' => 'x'],
    ];
    $resultado = $service->apply($planoComErroEscopado);
    $assert($resultado['ok'] === false, 'Cenário 6: aplicação deveria falhar (segundo item aponta para metadados_id inexistente).');

    $rowPreExistente = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs6[2]}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$rowPreExistente['metadados_id'] === $mirrors6[1], 'Cenário 6: vínculo pré-existente fora do plano não deveria ter sido afetado pelo ROLLBACK do plano que falhou.');

    $row0 = $pdo->query("SELECT metadados_id FROM colaboradores WHERE id = {$colabs6[0]}")->fetch(PDO::FETCH_ASSOC);
    $assert($row0['metadados_id'] === null, 'Cenário 6: primeiro item do plano (aplicado antes do erro) deveria ter sido revertido pelo ROLLBACK.');
} finally {
    limpar($pdo, $colabs6, $mirrors6);
}

// Cenário 7 (limpeza após falha): confirma explicitamente que o finally do Cenário 6 removeu
// TODAS as fixtures daquela falha — nenhum resíduo sintético pode sobreviver a uma asserção que
// lança exceção no meio do cenário.
$placeholdersColabs6 = implode(',', array_fill(0, count($colabs6), '?'));
$stmtCheckColabs6 = $pdo->prepare("SELECT COUNT(*) FROM colaboradores WHERE id IN ($placeholdersColabs6)");
$stmtCheckColabs6->execute($colabs6);
$assert((int)$stmtCheckColabs6->fetchColumn() === 0, 'Cenário 7: os colaboradores fictícios do Cenário 6 deveriam ter sido removidos pelo finally.');

$placeholdersMirrors6 = implode(',', array_fill(0, count($mirrors6), '?'));
$stmtCheckMirrors6 = $pdo->prepare("SELECT COUNT(*) FROM colaboradores_metadados WHERE id IN ($placeholdersMirrors6)");
$stmtCheckMirrors6->execute($mirrors6);
$assert((int)$stmtCheckMirrors6->fetchColumn() === 0, 'Cenário 7: os mirrors fictícios do Cenário 6 deveriam ter sido removidos pelo finally.');

    echo "OK integration_colaborador_metadados_link_apply\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
