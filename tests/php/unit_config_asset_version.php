<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

/**
 * Correção "cache-busting congelado" — Config::assetVersion() substitui o antigo `?v=<stamp fixo
 * de config/build.php>` (nunca regenerado a cada deploy) por um valor derivado do `filemtime()` do
 * próprio arquivo publicado em `public/assets/...`. A URL só deve mudar quando o ARQUIVO muda.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    echo "  [ok] {$message}\n";
};

$dir = BASE_PATH . '/public/assets';
$nomeArquivo = 'zz_teste_cache_busting_' . substr(md5(uniqid('', true)), 0, 8) . '.tmp';
$caminhoRelativo = 'assets/' . $nomeArquivo;
$caminhoAbsoluto = $dir . '/' . $nomeArquivo;

try {
    // ---- Arquivo inexistente: fallback seguro, nunca quebra a página --------------------------
    $fallback = Config::assetVersion('assets/nao-existe-' . uniqid() . '.js');
    $assert($fallback !== '', 'arquivo inexistente: assetVersion() retorna um fallback não vazio (nunca string vazia/erro)');
    $assert($fallback === (string)(Config::app()['version'] ?? '1'), 'arquivo inexistente: fallback é exatamente app()[\'version\'] (mesmo stamp legado, só para esse caso extremo)');

    // ---- Arquivo real: versão = filemtime, estável sem mudança física --------------------------
    file_put_contents($caminhoAbsoluto, 'conteudo inicial');
    $v1 = Config::assetVersion($caminhoRelativo);
    $assert(ctype_digit($v1), 'arquivo existente: versão é um timestamp numérico (filemtime), não um stamp textual fixo');
    $assert($v1 === (string)filemtime($caminhoAbsoluto), 'versão retornada bate exatamente com filemtime() do arquivo físico');

    $v2 = Config::assetVersion($caminhoRelativo);
    $assert($v1 === $v2, 'sem alteração no arquivo, a versão permanece ESTÁVEL entre chamadas (não muda por request)');

    // ---- Alterar o arquivo físico -> a versão MUDA (cache-busting real) ------------------------
    sleep(1); // garante um filemtime() diferente (resolução de 1s em alguns filesystems)
    file_put_contents($caminhoAbsoluto, 'conteudo alterado — simula um novo deploy do asset');
    clearstatcache(true, $caminhoAbsoluto);
    $v3 = Config::assetVersion($caminhoRelativo);
    $assert($v3 !== $v1, 'ALTERAR o arquivo físico muda a versão — é isso que invalida o cache do navegador a cada deploy real');

    // ---- Os 6 pontos de uso nas views usam Config::assetVersion(), não mais o stamp fixo -------
    foreach (['app/views/layouts/admin.php', 'app/views/layouts/main.php'] as $view) {
        $conteudo = (string)file_get_contents(BASE_PATH . '/' . $view);
        $assert(
            substr_count($conteudo, "Config::assetVersion(") >= 2,
            "{$view} usa Config::assetVersion() para os assets estáticos (?v=...), não mais o stamp fixo de Config::app()['version']"
        );
    }
    // O rodapé (label "vX.X.X" visível ao usuário) é uma exibição textual, não cache-busting —
    // continua usando Config::app()['version'] de propósito, não é regressão.
    $mainFonte = (string)file_get_contents(BASE_PATH . '/app/views/layouts/main.php');
    $assert(str_contains($mainFonte, "v<?= Config::app()['version']"), 'rodapé com o label de versão legível continua intacto (não é cache-busting, não deveria mudar)');

    echo "\nCONFIG_ASSET_VERSION_OK\n";
} finally {
    if (is_file($caminhoAbsoluto)) {
        unlink($caminhoAbsoluto);
    }
}
