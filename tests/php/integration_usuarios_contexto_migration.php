<?php

/**
 * Integração — migration 2026-09-10-usuarios-contexto-organizacional.sql + rollback.
 *
 * Prova, em DEV, que:
 *   - a migration cria `usuarios.cargo_id` (FK -> cargos, ON DELETE SET NULL) e `usuario_setores`;
 *   - o rollback remove exatamente isso;
 *   - nenhum usuário é perdido no ciclo;
 *   - reaplicar a migration devolve o ambiente ao estado final (não deixa o dev quebrado).
 *
 * SKIP se o MySQL estiver fora. `finally` reaplica a migration sempre.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_usuarios_contexto_migration (MySQL indisponivel)\n";
    exit(0);
}

$MIGR = __DIR__ . '/../../database/migrations/2026-09-10-usuarios-contexto-organizacional.sql';
$ROLLBACK = __DIR__ . '/../../database/migrations/2026-09-10-usuarios-contexto-organizacional-rollback.sql';

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$aplicar = static function (string $path) use ($pdo): void {
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($path)) ?? '';
    foreach (explode(';', $sql) as $stmt) {
        $limpo = trim($stmt);
        if ($limpo !== '' && preg_match('/^(ALTER|CREATE|DROP)/i', $limpo)) {
            $pdo->exec($limpo);
        }
    }
};
$temColuna = static fn (): int => (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'cargo_id'"
)->fetchColumn();
$temTabela = static fn (): int => (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_setores'"
)->fetchColumn();
$temFk = static fn (): int => (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_usuarios_cargo'"
)->fetchColumn();

$usuariosAntes = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

try {
    // garante estado "aplicado" antes de exercitar o rollback
    if ($temColuna() === 0 || $temTabela() === 0) {
        $aplicar($MIGR);
    }
    $check($temColuna() === 1 && $temTabela() === 1 && $temFk() === 1, 'migration: coluna cargo_id + tabela usuario_setores + FK fk_usuarios_cargo presentes');

    $regra = $pdo->query(
        "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_usuarios_cargo'"
    )->fetchColumn();
    $check($regra === 'SET NULL', 'migration: fk_usuarios_cargo com ON DELETE SET NULL');

    $rollbackOk = false;
    try {
        $aplicar($ROLLBACK);
        $rollbackOk = true;
    } catch (\Throwable $e) {
        fwrite(STDERR, '  rollback erro: ' . $e->getMessage() . "\n");
    }
    $check($rollbackOk, 'rollback executa sem erro');
    $check($temColuna() === 0 && $temTabela() === 0 && $temFk() === 0, 'rollback: cargo_id, usuario_setores e FK removidos');
    $check((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === $usuariosAntes, 'rollback: nenhum usuário perdido');

    $aplicar($MIGR);
    $check($temColuna() === 1 && $temTabela() === 1 && $temFk() === 1, 'reaplicação: ambiente volta ao estado final');
    $check((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === $usuariosAntes, 'reaplicação: contagem de usuários intacta');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nUSUARIOS_CONTEXTO_MIGRATION_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    // rede de segurança: o dev NUNCA pode ficar sem a estrutura.
    try {
        if ($temColuna() === 0 || $temTabela() === 0) {
            $aplicar($MIGR);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'ATENCAO: falha ao reaplicar a migration no finally: ' . $e->getMessage() . "\n");
    }
}

exit($exit ?? 0);
