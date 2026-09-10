<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_reconciliar_setores_locais_metadados (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
};

$MIGR = __DIR__ . '/../../database/migrations/2026-09-08-reconciliar-setores-locais-metadados.sql';
$ROLLBACK = __DIR__ . '/../../database/migrations/2026-09-08-reconciliar-setores-locais-metadados-rollback.sql';

// Mapa aprovado (id local -> codigo oficial).
$RECONCILIAR = [10 => '1', 7 => '6', 12 => '9'];
$NOMES_RECONCILIAR = [10 => 'RH/DP/SST', 7 => 'LOGÍSTICA', 12 => 'TI'];
$OFICIAIS_RECONCILIADOS = [
    '1' => 'RECURSOS HUMANOS',
    '6' => 'LOGISTICA E TRANSPORTES',
    '9' => 'TECNOLOGIA DA INFORMACAO',
];
// Os 9 setores locais que casam EXATAMENTE por nome com uma descricao oficial (ADOTAR_EXISTENTE).
$IDS_ADOTAR = [1, 2, 3, 4, 5, 6, 8, 11, 17];
// Os 4 legados que permanecem deliberadamente sem codigo apos a reconciliacao.
$LEGADO_SEM_CODIGO = [9 => 'PRODUÇÃO', 16 => 'ADMINISTRATIVO', 19 => 'MANUTENÇÃO PROSPECTA', 20 => 'ADMINISTRATIVO PROSPECTA'];

$pdo = Database::conn();

$temCodigo = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setores' AND COLUMN_NAME = 'codigo_setor'"
)->fetchColumn();
if ($temCodigo === 0) {
    echo "SKIP integration_reconciliar_setores_locais_metadados (migration 2026-09-08-setores-cargos-metadados.sql nao aplicada)\n";
    exit(0);
}

// Esta migration e especifica dos dados reais da Madeplant. Precondicao: os 3 ids a reconciliar +
// os 9 a adotar + os 4 legados existem, com os nomes esperados e SEM codigo_setor.
$idsEsperados = array_merge(array_keys($RECONCILIAR), $IDS_ADOTAR, array_keys($LEGADO_SEM_CODIGO));
$linhas = [];
foreach ($pdo->query('SELECT id, codigo_setor, nome, slug, empresa_id, ativo, descricao_oficial, situacao_metadados, origem_metadados, sincronizado_em FROM setores') as $r) {
    $linhas[(int)$r['id']] = $r;
}
$baseOk = true;
foreach ($idsEsperados as $id) {
    if (!isset($linhas[$id]) || $linhas[$id]['codigo_setor'] !== null) {
        $baseOk = false;
        break;
    }
}
foreach ($NOMES_RECONCILIAR as $id => $nome) {
    if (($linhas[$id]['nome'] ?? null) !== $nome) {
        $baseOk = false;
    }
}
foreach ($LEGADO_SEM_CODIGO as $id => $nome) {
    if (($linhas[$id]['nome'] ?? null) !== $nome) {
        $baseOk = false;
    }
}
if (!$baseOk) {
    echo "SKIP integration_reconciliar_setores_locais_metadados (base local de setores nao esta no estado esperado)\n";
    exit(0);
}

$snapshot = [];
foreach (array_keys($RECONCILIAR) as $id) {
    $snapshot[$id] = $linhas[$id];
}

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

$restaurar = static function () use ($pdo, $snapshot): void {
    foreach ($snapshot as $id => $r) {
        $pdo->prepare('UPDATE setores SET codigo_setor = ?, descricao_oficial = ?, situacao_metadados = ?, origem_metadados = ?, sincronizado_em = ? WHERE id = ?')
            ->execute([$r['codigo_setor'], $r['descricao_oficial'], $r['situacao_metadados'], $r['origem_metadados'], $r['sincronizado_em'], $id]);
    }
    $pdo->exec("DELETE FROM setores WHERE slug = 'zz-decoy-conflito-521'");
};
$restaurar();

try {
    // ===== 1) Aplicacao: os 3 recebem o codigo aprovado, nada mais muda =====
    $aplicarProcedure($pdo, $MIGR);
    foreach ($RECONCILIAR as $id => $cod) {
        $r = $pdo->query("SELECT * FROM setores WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $assert($r['codigo_setor'] === $cod, "Caso 1: id {$id} deveria ter codigo_setor '{$cod}', veio '" . var_export($r['codigo_setor'], true) . "'.");
        $assert((int)$r['id'] === $id, "Caso 1: id {$id} nunca muda.");
        $assert($r['nome'] === $snapshot[$id]['nome'], "Caso 1: nome do id {$id} preservado.");
        $assert($r['slug'] === $snapshot[$id]['slug'], "Caso 1: slug do id {$id} preservado.");
        $assert((string)$r['empresa_id'] === (string)$snapshot[$id]['empresa_id'], "Caso 1: empresa_id do id {$id} preservado.");
        $assert((int)$r['ativo'] === (int)$snapshot[$id]['ativo'], "Caso 1: ativo do id {$id} preservado.");
        $assert($r['descricao_oficial'] === null, "Caso 1: descricao_oficial do id {$id} continua NULL (feita pela sync oficial).");
        $assert($r['sincronizado_em'] === null, "Caso 1: sincronizado_em do id {$id} nao carimbado nesta etapa.");
        $assert($r['origem_metadados'] === 'RHMADEPLANT', "Caso 1: origem_metadados do id {$id} = RHMADEPLANT.");
    }

    // ===== 2) Idempotencia =====
    $aplicarProcedure($pdo, $MIGR);
    foreach ($RECONCILIAR as $id => $cod) {
        $assert($pdo->query("SELECT codigo_setor FROM setores WHERE id = {$id}")->fetchColumn() === $cod, "Caso 2: reaplicar idempotente (id {$id}).");
    }
    foreach (['1', '6', '9'] as $cod) {
        $assert((int)$pdo->query("SELECT COUNT(*) FROM setores WHERE codigo_setor = " . $pdo->quote($cod))->fetchColumn() === 1, "Caso 2: sem duplicidade do codigo '{$cod}'.");
    }

    // ===== 3) Plano completo apos reconciliacao =====
    // Catalogo oficial dos 12: 9 descricoes = nome exato dos ids a adotar + 3 reconciliadas.
    $fonte = [];
    $ci = 100;
    foreach ($IDS_ADOTAR as $id) {
        $fonte[] = ['codigo' => (string)$ci++, 'descricao_oficial' => $linhas[$id]['nome'], 'situacao_oficial' => '1'];
    }
    foreach ($OFICIAIS_RECONCILIADOS as $cod => $desc) {
        $fonte[] = ['codigo' => $cod, 'descricao_oficial' => $desc, 'situacao_oficial' => '1'];
    }
    $assert(count($fonte) === 12, 'Caso 3: catalogo simulado deveria ter 12 setores oficiais.');

    $plano = (new CatalogoMetadadosSyncService('setores', new CatalogoMetadadosRepository('setores', $pdo)))->planejar($fonte, 'RHMADEPLANT');
    $cont = [];
    foreach ($plano['itens'] as $it) {
        $cont[$it['acao']] = ($cont[$it['acao']] ?? 0) + 1;
    }
    $assert(($cont['INSERIR_NOVA'] ?? 0) === 0, 'Caso 3: INSERIR_NOVA deveria ser 0 apos a reconciliacao. Veio ' . ($cont['INSERIR_NOVA'] ?? 0) . '.');
    $assert(($cont['ADOTAR_EXISTENTE'] ?? 0) === 9, 'Caso 3: 9 ADOTAR_EXISTENTE. Veio ' . ($cont['ADOTAR_EXISTENTE'] ?? 0) . '.');

    $porCodigo = [];
    foreach ($plano['itens'] as $it) {
        $porCodigo[$it['codigo']] = $it;
    }
    foreach ($RECONCILIAR as $id => $cod) {
        $acao = $porCodigo[$cod]['acao'] ?? '(ausente)';
        $assert(in_array($acao, ['ATUALIZAR_EXISTENTE', 'INALTERADA'], true), "Caso 3: codigo '{$cod}' deveria ser ATUALIZAR_EXISTENTE/INALTERADA, veio {$acao}.");
        $assert((int)($porCodigo[$cod]['local_id'] ?? 0) === $id, "Caso 3: codigo '{$cod}' deveria apontar o id local {$id}.");
    }

    // exatamente os 4 legados, com os nomes esperados
    $semCorr = [];
    foreach ($plano['locais_sem_correspondencia'] as $l) {
        $semCorr[(int)$l['id']] = $l['nome'];
    }
    $assert(count($semCorr) === 4, 'Caso 3: deveriam sobrar exatamente 4 LOCAL_SEM_CORRESPONDENCIA_OFICIAL. Vieram ' . count($semCorr) . ': ' . json_encode(array_values($semCorr), JSON_UNESCAPED_UNICODE));
    foreach ($LEGADO_SEM_CODIGO as $id => $nome) {
        $assert(($semCorr[$id] ?? null) === $nome, "Caso 3: legado id {$id} ('{$nome}') deveria estar em locais_sem_correspondencia.");
    }

    // ===== 4) Conflito: codigo ja pertence a outro id -> migration aborta, nada aplicado =====
    $aplicarPlain($pdo, $ROLLBACK);
    $extra = ", empresa_id"; $extraVal = ", NULL";
    $pdo->exec("INSERT INTO setores (codigo_setor, nome, slug, ativo{$extra}) VALUES ('1', 'ZZ DECOY CONFLITO 5.2.1', 'zz-decoy-conflito-521', 1{$extraVal})");
    $conflitou = false;
    try {
        $aplicarProcedure($pdo, $MIGR);
    } catch (\Throwable $e) {
        $conflitou = true;
    }
    $assert($conflitou, "Caso 4: migration deveria abortar quando o codigo '1' ja pertence a outro setor.");
    foreach (array_keys($RECONCILIAR) as $id) {
        $assert($pdo->query("SELECT codigo_setor FROM setores WHERE id = {$id}")->fetchColumn() === null, "Caso 4: id {$id} nao pode ter sido alterado quando a migration aborta.");
    }
    $pdo->exec("DELETE FROM setores WHERE slug = 'zz-decoy-conflito-521'");

    // ===== 5) Rollback desfaz apenas esta reconciliacao =====
    $aplicarProcedure($pdo, $MIGR);
    $aplicarPlain($pdo, $ROLLBACK);
    foreach (array_keys($RECONCILIAR) as $id) {
        $r = $pdo->query("SELECT codigo_setor, origem_metadados FROM setores WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $assert($r['codigo_setor'] === null && $r['origem_metadados'] === null, "Caso 5: rollback deveria soltar codigo e origem do id {$id}.");
    }

    echo "OK integration_reconciliar_setores_locais_metadados\n";
} finally {
    $restaurar();
}
