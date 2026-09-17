<?php

/**
 * Integração — Correção dos contadores oficiais das telas Empresas e Setores.
 *
 * Antes desta correção:
 *   - Empresas: "Uso em colaboradores" contava `setores.empresa_id` (nem colaborador nem
 *     METADADOS — CadastroOrganizacional::usageCount()).
 *   - Setores: "Cargos" vinha de `cargo_setores` (legado) e "Colaboradores" vinha de
 *     `colaboradores.setor_id` (tabela local legada) — SetorRepository::paginateAdmin()/
 *     exportDataset().
 *
 * Depois: ambos vêm exclusivamente de `colaboradores_metadados` (espelho oficial do METADADOS,
 * PESSOAS distintas com contrato ativo, via codigo_empresa/codigo_setor) e
 * `cargo_setores_metadados` (Cargo x Setor oficial) — nunca `colaboradores`/`cargo_setores`.
 *
 * Prova, com fixtures isoladas (prefixo ZZ, nunca colidem com dados reais):
 *   1. Empresa não conta mais setores como "colaboradores".
 *   2. Empresa conta pessoas distintas com contrato ativo via codigo_empresa.
 *   3. Contratos históricos/inativos não entram.
 *   4. Dois contratos ativos da mesma pessoa contam uma pessoa só (dedup).
 *   5. Setor conta pessoas distintas ativas via codigo_setor.
 *   6. Colaborador sem codigo_setor não é atribuído a nenhum Setor.
 *   7. colaboradores.setor_id não influencia mais o contador oficial (ruído legado ignorado).
 *   8. cargo_setores não influencia mais o contador oficial (ruído legado ignorado).
 *   9. Quantidade de Cargos do Setor vem de cargo_setores_metadados.
 *  10. Setor oficial sem colaborador ativo retorna zero.
 *  11. Código de Setor sem correspondência local (órfão) não provoca associação indevida.
 *  12. As páginas de Empresas e Setores continuam renderizando sem Warning/Notice/Fatal.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = (string)time() . (string)random_int(1000, 9999);
$criados = [
    'empresas' => [], 'setores' => [], 'metadados_identificador' => 'ZZCONT_' . $suffix,
    'colaboradores_legado' => [], 'cargo_setores_legado' => [], 'cargo_setores_metadados' => [],
    'usuarios' => [],
];

try {
    // ---- Fixtures: empresas + setores locais -----------------------------------------------------
    $codigoEmpresaA = 'ZZCA' . substr($suffix, -4);
    $codigoEmpresaB = 'ZZCB' . substr($suffix, -4);
    $codigoSetorS1 = 'ZS1' . substr($suffix, -3);
    $codigoSetorS2 = 'ZS2' . substr($suffix, -3);
    $codigoSetorOrfao = 'ZSORF' . substr($suffix, -3);

    $pdo->prepare('INSERT INTO empresas (codigo_empresa, nome, slug, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$codigoEmpresaA, 'ZZDASH Empresa A ' . $suffix, 'zzcont-empresa-a-' . $suffix]);
    $empresaAId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaAId;

    $pdo->prepare('INSERT INTO empresas (codigo_empresa, nome, slug, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$codigoEmpresaB, 'ZZDASH Empresa B ' . $suffix, 'zzcont-empresa-b-' . $suffix]);
    $empresaBId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaBId;

    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, empresa_id, ativo) VALUES (?, ?, ?, ?, 1)')
        ->execute([$codigoSetorS1, 'ZZDASH Setor S1 ' . $suffix, 'zzcont-setor-s1-' . $suffix, $empresaAId]);
    $setorS1Id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorS1Id;

    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, empresa_id, ativo) VALUES (?, ?, ?, ?, 1)')
        ->execute([$codigoSetorS2, 'ZZDASH Setor S2 ' . $suffix, 'zzcont-setor-s2-' . $suffix, $empresaAId]);
    $setorS2Id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorS2Id;

    // S3: sem codigo_setor — só existe para "Setores" (contagem de setores) da Empresa A ficar
    // diferente de "Colaboradores ativos" (evita qualquer coincidência numérica no teste).
    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, empresa_id, ativo) VALUES (NULL, ?, ?, ?, 1)')
        ->execute(['ZZDASH Setor S3 sem código ' . $suffix, 'zzcont-setor-s3-' . $suffix, $empresaAId]);
    $setorS3Id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorS3Id;

    // ---- Fixtures: colaboradores_metadados (espelho oficial) ---------------------------------------
    $marcaZz = $criados['metadados_identificador'];
    $mkMetadados = static function (
        string $codigoPessoa,
        string $sufixoContrato,
        ?string $codigoEmpresa,
        ?string $codigoSetor,
        bool $ativo
    ) use ($pdo, $marcaZz): void {
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                nome, codigo_setor, setor, admissao, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)'
        )->execute([
            $marcaZz . '_' . $codigoPessoa . '_' . $sufixoContrato, $codigoEmpresa, 'ZZUNI', 'ZZCTR_' . $codigoPessoa . '_' . $sufixoContrato,
            $codigoPessoa, 'ZZDASH Fixture ' . $codigoPessoa, $codigoSetor,
            $codigoSetor !== null ? 'Setor texto ' . $codigoSetor : null,
            $ativo ? 1 : 0, 'zzcont-teste',
        ]);
    };

    // ZZP1: 2 contratos ATIVOS na mesma empresa/setor -> deve contar como 1 pessoa só (dedup).
    $mkMetadados('ZZP1', 'A', $codigoEmpresaA, $codigoSetorS1, true);
    $mkMetadados('ZZP1', 'B', $codigoEmpresaA, $codigoSetorS1, true);
    // ZZP2: contrato INATIVO no mesmo setor -> não deve entrar em nenhuma contagem.
    $mkMetadados('ZZP2', 'A', $codigoEmpresaA, $codigoSetorS1, false);
    // ZZP3: ativo, com Empresa mas SEM codigo_setor -> conta para a Empresa, não para setor algum.
    $mkMetadados('ZZP3', 'A', $codigoEmpresaA, null, true);
    // ZZP4/ZZP5: ativos na Empresa B (isolamento).
    $mkMetadados('ZZP4', 'A', $codigoEmpresaB, null, true);
    $mkMetadados('ZZP5', 'A', $codigoEmpresaB, null, true);
    // ZZP6: ativo, na Empresa B, com codigo_setor ÓRFÃO (sem setor local correspondente) -> não
    // pode contaminar S1/S2/S3 nem "aparecer" magicamente em nenhum setor. Fica na Empresa B (não
    // na A) só para manter os números de Empresa A e Empresa B sem coincidência acidental.
    $mkMetadados('ZZP6', 'A', $codigoEmpresaB, $codigoSetorOrfao, true);

    // ---- Ruído legado: colaboradores.setor_id / colaboradores.empresa_id / cargo_id NÃO deve influenciar
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $check($cargoId > 0, 'Fixture: existe cargo no catálogo local para o ruído legado de colaboradores/cargo_setores');
    for ($i = 1; $i <= 5; $i++) {
        $pdo->prepare(
            'INSERT INTO colaboradores (nome, slug, cargo_id, empresa_id, setor_id, ativo) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute(['ZZDASH Colaborador Legado ' . $suffix . '-' . $i, 'zzcont-colab-legado-' . $suffix . '-' . $i, $cargoId, $empresaAId, $setorS1Id]);
        $criados['colaboradores_legado'][] = (int)$pdo->lastInsertId();
    }

    // Ruído legado: cargo_setores (não deve influenciar "Cargos" do Setor).
    $pdo->prepare('INSERT IGNORE INTO cargo_setores (cargo_id, setor_id) VALUES (?, ?)')->execute([$cargoId, $setorS1Id]);
    $criados['cargo_setores_legado'][] = [$cargoId, $setorS1Id];

    // Fonte oficial de Cargo x Setor: cargo_setores_metadados (deve influenciar "Cargos" do Setor).
    $cargoIdOficial2 = (int)$pdo->query("SELECT id FROM cargos WHERE id <> {$cargoId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    $check($cargoIdOficial2 > 0, 'Fixture: existe um segundo cargo distinto para popular cargo_setores_metadados');
    $pdo->prepare('INSERT IGNORE INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados) VALUES (?, ?, ?)')
        ->execute([$cargoId, $setorS1Id, 'zzcont-teste']);
    $pdo->prepare('INSERT IGNORE INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados) VALUES (?, ?, ?)')
        ->execute([$cargoIdOficial2, $setorS1Id, 'zzcont-teste']);
    $criados['cargo_setores_metadados'][] = [$cargoId, $setorS1Id];
    $criados['cargo_setores_metadados'][] = [$cargoIdOficial2, $setorS1Id];

    // ================================================================================================
    // EMPRESAS
    // ================================================================================================
    $itensEmpresa = CadastroOrganizacional::all('empresas', []);
    $empresaA = null;
    $empresaB = null;
    foreach ($itensEmpresa as $item) {
        if ((int)$item['id'] === $empresaAId) {
            $empresaA = $item;
        }
        if ((int)$item['id'] === $empresaBId) {
            $empresaB = $item;
        }
    }
    $check($empresaA !== null && $empresaB !== null, 'Fixtures de Empresa aparecem na listagem de CadastroOrganizacional::all()');

    $check((int)($empresaA['setores_count'] ?? -1) === 3, '(1) Empresa A: "Setores" = 3 (S1+S2+S3) — métrica própria, separada de colaboradores');
    $check((int)($empresaA['usage_count'] ?? -1) === 2, '(2) Empresa A: "Colaboradores ativos" = 2 (ZZP1 dedup + ZZP3) via codigo_empresa — diferente de "Setores" (3), prova que não é mais a mesma contagem');
    $check((int)($empresaB['usage_count'] ?? -1) === 3, '(2b) Empresa B: "Colaboradores ativos" = 3 (ZZP4 + ZZP5 + ZZP6) — isolamento correto entre empresas');
    $check((int)($empresaA['usage_count'] ?? -1) !== 5, '(3) ZZP2 (contrato inativo) NÃO entra na contagem da Empresa A (senão daria 3, não 2)');

    // (7) colaboradores.setor_id/empresa_id local não influencia mais o contador oficial de Empresa —
    // 5 linhas de ruído legado foram inseridas acima em colaboradores(empresa_id=A) e o resultado
    // continua sendo 2 (via colaboradores_metadados), não 2+5.
    $check((int)($empresaA['usage_count'] ?? -1) === 2, '(7) 5 linhas de ruído em `colaboradores.empresa_id` não alteram "Colaboradores ativos" da Empresa (continua 2, fonte é colaboradores_metadados)');

    // ================================================================================================
    // SETORES
    // ================================================================================================
    $setorRepo = new SetorRepository();
    $resultado = $setorRepo->paginateAdmin(['empresa' => (string)$empresaAId], 1, 50);
    $porId = [];
    foreach ($resultado['items'] as $item) {
        $porId[(int)$item['id']] = $item;
    }
    $check(isset($porId[$setorS1Id], $porId[$setorS2Id], $porId[$setorS3Id]), 'Fixtures de Setor aparecem em SetorRepository::paginateAdmin()');

    $check((int)($porId[$setorS1Id]['colaboradores_vinculados'] ?? -1) === 1, '(4)+(5) Setor S1: "Colaboradores" = 1 — ZZP1 com 2 contratos ativos conta uma pessoa só (dedup por codigo_pessoa)');
    $check((int)($porId[$setorS1Id]['colaboradores_vinculados'] ?? -1) !== 2, '(3-setor) ZZP2 (inativo) não entra no contador do Setor S1');
    $check((int)($porId[$setorS2Id]['colaboradores_vinculados'] ?? -1) === 0, '(10) Setor S2 (código oficial, sem ninguém no espelho) retorna 0, não erro nem valor inventado');
    $check((int)($porId[$setorS3Id]['colaboradores_vinculados'] ?? -1) === 0, '(6) Setor S3 (sem codigo_setor) nunca recebe atribuição — ZZP3 (sem setor) fica de fora de qualquer Setor');

    // (11) codigo_setor órfão (ZZP6) não aparece em nenhum dos 3 setores fixture.
    $somaS1S2S3 = (int)($porId[$setorS1Id]['colaboradores_vinculados'] ?? 0)
        + (int)($porId[$setorS2Id]['colaboradores_vinculados'] ?? 0)
        + (int)($porId[$setorS3Id]['colaboradores_vinculados'] ?? 0);
    $check($somaS1S2S3 === 1, '(11) Código de Setor órfão (ZZP6, sem setor local correspondente) não se associa a nenhum dos 3 setores fixture — soma continua 1 (só ZZP1), não 2');
    $stmtOrfao = $pdo->prepare('SELECT COUNT(*) FROM setores WHERE codigo_setor = ?');
    $stmtOrfao->execute([$codigoSetorOrfao]);
    $check((int)$stmtOrfao->fetchColumn() === 0, '(11b) Confirma que o código usado para ZZP6 realmente não existe em nenhum Setor local (é órfão de verdade, não coincidência)');

    // (9) Cargos do Setor vem de cargo_setores_metadados (2 relações inseridas -> 2).
    $check((int)($porId[$setorS1Id]['cargos_vinculados'] ?? -1) === 2, '(9) Setor S1: "Cargos" = 2, exatamente as relações inseridas em cargo_setores_metadados');

    // (8) cargo_setores (legado) não influencia — só inserimos 1 relação lá (cargoId/setorS1), mas
    // cargo_setores_metadados tem 2 relações diferentes; o resultado bate com o oficial (2), não
    // com o legado (1) nem com a soma dos dois (que também daria 2 por coincidência de cargoId
    // repetido — por isso o assert abaixo also verifica que remover a fonte legada não muda nada).
    $pdo->prepare('DELETE FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?')->execute([$cargoId, $setorS1Id]);
    $resultadoSemLegado = $setorRepo->paginateAdmin(['empresa' => (string)$empresaAId], 1, 50);
    $porIdSemLegado = [];
    foreach ($resultadoSemLegado['items'] as $item) {
        $porIdSemLegado[(int)$item['id']] = $item;
    }
    $check(
        (int)($porIdSemLegado[$setorS1Id]['cargos_vinculados'] ?? -1) === 2,
        '(8) Apagar a relação em `cargo_setores` (legado) não muda "Cargos" do Setor S1 (continua 2) — a fonte real é cargo_setores_metadados'
    );
    // Recriar a linha legada para a limpeza no finally não precisar tratar caso especial.
    $pdo->prepare('INSERT IGNORE INTO cargo_setores (cargo_id, setor_id) VALUES (?, ?)')->execute([$cargoId, $setorS1Id]);

    // ================================================================================================
    // (12) Páginas renderizam sem Warning/Notice/Fatal (smoke test via sessão simulada de Admin)
    // ================================================================================================
    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $adminId = User::create('ZZCONT Admin', 'admin.zzcont.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];

    ob_start();
    $erroEmpresas = null;
    try {
        (new AdminEmpresasController())->index();
    } catch (Throwable $e) {
        $erroEmpresas = $e;
    }
    $htmlEmpresas = ob_get_clean();

    ob_start();
    $erroSetores = null;
    try {
        (new AdminSetoresController())->index();
    } catch (Throwable $e) {
        $erroSetores = $e;
    }
    $htmlSetores = ob_get_clean();

    $check($erroEmpresas === null, '(12a) AdminEmpresasController::index() renderiza sem lançar exceção — ' . ($erroEmpresas?->getMessage() ?? 'ok'));
    $check($erroSetores === null, '(12b) AdminSetoresController::index() renderiza sem lançar exceção — ' . ($erroSetores?->getMessage() ?? 'ok'));
    $check(
        $erroEmpresas === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlEmpresas),
        '(12c) HTML de /admin/empresas sem Warning/Notice/Fatal'
    );
    $check(
        $erroSetores === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlSetores),
        '(12d) HTML de /admin/setores sem Warning/Notice/Fatal'
    );
    $check($erroEmpresas === null && str_contains($htmlEmpresas, 'Setores') && str_contains($htmlEmpresas, 'Colaboradores ativos'), '(12e) Tela de Empresas exibe as duas colunas novas ("Setores" e "Colaboradores ativos")');

    echo "\nCONTADORES_EMPRESA_SETOR_METADADOS_OK\n";
} finally {
    // ---- limpeza (ordem respeita as FKs: filhos antes dos pais) --------------------------------
    if (!empty($criados['colaboradores_legado'])) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id IN (' . implode(',', array_map('intval', $criados['colaboradores_legado'])) . ')')->execute();
    }
    foreach ($criados['cargo_setores_metadados'] as [$cId, $sId]) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id = ? AND setor_id = ?')->execute([$cId, $sId]);
    }
    foreach ($criados['cargo_setores_legado'] as [$cId, $sId]) {
        $pdo->prepare('DELETE FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?')->execute([$cId, $sId]);
    }
    $pdo->prepare('DELETE FROM colaboradores_metadados WHERE identificador LIKE ?')->execute([$criados['metadados_identificador'] . '_%']);
    if (!empty($criados['setores'])) {
        $pdo->prepare('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $criados['setores'])) . ')')->execute();
    }
    if (!empty($criados['empresas'])) {
        $pdo->prepare('DELETE FROM empresas WHERE id IN (' . implode(',', array_map('intval', $criados['empresas'])) . ')')->execute();
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
