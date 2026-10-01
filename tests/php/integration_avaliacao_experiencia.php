<?php

/**
 * Integração — Avaliação do Período de Experiência (45/90 dias — Etapa 4, 2026-09).
 *
 * Prova que:
 *   - status derivado (futura/pendente/vencida/em_preenchimento/realizada/aguardando_ciencia/
 *     não aplicável — desligado) bate com a janela de datas (antecedência de alerta = 5 dias);
 *   - transferência contínua (ausente_na_origem=1) nunca duplica a pessoa na lista de pendências;
 *   - colaborador desligado ANTES do prazo, sem avaliação iniciada, vira "não aplicável", nunca
 *     "vencida" indefinidamente;
 *   - notas 1–5 dos 24 critérios persistem e são lidas de volta corretamente;
 *   - parecer final exige justificativa para todos os pareceres, exceto "Apto para efetivação";
 *   - salvar rascunho / concluir / ciência / reabrir seguem a máquina de estados esperada;
 *   - escopo por linha (gestor responsável) — Admin/RH veem tudo, gestor sem vínculo é bloqueado
 *     (IDOR), mesmo padrão de PdiService.
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
$hoje = new DateTimeImmutable('2026-06-15');

$dia = static fn(int $offset): string => $hoje->modify($offset . ' days')->format('Y-m-d');

$criarContrato = static function (string $nome, ?string $admissao, ?string $demissao, string $empresa = 'EMPA', bool $ausenteNaOrigem = false) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZAE' . $suffix . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados, ausente_na_origem
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $empresa, 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZAE Empresa', 'ZZAE Unidade',
        'ST1', 'ZZAE Setor', 'ZZAE Cargo', 'CG1', $admissao, $admissao, $demissao, $demissao !== null ? '003' : null,
        $demissao === null ? 1 : 0, 'zzae-teste', $ausenteNaOrigem ? 1 : 0,
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$criarUsuario = static function (string $rotulo, string $role, ?int $metadadosId = null, ?int $gestorId = null) use ($pdo, &$criados, $suffix, $senha): int {
    $id = User::create('ZZAE ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzae.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($metadadosId !== null || $gestorId !== null) {
        $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ?, gestor_usuario_id = ? WHERE id = ?')->execute([$metadadosId, $gestorId, $id]);
    }
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    // ---- 0) Permissões e atores ---------------------------------------------------------------------
    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.visualizar'")->fetchColumn();
    $permAva = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.avaliar'")->fetchColumn();
    $check($permVis > 0 && $permAva > 0, '(0) Permissões avaliacao_experiencia.visualizar/avaliar existem no catálogo (seed aplicado)');

    $gestorAId = $criarUsuario('Gestor A', 'viewer');
    $gestorBId = $criarUsuario('Gestor B', 'viewer');
    $rhId = $criarUsuario('RH', 'rh');
    Authorization::sincronizar($gestorAId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorBId, [$permVis, $permAva]);
    Authorization::sincronizar($rhId, [$permVis, $permAva]);
    $atorGestorA = ['id' => $gestorAId, 'role' => 'viewer'];
    $atorGestorB = ['id' => $gestorBId, 'role' => 'viewer'];
    $atorRh = ['id' => $rhId, 'role' => 'rh'];

    // ---- 1) Contratos cobrindo cada status derivado ---------------------------------------------------
    $cFutura = $criarContrato('ZZAE Futura', $dia(-10), null);
    $cPendente = $criarContrato('ZZAE Pendente', $dia(-42), null);
    $cVencida = $criarContrato('ZZAE Vencida', $dia(-75), null);
    $cDesligadoAntes = $criarContrato('ZZAE Desligado Antes', $dia(-75), $dia(-45));
    $cOrigemContinua = $criarContrato('ZZAE Origem Continua', $dia(-75), null, 'EMPA', true);
    $cRealizada = $criarContrato('ZZAE Realizada', $dia(-75), null);

    foreach ([$cFutura, $cPendente, $cVencida, $cDesligadoAntes, $cOrigemContinua, $cRealizada] as $m) {
        $criarUsuario('Vinculo ' . $m, 'viewer', $m, $gestorAId);
    }

    $svc = new AvaliacaoExperienciaService(new AvaliacaoExperienciaRepository($pdo), new AvaliacoesDesenvolvimentoAuditoriaService($pdo));

    // ---- 2) Derivação de status (45 dias) ---------------------------------------------------------------
    $lista = $svc->listarPendencias([], $atorGestorA, $hoje);
    $porMetadados = [];
    foreach ($lista['itens'] as $item) {
        if ($item['tipo'] === '45') {
            $porMetadados[(int)$item['metadados_id']] = $item;
        }
    }
    $check(($porMetadados[$cFutura]['status'] ?? null) === 'futura', '(1) Admissão há 10 dias (45 dias): FUTURA — fora da janela de alerta (5 dias antes do dia 45)');
    $check(($porMetadados[$cPendente]['status'] ?? null) === 'pendente', '(2) Admissão há 42 dias (45 dias): PENDENTE — dentro da janela de alerta (dia 40 a 45)');
    $check(($porMetadados[$cVencida]['status'] ?? null) === 'vencida', '(3) Admissão há 75 dias, sem avaliação: VENCIDA');
    $check(($porMetadados[$cDesligadoAntes]['status'] ?? null) === 'nao_aplicavel_desligado', '(4) Desligado ANTES do prazo de 45 dias, nunca avaliado: "Não aplicável — desligado antes do prazo", nunca "vencida" indefinidamente');
    $check(!isset($porMetadados[$cOrigemContinua]), '(5) Contrato ÓRFÃO de transferência contínua (ausente_na_origem=1) NUNCA aparece na lista — evita duplicar a pessoa');

    // ---- 3) Escopo por linha (gestor responsável) — IDOR ------------------------------------------------
    $listaGestorB = $svc->listarPendencias([], $atorGestorB, $hoje);
    $check($listaGestorB['itens'] === [], '(6) Gestor B (sem nenhum colaborador vinculado) não vê os colaboradores do Gestor A — escopo por linha');
    $listaRh = $svc->listarPendencias([], $atorRh, $hoje);
    $check(count($listaRh['itens']) >= count($lista['itens']), '(7) RH (escopo total) vê pelo menos os mesmos itens que o Gestor A');

    $ctxSemAcesso = $svc->contratoParaAvaliar($cFutura, '45', $atorGestorB);
    $check(($ctxSemAcesso['ok'] ?? true) === false, '(8) Gestor B não consegue abrir a avaliação de um colaborador do Gestor A (IDOR bloqueado)');
    $ctxComAcesso = $svc->contratoParaAvaliar($cFutura, '45', $atorGestorA);
    $check(($ctxComAcesso['ok'] ?? false) === true, '(8b) Gestor A (dono do vínculo) consegue abrir normalmente');

    // ---- 4) Salvar rascunho: notas 1–5 dos 24 critérios persistem ----------------------------------------
    $dadosRascunho = ['gestor_usuario_id' => (string)$gestorAId, 'tecnica_capacidade' => 'sim', 'adaptacao_nivel' => 'boa', 'feedback_geral' => 'ZZAE feedback geral.'];
    foreach (AvaliacaoExperienciaService::VALORES_CULTURAIS as $valor => $g) {
        for ($i = 1; $i <= 4; $i++) {
            $dadosRascunho['nota_' . $valor . '_' . $i] = (string)(($i % 5) + 1);
        }
        $dadosRascunho['valores_comentario_' . $valor] = 'Comentário ZZAE ' . $valor;
    }
    $rSalvar = $svc->salvarRascunho($cFutura, '45', $dadosRascunho, $atorGestorA, $hoje, '127.0.0.1');
    $check($rSalvar['ok'] ?? false, '(9) salvarRascunho() cria a avaliação com sucesso');
    $idAvaliacao = (int)($rSalvar['id'] ?? 0);

    $ctxReaberto = $svc->contratoParaAvaliar($cFutura, '45', $atorGestorA);
    $notaRespeito1 = $ctxReaberto['criterios']['respeito'][1] ?? null;
    $check($notaRespeito1 === 2, '(10) Nota do critério 1 de "respeito" persistida corretamente (1 % 5 + 1 = 2)');
    $check(($ctxReaberto['avaliacao']['status'] ?? null) === 'rascunho', '(11) Avaliação salva como rascunho fica com status "rascunho"');

    // status agora deve ser "em_preenchimento"
    $listaAposRascunho = $svc->listarPendencias([], $atorGestorA, $hoje);
    $itemAposRascunho = null;
    foreach ($listaAposRascunho['itens'] as $it) {
        if ((int)$it['metadados_id'] === $cFutura && $it['tipo'] === '45') {
            $itemAposRascunho = $it;
        }
    }
    $check(($itemAposRascunho['status'] ?? null) === 'em_preenchimento', '(12) Depois de salvar rascunho, o status derivado vira "em_preenchimento"');

    // ---- 5) Concluir: parecer + justificativa condicional -------------------------------------------------
    $rSemJustificativa = $svc->concluir($idAvaliacao, ['parecer' => 'prorrogacao_experiencia', 'parecer_justificativa' => ''], $atorGestorA, $hoje, '127.0.0.1');
    $check(($rSemJustificativa['ok'] ?? true) === false, '(13) Concluir com parecer "Necessita prorrogação" SEM justificativa é recusado pelo BACKEND (nunca confia só no frontend)');

    $rAptoSemJustificativa = $svc->concluir($idAvaliacao, ['parecer' => 'apto_efetivacao', 'parecer_justificativa' => ''], $atorGestorA, $hoje, '127.0.0.1');
    $check(($rAptoSemJustificativa['ok'] ?? false) === true, '(14) "Apto para efetivação" SEM justificativa é aceito (única exceção, §19)');

    $ctxConcluida = $svc->contratoParaAvaliar($cFutura, '45', $atorGestorA);
    $check(($ctxConcluida['avaliacao']['status'] ?? null) === 'concluido', '(15) Após concluir, status vira "concluido"');
    $check(($ctxConcluida['avaliacao']['parecer'] ?? null) === 'apto_efetivacao', '(16) Parecer registrado exatamente como informado — nenhuma efetivação automática é executada (§20/§21)');

    // ---- 5b) REGRESSÃO — concluir() com o payload REAL do <form> de encerramento (admin/avaliacoes-
    //          experiencia/form.php) NÃO PODE apagar notas/comentários dos valores culturais, avaliação
    //          técnica, adaptação nem feedback geral. Bug encontrado em 2026-09-30 (auditado junto com a
    //          Etapa 7 — Avaliações/Feedback → PDI): concluir() reconstruía esses campos a partir de
    //          $_POST via montarNotas($dados)/atualizar($id,[...]), mas o formulário de encerramento só
    //          envia csrf/parecer/parecer_justificativa — confirmado lendo o HTML do form (nenhum campo
    //          nota_*/valores_comentario_*/tecnica_*/adaptacao_*/feedback_geral existe nesse <form>).
    //          Corrigido: concluir() agora só altera parecer/parecer_justificativa/status/data_realizacao.
    //          Este teste falha com a implementação antiga (tudo viraria NULL) e passa com a corrigida.
    $check(($ctxConcluida['avaliacao']['feedback_geral'] ?? null) === 'ZZAE feedback geral.', '(16b) [REGRESSÃO] feedback_geral PERMANECE intacto após concluir() com payload real (não é apagado)');
    $check(($ctxConcluida['avaliacao']['tecnica_capacidade'] ?? null) === 'sim', '(16c) [REGRESSÃO] tecnica_capacidade PERMANECE intacto');
    $check(($ctxConcluida['avaliacao']['adaptacao_nivel'] ?? null) === 'boa', '(16d) [REGRESSÃO] adaptacao_nivel PERMANECE intacto');
    $check(($ctxConcluida['avaliacao']['valores_comentario_respeito'] ?? null) === 'Comentário ZZAE respeito', '(16e) [REGRESSÃO] valores_comentario_respeito PERMANECE intacto');
    $check(($ctxConcluida['criterios']['respeito'][1] ?? null) === 2, '(16f) [REGRESSÃO] nota do critério 1 de "respeito" PERMANECE intacta — concluir() não zera nem recalcula os 24 critérios');

    // status derivado agora: aguardando_ciencia (concluído, sem ciência do colaborador)
    $listaAposConcluir = $svc->listarPendencias([], $atorGestorA, $hoje);
    $itemAposConcluir = null;
    foreach ($listaAposConcluir['itens'] as $it) {
        if ((int)$it['metadados_id'] === $cFutura && $it['tipo'] === '45') {
            $itemAposConcluir = $it;
        }
    }
    $check(($itemAposConcluir['status'] ?? null) === 'aguardando_ciencia', '(17) Concluída sem ciência do colaborador: "aguardando_ciencia"');

    // ---- 6) Ciência do colaborador -----------------------------------------------------------------------
    $rCiencia = $svc->registrarCienciaColaborador($idAvaliacao, 'ZZAE Futura', $atorGestorA, $hoje);
    $check($rCiencia['ok'] ?? false, '(18) registrarCienciaColaborador() funciona para avaliação concluída');
    $listaAposCiencia = $svc->listarPendencias([], $atorGestorA, $hoje);
    $itemAposCiencia = null;
    foreach ($listaAposCiencia['itens'] as $it) {
        if ((int)$it['metadados_id'] === $cFutura && $it['tipo'] === '45') {
            $itemAposCiencia = $it;
        }
    }
    $check(($itemAposCiencia['status'] ?? null) === 'realizada', '(19) Com ciência do colaborador registrada, o status derivado vira "realizada"');

    // ---- 7) Reabertura (só Admin/RH) ------------------------------------------------------------------
    $rReabrirGestor = $svc->reabrir($idAvaliacao, 'tentativa', $atorGestorA, $hoje, '127.0.0.1');
    $check(($rReabrirGestor['ok'] ?? true) === false, '(20) Gestor (sem escopo total) NÃO pode reabrir uma avaliação concluída');
    $rReabrirSemJustificativa = $svc->reabrir($idAvaliacao, '', $atorRh, $hoje, '127.0.0.1');
    $check(($rReabrirSemJustificativa['ok'] ?? true) === false, '(21) RH sem justificativa é recusado');
    $rReabrirRh = $svc->reabrir($idAvaliacao, 'ZZAE reabertura de teste', $atorRh, $hoje, '127.0.0.1');
    $check($rReabrirRh['ok'] ?? false, '(22) RH com justificativa reabre com sucesso');
    $ctxReaberta = $svc->contratoParaAvaliar($cFutura, '45', $atorGestorA);
    $check(($ctxReaberta['avaliacao']['status'] ?? null) === 'rascunho', '(23) Após reabrir, status volta a "rascunho" — nunca edição silenciosa (§59)');

    // ---- 8) Avaliação realizada (fixture direta, para conferir "realizada" sem passar pelo fluxo acima) ----
    $rSalvarRealizada = $svc->salvarRascunho($cRealizada, '90', ['gestor_usuario_id' => (string)$gestorAId], $atorGestorA, $hoje, null);
    $idRealizada = (int)$rSalvarRealizada['id'];
    $svc->concluir($idRealizada, ['parecer' => 'apto_efetivacao'], $atorGestorA, $hoje, null);
    $listaTipo90 = $svc->listarPendencias(['tipo' => '90'], $atorGestorA, $hoje);
    $itemRealizada90 = null;
    foreach ($listaTipo90['itens'] as $it) {
        if ((int)$it['metadados_id'] === $cRealizada) {
            $itemRealizada90 = $it;
        }
    }
    $check(($itemRealizada90['status'] ?? null) === 'aguardando_ciencia', '(24) Avaliação de 90 dias concluída sem ciência: também deriva "aguardando_ciencia" (mesma regra do tipo 45)');
    $check($svc::TIPOS['90'] === '90 dias', '(25) Tipo 90 dias reconhecido pela mesma estrutura (§21 — mesma matriz de 45/90)');

    echo $falhas === [] ? "\nAVALIACAO_EXPERIENCIA_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
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
        }
        if ($metadadosIds !== []) {
            $inMeta = implode(',', $metadadosIds);
            $pdo->exec("UPDATE usuarios SET colaborador_metadados_id = NULL WHERE colaborador_metadados_id IN ($inMeta)");
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
