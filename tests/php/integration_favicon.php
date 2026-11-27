<?php

/**
 * Integração — favicon oficial (assets/favicon.svg).
 *   - SVG válido, só elementos de desenho (sem script, eventos, links, url(), foreignObject, entidades);
 *   - FONTE ÚNICA: assets/favicon.svg é a origem; public/assets/favicon.svg é a cópia publicada (mesma convenção dos demais assets) e
 *     precisa ser idêntica byte a byte — este teste falha se as duas divergirem;
 *   - os três layouts declaram <link rel="icon" type="image/svg+xml"> com base_url e versão de cache (Config::assetVersion);
 *   - aparece no login, Central, Manual, People Analytics, Recrutamento, Cadastros, página pública e página pública com token;
 *   - servidor HTTP real: /assets/favicon.svg responde 200 com image/svg+xml.
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
$fonte = $raiz . '/assets/favicon.svg';
$publicado = $raiz . '/public/assets/favicon.svg';

// ---- 1. o arquivo ---------------------------------------------------------------------------------------------------------------------
$svg = (string)@file_get_contents($fonte);
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$ok = $svg !== '' && $dom->loadXML($svg, LIBXML_NONET) && libxml_get_errors() === [];
libxml_clear_errors();
$check($ok && $dom->documentElement->localName === 'svg' && $dom->documentElement->getAttribute('viewBox') !== '', '(svg) XML válido com raiz <svg> e viewBox');
$permitidos = ['svg', 'defs', 'style', 'g', 'path', 'rect', 'polygon', 'circle', 'ellipse', 'line', 'polyline', 'title'];
$estranhos = [];
foreach ($dom->getElementsByTagName('*') as $el) {
    if (!in_array($el->localName, $permitidos, true)) {
        $estranhos[] = $el->localName;
    }
    foreach ($el->attributes as $at) {
        if (preg_match('/^on/i', $at->name) || in_array(strtolower($at->name), ['href', 'xlink:href', 'src'], true)) {
            $estranhos[] = $el->localName . '@' . $at->name;
        }
    }
}
$check($ok && $estranhos === [], '(svg) só elementos de desenho, sem atributos de evento ou links' . ($estranhos ? ' — achados: ' . implode(',', $estranhos) : ''));
$check(!preg_match('/<script|javascript:|url\s*\(|@import|<!ENTITY|<foreignObject|https?:\/\/(?!www\.w3\.org\/2000\/svg)/i', $svg), '(svg) sem conteúdo ativo nem referência externa (só o namespace SVG)');
$check(is_file($publicado) && hash_file('sha256', $fonte) === hash_file('sha256', $publicado), '(fonte única) public/assets/favicon.svg é cópia idêntica de assets/favicon.svg');
$check(preg_match('/^\d+$/', Config::assetVersion('assets/favicon.svg')) === 1, '(cache) Config::assetVersion resolve a versão do arquivo publicado (sem número fixo)');

// ---- 2. páginas ---------------------------------------------------------------------------------------------------------------------
$requisitar = static function (int $uid, string $role, string $ctrl, string $metodo, array $get = [], array $args = []): string {
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, '0', $ctrl, $metodo, json_encode($get), json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return $o;
};
// A base é a MESMA que a página usa para os demais assets (tailwind.css): o favicon respeita base_url como todo o resto.
$padrao = '#<link rel="icon" type="image/svg\+xml" href="([^"]*)/assets/favicon\.svg\?v=\d+">#';
$baseDoCss = static fn(string $html): ?string => preg_match('#href="([^"]*)/assets/tailwind\.css\?v=#', $html, $m) ? $m[1] : null;
$admin = User::create('ZZFAV Admin', 'zzfav.admin.' . bin2hex(random_bytes(3)) . '@teste.local', password_hash('irrelevante', PASSWORD_BCRYPT), 'admin');
User::setActiveStatus($admin, true);
try {
    $paginas = [
        'Login (layout main)' => [0, '', 'AuthController', 'login', [], []],
        'Central (app-shell)' => [$admin, 'admin', 'AdminCentralController', 'index', [], []],
        'Manual' => [$admin, 'admin', 'AdminManualController', 'index', [], []],
        'People Analytics (dashboard)' => [$admin, 'admin', 'AdminController', 'index', [], []],
        'Recrutamento (Vagas)' => [$admin, 'admin', 'AdminVagasController', 'index', [], []],
        'Cadastros (Setores)' => [$admin, 'admin', 'AdminSetoresController', 'index', [], []],
        'Página pública (vagas)' => [0, '', 'HomeController', 'index', [], []],
        'Página pública com token (publico-seguro)' => [0, '', 'CartaPropostaController', 'show', [], [str_repeat('a', 64)]],
    ];
    foreach ($paginas as $nome => [$uid, $role, $ctrl, $metodo, $get, $args]) {
        $html = $requisitar($uid, $role, $ctrl, $metodo, $get, $args);
        $n = preg_match_all($padrao, $html, $mm);
        $mesmaBase = $n === 1 && $baseDoCss($html) !== null && $mm[1][0] === $baseDoCss($html);
        $check($n === 1 && $mesmaBase && str_contains($html, '</head>') && strpos($html, 'favicon.svg') < strpos($html, '</head>'), "(página) {$nome}: um único <link rel=\"icon\"> SVG no <head>, com a mesma base dos demais assets e versão");
    }
} finally {
    Database::conn()->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$admin, $admin]);
    Database::conn()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$admin]);
}
foreach (['app-shell', 'main', 'publico-seguro'] as $layout) {
    $src = (string)file_get_contents($raiz . "/app/views/layouts/{$layout}.php");
    $check(substr_count($src, 'rel="icon"') === 1 && str_contains($src, "Config::assetVersion('assets/favicon.svg')"), "(layout) {$layout}.php declara o favicon com Config::assetVersion");
}

// ---- 3. servidor HTTP real -----------------------------------------------------------------------------------------------------------
$porta = 8090 + random_int(0, 50);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $raiz . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesSrv);
try {
    $resp = false;
    for ($i = 0; $i < 40 && $resp === false; $i++) {
        usleep(150000);
        $resp = @file_get_contents("http://127.0.0.1:{$porta}/assets/favicon.svg?v=" . Config::assetVersion('assets/favicon.svg'), false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]));
    }
    $cab = implode("\n", $http_response_header ?? []);
    $check($resp !== false && preg_match('#^HTTP/\S+ 200#', $cab) === 1, '(http) /assets/favicon.svg responde 200');
    $check(preg_match('#Content-Type:\s*image/svg\+xml#i', $cab) === 1, '(http) MIME image/svg+xml');
    $check($resp === $svg, '(http) conteúdo servido = arquivo oficial');
} finally {
    proc_terminate($srv);
    proc_close($srv);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nFAVICON_OK\n";
