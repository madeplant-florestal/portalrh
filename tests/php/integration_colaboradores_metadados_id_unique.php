<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_colaboradores_metadados_id_unique (MySQL indisponivel)\n";
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
$hasColumn = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores' AND COLUMN_NAME = 'metadados_id'"
)->fetchColumn();
if ($hasColumn === 0) {
    echo "SKIP integration_colaboradores_metadados_id_unique (migration 2026-08-28-colaboradores-metadados-id.sql nao aplicada)\n";
    exit(0);
}

$cargo = $pdo->query('SELECT id FROM cargos LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$cargo) {
    echo "SKIP integration_colaboradores_metadados_id_unique (nenhum cargo disponivel para fixture)\n";
    exit(0);
}
$cargoId = (int)$cargo['id'];

$sufixo = (string)time() . (string)random_int(100, 999);
$empresa = 'EMPU' . $sufixo;
$unidade = 'UNIU' . $sufixo;

$mirrorId = null;
$colabAId = null;
$colabBId = null;
$colabCId = null;

try {
    // Cria uma linha de espelho fictícia para vincular.
    $stmt = $pdo->prepare(
        "INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome)
         VALUES (?,?,?,?,?,?)"
    );
    $stmt->execute(["$empresa-$unidade-001", $empresa, $unidade, '001', 'PESU' . $sufixo, 'Colaborador Teste Unique']);
    $mirrorId = (int)$pdo->lastInsertId();

    // Colaborador A: vincula ao espelho — deve funcionar normalmente.
    $stmt = $pdo->prepare(
        "INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id) VALUES (?,?,?,?)"
    );
    $stmt->execute(['Colaborador Teste A ' . $sufixo, 'colaborador-teste-a-' . $sufixo, $cargoId, $mirrorId]);
    $colabAId = (int)$pdo->lastInsertId();
    $assert($colabAId > 0, 'Falha: colaborador A deveria ter sido inserido com metadados_id preenchido.');

    // Colaborador B: tenta apontar para o MESMO metadados_id — deve violar a UNIQUE.
    $violou = false;
    try {
        $stmt->execute(['Colaborador Teste B ' . $sufixo, 'colaborador-teste-b-' . $sufixo, $cargoId, $mirrorId]);
        $colabBId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $violou = true;
        $assert(
            str_contains($e->getMessage(), 'uk_colaboradores_metadados_id') || (int)$e->getCode() === 23000 || $e->errorInfo[1] === 1062,
            'Falha: a exceção deveria ser de violação de UNIQUE (1062/23000), veio: ' . $e->getMessage()
        );
    }
    $assert($violou, 'Falha: inserir um segundo colaborador com o mesmo metadados_id deveria violar a restrição UNIQUE.');

    // Colaborador C: metadados_id NULL — deve ser permitido livremente (múltiplos NULL sob UNIQUE).
    $stmtNull = $pdo->prepare("INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id) VALUES (?,?,?,NULL)");
    $stmtNull->execute(['Colaborador Teste C ' . $sufixo, 'colaborador-teste-c-' . $sufixo, $cargoId]);
    $colabCId = (int)$pdo->lastInsertId();
    $stmtNull->execute(['Colaborador Teste D ' . $sufixo, 'colaborador-teste-d-' . $sufixo, $cargoId]);
    $colabDId = (int)$pdo->lastInsertId();
    $assert($colabCId > 0 && $colabDId > 0, 'Falha: dois colaboradores com metadados_id NULL deveriam poder coexistir.');

    // ON DELETE RESTRICT: apagar a linha do espelho enquanto houver vínculo ativo deve falhar.
    $bloqueouDelete = false;
    try {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$mirrorId]);
    } catch (PDOException $e) {
        $bloqueouDelete = true;
    }
    $assert($bloqueouDelete, 'Falha: apagar um vínculo do espelho referenciado por colaboradores deveria ser bloqueado (ON DELETE RESTRICT).');
} finally {
    if ($colabAId) { $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$colabAId]); }
    if ($colabCId) { $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$colabCId]); }
    if (isset($colabDId) && $colabDId) { $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$colabDId]); }
    if ($mirrorId) { $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$mirrorId]); }
}

echo "OK integration_colaboradores_metadados_id_unique\n";
