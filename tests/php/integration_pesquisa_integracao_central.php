<?php

/**
 * Integração — Central "Pesquisas de Integração" (/admin/pesquisas-reacao-integracao): novo bloco
 * com os RESULTADOS da Pesquisa de Integração respondida via QR Code, acima das campanhas da
 * Pesquisa de Reação, mais o detalhamento por data de integração.
 *
 * Prova que:
 *   - a origem QR é identificada estruturalmente (colaborador_id e token_hash NULL) — respostas do
 *     fluxo individual (mesmo com metadados_id preenchido) e pesquisas pendentes NÃO entram;
 *   - o resumo agrupa por integracao_data_relacionada, com total, NPS (%Promotores - %Detratores),
 *     Promotores, Neutros e Detratores corretos — nunca a média das notas;
 *   - o detalhamento usa as perguntas REAIS da Pesquisa de Integração (média + distribuição 1-5),
 *     lista só comentários preenchidos com Nome/Cargo/Empresa oficiais e NUNCA exibe CPF/nascimento;
 *   - integração sem respostas não inventa números;
 *   - os dois blocos têm permissões independentes: quem só tem a da Reação não vê dados QR e quem
 *     só tem a da Integração não recebe o bloco/ações administrativas das campanhas;
 *   - as campanhas da Pesquisa de Reação continuam aparecendo e a nova rota não colide com a
 *     rota de resultados de campanha; People Analytics segue lendo as respostas normalmente.
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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'metadados' => [], 'colaboradores' => [], 'campanhas' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

// Datas de integração bem no passado — nunca colidem com dados reais do dev.
$D1 = '2001-03-05';
$D2 = '2001-04-10';
$D3 = '2001-05-15'; // sem respostas
$cpfFixture = '39053344705';
$nascimentoFixture = '1975-04-09';

$mkContrato = static function (string $nome, string $empresa, string $cargo) use ($pdo, &$criados, $suffix, $cpfFixture, $nascimentoFixture): int {
    static $seq = 0;
    $seq++;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf,
            nome, empresa, cargo, nascimento, admissao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        'ZZCE_' . $suffix . '_' . $seq, 'ZC' . $suffix, 'ZCU' . $suffix, 'ZCC' . $suffix . $seq, 'ZCP' . $suffix . $seq,
        $cpfFixture, $nome, $empresa, $cargo, $nascimentoFixture, '2000-01-01', 'zzce-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};
$notas = static fn(int $nps, int $n = 4): array => [
    'nota_nps' => $nps, 'nota_clareza' => $n, 'nota_acolhimento' => $n, 'nota_normas' => $n,
    'nota_utilidade' => $n, 'nota_satisfacao_geral' => $n,
];
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};

try {
    // ---- usuários / permissões -----------------------------------------------------------------
    $mkUser = static function (string $rotulo, array $codigos) use ($pdo, &$criados, $suffix, $senha): int {
        $id = User::create('ZZCE ' . $rotulo, strtolower($rotulo) . '.zzce.' . $suffix . '@teste.local', $senha, 'rh');
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        $ids = [];
        foreach ($codigos as $codigo) {
            $stmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
            $stmt->execute([$codigo]);
            $ids[] = (int)$stmt->fetchColumn();
        }
        Authorization::sincronizar($id, $ids);
        return $id;
    };
    $adminId = User::create('ZZCE Admin', 'admin.zzce.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;
    $soReacaoId = $mkUser('SoReacao', ['pesquisa_reacao_integracao.visualizar']);
    $soIntegracaoId = $mkUser('SoIntegracao', ['integracao_colaborador.visualizar']);
    $nadaId = $mkUser('Nada', []);
    $comoUsuario = static function (int $id, string $role): void {
        $_SESSION['user_id'] = $id;
        $_SESSION['user_role'] = $role;
        $_SESSION['user_is_supervisor'] = 0;
    };

    // ---- fixtures de respostas -----------------------------------------------------------------
    $c1 = $mkContrato('ZZCE Ana Souza', 'ZZCE Empresa Alfa', 'ZZCE Operadora');
    $c2 = $mkContrato('ZZCE Bruno Lima', 'ZZCE Empresa Alfa', 'ZZCE Motorista');
    $c3 = $mkContrato('ZZCE Carla Dias', 'ZZCE Empresa Beta', 'ZZCE Auxiliar');
    $c4 = $mkContrato('ZZCE Diego Reis', 'ZZCE Empresa Beta', 'ZZCE Analista');
    $c5 = $mkContrato('ZZCE Eva Prado', 'ZZCE Empresa Alfa', 'ZZCE Gestora');
    $cLegado = $mkContrato('ZZCE Legado Individual', 'ZZCE Empresa Alfa', 'ZZCE Cargo');

    // D1: NPS 10, 9 (promotores), 8 (neutro), 3 (detrator) -> NPS = (2-1)/4 = 25.0; médias: clareza (5+5+3+1)/4=3.5
    PesquisaIntegracaoQr::inserirResposta($c1, $D1, array_merge($notas(10, 5), []), 'Ótima recepção, muito claro.');
    PesquisaIntegracaoQr::inserirResposta($c2, $D1, $notas(9, 5), null);
    PesquisaIntegracaoQr::inserirResposta($c3, $D1, $notas(8, 3), '   ');
    PesquisaIntegracaoQr::inserirResposta($c4, $D1, $notas(3, 1), 'Faltou informação sobre benefícios.');
    // D2: 1 resposta
    PesquisaIntegracaoQr::inserirResposta($c5, $D2, $notas(6, 2), null);

    // Ruído que NÃO pode entrar no resultado QR: pesquisa INDIVIDUAL respondida (com colaborador_id,
    // token e metadados_id preenchido) na mesma data, e uma individual pendente.
    $cargoLocal = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, integracao_status, integracao_data, ativo) VALUES (?, ?, ?, ?, 'realizada', ?, 1)")
        ->execute(['ZZCE Legado', 'zzce-legado-' . $suffix, $cargoLocal, $cLegado, $D1]);
    $colLegado = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $colLegado;
    $pdo->prepare('INSERT INTO pesquisas_integracao (colaborador_id, metadados_id, integracao_data_relacionada, token_hash, nota_nps, nota_clareza, nota_acolhimento, nota_normas, nota_utilidade, nota_satisfacao_geral, comentarios, respondida_em) VALUES (?, ?, ?, ?, 10, 5, 5, 5, 5, 5, ?, NOW())')
        ->execute([$colLegado, $cLegado, $D1, hash('sha256', 'zzce-ind-' . $suffix), 'COMENTARIO-INDIVIDUAL-NAO-DEVE-APARECER']);
    $pdo->prepare('INSERT INTO pesquisas_integracao (colaborador_id, integracao_data_relacionada, token_hash) VALUES (?, ?, ?)')
        ->execute([$colLegado, $D2, hash('sha256', 'zzce-pend-' . $suffix)]);

    // ---- 1) Origem QR e resumo -----------------------------------------------------------------
    $resumo = [];
    foreach (PesquisaIntegracaoResultadosService::resumoPorIntegracao() as $linha) {
        $resumo[$linha['data_integracao']] = $linha;
    }
    $check(isset($resumo[$D1]) && isset($resumo[$D2]) && !isset($resumo[$D3]), '(1) Resumo agrupa por integracao_data_relacionada e só lista datas COM respostas QR');
    $check($resumo[$D1]['total'] === 4, '(1) D1: quantidade = 4 — a pesquisa individual respondida (metadados_id preenchido) NÃO conta como QR');
    $check($resumo[$D1]['promotores'] === 2 && $resumo[$D1]['neutros'] === 1 && $resumo[$D1]['detratores'] === 1, '(2) D1: Promotores=2, Neutros=1, Detratores=1');
    $check($resumo[$D1]['nps'] === 25.0, '(2) D1: NPS = %Promotores(50) - %Detratores(25) = 25.0 — nunca a média das notas (7,5)');
    $check($resumo[$D2]['total'] === 1 && $resumo[$D2]['detratores'] === 1 && $resumo[$D2]['nps'] === -100.0, '(2) D2: 1 resposta com NPS 6 = Detrator -> NPS -100 (pendente individual não conta)');
    $chaves = array_keys($resumo);
    $posD1 = array_search($D1, $chaves, true);
    $posD2 = array_search($D2, $chaves, true);
    $check($posD2 < $posD1, '(1) Integrações listadas da mais recente para a mais antiga');
    $check(PesquisaIntegracaoResultadosService::classificarNps(6) === 'detrator' && PesquisaIntegracaoResultadosService::classificarNps(7) === 'neutro' && PesquisaIntegracaoResultadosService::classificarNps(8) === 'neutro' && PesquisaIntegracaoResultadosService::classificarNps(9) === 'promotor', '(2) Regra NPS: 0-6 Detrator, 7-8 Neutro, 9-10 Promotor');
    $check(PesquisaIntegracaoResultadosService::calcularNps([])['nps'] === null, '(2) Sem respostas o NPS é null, nunca 0 inventado');

    // ---- 2) Detalhamento -----------------------------------------------------------------------
    $det = PesquisaIntegracaoResultadosService::resultadosDaIntegracao($D1);
    $check($det['total'] === 4 && $det['nps'] === 25.0, '(3) Detalhe D1: total e NPS coerentes com o resumo');
    $rotulosReais = array_values(PesquisaIntegracaoQrService::criteriosSatisfacao());
    $check(array_column($det['perguntas'], 'rotulo') === $rotulosReais && count($det['perguntas']) === 5, '(4) Perguntas exibidas são as 5 REAIS da Pesquisa de Integração (não as 6 da Pesquisa de Reação)');
    $check($det['perguntas'][0]['media'] === 3.5, '(4) Média da 1ª pergunta = (5+5+3+1)/4 = 3,5');
    $check($det['perguntas'][0]['distribuicao'] === [1 => 1, 2 => 0, 3 => 1, 4 => 0, 5 => 2], '(4) Distribuição 1-5 da 1ª pergunta correta');
    $check(count($det['comentarios']) === 2, '(5) Só comentários preenchidos aparecem (vazio/branco e o da pesquisa individual ficam de fora)');
    $porNome = [];
    foreach ($det['comentarios'] as $c) {
        $porNome[(string)$c['nome']] = $c;
    }
    $check(($porNome['ZZCE Ana Souza']['cargo'] ?? null) === 'ZZCE Operadora' && ($porNome['ZZCE Ana Souza']['empresa'] ?? null) === 'ZZCE Empresa Alfa', '(6) Nome/Cargo/Empresa do comentário vêm do espelho oficial (contrato)');
    $check(!array_key_exists('cpf', $det['comentarios'][0]) && !array_key_exists('nascimento', $det['comentarios'][0]) && !array_key_exists('metadados_id', $det['comentarios'][0]), '(6) Nenhum CPF/nascimento/metadados_id nos dados de detalhe');
    $vazio = PesquisaIntegracaoResultadosService::resultadosDaIntegracao($D3);
    $check($vazio['total'] === 0 && $vazio['nps'] === null && $vazio['comentarios'] === [] && $vazio['perguntas'][0]['media'] === null, '(7) Integração sem respostas: total 0, NPS/médias nulos, sem comentários');
    $check(PesquisaIntegracaoResultadosService::dataValida('2001-02-30') === null && PesquisaIntegracaoResultadosService::dataValida('abc') === null && PesquisaIntegracaoResultadosService::dataValida($D1) === $D1, 'Parâmetro de data é validado estritamente (Y-m-d real)');

    // ---- 3) Telas ------------------------------------------------------------------------------
    $_GET = [];
    $campanha = PesquisaReacaoIntegracaoService::criarCampanha(['validade_dias' => '7', 'data_integracao' => '2001-06-20'], $adminId);
    $criados['campanhas'][] = (int)$campanha['id'];

    $comoUsuario($adminId, 'admin');
    $htmlAdmin = $renderizar(static fn() => (new AdminPesquisaReacaoIntegracaoController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlAdmin), '(8) Central renderiza sem Warning/Notice/Fatal');
    $check(str_contains($htmlAdmin, 'Pesquisas de Integração') && str_contains($htmlAdmin, 'Acompanhe as pesquisas aplicadas aos colaboradores e os resultados das integrações realizadas.'), '(8) Título e subtítulo da central atualizados');
    $posQr = strpos($htmlAdmin, 'Pesquisa de Integração — Respostas via QR Code');
    $posReacao = strpos($htmlAdmin, 'id="bloco-reacao"');
    $check($posQr !== false && $posReacao !== false && $posQr < $posReacao, '(8) Bloco QR vem ANTES do bloco da Pesquisa de Reação, claramente separados');
    $check(str_contains($htmlAdmin, '05/03/2001') && str_contains($htmlAdmin, '/admin/pesquisas-reacao-integracao/integracao/' . $D1 . '/resultados') && str_contains($htmlAdmin, 'Ver resultados'), '(8) Linha da integração D1 com data, respostas e link "Ver resultados"');
    $check(str_contains($htmlAdmin, '+25,0'), '(8) NPS exibido com sinal (+25,0)');
    $check(str_contains($htmlAdmin, 'Gerar link') && str_contains($htmlAdmin, 'Desativar'), '(9) Campanhas da Pesquisa de Reação continuam com "Gerar link" e "Desativar"');
    $check(str_contains($htmlAdmin, 'Ver resultados') && str_contains($htmlAdmin, '/admin/pesquisas-reacao-integracao/' . (int)$campanha['id'] . '/resultados'), '(9) A campanha criada aparece com "Ver resultados"');
    $check(!str_contains($htmlAdmin, 'COMENTARIO-INDIVIDUAL') && !str_contains($htmlAdmin, $cpfFixture) && !str_contains($htmlAdmin, '1975'), '(10) Central não expõe comentários, CPF ou nascimento');

    // permissões independentes
    $comoUsuario($soReacaoId, 'rh');
    $htmlSoReacao = $renderizar(static fn() => (new AdminPesquisaReacaoIntegracaoController())->index());
    $check(str_contains($htmlSoReacao, 'id="bloco-reacao"') && !str_contains($htmlSoReacao, 'Respostas via QR Code') && !str_contains($htmlSoReacao, '05/03/2001'), '(11) Usuário só com a permissão da Reação vê as campanhas e NÃO vê dados QR');
    $comoUsuario($soIntegracaoId, 'rh');
    $htmlSoIntegracao = $renderizar(static fn() => (new AdminPesquisaReacaoIntegracaoController())->index());
    $check(str_contains($htmlSoIntegracao, 'Respostas via QR Code') && str_contains($htmlSoIntegracao, '05/03/2001'), '(12) Usuário só com integracao_colaborador.visualizar vê os resultados QR');
    $check(!str_contains($htmlSoIntegracao, 'id="bloco-reacao"') && !str_contains($htmlSoIntegracao, 'Gerar link') && !str_contains($htmlSoIntegracao, 'Desativar') && !str_contains($htmlSoIntegracao, '/admin/pesquisas-reacao-integracao/' . (int)$campanha['id'] . '/resultados'), '(12) ...mas NÃO recebe o bloco, os dados nem as ações administrativas das campanhas da Reação');
    $check(!Authorization::usuarioTemPermissao($soIntegracaoId, 'pesquisa_reacao_integracao.visualizar') && !Authorization::usuarioTemPermissao($soIntegracaoId, 'pesquisa_reacao_integracao.gerenciar'), '(12) Permissão da Integração não concede nenhuma capability da Reação');
    $check(!Authorization::usuarioTemPermissao($soReacaoId, 'integracao_colaborador.visualizar'), '(11) Permissão da Reação não concede a da Integração');
    $check(!Authorization::usuarioTemPermissao($nadaId, 'pesquisa_reacao_integracao.visualizar') && !Authorization::usuarioTemPermissao($nadaId, 'integracao_colaborador.visualizar'), '(13) Sem nenhuma das duas permissões a central não é acessível (index() cai em requirePermissao -> 403)');

    $corpoIndex = (static function (): string {
        $r = new ReflectionMethod(AdminPesquisaReacaoIntegracaoController::class, 'index');
        $arq = new SplFileObject($r->getFileName());
        $arq->seek($r->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $r->getEndLine()) {
            $corpo .= $arq->current();
            $arq->next();
        }
        return $corpo;
    })();
    $check(str_contains($corpoIndex, "Authorization::requirePermissao('pesquisa_reacao_integracao.visualizar')") && str_contains($corpoIndex, "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(13) index() continua travado por Auth::requireRole + requirePermissao quando não há nenhuma capability');

    // detalhamento
    $comoUsuario($soIntegracaoId, 'rh');
    $htmlDetalhe = $renderizar(static fn() => (new AdminPesquisaIntegracaoResultadosController())->resultados($D1));
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlDetalhe), '(14) Detalhamento renderiza sem Warning/Notice/Fatal');
    $check(str_contains($htmlDetalhe, 'Resultados — Pesquisa de Integração') && str_contains($htmlDetalhe, 'Data da Integração:') && str_contains($htmlDetalhe, '05/03/2001'), '(14) Cabeçalho com "Resultados — Pesquisa de Integração" e a data');
    $check(str_contains($htmlDetalhe, 'Total de Respostas') && str_contains($htmlDetalhe, 'NPS') && str_contains($htmlDetalhe, 'Promotores') && str_contains($htmlDetalhe, 'Neutros') && str_contains($htmlDetalhe, 'Detratores'), '(14) Cards principais');
    $check(str_contains($htmlDetalhe, $rotulosReais[0]) && !str_contains($htmlDetalhe, 'história, propósito e valores'), '(14) Exibe as perguntas reais da Pesquisa de Integração e nenhuma da Pesquisa de Reação');
    $check(str_contains($htmlDetalhe, 'Comentários dos colaboradores') && str_contains($htmlDetalhe, 'Ótima recepção, muito claro.') && str_contains($htmlDetalhe, 'ZZCE Ana Souza') && str_contains($htmlDetalhe, 'ZZCE Operadora') && str_contains($htmlDetalhe, 'ZZCE Empresa Alfa'), '(14) Comentários com Nome · Cargo · Empresa oficiais');
    $check(!str_contains($htmlDetalhe, 'COMENTARIO-INDIVIDUAL') && !str_contains($htmlDetalhe, 'ZZCE Legado') && !str_contains($htmlDetalhe, 'ZZCE Bruno Lima'), '(14) Não lista respondentes sem comentário nem a pesquisa individual — resultado agregado, não listagem nominal');
    $check(!str_contains($htmlDetalhe, $cpfFixture) && !str_contains($htmlDetalhe, '390.533.447-05') && !str_contains($htmlDetalhe, '1975') && !str_contains($htmlDetalhe, '09/04'), '(15) Nenhum CPF nem nascimento é exibido');
    $htmlVazio = $renderizar(static fn() => (new AdminPesquisaIntegracaoResultadosController())->resultados($D3));
    $check(str_contains($htmlVazio, 'Nenhuma resposta recebida até o momento.'), '(7) Integração sem respostas mostra a mensagem, sem números inventados');
    $htmlInvalido = $renderizar(static fn() => (new AdminPesquisaIntegracaoResultadosController())->resultados('2001-13-40'));
    $check(str_contains($htmlInvalido, 'Integração não encontrada'), 'Data inválida na rota -> não encontrada');

    $corpoDet = (static function (): string {
        $r = new ReflectionMethod(AdminPesquisaIntegracaoResultadosController::class, 'resultados');
        $arq = new SplFileObject($r->getFileName());
        $arq->seek($r->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $r->getEndLine()) {
            $corpo .= $arq->current();
            $arq->next();
        }
        return $corpo;
    })();
    $check(str_contains($corpoDet, "Authorization::requirePermissao('integracao_colaborador.visualizar')") && str_contains($corpoDet, "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(12) Detalhamento exige integracao_colaborador.visualizar no backend (quem só tem a da Reação recebe 403)');
    $check(!Authorization::usuarioTemPermissao($soReacaoId, 'integracao_colaborador.visualizar'), '(12) Usuário só com a Reação não acessa o detalhamento QR');

    // ---- 4) Rotas e não-regressão ------------------------------------------------------------
    $fonteIndexPhp = (string)file_get_contents(APP_PATH . '/../index.php');
    $check(str_contains($fonteIndexPhp, "\$router->get('/admin/pesquisas-reacao-integracao/integracao/{data}/resultados', [AdminPesquisaIntegracaoResultadosController::class, 'resultados'])"), '(14) Rota de detalhamento registrada');
    $check(str_contains($fonteIndexPhp, "\$router->get('/admin/pesquisas-reacao-integracao/{id}/resultados', [AdminPesquisaReacaoIntegracaoController::class, 'resultados'])"), '(9) Rota de resultados da campanha da Reação preservada');
    $padraoReacao = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', '/admin/pesquisas-reacao-integracao/{id}/resultados') . '$#';
    $check(!preg_match($padraoReacao, '/admin/pesquisas-reacao-integracao/integracao/' . $D1 . '/resultados'), 'A nova rota não é capturada pela rota {id}/resultados da Reação');
    $check(preg_match($padraoReacao, '/admin/pesquisas-reacao-integracao/' . (int)$campanha['id'] . '/resultados') === 1, 'A rota da Reação segue casando normalmente');

    $comoUsuario($adminId, 'admin');
    $htmlResultadoReacao = $renderizar(static fn() => (new AdminPesquisaReacaoIntegracaoController())->resultados((string)(int)$campanha['id']));
    $check(str_contains($htmlResultadoReacao, 'Resultados da campanha') && str_contains($htmlResultadoReacao, 'Nenhuma resposta recebida até o momento.'), '(9) Resultados da campanha da Reação continuam funcionando');

    $notasPa = (new PeopleAnalyticsRepository())->buscarNotasNpsIntegracao(new DateTimeImmutable('today'), new DateTimeImmutable('today'));
    $check(count($notasPa) >= 5, '(16) People Analytics segue lendo as respostas de pesquisas_integracao (QR + individual) normalmente, sem alteração');

    foreach ([APP_PATH . '/services/PesquisaIntegracaoResultadosService.php', APP_PATH . '/controllers/AdminPesquisaIntegracaoResultadosController.php'] as $arq) {
        $src = (string)file_get_contents($arq);
        $check(!preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $src) && !preg_match('/\bcpf\b|nascimento/i', preg_replace('#//.*$#m', '', preg_replace('#/\*.*?\*/#s', '', $src))), basename($arq) . ' é só leitura e nunca referencia CPF/nascimento no código');
    }
    $colunas = $pdo->query('SHOW COLUMNS FROM pesquisas_integracao')->fetchAll(PDO::FETCH_COLUMN);
    $check(!in_array('origem', $colunas, true), 'Nenhuma coluna de origem foi criada — a origem QR é derivada da estrutura existente');

    echo "\nPESQUISA_INTEGRACAO_CENTRAL_OK\n";
} finally {
    $ids = $criados['metadados'] !== [] ? implode(',', array_map('intval', $criados['metadados'])) : '0';
    $pdo->exec("DELETE FROM pesquisas_integracao WHERE metadados_id IN ($ids)");
    if (!empty($criados['colaboradores'])) {
        $col = implode(',', array_map('intval', $criados['colaboradores']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE colaborador_id IN ($col)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($col)");
    }
    $pdo->exec("DELETE FROM colaboradores_metadados WHERE id IN ($ids)");
    if (!empty($criados['campanhas'])) {
        $camp = implode(',', array_map('intval', $criados['campanhas']));
        $pdo->exec("DELETE FROM respostas_pesquisa_reacao_integracao WHERE campanha_id IN ($camp)");
        $pdo->exec("DELETE FROM campanhas_pesquisa_reacao_integracao WHERE id IN ($camp)");
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
