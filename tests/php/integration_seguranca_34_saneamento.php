<?php

/**
 * Integração — Segurança 3.4: saneamento de arquivos sensíveis e proteção do instalador no próprio PHP.
 *   A. repositório: planilha real e relatórios de importação fora do Git; .gitignore impede planilhas em assets/ e public/assets/;
 *      dumps legados removidos (Segurança 3.5A; a homologação da instalação está em integration_schema_instalacao.php);
 *   B. importador: sem --file não importa nada (sem fallback); arquivo inexistente = erro; CSV FICTÍCIO explícito em --validate-only funciona;
 *   C. instalador: regra pura (desabilitado por padrão, chave forte obrigatória, instalado = bloqueado) e HTTP real SEM Apache
 *      (php -S, sem .htaccess): /install.php e /public/install.php negados por padrão; habilitado, POST sem/errado chave negado.
 *      O instalador NUNCA é executado com a chave correta neste teste.
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
$git = static function (array $args) use ($raiz): string {
    $p = proc_open(array_merge(['git', '-C', $raiz], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return trim($o);
};
$rodar = static function (array $cmd, array $env = []) use ($raiz): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, array_merge(getenv(), $env));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), (string)$out];
};

// ---- A. repositório e dumps ------------------------------------------------------------------------------------------------------------
$versionados = $git(['ls-files', 'assets', 'public/assets', 'storage/imports']);
$check(!str_contains($versionados, 'colaboradores.xlsx'), '(git) assets/colaboradores.xlsx fora do versionamento');
$check(preg_match('/\.(xlsx|xls|csv)$/mi', $versionados) === 0, '(git) nenhuma planilha versionada em assets/ ou public/assets/');
$check(preg_match('#^storage/imports/.*\.json$#m', $versionados) === 0, '(git) relatórios de importação (storage/imports/*.json) fora do versionamento');
$ignore = (string)file_get_contents($raiz . '/.gitignore');
foreach (['/assets/*.xlsx', '/assets/*.csv', '/public/assets/*.xlsx', 'storage/imports/*.json'] as $regra) {
    $check(str_contains($ignore, $regra), "(gitignore) contém {$regra}");
}
// Dumps legados: na 3.4 foram saneados; na 3.5A foram REMOVIDOS (a instalação usa só database/schema.sql — ver integration_schema_instalacao.php).
foreach (['recrutamento.sql', 'database/recrutamento.sql'] as $arq) {
    $check(!is_file($raiz . '/' . $arq) && strpos($git(['ls-files', $arq]), $arq) === false, "(dump) {$arq}: fora do repositório");
}

// ---- B. importador -------------------------------------------------------------------------------------------------------------------
$antes = glob($raiz . '/storage/imports/colaboradores-import-*.json') ?: [];
[$rc, $out] = $rodar([PHP_BINARY, $raiz . '/scripts/import_colaboradores_xlsx.php']);
$check($rc === 2 && str_contains($out, '--file=') && str_contains($out, 'Nada foi importado'), '(importador) sem --file: recusa, nenhum arquivo padrão');
$fonteImp = (string)file_get_contents($raiz . '/scripts/import_colaboradores_xlsx.php');
$semComentarios = '';
foreach (token_get_all($fonteImp) as $t) {
    $semComentarios .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
}
$check(!str_contains($semComentarios, 'colaboradores.xlsx') && !str_contains($semComentarios, "'assets'"), '(importador) código sem fallback para a planilha antiga');
[$rc, $out] = $rodar([PHP_BINARY, $raiz . '/scripts/import_colaboradores_xlsx.php', '--file=' . sys_get_temp_dir() . '/zz34-inexistente.xlsx', '--validate-only']);
$check($rc === 1 && str_contains($out, 'nao encontrado'), '(importador) arquivo inexistente: erro controlado');
$csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz34-' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents($csv, "COD;COLABORADOR;EMPRESA;CPF;ADMISSÃO;NASC.;CARGO;DEMISSÃO;MOTIVO RESCISÃO\n900;ZZ34 FICTICIO;ZZ34 EMPRESA;529.982.247-25;01/01/2024;01/01/1990;ANALISTA;;\n");
try {
    [$rc, $out] = $rodar([PHP_BINARY, $raiz . '/scripts/import_colaboradores_xlsx.php', '--file=' . $csv, '--validate-only']);
    $j = json_decode(trim($out), true);
    $check($rc === 0 && ($j['ok'] ?? false) === true && ($j['file_type'] ?? '') === 'csv', '(importador) caminho explícito (CSV fictício, --validate-only): validação OK, sem gravar no banco');
} finally {
    @unlink($csv);
    foreach (array_diff(glob($raiz . '/storage/imports/colaboradores-import-*.json') ?: [], $antes) as $novo) {
        @unlink($novo);
    }
}

// ---- C. instalador ------------------------------------------------------------------------------------------------------------------
$forte = str_repeat('k', Installer::CHAVE_MINIMA);
$a = static fn(...$p) => Installer::avaliarAcessoWeb(...$p);
$check(!$a(false, false, '', 'GET', '')['ok'], '(regra) padrão (sem habilitação nem chave): GET negado');
$check(!$a(false, false, $forte, 'POST', $forte)['ok'], '(regra) chave sem habilitação explícita: negado');
$check(!$a(false, true, '', 'GET', '')['ok'] && !$a(false, true, 'curta', 'POST', 'curta')['ok'], '(regra) habilitado sem chave ou com chave fraca (< 32): negado');
$check($a(false, true, $forte, 'GET', '')['ok'], '(regra) habilitado + chave forte: GET exibe o formulário');
$check(!$a(false, true, $forte, 'POST', '')['ok'] && !$a(false, true, $forte, 'POST', $forte . 'x')['ok'], '(regra) POST sem chave ou com chave errada: negado');
$check($a(false, true, $forte, 'POST', $forte)['ok'], '(regra) POST com a chave correta: permitido (só quando habilitado fora do Git)');
$check(!$a(true, true, $forte, 'POST', $forte)['ok'] && $a(true, true, $forte, 'POST', $forte)['mensagem'] === 'Instalador bloqueado: a aplicação já está instalada.', '(regra) instalação concluída: bloqueado mesmo habilitado e com a chave');
$fonteInst = (string)file_get_contents($raiz . '/app/core/Installer.php');
$check(str_contains($fonteInst, "if (PHP_SAPI !== 'cli')") && strpos($fonteInst, "if (PHP_SAPI !== 'cli')") < strpos($fonteInst, 'self::buildConfig($input)'), '(defesa) Installer::run recusa execução web sem habilitação, antes de qualquer escrita');
$public = (string)file_get_contents($raiz . '/public/install.php');
$check(trim(preg_replace('/^<\?php\s*/', '', $public)) === "require_once __DIR__ . '/../install.php';", '(caminhos) public/install.php só inclui install.php (mesma guarda, sem desvio)');

$hashes = static fn() => array_map(static fn($f) => is_file($f) ? hash_file('sha256', $f) : null, [$raiz . '/app/config/config.php', $raiz . '/app/config/local.php']);
$servidor = static function (string $docroot, array $env) {
    $porta = 8140 + random_int(0, 200);
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $docroot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $docroot, array_merge(getenv(), $env));
    return [$p, "http://127.0.0.1:{$porta}"];
};
$http = static function (string $url, string $metodo = 'GET', array $form = []): array {
    $ctx = stream_context_create(['http' => ['method' => $metodo, 'ignore_errors' => true, 'timeout' => 10, 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($form)]]);
    $corpo = false;
    for ($i = 0; $i < 30 && $corpo === false; $i++) {
        $corpo = @file_get_contents($url, false, $ctx);
        if ($corpo === false) {
            usleep(150000);
        }
    }
    $status = preg_match('#^HTTP/\S+ (\d{3})#', ($http_response_header[0] ?? ''), $m) ? (int)$m[1] : 0;
    return [$status, (string)$corpo];
};
$hAntes = $hashes();
$postMalicioso = ['csrf' => 'x', 'allow_overwrite_config' => '1', 'config_mode' => 'local', 'db_dsn' => 'mysql:host=127.0.0.1;dbname=zz', 'db_user' => 'zz', 'mail_from' => 'a@b.c', 'mail_to_hr' => 'a@b.c', 'supervisor_email' => 'zz@zz.zz', 'supervisor_password' => 'zz', 'admin_email' => 'zz@zz.zz', 'admin_password' => 'ZzzzZzzz1!zz'];
// sem Apache: php -S não lê .htaccess — prova que a proteção está no PHP
foreach (['raiz' => [$raiz, '/install.php'], 'public' => [$raiz . '/public', '/install.php']] as $nome => [$doc, $rota]) {
    [$p, $base] = $servidor($doc, ['RH_INSTALADOR_WEB' => '', 'INSTALLER_KEY' => '']);
    try {
        [$st, $corpo] = $http($base . $rota);
        $check($st === 403 && trim($corpo) === 'Instalador web desabilitado.', "(http sem Apache, {$nome}) GET /install.php: 403 desabilitado por padrão");
        [$st, $corpo] = $http($base . $rota, 'POST', $postMalicioso + ['installer_key' => 'qualquer']);
        $check($st === 403 && !str_contains($corpo, 'Instalação concluída'), "(http sem Apache, {$nome}) POST anônimo: 403, instalador não executa");
    } finally {
        proc_terminate($p);
        proc_close($p);
    }
}
[$p, $base] = $servidor($raiz, ['RH_INSTALADOR_WEB' => '1', 'INSTALLER_KEY' => $forte]);
try {
    [$st, $corpo] = $http($base . '/install.php');
    $esperado = Installer::isInstalled() ? 403 : 200;
    $check($st === $esperado && ($esperado === 403 || str_contains($corpo, 'name="installer_key"')), '(http) habilitado fora do Git: GET mostra o formulário com a chave obrigatória (ou 403 se já instalado)');
    [$st] = $http($base . '/install.php', 'POST', $postMalicioso);
    [$st2] = $http($base . '/install.php', 'POST', $postMalicioso + ['installer_key' => $forte . 'errada']);
    $check($st === 403 && $st2 === 403, '(http) habilitado: POST sem chave ou com chave errada = 403');
} finally {
    proc_terminate($p);
    proc_close($p);
}
$check($hashes() === $hAntes, '(integridade) config.php e local.php intactos após todas as tentativas');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nSEGURANCA_34_OK\n";
