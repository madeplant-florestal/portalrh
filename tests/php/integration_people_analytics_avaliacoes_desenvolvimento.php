<?php

/**
 * Integração — People Analytics / "Avaliações e Desenvolvimento" + "Integração/Onboarding"
 * (Etapa 9, 2026-09: PeopleAnalyticsAvaliacoesRepository + PeopleAnalyticsService::
 * montarAvaliacoesDesenvolvimentoExecutivo()/montarIntegracaoExecutivo()).
 *
 * Prova que:
 *   - Avaliação de Experiência: realizadas por tipo (45/90) ancoradas em data_realizacao dentro do
 *     período; pendentes (rascunho, prazo futuro) e vencidas (rascunho, prazo já passado) são
 *     retrato de AGORA; aguardando_ciencia exclui quem já tem ciência do colaborador registrada;
 *     distribuição de pareceres só conta concluídas no período;
 *   - Avaliação de Desempenho: médias de nota_atual/nota_esperada/GAP vêm só de critérios
 *     preenchidos, nunca de avaliação sem critério; distribuição de resultado_final;
 *   - Feedback: contagem por tipo, "desenvolvimento necessário" (via resultado_geral OU via
 *     feedback_valores.avaliacao), valores culturais mais incidentes;
 *   - PDI: backlog ativo (nao_iniciado/em_andamento, NUNCA rascunho — mesma convenção de
 *     PdiService::STATUS_COM_PRAZO), concluídos no período, vínculos de origem por pdi_origens
 *     (PDI com múltiplas origens conta em cada categoria — nunca deduplicado) + manuais
 *     (origem_tipo=desenvolvimento_carreira, sem linha em pdi_origens);
 *   - Cobertura de Desenvolvimento: documento ELEGÍVEL (sinal real de necessidade, mesmos critérios
 *     de *Service::candidatosParaPdi()) x VINCULADO (EXISTS em pdi_origens) — nunca uma taxa sobre
 *     a população toda;
 *   - Filtro Empresa/Setor isola corretamente (inclusive PDI, que não tem snap_codigo_setor e
 *     precisa do JOIN com colaboradores_metadados);
 *   - Sem dados no período: médias/GAP ficam null (nunca 0 falso);
 *   - PeopleAnalyticsService::montarPainel()['avaliacoes_desenvolvimento'] bate exatamente com as
 *     mesmas chamadas diretas ao repositório (prova de wiring, sem duplicar lógica);
 *   - PeopleAnalyticsService::montarPainel()['integracao_onboarding']['nps'] reaproveita
 *     INTEGRALMENTE DashboardIntegracaoService::montarPainel() (mesmo valor, nunca uma fórmula
 *     paralela) e expõe a diferença do comparativo em PONTOS (nunca percentual — §27 da Etapa 9).
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
$criados = [
    'metadados_ids' => [], 'usuarios' => [], 'avaliacoes_experiencia' => [], 'avaliacoes_desempenho' => [],
    'feedbacks' => [], 'pdis' => [],
];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$empA = 'ZZAD' . $suffix . 'A';
$setA = 'ZZAD' . $suffix . 'SA';
$empB = 'ZZAD' . $suffix . 'B';
$setB = 'ZZAD' . $suffix . 'SB';

try {
    $gestorId = User::create('ZZAD Gestor', 'gestor.zzad.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($gestorId, true);
    $criados['usuarios'][] = $gestorId;

    $mkMetadados = static function (string $codigoEmpresa, string $codigoSetor, string $marcador) use ($pdo, &$criados, $suffix): int {
        $identificador = 'ZZAD_' . $suffix . '_' . $marcador;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $identificador, $codigoEmpresa, 'ZZADU', 'ZC' . $suffix . $marcador, 'ZP' . $suffix . $marcador, null,
            'ZZAD Fixture ' . $marcador, 'ZZAD Empresa', 'ZZAD Unidade', $codigoSetor, 'ZZAD Setor', 'ZZAD Cargo', 'CG1',
            '2024-01-10', '2024-01-10', 'zzad-teste',
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['metadados_ids'][] = $id;
        return $id;
    };

    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-30 days');
    $fim = $hoje;
    $agoraSql = $hoje->format('Y-m-d H:i:s');

    // ================================================================= Avaliação de Experiência

    $mkAvExp = static function (
        int $metadadosId,
        string $tipo,
        string $status,
        ?DateTimeImmutable $dataPrevista,
        ?DateTimeImmutable $dataRealizacao,
        ?string $parecer,
        string $codigoEmpresa,
        string $codigoSetor
    ) use ($pdo, $gestorId, &$criados, $agoraSql): int {
        $justificativa = ($parecer !== null && $parecer !== 'apto_efetivacao') ? 'Justificativa fixture.' : null;
        $dataPrevistaEfetiva = $dataPrevista ?? $dataRealizacao ?? new DateTimeImmutable('today');
        $pdo->prepare(
            'INSERT INTO avaliacoes_experiencia (
                metadados_id, tipo, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                snap_admissao, gestor_usuario_id, gestor_nome_snapshot, status, data_prevista, data_realizacao,
                parecer, parecer_justificativa, criado_por_usuario_id, criado_em, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $metadadosId, $tipo, 'ZZAD Nome', $codigoEmpresa, 'ZZADU', $codigoSetor,
            '2024-01-10', $gestorId, 'ZZAD Gestor', $status,
            $dataPrevistaEfetiva->format('Y-m-d'), $dataRealizacao?->format('Y-m-d'),
            $parecer, $justificativa, $gestorId, $agoraSql, $agoraSql,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['avaliacoes_experiencia'][] = $id;
        return $id;
    };

    $mkCiencia = static function (string $documentoTipo, int $documentoId, string $papel) use ($pdo, $agoraSql): void {
        $pdo->prepare(
            'INSERT INTO avaliacoes_desenvolvimento_ciencia (documento_tipo, documento_id, papel, usuario_id, nome_snapshot, registrado_em)
             VALUES (?, ?, ?, NULL, ?, ?)'
        )->execute([$documentoTipo, $documentoId, $papel, 'ZZAD Ciencia', $agoraSql]);
    };

    $cAe1 = $mkMetadados($empA, $setA, 'ae1'); // 45, concluído, apto (não elegível p/ cobertura)
    $cAe2 = $mkMetadados($empA, $setA, 'ae2'); // 90, concluído, efetivacao_acompanhamento (elegível, vinculado)
    $cAe3 = $mkMetadados($empA, $setA, 'ae3'); // 45, concluído, nao_recomendado (elegível, NÃO vinculado)
    $cAe4 = $mkMetadados($empA, $setA, 'ae4'); // rascunho, prazo futuro -> pendente
    $cAe5 = $mkMetadados($empA, $setA, 'ae5'); // rascunho, prazo passado -> vencida
    $cAe6 = $mkMetadados($empA, $setA, 'ae6'); // concluído, SEM ciência do colaborador -> aguardando_ciencia
    $cAe7 = $mkMetadados($empA, $setA, 'ae7'); // concluído, COM ciência do colaborador -> não aparece em aguardando_ciencia
    $cAeB = $mkMetadados($empB, $setB, 'aeB'); // empresa B — isolamento de filtro

    $aeAguardando = $mkAvExp($cAe6, '45', 'concluido', null, $hoje->modify('-3 days'), 'apto_efetivacao', $empA, $setA);
    $aeComCiencia = $mkAvExp($cAe7, '45', 'concluido', null, $hoje->modify('-2 days'), 'apto_efetivacao', $empA, $setA);
    $mkCiencia('avaliacao_experiencia', $aeComCiencia, 'colaborador');

    $aeElegivelVinculada = $mkAvExp($cAe2, '90', 'concluido', null, $hoje->modify('-4 days'), 'efetivacao_acompanhamento', $empA, $setA);
    $aeElegivelSemVinculo = $mkAvExp($cAe3, '45', 'concluido', null, $hoje->modify('-6 days'), 'nao_recomendado', $empA, $setA);
    $mkAvExp($cAe1, '45', 'concluido', null, $hoje->modify('-5 days'), 'apto_efetivacao', $empA, $setA);
    $mkAvExp($cAe4, '90', 'rascunho', $hoje->modify('+20 days'), null, null, $empA, $setA);
    $mkAvExp($cAe5, '90', 'rascunho', $hoje->modify('-10 days'), null, null, $empA, $setA);
    $mkAvExp($cAeB, '45', 'concluido', null, $hoje->modify('-1 days'), 'apto_efetivacao', $empB, $setB);

    $avaliacoesRepo = new PeopleAnalyticsAvaliacoesRepository();

    // Concluídas em empA/setA: ae1/ae3/ae6/ae7 (tipo "45") + ae2 (tipo "90") = 5 no total.
    $porTipo = $avaliacoesRepo->experienciaRealizadasPorTipo($inicio, $fim, $empA, $setA);
    $check($porTipo['45'] === 4 && $porTipo['90'] === 1, '(1) Experiência realizadas por tipo: 4x "45" (ae1/ae3/ae6/ae7) + 1x "90" (ae2) dentro do período, isoladas por Empresa+Setor');

    $situacao = $avaliacoesRepo->experienciaSituacaoAtual($hoje, $empA, $setA);
    $check($situacao['pendentes'] === 1, '(2) Experiência pendentes = 1 (ae4, prazo futuro) — retrato de agora');
    $check($situacao['vencidas'] === 1, '(2) Experiência vencidas = 1 (ae5, prazo passado, ainda rascunho)');
    // Concluídas sem ciência do colaborador: ae1, ae2, ae3, ae6 = 4 (só ae7 tem ciência registrada).
    $check($situacao['aguardando_ciencia'] === 4, '(3) Experiência aguardando_ciencia = 4 (ae1/ae2/ae3/ae6 concluídas sem ciência do colaborador; só ae7 tem ciência registrada e fica de fora)');

    $pareceres = $avaliacoesRepo->experienciaPareceres($inicio, $fim, $empA, $setA);
    $check(($pareceres['apto_efetivacao'] ?? 0) === 3, '(4) Pareceres: 3x apto_efetivacao (ae1, ae6, ae7) no período');
    $check(($pareceres['efetivacao_acompanhamento'] ?? 0) === 1, '(4) Pareceres: 1x efetivacao_acompanhamento (ae2)');
    $check(($pareceres['nao_recomendado'] ?? 0) === 1, '(4) Pareceres: 1x nao_recomendado (ae3)');
    $check(!array_key_exists('prorrogacao_experiencia', $pareceres), '(4) Parecer sem nenhuma ocorrência no período não aparece com 0 inventado — só os pareceres REALMENTE emitidos entram no mapa');

    $porTipoB = $avaliacoesRepo->experienciaRealizadasPorTipo($inicio, $fim, $empB, $setB);
    $check($porTipoB['45'] === 1 && $porTipoB['90'] === 0, '(5) Isolamento de Empresa: Empresa B só vê sua própria avaliação (aeB), nunca contamina com as da Empresa A');

    // ================================================================= Avaliação de Desempenho

    $mkAvDes = static function (
        int $metadadosId,
        string $status,
        ?DateTimeImmutable $dataRealizacao,
        ?string $resultadoFinal,
        ?string $gaps,
        string $codigoEmpresa,
        string $codigoSetor
    ) use ($pdo, $gestorId, &$criados, $agoraSql): int {
        $pdo->prepare(
            'INSERT INTO avaliacoes_desempenho (
                metadados_id, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor, snap_admissao,
                gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim, status, data_realizacao,
                gaps_identificados, plano_acao_sugerido, resultado_final, criado_por_usuario_id, criado_em, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
        )->execute([
            $metadadosId, 'ZZAD Nome', $codigoEmpresa, 'ZZADU', $codigoSetor, '2024-01-10',
            $gestorId, 'ZZAD Gestor', 'Ciclo Fixture', '2026-01-01', '2026-12-31', $status,
            $dataRealizacao?->format('Y-m-d'), $gaps, $resultadoFinal, $gestorId, $agoraSql, $agoraSql,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['avaliacoes_desempenho'][] = $id;
        return $id;
    };

    $mkCriterioDesempenho = static function (int $avaliacaoId, int $notaAtual, int $notaEsperada) use ($pdo): void {
        $pdo->prepare(
            'INSERT INTO avaliacoes_desempenho_criterios (avaliacao_id, competencia_texto, nota_atual, nota_esperada)
             VALUES (?, ?, ?, ?)'
        )->execute([$avaliacaoId, 'ZZAD Competência Fixture', $notaAtual, $notaEsperada]);
    };

    $cDes1 = $mkMetadados($empA, $setA, 'des1'); // concluído, GAP real (elegível, vinculado)
    $cDes2 = $mkMetadados($empA, $setA, 'des2'); // concluído, SEM gap (não elegível)
    $cDes3 = $mkMetadados($empA, $setA, 'des3'); // rascunho

    $desElegivelVinculada = $mkAvDes($cDes1, 'concluido', $hoje->modify('-5 days'), 'atende_parcialmente', null, $empA, $setA);
    $mkCriterioDesempenho($desElegivelVinculada, 2, 4); // GAP = 2

    $desNaoElegivel = $mkAvDes($cDes2, 'concluido', $hoje->modify('-6 days'), 'supera_expectativas', null, $empA, $setA);
    $mkCriterioDesempenho($desNaoElegivel, 5, 5); // GAP = 0 -> não elegível

    $mkAvDes($cDes3, 'rascunho', null, null, null, $empA, $setA);

    $resumoDesempenho = $avaliacoesRepo->desempenhoResumo($inicio, $fim, $empA, $setA);
    $check($resumoDesempenho['concluidas'] === 2, '(6) Desempenho concluídas no período = 2 (des1 + des2)');
    $check($resumoDesempenho['rascunho_atual'] === 1, '(6) Desempenho rascunho_atual = 1 (des3) — retrato de agora');
    $check($resumoDesempenho['media_nota_atual'] === 3.5, '(7) Média nota_atual = (2+5)/2 = 3.5, só de critérios preenchidos');
    $check($resumoDesempenho['media_nota_esperada'] === 4.5, '(7) Média nota_esperada = (4+5)/2 = 4.5');
    $check($resumoDesempenho['gap_medio'] === 2.0, '(7) GAP médio = média só dos GAPs POSITIVOS (des2 tem gap 0 -> NULL na média, só des1 conta) = 2.0');

    $resultadosDesempenho = $avaliacoesRepo->desempenhoResultados($inicio, $fim, $empA, $setA);
    $check(($resultadosDesempenho['atende_parcialmente'] ?? 0) === 1 && ($resultadosDesempenho['supera_expectativas'] ?? 0) === 1, '(8) Distribuição de resultado_final: 1x atende_parcialmente + 1x supera_expectativas');

    // Sem dados (empresa sem nenhuma avaliação de desempenho): médias ficam null, nunca 0 falso.
    $resumoVazio = $avaliacoesRepo->desempenhoResumo($inicio, $fim, 'ZZAD-SEM-DADOS-' . $suffix, null);
    $check($resumoVazio['media_nota_atual'] === null && $resumoVazio['gap_medio'] === null, '(9) Sem nenhuma avaliação de desempenho no filtro: médias/GAP ficam null, nunca 0 falso');

    // ================================================================= Feedback

    $mkFeedback = static function (
        int $metadadosId,
        string $tipo,
        string $status,
        ?DateTimeImmutable $dataFeedback,
        ?string $resultadoGeral,
        ?string $pontosDesenvolvimento,
        string $codigoEmpresa,
        string $codigoSetor
    ) use ($pdo, $gestorId, &$criados, $agoraSql): int {
        $concluidoEm = $status === 'concluido' ? $agoraSql : null;
        $dataFeedbackEfetiva = $dataFeedback ?? new DateTimeImmutable('today');
        $pdo->prepare(
            'INSERT INTO feedbacks (
                metadados_id, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                gestor_usuario_id, gestor_nome_snapshot, tipo, data_feedback, status, pontos_desenvolvimento,
                resultado_geral, criado_por_usuario_id, criado_em, atualizado_em, concluido_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $metadadosId, 'ZZAD Nome', $codigoEmpresa, 'ZZADU', $codigoSetor, $gestorId, 'ZZAD Gestor',
            $tipo, $dataFeedbackEfetiva->format('Y-m-d'), $status, $pontosDesenvolvimento, $resultadoGeral,
            $gestorId, $agoraSql, $agoraSql, $concluidoEm,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['feedbacks'][] = $id;
        return $id;
    };

    $mkValorFeedback = static function (int $feedbackId, string $valor, ?string $avaliacao, ?string $comentario = null) use ($pdo): void {
        $pdo->prepare('INSERT INTO feedback_valores (feedback_id, valor, avaliacao, comentario) VALUES (?, ?, ?, ?)')
            ->execute([$feedbackId, $valor, $avaliacao, $comentario]);
    };

    $cFb1 = $mkMetadados($empA, $setA, 'fb1'); // elegível (resultado_geral + valor), vinculada
    $cFb2 = $mkMetadados($empA, $setA, 'fb2'); // NÃO elegível
    $cFb3 = $mkMetadados($empA, $setA, 'fb3'); // rascunho

    $fbElegivelVinculado = $mkFeedback($cFb1, 'desenvolvimento', 'concluido', $hoje->modify('-5 days'), 'em_desenvolvimento', 'Pontos fixture.', $empA, $setA);
    $mkValorFeedback($fbElegivelVinculado, 'respeito', 'desenvolvimento_necessario', 'Comentário obrigatório fixture.');
    $mkValorFeedback($fbElegivelVinculado, 'honestidade', 'atende');

    $fbNaoElegivel = $mkFeedback($cFb2, 'reconhecimento', 'concluido', $hoje->modify('-6 days'), 'reconhecido_alinhado', null, $empA, $setA);
    $mkValorFeedback($fbNaoElegivel, 'respeito', 'atende');

    $mkFeedback($cFb3, 'desenvolvimento', 'rascunho', null, null, null, $empA, $setA);

    $resumoFeedback = $avaliacoesRepo->feedbackResumo($inicio, $fim, $empA, $setA);
    $check(($resumoFeedback['por_tipo']['desenvolvimento'] ?? 0) === 1, '(10) Feedback por tipo: 1x "desenvolvimento" concluído no período (fb3 é rascunho, não conta)');
    $check(($resumoFeedback['por_tipo']['reconhecimento'] ?? 0) === 1, '(10) Feedback por tipo: 1x "reconhecimento" concluído');
    $check($resumoFeedback['desenvolvimento_necessario'] === 1, '(11) Feedback com algum valor cultural "Desenvolvimento Necessário" = 1 (fb1)');
    $check($resumoFeedback['acompanhamento'] === 0, '(11) Nenhum feedback com resultado_geral = necessita_acompanhamento nesta fixture');

    $valoresDesenvolvimento = $avaliacoesRepo->feedbackValoresDesenvolvimentoNecessario($inicio, $fim, $empA, $setA);
    $check(count($valoresDesenvolvimento) === 1 && $valoresDesenvolvimento[0]['valor'] === 'respeito', '(12) Valor cultural mais incidente em "Desenvolvimento Necessário": respeito (único registrado)');

    // ================================================================= PDI

    $mkPdi = static function (
        int $metadadosId,
        string $status,
        string $origemTipo,
        ?DateTimeImmutable $dataRealConclusao,
        string $codigoEmpresa
    ) use ($pdo, $gestorId, &$criados, $hoje, $agoraSql): int {
        $avaliacaoFinal = $status === 'concluido' ? 'objetivo_atingido' : null;
        $pdo->prepare(
            'INSERT INTO pdis (
                metadados_id, snap_nome, snap_codigo_empresa, snap_codigo_unidade, gestor_usuario_id, gestor_nome_snapshot,
                origem_tipo, status, data_abertura, data_prevista_conclusao, data_real_conclusao, avaliacao_final,
                criado_por_usuario_id, criado_em, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $metadadosId, 'ZZAD Nome', $codigoEmpresa, 'ZZADU', $gestorId, 'ZZAD Gestor',
            $origemTipo, $status, $hoje->modify('-60 days')->format('Y-m-d'), $hoje->modify('+30 days')->format('Y-m-d'),
            $dataRealConclusao?->format('Y-m-d'), $avaliacaoFinal, $gestorId, $agoraSql, $agoraSql,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['pdis'][] = $id;
        return $id;
    };

    $mkPdiOrigem = static function (int $pdiId, string $origemTipo, int $origemRefId) use ($pdo, $gestorId, $agoraSql): void {
        $pdo->prepare(
            'INSERT INTO pdi_origens (pdi_id, origem_tipo, origem_ref_id, criado_por_usuario_id, criado_em)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$pdiId, $origemTipo, $origemRefId, $gestorId, $agoraSql]);
    };

    $cPdi1 = $mkMetadados($empA, $setA, 'pdi1');
    $cPdi2 = $mkMetadados($empA, $setA, 'pdi2');
    $cPdi3 = $mkMetadados($empA, $setA, 'pdi3');
    $cPdi4 = $mkMetadados($empA, $setA, 'pdi4');
    $cPdi5 = $mkMetadados($empA, $setA, 'pdi5');

    $pdiNaoIniciado = $mkPdi($cPdi1, 'nao_iniciado', 'avaliacao_experiencia', null, $empA);
    $pdiEmAndamento = $mkPdi($cPdi2, 'em_andamento', 'feedback', null, $empA);
    $pdiConcluido = $mkPdi($cPdi3, 'concluido', 'avaliacao_desempenho', $hoje->modify('-4 days'), $empA);
    $pdiRascunho = $mkPdi($cPdi4, 'rascunho', 'avaliacao_experiencia', null, $empA);
    $pdiManual = $mkPdi($cPdi5, 'nao_iniciado', 'desenvolvimento_carreira', null, $empA);

    $mkPdiOrigem($pdiNaoIniciado, 'avaliacao_experiencia', $aeElegivelVinculada);
    $mkPdiOrigem($pdiEmAndamento, 'feedback', $fbElegivelVinculado);
    $mkPdiOrigem($pdiConcluido, 'avaliacao_desempenho', $desElegivelVinculada);
    // PDI multi-origem: o mesmo pdiConcluido também recebeu uma avaliação de experiência depois —
    // prova que "vínculos de origem" conta POR VÍNCULO, nunca por PDI único.
    $mkPdiOrigem($pdiConcluido, 'avaliacao_experiencia', $aeAguardando);

    $situacaoPdi = $avaliacoesRepo->pdiSituacaoAtual($empA, $setA);
    $check($situacaoPdi['ativos'] === 3, '(13) PDI ativos = 3 (pdiNaoIniciado + pdiEmAndamento + pdiManual, todos nao_iniciado/em_andamento) — rascunho (pdiRascunho) NUNCA conta como ativo, mesma convenção de PdiService::STATUS_COM_PRAZO');

    $concluidosPeriodo = $avaliacoesRepo->pdiConcluidosPeriodo($inicio, $fim, $empA, $setA);
    $check($concluidosPeriodo === 1, '(14) PDI concluídos no período = 1 (pdiConcluido, data_real_conclusao há 4 dias)');

    $vinculosOrigem = $avaliacoesRepo->pdiVinculosOrigem($empA, $setA);
    $check($vinculosOrigem['avaliacao_experiencia'] === 2, '(15) Vínculos de origem "avaliacao_experiencia" = 2 (pdiNaoIniciado->ae2 + o segundo vínculo de pdiConcluido->ae6) — PDI multi-origem conta em cada categoria, nunca deduplicado por PDI');
    $check($vinculosOrigem['feedback'] === 1, '(15) Vínculos de origem "feedback" = 1 (pdiEmAndamento)');
    $check($vinculosOrigem['avaliacao_desempenho'] === 1, '(15) Vínculos de origem "avaliacao_desempenho" = 1 (pdiConcluido)');
    $check($vinculosOrigem['manual'] === 1, '(15) PDI manual (origem_tipo=desenvolvimento_carreira) = 1 (pdiManual) — nunca aparece em pdi_origens por desenho');

    // ================================================================= Filtro de Setor (PDI via JOIN)

    $setC = 'ZZAD' . $suffix . 'SC';
    $cPdiSetorC = $mkMetadados($empA, $setC, 'pdiSetorC');
    $mkPdi($cPdiSetorC, 'nao_iniciado', 'desenvolvimento_carreira', null, $empA);
    $situacaoSetorA = $avaliacoesRepo->pdiSituacaoAtual($empA, $setA);
    $situacaoSetorC = $avaliacoesRepo->pdiSituacaoAtual($empA, $setC);
    $check($situacaoSetorA['ativos'] === 3, '(16) Filtro de Setor do PDI (via JOIN colaboradores_metadados, pdis não tem snap_codigo_setor): Setor A continua com 3, nunca contaminado pelo novo PDI do Setor C');
    $check($situacaoSetorC['ativos'] === 1, '(16) Setor C isolado corretamente = 1 (o PDI recém-criado)');

    // ================================================================= Cobertura de Desenvolvimento

    $coberturaExp = $avaliacoesRepo->coberturaExperiencia($inicio, $fim, $empA, $setA);
    $check($coberturaExp['elegiveis'] === 2, '(17) Cobertura Experiência: elegíveis = 2 (ae2 efetivacao_acompanhamento + ae3 nao_recomendado; ae1/ae6 com parecer apto_efetivacao NÃO são elegíveis)');
    $check($coberturaExp['vinculados'] === 1, '(17) Cobertura Experiência: vinculados = 1 (só ae2 está entre os elegíveis E vinculados; ae6, usado no teste (15) de multi-origem, não é elegível — parecer apto_efetivacao; ae3 é elegível mas ficou SEM vínculo)');

    $coberturaFb = $avaliacoesRepo->coberturaFeedback($inicio, $fim, $empA, $setA);
    $check($coberturaFb['elegiveis'] === 1 && $coberturaFb['vinculados'] === 1, '(18) Cobertura Feedback: 1 elegível (fb1, via resultado_geral+valor), 1 vinculado (mesma fb1) — fb2 não entra em nenhum dos dois');

    $coberturaDes = $avaliacoesRepo->coberturaDesempenho($inicio, $fim, $empA, $setA);
    $check($coberturaDes['elegiveis'] === 1 && $coberturaDes['vinculados'] === 1, '(19) Cobertura Desempenho: 1 elegível (des1, GAP real), 1 vinculado (mesma des1) — des2 (GAP zero) não entra');

    // ================================================================= Wiring: PeopleAnalyticsService

    $service = new PeopleAnalyticsService();
    $painel = $service->montarPainel(['codigo_empresa' => $empA, 'codigo_setor' => $setA], $inicio, $fim);
    $avaliacoesPainel = $painel['avaliacoes_desenvolvimento'];

    $check(
        $avaliacoesPainel['experiencia']['realizadas_por_tipo'] === $avaliacoesRepo->experienciaRealizadasPorTipo($inicio, $fim, $empA, $setA),
        '(20) montarPainel()["avaliacoes_desenvolvimento"]["experiencia"] bate exatamente com a chamada direta ao repositório — wiring sem duplicar lógica'
    );
    $check(
        $avaliacoesPainel['desempenho']['gap_medio'] === $resumoDesempenho['gap_medio']
        && $avaliacoesPainel['feedback']['desenvolvimento_necessario'] === $resumoFeedback['desenvolvimento_necessario']
        && $avaliacoesPainel['pdi']['ativos'] === $situacaoPdi['ativos'],
        '(20) Demais sub-chaves (desempenho/feedback/pdi) do painel batem com as mesmas chamadas diretas'
    );
    $check(
        $avaliacoesPainel['cobertura_desenvolvimento']['total']['elegiveis'] === 4
        && $avaliacoesPainel['cobertura_desenvolvimento']['total']['vinculados'] === 3,
        '(21) Cobertura de Desenvolvimento TOTAL = soma dos 3 domínios: 4 elegíveis (2 AE + 1 Feedback + 1 Desempenho), 3 vinculados (1 AE + 1 Feedback + 1 Desempenho)'
    );
    $check(
        $avaliacoesPainel['cobertura_desenvolvimento']['total']['percentual'] === 75.0,
        '(21) Percentual de Cobertura = 3/4 × 100 = 75,0% — nunca uma taxa sobre a população toda de colaboradores'
    );

    // Sem nenhum dado no filtro: Cobertura TOTAL fica 0/0 -> percentual null (nunca 0% falso).
    $painelVazio = $service->montarPainel(['codigo_empresa' => 'ZZAD-SEM-DADOS-' . $suffix], $inicio, $fim);
    $check($painelVazio['avaliacoes_desenvolvimento']['cobertura_desenvolvimento']['total']['percentual'] === null, '(22) Sem nenhum documento elegível no filtro: percentual de Cobertura fica null, nunca 0% enganoso');

    // ================================================================= Integração/Onboarding (reuso)

    $criarContratoIntegracao = static function (string $marcador, string $codigoEmpresa, string $codigoSetor) use ($pdo, &$criados, $suffix): int {
        $identificador = 'ZZADI_' . $suffix . '_' . $marcador;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $identificador, $codigoEmpresa, 'ZZADU', 'ZCI' . $suffix . $marcador, 'ZPI' . $suffix . $marcador, null,
            'ZZADI Fixture ' . $marcador, 'ZZADI Empresa', 'ZZADI Unidade', $codigoSetor, 'ZZADI Setor', 'ZZADI Cargo', 'CG1',
            '2024-01-10', '2024-01-10', 'zzadi-teste',
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['metadados_ids'][] = $id;
        return $id;
    };

    $cInt1 = $criarContratoIntegracao('int1', $empA, $setA);
    $cInt2 = $criarContratoIntegracao('int2', $empA, $setA);
    $cIntComp1 = $criarContratoIntegracao('intcomp1', $empA, $setA);

    // Período atual: 1 promotor + 1 detrator -> NPS = 0.0. Comparativo: 1 detrator só -> NPS = -100.0.
    // Diferença esperada: 0.0 - (-100.0) = +100 pontos.
    $notasPadrao = ['nota_clareza' => 4, 'nota_acolhimento' => 4, 'nota_normas' => 4, 'nota_utilidade' => 4, 'nota_satisfacao_geral' => 4];
    PesquisaIntegracaoQr::inserirResposta($cInt1, $hoje->modify('-5 days')->format('Y-m-d'), ['nota_nps' => 10] + $notasPadrao, null);
    PesquisaIntegracaoQr::inserirResposta($cInt2, $hoje->modify('-4 days')->format('Y-m-d'), ['nota_nps' => 2] + $notasPadrao, null);
    [$compInicio, $compFim] = RhIndicadoresService::periodoMesmoIntervaloAnoAnterior($inicio, $fim);
    PesquisaIntegracaoQr::inserirResposta($cIntComp1, $compInicio->modify('+2 days')->format('Y-m-d'), ['nota_nps' => 1] + $notasPadrao, null);

    $painelComIntegracao = $service->montarPainel(['codigo_empresa' => $empA, 'codigo_setor' => $setA], $inicio, $fim, $compInicio, $compFim);
    $integracaoDireto = (new DashboardIntegracaoService())->montarPainel([
        'inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d'),
        'codigo_empresa' => $empA, 'codigo_unidade' => '', 'codigo_setor' => $setA,
    ]);

    $check(
        $painelComIntegracao['integracao_onboarding']['nps']['valor'] === $integracaoDireto['nps']['nps'],
        '(23) montarPainel()["integracao_onboarding"]["nps"]["valor"] é EXATAMENTE igual ao retorno direto de DashboardIntegracaoService::montarPainel() — reaproveitamento integral, nenhuma fórmula paralela'
    );
    $check($painelComIntegracao['integracao_onboarding']['amostra'] === 2, '(23) Amostra do período atual = 2 (as 2 respostas de cInt1/cInt2)');
    $check(
        $painelComIntegracao['integracao_onboarding']['nps']['variacao_pontos'] === 100.0,
        '(24/§27) Variação do NPS exibida em PONTOS: 0,0 (atual) - (-100,0) (comparativo) = +100 pontos — nunca variação percentual'
    );

    // Sem nenhuma resposta no filtro: NPS/variação ficam null, nunca 0 ou 100% inventados.
    $painelSemIntegracao = $service->montarPainel(['codigo_empresa' => 'ZZADI-SEM-DADOS-' . $suffix], $inicio, $fim);
    $check(
        $painelSemIntegracao['integracao_onboarding']['amostra'] === 0
        && $painelSemIntegracao['integracao_onboarding']['nps']['valor'] === null
        && $painelSemIntegracao['integracao_onboarding']['nps']['variacao_pontos'] === null,
        '(25) Sem nenhuma resposta no filtro: amostra 0, NPS null e variação em pontos null — nunca falso zero'
    );

    echo "PEOPLE_ANALYTICS_AVALIACOES_DESENVOLVIMENTO_OK\n";
} finally {
    foreach ($criados['pdis'] as $id) {
        $pdo->prepare('DELETE FROM pdi_origens WHERE pdi_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM pdis WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['feedbacks'] as $id) {
        $pdo->prepare('DELETE FROM feedback_valores WHERE feedback_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM feedbacks WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['avaliacoes_desempenho'] as $id) {
        $pdo->prepare('DELETE FROM avaliacoes_desempenho_criterios WHERE avaliacao_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM avaliacoes_desempenho WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['avaliacoes_experiencia'] as $id) {
        $pdo->prepare('DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = ? AND documento_id = ?')->execute(['avaliacao_experiencia', $id]);
        $pdo->prepare('DELETE FROM avaliacoes_experiencia_criterios WHERE avaliacao_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM avaliacoes_experiencia WHERE id = ?')->execute([$id]);
    }
    $pdo->prepare("DELETE FROM pesquisas_integracao WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE 'ZZADI\\_%' ESCAPE '\\\\')")->execute();
    foreach ($criados['metadados_ids'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }

    if ($falhas !== []) {
        fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
        exit(1);
    }
}
