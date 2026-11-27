<?php

/**
 * Unitário — proteção de arquivos internos no .htaccess da RAIZ (raiz publicada = raiz do repositório no deploy Git da hospedagem).
 * Aplica, com PCRE (o mesmo motor do mod_rewrite), as regras `RewriteRule <padrão> - [F...]` do arquivo real a caminhos que DEVEM ser negados
 * e a caminhos que DEVEM continuar servidos (assets, favicon, logos enviados, rotas da aplicação). Também exige que os bloqueios venham
 * ANTES do front controller e que `Options -Indexes` esteja ativo. Validação HTTP real (Apache): ver o relatório da tarefa.
 */

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
$ht = (string)file_get_contents($raiz . '/.htaccess');

$regras = [];
$posFront = strpos($ht, 'RewriteRule ^ index.php');
foreach (preg_split('/\R/', $ht) as $linha) {
    if (preg_match('/^\s*RewriteRule\s+(\S+)\s+-\s+\[([^\]]*)\]/', $linha, $m) && str_contains($m[2], 'F')) {
        $regras[] = ['re' => '#' . str_replace('#', '\#', $m[1]) . '#' . (stripos($m[2], 'NC') !== false ? 'i' : ''), 'pos' => strpos($ht, $linha)];
    }
}
$check(count($regras) >= 4, '(regras) há regras de bloqueio [F] no .htaccess da raiz');
$check($posFront !== false && array_filter($regras, static fn($r) => $r['pos'] > $posFront) === [], '(ordem) todos os bloqueios vêm ANTES do encaminhamento ao front controller');
$check((bool)preg_match('/^\s*Options\s+-Indexes/m', $ht), '(listagem) Options -Indexes ativo');

$negado = static function (string $caminho) use ($regras): bool {
    $rel = ltrim($caminho, '/'); // no .htaccess da raiz o mod_rewrite compara o caminho sem a barra inicial
    foreach ($regras as $r) {
        if (preg_match($r['re'], $rel)) {
            return true;
        }
    }
    return false;
};

$devemNegar = [
    '/.git/HEAD', '/.git/config', '/.git/objects/pack/pack-x.pack', '/.gitignore', '/.env', '/.htaccess', '/.cpanel.yml', '/.serena/project.yml',
    '/.claude/settings.json', '/.dbg/log.ndjson', '/recrutamento.sql', '/database/recrutamento.sql', '/database/migrations/x.sql',
    '/composer.json', '/composer.lock', '/package.json', '/package-lock.json', '/playwright.config.js', '/tailwind.config.js', '/postcss.config.js',
    '/deploy.ps1', '/router.php', '/CLAUDE.md', '/README.md', '/docs/claude/arquitetura.md', '/vendor/autoload.php', '/vendor/composer/installed.json',
    '/tests/php/run_all.php', '/tests/php/integration_favicon.php', '/tests/support/credenciais-admin.js', '/scripts/init.php', '/app/config/config.php',
    '/app/config/local.php', '/storage/logs/app.log', '/assets/colaboradores.xlsx', '/assets/x.csv', '/assets/backup.bak', '/uploads/logos/x.sql',
    '/qualquer/arquivo.tmp', '/APP/config/config.php', '/Vendor/autoload.php', '/install.php', '/INSTALL.PHP',
];
foreach ($devemNegar as $c) {
    $check($negado($c), "(negar) {$c}");
}

$devemServir = [
    '/assets/favicon.svg', '/assets/tailwind.css', '/assets/admin.js', '/assets/qrcode.js', '/assets/logo.png', '/assets/Isotipolinear.png',
    '/assets/imgfundotelainicial.webp', '/public/assets/tailwind.css', '/public/assets/favicon.svg', '/uploads/logos/443b4f9f.png',
    '/admin/login', '/login', '/admin', '/admin/manual', '/vagas', '/vaga/12', '/api/public/vagas', '/carta-proposta/abc/pdf',
    '/entrevista-desligamento/abc', '/admin/dashboard/colaboradores/exportar', '/admin/candidaturas/1/curriculo', '/.well-known/acme-challenge/x',
    '/index.php', '/admin/docs', '/admin/vendor-x',
];
foreach ($devemServir as $c) {
    $check(!$negado($c), "(servir) {$c}");
}
// public/.htaccess tem RewriteEngine próprio (não herda a raiz): o instalador também precisa estar bloqueado lá, antes do front controller
$htPub = (string)file_get_contents($raiz . '/public/.htaccess');
$posInst = strpos($htPub, 'RewriteRule ^install\.php$ - [F');
$posFrontPub = strpos($htPub, 'RewriteRule ^ index.php');
$check($posInst !== false && $posFrontPub !== false && $posInst < $posFrontPub, '(instalador) public/.htaccess bloqueia install.php antes do front controller');
$check(!$negado('/logs.php'), '(logs.php) visualizador de logs NÃO bloqueado pelo .htaccess (protegido por chave, falha fechada) — decisão registrada');
// os assets estáticos usados pelo Portal existem de fato onde as regras os deixam passar
foreach (['assets/favicon.svg', 'assets/tailwind.css', 'assets/admin.js', 'public/assets/favicon.svg'] as $arq) {
    $check(is_file($raiz . '/' . $arq), "(arquivo) {$arq} existe");
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nHTACCESS_PROTECAO_OK\n";
