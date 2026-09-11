<?php

/**
 * Integração — Sprint "Solicitação de Vaga" — Etapa 2 (adaptação ao Contexto Organizacional dos
 * Usuários). Cobre os cenários do §26 não exercitados por integration_usuario_vaga_acesso.php,
 * mais os itens adicionais da revisão de Centro de Custo/Kanban/auditoria (§H):
 *
 *   4  usuário sem Setor é bloqueado
 *   6  usuário com vários Setores pode escolher entre os autorizados
 *   7  não pode escolher Setor fora do contexto (inclui Setor legado)
 *   8  Admin cria em nome de outro usuário autorizado
 *   9  RH cria em nome de outro, com a ACL atual
 *   10 solicitante inativo é rejeitado (ao ser escolhido por Admin/RH)
 *   11 Cargo legado não pode ser usado na vaga
 *   14 histórico antigo com gestor_solicitante_colaborador_id continua funcionando
 *   16 Kanban continua funcionando (LEFT JOIN — solicitação nova com gestor NULL aparece)
 *   18 auditoria diferencia solicitante de criador
 *   H1-H3 Centro de Custo: opcional sem cadastro, obrigatório com cadastro, rejeita de outro Setor
 *   H4/H5 Kanban: solicitação nova (gestor NULL) e histórico legado (gestor preenchido) visíveis
 *   H6/H7 Admin/RH criando em nome de outro: solicitante correto + auditoria do operador
 *
 * Sem rollback de transação (create() tem transação própria) — fixtures marcadas e removidas no
 * finally, como os demais testes de Solicitação de Vaga/Usuários.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_solicitacao_vaga_contexto_organizacional (MySQL indisponivel)\n";
    exit(0);
}

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

$mk = 'ZZSV2_' . substr(md5(uniqid('', true)), 0, 6);
$sfx = strtolower($mk);
$criados = ['solicitacoes' => [], 'usuarios' => [], 'centros' => [], 'cargos' => [], 'setores' => [], 'colaboradores' => []];

$limparSolicitacao = static function (int $id) use ($pdo): void {
    foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
};

try {
    // ---------------------------------------------------------------- fixtures
    $novoSetor = static function (?string $codigo, string $rot) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, origem_metadados) VALUES (?,?,?,?,1,?)')
            ->execute([$codigo, strtoupper($rot) . ' ' . $sfx, $codigo !== null ? strtoupper($rot) : null, $rot . '-' . $sfx, $codigo !== null ? 'RHMADEPLANT' : null]);
        $id = (int)$pdo->lastInsertId();
        $criados['setores'][] = $id;
        return $id;
    };
    $novoCargo = static function (?string $codigo, string $rot) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, descricao_oficial, slug, ativo, origem_metadados) VALUES (?,?,?,?,1,?)')
            ->execute([$codigo, strtoupper($rot) . ' ' . $sfx, $codigo !== null ? strtoupper($rot) : null, $rot . '-' . $sfx, $codigo !== null ? 'RHMADEPLANT' : null]);
        $id = (int)$pdo->lastInsertId();
        $criados['cargos'][] = $id;
        return $id;
    };
    $novoCentro = static function (int $setorId, string $tag) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO centros_custo (setor_id, codigo, nome, ativo) VALUES (?,?,?,1)')
            ->execute([$setorId, 'CC' . $tag . substr($sfx, -4), 'CC ' . $tag . ' ' . $sfx]);
        $id = (int)$pdo->lastInsertId();
        $criados['centros'][] = $id;
        return $id;
    };

    $setorA = $novoSetor('ZA' . substr($sfx, -6), 'setor-a'); // com Centro de Custo
    $setorB = $novoSetor('ZB' . substr($sfx, -6), 'setor-b'); // sem Centro de Custo (usado no resto do teste)
    $setorC = $novoSetor('ZC' . substr($sfx, -6), 'setor-c'); // isolado, só para o teste H3 (centro de outro Setor)
    $setorLegado = $novoSetor(null, 'setor-legado');
    $cargoOficial = $novoCargo('ZC' . substr($sfx, -6), 'cargo-oficial');
    $cargoLegado = $novoCargo(null, 'cargo-legado');
    $centroA1 = $novoCentro($setorA, 'A1');
    $centroA2 = $novoCentro($setorA, 'A2');

    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $contextoService = new UsuarioContextoOrganizacionalService();
    $novoUsuario = static function (string $tag, string $role, bool $ativo = true) use ($sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . strtoupper($sfx), "{$sfx}+{$tag}@teste.local", $senha, $role);
        if ($ativo) {
            User::setActiveStatus($id, true);
        }
        $criados['usuarios'][] = $id;
        return $id;
    };

    $payloadBase = [
        'quantidade_vagas' => 1, 'cargo_id' => $cargoOficial,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'pj',
        'salario_previsto' => 'R$ 5.000,00',
        'previsto_orcamento' => '1', 'jornada_trabalho' => '44h semanais',
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    $admin = $novoUsuario('admin', 'admin');
    $rh = $novoUsuario('rh', 'rh');
    // $rh NUNCA recebe pode_solicitar_vaga=1 (fica 0, o default) — de propósito, para provar a
    // exceção de elegibilidade: role admin/rh/supervisor master é suficiente por si só.
    $contextoService->definirContextoManual($rh, null, $setorB, []);
    $aprovador = $novoUsuario('aprov', 'rh');

    $uSemSetor = $novoUsuario('semsetor', 'viewer');
    User::setVagaAccess($uSemSetor, true, $aprovador);

    $uVarios = $novoUsuario('varios', 'viewer');
    User::setVagaAccess($uVarios, true, $aprovador);
    $contextoService->definirContextoManual($uVarios, null, $setorA, [$setorB]);

    $uUmSetor = $novoUsuario('umsetor', 'viewer');
    User::setVagaAccess($uUmSetor, true, $aprovador);
    $contextoService->definirContextoManual($uUmSetor, null, $setorB, []);

    $uInativo = $novoUsuario('inativo', 'viewer', false);
    User::setVagaAccess($uInativo, true, $aprovador);
    $contextoService->definirContextoManual($uInativo, null, $setorA, []);

    $solicitanteAlvo = $novoUsuario('alvo', 'viewer');
    User::setVagaAccess($solicitanteAlvo, true, $aprovador);
    $contextoService->definirContextoManual($solicitanteAlvo, null, $setorB, []);

    // ---- 4: usuário sem Setor é bloqueado ---------------------------------
    $bloqueouSemSetor = false;
    try {
        SolicitacaoVaga::create($payloadBase, $uSemSetor, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueouSemSetor = stripos($e->getMessage(), 'Setor de atuação') !== false;
    }
    $check($bloqueouSemSetor, '4 usuário sem Setor de atuação é bloqueado com mensagem clara');

    // ---- 6/7: vários Setores — escolher, e não pode escolher fora do contexto
    $semSetorEscolhido = false;
    try {
        SolicitacaoVaga::create($payloadBase, $uVarios, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $semSetorEscolhido = stripos($e->getMessage(), 'Selecione um Setor') !== false;
    }
    $check($semSetorEscolhido, '6 usuário com vários Setores precisa escolher um (sem default)');

    $idVariosA = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA, 'centro_custo_id' => $centroA1]), $uVarios, '127.0.0.1');
    $criados['solicitacoes'][] = $idVariosA;
    $check((int)$pdo->query("SELECT setor_id FROM solicitacoes_vaga WHERE id = {$idVariosA}")->fetchColumn() === $setorA, '6 Setor escolhido (dentre os autorizados) é persistido');

    $foraDoContexto = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorLegado]), $uVarios, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $foraDoContexto = stripos($e->getMessage(), 'Setores de atuação') !== false;
    }
    $check($foraDoContexto, '7/13 Setor fora do contexto do solicitante (aqui, legado) é rejeitado');

    // ---- 5 (reforço): 1 Setor -> automático, ignora valor postado diferente
    $idUmSetor = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA]), $uUmSetor, '127.0.0.1');
    $criados['solicitacoes'][] = $idUmSetor;
    $check((int)$pdo->query("SELECT setor_id FROM solicitacoes_vaga WHERE id = {$idUmSetor}")->fetchColumn() === $setorB, '5 usuário com 1 Setor usa sempre aquele Setor, mesmo com outro postado');

    // ---- 11: Cargo legado não pode ser usado ------------------------------
    $cargoLegadoRecusado = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['cargo_id' => $cargoLegado]), $uUmSetor, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $cargoLegadoRecusado = stripos($e->getMessage(), 'catálogo oficial') !== false;
    }
    $check($cargoLegadoRecusado, '11 Cargo legado (sem codigo_cargo) é recusado');

    // ---- H1/H2/H3: Centro de Custo -----------------------------------------
    $check((int)$pdo->query("SELECT centro_custo_id IS NULL FROM solicitacoes_vaga WHERE id = {$idUmSetor}")->fetchColumn() === 1, 'H1 Setor sem Centro de Custo -> centro_custo_id NULL aceito, sem bloqueio');

    $centroObrigatorio = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA]), $uVarios, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $centroObrigatorio = stripos($e->getMessage(), 'Centro de Custo') !== false;
    }
    $check($centroObrigatorio, 'H2 Setor COM Centro de Custo cadastrado -> seleção obrigatória');

    $idComCentro = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA, 'centro_custo_id' => $centroA1]), $uVarios, '127.0.0.1');
    $criados['solicitacoes'][] = $idComCentro;
    $check((int)$pdo->query("SELECT centro_custo_id FROM solicitacoes_vaga WHERE id = {$idComCentro}")->fetchColumn() === $centroA1, 'H2 Centro de Custo válido do próprio Setor é aceito');

    // centro de outro Setor: cria um centro em setorC (isolado) e tenta usar com setorA
    $centroDeC = $novoCentro($setorC, 'C1');
    $centroRejeitado = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA, 'centro_custo_id' => $centroDeC]), $uVarios, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $centroRejeitado = stripos($e->getMessage(), 'Centro de Custo válido') !== false;
    }
    $check($centroRejeitado, 'H3 Centro de Custo de outro Setor é rejeitado');

    // ---- 10: solicitante inativo é rejeitado (Admin escolhendo) -----------
    $inativoRejeitado = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorA, 'solicitante_usuario_id' => $uInativo]), $admin, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $inativoRejeitado = stripos($e->getMessage(), 'inativo') !== false;
    }
    $check($inativoRejeitado, '10 Admin não consegue escolher um solicitante inativo');

    // ---- 8/9/18/H6/H7: Admin e RH criam em nome de outro -------------------
    $idPorAdmin = SolicitacaoVaga::create(array_merge($payloadBase, ['solicitante_usuario_id' => $solicitanteAlvo]), $admin, '127.0.0.1');
    $criados['solicitacoes'][] = $idPorAdmin;
    $rowAdmin = $pdo->query("SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = {$idPorAdmin}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$rowAdmin['solicitante_usuario_id'] === $solicitanteAlvo, '8 Admin cria em nome de outro: solicitante correto persistido');
    $auditAdmin = $pdo->query("SELECT actor_usuario_id FROM solicitacao_vaga_auditoria WHERE solicitacao_id = {$idPorAdmin} AND event_type = 'created'")->fetch(PDO::FETCH_ASSOC);
    $check((int)($auditAdmin['actor_usuario_id'] ?? 0) === $admin, '18/H6 auditoria "created" registra o Admin como executor (actor), não o solicitante');

    $idPorRh = SolicitacaoVaga::create(array_merge($payloadBase, ['solicitante_usuario_id' => $solicitanteAlvo]), $rh, '127.0.0.1');
    $criados['solicitacoes'][] = $idPorRh;
    $rowRh = $pdo->query("SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = {$idPorRh}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$rowRh['solicitante_usuario_id'] === $solicitanteAlvo, '9 RH cria em nome de outro: solicitante correto persistido');
    $auditRh = $pdo->query("SELECT actor_usuario_id FROM solicitacao_vaga_auditoria WHERE solicitacao_id = {$idPorRh} AND event_type = 'created'")->fetch(PDO::FETCH_ASSOC);
    $check((int)($auditRh['actor_usuario_id'] ?? 0) === $rh, '18/H7 auditoria "created" registra o RH como executor (actor), não o solicitante');

    // ---- Decisão de negócio: exceção de elegibilidade do ALVO -------------
    // Admin/RH/supervisor master são elegíveis como SOLICITANTE mesmo com pode_solicitar_vaga=0
    // (conta especial/administrativa do Portal) — $rh nunca recebeu a flag (fica 0, o default).
    $check((int)$pdo->query("SELECT pode_solicitar_vaga FROM usuarios WHERE id = {$rh}")->fetchColumn() === 0, 'pré-condição: $rh tem pode_solicitar_vaga=0 (default, nunca setado)');
    $idRhComoAlvo = SolicitacaoVaga::create(array_merge($payloadBase, ['solicitante_usuario_id' => $rh]), $admin, '127.0.0.1');
    $criados['solicitacoes'][] = $idRhComoAlvo;
    $check((int)$pdo->query("SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = {$idRhComoAlvo}")->fetchColumn() === $rh, 'exceção: Admin escolhe um RH com pode_solicitar_vaga=0 como solicitante — aceito pela role');

    // "criado por" (auditoria) difere de "solicitante" quando Admin/RH agiu por outro;
    // quando o próprio usuário cria, os dois coincidem (self-service).
    $idProprio = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorB]), $solicitanteAlvo, '127.0.0.1');
    $criados['solicitacoes'][] = $idProprio;
    $auditProprio = $pdo->query("SELECT actor_usuario_id FROM solicitacao_vaga_auditoria WHERE solicitacao_id = {$idProprio} AND event_type = 'created'")->fetch(PDO::FETCH_ASSOC);
    $check((int)($auditProprio['actor_usuario_id'] ?? 0) === $solicitanteAlvo, '18 auto-atendimento: actor da auditoria = o próprio solicitante');

    // ---- 16/H4: Kanban enxerga solicitação nova (gestor NULL) --------------
    $kanban = SolicitacaoVaga::allForKanban($admin, 'admin', false);
    $idsKanban = array_column($kanban, 'id');
    $check(in_array($idPorAdmin, $idsKanban, true), 'H4/16 solicitação nova (gestor legado NULL) aparece no Kanban (LEFT JOIN)');

    // ---- 14/H5: histórico legado (gestor_solicitante_colaborador_id preenchido) segue funcionando
    $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id) VALUES (?,?,?)')
        ->execute(['COLAB LEGADO ' . $mk, 'colab-legado-' . $sfx, $cargoOficial]);
    $colaboradorLegadoId = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $colaboradorLegadoId;

    $idLegado = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorB, 'gestor_solicitante_colaborador_id' => $colaboradorLegadoId]), $uUmSetor, '127.0.0.1');
    $criados['solicitacoes'][] = $idLegado;
    $check((int)$pdo->query("SELECT gestor_solicitante_colaborador_id FROM solicitacoes_vaga WHERE id = {$idLegado}")->fetchColumn() === $colaboradorLegadoId, '14 gestor_solicitante_colaborador_id legado ainda pode ser gravado (compatibilidade)');

    $acessivelLegado = SolicitacaoVaga::findAccessible($idLegado, $admin, 'admin', false);
    $check(is_array($acessivelLegado) && $acessivelLegado['gestor_nome'] === 'COLAB LEGADO ' . $mk, '14 findAccessible resolve o nome do gestor legado a partir de colaboradores');

    $kanbanLegado = SolicitacaoVaga::allForKanban($admin, 'admin', false);
    $idsKanbanLegado = array_column($kanbanLegado, 'id');
    $check(in_array($idLegado, $idsKanbanLegado, true), 'H5 histórico com gestor legado preenchido continua visível no Kanban');
    $itemLegado = array_values(array_filter($kanbanLegado, static fn ($i) => $i['id'] === $idLegado))[0] ?? [];
    $check(($itemLegado['gestor_nome'] ?? '') === 'COLAB LEGADO ' . $mk, 'H5 Kanban exibe o nome do gestor legado quando presente (COALESCE)');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nSOLICITACAO_VAGA_CONTEXTO_ORGANIZACIONAL_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['solicitacoes'] as $id) {
        $limparSolicitacao((int)$id);
    }
    foreach ($criados['colaboradores'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['centros'] as $id) {
        $pdo->prepare('DELETE FROM centros_custo WHERE id = ?')->execute([(int)$id]);
    }
    // Usuários ANTES de Setores/Cargos: usuario_setores (FK RESTRICT em setor_id) e usuarios.cargo_id
    // (FK SET NULL) só se resolvem sozinhos quando o próprio usuário é removido (cascade/set null).
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores WHERE cargo_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
