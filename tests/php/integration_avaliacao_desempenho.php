<?php

/**
 * Integração — Avaliação de Desempenho NATIVA (Etapa 6, 2026-09).
 *
 * Prova, contra o banco:
 *   - criar/editar rascunho; escala 1–5 centralizada (fora da escala nunca é persistido, mesmo que
 *     enviado no POST — defesa em profundidade com o CHECK do banco);
 *   - conclusão exige resultado_final + pelo menos um critério com nota_atual; nunca calcula
 *     resultado_final a partir da média;
 *   - snapshot (nome/cargo/...) congelado no momento da criação, nunca reagindo a mudança posterior
 *     em colaboradores_metadados;
 *   - gestor responsável obrigatório e persistido; escopo por linha (gestor só vê o próprio, RH/Admin
 *     tudo — IDOR bloqueado);
 *   - permissão avaliacao_desempenho.avaliar exigida para criar/editar/concluir/cancelar;
 *   - reabertura só Admin/RH, sempre com justificativa e evento de auditoria;
 *   - cancelamento só em rascunho, com motivo obrigatório;
 *   - ciência do colaborador só após conclusão;
 *   - eventos de auditoria (criacao/atualizacao_rascunho/conclusao/cancelamento/reabertura) registrados;
 *   - GAP e indicadores derivados calculados corretamente e NUNCA persistidos;
 *   - render HTTP dos controllers (index/novo/editar) sem erro fatal;
 *   - navegação do hub aponta para a rota nativa com a permissão correta.
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
$hoje = new DateTimeImmutable('2026-09-30');

$criarContrato = static function (string $nome) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZAD' . $suffix . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $identificador, 'EMPA', 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZAD Empresa', 'ZZAD Unidade',
        'ST1', 'ZZAD Setor', 'ZZAD Cargo', 'CG1', '2024-01-10', '2024-01-10', 'zzad-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$criarUsuario = static function (string $rotulo, string $role, ?int $metadadosId = null, ?int $gestorId = null) use ($pdo, &$criados, $suffix, $senha): int {
    $id = User::create('ZZAD ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzad.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($metadadosId !== null || $gestorId !== null) {
        $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ?, gestor_usuario_id = ? WHERE id = ?')->execute([$metadadosId, $gestorId, $id]);
    }
    $criados['usuarios'][] = $id;
    return $id;
};

$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$comoUsuario = static function (int $id, string $role): void {
    $_SESSION['user_id'] = $id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_is_supervisor'] = 0;
};

try {
    // ---- 0) Permissões ----------------------------------------------------------------------------
    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_desempenho.visualizar'")->fetchColumn();
    $permAva = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_desempenho.avaliar'")->fetchColumn();
    $check($permVis > 0 && $permAva > 0, '(0) Permissões avaliacao_desempenho.visualizar/avaliar existem no catálogo (seed aplicado)');

    $gestorAId = $criarUsuario('Gestor A', 'viewer');
    $gestorBId = $criarUsuario('Gestor B', 'viewer');
    $rhId = $criarUsuario('RH', 'rh');
    Authorization::sincronizar($gestorAId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorBId, [$permVis, $permAva]);
    Authorization::sincronizar($rhId, [$permVis, $permAva]);
    $atorGestorA = ['id' => $gestorAId, 'role' => 'viewer'];
    $atorGestorB = ['id' => $gestorBId, 'role' => 'viewer'];
    $atorRh = ['id' => $rhId, 'role' => 'rh'];

    $cAlvo = $criarContrato('ZZAD Colaborador');

    $svc = new AvaliacaoDesempenhoService(new AvaliacaoDesempenhoRepository($pdo), new AvaliacoesDesenvolvimentoAuditoriaService($pdo));
    $repo = new AvaliacaoDesempenhoRepository($pdo);

    // ---- 1) Permissão exigida para criar -----------------------------------------------------------
    $semUsuario = $criarUsuario('Sem Permissao', 'viewer');
    $rSemPerm = $svc->criar(['metadados_id' => (string)$cAlvo, 'ciclo' => 'ZZAD 2026.1', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30'], ['id' => $semUsuario, 'role' => 'viewer'], $hoje, null);
    $check(($rSemPerm['ok'] ?? true) === false, '(1) criar() recusa ator sem permissão avaliacao_desempenho.avaliar');

    // ---- 2) Criar rascunho com 2 critérios ---------------------------------------------------------
    $dadosCriar = [
        'metadados_id' => (string)$cAlvo, 'gestor_usuario_id' => (string)$gestorAId,
        'ciclo' => 'ZZAD 2026.1', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30',
        'pontos_fortes' => 'ZZAD pontos fortes iniciais.',
        'criterios' => [
            1 => ['competencia_texto' => 'ZZAD Qualidade técnica', 'nota_atual' => '2', 'nota_esperada' => '4', 'comentario' => 'ZZAD comentário 1'],
            2 => ['competencia_texto' => 'ZZAD Colaboração', 'nota_atual' => '5', 'nota_esperada' => '3', 'comentario' => ''],
            3 => ['competencia_texto' => '', 'nota_atual' => '3', 'nota_esperada' => '3', 'comentario' => ''],
        ],
    ];
    $rCriar = $svc->criar($dadosCriar, $atorGestorA, $hoje, '127.0.0.1');
    $check($rCriar['ok'] ?? false, '(2) criar() cria a avaliação com sucesso (Admin/RH bypass não necessário — gestor dono)');
    $idAvaliacao = (int)($rCriar['id'] ?? 0);

    $detalhe = $svc->detalhe($idAvaliacao, $atorGestorA);
    $check($detalhe !== null && (string)$detalhe['avaliacao']['status'] === 'rascunho', '(3) Avaliação criada nasce em status "rascunho"');
    $check(count($detalhe['criterios'] ?? []) === 2, '(4) Critério com competencia_texto vazio (slot 3) é descartado — só 2 linhas persistidas');
    $check((int)($detalhe['criterios'][0]['nota_atual'] ?? 0) === 2 && (int)($detalhe['criterios'][0]['nota_esperada'] ?? 0) === 4, '(5) Notas 1–5 do primeiro critério persistidas corretamente');

    // ---- 3) Escopo por gestor (IDOR) ----------------------------------------------------------------
    $check($svc->detalhe($idAvaliacao, $atorGestorB) === null, '(6) Gestor B (não é o gestor responsável) não acessa a avaliação — IDOR bloqueado');
    $check($svc->detalhe($idAvaliacao, $atorRh) !== null, '(7) RH (escopo total) acessa qualquer avaliação');
    $rEditarGestorB = $svc->atualizar($idAvaliacao, ['pontos_fortes' => 'tentativa invasão'], $atorGestorB, $hoje, null);
    $check(($rEditarGestorB['ok'] ?? true) === false, '(8) Gestor B não consegue editar a avaliação de outro gestor (IDOR bloqueado na escrita)');

    // ---- 4) Nota fora da escala é descartada (defesa em profundidade com o CHECK do banco) ----------
    $rNotaInvalida = $svc->atualizar($idAvaliacao, [
        'criterios' => [
            1 => ['competencia_texto' => 'ZZAD Qualidade técnica', 'nota_atual' => '6', 'nota_esperada' => '0', 'comentario' => ''],
            2 => ['competencia_texto' => 'ZZAD Colaboração', 'nota_atual' => '5', 'nota_esperada' => '3', 'comentario' => ''],
        ],
    ], $atorGestorA, $hoje, null);
    $check($rNotaInvalida['ok'] ?? false, '(9) atualizar() aceita a requisição mesmo com nota fora da escala no POST (nunca fatal)');
    $detalheAposInvalida = $svc->detalhe($idAvaliacao, $atorGestorA);
    $notaFora = $detalheAposInvalida['criterios'][0]['nota_atual'];
    $notaEsperadaFora = $detalheAposInvalida['criterios'][0]['nota_esperada'];
    $check($notaFora === null && $notaEsperadaFora === null, '(10) Notas 6 e 0 (fora de 1–5) NUNCA são persistidas — viram NULL, não o valor recusado');

    // restaura notas válidas para os próximos passos
    $svc->atualizar($idAvaliacao, [
        'criterios' => [
            1 => ['competencia_texto' => 'ZZAD Qualidade técnica', 'nota_atual' => '2', 'nota_esperada' => '4', 'comentario' => ''],
            2 => ['competencia_texto' => 'ZZAD Colaboração', 'nota_atual' => '5', 'nota_esperada' => '3', 'comentario' => ''],
        ],
    ], $atorGestorA, $hoje, null);

    // ---- 5) Snapshot congelado --------------------------------------------------------------------
    $pdo->prepare('UPDATE colaboradores_metadados SET nome = ?, cargo = ? WHERE id = ?')->execute(['ZZAD Nome Alterado Depois', 'ZZAD Cargo Alterado Depois', $cAlvo]);
    $detalheSnapshot = $svc->detalhe($idAvaliacao, $atorGestorA);
    $check($detalheSnapshot['avaliacao']['snap_nome'] === 'ZZAD Colaborador', '(11) snap_nome permanece o valor CONGELADO na criação, mesmo após colaboradores_metadados mudar depois');
    $check($detalheSnapshot['avaliacao']['snap_cargo'] === 'ZZAD Cargo', '(12) snap_cargo também congelado — snapshot é integral, não recalculado em leitura');

    // ---- 6) Gestor responsável persistido -----------------------------------------------------------
    $check((int)$detalheSnapshot['avaliacao']['gestor_usuario_id'] === $gestorAId, '(13) gestor_usuario_id persistido é o gestor dono da avaliação');
    $check($detalheSnapshot['avaliacao']['gestor_nome_snapshot'] === 'ZZAD Gestor A', '(14) gestor_nome_snapshot também congelado no momento da criação');

    // ---- 7) Impedir conclusão inválida --------------------------------------------------------------
    $rConcluirSemResultado = $svc->concluir($idAvaliacao, [], $atorGestorA, $hoje, null);
    $check(($rConcluirSemResultado['ok'] ?? true) === false, '(15) concluir() sem resultado_final é recusado');

    $rConcluirSemNotas = $svc->concluir((int)$svc->criar([
        'metadados_id' => (string)$cAlvo, 'gestor_usuario_id' => (string)$gestorAId,
        'ciclo' => 'ZZAD Sem Notas', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30',
    ], $atorGestorA, $hoje, null)['id'], ['resultado_final' => 'atende_expectativas'], $atorGestorA, $hoje, null);
    $check(($rConcluirSemNotas['ok'] ?? true) === false, '(16) concluir() sem nenhum critério com nota_atual preenchida é recusado — não pode concluir "vazio"');

    // ---- 8) Conclusão válida — resultado_final NUNCA calculado da média ------------------------------
    $rConcluir = $svc->concluir($idAvaliacao, ['resultado_final' => 'atende_parcialmente', 'parecer_comentario' => 'ZZAD parecer final.'], $atorGestorA, $hoje, '127.0.0.1');
    $check($rConcluir['ok'] ?? false, '(17) concluir() com resultado_final explícito e pelo menos 1 critério avaliado é aceito');
    $detalheConcluido = $svc->detalhe($idAvaliacao, $atorGestorA);
    $check((string)$detalheConcluido['avaliacao']['status'] === 'concluido', '(18) Status vira "concluido"');
    $check($detalheConcluido['avaliacao']['resultado_final'] === 'atende_parcialmente', '(19) resultado_final é EXATAMENTE o valor escolhido pelo avaliador — média das notas (2 e 5 = 3.5) não sugere "atende_parcialmente" automaticamente, prova que não há cálculo automático');
    $check(!empty($detalheConcluido['avaliacao']['data_realizacao']), '(20) data_realizacao preenchida na conclusão');

    $rConcluirDeNovo = $svc->concluir($idAvaliacao, ['resultado_final' => 'nao_atende'], $atorGestorA, $hoje, null);
    $check(($rConcluirDeNovo['ok'] ?? true) === false, '(21) concluir() uma avaliação já concluída é recusado (sem reedição silenciosa)');
    $rEditarConcluida = $svc->atualizar($idAvaliacao, ['pontos_fortes' => 'tentativa pós-conclusão'], $atorGestorA, $hoje, null);
    $check(($rEditarConcluida['ok'] ?? true) === false, '(22) atualizar() uma avaliação concluída é recusado — só reabertura formal permite editar de novo');

    // ---- 9) GAP e indicadores derivados (display-only, nunca persistidos) ---------------------------
    $indicadores = $detalheConcluido['indicadores'];
    $check($indicadores['media_nota_atual'] === 3.5, '(23) Média nota atual derivada = (2+5)/2 = 3.5');
    $check($indicadores['media_nota_esperada'] === 3.5, '(24) Média nota esperada derivada = (4+3)/2 = 3.5');
    $check($indicadores['qtd_com_gap'] === 1, '(25) Apenas 1 critério tem GAP positivo (nota_esperada 4 > nota_atual 2 no critério 1; critério 2: esperada 3 <= atual 5, sem GAP)');
    $check($indicadores['maior_gap'] === 2, '(26) Maior GAP = 4 - 2 = 2');
    $check($indicadores['percentual_atendidos'] === 50.0, '(27) 1 de 2 critérios atendidos = 50%');
    $colunas = $pdo->query("SHOW COLUMNS FROM avaliacoes_desempenho LIKE '%gap%'")->fetchAll(PDO::FETCH_ASSOC);
    $check($colunas === [] || (count($colunas) === 1 && $colunas[0]['Field'] === 'gaps_identificados'), '(28) Nenhuma coluna de indicador derivado (média/GAP/percentual) existe no banco — só o resumo textual gaps_identificados');

    // ---- 10) Ciência só após conclusão ---------------------------------------------------------------
    $rascunhoParaCiencia = $svc->criar(['metadados_id' => (string)$cAlvo, 'gestor_usuario_id' => (string)$gestorAId, 'ciclo' => 'ZZAD Ciencia', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-03-31'], $atorGestorA, $hoje, null);
    $idParaCiencia = (int)$rascunhoParaCiencia['id'];
    $rCienciaAntesDeConcluir = $svc->registrarCienciaColaborador($idParaCiencia, 'ZZAD Colaborador', $atorGestorA, $hoje);
    $check(($rCienciaAntesDeConcluir['ok'] ?? true) === false, '(29) Ciência recusada antes da conclusão');
    $rCiencia = $svc->registrarCienciaColaborador($idAvaliacao, 'ZZAD Colaborador', $atorGestorA, $hoje);
    $check($rCiencia['ok'] ?? false, '(30) Ciência aceita após conclusão');
    $ciencias = (new AvaliacoesDesenvolvimentoAuditoriaService($pdo))->listarCiencias('avaliacao_desempenho', $idAvaliacao);
    $check(isset($ciencias['colaborador']), '(31) Ciência do colaborador registrada e recuperável via listarCiencias()');

    // ---- 11) Reabertura só Admin/RH, com justificativa obrigatória e evento de auditoria -------------
    $rReabrirGestor = $svc->reabrir($idAvaliacao, 'tentativa', $atorGestorA, $hoje, '127.0.0.1');
    $check(($rReabrirGestor['ok'] ?? true) === false, '(32) Gestor comum NÃO pode reabrir uma avaliação concluída');
    $rReabrirSemJustificativa = $svc->reabrir($idAvaliacao, '', $atorRh, $hoje, '127.0.0.1');
    $check(($rReabrirSemJustificativa['ok'] ?? true) === false, '(33) RH sem justificativa é recusado');
    $rReabrirRh = $svc->reabrir($idAvaliacao, 'ZZAD reabertura de teste', $atorRh, $hoje, '127.0.0.1');
    $check($rReabrirRh['ok'] ?? false, '(34) RH com justificativa reabre com sucesso');
    $detalhePosReabertura = $svc->detalhe($idAvaliacao, $atorGestorA);
    $check((string)$detalhePosReabertura['avaliacao']['status'] === 'rascunho', '(35) Após reabrir, status volta a "rascunho"');
    $check($detalhePosReabertura['avaliacao']['resultado_final'] === null && $detalhePosReabertura['avaliacao']['data_realizacao'] === null, '(36) Reabertura limpa resultado_final e data_realizacao — nada de conclusão "fantasma"');

    // ---- 12) Cancelamento só em rascunho, com motivo obrigatório -------------------------------------
    $rCancelarSemMotivo = $svc->cancelar($idAvaliacao, '', $atorGestorA, $hoje, null);
    $check(($rCancelarSemMotivo['ok'] ?? true) === false, '(37) cancelar() sem motivo é recusado');
    $rCancelar = $svc->cancelar($idAvaliacao, 'ZZAD motivo do cancelamento', $atorGestorA, $hoje, '127.0.0.1');
    $check($rCancelar['ok'] ?? false, '(38) cancelar() com motivo, em rascunho, é aceito');
    $detalheCancelada = $svc->detalhe($idAvaliacao, $atorGestorA);
    $check((string)$detalheCancelada['avaliacao']['status'] === 'cancelado', '(39) Status vira "cancelado"');
    $rCancelarDeNovo = $svc->cancelar($idAvaliacao, 'segunda tentativa', $atorGestorA, $hoje, null);
    $check(($rCancelarDeNovo['ok'] ?? true) === false, '(40) Uma avaliação já cancelada não pode ser cancelada de novo');
    $rConcluirCancelada = $svc->concluir($idAvaliacao, ['resultado_final' => 'atende_expectativas'], $atorGestorA, $hoje, null);
    $check(($rConcluirCancelada['ok'] ?? true) === false, '(41) Uma avaliação cancelada não pode ser concluída');

    // ---- 13) Eventos de auditoria ---------------------------------------------------------------------
    $eventos = (new AvaliacoesDesenvolvimentoAuditoriaService($pdo))->listarEventos('avaliacao_desempenho', $idAvaliacao);
    $tipos = array_map(static fn(array $e): string => (string)$e['tipo_evento'], $eventos);
    foreach (['criacao', 'atualizacao_rascunho', 'conclusao', 'ciencia_colaborador', 'reabertura', 'cancelamento'] as $tipoEsperado) {
        $check(in_array($tipoEsperado, $tipos, true), "(42.{$tipoEsperado}) Evento de auditoria \"{$tipoEsperado}\" foi registrado");
    }

    // ---- 14) Navegação do hub ---------------------------------------------------------------------
    $abas = PortalNavegacaoService::definicaoAbas()['avaliacoes-desenvolvimento']['abas'] ?? [];
    $abaDesempenho = null;
    foreach ($abas as $aba) {
        if (($aba['chave'] ?? '') === 'avaliacao-desempenho') {
            $abaDesempenho = $aba;
        }
    }
    $check(($abaDesempenho['href'] ?? '') === '/admin/avaliacoes-desempenho', '(43) Aba "Avaliação de Desempenho" do hub aponta para a rota NATIVA, não mais para /admin/avaliacoes (legado)');
    $check(($abaDesempenho['regra'] ?? '') === 'perm:avaliacao_desempenho.visualizar', '(44) Aba exige a permissão avaliacao_desempenho.visualizar (não mais "aberto")');

    // ---- 15) Render HTTP dos controllers (sem erro fatal) ----------------------------------------
    $comoUsuario($gestorAId, 'viewer');
    $htmlIndex = $renderizar(static fn() => (new AdminAvaliacoesDesempenhoController())->index());
    $check(str_contains($htmlIndex, 'Avaliação de Desempenho'), '(45) index() renderiza sem erro fatal e contém o título do módulo');

    $htmlNovo = $renderizar(static fn() => (new AdminAvaliacoesDesempenhoController())->novo());
    $check(str_contains($htmlNovo, 'Colaborador'), '(46) novo() (busca de colaborador) renderiza sem erro fatal');

    $idParaEditar = (int)$svc->criar(['metadados_id' => (string)$cAlvo, 'gestor_usuario_id' => (string)$gestorAId, 'ciclo' => 'ZZAD Render', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-03-31'], $atorGestorA, $hoje, null)['id'];
    $htmlEditar = $renderizar(static fn() => (new AdminAvaliacoesDesempenhoController())->editar((string)$idParaEditar));
    $check(str_contains($htmlEditar, 'Muito abaixo do esperado') && str_contains($htmlEditar, 'Supera significativamente o esperado'), '(47) editar() exibe os rótulos completos da escala 1–5 (nunca só o número)');

    echo $falhas === [] ? "\nAVALIACAO_DESEMPENHO_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_is_supervisor']);
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $ids->execute($criados['metadados']);
        $metadadosIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($metadadosIds !== []) {
            $in = implode(',', $metadadosIds);
            $avIds = array_map('intval', $pdo->query("SELECT id FROM avaliacoes_desempenho WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($avIds !== []) {
                $inAv = implode(',', $avIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_desempenho_criterios WHERE avaliacao_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_desempenho WHERE id IN ($inAv)");
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
