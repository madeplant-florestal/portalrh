<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_reconciliar_empresas_locais_metadados (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$MIGR = __DIR__ . '/../../database/migrations/2026-09-08-reconciliar-empresas-locais-metadados.sql';
$ROLLBACK = __DIR__ . '/../../database/migrations/2026-09-08-reconciliar-empresas-locais-metadados-rollback.sql';

$MAPA = [2 => '0005', 3 => '0007', 4 => '0008'];
$NOMES = [2 => 'MADEPLANT TRANSPORTES', 3 => 'MADEPLANT CSC', 4 => 'PROSPECTA SERVICOS'];
$OFICIAIS = [
    '0005' => 'MADEPLANT TRANSPORTES LTDA',
    '0007' => 'MADEPLANT CENTRO DE SERV COMPARTILHADOS',
    '0008' => 'PROSPECTA SERVICOS E TRANSPORTES LTDA',
];

$pdo = Database::conn();

$temCodigo = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'codigo_empresa'"
)->fetchColumn();
if ($temCodigo === 0) {
    echo "SKIP integration_reconciliar_empresas_locais_metadados (migration 2026-09-04-empresas-unidades-metadados.sql nao aplicada)\n";
    exit(0);
}

// Esta migration é específica dos dados reais da Madeplant (ids 2/3/4 com nomes abreviados,
// ainda sem código). Se a base local não estiver nesse estado, não há o que testar.
$atuais = $pdo->query('SELECT id, nome, slug, ativo, codigo_empresa, origem_metadados, razao_social, sincronizado_em FROM empresas WHERE id IN (2,3,4) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($atuais as $r) {
    $byId[(int)$r['id']] = $r;
}
foreach ([2, 3, 4] as $id) {
    if (!isset($byId[$id]) || $byId[$id]['nome'] !== $NOMES[$id] || $byId[$id]['codigo_empresa'] !== null) {
        echo "SKIP integration_reconciliar_empresas_locais_metadados (base local nao esta no estado esperado para os ids 2/3/4)\n";
        exit(0);
    }
}
$snapshot = $byId;

// Remove linhas inteiras de comentário -- ... (evita que um ';' dentro de comentário confunda o
// split). Clientes reais (phpMyAdmin/mysql CLI) tratam DELIMITER e comentários nativamente.
$semComentarios = static function (string $sql): string {
    return preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
};

$aplicarProcedure = static function (PDO $pdo, string $path) use ($semComentarios): void {
    $sql = $semComentarios((string)file_get_contents($path));
    $sql = preg_replace('/^\s*DELIMITER.*$/m', '', $sql) ?? $sql;
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
    $sql = $semComentarios((string)file_get_contents($path));
    foreach (explode(';', $sql) as $stmt) {
        $limpo = trim($stmt);
        if (!preg_match('/^(UPDATE|INSERT|DELETE)/i', $limpo)) {
            continue;
        }
        $pdo->exec($limpo);
    }
};

$restaurar = static function () use ($pdo, $snapshot): void {
    foreach ($snapshot as $id => $r) {
        $stmt = $pdo->prepare(
            'UPDATE empresas SET codigo_empresa = ?, origem_metadados = ?, razao_social = ?, sincronizado_em = ? WHERE id = ?'
        );
        $stmt->execute([$r['codigo_empresa'], $r['origem_metadados'], $r['razao_social'], $r['sincronizado_em'], $id]);
    }
    $pdo->exec("DELETE FROM empresas WHERE slug = 'zz-decoy-conflito-5.1a1'");
};
$restaurar();

try {
    // ===== 1) Aplicação: os 3 registros recebem o código aprovado, nada mais muda =====
    $aplicarProcedure($pdo, $MIGR);
    $pos = [];
    foreach ($pdo->query('SELECT * FROM empresas WHERE id IN (2,3,4)')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos[(int)$r['id']] = $r;
    }
    foreach ([2, 3, 4] as $id) {
        $assert($pos[$id]['codigo_empresa'] === $MAPA[$id], "Caso 1: id {$id} deveria ter codigo_empresa {$MAPA[$id]}.");
        $assert((int)$pos[$id]['id'] === $id, "Caso 1: id {$id} nunca deve mudar.");
        $assert($pos[$id]['nome'] === $snapshot[$id]['nome'], "Caso 1: nome do id {$id} nao pode mudar.");
        $assert($pos[$id]['slug'] === $snapshot[$id]['slug'], "Caso 1: slug do id {$id} nao pode mudar.");
        $assert((int)$pos[$id]['ativo'] === (int)$snapshot[$id]['ativo'], "Caso 1: ativo do id {$id} nao pode mudar.");
        $assert($pos[$id]['razao_social'] === null, "Caso 1: razao_social do id {$id} deve continuar NULL (nenhuma sync ocorreu).");
        $assert($pos[$id]['sincronizado_em'] === null, "Caso 1: sincronizado_em do id {$id} nao deve ser carimbado nesta etapa.");
        $assert($pos[$id]['origem_metadados'] === 'RHMADEPLANT', "Caso 1: origem_metadados do id {$id} deveria ser RHMADEPLANT.");
    }

    // ===== 2) Idempotência: reaplicar não muda nada e não lança =====
    $aplicarProcedure($pdo, $MIGR);
    foreach ([2, 3, 4] as $id) {
        $c = $pdo->query("SELECT codigo_empresa FROM empresas WHERE id = {$id}")->fetchColumn();
        $assert($c === $MAPA[$id], "Caso 2: segunda execução deveria ser idempotente (id {$id}).");
    }
    $qtd0005 = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE codigo_empresa = '0005'")->fetchColumn();
    $assert($qtd0005 === 1, 'Caso 2: nao pode haver duplicidade de codigo apos reaplicar.');

    // ===== 3) planejar() para 0005/0007/0008: ATUALIZAR_EXISTENTE ou INALTERADA, nunca INSERIR/ADOTAR =====
    $fonte = [];
    foreach ($OFICIAIS as $codigo => $razao) {
        $fonte[] = ['codigo_empresa' => $codigo, 'razao_social' => $razao];
    }
    $plano = (new EmpresaMetadadosSyncService(new EmpresaMetadadosRepository($pdo)))->planejar($fonte, 'RHMADEPLANT');
    $porCodigo = [];
    foreach ($plano['itens'] as $item) {
        $porCodigo[$item['codigo_empresa']] = $item;
    }
    foreach ($MAPA as $id => $codigo) {
        $acao = $porCodigo[$codigo]['acao'] ?? '(ausente)';
        $assert(in_array($acao, ['ATUALIZAR_EXISTENTE', 'INALTERADA'], true), "Caso 3: {$codigo} deveria ser ATUALIZAR_EXISTENTE/INALTERADA, veio {$acao}.");
        $assert(!in_array($acao, ['INSERIR_NOVA', 'ADOTAR_EXISTENTE'], true), "Caso 3: {$codigo} nunca pode ser INSERIR_NOVA/ADOTAR_EXISTENTE.");
        $assert((int)($porCodigo[$codigo]['empresa_local_id'] ?? 0) === $id, "Caso 3: {$codigo} deveria apontar para o id local {$id}.");
    }
    $assert($plano['locais_sem_correspondencia'] === [] || !in_array(2, array_column($plano['locais_sem_correspondencia'], 'id'), true), 'Caso 3: id 2 nao deveria mais aparecer como sem correspondencia.');

    // ===== 4) Conflito: código já pertence a outro id -> migration aborta, nada é aplicado =====
    $aplicarPlain($pdo, $ROLLBACK); // solta os 3
    $pdo->exec("INSERT INTO empresas (codigo_empresa, nome, slug, ativo) VALUES ('0005', 'ZZ DECOY CONFLITO 5.1A.1', 'zz-decoy-conflito-5.1a1', 1)");
    $conflitou = false;
    try {
        $aplicarProcedure($pdo, $MIGR);
    } catch (\Throwable $e) {
        $conflitou = true;
    }
    $assert($conflitou, 'Caso 4: migration deveria abortar quando 0005 ja pertence a outra empresa.');
    foreach ([2, 3, 4] as $id) {
        $c = $pdo->query("SELECT codigo_empresa FROM empresas WHERE id = {$id}")->fetchColumn();
        $assert($c === null, "Caso 4: nenhum dos 3 registros pode ter sido alterado quando a migration aborta (id {$id}).");
    }
    $pdo->exec("DELETE FROM empresas WHERE slug = 'zz-decoy-conflito-5.1a1'");

    // ===== 5) Rollback desfaz apenas esta reconciliação =====
    $aplicarProcedure($pdo, $MIGR);
    $aplicarPlain($pdo, $ROLLBACK);
    foreach ([2, 3, 4] as $id) {
        $r = $pdo->query("SELECT codigo_empresa, origem_metadados FROM empresas WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $assert($r['codigo_empresa'] === null && $r['origem_metadados'] === null, "Caso 5: rollback deveria soltar o codigo e a origem do id {$id}.");
    }

    echo "OK integration_reconciliar_empresas_locais_metadados\n";
} finally {
    $restaurar();
}
