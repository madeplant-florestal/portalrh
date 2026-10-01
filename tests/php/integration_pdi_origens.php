<?php

/**
 * Integração — Avaliações/Feedback alimentando o PDI (Etapa 7, 2026-09).
 *
 * Prova, contra o banco:
 *   - Avaliação de Experiência/Feedback/Avaliação de Desempenho concluídos expõem candidatos a
 *     necessidade de desenvolvimento (PdiService::contextoOrigem() → *Service::candidatosParaPdi());
 *   - "criar novo PDI" a partir de uma origem grava origem_tipo/origem_ref_tipo/origem_ref_id no
 *     cabeçalho E uma linha em pdi_origens com o mesmo documento;
 *   - "adicionar a PDI existente" só funciona para o MESMO colaborador, nunca duplica silenciosamente;
 *   - um PDI pode ter MÚLTIPLAS origens (Feedback + Avaliação de Desempenho no mesmo PDI);
 *   - navegação reversa (PdiRepository::pdisPorOrigem()) encontra os PDIs de um documento;
 *   - documentos de origem NUNCA são alterados pelo vínculo;
 *   - avaliação em rascunho/cancelada NUNCA pode alimentar PDI;
 *   - IDOR: gestor B não acessa origem nem PDI do gestor A, nem consegue vincular por ID "cru";
 *   - PDI manual continua funcionando exatamente como antes, sem nenhuma linha em pdi_origens.
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
$criados = ['usuarios' => [], 'metadados' => [], 'pdis' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$hoje = new DateTimeImmutable('2026-09-30');

$criarContrato = static function (string $nome) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZPO' . $suffix . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $identificador, 'EMPA', 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZPO Empresa', 'ZZPO Unidade',
        'ST1', 'ZZPO Setor', 'ZZPO Cargo', 'CG1', '2024-01-10', '2024-01-10', 'zzpo-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$criarUsuario = static function (string $rotulo, string $role, array $permissoesPdi = ['visualizar', 'gerenciar']) use ($pdo, &$criados, $suffix, $senha): int {
    $id = User::create('ZZPO ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzpo.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    if ($permissoesPdi !== []) {
        $ids = [];
        foreach ($permissoesPdi as $acao) {
            $ids[] = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'pdi.{$acao}'")->fetchColumn();
        }
        $permAvExp = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.visualizar'")->fetchColumn();
        $permAvExpAv = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.avaliar'")->fetchColumn();
        $permFb = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'feedback.visualizar'")->fetchColumn();
        $permFbAv = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'feedback.avaliar'")->fetchColumn();
        $permAd = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_desempenho.visualizar'")->fetchColumn();
        $permAdAv = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_desempenho.avaliar'")->fetchColumn();
        Authorization::sincronizar($id, array_merge($ids, [$permAvExp, $permAvExpAv, $permFb, $permFbAv, $permAd, $permAdAv]));
    }
    return $id;
};

try {
    $gestorAId = $criarUsuario('Gestor A', 'viewer');
    $gestorBId = $criarUsuario('Gestor B', 'viewer');
    $rhId = $criarUsuario('RH', 'rh');
    $atorGestorA = ['id' => $gestorAId, 'role' => 'viewer'];
    $atorGestorB = ['id' => $gestorBId, 'role' => 'viewer'];
    $atorRh = ['id' => $rhId, 'role' => 'rh'];

    $contratoA = $criarContrato('ZZPO Colaborador A');
    $contratoB = $criarContrato('ZZPO Colaborador B');

    // AvaliacaoExperienciaService::contratoParaAvaliar() resolve o gestor do contrato via o vínculo "Gestor
    // Imediato" (usuarios.colaborador_metadados_id -> usuarios.gestor_usuario_id), não pelo gestor_usuario_id
    // enviado no POST — por isso cada contrato precisa de um usuário "vínculo" ligado ao gestor dono.
    $vinculoA = $criarUsuario('Vinculo A', 'viewer', []);
    $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ?, gestor_usuario_id = ? WHERE id = ?')->execute([$contratoA, $gestorAId, $vinculoA]);
    $vinculoB = $criarUsuario('Vinculo B', 'viewer', []);
    $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ?, gestor_usuario_id = ? WHERE id = ?')->execute([$contratoB, $gestorBId, $vinculoB]);

    $aeSvc = new AvaliacaoExperienciaService();
    $fbSvc = new FeedbackService();
    $adSvc = new AvaliacaoDesempenhoService();
    $pdiSvc = new PdiService();
    $pdiRepo = new PdiRepository();

    // ============================================================= 1) Experiência → PDI
    $rAeRascunho = $aeSvc->salvarRascunho($contratoA, '45', ['gestor_usuario_id' => (string)$gestorAId], $atorGestorA, $hoje, null);
    $idAeRascunho = (int)$rAeRascunho['id'];
    $check($pdiSvc->contextoOrigem('avaliacao_experiencia', $idAeRascunho, $atorGestorA) === null, '(1) Avaliação de Experiência em RASCUNHO não pode alimentar PDI (§25)');

    $rAe = $aeSvc->salvarRascunho($contratoA, '90', [
        'gestor_usuario_id' => (string)$gestorAId,
        'feedback_geral' => 'ZZPO feedback geral do gestor sobre o período.',
        'valores_comentario_respeito' => 'ZZPO comentário de respeito.',
    ], $atorGestorA, $hoje, null);
    $idAe = (int)$rAe['id'];
    $aeSvc->concluir($idAe, ['parecer' => 'apto_efetivacao'], $atorGestorA, $hoje, null);

    $ctxAe = $pdiSvc->contextoOrigem('avaliacao_experiencia', $idAe, $atorGestorA);
    $check($ctxAe !== null, '(2) Avaliação de Experiência CONCLUÍDA expõe contexto para o PDI');
    $check(count($ctxAe['itens']) >= 2, '(3) Contexto inclui feedback geral + comentário de valor cultural como candidatos');
    $check($pdiSvc->contextoOrigem('avaliacao_experiencia', $idAe, $atorGestorB) === null, '(4) Gestor B (IDOR) não enxerga o contexto da avaliação do Gestor A');

    $rCriarAe = $pdiSvc->criarDeOrigem('avaliacao_experiencia', $idAe, array_column($ctxAe['itens'], 'chave'), $atorGestorA, $hoje, '127.0.0.1');
    $check($rCriarAe['ok'] ?? false, '(5) criarDeOrigem() a partir da Avaliação de Experiência cria o PDI');
    $idPdiAe = (int)$rCriarAe['id'];
    $criados['pdis'][] = $idPdiAe;

    $detAe = $pdiSvc->detalhe($idPdiAe, $atorGestorA, $hoje);
    $check($detAe['pdi']['origem_tipo'] === 'avaliacao_experiencia' && (int)$detAe['pdi']['origem_ref_id'] === $idAe, '(6) Cabeçalho do PDI grava origem_tipo/origem_ref_id corretamente');
    $check(count($detAe['origens']) === 1 && $detAe['origens'][0]['origem_tipo'] === 'avaliacao_experiencia', '(7) pdi_origens também registra o vínculo (rastreabilidade adicional)');
    $check(!empty($detAe['origens'][0]['contexto_snapshot']), '(8) contexto_snapshot preserva o texto selecionado no momento da criação');

    $reversoAe = $pdiRepo->pdisPorOrigem('avaliacao_experiencia', $idAe);
    $check(count($reversoAe) === 1 && (int)$reversoAe[0]['id'] === $idPdiAe, '(9) Navegação REVERSA: pdisPorOrigem() encontra o PDI a partir do documento');

    $avAeDepois = (new AvaliacaoExperienciaRepository())->buscarPorId($idAe);
    $check($avAeDepois['feedback_geral'] === 'ZZPO feedback geral do gestor sobre o período.', '(10) Avaliação de Experiência original NUNCA é alterada pelo vínculo ao PDI');

    // ============================================================= 2) Feedback → PDI
    $rFb = $fbSvc->criar([
        'metadados_id' => (string)$contratoA, 'gestor_usuario_id' => (string)$gestorAId, 'tipo' => 'desenvolvimento', 'data_feedback' => '2026-06-10',
        'pontos_desenvolvimento' => 'ZZPO precisa melhorar comunicação com outras áreas.',
        'valor_respeito' => 'atende', 'comentario_respeito' => '',
        'valor_honestidade' => 'desenvolvimento_necessario', 'comentario_honestidade' => 'ZZPO precisa ser mais transparente nos relatos.',
        'valor_lealdade' => 'atende', 'comentario_lealdade' => '',
        'valor_etica' => 'atende', 'comentario_etica' => '',
        'valor_coragem' => 'atende', 'comentario_coragem' => '',
        'valor_ousadia' => 'atende', 'comentario_ousadia' => '',
        'proximos_passos' => 'ZZPO Participar de treinamento de comunicação.',
    ], $atorGestorA, $hoje, null);
    $idFb = (int)$rFb['id'];
    $check($pdiSvc->contextoOrigem('feedback', $idFb, $atorGestorA) === null, '(11) Feedback em RASCUNHO não pode alimentar PDI');
    $fbSvc->concluir($idFb, ['resultado_geral' => 'em_desenvolvimento', 'observacoes_finais' => 'ZZPO obs finais.'], $atorGestorA, $hoje, null);

    $ctxFb = $pdiSvc->contextoOrigem('feedback', $idFb, $atorGestorA);
    $check($ctxFb !== null, '(12) Feedback concluído expõe contexto');
    $temDesenvolvimentoNecessario = false;
    foreach ($ctxFb['itens'] as $it) {
        if (str_contains($it['texto'], 'Desenvolvimento Necessário')) {
            $temDesenvolvimentoNecessario = true;
        }
    }
    $check($temDesenvolvimentoNecessario, '(13) Valor marcado "Desenvolvimento Necessário" com comentário aparece como candidato');
    $check(count(array_filter($ctxFb['itens'], static fn(array $i): bool => str_starts_with($i['chave'], 'proximos_passos'))) === 1, '(14) Próximos passos também aparece como candidato');

    // "adicionar a PDI existente" — adiciona o Feedback ao MESMO PDI criado a partir da Avaliação de Experiência
    $rVincularFb = $pdiSvc->vincularOrigem($idPdiAe, 'feedback', $idFb, $atorGestorA, $hoje, '127.0.0.1', false, array_column($ctxFb['itens'], 'chave'));
    $check($rVincularFb['ok'] ?? false, '(15) vincularOrigem() "adicionar a PDI existente" funciona');

    $fbDepois = (new FeedbackRepository())->buscarPorId($idFb);
    $check($fbDepois['pontos_desenvolvimento'] === 'ZZPO precisa melhorar comunicação com outras áreas.', '(16) Feedback original NUNCA é alterado pelo vínculo');

    // ============================================================= 3) Desempenho → PDI
    $rAd = $adSvc->criar([
        'metadados_id' => (string)$contratoA, 'gestor_usuario_id' => (string)$gestorAId, 'ciclo' => 'ZZPO 2026.1', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30',
        'criterios' => [1 => ['competencia_texto' => 'ZZPO Comunicação', 'nota_atual' => '2', 'nota_esperada' => '4', 'comentario' => 'Precisa evoluir.']],
    ], $atorGestorA, $hoje, null);
    $idAd = (int)$rAd['id'];
    $check($pdiSvc->contextoOrigem('avaliacao_desempenho', $idAd, $atorGestorA) === null, '(17) Avaliação de Desempenho em RASCUNHO não pode alimentar PDI');
    $adSvc->concluir($idAd, ['resultado_final' => 'atende_parcialmente'], $atorGestorA, $hoje, null);

    $ctxAd = $pdiSvc->contextoOrigem('avaliacao_desempenho', $idAd, $atorGestorA);
    $check($ctxAd !== null, '(18) Avaliação de Desempenho concluída expõe contexto');
    $check(str_contains($ctxAd['itens'][0]['texto'] ?? '', 'GAP 2'), '(19) GAP derivado (esperada 4 - atual 2 = 2) aparece como indicador no texto — nunca recalculado/persistido à parte');

    // múltiplas origens (§6/§35): adiciona a Avaliação de Desempenho ao MESMO PDI
    $rVincularAd = $pdiSvc->vincularOrigem($idPdiAe, 'avaliacao_desempenho', $idAd, $atorGestorA, $hoje, '127.0.0.1', false, array_column($ctxAd['itens'], 'chave'));
    $check($rVincularAd['ok'] ?? false, '(20) Avaliação de Desempenho vinculada ao MESMO PDI que já tinha Experiência + Feedback');

    $detFinal = $pdiSvc->detalhe($idPdiAe, $atorGestorA, $hoje);
    $check(count($detFinal['origens']) === 3, '(21) [MÚLTIPLAS ORIGENS] O PDI agora tem 3 origens distintas (Experiência + Feedback + Desempenho)');
    $tiposOrigens = array_column($detFinal['origens'], 'origem_tipo');
    $check(in_array('avaliacao_experiencia', $tiposOrigens, true) && in_array('feedback', $tiposOrigens, true) && in_array('avaliacao_desempenho', $tiposOrigens, true), '(22) As 3 origens são de tipos diferentes, todas visíveis no mesmo PDI');

    $avAdDepois = (new AvaliacaoDesempenhoRepository())->buscarPorId($idAd);
    $check($avAdDepois['status'] === 'concluido' && $avAdDepois['resultado_final'] === 'atende_parcialmente', '(23) Avaliação de Desempenho original intacta após o vínculo');

    // ============================================================= 4) Prevenção de duplicidade (§16)
    $rDup = $pdiSvc->vincularOrigem($idPdiAe, 'feedback', $idFb, $atorGestorA, $hoje, null);
    $check(($rDup['ok'] ?? true) === false, '(24) Vincular o MESMO Feedback ao MESMO PDI de novo é recusado (sem duplicidade silenciosa)');
    $check(count($pdiSvc->detalhe($idPdiAe, $atorGestorA, $hoje)['origens']) === 3, '(25) Continuam exatamente 3 origens após a tentativa de duplicação');

    // O MESMO Feedback pode alimentar um PDI DIFERENTE (não há UNIQUE em origem sozinha)
    $rCriarFbOutro = $pdiSvc->criarDeOrigem('feedback', $idFb, [], $atorGestorA, $hoje, null);
    $check($rCriarFbOutro['ok'] ?? false, '(26) O MESMO Feedback pode alimentar um PDI DIFERENTE (sem limite 1:1 artificial)');
    $criados['pdis'][] = (int)$rCriarFbOutro['id'];

    // ============================================================= 5) IDOR (§24)
    $pdiSoDeA = $pdiSvc->detalhe($idPdiAe, $atorGestorB, $hoje);
    $check($pdiSoDeA === null, '(27) [IDOR] Gestor B não acessa o PDI do Gestor A (escopo por linha já existente, preservado)');

    $rVincularIdorPdi = $pdiSvc->vincularOrigem($idPdiAe, 'feedback', $idFb, $atorGestorB, $hoje, null);
    $check(($rVincularIdorPdi['ok'] ?? true) === false, '(28) [IDOR] Gestor B não consegue vincular origem a um PDI que não é seu, mesmo sabendo o id');

    // Colaborador B com um Feedback concluído próprio — Gestor A tenta vincular ao PDI do Gestor A (cross-colaborador)
    $rFbB = $fbSvc->criar(['metadados_id' => (string)$contratoB, 'gestor_usuario_id' => (string)$gestorBId, 'tipo' => 'reconhecimento', 'data_feedback' => '2026-06-10'], $atorGestorB, $hoje, null);
    $idFbB = (int)$rFbB['id'];
    $fbSvc->concluir($idFbB, ['resultado_geral' => 'reconhecido_alinhado'], $atorGestorB, $hoje, null);
    $rCrossColaborador = $pdiSvc->vincularOrigem($idPdiAe, 'feedback', $idFbB, $atorGestorB, $hoje, null);
    $check(($rCrossColaborador['ok'] ?? true) === false, '(29) [IDOR] Gestor B não acessa o PDI do colaborador A de qualquer forma (nem para vincular a origem do próprio colaborador B)');

    // trocar origem_ref_id no "POST" para um documento de outro colaborador, mesmo como o DONO do PDI (Gestor A)
    $rCrossNoProprioPdi = $pdiSvc->vincularOrigem($idPdiAe, 'feedback', $idFbB, $atorGestorA, $hoje, null);
    $check(($rCrossNoProprioPdi['ok'] ?? true) === false, '(30) [IDOR] Mesmo o dono do PDI não consegue vincular uma origem de OUTRO colaborador (metadados_id não bate)');

    $rOrigemInacessivel = $pdiSvc->contextoOrigem('feedback', 999999999, $atorGestorA);
    $check($rOrigemInacessivel === null, '(31) [IDOR] origem_ref_id inexistente/forjado nunca resolve contexto');

    // ============================================================= 6) PDI manual continua intacto (§27/§36)
    $rManual = $pdiSvc->criar([
        'metadados_id' => (string)$contratoA, 'gestor_usuario_id' => (string)$gestorAId, 'origem_tipo' => 'desenvolvimento_carreira',
        'data_abertura' => '2026-01-01', 'data_prevista_conclusao' => '2026-06-30',
    ], $atorGestorA, $hoje, null);
    $check($rManual['ok'] ?? false, '(32) PDI manual (origem_tipo=desenvolvimento_carreira, sem origem_ref) continua sendo criado normalmente');
    $idPdiManual = (int)$rManual['id'];
    $criados['pdis'][] = $idPdiManual;
    $detManual = $pdiSvc->detalhe($idPdiManual, $atorGestorA, $hoje);
    $check($detManual['origens'] === [], '(33) PDI manual não tem NENHUMA linha em pdi_origens');
    $check($detManual['pdi']['origem_tipo'] === 'desenvolvimento_carreira' && $detManual['pdi']['origem_ref_id'] === null, '(34) origem_tipo/origem_ref_id do PDI manual preservados exatamente como no fluxo antigo');

    // ============================================================= 7) Auditoria
    $eventosOrigem = array_values(array_filter($pdiRepo->eventos($idPdiAe), static fn(array $e): bool => $e['tipo_evento'] === 'origem_vinculada'));
    $check(count($eventosOrigem) === 3, '(35) Evento "origem_vinculada" registrado para as 3 origens (inclusive a fundadora, gravada via criarDeOrigem())');

    // ============================================================= 8) pdisDoContrato (picker "adicionar a existente")
    $lista = $pdiSvc->pdisDoContrato($contratoA, $atorGestorA);
    $check(count($lista) >= 2, '(36) pdisDoContrato() lista os PDIs do colaborador A no escopo do Gestor A');
    $listaB = $pdiSvc->pdisDoContrato($contratoA, $atorGestorB);
    $check($listaB === [], '(37) [IDOR] Gestor B não vê nenhum PDI do colaborador A em pdisDoContrato()');

    echo $falhas === [] ? "\nPDI_ORIGENS_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    if (!empty($criados['pdis'])) {
        $inPdi = implode(',', array_map('intval', $criados['pdis']));
        $pdo->exec("DELETE FROM pdi_eventos WHERE pdi_id IN ($inPdi)");
        $pdo->exec("DELETE FROM pdi_origens WHERE pdi_id IN ($inPdi)");
        $pdo->exec("DELETE FROM pdi_acoes WHERE pdi_id IN ($inPdi)");
        $pdo->exec("DELETE FROM pdi_competencias WHERE pdi_id IN ($inPdi)");
        $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE pdi_id IN ($inPdi)");
        $pdo->exec("DELETE FROM pdis WHERE id IN ($inPdi)");
    }
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $ids->execute($criados['metadados']);
        $metadadosIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($metadadosIds !== []) {
            $in = implode(',', $metadadosIds);
            $avIds = array_map('intval', $pdo->query("SELECT id FROM avaliacoes_experiencia WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($avIds !== []) {
                $inAv = implode(',', $avIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_experiencia_criterios WHERE avaliacao_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_experiencia WHERE id IN ($inAv)");
            }
            $fbIds = array_map('intval', $pdo->query("SELECT id FROM feedbacks WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($fbIds !== []) {
                $inFb = implode(',', $fbIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'feedback' AND documento_id IN ($inFb)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'feedback' AND documento_id IN ($inFb)");
                $pdo->exec("DELETE FROM feedback_valores WHERE feedback_id IN ($inFb)");
                $pdo->exec("DELETE FROM feedbacks WHERE id IN ($inFb)");
            }
            $adIds = array_map('intval', $pdo->query("SELECT id FROM avaliacoes_desempenho WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($adIds !== []) {
                $inAd = implode(',', $adIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ($inAd)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ($inAd)");
                $pdo->exec("DELETE FROM avaliacoes_desempenho_criterios WHERE avaliacao_id IN ($inAd)");
                $pdo->exec("DELETE FROM avaliacoes_desempenho WHERE id IN ($inAd)");
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
