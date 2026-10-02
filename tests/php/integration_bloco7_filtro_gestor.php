<?php

/**
 * Integração — Bloco 7 (2026-10, pedido do RH): filtro por Gestor, derivado de
 * `Usuário gestor -> Setores gerenciados -> colaboradores_metadados.codigo_setor`.
 *
 * Decisão de negócio: não existe vínculo individual colaborador -> gestor confiável no METADADOS
 * (investigado e descartado). "Setores gerenciados" (tabela `usuario_setores_gerenciados`, nova,
 * separada de `usuario_setores`/`gestor_usuario_id`) é declarado manualmente pelo RH/Admin.
 *
 * Fixtures ZZB7-* com limpeza em `finally`. Prova, contra o banco:
 *   - UsuarioSetoresGerenciadosService: persistência (replace completo), gestoresElegiveis()
 *     (só ativos com >=1 setor), codigosSetorGerenciadosPor();
 *   - gestor com 1 setor e gestor com vários setores;
 *   - dois gestores no MESMO setor: filtrar qualquer um mostra o setor inteiro (responsabilidade
 *     compartilhada, nunca dedução individual por colaborador);
 *   - usuário sem nenhum setor gerenciado e usuário inativo NUNCA aparecem como gestor filtrável;
 *   - colaborador sem setor nunca entra em nenhum filtro de Gestor;
 *   - Dashboard de Integração: Gestor sozinho, Gestor + Empresa, Gestor + Setor (combinação AND,
 *     inclusive o caso contraditório que zera o resultado) — afeta os 7 pontos do painel
 *     (integrações realizadas, respostas, NPS, satisfação, taxa de resposta, comentários, mensal);
 *   - People Analytics: o filtro de Gestor muda SÓ o bloco Integração/Onboarding — Headcount não é
 *     afetado pelo mesmo parâmetro;
 *   - nenhuma fórmula mudou: mesmo resultado filtrando por Gestor vs. filtrando pelo(s) mesmo(s)
 *     código(s) de Setor diretamente.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
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

$suffix = substr((string)time(), -4) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'setores' => [], 'metadados' => [], 'pesquisas' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$mkSetor = static function (string $codigo, string $nome) use ($pdo, &$criados): int {
    $stmt = $pdo->prepare(
        "INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados)
         VALUES (?, ?, ?, ?, 1, 'A', 'zzb7-teste')"
    );
    $stmt->execute([$codigo, $nome, $nome, 'zzb7-' . strtolower($codigo) . '-' . uniqid()]);
    $id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $id;
    return $id;
};

$mkUsuario = static function (string $nome, bool $ativo) use (&$criados, $senha, $suffix): int {
    static $i = 0;
    $i++;
    $id = User::create("ZZB7 {$nome}", "zzb7.{$suffix}.{$i}@teste.local", $senha, 'viewer');
    User::setActiveStatus($id, $ativo);
    $criados['usuarios'][] = $id;
    return $id;
};

$mkMetadados = static function (string $codigoSetor, string $setorNome, string $codigoEmpresa, string $nome) use ($pdo, &$criados, $suffix): int {
    static $i = 0;
    $i++;
    $stmt = $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa,
            codigo_setor, setor, cargo, codigo_cargo, admissao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        "ZZB7{$suffix}-{$i}", $codigoEmpresa, 'UNI', "ZZB7C{$suffix}{$i}", "ZZB7P{$suffix}{$i}", $nome, "Empresa {$codigoEmpresa}",
        $codigoSetor, $setorNome, 'ZZB7 Cargo', 'ZZB7CG', date('Y-m-d', strtotime('-200 days')),
        'zzb7-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

$mkResposta = static function (int $metadadosId, int $notaNps) use ($pdo, &$criados): int {
    $stmt = $pdo->prepare(
        "INSERT INTO pesquisas_integracao (metadados_id, integracao_data_relacionada, token_hash, nota_nps, nota_satisfacao_geral, respondida_em)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$metadadosId, date('Y-m-d', strtotime('-10 days')), hash('sha256', uniqid('zzb7', true)), $notaNps, $notaNps]);
    $id = (int)$pdo->lastInsertId();
    $criados['pesquisas'][] = $id;
    return $id;
};

try {
    // ---- Fixtures: 3 setores, 4 usuários (gestores), 4 colaboradores_metadados + respostas ------
    $setorS1 = $mkSetor('ZZB7S1', 'ZZB7 Setor Um');
    $setorS2 = $mkSetor('ZZB7S2', 'ZZB7 Setor Dois');
    $setorS4 = $mkSetor('ZZB7S4', 'ZZB7 Setor Quatro (sem gestor)');

    $gestorA = $mkUsuario('Gestor A (1 setor: S1)', true);
    $gestorB = $mkUsuario('Gestor B (2 setores: S1+S2)', true);
    $gestorInativo = $mkUsuario('Gestor Inativo (setor S1)', false);
    $gestorSemSetor = $mkUsuario('Gestor Sem Setor', true);

    $service = new UsuarioSetoresGerenciadosService($pdo);

    // ---- 1) Persistência -----------------------------------------------------------------------
    $r1 = $service->definirSetoresGerenciados($gestorA, [$setorS1]);
    $check(($r1['ok'] ?? false) === true, '(1) definirSetoresGerenciados() salva com sucesso para Gestor A (1 setor)');
    $codigosA = $service->codigosSetorGerenciadosPor($gestorA);
    $check($codigosA === ['ZZB7S1'], '(1) Gestor A tem exatamente 1 setor gerenciado: ZZB7S1 — ' . json_encode($codigosA));

    $r2 = $service->definirSetoresGerenciados($gestorB, [$setorS1, $setorS2]);
    $check(($r2['ok'] ?? false) === true, '(1) definirSetoresGerenciados() salva com sucesso para Gestor B (2 setores)');
    $codigosB = $service->codigosSetorGerenciadosPor($gestorB);
    sort($codigosB);
    $check($codigosB === ['ZZB7S1', 'ZZB7S2'], '(1) Gestor B gerencia vários setores: ZZB7S1 + ZZB7S2 — ' . json_encode($codigosB));

    $service->definirSetoresGerenciados($gestorInativo, [$setorS1]);

    // Alteração persiste: redefine Gestor B para só S2 e confirma substituição completa (replace).
    $service->definirSetoresGerenciados($gestorB, [$setorS2]);
    $codigosBDepois = $service->codigosSetorGerenciadosPor($gestorB);
    $check($codigosBDepois === ['ZZB7S2'], '(1) Alteração dos setores gerenciados PERSISTE e substitui o conjunto anterior por completo (replace, não acumula)');
    // Restaura para o cenário dos dois setores usado no resto do teste.
    $service->definirSetoresGerenciados($gestorB, [$setorS1, $setorS2]);

    // ---- 2) gestoresElegiveis(): só ativos com >=1 setor ----------------------------------------
    $elegiveis = $service->gestoresElegiveis();
    $idsElegiveis = array_column($elegiveis, 'id');
    $check(in_array($gestorA, $idsElegiveis, true), '(2) Gestor A (ativo, 1 setor) aparece como elegível');
    $check(in_array($gestorB, $idsElegiveis, true), '(2) Gestor B (ativo, vários setores) aparece como elegível');
    $check(!in_array($gestorInativo, $idsElegiveis, true), '(2) Gestor Inativo (email_verified_at NULL) NUNCA aparece, mesmo com setor gerenciado');
    $check(!in_array($gestorSemSetor, $idsElegiveis, true), '(2) Gestor Sem Setor (0 setores marcados) não participa dos filtros por Gestor');

    // ---- 3) Dois gestores no MESMO setor: responsabilidade compartilhada -------------------------
    $check(in_array('ZZB7S1', $service->codigosSetorGerenciadosPor($gestorA), true) && in_array('ZZB7S1', $service->codigosSetorGerenciadosPor($gestorB), true), '(3) ZZB7S1 é gerenciado por DOIS gestores simultaneamente (sem exclusividade 1 setor = 1 gestor)');

    // ---- Fixtures de respostas para os cenários do Dashboard de Integração -----------------------
    $metaS1 = $mkMetadados('ZZB7S1', 'ZZB7 Setor Um', 'ZZB7EMPX', 'ZZB7 Pessoa S1');
    $metaS2 = $mkMetadados('ZZB7S2', 'ZZB7 Setor Dois', 'ZZB7EMPY', 'ZZB7 Pessoa S2');
    $metaS4 = $mkMetadados('ZZB7S4', 'ZZB7 Setor Quatro (sem gestor)', 'ZZB7EMPX', 'ZZB7 Pessoa S4');
    $metaSemSetor = $mkMetadados('', '', 'ZZB7EMPX', 'ZZB7 Pessoa Sem Setor');

    $mkResposta($metaS1, 9);
    $mkResposta($metaS2, 3);
    $mkResposta($metaS4, 5);
    $mkResposta($metaSemSetor, 1);

    $filtrosBase = ['inicio' => date('Y-m-d', strtotime('-30 days')), 'fim' => date('Y-m-d'), 'codigo_empresa' => '', 'codigo_unidade' => '', 'codigo_setor' => ''];
    $integracaoService = new DashboardIntegracaoService(new DashboardIntegracaoRepository($pdo), $service);

    // ---- 4) Gestor sozinho: Gestor A só enxerga ZZB7S1 -------------------------------------------
    $painelGestorA = $integracaoService->montarPainel(array_merge($filtrosBase, ['gestor_usuario_id' => (string)$gestorA]));
    $check($painelGestorA['total_respostas'] === 1, '(4) Filtro só por Gestor A (1 setor) retorna exatamente 1 resposta (a de ZZB7S1)');

    // ---- 5) Gestor com vários setores: Gestor B enxerga ZZB7S1 + ZZB7S2 --------------------------
    $painelGestorB = $integracaoService->montarPainel(array_merge($filtrosBase, ['gestor_usuario_id' => (string)$gestorB]));
    $check($painelGestorB['total_respostas'] === 2, '(5) Filtro só por Gestor B (2 setores) retorna 2 respostas (ZZB7S1 + ZZB7S2)');

    // ---- 6) Gestor + Empresa (combinação AND) -----------------------------------------------------
    $painelGestorBEmpresaX = $integracaoService->montarPainel(array_merge($filtrosBase, ['gestor_usuario_id' => (string)$gestorB, 'codigo_empresa' => 'ZZB7EMPX']));
    $check($painelGestorBEmpresaX['total_respostas'] === 1, '(6) Gestor B + Empresa ZZB7EMPX (só ZZB7S1 é dessa empresa) = 1 resposta — combinação respeitada');

    // ---- 7) Gestor + Setor explícito, CONTRADITÓRIO (nunca mostra fora da responsabilidade) ------
    $painelGestorASetorS2 = $integracaoService->montarPainel(array_merge($filtrosBase, ['gestor_usuario_id' => (string)$gestorA, 'codigo_setor' => 'ZZB7S2']));
    $check($painelGestorASetorS2['total_respostas'] === 0, '(7) Gestor A (só gerencia ZZB7S1) + Setor=ZZB7S2 explícito = ZERO resultados — nunca mostra fora da responsabilidade do gestor selecionado');

    // ---- 8) Colaborador sem Setor nunca aparece em nenhum filtro de Gestor ------------------------
    // Sem isolamento total do banco (outros testes podem ter fixtures recentes coexistindo), a
    // asserção fica sobre os códigos de Setor das 4 fixtures deste arquivo, nunca sobre o total
    // global da janela de 30 dias.
    $painelSemFiltro = $integracaoService->montarPainel($filtrosBase);
    $check($painelSemFiltro['total_respostas'] >= 4, '(8) Sem filtro de Gestor, as 4 respostas das fixtures ZZB7 aparecem (baseline, >= 4)');
    $check($painelGestorA['total_respostas'] + $painelGestorB['total_respostas'] < $painelSemFiltro['total_respostas'], '(8) Colaborador sem Setor (ZZB7 Pessoa Sem Setor) nunca é alcançado por nenhum filtro de Gestor');

    // ---- 9) Gestor elegível, mas com 0 setores no momento da consulta -> zero resultados (nunca "sem filtro") ----
    $painelSemSetorNoFiltro = $integracaoService->montarPainel(array_merge($filtrosBase, ['gestor_usuario_id' => (string)$gestorSemSetor]));
    $check($painelSemSetorNoFiltro['total_respostas'] === 0, '(9) Gestor sem nenhum setor gerenciado (hipoteticamente selecionado) força ZERO resultados — nunca omite o filtro silenciosamente');

    // ---- 10) Nenhuma fórmula mudou: Gestor A equivale a filtrar por Setor=ZZB7S1 diretamente ------
    $painelSetorS1Direto = $integracaoService->montarPainel(array_merge($filtrosBase, ['codigo_setor' => 'ZZB7S1']));
    $check(
        $painelGestorA['total_respostas'] === $painelSetorS1Direto['total_respostas']
        && $painelGestorA['nps']['nps'] === $painelSetorS1Direto['nps']['nps']
        && $painelGestorA['integracoes_realizadas'] === $painelSetorS1Direto['integracoes_realizadas'],
        '(10) Filtrar por Gestor A (que só gerencia ZZB7S1) dá EXATAMENTE o mesmo resultado (respostas/NPS/integrações) que filtrar por Setor=ZZB7S1 diretamente — nenhuma fórmula paralela'
    );

    // ---- 11) opcoesFiltro() expõe os gestores elegíveis -------------------------------------------
    $opcoes = $integracaoService->opcoesFiltro();
    $check(in_array($gestorA, array_column($opcoes['gestores'], 'id'), true) && !in_array($gestorInativo, array_column($opcoes['gestores'], 'id'), true), '(11) DashboardIntegracaoService::opcoesFiltro() expõe só gestores elegíveis (Gestor A sim, Gestor Inativo não)');

    // ---- 12) People Analytics: Gestor afeta SÓ o bloco Integração/Onboarding ----------------------
    $paService = new PeopleAnalyticsService(null, null, null, null, null, null, $integracaoService, $service);
    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-30 days');
    [$compInicio, $compFim] = RhIndicadoresService::periodoMesmoIntervaloAnoAnterior($inicio, $hoje);

    $painelPaSemGestor = $paService->montarPainel([], $inicio, $hoje, $compInicio, $compFim);
    $painelPaComGestorA = $paService->montarPainel(['gestor_usuario_id' => (string)$gestorA], $inicio, $hoje, $compInicio, $compFim);

    $check(
        $painelPaComGestorA['integracao_onboarding']['amostra'] !== $painelPaSemGestor['integracao_onboarding']['amostra'],
        '(12) People Analytics: selecionar Gestor A MUDA o bloco Integração/Onboarding (de 4 para 1 resposta)'
    );
    $check(
        $painelPaComGestorA['headcount']['atual'] === $painelPaSemGestor['headcount']['atual'],
        '(12) People Analytics: o MESMO parâmetro de Gestor NÃO altera Headcount Atual — filtro escopado só ao bloco auditado como seguro'
    );

    $opcoesPa = $paService->opcoesFiltro();
    $check(in_array($gestorB, array_column($opcoesPa['gestores'], 'id'), true), '(12) PeopleAnalyticsService::opcoesFiltro() também expõe a lista de gestores elegíveis');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nBLOCO7_FILTRO_GESTOR_OK\n";
} finally {
    foreach ($criados['pesquisas'] as $id) {
        $pdo->prepare('DELETE FROM pesquisas_integracao WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$id]);
    }
}
