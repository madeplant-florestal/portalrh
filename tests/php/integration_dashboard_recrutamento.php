<?php

/**
 * Integração — Sprint "Dashboard de Recrutamento e Seleção" (RecrutamentoIndicadoresRepository +
 * RecrutamentoIndicadoresService + AdminDashboardRecrutamentoController + permissão
 * dashboard_recrutamento.visualizar).
 *
 * Prova que:
 *   - a permissão exige o mecanismo central de Authorization (Admin via bypass, usuário via
 *     permissão individual, NUNCA por role RH/Supervisor automaticamente);
 *   - Vagas Abertas/Fechadas usam solicitacoes_vaga.situacao_kanban_id (nunca vagas.ativo);
 *   - Funil só conta "passagem real" via pipeline_movements — um candidato reprovado direto da
 *     Nova Inscrição NÃO aparece como se tivesse passado por Triagem/Entrevista;
 *   - Tempo por etapa só considera passagens CONCLUÍDAS (entrada -> próxima movimentação);
 *     candidato ainda na etapa não contamina a média, mas é contado em "atualmente_na_etapa";
 *   - Tempo de Contratação é inscrição -> 1ª entrada em Admissão, só para quem chegou lá;
 *   - Efetivação/Desligamento na experiência usam a janela oficial de 90 dias (reaproveitada de
 *     RhIndicadoresService, nunca duplicada) e distinguem corretamente desligamento antes/depois
 *     do marco;
 *   - Nota média da Pesquisa de Experiência (3 notas + consolidada calculada, nunca persistida);
 *   - Aceite de proposta e desistência ficam "indisponível" (nunca inventados);
 *   - Filtros de Empresa/Vaga isolam corretamente os dados de outra empresa/vaga;
 *   - amostra zero em qualquer indicador vira `null`, nunca `0` falso;
 *   - nenhuma escrita no METADADOS (SQL Server) — só leitura do espelho `colaboradores_metadados`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

PipelineStage::ensureRecruitmentLifecycle();
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

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$criados = [
    'usuarios' => [], 'vagas' => [], 'candidaturas' => [], 'empresas' => [],
    'setores' => [], 'solicitacoes' => [], 'metadados_identificador' => 'ZZDASH_' . $suffix,
];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

try {
    // ---- 0) Fonte de verdade da permissão (Authorization + backend do controller) -------------
    $permStmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $permStmt->execute(['dashboard_recrutamento.visualizar']);
    $permissaoId = (int)($permStmt->fetchColumn() ?: 0);
    $check($permissaoId > 0, 'Permissão dashboard_recrutamento.visualizar existe no catálogo (migration seed aplicada)');

    $adminId = User::create('ZZDASH Admin', 'admin.zzdash.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $viewerComId = User::create('ZZDASH Viewer Com Permissao', 'viewer.com.zzdash.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($viewerComId, true);
    $criados['usuarios'][] = $viewerComId;
    if ($permissaoId > 0) {
        Authorization::sincronizar($viewerComId, [$permissaoId]);
    }

    $viewerSemId = User::create('ZZDASH Viewer Sem Permissao', 'viewer.sem.zzdash.' . $suffix . '@teste.local', $senha, 'viewer');
    User::setActiveStatus($viewerSemId, true);
    $criados['usuarios'][] = $viewerSemId;

    $rhSemId = User::create('ZZDASH RH Sem Permissao', 'rh.sem.zzdash.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($rhSemId, true);
    $criados['usuarios'][] = $rhSemId;

    $check(Authorization::usuarioTemPermissao($adminId, 'dashboard_recrutamento.visualizar') === true, '(1) Admin acessa via bypass central, sem nenhuma permissão individual concedida');
    $check(Authorization::usuarioTemPermissao($viewerComId, 'dashboard_recrutamento.visualizar') === true, '(2) Usuário com a permissão individual acessa');
    $check(Authorization::usuarioTemPermissao($viewerSemId, 'dashboard_recrutamento.visualizar') === false, '(3) Usuário viewer sem a permissão NÃO acessa');
    $check(Authorization::usuarioTemPermissao($rhSemId, 'dashboard_recrutamento.visualizar') === false, '(3b) RH sem a permissão individual NÃO acessa — role sozinha não basta, diferente do padrão aditivo legado');

    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $reflexao = new ReflectionMethod($classe, $metodo);
        $arquivo = new SplFileObject($reflexao->getFileName());
        $arquivo->seek($reflexao->getStartLine() - 1);
        $corpo = '';
        while ($arquivo->key() < $reflexao->getEndLine()) {
            $corpo .= $arquivo->current();
            $arquivo->next();
        }
        return $corpo;
    };
    $corpoIndex = $corpoDoMetodo(AdminDashboardRecrutamentoController::class, 'index');
    $check(str_contains($corpoIndex, "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(4a) index() exige sessão autenticada de área administrativa');
    $check(str_contains($corpoIndex, "Authorization::requirePermissao('dashboard_recrutamento.visualizar')"), "(4b) index() exige dashboard_recrutamento.visualizar no backend (403 real para quem não tem, não só ocultação de menu)");

    // ---- 1) Fixtures: empresas + setores + vagas -----------------------------------------------
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')
        ->execute(['ZZDASH Empresa A ' . $suffix, 'zzdash-empresa-a-' . $suffix]);
    $empresaAId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaAId;

    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')
        ->execute(['ZZDASH Empresa B ' . $suffix, 'zzdash-empresa-b-' . $suffix]);
    $empresaBId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaBId;

    $pdo->prepare('INSERT INTO setores (nome, slug, empresa_id, ativo) VALUES (?, ?, ?, 1)')
        ->execute(['ZZDASH Setor A ' . $suffix, 'zzdash-setor-a-' . $suffix, $empresaAId]);
    $setorAId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorAId;

    $pdo->prepare('INSERT INTO setores (nome, slug, empresa_id, ativo) VALUES (?, ?, ?, 1)')
        ->execute(['ZZDASH Setor B ' . $suffix, 'zzdash-setor-b-' . $suffix, $empresaBId]);
    $setorBId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorBId;

    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $check($cargoId > 0, 'Fixture: existe ao menos um cargo no catálogo local para a solicitação de vaga de teste');

    $vagaAId = Vaga::create([
        'titulo' => 'ZZDASH Vaga A ' . $suffix,
        'descricao' => 'Fixture do Dashboard de R&S — empresa A.',
        'requisitos' => 'Fixture.',
        'area' => 'RH',
        'local' => 'Remoto',
        'ativo' => 1,
    ]);
    $criados['vagas'][] = $vagaAId;
    $pdo->prepare('UPDATE vagas SET empresa_id = ? WHERE id = ?')->execute([$empresaAId, $vagaAId]);

    $vagaBId = Vaga::create([
        'titulo' => 'ZZDASH Vaga B ' . $suffix,
        'descricao' => 'Fixture do Dashboard de R&S — empresa B (isolamento de filtro).',
        'requisitos' => 'Fixture.',
        'area' => 'RH',
        'local' => 'Remoto',
        'ativo' => 1,
    ]);
    $criados['vagas'][] = $vagaBId;
    $pdo->prepare('UPDATE vagas SET empresa_id = ? WHERE id = ?')->execute([$empresaBId, $vagaBId]);

    // ---- 2) Fixtures: solicitações de vaga (Vagas Abertas/Fechadas) ---------------------------
    $stagesKanban = [];
    foreach ($pdo->query('SELECT id, slug FROM solicitacao_vaga_stages') as $row) {
        $stagesKanban[(string)$row['slug']] = (int)$row['id'];
    }
    foreach (['em-aprovacao', 'aprovada', 'fechada', 'cancelada'] as $slugNecessario) {
        $check(($stagesKanban[$slugNecessario] ?? 0) > 0, "Fixture: etapa '$slugNecessario' existe em solicitacao_vaga_stages");
    }

    $hoje = new DateTimeImmutable('today');
    $mkSolicitacao = static function (int $setorId, string $slugKanban, ?DateTimeImmutable $fechadaEm) use ($pdo, $cargoId, $adminId, &$criados, $stagesKanban): int {
        $stmt = $pdo->prepare(
            'INSERT INTO solicitacoes_vaga (
                setor_id, quantidade_vagas, cargo_id, solicitante_usuario_id, tipo_vaga,
                tipo_contratacao, salario_previsto, centro_custo_id, previsto_orcamento, jornada_trabalho,
                escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade,
                data_prevista_inicio, urgencia, status_fluxo, situacao_kanban_id, fechada_em
            ) VALUES (?, 1, ?, ?, \'nova_posicao\', \'clt\', 3000, NULL, 0, \'8h/dia\',
                \'medio\', \'fixture zzdash\', \'operacional\', CURDATE(), \'media\', \'concluida\', ?, ?)'
        );
        $stmt->execute([
            $setorId, $cargoId, $adminId, $stagesKanban[$slugKanban],
            $fechadaEm !== null ? $fechadaEm->format('Y-m-d H:i:s') : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['solicitacoes'][] = $id;
        return $id;
    };

    $mkSolicitacao($setorAId, 'em-aprovacao', null);         // SV1: aberta, empresa A
    $mkSolicitacao($setorAId, 'aprovada', null);              // SV2: aberta, empresa A
    $mkSolicitacao($setorAId, 'fechada', $hoje->modify('-5 days'));   // SV3: fechada dentro do período, empresa A
    $mkSolicitacao($setorAId, 'cancelada', null);              // SV4: cancelada — não é aberta nem fechada
    $mkSolicitacao($setorBId, 'fechada', $hoje->modify('-3 days'));   // SV5: fechada dentro do período, empresa B
    $mkSolicitacao($setorAId, 'fechada', $hoje->modify('-500 days')); // SV6: fechada MUITO antes do período — deve ficar de fora

    $periodoInicio = $hoje->modify('-400 days');
    $periodoFim = $hoje;

    $repo = new RecrutamentoIndicadoresRepository();

    $abertasSemFiltro = $repo->contarSolicitacoesAbertas(null);
    $check((int)$abertasSemFiltro['total'] >= 2, '(5) Vagas Abertas conta situações fora de fechada/cancelada (>= 2 fixtures abertas)');
    $abertasEmpresaA = $repo->contarSolicitacoesAbertas($empresaAId);
    $check((int)$abertasEmpresaA['total'] === 2, '(5b) Vagas Abertas filtradas por Empresa A conta exatamente as 2 fixtures (SV1 em-aprovação + SV2 aprovada), excluindo SV4 cancelada');

    $fechadasNoPeriodo = $repo->contarSolicitacoesFechadas($periodoInicio, $periodoFim, null);
    $check((int)$fechadasNoPeriodo['total'] === 2, '(6) Vagas Fechadas no período conta SV3+SV5 (dentro da janela), exclui SV6 (fechada há 500 dias) e SV4 (cancelada)');
    $fechadasEmpresaA = $repo->contarSolicitacoesFechadas($periodoInicio, $periodoFim, $empresaAId);
    $check((int)$fechadasEmpresaA['total'] === 1, '(6b) Vagas Fechadas filtradas por Empresa A conta só SV3, isolando corretamente a Empresa B (SV5)');

    // ---- 3) Fixtures: candidaturas + pipeline_movements (Funil / Tempo por Etapa / Contratação) -
    $stagesBySlug = [];
    foreach (PipelineStage::all() as $stage) {
        $stagesBySlug[(string)$stage['slug']] = (int)$stage['id'];
    }
    foreach (['nova-inscricao', 'triagem-rh', 'entrevista-rh', 'entrevista-gestor', 'admissao', 'reprovado'] as $slugNecessario) {
        $check(($stagesBySlug[$slugNecessario] ?? 0) > 0, "Fixture: etapa '$slugNecessario' existe em pipeline_stages");
    }

    $seq = 0;
    $mkCandidatura = static function (int $vagaId, DateTimeImmutable $criadaEm, int $stageAtualId) use ($pdo, &$criados, $suffix, &$seq): int {
        $seq++;
        $id = Candidatura::create([
            'vaga_id' => $vagaId,
            'nome' => 'ZZDASH Candidato ' . $suffix . '-' . $seq,
            'email' => 'candidato.zzdash.' . $suffix . '.' . $seq . '@rhmadeplant.local',
            'telefone' => '67999999999',
            'cpf' => str_pad((string)random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'cargo_pretendido' => 'Analista de RH',
            'experiencia' => 'Fixture de teste do Dashboard de R&S.',
            'pdf_path' => 'zzdash-teste.pdf',
            'status' => 'novo',
            'indicacao_colaborador' => 0,
        ]);
        $criados['candidaturas'][] = $id;
        $pdo->prepare('UPDATE candidaturas SET created_at = ?, stage_id = ? WHERE id = ?')
            ->execute([$criadaEm->format('Y-m-d H:i:s'), $stageAtualId, $id]);
        return $id;
    };
    $mkMovimento = static function (int $candidaturaId, int $stageNovoId, DateTimeImmutable $em) use ($pdo): void {
        $pdo->prepare('INSERT INTO pipeline_movements (candidatura_id, stage_anterior_id, stage_novo_id, usuario_id, created_at) VALUES (?, NULL, ?, NULL, ?)')
            ->execute([$candidaturaId, $stageNovoId, $em->format('Y-m-d H:i:s')]);
    };

    // Candidato A: jornada completa e concluída até Admissão.
    $criadaA = $hoje->modify('-14 days');
    $candA = $mkCandidatura($vagaAId, $criadaA, $stagesBySlug['admissao']);
    $mkMovimento($candA, $stagesBySlug['triagem-rh'], $criadaA->modify('+2 days'));
    $mkMovimento($candA, $stagesBySlug['entrevista-rh'], $criadaA->modify('+5 days'));   // Triagem RH concluída: 3 dias
    $mkMovimento($candA, $stagesBySlug['entrevista-gestor'], $criadaA->modify('+9 days')); // Entrevista RH concluída: 4 dias
    $mkMovimento($candA, $stagesBySlug['admissao'], $criadaA->modify('+14 days'));         // Entrevista Gestor concluída: 5 dias — Admissão é a última (não concluída)

    // Candidato B: preso em Triagem RH, nunca saiu (não deve contaminar média concluída).
    $criadaB = $hoje->modify('-13 days');
    $candB = $mkCandidatura($vagaAId, $criadaB, $stagesBySlug['triagem-rh']);
    $mkMovimento($candB, $stagesBySlug['triagem-rh'], $criadaB->modify('+3 days'));

    // Candidato C: segunda passagem concluída de Triagem RH (duração diferente de A, para testar
    // a média), depois preso em Entrevista RH.
    $criadaC = $hoje->modify('-12 days');
    $candC = $mkCandidatura($vagaAId, $criadaC, $stagesBySlug['entrevista-rh']);
    $mkMovimento($candC, $stagesBySlug['triagem-rh'], $criadaC->modify('+3 days'));
    $mkMovimento($candC, $stagesBySlug['entrevista-rh'], $criadaC->modify('+5 days')); // Triagem RH concluída: 2 dias

    // Candidato D: reprovado direto da Nova Inscrição — não deve contar como se tivesse passado
    // por Triagem RH/Entrevista RH/etc.
    $criadaD = $hoje->modify('-11 days');
    $candD = $mkCandidatura($vagaAId, $criadaD, $stagesBySlug['reprovado']);
    $mkMovimento($candD, $stagesBySlug['reprovado'], $criadaD->modify('+2 days'));

    // Candidato E: fora do período (deve ser 100% excluído da coorte).
    $criadaE = $hoje->modify('-450 days');
    $mkCandidatura($vagaAId, $criadaE, $stagesBySlug['nova-inscricao']);

    // Candidato F: dentro do período, mas de outra vaga/empresa (isolamento de filtro).
    $criadaF = $hoje->modify('-10 days');
    $mkCandidatura($vagaBId, $criadaF, $stagesBySlug['nova-inscricao']);

    $service = new RecrutamentoIndicadoresService();

    // Isolamento pelo filtro de Vaga (nunca "sem filtro"): o ambiente de DEV acumula candidaturas
    // de outras suítes de teste dentro da mesma janela de 400 dias — contá-las junto quebraria
    // qualquer expectativa exata. vaga_id = vagaAId isola precisamente A, B, C, D (E fica fora
    // pela data; F fica fora por ser de outra vaga).
    $painelVagaA = $service->montarPainel(['empresa_id' => null, 'vaga_id' => $vagaAId], $periodoInicio, $periodoFim);
    $check($painelVagaA['total_candidaturas_cohort'] === 4, '(19b) Filtro por Vaga A isola a coorte para 4 candidatos (A,B,C,D) — exclui E (fora do período) e F (outra vaga)');

    $funil = array_column($painelVagaA['funil'], 'quantidade', 'label');
    $check(($funil['Candidatos Inscritos'] ?? -1) === 4, '(8a) Funil "Candidatos Inscritos" = 4 (A,B,C,D)');
    $check(($funil['Triagem RH'] ?? -1) === 3, '(8b) Funil "Triagem RH" = 3 (A,B,C tiveram passagem real) — D pulou direto para Reprovado');
    $check(($funil['Entrevista RH'] ?? -1) === 2, '(8c) Funil "Entrevista RH" = 2 (A,C) — B nunca saiu de Triagem, D pulou direto para Reprovado');
    $check(($funil['Entrevista Gestor'] ?? -1) === 1, '(8d) Funil "Entrevista Gestor" = 1 (só A)');
    $check(($funil['Contratados'] ?? -1) === 1, '(8e) Funil "Contratados" (Admissão) = 1 (só A chegou lá)');

    $tempoEtapa = $painelVagaA['tempo_por_etapa'];
    $check($tempoEtapa['triagem-rh']['media_dias'] === 2.5, '(9a) Tempo médio concluído em Triagem RH = 2.5 dias — média de A (3d) e C (2d)');
    $check($tempoEtapa['triagem-rh']['amostra_concluida'] === 2, '(9b) Amostra concluída de Triagem RH = 2 (A e C) — B (ainda preso) não entra');
    $check($tempoEtapa['triagem-rh']['atualmente_na_etapa'] === 1, '(10a) "Atualmente na etapa" Triagem RH = 1 (B) — o gargalo em andamento não desaparece da leitura');
    $check($tempoEtapa['entrevista-rh']['media_dias'] === 4.0, '(9c) Tempo médio concluído em Entrevista RH = 4.0 dias (só A concluiu; C ainda está preso lá)');
    $check($tempoEtapa['entrevista-rh']['amostra_concluida'] === 1, '(9d) Amostra concluída de Entrevista RH = 1 (A) — C não entra por ainda estar na etapa');
    $check($tempoEtapa['entrevista-rh']['atualmente_na_etapa'] === 1, '(10b) "Atualmente na etapa" Entrevista RH = 1 (C)');
    $check($tempoEtapa['entrevista-gestor']['media_dias'] === 5.0, '(9e) Tempo médio concluído em Entrevista Gestor = 5.0 dias (A)');
    $check($tempoEtapa['admissao']['media_dias'] === null, '(9f) Tempo médio concluído em Admissão = null — A é o único e ainda está lá (nenhuma passagem concluída, nunca 0 falso)');
    $check($tempoEtapa['admissao']['atualmente_na_etapa'] === 1, '(10c) "Atualmente na etapa" Admissão = 1 (A)');

    $check($painelVagaA['tempo_contratacao']['media_dias'] === 14.0, '(7) Tempo Médio de Contratação = 14.0 dias (A: inscrição -> 1ª entrada em Admissão)');
    $check($painelVagaA['tempo_contratacao']['amostra'] === 1, '(7b) Amostra do Tempo de Contratação = 1 — só quem chegou a Admissão entra, nunca todo mundo');

    // Filtro por Empresa B: só F.
    $painelEmpresaB = $service->montarPainel(['empresa_id' => $empresaBId, 'vaga_id' => null], $periodoInicio, $periodoFim);
    $check($painelEmpresaB['total_candidaturas_cohort'] === 1, '(19c) Filtro por Empresa B isola a coorte para 1 candidato (F)');

    // ---- 4) Aceite de proposta / desistência — sempre indisponível, nunca inventado ------------
    $check($painelVagaA['proposta_aceite']['disponivel'] === false, '(17) Taxa de aceite de proposta marcada como indisponível (candidatura_propostas sem uso real na aplicação)');
    $check($painelVagaA['desistencia']['disponivel'] === false, '(18) Taxa de desistência marcada como indisponível (sem motivo estruturado hoje)');

    // ---- 5) Efetivação / Desligamento na experiência / Turnover 90 dias (METADADOS) ------------
    // Sem filtro de Empresa/Vaga (decisão desta sprint — ver comentário em
    // RecrutamentoIndicadoresService::montarEfetivacaoEDesligamento). Por isso, ao contrário do
    // funil acima, aqui NÃO dá para isolar por vaga/empresa — o ambiente de DEV pode ter outros
    // contratos no espelho. Validação por DELTA (antes/depois de inserir as fixtures G,H,I,J),
    // robusta a qualquer dado pré-existente.
    $painelQualidadeAntes = $service->montarPainel(['empresa_id' => null, 'vaga_id' => null], $periodoInicio, $periodoFim);
    $baseElegiveis = $painelQualidadeAntes['qualidade']['efetivacao']['efetivacao']['elegiveis'];
    $baseEfetivados = $painelQualidadeAntes['qualidade']['efetivacao']['efetivacao']['efetivados'];
    $baseDesligadosExp = $painelQualidadeAntes['qualidade']['efetivacao']['desligamento_experiencia']['desligados'];
    $baseTotalDesligamentos = $painelQualidadeAntes['qualidade']['efetivacao']['turnover_precoce']['total_desligamentos'];
    $basePrecoces = $painelQualidadeAntes['qualidade']['efetivacao']['turnover_precoce']['precoces'];

    $marcaZz = $criados['metadados_identificador'];
    $mkMetadados = static function (string $codigoPessoa, DateTimeImmutable $admissao, ?DateTimeImmutable $demissao) use ($pdo, $marcaZz): void {
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                nome, admissao, demissao, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $marcaZz . '_' . $codigoPessoa, 'ZZ01', 'ZZUNI', 'ZZCTR_' . $codigoPessoa, $codigoPessoa,
            'ZZDASH Fixture ' . $codigoPessoa, $admissao->format('Y-m-d'),
            $demissao !== null ? $demissao->format('Y-m-d') : null,
            $demissao === null ? 1 : 0, 'zzdash-teste',
        ]);
    };

    $mkMetadados('G', $hoje->modify('-200 days'), null);                              // efetivado, ainda ativo
    $mkMetadados('H', $hoje->modify('-150 days'), $hoje->modify('-40 days'));          // desligado APÓS os 90 dias -> efetivado
    $mkMetadados('I', $hoje->modify('-120 days'), $hoje->modify('-100 days'));         // desligado DENTRO dos 90 dias -> desligamento na experiência
    $mkMetadados('J', $hoje->modify('-30 days'), null);                                // ainda não elegível (< 90 dias de casa) -> fora do denominador

    $painelQualidade = $service->montarPainel(['empresa_id' => null, 'vaga_id' => null], $periodoInicio, $periodoFim);
    $efetivacao = $painelQualidade['qualidade']['efetivacao']['efetivacao'];
    $desligamentoExp = $painelQualidade['qualidade']['efetivacao']['desligamento_experiencia'];
    $turnover = $painelQualidade['qualidade']['efetivacao']['turnover_precoce'];

    $check($efetivacao['elegiveis'] - $baseElegiveis === 3, '(11a) Elegíveis para Efetivação aumenta em exatamente 3 (G,H,I — J ainda não completou 90 dias)');
    $check($efetivacao['efetivados'] - $baseEfetivados === 2, '(11b) Efetivados aumenta em exatamente 2 (G ativo + H desligado depois dos 90 dias)');
    $check($desligamentoExp['desligados'] - $baseDesligadosExp === 1, '(13) Desligamento na experiência aumenta em exatamente 1 (I, desligado 20 dias após a admissão, dentro dos 90)');
    $percentualEfetivacaoEsperado = $efetivacao['elegiveis'] > 0 ? round(($efetivacao['efetivados'] / $efetivacao['elegiveis']) * 100, 1) : null;
    $check($efetivacao['percentual'] === $percentualEfetivacaoEsperado, '(11c) Percentual de Efetivação bate exatamente com efetivados/elegíveis (fórmula correta, robusto a outros contratos já existentes no ambiente)');
    $percentualDesligExpEsperado = $desligamentoExp['elegiveis'] > 0 ? round(($desligamentoExp['desligados'] / $desligamentoExp['elegiveis']) * 100, 1) : null;
    $check($desligamentoExp['percentual'] === $percentualDesligExpEsperado, '(13b) Percentual de desligamento na experiência bate exatamente com desligados/elegíveis');
    $check((int)$turnover['total_desligamentos'] - $baseTotalDesligamentos === 2, '(12/13-reuso) Turnover até 90 dias reaproveita RhIndicadoresService::turnoverPrecoce() — total de desligamentos no período aumenta em 2 (H e I)');
    $check((int)$turnover['precoces'] - $basePrecoces === 1, '(12) Só I conta como desligamento precoce (H foi desligado depois dos 90 dias — conta como efetivado, não como turnover precoce)');
    $check(RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS === 90, 'Constante oficial de 90 dias reaproveitada — não duplicada em RecrutamentoIndicadoresService (grep abaixo confirma ausência de número mágico)');
    $check(
        !str_contains((string)file_get_contents(APP_PATH . '/services/RecrutamentoIndicadoresService.php'), '= 90;')
        && !str_contains((string)file_get_contents(APP_PATH . '/services/RecrutamentoIndicadoresService.php'), "'90'"),
        'RecrutamentoIndicadoresService não redeclara o número mágico 90 — usa RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS'
    );

    // Período estreito sem nenhum contrato elegível: percentuais devem virar null (dados
    // insuficientes), nunca 0 falso.
    $painelVazio = $service->montarPainel(['empresa_id' => null, 'vaga_id' => null], $hoje->modify('-3 days'), $hoje);
    $check($painelVazio['qualidade']['efetivacao']['efetivacao']['percentual'] === null, '(16-correlato) Sem elegíveis no período, percentual de Efetivação é null, não 0');
    $check($painelVazio['tempo_contratacao']['media_dias'] === null, 'Sem candidatos no período, Tempo de Contratação é null, não 0');
    $check($painelVazio['qualidade']['pesquisa_experiencia']['respostas'] === 0 && $painelVazio['qualidade']['pesquisa_experiencia']['consolidada'] === null, '(16) Sem pesquisas respondidas no período, nota consolidada é null ("Dados insuficientes"), nunca 0');

    // ---- 6) Pesquisa de Experiência — 3 notas + consolidada calculada, nunca persistida --------
    $pdo->prepare('INSERT INTO pesquisas_experiencia (candidatura_id, token_hash, nota_clareza, nota_tempo_retorno, nota_atendimento, respondida_em) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$candA, hash('sha256', 'zzdash-token-a-' . $suffix), 5, 3, 4, $hoje->modify('-2 days')->format('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO pesquisas_experiencia (candidatura_id, token_hash, nota_clareza, nota_tempo_retorno, nota_atendimento, respondida_em) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$candB, hash('sha256', 'zzdash-token-b-' . $suffix), 3, 3, 3, $hoje->modify('-3 days')->format('Y-m-d H:i:s')]);
    // Pesquisa ainda não respondida — deve ficar FORA da agregação.
    $pdo->prepare('INSERT INTO pesquisas_experiencia (candidatura_id, token_hash, respondida_em) VALUES (?, ?, NULL)')
        ->execute([$candC, hash('sha256', 'zzdash-token-c-' . $suffix)]);

    $painelPesquisa = $service->montarPainel(['empresa_id' => null, 'vaga_id' => $vagaAId], $periodoInicio, $periodoFim);
    $pesquisa = $painelPesquisa['qualidade']['pesquisa_experiencia'];
    $check($pesquisa['respostas'] === 2, '(14a) Só as 2 pesquisas RESPONDIDAS entram na agregação — a pendente (candidato C) fica de fora');
    $check($pesquisa['clareza'] === 4.0, '(14b) Média de Clareza = 4.0 ((5+3)/2)');
    $check($pesquisa['tempo_retorno'] === 3.0, '(14c) Média de Tempo de Retorno = 3.0 ((3+3)/2)');
    $check($pesquisa['atendimento'] === 3.5, '(14d) Média de Atendimento = 3.5 ((4+3)/2)');
    $check($pesquisa['consolidada'] === 3.5, '(15) Nota consolidada = 3.5 — média das consolidadas por resposta ((5+3+4)/3=4.0 e (3+3+3)/3=3.0, média 3.5), calculada em memória');
    $check(
        !preg_match('/ALTER TABLE\s+pesquisas_experiencia|ADD COLUMN.*nota_geral/i', (string)file_get_contents(APP_PATH . '/services/RecrutamentoIndicadoresService.php')),
        'Nenhuma coluna nova de "nota geral" foi criada — a consolidada é sempre calculada, nunca persistida'
    );

    // ---- 7) Nenhuma escrita no METADADOS ---------------------------------------------------------
    $fontesNovas = [
        APP_PATH . '/repositories/RecrutamentoIndicadoresRepository.php',
        APP_PATH . '/services/RecrutamentoIndicadoresService.php',
        APP_PATH . '/controllers/AdminDashboardRecrutamentoController.php',
    ];
    foreach ($fontesNovas as $arquivo) {
        $conteudo = (string)file_get_contents($arquivo);
        $check(
            !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\s+colaboradores_metadados\b/i', $conteudo),
            basename($arquivo) . ' não contém nenhuma escrita em colaboradores_metadados (só leitura via RhIndicadoresRepository::buscarContratos)'
        );
        // Regex mira uso REAL de conexão SQL Server (DSN/extensão), não a palavra em comentários —
        // os próprios docblocks destes arquivos explicam em português que NUNCA tocam o SQL Server.
        $check(!preg_match('/\bsqlsrv:|pdo_sqlsrv|new\s+PDO\s*\(\s*[\'"]sqlsrv/i', $conteudo), basename($arquivo) . ' não abre nenhuma conexão SQL Server/pdo_sqlsrv — só o espelho MySQL local');
    }

    // ---- 8) Assets — cache-busting preservado (nenhum CSS/JS novo criado nesta sprint) ---------
    $check(
        !str_contains((string)file_get_contents(APP_PATH . '/views/admin/dashboard-recrutamento.php'), "Config::app()['version']"),
        'A view do novo Dashboard não usa Config::app()[\'version\'] para nenhum asset — nenhum asset novo foi introduzido nesta sprint'
    );

    echo "\nDASHBOARD_RECRUTAMENTO_OK\n";
} finally {
    // ---- limpeza (ordem respeita as FKs: filhos antes dos pais) --------------------------------
    if (!empty($criados['candidaturas'])) {
        $pdo->prepare('DELETE FROM pesquisas_experiencia WHERE candidatura_id IN (' . implode(',', array_map('intval', $criados['candidaturas'])) . ')')->execute();
        $pdo->prepare('DELETE FROM candidaturas WHERE id IN (' . implode(',', array_map('intval', $criados['candidaturas'])) . ')')->execute();
    }
    if (!empty($criados['vagas'])) {
        $pdo->prepare('DELETE FROM vagas WHERE id IN (' . implode(',', array_map('intval', $criados['vagas'])) . ')')->execute();
    }
    if (!empty($criados['solicitacoes'])) {
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id IN (' . implode(',', array_map('intval', $criados['solicitacoes'])) . ')')->execute();
    }
    if (!empty($criados['setores'])) {
        $pdo->prepare('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $criados['setores'])) . ')')->execute();
    }
    if (!empty($criados['empresas'])) {
        $pdo->prepare('DELETE FROM empresas WHERE id IN (' . implode(',', array_map('intval', $criados['empresas'])) . ')')->execute();
    }
    $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador LIKE ?")->execute([$criados['metadados_identificador'] . '_%']);
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
