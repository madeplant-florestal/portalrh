<?php

/**
 * Integração — Etapa 2C.1: a migration 2026-11-22-usuarios-role-gestor.sql e o rollback, executados num SCHEMA ISOLADO (cópia da
 * estrutura de `usuarios`; o banco de desenvolvimento NÃO é tocado). Prova que: o ALTER só acrescenta 'gestor' ao fim e preserva
 * charset/collation/NOT NULL/DEFAULT; nenhuma linha muda; reexecutar é inofensivo; o rollback é BLOQUEADO enquanto existir conta
 * 'gestor' (mesmo com modo SQL permissivo) sem converter ninguém; e funciona quando não há contas 'gestor'.
 * Os arquivos SQL são executados como o phpMyAdmin os executaria (instrução por instrução, comentários ignorados).
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
$principal = Database::conn();
$nomeSchema = 'zz_mig_gestor_' . substr((string)time(), -6) . random_int(10, 99);
$principal->exec("CREATE DATABASE `{$nomeSchema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $nomeSchema, (string)$cfg['dsn']);
$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

/** Executa um arquivo .sql instrução por instrução (comentários -- ignorados) e devolve o resultado de cada SELECT. */
$executar = static function (string $arquivo) use ($pdo): array {
    $sql = '';
    foreach (preg_split('/\R/', (string)file_get_contents($arquivo)) as $linha) {
        if (!str_starts_with(ltrim($linha), '--')) {
            $sql .= $linha . "\n";
        }
    }
    $saidas = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $instrucao) {
        $st = $pdo->query($instrucao);
        if ($st !== false && $st->columnCount() > 0) {
            $saidas[] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    return $saidas;
};
$definicao = static fn(): array => $pdo->query(
    "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'role'"
)->fetch(PDO::FETCH_ASSOC);
$contagem = static fn(): array => $pdo->query('SELECT role, COUNT(*) c FROM usuarios GROUP BY role ORDER BY role')->fetchAll(PDO::FETCH_KEY_PAIR);

$mig = __DIR__ . '/../../database/migrations/2026-11-22-usuarios-role-gestor.sql';
$rol = __DIR__ . '/../../database/migrations/2026-11-22-usuarios-role-gestor-rollback.sql';

try {
    // schema isolado com a MESMA definição de usuarios do ambiente de desenvolvimento
    $pdo->exec("CREATE TABLE usuarios LIKE `{$principal->query('SELECT DATABASE()')->fetchColumn()}`.usuarios");
    // baseline = a definição ANTERIOR à migration (o banco de desenvolvimento já pode estar migrado)
    $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN role ENUM('admin','rh','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'viewer'");
    $original = $definicao();
    $check($original['COLUMN_TYPE'] === "enum('admin','rh','viewer')" && $original['IS_NULLABLE'] === 'NO' && $original['COLUMN_DEFAULT'] === 'viewer', '(setup) o schema isolado nasce com enum(admin,rh,viewer) NOT NULL DEFAULT viewer');
    foreach ([['admin', 1], ['admin', 0], ['rh', 0], ['viewer', 0], ['viewer', 0]] as $i => [$role, $flag]) {
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor) VALUES (?, ?, ?, ?, ?)')->execute(["U{$i}", "u{$i}@t.local", 'x', $role, $flag]);
    }
    $antes = $contagem();
    $linhasAntes = $pdo->query('SELECT id, role, is_supervisor FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    // ---- migration ----
    $saidas = $executar($mig);
    $depois = $definicao();
    $check($depois['COLUMN_TYPE'] === "enum('admin','rh','viewer','gestor')", '(migration) o ENUM passa a enum(admin,rh,viewer,gestor) — valor novo no FIM da lista');
    $check($depois['IS_NULLABLE'] === $original['IS_NULLABLE'] && $depois['COLUMN_DEFAULT'] === $original['COLUMN_DEFAULT'] && $depois['CHARACTER_SET_NAME'] === $original['CHARACTER_SET_NAME'] && $depois['COLLATION_NAME'] === $original['COLLATION_NAME'], '(migration) NOT NULL, DEFAULT, charset e collation preservados');
    $check($contagem() === $antes && $pdo->query('SELECT id, role, is_supervisor FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $linhasAntes, '(migration) NENHUM usuário mudou de perfil ou de flag; nenhum "gestor" foi criado');
    $check(isset($saidas[0][0]['COLUMN_TYPE']) && str_contains($saidas[0][0]['COLUMN_TYPE'], "'gestor'"), '(migration) o bloco de verificação do próprio script mostra o ENUM novo');
    $executar($mig);
    $check($definicao() === $depois, '(migration) reexecutar é inofensivo (mesma definição)');
    $pdo->exec("INSERT INTO usuarios (nome, email, senha_hash) VALUES ('Sem Role', 'semrole@t.local', 'x')");
    $check($pdo->query("SELECT role FROM usuarios WHERE email = 'semrole@t.local'")->fetchColumn() === 'viewer', '(migration) o DEFAULT continua viewer');
    $pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, role) VALUES ('G1', 'g1@t.local', 'x', 'gestor')");
    $check($pdo->query("SELECT role FROM usuarios WHERE email = 'g1@t.local'")->fetchColumn() === 'gestor', '(migration) uma conta gestor passa a ser aceita');

    // ---- rollback bloqueado com conta gestor ----
    $bloqueou = false;
    try {
        $executar($rol);
    } catch (PDOException) {
        $bloqueou = true;
    }
    $check($bloqueou, '(rollback) BLOQUEADO enquanto existir conta gestor (o ALTER falha)');
    $check($definicao()['COLUMN_TYPE'] === "enum('admin','rh','viewer','gestor')" && $pdo->query("SELECT role FROM usuarios WHERE email = 'g1@t.local'")->fetchColumn() === 'gestor', '(rollback) bloqueado: o ENUM e a conta gestor ficam INTACTOS — ninguém foi convertido');

    // mesmo com modo SQL permissivo na sessão, a trava do script impede a conversão silenciosa
    $pdo->exec("SET SESSION sql_mode = ''");
    $bloqueou = false;
    try {
        $executar($rol);
    } catch (PDOException) {
        $bloqueou = true;
    }
    $check($bloqueou && $pdo->query("SELECT role FROM usuarios WHERE email = 'g1@t.local'")->fetchColumn() === 'gestor', '(rollback) com sql_mode permissivo continua bloqueado (a trava STRICT do script) — nunca vira role vazia');

    // ---- rollback liberado após decisão administrativa (sem contas gestor) ----
    $pdo->exec("DELETE FROM usuarios WHERE role = 'gestor'");
    $executar($rol);
    $check($definicao() === $original, '(rollback) sem contas gestor: volta exatamente à definição original');
    $esperado = $antes;
    $esperado['viewer'] = ($esperado['viewer'] ?? 0) + 1; // a conta 'Sem Role' criada no teste (DEFAULT viewer)
    $check($contagem() === $esperado, '(rollback) as demais contas permanecem como estavam');
    $executar($mig);
    $check($definicao()['COLUMN_TYPE'] === "enum('admin','rh','viewer','gestor')", '(ciclo) a migration pode ser reaplicada após o rollback');
} finally {
    $principal->exec("DROP DATABASE IF EXISTS `{$nomeSchema}`");
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — migration e rollback do perfil gestor (schema isolado).\n";
