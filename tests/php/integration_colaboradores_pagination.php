<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $cargoStmt = $pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1');
    $cargoId = (int)$cargoStmt->fetchColumn();
    if ($cargoId <= 0) {
        throw new RuntimeException('Nenhum cargo disponivel para montar o cenario de paginacao.');
    }

    $originalTotal = Colaborador::countAll();
    $requiredTotal = 25;
    $toInsert = max(0, $requiredTotal - $originalTotal);
    $createdPrefix = 'Paginacao Teste ';
    $createdLike = $createdPrefix . '%';

    $insertStmt = $pdo->prepare(
        'INSERT INTO colaboradores (nome, slug, cargo_id, ativo)
         VALUES (?, ?, ?, 1)'
    );

    for ($index = 1; $index <= $toInsert; $index++) {
        $name = $createdPrefix . $index;
        $slug = 'paginacao-teste-' . uniqid('', true) . '-' . $index;
        $insertStmt->execute([$name, $slug, $cargoId]);
    }

    $defaultResult = Colaborador::paginateAdmin([], 1, 999);
    if ((int)$defaultResult['per_page'] !== 20) {
        throw new RuntimeException('O valor padrao de registros por pagina deveria ser 20.');
    }
    if ((int)$defaultResult['page'] !== 1) {
        throw new RuntimeException('A primeira pagina deveria ser retornada por padrao.');
    }
    if (count($defaultResult['items']) > 20) {
        throw new RuntimeException('A pagina padrao nao pode retornar mais de 20 registros.');
    }

    $pageTwoResult = Colaborador::paginateAdmin([], 2, 20);
    if ((int)$pageTwoResult['page'] !== 2) {
        throw new RuntimeException('A segunda pagina deveria estar disponivel com mais de 20 registros.');
    }
    if ((int)$pageTwoResult['pages'] < 2) {
        throw new RuntimeException('Era esperado pelo menos duas paginas com o cenario montado.');
    }
    if (count($pageTwoResult['items']) < 1 || count($pageTwoResult['items']) > 20) {
        throw new RuntimeException('A segunda pagina retornou uma quantidade invalida de registros.');
    }

    $perPage50 = Colaborador::paginateAdmin([], 1, 50);
    if ((int)$perPage50['per_page'] !== 50) {
        throw new RuntimeException('A opcao de 50 registros por pagina nao foi respeitada.');
    }
    if (count($perPage50['items']) > 50) {
        throw new RuntimeException('A pagina com 50 registros excedeu o limite configurado.');
    }

    $filterResult = Colaborador::paginateAdmin(['q' => $createdPrefix], 1, 20);
    if ((int)$filterResult['total'] < $toInsert) {
        throw new RuntimeException('A paginacao com filtro nao retornou os registros temporarios esperados.');
    }

    echo "COLABORADORES_PAGINATION_OK\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
