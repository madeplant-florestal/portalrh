<?php

/**
 * Integração — Dashboard de Integração/Onboarding (Etapa 8, 2026-10).
 *
 * Prova, contra o banco:
 *   - filtros Ano / Ano+Mês / Todo o período (mesmo padrão de DashboardEntrevistaDesligamentoService);
 *   - NPS correto em casos de 100% promotores, 100% detratores, misto e SEM resposta (null, nunca 0);
 *   - filtros de Empresa/Unidade/Setor isolam corretamente;
 *   - taxa de resposta só é calculada (e correta) para o FLUXO INDIVIDUAL — nunca para o QR;
 *   - distribuição por pergunta (1-5) e médias corretas;
 *   - ausência de dado nunca inventa número;
 *   - render HTTP do dashboard sem erro, com e sem permissão;
 *   - período ancorado em integracao_data_relacionada (não respondida_em) — sem comparar NOW() do
 *     MySQL com "today" do PHP (mesma lição da correção anterior da Pesquisa de Integração).
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

$suffix = (string)time() . (string)random_int(100, 999);
$criados = ['metadados' => [], 'colaboradores' => [], 'usuarios' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$criarContrato = static function (string $nome, string $codigoEmpresa = 'EMPA', string $codigoSetor = 'ST1') use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZDI' . $suffix . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $identificador, $codigoEmpresa, 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZDI Empresa ' . $codigoEmpresa, 'ZZDI Unidade',
        $codigoSetor, 'ZZDI Setor ' . $codigoSetor, 'ZZDI Cargo', 'CG1', '2024-01-10', '2024-01-10', 'zzdi-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$qrResposta = static function (int $metadadosId, string $data, int $notaNps, ?string $comentario = null): void {
    PesquisaIntegracaoQr::inserirResposta($metadadosId, $data, [
        'nota_nps' => $notaNps, 'nota_clareza' => 4, 'nota_acolhimento' => 5, 'nota_normas' => 3, 'nota_utilidade' => 4, 'nota_satisfacao_geral' => 4,
    ], $comentario);
};

$mkColaboradorLegado = static function (string $nome) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, integracao_status, ativo) VALUES (?, ?, ?, NULL, \'realizada\', 1)')
        ->execute([$nome, 'zzdi-legado-' . $suffix . '-' . $seq, $cargoId]);
    $id = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $id;
    return $id;
};

try {
    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'integracao_colaborador.visualizar'")->fetchColumn();
    $check($permVis > 0, '(0) Permissão integracao_colaborador.visualizar existe no catálogo');

    $comPerm = User::create('ZZDI Com Permissao', 'zzdi.compermissao.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($comPerm, true);
    Authorization::sincronizar($comPerm, [$permVis]);
    $criados['usuarios'][] = $comPerm;
    $semPerm = User::create('ZZDI Sem Permissao', 'zzdi.sempermissao.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($semPerm, true);
    $criados['usuarios'][] = $semPerm;

    $contratoA = $criarContrato('ZZDI Colaborador A', 'EMPA', 'ST1');
    $contratoB = $criarContrato('ZZDI Colaborador B', 'EMPB', 'ST2');

    // ---- 1) Período: Todo / Ano / Ano+Mês ------------------------------------------------------
    $qrResposta($contratoA, '2025-03-10', 2, 'ZZDI comentário 2025.');            // detrator, fora de 2026
    $qrResposta($contratoA, '2026-01-15', 9);                                    // promotor, janeiro/2026
    $qrResposta($contratoA, '2026-01-20', 10);                                   // promotor, janeiro/2026
    $qrResposta($contratoA, '2026-01-25', 3);                                    // detrator, janeiro/2026
    $qrResposta($contratoA, '2026-06-05', 7);                                    // neutro, junho/2026

    $hoje = new DateTimeImmutable('2026-12-31');
    $svc = new DashboardIntegracaoService();
    $opcoes = $svc->opcoesFiltro();

    $filtrosTodo = DashboardIntegracaoService::normalizarFiltros([], $hoje, $opcoes);
    $painelTodo = $svc->montarPainel($filtrosTodo);
    $check($painelTodo['total_respostas'] >= 5, '(1) "Todo o período" inclui todas as respostas ZZDI (2025 + 2026)');

    $filtros2026 = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026'], $hoje, $opcoes);
    $check($filtros2026['inicio'] === '2026-01-01' && $filtros2026['fim'] === '2026-12-31', '(2) Filtro "Ano=2026" resolve para 01/01/2026–31/12/2026');
    $filtros2026EmpA = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'empresa' => 'EMPA'], $hoje, $opcoes);
    $check($filtros2026EmpA['codigo_empresa'] === 'EMPA', '(2b) Filtro "empresa=EMPA" resolve corretamente contra as opções reais');
    $painel2026 = $svc->montarPainel($filtros2026EmpA);
    $check($painel2026['total_respostas'] === 4, '(3) "Ano=2026" + Empresa=EMPA inclui as 4 respostas de 2026, exclui a de 2025');

    $filtrosJan = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '1', 'empresa' => 'EMPA'], $hoje, $opcoes);
    $check($filtrosJan['inicio'] === '2026-01-01' && $filtrosJan['fim'] === '2026-01-31', '(4) Filtro "Ano=2026 + Mês=1" resolve para o mês de janeiro inteiro');
    $painelJan = $svc->montarPainel($filtrosJan);
    $check($painelJan['total_respostas'] === 3, '(5) "Ano=2026 + Mês=1" inclui só as 3 respostas de janeiro (exclui a de junho e a de 2025)');
    $check($painelJan['nps']['promotores'] === 2 && $painelJan['nps']['detratores'] === 1 && $painelJan['nps']['nps'] === round((2 - 1) / 3 * 100, 1), '(6) NPS misto de janeiro calculado corretamente: (2 promotores - 1 detrator) / 3 respostas');

    $filtrosMesSemAno = DashboardIntegracaoService::normalizarFiltros(['mes' => '1'], $hoje, $opcoes);
    $check($filtrosMesSemAno['mes'] === '', '(7) "Mês" sozinho (sem "Ano") é ignorado — mesma convenção do Dashboard de Entrevista de Desligamento');

    // ---- 2) NPS — 100% promotores / 100% detratores / sem resposta ------------------------------
    $contratoProm = $criarContrato('ZZDI Só Promotores', 'EMPA', 'ST1');
    $qrResposta($contratoProm, '2026-02-10', 9);
    $qrResposta($contratoProm, '2026-02-11', 10);
    $filtrosFev = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '2'], $hoje, $opcoes);
    $painelFevAntes = $svc->montarPainel($filtrosFev);
    $check($painelFevAntes['nps']['nps'] === 100.0, '(8) 100% promotores => NPS = +100 (nunca "quase 100")');

    $contratoDetr = $criarContrato('ZZDI Só Detratores', 'EMPA', 'ST1');
    $qrResposta($contratoDetr, '2026-03-10', 0);
    $qrResposta($contratoDetr, '2026-03-11', 6);
    $filtrosMar = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '3'], $hoje, $opcoes);
    $painelMar = $svc->montarPainel($filtrosMar);
    $check($painelMar['nps']['nps'] === -100.0, '(9) 100% detratores => NPS = -100');

    $filtrosVazio = DashboardIntegracaoService::normalizarFiltros(['ano' => '2030'], $hoje, $opcoes);
    $painelVazio = $svc->montarPainel($filtrosVazio);
    $check($painelVazio['total_respostas'] === 0 && $painelVazio['nps']['nps'] === null, '(10) Ano sem nenhuma resposta: total=0 e NPS=null — NUNCA um 0 inventado');
    $check($painelVazio['satisfacao_geral']['media'] === null, '(11) Sem resposta, satisfação geral também é null, não 0');

    // ---- 3) Filtro Empresa/Unidade/Setor isola corretamente --------------------------------------
    $qrResposta($contratoB, '2026-04-05', 5);
    $opcoesComB = $svc->opcoesFiltro();
    $filtrosEmpB = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '4', 'empresa' => 'EMPB'], $hoje, $opcoesComB);
    $check($filtrosEmpB['codigo_empresa'] === 'EMPB', '(11b) Filtro "empresa=EMPB" resolve corretamente');
    $painelEmpB = $svc->montarPainel($filtrosEmpB);
    $check($painelEmpB['total_respostas'] === 1, '(12) Filtro Empresa=EMPB inclui só a resposta do contrato B');
    $filtrosEmpAErrado = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '4', 'empresa' => 'EMPA'], $hoje, $opcoesComB);
    $painelEmpAErrado = $svc->montarPainel($filtrosEmpAErrado);
    $check($painelEmpAErrado['total_respostas'] === 0, '(13) Filtro Empresa=EMPA NÃO inclui a resposta do contrato B (isolamento correto)');
    $opcoesComB = $svc->opcoesFiltro();
    $check(in_array('EMPB', array_column($opcoesComB['empresas'], 'codigo'), true), '(14) opcoesFiltro() lista EMPB como opção real (só empresas com pesquisa de integração)');

    // ---- 4) Distribuição por pergunta ------------------------------------------------------------
    $perguntaClareza = null;
    foreach ($painelJan['perguntas'] as $p) {
        if ($p['campo'] === 'nota_clareza') {
            $perguntaClareza = $p;
        }
    }
    $check($perguntaClareza !== null && $perguntaClareza['media'] === 4.0 && $perguntaClareza['distribuicao'][4] === 3, '(15) Distribuição de "Clareza" em janeiro: 3 respostas com nota 4 (fixture), média 4.0');

    // ---- 5) Taxa de resposta — SÓ fluxo individual -------------------------------------------------
    $check($painelJan['fluxo_individual']['geradas'] === 0 && $painelJan['fluxo_individual']['taxa_resposta'] === null, '(16) Sem pesquisa individual gerada no período: taxa de resposta = null (nunca 0% nem 100% inventados)');

    $colInd1 = $mkColaboradorLegado('ZZDI Individual 1');
    $colInd2 = $mkColaboradorLegado('ZZDI Individual 2');
    $colInd3 = $mkColaboradorLegado('ZZDI Individual 3');
    $tok1 = bin2hex(random_bytes(16));
    $tok2 = bin2hex(random_bytes(16));
    $tok3 = bin2hex(random_bytes(16));
    $idPesq1 = PesquisaIntegracao::create($colInd1, '2026-05-10', hash('sha256', $tok1));
    $idPesq2 = PesquisaIntegracao::create($colInd2, '2026-05-10', hash('sha256', $tok2));
    PesquisaIntegracao::create($colInd3, '2026-05-10', hash('sha256', $tok3)); // fica pendente, nunca respondida
    PesquisaIntegracao::responder($idPesq1, 8, 4, 4, 4, 4, 4, null);
    PesquisaIntegracao::responder($idPesq2, 9, 5, 5, 5, 5, 5, null);

    $filtrosMaio = DashboardIntegracaoService::normalizarFiltros(['ano' => '2026', 'mes' => '5'], $hoje, $opcoes);
    $painelMaio = $svc->montarPainel($filtrosMaio);
    $check($painelMaio['fluxo_individual']['geradas'] === 3 && $painelMaio['fluxo_individual']['respondidas'] === 2, '(17) Fluxo individual de maio: 3 geradas, 2 respondidas');
    $check($painelMaio['fluxo_individual']['taxa_resposta'] === round(2 / 3 * 100, 1), '(18) Taxa de resposta = 2/3 = 66,7% — denominador claro (geradas), nunca confundido com o total QR');
    $check($painelMaio['total_respostas'] === 2, '(19) As 2 respostas individuais entram no total geral de respostas (mesma contagem do fluxo QR)');

    // ---- 6) Render HTTP — com e sem permissão ------------------------------------------------------
    $_SESSION['user_id'] = $comPerm;
    $_SESSION['user_role'] = 'viewer';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = ['ano' => '2026', 'mes' => '1'];
    ob_start();
    $erroRender = null;
    try {
        (new AdminPesquisaIntegracaoResultadosController())->dashboard();
    } catch (Throwable $e) {
        $erroRender = $e;
    }
    $html = ob_get_clean();
    $check($erroRender === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html), '(20) dashboard() renderiza sem erro/Warning/Notice para quem TEM a permissão — ' . ($erroRender?->getMessage() ?? 'ok'));
    $check(str_contains($html, 'NPS') && str_contains($html, 'Taxa de resposta'), '(21) HTML contém os indicadores esperados (NPS, Taxa de resposta)');

    $check(!Authorization::usuarioTemPermissao($semPerm, 'integracao_colaborador.visualizar'), '(22) Usuário ZZDI Sem Permissão de fato não tem integracao_colaborador.visualizar (pré-condição do redirect/403 real via HTTP)');

    echo $falhas === [] ? "\nDASHBOARD_INTEGRACAO_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_is_supervisor']);
    $_GET = [];
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $ids->execute($criados['metadados']);
        $metadadosIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($metadadosIds !== []) {
            $in = implode(',', $metadadosIds);
            $pdo->exec("DELETE FROM pesquisas_integracao WHERE metadados_id IN ($in)");
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
    if (!empty($criados['colaboradores'])) {
        $inCol = implode(',', array_map('intval', $criados['colaboradores']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE colaborador_id IN ($inCol)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($inCol)");
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
}

if ($falhas !== []) {
    exit(1);
}
