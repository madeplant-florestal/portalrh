<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_rh_indicadores_repository (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::conn();
$sufixo = (string)time() . (string)random_int(100, 999);

/** Cria N contratos fictícios em colaboradores_metadados e retorna os ids gerados. */
function criarContratos(PDO $pdo, string $sufixo, array $linhas): array
{
    $ids = [];
    $stmt = $pdo->prepare(
        'INSERT INTO colaboradores_metadados
            (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome,
             empresa, unidade, cargo, setor, centro_custo, admissao, demissao,
             motivo_rescisao_descricao, ativo)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($linhas as $i => $linha) {
        $stmt->execute([
            "IND{$sufixo}-{$i}",
            $linha['codigo_empresa'] ?? "EMP{$sufixo}",
            $linha['codigo_unidade'] ?? "UNI{$sufixo}",
            (string)$i,
            "PES{$sufixo}{$i}",
            "Contrato Teste {$i}",
            $linha['empresa'] ?? 'Empresa Teste',
            $linha['unidade'] ?? 'Unidade Teste',
            $linha['cargo'] ?? null,
            $linha['setor'] ?? null,
            $linha['centro_custo'] ?? null,
            $linha['admissao'],
            $linha['demissao'] ?? null,
            $linha['motivo_rescisao_descricao'] ?? null,
            $linha['ativo'] ?? ((($linha['demissao'] ?? null) === null) ? 1 : 0),
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

function limparContratos(PDO $pdo, array $ids): void
{
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }
}

try {
    $repository = new RhIndicadoresRepository($pdo);

    // ===== Cenário 1: buscarContratos sem filtro traz todos os contratos do sufixo, com os
    // campos certos e sem PII (cpf/nome/nascimento/salário nunca selecionados). =====
    $ids1 = criarContratos($pdo, $sufixo . 'a', [
        ['admissao' => '2023-01-10', 'demissao' => null, 'setor' => 'Produção', 'cargo' => 'Operador', 'centro_custo' => 'CC1'],
        ['admissao' => '2023-02-10', 'demissao' => '2024-01-10', 'setor' => null, 'cargo' => 'Auxiliar', 'centro_custo' => 'CC1', 'motivo_rescisao_descricao' => 'Motivo Teste'],
    ]);
    try {
        $codigoEmpresaFiltro = "EMP{$sufixo}a";
        $contratos = $repository->buscarContratos(['codigo_empresa' => $codigoEmpresaFiltro]);
        $assert(count($contratos) === 2, 'Cenário 1: deveria trazer os 2 contratos sintéticos filtrando por codigo_empresa.');
        foreach ($contratos as $linha) {
            $assert(!array_key_exists('cpf', $linha), 'Cenário 1: buscarContratos nunca deveria selecionar cpf.');
            $assert(!array_key_exists('nome', $linha), 'Cenário 1: buscarContratos nunca deveria selecionar nome.');
            $assert(!array_key_exists('nascimento', $linha), 'Cenário 1: buscarContratos nunca deveria selecionar nascimento.');
            $assert(!array_key_exists('salario_atual', $linha), 'Cenário 1: buscarContratos nunca deveria selecionar salario_atual.');
        }
        $comSetorNulo = array_values(array_filter($contratos, static fn(array $c) => $c['setor'] === null));
        $assert(count($comSetorNulo) === 1, 'Cenário 1: o contrato com setor NULL deveria ser retornado tal como está (NULL preservado, não descartado).');
    } finally {
        limparContratos($pdo, $ids1);
    }

    // ===== Cenário 2: filtros combinados (empresa + cargo) restringem corretamente. =====
    $ids2 = criarContratos($pdo, $sufixo . 'b', [
        ['codigo_empresa' => "EMP{$sufixo}b", 'cargo' => 'Motorista', 'admissao' => '2023-01-01'],
        ['codigo_empresa' => "EMP{$sufixo}b", 'cargo' => 'Operador', 'admissao' => '2023-01-01'],
        ['codigo_empresa' => "OUTRA{$sufixo}b", 'cargo' => 'Motorista', 'admissao' => '2023-01-01'],
    ]);
    try {
        $filtrados = $repository->buscarContratos(['codigo_empresa' => "EMP{$sufixo}b", 'cargo' => 'Motorista']);
        $assert(count($filtrados) === 1, 'Cenário 2: filtro combinado empresa+cargo deveria retornar exatamente 1 contrato.');
    } finally {
        limparContratos($pdo, $ids2);
    }

    // ===== Cenário 3: opcoesFiltro() e periodoDisponivel() não quebram e trazem estrutura esperada. =====
    $opcoes = $repository->opcoesFiltro();
    foreach (['empresas', 'unidades', 'cargos', 'setores', 'centrosCusto'] as $chave) {
        $assert(array_key_exists($chave, $opcoes), "Cenário 3: opcoesFiltro() deveria retornar a chave '{$chave}'.");
        $assert(is_array($opcoes[$chave]), "Cenário 3: '{$chave}' deveria ser um array.");
    }
    $periodo = $repository->periodoDisponivel();
    foreach (['primeira_admissao', 'ultima_admissao', 'ultima_demissao'] as $chave) {
        $assert(array_key_exists($chave, $periodo), "Cenário 3: periodoDisponivel() deveria retornar a chave '{$chave}'.");
    }

    echo "OK integration_rh_indicadores_repository\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
