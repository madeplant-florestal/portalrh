<?php

/**
 * Integração — migration 2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql (+ rollback).
 *
 * Roda a migration e o rollback REAIS (os arquivos .sql do repositório) contra um BANCO DESCARTÁVEL
 * (`rhm_zzmig_*`, criado e removido por este teste) — nunca toca o banco da aplicação. Usa só dados
 * fictícios (nada de produção). Duas variantes do schema:
 *   A) "produção": sem chaves estrangeiras na tabela de exclusões, collation das colunas de origem
 *      DIFERENTE do default do banco (prova que a migration copia a collation da origem);
 *   B) "desenvolvimento": com fk_cmea_metadados / fk_cmea_usuario como em 2026-10-29.
 *
 * Prova:
 *   - migração das exclusões existentes: id, motivo, usuário, data e metadados_id originais
 *     preservados; chave natural preenchida a partir de metadados_id; nenhuma linha recriada;
 *     contratos originais intactos;
 *   - NOT NULL na chave natural, metadados_id NULLable, UNIQUE da chave natural, sem FK nova;
 *   - idempotência (segunda execução não altera nada);
 *   - rollback restaura metadados_id (inclusive de exclusões criadas só pela chave natural) e não
 *     perde nenhuma exclusão; idempotente;
 *   - aborta com erro explícito, SEM endurecer o schema, quando há exclusão órfã (variante A).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$cfg = Config::app()['database'];
if (!preg_match('/dbname=([^;]+)/', (string)$cfg['dsn'], $mDb)) {
    fwrite(STDERR, "DSN sem dbname — não dá para criar o banco descartável.\n");
    exit(1);
}
$dsnSemBanco = preg_replace('/;?dbname=[^;]+/', '', (string)$cfg['dsn']);

$novoPdo = static function (?string $banco) use ($cfg, $dsnSemBanco): PDO {
    $dsn = $banco === null ? $dsnSemBanco : $dsnSemBanco . ';dbname=' . $banco;
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true, // PREPARE/EXECUTE do arquivo .sql rodam como texto
    ]);
};

/** Executa um arquivo .sql do repositório (statements separados por `;` no fim de linha). */
$rodarArquivo = static function (PDO $pdo, string $arquivo): void {
    $linhas = [];
    foreach (preg_split('/\R/', (string)file_get_contents($arquivo)) as $linha) {
        if (str_starts_with(ltrim($linha), '--')) {
            continue;
        }
        $linhas[] = $linha;
    }
    foreach (preg_split('/;\s*\n/', implode("\n", $linhas) . "\n") as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $res = $pdo->query($stmt);
        if ($res instanceof PDOStatement) {
            $res->fetchAll();
            $res->closeCursor();
        }
    }
};

$tentar = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

$migration = __DIR__ . '/../../database/migrations/2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql';
$rollback = __DIR__ . '/../../database/migrations/2026-11-11-colaboradores-metadados-exclusoes-chave-natural-rollback.sql';
$check(is_file($migration) && is_file($rollback), 'Migration e rollback existem');

$admin = $novoPdo(null);
$bancos = [];

$variante = static function (string $nome, bool $comFk) use ($admin, $novoPdo, &$bancos, $check, $rodarArquivo, $tentar, $migration, $rollback): void {
    echo "\n=== Variante {$nome} ===\n";
    $banco = 'rhm_zzmig_' . strtolower($nome) . '_' . substr((string)time(), -5) . random_int(10, 99);
    $bancos[] = $banco;
    // Collation do banco DIFERENTE da das colunas de origem (general_ci) para provar a cópia.
    $admin->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = $novoPdo($banco);

    $pdo->exec("CREATE TABLE usuarios (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE colaboradores_metadados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identificador VARCHAR(60) NOT NULL,
        codigo_empresa VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
        codigo_unidade VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
        numero_contrato VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
        nome VARCHAR(160) NOT NULL,
        UNIQUE KEY uk_colaboradores_metadados_vinculo (codigo_empresa, codigo_unidade, numero_contrato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $fk = $comFk
        ? ", CONSTRAINT fk_cmea_metadados FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
             CONSTRAINT fk_cmea_usuario FOREIGN KEY (excluido_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL"
        : '';
    $pdo->exec("CREATE TABLE colaboradores_metadados_exclusoes_analiticas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        metadados_id INT NOT NULL,
        motivo TEXT NOT NULL,
        excluido_por_usuario_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cmea_metadados (metadados_id),
        KEY idx_cmea_usuario (excluido_por_usuario_id){$fk}
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO usuarios (id, nome) VALUES (1, 'Admin Ficticio')");
    // Ids "de produção" deliberadamente altos/esparsos + um contrato NÃO excluído com dados parecidos.
    $pdo->exec("INSERT INTO colaboradores_metadados (id, identificador, codigo_empresa, codigo_unidade, numero_contrato, nome) VALUES
        (61,  '0002-0002-9',   '0002', '0002', '9',   'PESSOA FICTICIA A'),
        (556, '0003-0003-1',   '0003', '0003', '1',   'PESSOA FICTICIA B'),
        (571, '0002-0002-347', '0002', '0002', '347', 'PESSOA FICTICIA C'),
        (700, '0002-0002-10',  '0002', '0002', '10',  'PESSOA FICTICIA NAO EXCLUIDA')");
    $pdo->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (id, metadados_id, motivo, excluido_por_usuario_id, created_at) VALUES
        (11, 61,  'motivo A', 1, '2026-11-10 10:00:01'),
        (12, 556, 'motivo B', 1, '2026-11-10 10:00:02'),
        (13, 571, 'motivo C', 1, '2026-11-10 10:00:03')");
    $contratosAntes = $pdo->query('SELECT id, identificador, codigo_empresa, codigo_unidade, numero_contrato, nome FROM colaboradores_metadados ORDER BY id')->fetchAll();

    // ---- migration -------------------------------------------------------------------------------
    $erro = $tentar(fn() => $rodarArquivo($pdo, $migration));
    $check($erro === null, "[{$nome}] Migration executa sem erro" . ($erro ? " ({$erro})" : ''));

    $linhas = $pdo->query('SELECT * FROM colaboradores_metadados_exclusoes_analiticas ORDER BY id')->fetchAll();
    $check(count($linhas) === 3, "[{$nome}] As 3 exclusões continuam (nenhuma recriada nem perdida)");
    $esperado = [
        11 => [61, '0002', '0002', '9', 'motivo A', '2026-11-10 10:00:01'],
        12 => [556, '0003', '0003', '1', 'motivo B', '2026-11-10 10:00:02'],
        13 => [571, '0002', '0002', '347', 'motivo C', '2026-11-10 10:00:03'],
    ];
    foreach ($linhas as $l) {
        $e = $esperado[(int)$l['id']] ?? null;
        $ok = $e !== null
            && (int)$l['metadados_id'] === $e[0]
            && $l['codigo_empresa'] === $e[1] && $l['codigo_unidade'] === $e[2] && $l['numero_contrato'] === $e[3]
            && $l['motivo'] === $e[4] && (int)$l['excluido_por_usuario_id'] === 1 && $l['created_at'] === $e[5];
        $check($ok, "[{$nome}] Exclusão #{$l['id']}: id, motivo, usuário, data e metadados_id originais preservados; chave natural preenchida");
    }
    $contratosDepois = $pdo->query('SELECT id, identificador, codigo_empresa, codigo_unidade, numero_contrato, nome FROM colaboradores_metadados ORDER BY id')->fetchAll();
    $check($contratosAntes === $contratosDepois, "[{$nome}] colaboradores_metadados (contratos originais) permanece intacta");

    $col = static fn(string $c) => $pdo->query(
        "SELECT IS_NULLABLE, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas' AND COLUMN_NAME = '{$c}'"
    )->fetch();
    foreach (['codigo_empresa', 'codigo_unidade', 'numero_contrato'] as $c) {
        $info = $col($c);
        $check(($info['IS_NULLABLE'] ?? '') === 'NO', "[{$nome}] {$c} é NOT NULL");
        $check(strcasecmp((string)($info['COLLATION_NAME'] ?? ''), 'utf8mb4_general_ci') === 0, "[{$nome}] {$c} copia a collation da origem (utf8mb4_general_ci, não a do banco)");
    }
    $check(($col('metadados_id')['IS_NULLABLE'] ?? '') === 'YES', "[{$nome}] metadados_id passa a aceitar NULL");
    $uk = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas' AND INDEX_NAME = 'uk_cmea_vinculo' AND NON_UNIQUE = 0"
    )->fetchColumn();
    $check((int)$uk === 3, "[{$nome}] UNIQUE uk_cmea_vinculo cobre as 3 colunas da chave natural");
    $fks = (int)$pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    $check($fks === ($comFk ? 2 : 0), "[{$nome}] A migration não cria nem remove chaves estrangeiras (esperado " . ($comFk ? 2 : 0) . ", veio {$fks})");

    // ---- exclusão nova só pela chave natural + UNIQUE ----------------------------------------------
    $pdo->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (codigo_empresa, codigo_unidade, numero_contrato, motivo, excluido_por_usuario_id)
                VALUES ('0002', '0002', '10', 'motivo novo', 1)");
    $dup = $tentar(fn() => $pdo->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (codigo_empresa, codigo_unidade, numero_contrato, motivo)
                                       VALUES ('0002', '0002', '10', 'duplicada')"));
    $check($dup !== null && str_contains($dup, 'uk_cmea_vinculo'), "[{$nome}] Segunda exclusão da mesma chave natural é rejeitada pelo UNIQUE");

    // ---- idempotência ------------------------------------------------------------------------------
    $antesReexec = $pdo->query('SELECT * FROM colaboradores_metadados_exclusoes_analiticas ORDER BY id')->fetchAll();
    $erro = $tentar(fn() => $rodarArquivo($pdo, $migration));
    $check($erro === null, "[{$nome}] Reexecutar a migration é idempotente (sem erro)" . ($erro ? " ({$erro})" : ''));
    $check($antesReexec === $pdo->query('SELECT * FROM colaboradores_metadados_exclusoes_analiticas ORDER BY id')->fetchAll(), "[{$nome}] Reexecução não altera nenhum dado");

    // ---- rollback ----------------------------------------------------------------------------------
    $erro = $tentar(fn() => $rodarArquivo($pdo, $rollback));
    $check($erro === null, "[{$nome}] Rollback executa sem erro" . ($erro ? " ({$erro})" : ''));
    $apos = $pdo->query('SELECT id, metadados_id, motivo, excluido_por_usuario_id, created_at FROM colaboradores_metadados_exclusoes_analiticas ORDER BY id')->fetchAll();
    $check(count($apos) === 4, "[{$nome}] Rollback não perde nenhuma exclusão (3 migradas + 1 só por chave natural)");
    $porMotivo = array_column($apos, 'metadados_id', 'motivo');
    $check((int)($porMotivo['motivo novo'] ?? 0) === 700, "[{$nome}] Rollback restaura metadados_id da exclusão criada só pela chave natural (contrato 700)");
    $check((int)($porMotivo['motivo A'] ?? 0) === 61 && (int)($porMotivo['motivo C'] ?? 0) === 571, "[{$nome}] Rollback mantém metadados_id das exclusões originais");
    $colsRestantes = (int)$pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
         AND COLUMN_NAME IN ('codigo_empresa','codigo_unidade','numero_contrato')"
    )->fetchColumn();
    $check($colsRestantes === 0, "[{$nome}] Rollback remove as colunas da chave natural");
    $check(($col('metadados_id')['IS_NULLABLE'] ?? '') === 'NO', "[{$nome}] Rollback devolve metadados_id a NOT NULL");
    $erro = $tentar(fn() => $rodarArquivo($pdo, $rollback));
    $check($erro === null, "[{$nome}] Reexecutar o rollback é idempotente" . ($erro ? " ({$erro})" : ''));

    // ---- migration de novo (ciclo completo) --------------------------------------------------------
    $erro = $tentar(fn() => $rodarArquivo($pdo, $migration));
    $check($erro === null, "[{$nome}] Migration reaplicada após rollback" . ($erro ? " ({$erro})" : ''));

    // ---- exclusão órfã: só sem FK (produção) --------------------------------------------------------
    if (!$comFk) {
        $erro = $tentar(fn() => $rodarArquivo($pdo, $rollback));
        $check($erro === null, "[{$nome}] (preparo) rollback antes do cenário órfão");
        $pdo->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (id, metadados_id, motivo, excluido_por_usuario_id)
                    VALUES (99, 999999, 'orfa', 1)"); // aponta para contrato inexistente
        $erro = $tentar(fn() => $rodarArquivo($pdo, $migration));
        $check($erro !== null && str_contains($erro, 'ERRO__existem_exclusoes_sem_vinculo_correspondente'), "[{$nome}] Exclusão órfã ABORTA a migration com erro explícito");
        $nullavel = $pdo->query(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas' AND COLUMN_NAME = 'codigo_empresa'"
        )->fetchColumn();
        $check($nullavel === 'YES', "[{$nome}] Abortada: o schema NÃO foi endurecido (chave natural ainda NULLable)");
        $check((int)$pdo->query('SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas')->fetchColumn() === 5, "[{$nome}] Abortada: nenhuma exclusão foi perdida (3 migradas + 1 só por chave natural + 1 órfã)");
        $pdo->exec('DELETE FROM colaboradores_metadados_exclusoes_analiticas WHERE id = 99'); // correção consciente
        $erro = $tentar(fn() => $rodarArquivo($pdo, $migration));
        $check($erro === null, "[{$nome}] Após corrigir a causa, reexecutar a migration conclui" . ($erro ? " ({$erro})" : ''));
    }
};

try {
    $variante('A', false);
    $variante('B', true);
} finally {
    foreach ($bancos as $b) {
        $admin->exec("DROP DATABASE IF EXISTS `{$b}`");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_MIGRATION_CHAVE_NATURAL_OK\n";
