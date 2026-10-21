<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Materializa em `cargo_setores_metadados` os pares Cargo x Setor extraídos do histórico de
 * RHCONTRATOS por scripts/extrair_cargo_setor_rhcontratos.php (rodado separadamente, na rede
 * interna Madeplant). Correção 2026-10 (caso recorrente: MANUTENÇÃO/código 12).
 *
 * Roda onde o MySQL do Portal (dev OU produção) é alcançável — NUNCA precisa do SQL Server/
 * pdo_sqlsrv, nunca acessa METADADOS diretamente. Só lê o arquivo JSON e aplica via
 * CargoSetorMetadadosRepository::materializarPorCodigos(): idempotente (`INSERT IGNORE`),
 * nunca apaga relação existente de qualquer origem (`origem_metadados` preenchida como
 * 'RHCONTRATOS' — nunca sobrescreve uma linha já marcada 'RHQUADROLOTCARGO', se um dia existir).
 * Pode ser executado quantas vezes for preciso com extrações novas.
 *
 * Uso:  php scripts/materializar_cargo_setor_metadados.php <caminho/para/pares.json>
 */

$caminho = $argv[1] ?? null;
if ($caminho === null || !is_file($caminho)) {
    fwrite(STDERR, 'Uso: php scripts/materializar_cargo_setor_metadados.php <arquivo.json>' . PHP_EOL);
    fwrite(STDERR, 'O arquivo é gerado por scripts/extrair_cargo_setor_rhcontratos.php na rede interna.' . PHP_EOL);
    exit(1);
}

$conteudo = file_get_contents($caminho);
$pares = $conteudo !== false ? json_decode($conteudo, true) : null;
if (!is_array($pares)) {
    fwrite(STDERR, 'Arquivo inválido: esperado um array JSON de objetos {codigo_cargo, codigo_setor}.' . PHP_EOL);
    exit(1);
}

$resultado = (new CargoSetorMetadadosRepository())->materializarPorCodigos($pares, 'RHCONTRATOS');

fwrite(STDERR, 'Pares recebidos: ' . count($pares) . PHP_EOL);
fwrite(STDERR, 'Inseridos (novos vínculos): ' . $resultado['inseridos'] . PHP_EOL);
fwrite(STDERR, 'Já existentes (ignorados, idempotente): ' . $resultado['ja_existentes'] . PHP_EOL);
fwrite(STDERR, 'Sem correspondência no catálogo local (código ainda não sincronizado): ' . $resultado['sem_correspondencia_local'] . PHP_EOL);

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
