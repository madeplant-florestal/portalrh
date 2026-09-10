<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_reconciliar_cargos_locais_metadados (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
};

$MIGR = __DIR__ . '/../../database/migrations/2026-09-10-reconciliar-cargos-locais-metadados.sql';
$ROLLBACK = __DIR__ . '/../../database/migrations/2026-09-10-reconciliar-cargos-locais-metadados-rollback.sql';

// Decisao aprovada (id local -> codigo oficial opaco). '047' = 'CONTADOR  ( A )'.
$ID = 24;
$COD = '047';
$NOME = 'CONTADOR';

$pdo = Database::conn();

$temCodigo = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cargos' AND COLUMN_NAME = 'codigo_cargo'"
)->fetchColumn();
if ($temCodigo === 0) {
    echo "SKIP integration_reconciliar_cargos_locais_metadados (migration 2026-09-08-setores-cargos-metadados.sql nao aplicada)\n";
    exit(0);
}

// Especifica dos dados reais da Madeplant. Precondicao: cargos.id = 24 existe, nome 'CONTADOR',
// SEM codigo_cargo.
$linha = $pdo->query("SELECT id, codigo_cargo, nome, slug, ativo, descricao_oficial, situacao_metadados, origem_metadados, sincronizado_em FROM cargos WHERE id = {$ID}")->fetch(PDO::FETCH_ASSOC);
if (!$linha || $linha['nome'] !== $NOME || $linha['codigo_cargo'] !== null) {
    echo "SKIP integration_reconciliar_cargos_locais_metadados (cargos.id {$ID} nao esta no estado esperado: CONTADOR sem codigo)\n";
    exit(0);
}
$snapshot = $linha;

$semComentarios = static fn (string $sql): string => preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

$aplicarProcedure = static function (PDO $pdo, string $path) use ($semComentarios): void {
    $sql = preg_replace('/^\s*DELIMITER.*$/m', '', $semComentarios((string)file_get_contents($path))) ?? '';
    foreach (explode('$$', $sql) as $chunk) {
        if (trim($chunk) === '') {
            continue;
        }
        $stmt = $pdo->query(trim($chunk));
        if ($stmt !== false) {
            do {
                $stmt->fetchAll();
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        }
    }
};

$aplicarPlain = static function (PDO $pdo, string $path) use ($semComentarios): void {
    foreach (explode(';', $semComentarios((string)file_get_contents($path))) as $stmt) {
        $limpo = trim($stmt);
        if (preg_match('/^(UPDATE|INSERT|DELETE)/i', $limpo)) {
            $pdo->exec($limpo);
        }
    }
};

$restaurar = static function () use ($pdo, $snapshot, $ID): void {
    $pdo->prepare('UPDATE cargos SET codigo_cargo = ?, descricao_oficial = ?, situacao_metadados = ?, origem_metadados = ?, sincronizado_em = ? WHERE id = ?')
        ->execute([$snapshot['codigo_cargo'], $snapshot['descricao_oficial'], $snapshot['situacao_metadados'], $snapshot['origem_metadados'], $snapshot['sincronizado_em'], $ID]);
    $pdo->exec("DELETE FROM cargos WHERE slug = 'zz-decoy-conflito-cargos-0910'");
};
$restaurar();

try {
    // ===== 1) Aplicacao: id 24 recebe '047', nada mais muda =====
    $aplicarProcedure($pdo, $MIGR);
    $r = $pdo->query("SELECT * FROM cargos WHERE id = {$ID}")->fetch(PDO::FETCH_ASSOC);
    $assert($r['codigo_cargo'] === $COD, "Caso 1: id {$ID} deveria ter codigo_cargo '{$COD}', veio '" . var_export($r['codigo_cargo'], true) . "'.");
    $assert($r['codigo_cargo'] !== ltrim($COD, '0') && $r['codigo_cargo'] !== '47', 'Caso 1: zero a esquerda do codigo preservado (string opaca).');
    $assert((int)$r['id'] === $ID, "Caso 1: id nunca muda.");
    $assert($r['nome'] === $snapshot['nome'], 'Caso 1: nome legado preservado.');
    $assert($r['slug'] === $snapshot['slug'], 'Caso 1: slug preservado.');
    $assert((int)$r['ativo'] === (int)$snapshot['ativo'], 'Caso 1: ativo preservado.');
    $assert($r['descricao_oficial'] === null, 'Caso 1: descricao_oficial continua NULL (feita pela sync oficial).');
    $assert($r['sincronizado_em'] === null, 'Caso 1: sincronizado_em nao carimbado nesta etapa.');
    $assert($r['origem_metadados'] === 'RHMADEPLANT', 'Caso 1: origem_metadados = RHMADEPLANT.');

    // ===== 2) Idempotencia =====
    $aplicarProcedure($pdo, $MIGR);
    $assert($pdo->query("SELECT codigo_cargo FROM cargos WHERE id = {$ID}")->fetchColumn() === $COD, 'Caso 2: reaplicar idempotente.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM cargos WHERE codigo_cargo = " . $pdo->quote($COD))->fetchColumn() === 1, "Caso 2: sem duplicidade do codigo '{$COD}'.");

    // ===== 3) planejar() apos a reconciliacao: '047' -> ATUALIZAR/INALTERADA no id 24, nunca ADOTAR/INSERIR =====
    $fonte = [
        ['codigo' => $COD, 'descricao_oficial' => 'CONTADOR  ( A )', 'situacao_oficial' => '1'],
        ['codigo' => 'ZZ998', 'descricao_oficial' => 'ZZ CARGO DECOY SEM MATCH', 'situacao_oficial' => '1'],
    ];
    $antes = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE(codigo_cargo,'~'),':',nome ORDER BY id)) FROM cargos")->fetchColumn();
    $plano = (new CatalogoMetadadosSyncService('cargos', new CatalogoMetadadosRepository('cargos', $pdo)))->planejar($fonte, 'RHMADEPLANT');
    $depois = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE(codigo_cargo,'~'),':',nome ORDER BY id)) FROM cargos")->fetchColumn();
    $assert($antes === $depois, 'Caso 3: planejar() nao pode alterar a tabela cargos.');

    $porCodigo = [];
    foreach ($plano['itens'] as $it) {
        $porCodigo[$it['codigo']] = $it;
    }
    $acao047 = $porCodigo[$COD]['acao'] ?? '(ausente)';
    $assert(in_array($acao047, ['ATUALIZAR_EXISTENTE', 'INALTERADA'], true), "Caso 3: codigo '{$COD}' deveria ser ATUALIZAR_EXISTENTE/INALTERADA, veio {$acao047}.");
    $assert((int)($porCodigo[$COD]['local_id'] ?? 0) === $ID, "Caso 3: codigo '{$COD}' deveria apontar o id local {$ID}.");
    $assert(($porCodigo['ZZ998']['acao'] ?? '') === 'INSERIR_NOVA', 'Caso 3: decoy sem match -> INSERIR_NOVA.');

    // id 24 NAO pode mais aparecer como LOCAL_SEM_CORRESPONDENCIA (ja tem codigo)
    $idsSemCorr = array_map(static fn ($l) => (int)$l['id'], $plano['locais_sem_correspondencia']);
    $assert(!in_array($ID, $idsSemCorr, true), "Caso 3: id {$ID} nao pode aparecer em locais_sem_correspondencia depois de reconciliado.");

    // ===== 4) Conflito: '047' ja pertence a outro id -> migration aborta, id 24 intacto =====
    $aplicarPlain($pdo, $ROLLBACK);
    $pdo->exec("INSERT INTO cargos (codigo_cargo, nome, slug, ativo) VALUES ('047', 'ZZ DECOY CONFLITO CARGOS', 'zz-decoy-conflito-cargos-0910', 1)");
    $conflitou = false;
    try {
        $aplicarProcedure($pdo, $MIGR);
    } catch (\Throwable $e) {
        $conflitou = true;
    }
    $assert($conflitou, "Caso 4: migration deveria abortar quando '047' ja pertence a outro cargo.");
    $assert($pdo->query("SELECT codigo_cargo FROM cargos WHERE id = {$ID}")->fetchColumn() === null, 'Caso 4: id 24 nao pode ter sido alterado quando a migration aborta.');
    $pdo->exec("DELETE FROM cargos WHERE slug = 'zz-decoy-conflito-cargos-0910'");

    // ===== 5) Conflito: id 24 ja tem outro codigo -> migration aborta =====
    $pdo->exec("UPDATE cargos SET codigo_cargo = '0999' WHERE id = {$ID}");
    $conflitou2 = false;
    try {
        $aplicarProcedure($pdo, $MIGR);
    } catch (\Throwable $e) {
        $conflitou2 = true;
    }
    $assert($conflitou2, 'Caso 5: migration deveria abortar quando id 24 ja tem codigo diferente.');
    $assert($pdo->query("SELECT codigo_cargo FROM cargos WHERE id = {$ID}")->fetchColumn() === '0999', 'Caso 5: codigo pre-existente do id 24 nao pode ter sido tocado.');
    $pdo->exec("UPDATE cargos SET codigo_cargo = NULL WHERE id = {$ID}");

    // ===== 6) Rollback desfaz apenas esta reconciliacao =====
    $aplicarProcedure($pdo, $MIGR);
    $aplicarPlain($pdo, $ROLLBACK);
    $r = $pdo->query("SELECT codigo_cargo, origem_metadados FROM cargos WHERE id = {$ID}")->fetch(PDO::FETCH_ASSOC);
    $assert($r['codigo_cargo'] === null && $r['origem_metadados'] === null, 'Caso 6: rollback deveria soltar codigo e origem do id 24.');

    echo "OK integration_reconciliar_cargos_locais_metadados\n";
} finally {
    $restaurar();
}
