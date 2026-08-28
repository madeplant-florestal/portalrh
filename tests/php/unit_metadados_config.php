<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

// Regressão do bug real da Fase 2: Config::app() é uma projeção curada (name, version, database,
// security, mail, logging...) e NUNCA expôs o bloco 'metadados' — MetadadosDatabase lia
// Config::app()['metadados'] e sempre recebia null, então o DSN configurado em local.php nunca
// era visto. A correção foi trocar para Config::get(), que retorna o array bruto mesclado
// (config.php -> local.php -> build.php).
$assert(
    !array_key_exists('metadados', Config::app()),
    'Falha: Config::app() não deveria expor "metadados" — se passou a expor, o comentário/guarda abaixo está desatualizado, revise.'
);

$rawConfig = Config::get();
$assert(array_key_exists('metadados', $rawConfig), 'Falha: Config::get() deveria expor o bloco "metadados".');
$assert(is_array($rawConfig['metadados']), 'Falha: "metadados" deveria ser um array.');
foreach (['dsn', 'user', 'pass'] as $key) {
    $assert(array_key_exists($key, $rawConfig['metadados']), "Falha: metadados.{$key} deveria existir (mesmo que vazio).");
}

// Guarda de regressão direta: garante que MetadadosDatabase não volte a usar Config::app().
// Não é um teste de comportamento "ideal" (o projeto não tem mocking), mas pega exatamente a
// classe de bug que já aconteceu uma vez.
$source = file_get_contents(__DIR__ . '/../../app/core/MetadadosDatabase.php');
$assert($source !== false, 'Falha: não foi possível ler MetadadosDatabase.php.');
$assert(
    !str_contains($source, "Config::app()['metadados']") && !str_contains($source, 'Config::app()["metadados"]'),
    'Falha: MetadadosDatabase voltou a ler metadados via Config::app() — esse bloco não existe ali, a conexão nunca vai encontrar o DSN configurado.'
);
$assert(
    str_contains($source, "Config::get()['metadados']") || str_contains($source, 'Config::get()["metadados"]'),
    'Falha: MetadadosDatabase deveria ler a configuração via Config::get()[\'metadados\'].'
);

// Se local.php existir e declarar metadados.dsn, o valor mesclado deve refletir exatamente o
// que está lá — nunca imprimimos o valor em si, só comparamos por igualdade.
$localPath = __DIR__ . '/../../app/config/local.php';
if (is_file($localPath)) {
    $local = require $localPath;
    if (is_array($local) && isset($local['metadados']['dsn']) && $local['metadados']['dsn'] !== '') {
        $assert(
            $rawConfig['metadados']['dsn'] === $local['metadados']['dsn'],
            'Falha: local.php declara metadados.dsn mas Config::get() não refletiu esse valor (merge quebrado).'
        );
    }
}

// Carregar o bootstrap (já feito no topo deste arquivo) não deve ter estabelecido nenhuma
// conexão com o METADADOS. Usa reflexão para inspecionar o estado estático sem chamar conn().
$reflection = new ReflectionClass('MetadadosDatabase');
$pdoProperty = $reflection->getProperty('pdo');
$pdoProperty->setAccessible(true);
$assert(
    $pdoProperty->getValue() === null,
    'Falha: carregar o bootstrap não deveria ter estabelecido conexão com o METADADOS (MetadadosDatabase::$pdo deveria continuar null).'
);

echo "OK unit_metadados_config\n";
