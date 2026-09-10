<?php

/**
 * Integração — Sprint "Usuários/Lideranças independentes de colaboradores" (migration 2026-09-09).
 *
 * Prova que:
 *   - a autorização para Solicitação de Vaga vive em `usuarios.pode_solicitar_vaga`;
 *   - a hierarquia de aprovação vive em `usuarios.aprovador_usuario_id`;
 *   - um usuário pode existir e operar sem linha em `colaboradores` nem vínculo METADADOS (gestor PJ);
 *   - o vínculo METADADOS é opcional, validado e único;
 *   - a identidade da solicitação é sempre `solicitante_usuario_id` da sessão;
 *   - nenhum fluxo materializa `colaboradores`.
 *
 * `SolicitacaoVaga::create()` roda DDL (commit implícito) + transação própria, então este teste
 * NÃO usa rollback: cria fixtures com marcador e as remove no finally, como integration_solicitacoes_vaga.php.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SolicitacaoVaga::ensureSchema();

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

$mk = 'ZZUVA_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['solicitacoes' => [], 'usuarios' => [], 'centros' => [], 'cargos' => [], 'setores' => [], 'metadados' => []];

$limparSolicitacao = static function (int $id) use ($pdo): void {
    foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
};

try {
    // ---- fixtures estruturais -------------------------------------------
    $pdo->prepare('INSERT INTO setores (nome, slug, ativo) VALUES (?, ?, 1)')->execute(['SETOR ' . $mk, 'setor-' . strtolower($mk)]);
    $setorId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorId;

    $pdo->prepare('INSERT INTO cargos (nome, slug, ativo) VALUES (?, ?, 1)')->execute(['CARGO ' . $mk, 'cargo-' . strtolower($mk)]);
    $cargoId = (int)$pdo->lastInsertId();
    $criados['cargos'][] = $cargoId;
    $pdo->prepare('INSERT IGNORE INTO cargo_setores (cargo_id, setor_id) VALUES (?, ?)')->execute([$cargoId, $setorId]);

    $pdo->prepare('INSERT INTO centros_custo (setor_id, codigo, nome, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$setorId, 'CC' . substr($mk, -6), 'CC ' . $mk]);
    $centroId = (int)$pdo->lastInsertId();
    $criados['centros'][] = $centroId;

    $pdo->prepare(
        'INSERT INTO colaboradores_metadados
            (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
             nome, empresa, unidade, setor, cargo, ativo, origem_metadados)
         VALUES (?,?,?,?,?,?,?,?,?,?,1,\'RHMADEPLANT\')'
    )->execute(['ID' . $mk, 'E' . substr($mk, 0, 8), 'U1', 'K' . substr($mk, 0, 8), 'P' . $mk,
        'CONTRATO OFICIAL ' . $mk, 'EMP ' . $mk, 'UNID ' . $mk, 'SET ' . $mk, 'CRG ' . $mk]);
    $metadadosId = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $metadadosId;

    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $mkUser = static function (string $sufixo, string $role) use ($pdo, $emailMk, $senha, &$criados): int {
        $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        return $id;
    };

    $payloadBase = [
        'setor_id' => $setorId, 'quantidade_vagas' => 1, 'cargo_id' => $cargoId,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'pj',
        'salario_previsto' => 'R$ 5.000,00', 'centro_custo_id' => $centroId,
        'previsto_orcamento' => '1', 'jornada_trabalho' => '44h semanais',
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    // ---- 1. usuário PJ existe só em `usuarios` --------------------------
    $pjId = $mkUser('pj', 'viewer');
    $temColaborador = (int)$pdo->query("SELECT COUNT(*) FROM colaboradores WHERE nome LIKE 'USR pj%'")->fetchColumn();
    $temUsuarioColab = (int)$pdo->prepare('SELECT COUNT(*) FROM usuario_colaboradores WHERE usuario_id = ?')->execute([$pjId]);
    $ucCount = (int)$pdo->query("SELECT COUNT(*) FROM usuario_colaboradores uc WHERE uc.usuario_id = {$pjId}")->fetchColumn();
    $check($temColaborador === 0 && $ucCount === 0, 'usuário PJ existe sem linha em colaboradores nem usuario_colaboradores');

    // ---- 2. permissão 0 bloqueia --------------------------------------
    $bloqueou = false;
    try {
        SolicitacaoVaga::create($payloadBase, $pjId, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueou = stripos($e->getMessage(), 'autoriz') !== false;
    }
    $check($bloqueou, 'PJ com pode_solicitar_vaga=0 é bloqueado no backend');

    // ---- 3. PJ autorizado, sem aprovador -> bloqueio antes da persistência
    User::setVagaAccess($pjId, true, null);
    $antesSol = (int)$pdo->query('SELECT COUNT(*) FROM solicitacoes_vaga')->fetchColumn();
    $bloqueouSemAprovador = false;
    try {
        SolicitacaoVaga::create($payloadBase, $pjId, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueouSemAprovador = stripos($e->getMessage(), 'aprovador') !== false;
    }
    $depoisSol = (int)$pdo->query('SELECT COUNT(*) FROM solicitacoes_vaga')->fetchColumn();
    $check($bloqueouSemAprovador, 'PJ autorizado SEM aprovador: envio bloqueado com mensagem de configuração pendente');
    $check($antesSol === $depoisSol, 'PJ sem aprovador: nenhuma solicitação criada');

    // ---- 4. PJ autorizado + aprovador -> cria normalmente -------------
    $aprovadorId = $mkUser('aprov', 'rh');
    $result = User::setVagaAccess($pjId, true, $aprovadorId);
    $check(($result['ok'] ?? false) === true, 'setVagaAccess grava pode_solicitar_vaga + aprovador');

    $idPj = SolicitacaoVaga::create($payloadBase, $pjId, '127.0.0.1');
    $criados['solicitacoes'][] = $idPj;
    $rowPj = $pdo->query("SELECT solicitante_usuario_id, gestor_solicitante_colaborador_id, lider_imediato_usuario_id, lider_imediato_colaborador_id, status_fluxo FROM solicitacoes_vaga WHERE id = {$idPj}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$rowPj['solicitante_usuario_id'] === $pjId, 'solicitante persistido = usuário da sessão');
    $check($rowPj['gestor_solicitante_colaborador_id'] === null, 'gestor_solicitante_colaborador_id = NULL para PJ');
    $check((int)$rowPj['lider_imediato_usuario_id'] === $aprovadorId, 'lider_imediato_usuario_id = aprovador configurado');
    $check($rowPj['lider_imediato_colaborador_id'] === null, 'lider_imediato_colaborador_id = NULL (sem inferência por cargo)');
    $check($rowPj['status_fluxo'] === 'pendente_lider', 'status inicial = pendente_lider');

    $aprPj = $pdo->query("SELECT etapa, status, destinatario_usuario_id FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = {$idPj} ORDER BY etapa")->fetchAll(PDO::FETCH_ASSOC);
    $liderRow = array_values(array_filter($aprPj, static fn($r) => $r['etapa'] === 'lider_imediato'))[0] ?? [];
    $check(($liderRow['status'] ?? '') === 'pendente' && (int)($liderRow['destinatario_usuario_id'] ?? 0) === $aprovadorId, 'etapa do líder endereçada ao aprovador-usuário');

    $rec = SolicitacaoVaga::findAccessible($idPj, $pjId, 'viewer', false);
    $check(is_array($rec), 'solicitação de PJ é visível em findAccessible para o próprio solicitante');
    $check(($rec['gestor_nome'] ?? '') === 'USR pj', 'sem gestor local, o nome exibido cai para o solicitante (usuarios)');
    $recAprovador = SolicitacaoVaga::findAccessible($idPj, $aprovadorId, 'rh', false);
    $check(is_array($recAprovador), 'aprovador enxerga a solicitação');

    $check((int)$pdo->query("SELECT COUNT(*) FROM colaboradores WHERE nome LIKE 'USR %{$mk}%' OR nome LIKE 'USR pj'")->fetchColumn() === 0, 'nenhum colaborador fictício foi criado');

    // POST não altera solicitante
    $idAdult = SolicitacaoVaga::create(array_merge($payloadBase, ['solicitante_usuario_id' => 999999]), $pjId, '127.0.0.1');
    $criados['solicitacoes'][] = $idAdult;
    $check((int)$pdo->query("SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = {$idAdult}")->fetchColumn() === $pjId, 'POST não consegue adulterar solicitante_usuario_id');

    // ---- 5. RH/Admin sem aprovador -> etapa do líder dispensada ------
    $rhId = $mkUser('rh', 'rh');
    $idRh = SolicitacaoVaga::create($payloadBase, $rhId, '127.0.0.1');
    $criados['solicitacoes'][] = $idRh;
    $rowRh = $pdo->query("SELECT status_fluxo FROM solicitacoes_vaga WHERE id = {$idRh}")->fetch(PDO::FETCH_ASSOC);
    $aprRh = $pdo->query("SELECT etapa, status, destinatario_usuario_id FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = {$idRh} ORDER BY etapa")->fetchAll(PDO::FETCH_ASSOC);
    $liderRhRow = array_values(array_filter($aprRh, static fn($r) => $r['etapa'] === 'lider_imediato'))[0] ?? [];
    $rhStepRow = array_values(array_filter($aprRh, static fn($r) => $r['etapa'] === 'rh'))[0] ?? [];
    $check($rowRh['status_fluxo'] === 'pendente_rh', 'RH sem aprovador: solicitação entra direto em pendente_rh');
    $check(($liderRhRow['status'] ?? '') === 'aprovado' && $liderRhRow['destinatario_usuario_id'] === null, 'RH sem aprovador: etapa do líder nasce aprovada, sem destinatário órfão');
    $check(($rhStepRow['status'] ?? '') === 'pendente', 'RH sem aprovador: etapa de RH continua pendente');

    // RH aprova a etapa final -> aprovada + rascunho de vaga
    $rhResult = SolicitacaoVaga::approve($idRh, 'rh', $rhId, true, false, 'aprovado', 'Teste', '127.0.0.1');
    $check(($rhResult['ok'] ?? false) === true, 'RH aprova a etapa final de uma solicitação com líder dispensado');
    $check((int)$pdo->query("SELECT COUNT(*) FROM vagas WHERE solicitacao_vaga_id = {$idRh}")->fetchColumn() === 1, 'aprovação do RH gera o rascunho da vaga (compatível com o pipeline)');

    // ---- 6. vínculo METADADOS opcional / validado / único ----------
    $check((int)$pdo->query("SELECT colaborador_metadados_id IS NULL FROM usuarios WHERE id = {$pjId}")->fetchColumn() === 1, 'usuário opera com colaborador_metadados_id NULL');
    $invalido = User::vincularMetadados($pjId, 99999999);
    $check(($invalido['ok'] ?? true) === false, 'vínculo METADADOS inexistente é rejeitado');
    $okLink = User::vincularMetadados($pjId, $metadadosId);
    $check(($okLink['ok'] ?? false) === true, 'vínculo METADADOS válido é aceito');
    $u2 = $mkUser('u2', 'viewer');
    $dup = User::vincularMetadados($u2, $metadadosId);
    $check(($dup['ok'] ?? true) === false, 'dois usuários não podem compartilhar o mesmo colaborador_metadados_id');
    User::desvincularMetadados($pjId);
    $check((int)$pdo->query("SELECT colaborador_metadados_id IS NULL FROM usuarios WHERE id = {$pjId}")->fetchColumn() === 1, 'desvincular limpa o colaborador_metadados_id');

    // ---- 7. aprovador inválido / autoapontamento -------------------
    $selfRef = User::setVagaAccess($pjId, true, $pjId);
    $check(($selfRef['ok'] ?? true) === false, 'usuário não pode ser o próprio aprovador');

    // ---- 8. usuário vinculado ao METADADOS cria normalmente -------
    User::vincularMetadados($u2, $metadadosId);
    User::setVagaAccess($u2, true, $aprovadorId);
    $idU2 = SolicitacaoVaga::create($payloadBase, $u2, '127.0.0.1');
    $criados['solicitacoes'][] = $idU2;
    $check((int)$pdo->query("SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = {$idU2}")->fetchColumn() === $u2, 'usuário COM vínculo METADADOS cria solicitação; vínculo não é condição de autorização');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nUSUARIO_VAGA_ACESSO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['solicitacoes'] as $id) {
        $limparSolicitacao((int)$id);
    }
    foreach ($criados['centros'] as $id) {
        $pdo->prepare('DELETE FROM centros_custo WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores WHERE cargo_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
