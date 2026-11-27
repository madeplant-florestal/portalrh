<?php

/**
 * Integração — versão publicada e data do commit no Manual (VersaoPublicadaService + scripts/generate_build_info.php + manual.php).
 *
 * Usa repositórios Git TEMPORÁRIOS (fixtures criadas com o binário git só para montar o cenário; o código testado NUNCA executa git):
 *   - versão `git-<hash de 7>` e data do COMMIT (committer date) em DD/MM/AAAA HH:MM, America/Campo_Grande;
 *   - objetos soltos, empacotados (clone) e após gc + packed-refs; HEAD destacado; novo commit atualiza a versão;
 *   - sem `.git` e sem build.php com versão real: estado neutro (nunca '1.0.0');
 *   - build.php: só `git-<hash>` é aceito; o gerador (mesmo leitor) escreve SÓ versão e data, sem shell/`2>NUL`;
 *   - o Manual real (AdminManualController) mostra a versão e a data do commit em execução e mantém o WhatsApp;
 *   - nenhuma chamada externa, shell ou filemtime no leitor.
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

$raizProjeto = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzversao_' . bin2hex(random_bytes(4));
$git = static function (string $dir, array $args, array $env = []): string {
    $p = proc_open(array_merge(['git', '-C', $dir], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $o = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return trim($o);
};
$apagar = static function (string $dir) use (&$apagar): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        @chmod($f->getPathname(), 0777);
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
};
$commitar = static function (string $repo, string $arquivo, string $dataUtc) use ($git): string {
    file_put_contents($repo . DIRECTORY_SEPARATOR . $arquivo, random_bytes(8));
    $git($repo, ['add', '-A']);
    $env = ['GIT_COMMITTER_DATE' => $dataUtc, 'GIT_AUTHOR_DATE' => '2020-01-01T00:00:00+00:00', 'GIT_AUTHOR_NAME' => 'ZZ', 'GIT_AUTHOR_EMAIL' => 'zz@teste.local', 'GIT_COMMITTER_NAME' => 'ZZ', 'GIT_COMMITTER_EMAIL' => 'zz@teste.local'];
    $git($repo, ['commit', '-q', '-m', 'zz ' . $arquivo], $env);
    return $git($repo, ['rev-parse', 'HEAD']);
};

try {
    if ($git($raizProjeto, ['--version']) === '') {
        echo "SKIP integration_versao_publicada (binário git indisponível para montar as fixtures)\n";
        exit(0);
    }
    $repo = $tmp . DIRECTORY_SEPARATOR . 'repo';
    mkdir($repo, 0777, true);
    $git($repo, ['init', '-q', '-b', 'main']);
    // 15/01/2026 22:30 UTC = 18:30 em Campo Grande (UTC-4) — prova a conversão de fuso
    $h1 = $commitar($repo, 'a.txt', '2026-01-15T22:30:00+00:00');

    $r = VersaoPublicadaService::doGit($repo);
    $check(($r['versao'] ?? null) === 'git-' . substr($h1, 0, 7) && ($r['hash'] ?? null) === $h1, '(git) versão = git-<hash abreviado (7)> do HEAD');
    $check(VersaoPublicadaService::dataFormatada($r['data_commit'] ?? null) === '15/01/2026 18:30', '(data) data do COMMIT (não do autor) em DD/MM/AAAA HH:MM, convertida para America/Campo_Grande');
    $check(preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', (string)VersaoPublicadaService::dataFormatada($r['data_commit'])) === 1, '(data) formato brasileiro');

    $h2 = $commitar($repo, 'b.txt', '2026-03-02T03:05:00+00:00'); // 01/03/2026 23:05 em Campo Grande (vira o dia)
    $r2 = VersaoPublicadaService::doGit($repo);
    $check(($r2['versao'] ?? null) === 'git-' . substr($h2, 0, 7) && $h2 !== $h1, '(atualização) novo commit = nova versão, sem configuração manual');
    $check(VersaoPublicadaService::dataFormatada($r2['data_commit']) === '01/03/2026 23:05', '(fuso) virada de dia UTC→Campo Grande correta');

    $clone = $tmp . DIRECTORY_SEPARATOR . 'clone';
    $git($tmp, ['clone', '-q', '--no-local', $repo, $clone]);
    $soltos = glob($clone . '/.git/objects/[0-9a-f][0-9a-f]/*') ?: [];
    $rc = VersaoPublicadaService::doGit($clone);
    $check($soltos === [] && ($rc['versao'] ?? null) === 'git-' . substr($h2, 0, 7) && VersaoPublicadaService::dataFormatada($rc['data_commit']) === '01/03/2026 23:05', '(pack) clone do deploy (objetos empacotados): versão e data lidas do pack');
    $git($clone, ['gc', '-q', '--aggressive']);
    $git($clone, ['pack-refs', '--all']);
    @unlink($clone . '/.git/refs/heads/main');
    $rg = VersaoPublicadaService::doGit($clone);
    $check(($rg['hash'] ?? null) === $h2 && $rg['data_commit'] !== null, '(pack) após gc + ref só em packed-refs: continua lendo');
    $git($repo, ['checkout', '-q', '--detach', $h1]);
    $rd = VersaoPublicadaService::doGit($repo);
    $check(($rd['hash'] ?? null) === $h1, '(HEAD destacado) versão = commit em checkout, não a ponta da branch');

    $semGit = $tmp . DIRECTORY_SEPARATOR . 'semgit';
    mkdir($semGit);
    $check(VersaoPublicadaService::doGit($semGit) === null, '(sem .git) leitor devolve null');
    $check(VersaoPublicadaService::daConfiguracao(['version' => '1.0.0', 'release_date' => '']) === null, '(build) versão padrão 1.0.0 NÃO é tratada como versão publicada');
    $check(VersaoPublicadaService::daConfiguracao([]) === null, '(build) sem metadados: null (Manual mostra estado neutro)');
    $b = VersaoPublicadaService::daConfiguracao(['version' => 'git-abcdef123456', 'release_date_iso' => '2026-01-15T22:30:00+00:00']);
    $check(($b['versao'] ?? null) === 'git-abcdef1' && VersaoPublicadaService::dataFormatada($b['data_commit']) === '15/01/2026 18:30' && ($b['fonte'] ?? '') === 'build', '(build) build.php com versão real: aceito, abreviado e com a data do commit');

    // gerador: mesmo leitor, só metadados públicos, sem shell
    $proj = $tmp . DIRECTORY_SEPARATOR . 'proj';
    mkdir($proj . '/scripts', 0777, true);
    mkdir($proj . '/app/services', 0777, true);
    copy($raizProjeto . '/scripts/generate_build_info.php', $proj . '/scripts/generate_build_info.php');
    copy($raizProjeto . '/app/services/VersaoPublicadaService.php', $proj . '/app/services/VersaoPublicadaService.php');
    $git($proj, ['init', '-q', '-b', 'main']);
    $hp = $commitar($proj, 'x.txt', '2026-11-27T12:17:43+00:00');
    $p = proc_open([PHP_BINARY, $proj . '/scripts/generate_build_info.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $rcG = proc_close($p);
    $build = is_file($proj . '/app/config/build.php') ? require $proj . '/app/config/build.php' : null;
    $check($rcG === 0 && ($build['app']['version'] ?? null) === 'git-' . substr($hp, 0, 7) && ($build['app']['release_date_iso'] ?? null) === '2026-11-27T12:17:43+00:00', '(gerador) build.php com a versão e a data do commit');
    $check(array_keys($build) === ['app'] && array_keys($build['app']) === ['version', 'release_date', 'release_date_iso'], '(gerador) build.php contém SOMENTE metadados públicos de versão');
    $check(str_contains($saida, '08:17'), '(gerador) informa a data no horário de Campo Grande');
    $semGitProj = $tmp . DIRECTORY_SEPARATOR . 'proj2';
    mkdir($semGitProj . '/scripts', 0777, true);
    mkdir($semGitProj . '/app/services', 0777, true);
    copy($raizProjeto . '/scripts/generate_build_info.php', $semGitProj . '/scripts/generate_build_info.php');
    copy($raizProjeto . '/app/services/VersaoPublicadaService.php', $semGitProj . '/app/services/VersaoPublicadaService.php');
    $p = proc_open([PHP_BINARY, $semGitProj . '/scripts/generate_build_info.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $check(proc_close($p) === 1 && !is_file($semGitProj . '/app/config/build.php'), '(gerador) sem .git: falha controlada, nenhum build.php fictício');

    $semComentarios = static function (string $arq): string {
        $codigo = '';
        foreach (token_get_all((string)file_get_contents($arq)) as $t) {
            $codigo .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $codigo;
    };
    $fonte = $semComentarios($raizProjeto . '/app/services/VersaoPublicadaService.php') . $semComentarios($raizProjeto . '/scripts/generate_build_info.php');
    $check(!preg_match('/shell_exec|\bexec\(|proc_open|passthru|curl_|https?:\/\/|filemtime|2>NUL/i', $fonte), '(segurança/compatibilidade) leitor e gerador sem shell, sem rede, sem filemtime, sem sintaxe Windows (2>NUL)');

    // Manual real
    $admin = User::create('ZZVER Admin', 'zzver.admin.' . bin2hex(random_bytes(3)) . '@teste.local', password_hash('irrelevante', PASSWORD_BCRYPT), 'admin');
    User::setActiveStatus($admin, true);
    try {
        $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$admin, 'admin', '0', 'AdminManualController', 'index', '[]', '[]'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $html = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
    } finally {
        Database::conn()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$admin]);
    }
    VersaoPublicadaService::limparMemo();
    $atual = VersaoPublicadaService::atual();
    $esperadoVersao = (string)$atual['versao'];
    $esperadaData = (string)VersaoPublicadaService::dataFormatada($atual['data_commit']);
    $check($esperadoVersao !== '' && preg_match('/data-manual-versao>' . preg_quote($esperadoVersao, '/') . '</', $html) === 1, "(Manual) 'Versão publicada' = commit em execução ({$esperadoVersao})");
    $check($esperadaData !== '' && preg_match('/data-manual-atualizacao>' . preg_quote($esperadaData, '/') . '</', $html) === 1, "(Manual) 'Última atualização' = data do commit em Campo Grande ({$esperadaData})");
    $check(str_contains($html, 'Versão publicada') && str_contains($html, 'Última atualização') && str_contains($html, 'https://wa.me/5567993256260') && !str_contains($html, 'não informada na configuração') && !preg_match('/data-manual-versao>1\.0\.0</', $html), '(Manual) rótulos novos, WhatsApp mantido, sem "1.0.0" nem "não informada"');

    echo "\nVERSAO_PUBLICADA_OK\n";
} finally {
    $apagar($tmp);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
