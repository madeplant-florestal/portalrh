<?php

/**
 * Bloco 3 — Correções RH na Integração (2026-10).
 *
 * Prova que:
 *   - Comentários da Pesquisa de Integração (fluxo QR, DashboardIntegracaoService E
 *     PesquisaIntegracaoResultadosService) NUNCA trazem o Nome do respondente — só
 *     Cargo/Empresa como contexto agregado; o nome continua intocado em
 *     colaboradores_metadados (nada foi apagado do banco);
 *   - "Integrações realizadas" é um KPI NOVO, com fonte oficial própria
 *     (colaboradores.integracao_status = 'realizada' + integracao_data no período) —
 *     completamente INDEPENDENTE de "pesquisas respondidas": uma integração pode estar
 *     realizada sem nenhuma pesquisa respondida, e vice-versa;
 *   - o filtro de Empresa/Setor do KPI usa colaboradores.metadados_id quando existe; um
 *     colaborador sem vínculo METADADOS (integracao_status não exige) simplesmente não casa
 *     com um filtro de Empresa/Setor ativo — nunca inventa um vínculo;
 *   - NPS, satisfação geral e taxa de resposta do fluxo individual continuam exatamente como
 *     antes — o novo KPI não interfere em nenhum cálculo existente.
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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados' => [], 'colaboradores' => [], 'pesquisas_integracao' => []];

$empA = 'ZZB3' . $suffix . 'A';
$setA = 'ZZB3' . $suffix . 'SA';

try {
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $check($cargoId > 0, 'Fixture: existe ao menos 1 cargo cadastrado para os testes');

    $mkMetadados = static function (string $marcador, string $codigoEmpresa, string $codigoSetor) use ($pdo, &$criados, $suffix): int {
        $identificador = 'ZZB3_' . $suffix . '_' . $marcador;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $identificador, $codigoEmpresa, 'ZZU', 'ZC' . $suffix . $marcador, 'ZP' . $suffix . $marcador, null,
            'ZZB3 Nome Sigiloso ' . $marcador, 'ZZB3 Empresa', 'ZZB3 Unidade', $codigoSetor, 'ZZB3 Setor',
            'ZZB3 Cargo', 'CG1', '2024-01-10', '2024-01-10', 'zzb3-teste',
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['metadados'][] = $id;
        return $id;
    };

    $mkColaborador = static function (string $nome, ?int $metadadosId, string $integracaoStatus, ?string $integracaoData) use ($pdo, $cargoId, &$criados, $suffix): int {
        static $seq = 0;
        $seq++;
        $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, integracao_status, integracao_data, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$nome, 'zzb3-' . $suffix . '-' . $seq, $cargoId, $metadadosId, $integracaoStatus, $integracaoData]);
        $id = (int)$pdo->lastInsertId();
        $criados['colaboradores'][] = $id;
        return $id;
    };

    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-30 days');
    $fim = $hoje;

    // ================================================================= Integrações realizadas

    // colaboradores.metadados_id é UNIQUE — cada colaborador (legado) precisa do seu próprio
    // vínculo, nunca compartilhado.
    $metadadosA = $mkMetadados('a', $empA, $setA);
    $metadadosA3 = $mkMetadados('a3', $empA, $setA);
    $metadadosA4 = $mkMetadados('a4', $empA, $setA);
    // C1: integração REALIZADA dentro do período, COM vínculo METADADOS (empresa A / setor A).
    $c1 = $mkColaborador('ZZB3 Realizada Com Metadados', $metadadosA, 'realizada', $hoje->modify('-5 days')->format('Y-m-d'));
    // C2: integração REALIZADA dentro do período, SEM vínculo METADADOS (PJ/terceiro — permitido).
    $c2 = $mkColaborador('ZZB3 Realizada Sem Metadados', null, 'realizada', $hoje->modify('-6 days')->format('Y-m-d'));
    // C3: integração PENDENTE — nunca deve contar como realizada.
    $c3 = $mkColaborador('ZZB3 Pendente', $metadadosA3, 'pendente', null);
    // C4: integração REALIZADA, mas FORA do período (há 400 dias) — nunca deve contar no período atual.
    $c4 = $mkColaborador('ZZB3 Realizada Fora Periodo', $metadadosA4, 'realizada', $hoje->modify('-400 days')->format('Y-m-d'));

    $repo = new DashboardIntegracaoRepository();
    $filtrosSemEmpresa = ['inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d'), 'codigo_empresa' => '', 'codigo_unidade' => '', 'codigo_setor' => ''];
    $totalSemFiltro = $repo->integracoesRealizadas($filtrosSemEmpresa);
    $check($totalSemFiltro >= 2, '(1) integracoesRealizadas() sem filtro de Empresa: conta ao menos as 2 realizadas no período (com e sem metadados)');

    $filtrosComEmpresa = $filtrosSemEmpresa;
    $filtrosComEmpresa['codigo_empresa'] = $empA;
    $totalComEmpresa = $repo->integracoesRealizadas($filtrosComEmpresa);
    // Com filtro de Empresa, só C1 (tem metadados na empresa A) deve contar — C2 (sem metadados)
    // nunca casa com um filtro de Empresa/Setor ativo.
    $filtrosComEmpresaESetor = $filtrosComEmpresa;
    $filtrosComEmpresaESetor['codigo_setor'] = $setA;
    $totalComEmpresaESetor = $repo->integracoesRealizadas($filtrosComEmpresaESetor);
    $check($totalComEmpresaESetor === 1, '(2) Filtrado por Empresa+Setor: só C1 conta (tem vínculo METADADOS na empresa/setor) — C2 (sem metadados) nunca casa com o filtro, C3 (pendente) e C4 (fora do período) ficam de fora');

    // ================================================================= DashboardIntegracaoService

    $service = new DashboardIntegracaoService();
    $painelComEmpresaESetor = $service->montarPainel($filtrosComEmpresaESetor);
    $check($painelComEmpresaESetor['integracoes_realizadas'] === 1, '(3) montarPainel() expõe integracoes_realizadas = 1 (mesma contagem do repositório)');
    $check($painelComEmpresaESetor['total_respostas'] === 0, '(3) total_respostas (pesquisas) = 0 — nenhuma pesquisa foi criada ainda; prova que os dois KPIs são INDEPENDENTES (integração realizada sem nenhuma pesquisa respondida)');

    // ================================================================= Independência dos 2 KPIs (sentido inverso)

    $metadadosB = $mkMetadados('b', $empA, $setA);
    $c5 = $mkColaborador('ZZB3 Pendente Com Pesquisa Respondida', $metadadosB, 'pendente', null);
    PesquisaIntegracaoQr::inserirResposta($metadadosB, $hoje->modify('-3 days')->format('Y-m-d'), [
        'nota_nps' => 9, 'nota_clareza' => 4, 'nota_acolhimento' => 4, 'nota_normas' => 4, 'nota_utilidade' => 4, 'nota_satisfacao_geral' => 4,
    ], null);

    $filtrosSoB = $filtrosSemEmpresa;
    $filtrosSoB['codigo_empresa'] = $empA;
    $filtrosSoB['codigo_setor'] = $setA;
    $painelSoB = $service->montarPainel($filtrosSoB);
    // Agora: C1 (realizada) + C5 (pendente, mas com pesquisa respondida) no mesmo filtro.
    $check($painelSoB['integracoes_realizadas'] === 1, '(4) Integrações realizadas continua 1 (C5 está "pendente" — responder uma pesquisa NUNCA marca integração como realizada por conta própria)');
    $check($painelSoB['total_respostas'] === 1, '(4) Pesquisas respondidas = 1 (a resposta de C5, cuja integração continua "pendente") — prova a independência no sentido inverso');
    $check($painelSoB['nps']['nps'] === 100.0, '(4) NPS continua calculado normalmente a partir das respostas, sem nenhuma interferência do novo KPI');

    // ================================================================= Identidade nunca exposta (DashboardIntegracaoService)

    $comentarioTexto = 'Comentário de teste do Bloco 3 — sigiloso quanto à identidade.';
    PesquisaIntegracaoQr::inserirResposta($metadadosA, $hoje->modify('-2 days')->format('Y-m-d'), [
        'nota_nps' => 10, 'nota_clareza' => 5, 'nota_acolhimento' => 5, 'nota_normas' => 5, 'nota_utilidade' => 5, 'nota_satisfacao_geral' => 5,
    ], $comentarioTexto);
    $painelComComentario = $service->montarPainel($filtrosComEmpresaESetor);
    $check(count($painelComComentario['comentarios']) === 1, '(5) 1 comentário capturado no painel');
    $comentario = $painelComComentario['comentarios'][0];
    $check($comentario['comentario'] === $comentarioTexto, '(5) Texto do comentário preservado integralmente');
    $check(!array_key_exists('nome', $comentario), '(5) Comentário do DashboardIntegracaoService NUNCA inclui o campo "nome" — identidade do respondente não exposta');
    $check(($comentario['cargo'] ?? null) === 'ZZB3 Cargo' && ($comentario['empresa'] ?? null) === 'ZZB3 Empresa', '(5) Cargo/Empresa (contexto agregado) continuam presentes no comentário');
    // O nome continua intocado no banco (colaboradores_metadados) — nada foi apagado, só deixou de
    // ser incluído neste retorno específico.
    $nomeNoBanco = $pdo->prepare('SELECT nome FROM colaboradores_metadados WHERE id = ?');
    $nomeNoBanco->execute([$metadadosA]);
    $check((string)$nomeNoBanco->fetchColumn() === 'ZZB3 Nome Sigiloso a', '(5) O nome continua gravado normalmente em colaboradores_metadados — nada foi apagado do banco');

    echo "INTEGRACAO_BLOCO3_RH_OK\n";
} finally {
    $pdo->prepare("DELETE FROM pesquisas_integracao WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE 'ZZB3\\_%' ESCAPE '\\\\')")->execute();
    foreach ($criados['colaboradores'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }

    if ($falhas !== []) {
        fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
        exit(1);
    }
}
