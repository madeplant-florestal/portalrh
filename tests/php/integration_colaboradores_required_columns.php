<?php
require __DIR__ . '/../../app/core/bootstrap.php';

MovimentacaoPessoal::ensureSchema();

$pdo = Database::conn();

$requiredColumns = [
    'id',
    'nome',
    'codigo',
    'empresa_id',
    'cpf',
    'data_admissao',
    'data_nascimento',
    'cargo_id',
    'data_demissao',
    'motivo_rescisao',
];

$stmt = $pdo->prepare(
    'SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
     ORDER BY ORDINAL_POSITION'
);
$stmt->execute(['colaboradores']);
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

foreach ($requiredColumns as $column) {
    if (!in_array($column, $columns, true)) {
        throw new RuntimeException('Coluna obrigatória ausente em colaboradores: ' . $column);
    }
}

$pkStmt = $pdo->query("SHOW KEYS FROM colaboradores WHERE Key_name = 'PRIMARY'");
$primaryKeys = $pkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($primaryKeys) !== 1 || ($primaryKeys[0]['Column_name'] ?? '') !== 'id') {
    throw new RuntimeException('A chave primária da tabela colaboradores não está preservada exclusivamente em id.');
}

$countStmt = $pdo->query('SELECT COUNT(*) FROM colaboradores');
$total = (int)$countStmt->fetchColumn();
if ($total <= 0) {
    throw new RuntimeException('A tabela colaboradores não possui registros para validar a integridade pós-migração.');
}

$nullCodigoStmt = $pdo->query("SELECT COUNT(*) FROM colaboradores WHERE codigo IS NULL OR codigo = ''");
$nullCodigo = (int)$nullCodigoStmt->fetchColumn();
if ($nullCodigo > 0) {
    throw new RuntimeException('Existem colaboradores sem COD preenchido após a autocorreção estrutural.');
}

echo "COLABORADORES_REQUIRED_COLUMNS_OK\n";
