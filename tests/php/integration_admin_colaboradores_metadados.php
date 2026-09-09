<?php

/**
 * Integração — tela /admin/colaboradores passa a ser alimentada pelo espelho oficial
 * `colaboradores_metadados` via ColaboradorMetadadosConsultaRepository, e não mais pela
 * tabela hub legada `colaboradores`.
 *
 * Cenário montado dentro de uma transação com ROLLBACK garantido (fixtures próprias):
 *   R1  FABIANE  — ativo=1,   pessoa P1, cargo A   — SEM extensão local (caso "Fabiane")
 *   R2  BRUNO    — ativo=1,   pessoa P2, cargo B   — COM extensão local (colaboradores.metadados_id)
 *   R3  CARLA    — ativo=0,   pessoa P3, cargo A
 *   R4  DIEGO    — ativo=NULL, pessoa P3, cargo B  — readmissão (mesma pessoa de R3)
 *   R5  ELISA    — ativo=1,   pessoa P4, cargo A
 *
 * Esperado: 5 contratos, 3 ativos, 2 desligados, 4 pessoas distintas, 2 cargos distintos,
 * 1 empresa distinta (código novo).
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

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    $tok = 'ZZMTD' . substr(md5(uniqid('', true)), 0, 8);
    $codEmpresa = 'E' . substr($tok, 0, 12);          // <= 20 chars, novo no espelho
    $repo = new ColaboradorMetadadosConsultaRepository($pdo);

    $antes = $repo->summary();
    $colaboradoresAntes = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores')->fetchColumn();

    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($cargoId <= 0) {
        throw new RuntimeException('Nenhum cargo disponível para montar a extensão local do cenário.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO colaboradores_metadados
            (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
             cpf, nome, empresa, nascimento, admissao, cargo, demissao,
             motivo_rescisao_descricao, unidade, setor, ativo, origem_metadados)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $empresaNome = 'EMP ' . $tok;
    $setorNome = 'SET ' . $tok;
    $cargoA = 'CARGO ' . $tok . ' A';
    $cargoB = 'CARGO ' . $tok . ' B';

    // [nome, pessoa, cargo, ativo, demissao, motivo]
    $linhas = [
        ['FABIANE ' . $tok . ' SILVA', $tok . 'P1', $cargoA, 1,    null,         null],
        ['BRUNO ' . $tok,              $tok . 'P2', $cargoB, 1,    null,         null],
        ['CARLA ' . $tok,              $tok . 'P3', $cargoA, 0,    '2026-08-20', 'Pedido de demissão'],
        ['DIEGO ' . $tok,              $tok . 'P3', $cargoB, null, '2025-01-10', 'Término de contrato'],
        ['ELISA ' . $tok,              $tok . 'P4', $cargoA, 1,    null,         null],
    ];

    $ids = [];
    foreach ($linhas as $i => $l) {
        $contrato = 'C' . substr($tok, 0, 8) . $i;
        $insert->execute([
            'ID' . $tok . $i,
            $codEmpresa,
            'U1',
            $contrato,
            $l[1],
            '1234567890' . $i,          // 11 dígitos
            $l[0],
            $empresaNome,
            '1990-05-1' . $i,
            '2020-03-0' . ($i + 1),
            $l[2],
            $l[4],
            $l[5],
            'UNIDADE ' . $tok,
            $setorNome,
            $l[3],
            'RHMADEPLANT',
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    [$idFabiane, $idBruno] = [$ids[0], $ids[1]];

    // Extensão local apenas para BRUNO (R2).
    $pdo->prepare(
        'INSERT INTO colaboradores (nome, slug, cargo_id, ativo, metadados_id) VALUES (?,?,?,1,?)'
    )->execute(['BRUNO ' . $tok, 'bruno-' . strtolower($tok), $cargoId, $idBruno]);
    $localColabId = (int)$pdo->lastInsertId();

    // ---- summary() ----------------------------------------------------------
    $depois = $repo->summary();
    $check($depois['contratos'] - $antes['contratos'] === 5, 'summary(): contratos +5 (COUNT(*) do espelho)');
    $check($depois['ativos'] - $antes['ativos'] === 3, 'summary(): ativos +3 (colaboradores_metadados.ativo = 1)');
    $check($depois['desligados'] - $antes['desligados'] === 2, 'summary(): desligados +2 (ativo = 0 ou NULL)');
    $check($depois['pessoas_distintas'] - $antes['pessoas_distintas'] === 4, 'summary(): pessoas distintas +4 (codigo_pessoa)');
    $check($depois['empresas'] - $antes['empresas'] === 1, 'summary(): empresas +1 (codigo_empresa distinto)');
    $check($depois['cargos_distintos'] - $antes['cargos_distintos'] === 2, 'summary(): cargos distintos +2');

    // ---- busca textual -----------------------------------------------------
    $porNome = $repo->paginate(['q' => 'FABIANE ' . $tok], 1, 100);
    $check($porNome['total'] === 1 && ($porNome['items'][0]['id'] ?? null) === $idFabiane, 'busca por nome encontra o registro oficial (Fabiane)');
    $check(array_key_exists('local_id', $porNome['items'][0] ?? []) && $porNome['items'][0]['local_id'] === null, 'registro oficial sem extensão local: local_id = NULL');

    $porCargo = $repo->paginate(['q' => $cargoA], 1, 100);
    $check($porCargo['total'] === 3, 'busca por cargo funciona (3 contratos no cargo A)');

    $porEmpresa = $repo->paginate(['q' => $empresaNome], 1, 100);
    $check($porEmpresa['total'] === 5, 'busca por empresa funciona (5 contratos)');

    $porSetor = $repo->paginate(['q' => $setorNome], 1, 100);
    $check($porSetor['total'] === 5, 'busca por setor funciona (5 contratos)');

    // ---- paginação (bloco de 21 registros com token/empresa próprios,
    //      que NÃO devem casar com as buscas por $tok acima) ---------------
    $tokP = 'ZZPG' . substr(md5(uniqid('', true)), 0, 8);
    $codEmpresaP = 'E' . substr($tokP, 0, 12);
    for ($i = 0; $i < 21; $i++) {
        $insert->execute([
            'IP' . $tokP . $i, $codEmpresaP, 'U1', 'CP' . substr($tokP, 0, 8) . $i, $tokP . 'P' . $i,
            null, 'PAG ' . $tokP . ' ' . $i, 'EMP ' . $tokP, null, null, 'CARGO ' . $tokP, null,
            null, 'UNIDADE ' . $tokP, 'SET ' . $tokP, 1, 'RHMADEPLANT',
        ]);
    }
    $pg1 = $repo->paginate(['q' => $tokP], 1, 20);
    $pg2 = $repo->paginate(['q' => $tokP], 2, 20);
    $check($pg1['total'] === 21 && $pg1['pages'] === 2 && count($pg1['items']) === 20, 'paginação: página 1 de 2 com 20 itens (per_page=20)');
    $check($pg2['page'] === 2 && count($pg2['items']) === 1, 'paginação: página 2 com o registro restante');
    $check($repo->paginate(['q' => $tokP], 99, 20)['page'] === 2, 'paginação: página fora do intervalo é limitada à última');
    $check($repo->paginate(['q' => $tokP], 1, 7)['per_page'] === 20, 'paginação: per_page inválido cai no padrão 20');

    // ---- extensão local --------------------------------------------------
    $todos = $repo->paginate(['q' => $tok], 1, 100);
    $porId = [];
    foreach ($todos['items'] as $it) {
        $porId[(int)$it['id']] = $it;
    }
    $check(($porId[$idBruno]['local_id'] ?? null) === $localColabId, 'registro com extensão local retorna o colaboradores.id correto');
    $check(array_key_exists($idFabiane, $porId) && $porId[$idFabiane]['local_id'] === null, 'registro sem extensão local aparece normalmente na listagem');

    // ---- LEFT JOIN não duplica contratos --------------------------------
    $check($todos['total'] === 5 && count($todos['items']) === 5, 'LEFT JOIN não altera a cardinalidade (5 contratos, 5 linhas)');
    $idsDistintosNaPagina = count(array_unique(array_map(static fn($it) => (int)$it['id'], $todos['items'])));
    $check($idsDistintosNaPagina === 5, 'LEFT JOIN não repete o mesmo contrato oficial');

    // ---- ausência de extensão local NÃO cria registro ------------------
    $criadosParaFabiane = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores WHERE metadados_id = ' . $idFabiane)->fetchColumn();
    $colaboradoresDepois = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores')->fetchColumn();
    $check($criadosParaFabiane === 0, 'consultar a tela não materializa colaboradores para o registro sem extensão local');
    $check($colaboradoresDepois - $colaboradoresAntes === 1, 'nenhuma linha local nova além da extensão criada de propósito pelo teste');

    // ---- filtro de situação -------------------------------------------
    $ativos = $repo->paginate(['q' => $tok, 'situacao' => 'ativos'], 1, 100);
    $deslig = $repo->paginate(['q' => $tok, 'situacao' => 'desligados'], 1, 100);
    $check($ativos['total'] === 3, 'filtro situação=ativos retorna 3');
    $check($deslig['total'] === 2, 'filtro situação=desligados retorna 2 (inclui ativo NULL)');

    // ---- filtros por empresa/setor/cargo (mesma chave dos selects) ----
    $check($repo->paginate(['empresa' => $codEmpresa], 1, 100)['total'] === 5, 'filtro empresa (codigo_empresa) retorna os 5 contratos');
    $check($repo->paginate(['setor' => $setorNome], 1, 100)['total'] === 5, 'filtro setor (texto) retorna os 5 contratos');
    $check($repo->paginate(['cargo' => $cargoB], 1, 100)['total'] === 2, 'filtro cargo (texto) retorna os 2 contratos do cargo B');

    // ---- opções de filtro ---------------------------------------------
    $opcoes = $repo->opcoesFiltro();
    $codigosEmpresa = array_map(static fn($e) => (string)$e['codigo_empresa'], $opcoes['empresas']);
    $check(in_array($codEmpresa, $codigosEmpresa, true), 'opcoesFiltro(): empresa do cenário aparece na lista');
    $check(in_array($setorNome, array_map('strval', $opcoes['setores']), true), 'opcoesFiltro(): setor do cenário aparece na lista');
    $check(in_array($cargoA, array_map('strval', $opcoes['cargos']), true), 'opcoesFiltro(): cargo do cenário aparece na lista');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }

    echo "\nADMIN_COLABORADORES_METADADOS_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
