<?php

/**
 * Integração — saneamento de credenciais versionadas (scripts CLI, configuração, dumps, Supervisor).
 *
 * Prova que:
 *   - scripts/reset_password_cli.php e scripts/init.php NÃO têm senha padrão: sem e-mail, sem senha (sem variável de ambiente e sem
 *     terminal), com senha como argumento ou fora da política, encerram com erro e NÃO alteram/criam nada;
 *   - com a senha pela variável de ambiente, funcionam; a saída nunca mostra a senha nem o hash; conta existente nunca tem a senha
 *     trocada pelo init;
 *   - User::ensureSupervisor nunca cria conta com senha vazia (conta existente continua só restaurada);
 *   - os arquivos de configuração versionados não trazem senha de Supervisor utilizável;
 *   - os dumps versionados não contêm hash de senha utilizável e preservam a linha id 1 (relacionamentos).
 * Usa só contas fictícias (zzcli.*@teste.local), removidas no final.
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

$raiz = dirname(__DIR__, 2);
$pdo = Database::conn();
$suf = substr((string)time(), -5) . random_int(10, 99);
$emails = [];

/** Executa um script CLI com ambiente controlado e STDIN NÃO interativo. @return array{0:int,1:string} [exit code, stdout+stderr] */
$rodar = static function (string $script, array $args, array $env = []) use ($raiz): array {
    $ambiente = getenv();
    foreach (['RH_NOVA_SENHA', 'RH_ADMIN_SENHA'] as $v) {
        unset($ambiente[$v]);
    }
    $p = proc_open(array_merge([PHP_BINARY, $raiz . '/scripts/' . $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, array_merge($ambiente, $env));
    fclose($pipes[0]);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), (string)$saida];
};
$hashDe = static function (string $email) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $h = $st->fetchColumn();
    return $h === false ? null : (string)$h;
};

$senhaForte = 'ZzCliForte#2026x';
$senhaForte2 = 'ZzCliOutra#2026y';

try {
    // ---- reset_password_cli.php -----------------------------------------------------------------------------------------------
    $alvo = 'zzcli.reset.' . $suf . '@teste.local';
    $emails[] = $alvo;
    $id = User::create('ZZCLI Reset', $alvo, password_hash($senhaForte, PASSWORD_BCRYPT), 'viewer');
    $hashOriginal = $hashDe($alvo);

    [$rc, $out] = $rodar('reset_password_cli.php', []);
    $check($rc === 1 && str_contains($out, 'Informe o e-mail'), '(reset) sem e-mail: erro controlado, sem conta padrão');
    [$rc, $out] = $rodar('reset_password_cli.php', [$alvo]);
    $check($rc === 1 && str_contains($out, 'Senha não informada') && $hashDe($alvo) === $hashOriginal, '(reset) sem senha (sem variável e sem terminal): erro e senha NÃO alterada');
    [$rc, $out] = $rodar('reset_password_cli.php', [$alvo, $senhaForte2]);
    $check($rc === 1 && str_contains($out, 'Não informe a senha como argumento') && $hashDe($alvo) === $hashOriginal && !str_contains($out, $senhaForte2), '(reset) senha como argumento: recusada, nada alterado e a senha não é ecoada');
    [$rc, $out] = $rodar('reset_password_cli.php', [$alvo], ['RH_NOVA_SENHA' => 'fraca']);
    $check($rc === 1 && str_contains($out, 'política') && $hashDe($alvo) === $hashOriginal && !str_contains($out, 'fraca'), '(reset) senha fora da política: recusada sem exibir a senha');
    [$rc, $out] = $rodar('reset_password_cli.php', [$alvo], ['RH_NOVA_SENHA' => $senhaForte2]);
    $novoHash = (string)$hashDe($alvo);
    $check($rc === 0 && password_verify($senhaForte2, $novoHash), '(reset) senha pela variável de ambiente: atualizada');
    $check(!str_contains($out, $senhaForte2) && !str_contains($out, $novoHash) && !preg_match('/\$2y\$\d\d\$/', $out), '(reset) a saída não mostra a senha nem o hash');
    [$rc, $out] = $rodar('reset_password_cli.php', ['zzcli.inexistente.' . $suf . '@teste.local'], ['RH_NOVA_SENHA' => $senhaForte2]);
    $check($rc === 1 && str_contains($out, 'não encontrado') && !str_contains($out, $senhaForte2) && !preg_match('/\$2y\$\d\d\$/', $out) && !str_contains($out, 'UPDATE usuarios'), '(reset) conta inexistente: erro sem hash nem SQL de "fallback" com credencial');

    // ---- init.php ---------------------------------------------------------------------------------------------------------------
    $novoAdmin = 'zzcli.init.' . $suf . '@teste.local';
    $emails[] = $novoAdmin;
    [$rc, $out] = $rodar('init.php', []);
    $check($rc === 1 && str_contains($out, 'Informe o e-mail'), '(init) sem e-mail: nenhuma conta padrão é criada');
    [$rc, $out] = $rodar('init.php', [$novoAdmin]);
    $check($rc === 1 && $hashDe($novoAdmin) === null, '(init) sem senha: conta NÃO criada');
    [$rc, $out] = $rodar('init.php', [$novoAdmin, $senhaForte]);
    $check($rc === 1 && $hashDe($novoAdmin) === null && !str_contains($out, $senhaForte), '(init) senha como argumento: recusada, conta NÃO criada');
    [$rc, $out] = $rodar('init.php', [$novoAdmin], ['RH_ADMIN_SENHA' => $senhaForte]);
    $hAdmin = (string)$hashDe($novoAdmin);
    $check($rc === 0 && password_verify($senhaForte, $hAdmin) && !str_contains($out, $senhaForte), '(init) com a variável de ambiente: administrador criado, senha não exibida');
    [$rc, $out] = $rodar('init.php', [$novoAdmin], ['RH_ADMIN_SENHA' => $senhaForte2]);
    $check($rc === 0 && $hashDe($novoAdmin) === $hAdmin && str_contains($out, 'senha NÃO alterada'), '(init) conta existente: a senha NÃO é trocada');

    // ---- Supervisor ---------------------------------------------------------------------------------------------------------------
    $supNovo = 'zzcli.sup.' . $suf . '@teste.local';
    $emails[] = $supNovo;
    $lancou = false;
    try {
        User::ensureSupervisor('ZZCLI Sup', $supNovo, '');
    } catch (InvalidArgumentException) {
        $lancou = true;
    }
    $check($lancou && $hashDe($supNovo) === null, '(supervisor) senha vazia: conta NUNCA criada');
    $antes = $hashDe($alvo);
    User::ensureSupervisor('ZZCLI Sup', $alvo, '');
    $check($hashDe($alvo) === $antes, '(supervisor) conta existente: só restaurada, senha intocada (proteção preservada)');
    $pdo->prepare('UPDATE usuarios SET role = ?, is_supervisor = 0 WHERE email = ?')->execute(['viewer', $alvo]);

    // ---- configuração versionada ------------------------------------------------------------------------------------------------
    foreach (['app/config/config.php', 'app/config/config.example.php'] as $arq) {
        $cfg = require $raiz . '/' . $arq;
        $check(($cfg['security']['supervisor_password'] ?? null) === '', "(config) {$arq}: sem senha de Supervisor versionada");
    }

    // ---- dumps --------------------------------------------------------------------------------------------------------------------
    foreach (['recrutamento.sql', 'database/recrutamento.sql'] as $arq) {
        $sql = (string)file_get_contents($raiz . '/' . $arq);
        $check(!preg_match('/\$2[aby]\$\d\d\$/', $sql), "(dump) {$arq}: nenhum hash bcrypt");
        $ok = preg_match("/INSERT INTO `usuarios` VALUES \\(1,'[^']*','([^']*)','([^']*)'/", $sql, $m) === 1;
        $check($ok && str_ends_with($m[1], '.invalid') && password_get_info($m[2])['algo'] === null, "(dump) {$arq}: linha id 1 preservada com e-mail fictício e senha_hash inutilizável");
        $check($ok && !password_verify('', $m[2]) && !password_verify($m[2], $m[2]), "(dump) {$arq}: nenhuma senha autentica a conta do dump");
    }
    echo "\nSCRIPTS_SEM_SENHA_PADRAO_OK\n";
} finally {
    foreach ($emails as $e) {
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $st->execute([$e]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$uid, $uid]);
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$uid]);
        }
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
