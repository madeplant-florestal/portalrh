<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_empresa_metadados_plan (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
$temColunas = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'codigo_empresa'"
)->fetchColumn();
if ($temColunas === 0) {
    echo "SKIP integration_empresa_metadados_plan (migration 2026-09-04-empresas-unidades-metadados.sql nao aplicada)\n";
    exit(0);
}

$sufixo = substr((string)time(), -6) . (string)random_int(10, 99);
$codMatch = 'ZTP1' . $sufixo;
$codNova  = 'ZTP2' . $sufixo;
$codAmbig = 'ZTP3' . $sufixo;
$nomeMatch = 'ZZ PLANO ADOCAO ' . $sufixo . ' LTDA';
$nomeAmbig = 'ZZ PLANO AMBIGUO ' . $sufixo . ' LTDA';

$repo = new EmpresaMetadadosRepository($pdo);
$service = new EmpresaMetadadosSyncService($repo);

$limpar = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM unidades WHERE codigo_empresa LIKE 'ZTP%'");
    $pdo->exec("DELETE FROM empresas WHERE codigo_empresa LIKE 'ZTP%' OR nome LIKE 'ZZ PLANO %'");
};
$limpar();

$itemPorCodigo = static function (array $plano, string $codigo): ?array {
    foreach ($plano['itens'] as $item) {
        if ($item['codigo_empresa'] === $codigo) {
            return $item;
        }
    }
    return null;
};

try {
    // Fixtures: uma empresa local que casa exato, duas que normalizam para o mesmo nome (ambíguo),
    // uma local "solta" que não casa com nada.
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute([$nomeMatch, 'zz-plano-adocao-' . $sufixo]);
    $idMatch = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute([$nomeAmbig, 'zz-plano-ambiguo-a-' . $sufixo]);
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute([strtolower($nomeAmbig) . '!!!', 'zz-plano-ambiguo-b-' . $sufixo]);
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 0)')->execute(['ZZ PLANO SOLTA ' . $sufixo . ' LTDA', 'zz-plano-solta-' . $sufixo]);
    $idSolta = (int)$pdo->query("SELECT id FROM empresas WHERE slug = 'zz-plano-solta-{$sufixo}'")->fetchColumn();

    $fonte = [
        ['codigo_empresa' => $codMatch, 'razao_social' => $nomeMatch],
        ['codigo_empresa' => $codNova,  'razao_social' => 'ZZ PLANO SEM MATCH ' . $sufixo . ' LTDA'],
        ['codigo_empresa' => $codAmbig, 'razao_social' => $nomeAmbig],
        ['codigo_empresa' => '',        'razao_social' => 'ZZ PLANO INVALIDA'],
    ];

    // --- 1) planejar() NÃO altera o banco ---
    $countAntes = (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn();
    $hashAntes = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE(codigo_empresa,'∅'),':',nome ORDER BY id)) FROM empresas")->fetchColumn();
    $plano = $service->planejar($fonte, 'RHMADEPLANT');
    $countDepois = (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn();
    $hashDepois = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE(codigo_empresa,'∅'),':',nome ORDER BY id)) FROM empresas")->fetchColumn();
    $assert($countAntes === $countDepois && $hashAntes === $hashDepois, 'Caso 1: planejar() não pode alterar a tabela empresas.');

    // --- 2) match exato -> ADOTAR_EXISTENTE, com o id local certo ---
    $iMatch = $itemPorCodigo($plano, $codMatch);
    $assert($iMatch['acao'] === 'ADOTAR_EXISTENTE', 'Caso 2: match exato deveria ser ADOTAR_EXISTENTE.');
    $assert($iMatch['empresa_local_id'] === $idMatch && $iMatch['nome_local'] === $nomeMatch, 'Caso 2: deveria apontar a empresa local correta.');

    // --- 3) sem correspondência -> INSERIR_NOVA ---
    $iNova = $itemPorCodigo($plano, $codNova);
    $assert($iNova['acao'] === 'INSERIR_NOVA' && $iNova['empresa_local_id'] === null, 'Caso 3: sem match deveria ser INSERIR_NOVA.');

    // --- 4) ambiguidade -> nunca adota (INSERIR_NOVA), candidatos_ambiguos = 2 ---
    $iAmb = $itemPorCodigo($plano, $codAmbig);
    $assert($iAmb['acao'] === 'INSERIR_NOVA' && $iAmb['candidatos_ambiguos'] === 2, 'Caso 4: ambiguidade nunca adota.');

    // --- 5) linha inválida -> ERRO ---
    $iErro = $itemPorCodigo($plano, '');
    $assert($iErro !== null && $iErro['acao'] === 'ERRO', 'Caso 5: razao/codigo vazio deveria ser ERRO no plano.');

    // --- 6) locais sem correspondência: a "solta" e as 2 ambíguas aparecem; a de match NÃO ---
    $idsSemCorresp = array_column($plano['locais_sem_correspondencia'], 'id');
    $assert(in_array($idSolta, $idsSemCorresp, true), 'Caso 6: a empresa local "solta" deveria aparecer como LOCAL_SEM_CORRESPONDENCIA_OFICIAL.');
    $assert(!in_array($idMatch, $idsSemCorresp, true), 'Caso 6: a empresa local adotada NÃO deveria aparecer como sem correspondência.');
    foreach ($plano['locais_sem_correspondencia'] as $l) {
        $assert($l['classificacao'] === 'LOCAL_SEM_CORRESPONDENCIA_OFICIAL', 'Caso 6: classificação fixa.');
        $assert(array_key_exists('id', $l) && array_key_exists('nome', $l) && array_key_exists('slug', $l) && array_key_exists('ativo', $l), 'Caso 6: campos id/nome/slug/ativo presentes.');
    }
    $solta = null;
    foreach ($plano['locais_sem_correspondencia'] as $l) {
        if ($l['id'] === $idSolta) { $solta = $l; }
    }
    $assert($solta['ativo'] === 0, 'Caso 6: ativo da "solta" deveria refletir o valor real (0).');

    // --- 7) o plano é a MESMA regra que applyRows() executa ---
    $planoAntes = $service->planejar($fonte, 'RHMADEPLANT');
    $sum = $service->applyRows($fonte, 'RHMADEPLANT');
    $contagemPlano = [];
    foreach ($planoAntes['itens'] as $item) {
        $contagemPlano[$item['acao']] = ($contagemPlano[$item['acao']] ?? 0) + 1;
    }
    $assert(($contagemPlano['ADOTAR_EXISTENTE'] ?? 0) === $sum['adopted'], 'Caso 7: adotados do plano == adotados aplicados.');
    $assert(($contagemPlano['INSERIR_NOVA'] ?? 0) === $sum['inserted'], 'Caso 7: inseridos do plano == inseridos aplicados.');
    $assert(($contagemPlano['ERRO'] ?? 0) === $sum['errors'], 'Caso 7: erros do plano == erros aplicados.');
    $assert($repo->findByCodigo($codMatch)['id'] == $idMatch, 'Caso 7: applyRows realmente adotou a mesma linha que o plano previu.');

    // --- 8) reexecutar planejar() após aplicar: agora o match e a nova estão vinculadas -> INALTERADA ---
    $planoDepois = $service->planejar($fonte, 'RHMADEPLANT');
    $iMatch2 = $itemPorCodigo($planoDepois, $codMatch);
    $iNova2 = $itemPorCodigo($planoDepois, $codNova);
    $assert($iMatch2['acao'] === 'INALTERADA' && $iNova2['acao'] === 'INALTERADA', 'Caso 8: após aplicar, itens já vinculados por codigo_empresa ficam INALTERADA.');

    // --- 9) razão social muda na fonte -> ATUALIZAR_EXISTENTE ---
    $fonteAtualizada = $fonte;
    $fonteAtualizada[0]['razao_social'] = 'ZZ PLANO ADOCAO ' . $sufixo . ' SA';
    $iAtualiza = $itemPorCodigo($service->planejar($fonteAtualizada, 'RHMADEPLANT'), $codMatch);
    $assert($iAtualiza['acao'] === 'ATUALIZAR_EXISTENTE', 'Caso 9: mudança de razão social -> ATUALIZAR_EXISTENTE.');

    echo "OK integration_empresa_metadados_plan\n";
} finally {
    $limpar();
}
