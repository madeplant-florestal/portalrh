<?php

/**
 * Integração — Formulário de Feedback e Desenvolvimento (Etapa 4, 2026-09).
 *
 * Prova que:
 *   - criação vincula corretamente colaborador/gestor/tipo, nascendo como rascunho;
 *   - os 4 tipos (Reconhecimento/Desenvolvimento/Alinhamento/Acompanhamento) são aceitos;
 *   - os 6 valores culturais persistem avaliação (Atende/Desenvolvimento Necessário) + comentário;
 *   - comentário é obrigatório SOMENTE quando "Desenvolvimento Necessário" — validado no BACKEND,
 *     nunca confiando só no frontend (§34/§63);
 *   - pontos fortes/desenvolvimento/próximos passos persistem;
 *   - espaço do colaborador é preenchido de forma assistida, sem alterar as respostas do gestor;
 *   - resultado geral + observações finais fecham o feedback;
 *   - salvar rascunho / concluir / ciência / reabrir seguem a máquina de estados esperada, com
 *     imutabilidade após conclusão (§59);
 *   - ciência COMPARTILHADA (AvaliacoesDesenvolvimentoAuditoriaService) grava o documento_tipo/
 *     documento_id corretos e nunca permite registrar ciência em documento de terceiro;
 *   - escopo por linha (gestor responsável) — mesmo padrão de PdiService/AvaliacaoExperienciaService,
 *     Admin/RH veem tudo, gestor sem vínculo é bloqueado (IDOR).
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
$criados = ['usuarios' => [], 'metadados' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$agora = new DateTimeImmutable('2026-06-15 10:00:00');

$criarContrato = static function (string $nome) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZFB' . $suffix . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, 'EMPA', 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZFB Empresa', 'ZZFB Unidade',
        'ST1', 'ZZFB Setor', 'ZZFB Cargo', 'CG1', '2020-01-01', '2020-01-01', null, null, 1, 'zzfb-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$criarUsuario = static function (string $rotulo, string $role, ?int $metadadosId = null, ?int $gestorId = null) use ($pdo, &$criados, $suffix, $senha): int {
    $id = User::create('ZZFB ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzfb.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($metadadosId !== null || $gestorId !== null) {
        $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ?, gestor_usuario_id = ? WHERE id = ?')->execute([$metadadosId, $gestorId, $id]);
    }
    $criados['usuarios'][] = $id;
    return $id;
};

/** Monta o array de valores culturais para o POST — todos "atende" por padrão, sem comentário. */
$dadosValoresBase = static function (array $overrides = []): array {
    $dados = [];
    foreach (array_keys(FeedbackService::VALORES_CULTURAIS) as $valor) {
        $dados['valor_' . $valor] = $overrides[$valor]['avaliacao'] ?? 'atende';
        $dados['comentario_' . $valor] = $overrides[$valor]['comentario'] ?? '';
    }
    return $dados;
};

try {
    // ---- 0) Permissões e atores ---------------------------------------------------------------------
    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'feedback.visualizar'")->fetchColumn();
    $permAva = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'feedback.avaliar'")->fetchColumn();
    $check($permVis > 0 && $permAva > 0, '(0) Permissões feedback.visualizar/avaliar existem no catálogo (seed aplicado)');

    $gestorAId = $criarUsuario('Gestor A', 'viewer');
    $gestorBId = $criarUsuario('Gestor B', 'viewer');
    $rhId = $criarUsuario('RH', 'rh');
    Authorization::sincronizar($gestorAId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorBId, [$permVis, $permAva]);
    Authorization::sincronizar($rhId, [$permVis, $permAva]);
    $atorGestorA = ['id' => $gestorAId, 'role' => 'viewer'];
    $atorGestorB = ['id' => $gestorBId, 'role' => 'viewer'];
    $atorRh = ['id' => $rhId, 'role' => 'rh'];

    $contrato = $criarContrato('ZZFB Colaborador');
    $criarUsuario('Vinculo', 'viewer', $contrato, $gestorAId);

    $svc = new FeedbackService(new FeedbackRepository($pdo), new AvaliacoesDesenvolvimentoAuditoriaService($pdo));

    // ---- 1) Criação — vínculo colaborador/gestor/tipo, nasce como rascunho -------------------------------
    $rCriar = $svc->criar(array_merge([
        'metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'reconhecimento',
        'data_feedback' => '2026-06-10', 'pontos_fortes' => 'ZZFB pontos fortes.', 'pontos_desenvolvimento' => 'ZZFB pontos de desenvolvimento.',
        'proximos_passos' => 'ZZFB próximos passos.',
    ], $dadosValoresBase()), $atorGestorA, $agora, '127.0.0.1');
    $check($rCriar['ok'] ?? false, '(1) Criação de feedback válido tem sucesso');
    $idFeedback = (int)($rCriar['id'] ?? 0);

    $detalheInicial = $svc->detalhe($idFeedback, $atorGestorA);
    $check(($detalheInicial['feedback']['metadados_id'] ?? null) === $contrato, '(2) Feedback vinculado ao metadados_id correto');
    $check((int)($detalheInicial['feedback']['gestor_usuario_id'] ?? 0) === $gestorAId, '(3) Feedback vinculado ao gestor correto');
    $check(($detalheInicial['feedback']['tipo'] ?? null) === 'reconhecimento', '(4) Tipo "reconhecimento" persistido');
    $check(($detalheInicial['feedback']['status'] ?? null) === 'rascunho', '(5) Feedback nasce como rascunho');
    $check(($detalheInicial['feedback']['pontos_fortes'] ?? null) === 'ZZFB pontos fortes.', '(6) Pontos fortes persistidos');
    $check(($detalheInicial['feedback']['pontos_desenvolvimento'] ?? null) === 'ZZFB pontos de desenvolvimento.', '(7) Pontos de desenvolvimento persistidos');
    $check(($detalheInicial['feedback']['proximos_passos'] ?? null) === 'ZZFB próximos passos.', '(8) Próximos passos persistidos');

    // ---- 2) Os 4 tipos são aceitos --------------------------------------------------------------------
    $tiposAceitos = true;
    foreach (array_keys(FeedbackService::TIPOS) as $tipo) {
        $r = $svc->criar(array_merge(['metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => $tipo, 'data_feedback' => '2026-06-10'], $dadosValoresBase()), $atorGestorA, $agora, null);
        if (!($r['ok'] ?? false)) {
            $tiposAceitos = false;
        }
    }
    $check($tiposAceitos, '(9) Os 4 tipos (Reconhecimento/Desenvolvimento/Alinhamento/Acompanhamento) são aceitos por criar()');

    // ---- 3) Os 6 valores culturais persistem avaliação + comentário --------------------------------------
    $rTodosValores = $svc->criar(array_merge(
        ['metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'desenvolvimento', 'data_feedback' => '2026-06-10'],
        $dadosValoresBase(['respeito' => ['avaliacao' => 'atende', 'comentario' => 'Muito respeitoso.']])
    ), $atorGestorA, $agora, null);
    $idTodosValores = (int)$rTodosValores['id'];
    $detalheValores = $svc->detalhe($idTodosValores, $atorGestorA);
    $check(count($detalheValores['valores']) === 6, '(10) Os 6 valores culturais são persistidos (Respeito/Honestidade/Lealdade/Ética/Coragem/Ousadia)');
    $check(($detalheValores['valores']['respeito']['avaliacao'] ?? null) === 'atende' && ($detalheValores['valores']['respeito']['comentario'] ?? null) === 'Muito respeitoso.', '(11) Avaliação e comentário do valor "Respeito" persistidos corretamente');
    $check(array_key_exists('ousadia', $detalheValores['valores']) && array_key_exists('coragem', $detalheValores['valores']), '(12) Valores "Ousadia" e "Coragem" (nomes que divergem entre o texto do domínio e o slug) presentes');

    // ---- 4) Comentário condicional — SOMENTE quando "Desenvolvimento Necessário" (§34/§63) -----------------
    $rSemComentario = $svc->criar(array_merge(
        ['metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'desenvolvimento', 'data_feedback' => '2026-06-10'],
        $dadosValoresBase(['etica' => ['avaliacao' => 'desenvolvimento_necessario', 'comentario' => '']])
    ), $atorGestorA, $agora, null);
    $check(($rSemComentario['ok'] ?? true) === false, '(13) "Desenvolvimento Necessário" SEM comentário é recusado pelo BACKEND — nunca confia só no frontend');

    $rComComentario = $svc->criar(array_merge(
        ['metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'desenvolvimento', 'data_feedback' => '2026-06-10'],
        $dadosValoresBase(['etica' => ['avaliacao' => 'desenvolvimento_necessario', 'comentario' => 'Precisa seguir melhor as normas.']])
    ), $atorGestorA, $agora, null);
    $check($rComComentario['ok'] ?? false, '(14) "Desenvolvimento Necessário" COM comentário é aceito');

    $rAtendeSemComentario = $svc->criar(array_merge(
        ['metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'alinhamento', 'data_feedback' => '2026-06-10'],
        $dadosValoresBase(['ousadia' => ['avaliacao' => 'atende', 'comentario' => '']])
    ), $atorGestorA, $agora, null);
    $check($rAtendeSemComentario['ok'] ?? false, '(15) "Atende" SEM comentário é aceito (comentário opcional neste caso)');

    // ---- 5) Escopo por linha (IDOR) --------------------------------------------------------------------
    $check($svc->detalhe($idFeedback, $atorGestorB) === null, '(16) Gestor B (sem vínculo) NÃO acessa o feedback do Gestor A (IDOR bloqueado)');
    $check($svc->detalhe($idFeedback, $atorGestorA) !== null, '(17) Gestor A (dono) acessa normalmente');
    $check($svc->detalhe($idFeedback, $atorRh) !== null, '(18) RH (escopo total) acessa qualquer feedback');
    $rAtualizarSemAcesso = $svc->atualizar($idFeedback, $dadosValoresBase(), $atorGestorB, $agora, null);
    $check(($rAtualizarSemAcesso['ok'] ?? true) === false, '(19) Gestor B não consegue atualizar o feedback do Gestor A');

    // ---- 6) Salvar rascunho (atualizar) ------------------------------------------------------------------
    $rAtualizar = $svc->atualizar($idFeedback, array_merge($dadosValoresBase(), ['pontos_fortes' => 'ZZFB pontos fortes ATUALIZADOS.']), $atorGestorA, $agora, null);
    $check($rAtualizar['ok'] ?? false, '(20) atualizar() (salvar rascunho) funciona para o dono');
    $detalheAtualizado = $svc->detalhe($idFeedback, $atorGestorA);
    $check(($detalheAtualizado['feedback']['pontos_fortes'] ?? null) === 'ZZFB pontos fortes ATUALIZADOS.', '(21) Alteração do rascunho persiste');

    // ---- 7) Espaço do colaborador — assistido, nunca altera as respostas do gestor -------------------------
    $rEspaco = $svc->registrarEspacoColaborador($idFeedback, 'ZZFB relato do colaborador — dificuldade com prazos.', $atorGestorA, $agora);
    $check($rEspaco['ok'] ?? false, '(22) registrarEspacoColaborador() funciona');
    $detalheComEspaco = $svc->detalhe($idFeedback, $atorGestorA);
    $check(($detalheComEspaco['feedback']['espaco_colaborador'] ?? null) === 'ZZFB relato do colaborador — dificuldade com prazos.', '(23) Relato do colaborador persistido');
    $check(!empty($detalheComEspaco['feedback']['espaco_colaborador_preenchido_em']), '(24) Data/hora do preenchimento do espaço do colaborador registrada');
    $check(($detalheComEspaco['feedback']['pontos_fortes'] ?? null) === 'ZZFB pontos fortes ATUALIZADOS.', '(25) Espaço do colaborador NUNCA altera as respostas do gestor');

    // ---- 8) Concluir — resultado geral + observações finais -----------------------------------------------
    $rConcluirSemResultado = $svc->concluir($idFeedback, array_merge($dadosValoresBase(), ['resultado_geral' => '']), $atorGestorA, $agora, null);
    $check(($rConcluirSemResultado['ok'] ?? true) === false, '(26) Concluir sem Resultado Geral é recusado');
    $rConcluir = $svc->concluir($idFeedback, array_merge($dadosValoresBase(), ['resultado_geral' => 'reconhecido_alinhado', 'observacoes_finais' => 'ZZFB observações finais.']), $atorGestorA, $agora, null);
    $check($rConcluir['ok'] ?? false, '(27) Concluir com Resultado Geral válido tem sucesso');
    $detalheConcluido = $svc->detalhe($idFeedback, $atorGestorA);
    $check(($detalheConcluido['feedback']['status'] ?? null) === 'concluido', '(28) Status vira "concluido"');
    $check(($detalheConcluido['feedback']['resultado_geral'] ?? null) === 'reconhecido_alinhado', '(29) Resultado geral persistido');
    $check(!empty($detalheConcluido['feedback']['concluido_em']), '(30) Data de conclusão registrada');

    // ---- 8b) REGRESSÃO — concluir() com o payload REAL do <form> de encerramento (admin/feedbacks/form.php)
    //          NÃO PODE apagar valores culturais nem pontos_fortes/pontos_desenvolvimento/proximos_passos.
    //          Bug encontrado em 2026-09-30 (auditado junto com a Avaliação de Desempenho): concluir()
    //          reconstruía esses campos a partir de $_POST via validarValores($dados)/salvarValores(), mas
    //          o formulário de encerramento só envia csrf/resultado_geral/observacoes_finais — confirmado
    //          lendo o HTML do form (nenhum campo valor_*/comentario_*/pontos_*/proximos_passos existe
    //          nesse <form>). Corrigido: concluir() agora só altera resultado_geral/observacoes_finais/
    //          status/concluido_em — nunca reconstrói o que já está persistido. Este teste falha com a
    //          implementação antiga (tudo viraria NULL) e passa com a corrigida.
    $rCriarRegressao = $svc->criar(array_merge([
        'metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'acompanhamento',
        'data_feedback' => '2026-06-10', 'pontos_fortes' => 'ZZFB regressão pontos fortes.',
        'pontos_desenvolvimento' => 'ZZFB regressão pontos de desenvolvimento.', 'proximos_passos' => 'ZZFB regressão próximos passos.',
    ], $dadosValoresBase(['respeito' => ['avaliacao' => 'atende', 'comentario' => 'ZZFB comentário respeito regressão.']])), $atorGestorA, $agora, null);
    $check($rCriarRegressao['ok'] ?? false, '(30b) [REGRESSÃO] fixture criada com valores culturais e textos preenchidos');
    $idRegressao = (int)$rCriarRegressao['id'];

    // Payload EXATO do <form action=".../concluir"> em admin/feedbacks/form.php — só isto, nada mais.
    $payloadEncerramentoReal = ['resultado_geral' => 'em_desenvolvimento', 'observacoes_finais' => 'ZZFB regressão observações finais.'];
    $rConcluirRegressao = $svc->concluir($idRegressao, $payloadEncerramentoReal, $atorGestorA, $agora, null);
    $check($rConcluirRegressao['ok'] ?? false, '(30c) [REGRESSÃO] concluir() com o payload REAL do formulário (só resultado_geral/observacoes_finais) tem sucesso');

    $detalheRegressao = $svc->detalhe($idRegressao, $atorGestorA);
    $check(($detalheRegressao['feedback']['pontos_fortes'] ?? null) === 'ZZFB regressão pontos fortes.', '(30d) [REGRESSÃO] pontos_fortes PERMANECE intacto — não é apagado por concluir()');
    $check(($detalheRegressao['feedback']['pontos_desenvolvimento'] ?? null) === 'ZZFB regressão pontos de desenvolvimento.', '(30e) [REGRESSÃO] pontos_desenvolvimento PERMANECE intacto');
    $check(($detalheRegressao['feedback']['proximos_passos'] ?? null) === 'ZZFB regressão próximos passos.', '(30f) [REGRESSÃO] proximos_passos PERMANECE intacto');
    $check(($detalheRegressao['valores']['respeito']['avaliacao'] ?? null) === 'atende' && ($detalheRegressao['valores']['respeito']['comentario'] ?? null) === 'ZZFB comentário respeito regressão.', '(30g) [REGRESSÃO] valor cultural "respeito" (avaliação + comentário) PERMANECE intacto');
    $qtdValoresPreenchidos = count(array_filter($detalheRegressao['valores'], static fn(array $v): bool => $v['avaliacao'] !== null));
    $check($qtdValoresPreenchidos === 6, '(30h) [REGRESSÃO] os 6 valores culturais continuam com avaliação preenchida — nenhum foi zerado por concluir()');
    $check(($detalheRegressao['feedback']['resultado_geral'] ?? null) === 'em_desenvolvimento', '(30i) resultado_geral persistido corretamente a partir do payload real');
    $check(($detalheRegressao['feedback']['observacoes_finais'] ?? null) === 'ZZFB regressão observações finais.', '(30j) observacoes_finais persistido corretamente');
    $check(($detalheRegressao['feedback']['status'] ?? null) === 'concluido' && !empty($detalheRegressao['feedback']['concluido_em']), '(30k) status/concluido_em corretos');

    // ---- 9) Imutabilidade após conclusão (§59) ------------------------------------------------------------
    $rEditarConcluido = $svc->atualizar($idFeedback, $dadosValoresBase(), $atorGestorA, $agora, null);
    $check(($rEditarConcluido['ok'] ?? true) === false, '(31) Feedback concluído não pode ser editado silenciosamente (precisa reabrir antes)');

    // ---- 10) Ciência COMPARTILHADA — documento correto, nunca em documento de terceiro ----------------------
    $auditoria = new AvaliacoesDesenvolvimentoAuditoriaService($pdo);
    $rCiencia = $svc->registrarCienciaColaborador($idFeedback, 'ZZFB Colaborador', $atorGestorA, $agora);
    $check($rCiencia['ok'] ?? false, '(32) registrarCienciaColaborador() funciona para feedback concluído');
    $ciencias = $auditoria->listarCiencias('feedback', $idFeedback);
    $check(($ciencias['colaborador']['nome_snapshot'] ?? null) === 'ZZFB Colaborador', '(33) Ciência registra o NOME do colaborador correto');
    $check(($ciencias['colaborador']['status_assinatura'] ?? null) === 'nao_solicitada', '(34) status_assinatura nasce "não solicitada" — nenhum provedor integrado ainda (§46/§47)');
    $check(!$auditoria->temCiencia('avaliacao_experiencia', $idFeedback, 'colaborador'), '(35) Ciência do feedback NÃO aparece em avaliacao_experiencia com o mesmo id — documento_tipo isola corretamente os dois domínios');
    $rCienciaOutroFeedback = $svc->registrarCienciaColaborador($idTodosValores, 'Tentativa cruzada', $atorGestorB, $agora);
    $check(($rCienciaOutroFeedback['ok'] ?? true) === false, '(36) Gestor B não consegue registrar ciência em feedback de outro gestor (mesmo componente de ciência respeita o escopo do documento)');

    // ---- 11) Reabertura (só Admin/RH) ------------------------------------------------------------------
    $rReabrirGestor = $svc->reabrir($idFeedback, 'tentativa', $atorGestorA, $agora, null);
    $check(($rReabrirGestor['ok'] ?? true) === false, '(37) Gestor (sem escopo total) NÃO pode reabrir um feedback concluído');
    $rReabrirSemJustificativa = $svc->reabrir($idFeedback, '', $atorRh, $agora, null);
    $check(($rReabrirSemJustificativa['ok'] ?? true) === false, '(38) RH sem justificativa é recusado');
    $rReabrirRh = $svc->reabrir($idFeedback, 'ZZFB reabertura de teste', $atorRh, $agora, null);
    $check($rReabrirRh['ok'] ?? false, '(39) RH com justificativa reabre com sucesso');
    $detalheReaberto = $svc->detalhe($idFeedback, $atorGestorA);
    $check(($detalheReaberto['feedback']['status'] ?? null) === 'rascunho', '(40) Após reabrir, status volta a "rascunho"');

    echo $falhas === [] ? "\nFEEDBACK_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $idsStmt = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $idsStmt->execute($criados['metadados']);
        $metadadosIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($metadadosIds !== []) {
            $in = implode(',', $metadadosIds);
            $fbIds = array_map('intval', $pdo->query("SELECT id FROM feedbacks WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($fbIds !== []) {
                $inFb = implode(',', $fbIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'feedback' AND documento_id IN ($inFb)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'feedback' AND documento_id IN ($inFb)");
                $pdo->exec("DELETE FROM feedback_valores WHERE feedback_id IN ($inFb)");
                $pdo->exec("DELETE FROM feedbacks WHERE id IN ($inFb)");
            }
            $pdo->exec("UPDATE usuarios SET colaborador_metadados_id = NULL WHERE colaborador_metadados_id IN ($in)");
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE usuarios SET gestor_usuario_id = NULL WHERE gestor_usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
}

if ($falhas !== []) {
    exit(1);
}
