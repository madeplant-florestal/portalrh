<?php
declare(strict_types=1);

/**
 * Gera app/config/build.php (NÃO versionado) com a versão do commit em uso e a data desse commit — para deploys por CÓPIA de arquivos,
 * em que o destino não tem `.git` (deploy.ps1, cPanel). Quando o destino publicado tem `.git` (deploy Git da hospedagem), o Portal lê a
 * versão direto do repositório (VersaoPublicadaService) e este arquivo é só reserva.
 *
 * Usa o MESMO leitor do Portal (VersaoPublicadaService: leitura de arquivos do `.git`, sem shell, sem binário `git`, sem rede) —
 * funciona igual em Windows e Linux. Conteúdo: SOMENTE metadados públicos (versão e data do commit).
 */

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/app/services/VersaoPublicadaService.php';

$configDir = $repoRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';
$buildFile = $configDir . DIRECTORY_SEPARATOR . 'build.php';

$info = VersaoPublicadaService::doGit($repoRoot);
if ($info === null || $info['data_commit'] === null) {
    fwrite(STDERR, "ERRO: não foi possível ler o commit atual do repositório (.git). build.php NÃO foi alterado.\n");
    exit(1);
}

if (!is_dir($configDir)) {
    @mkdir($configDir, 0775, true);
}

$iso = $info['data_commit']->format(DATE_ATOM);
$conteudo = "<?php\n// Gerado por scripts/generate_build_info.php — não versionar, não editar.\nreturn [\n    'app' => [\n"
    . "        'version' => " . var_export($info['versao'], true) . ",\n"
    . "        'release_date' => " . var_export($iso, true) . ",\n"
    . "        'release_date_iso' => " . var_export($iso, true) . ",\n"
    . "    ],\n];\n";

if (@file_put_contents($buildFile, $conteudo) === false) {
    fwrite(STDERR, "ERRO: falha ao escrever app/config/build.php.\n");
    exit(1);
}

echo "OK: build info gerado em app/config/build.php\n";
echo "Versão: {$info['versao']}\n";
echo 'Data do commit (Campo Grande): ' . VersaoPublicadaService::dataFormatada($info['data_commit']) . "\n";
exit(0);
