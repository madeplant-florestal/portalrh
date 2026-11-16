<?php

/**
 * Integração — Frente D / D3: recuperação de senha com UM referencial temporal (UTC). A duração do token (30 min) não
 * muda; expirados são recusados; válidos não expiram antes por diferença de fuso do PHP; tokens já existentes
 * (gravados em UTC) continuam válidos.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

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

$fx = new ExclusaoFixtures($pdo);
$utc = new DateTimeZone('UTC');
$uid = 0;
try {
    $uid = $fx->usuario('ZZFZ Senha', 'viewer');
    $base = new DateTimeImmutable('2026-11-13 13:30:00', $utc);

    foreach (['UTC', 'Europe/Berlin', 'America/Campo_Grande', 'Asia/Tokyo'] as $tz) {
        date_default_timezone_set($tz);
        $token = bin2hex(random_bytes(16));
        PasswordReset::create($uid, $token, 30, $base);
        $exp = (string)$pdo->query("SELECT expires_at FROM password_resets WHERE usuario_id = {$uid} AND used_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
        $check($exp === '2026-11-13 14:00:00', "[PHP em {$tz}] expira exatamente 30 min depois, em UTC (obtido {$exp})");
        $check(PasswordReset::findValidByToken($token, $base->modify('+29 minutes +59 seconds')) !== null, "[PHP em {$tz}] válido a 29:59 (não expira antes por fuso)");
        $check(PasswordReset::findValidByToken($token, $base->modify('+30 minutes')) === null, "[PHP em {$tz}] expirado exatamente aos 30:00 é recusado");
        $check(PasswordReset::findValidByToken($token, $base->modify('+31 minutes')) === null, "[PHP em {$tz}] expirado aos 31 min é recusado");
        $check(PasswordReset::findValidByToken($token, $base->modify('-5 minutes')) !== null, "[PHP em {$tz}] relógio atrasado não invalida o token");
    }
    date_default_timezone_set('UTC');

    // token sem "agora" injetado: usa o instante real em UTC e vale ~30 min
    $t2 = bin2hex(random_bytes(16));
    PasswordReset::create($uid, $t2);
    $check(PasswordReset::findValidByToken($t2) !== null, 'token recém-criado (sem relógio injetado) é válido agora');
    $dur = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, created_at, expires_at) FROM password_resets WHERE usuario_id = {$uid} AND used_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check($dur >= 29 && $dur <= 30, "duração efetiva do token ≈ 30 min medida pelo banco (obtido {$dur} min)");

    // compatibilidade: token legado gravado em UTC (como em produção) segue válido até expirar
    $t3 = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO password_resets (usuario_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$uid, hash('sha256', $t3), DateHelper::paraUtcSql(DateHelper::agoraUtc()->modify('+10 minutes'))]);
    $check(PasswordReset::findValidByToken($t3) !== null, 'token legado (expires_at UTC) continua válido');
    PasswordReset::markUsed((int)$pdo->query("SELECT id FROM password_resets WHERE token_hash = '" . hash('sha256', $t3) . "'")->fetchColumn());
    $check(PasswordReset::findValidByToken($t3) === null, 'token usado é recusado');
} finally {
    if ($uid > 0) {
        $pdo->exec("DELETE FROM password_resets WHERE usuario_id = {$uid}");
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — D3 recuperação de senha (fuso).\n";
