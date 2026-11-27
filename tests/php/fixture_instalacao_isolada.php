<?php

/**
 * Fixture (NÃO é teste): executa os passos de instalação num banco TEMPORÁRIO e isolado (nunca o banco configurado do Portal).
 * Uso: php fixture_instalacao_isolada.php <banco zz35*> schema            → Installer::importSchema (database/schema.sql) + SchemaManager::ensure
 *      php fixture_instalacao_isolada.php <banco zz35*> arquivo <arq.sql> → importa um SQL de referência (ex.: o dump legado saneado) + ensure
 * Depois aplica as garantias que a aplicação executa em tempo de execução no primeiro uso de candidaturas (Candidatura::ensure*).
 * Só aceita bancos com prefixo zz35 e só em CLI.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[, $banco, $modo, $arquivo] = $argv + ['', '', '', ''];
if (!preg_match('/^zz35[a-z0-9_]{4,40}$/', $banco)) {
    fwrite(STDERR, "banco inválido (exige prefixo zz35)\n");
    exit(2);
}
require __DIR__ . '/../../app/core/bootstrap.php';

$cfg = Config::app()['database'];
$dsn = preg_replace('/dbname=[^;]*/', 'dbname=' . $banco, (string)$cfg['dsn']);
$pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], ($cfg['options'] ?? []) + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+00:00'");
$prop = new ReflectionProperty(Database::class, 'pdo');
$prop->setAccessible(true);
$prop->setValue(null, $pdo); // a partir daqui, Database::conn() = banco temporário
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
    fwrite(STDERR, "conexão não aponta para o banco temporário\n");
    exit(3);
}

$log = static function (string $l): void {
};
if ($modo === 'schema') {
    $m = new ReflectionMethod(Installer::class, 'importSchema');
    $m->setAccessible(true);
    $m->invoke(null, $pdo, $log);
} elseif ($modo === 'arquivo' && is_file($arquivo)) {
    $sql = preg_replace('/^(CREATE DATABASE|USE )[^\n]*\n/m', '', (string)file_get_contents($arquivo));
    $pdo->exec($sql);
} else {
    fwrite(STDERR, "modo inválido\n");
    exit(2);
}
$m = new ReflectionMethod(Installer::class, 'runSchemaEnsure');
$m->setAccessible(true);
$m->invoke(null, $log);
foreach (['ensureHistoricoTable', 'ensureObservacoesColumn', 'ensureCpfColumn', 'ensureStageColumn', 'ensureIndicacaoColumns', 'ensureIndicacaoPaymentAuditTable'] as $metodo) {
    $r = new ReflectionMethod(Candidatura::class, $metodo);
    $r->setAccessible(true);
    $r->invoke(null);
}
echo "INSTALACAO_OK\n";
