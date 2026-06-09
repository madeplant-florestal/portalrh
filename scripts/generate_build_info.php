<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$configDir = $repoRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';
$buildFile = $configDir . DIRECTORY_SEPARATOR . 'build.php';

$runGit = static function (string $args) use ($repoRoot): string {
    $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' ' . $args . ' 2>NUL';
    $out = shell_exec($cmd);
    return is_string($out) ? trim($out) : '';
};

$hash = $runGit('rev-parse --short=12 HEAD');
$isoDate = $runGit('log -1 --format=%cI');
$humanDate = $runGit('log -1 --format=%cd --date=format:%Y-%m-%d %H:%M:%S %z');

if ($hash === '' || $isoDate === '') {
    fwrite(STDERR, "ERRO: não foi possível obter informações do Git.\n");
    exit(1);
}

if (!is_dir($configDir)) {
    @mkdir($configDir, 0775, true);
}

$dateForApp = $humanDate !== '' ? $humanDate : $isoDate;

$content = "<?php\nreturn [\n    'app' => [\n        'version' => 'git-" . addslashes($hash) . "',\n        'release_date' => '" . addslashes($dateForApp) . "',\n        'release_date_iso' => '" . addslashes($isoDate) . "'\n    ]\n];\n";

$ok = @file_put_contents($buildFile, $content);
if ($ok === false) {
    fwrite(STDERR, "ERRO: falha ao escrever app/config/build.php.\n");
    exit(1);
}

echo "OK: build info gerado em app/config/build.php\n";
echo "Versão: git-{$hash}\n";
echo "Data: {$dateForApp}\n";
exit(0);
