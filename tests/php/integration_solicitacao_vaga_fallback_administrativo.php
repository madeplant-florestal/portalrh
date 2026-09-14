<?php

/**
 * Integração — Sprint "Solicitação de Vaga" — correção 2026-09-14 (§3): fallback administrativo
 * de Cargo quando o Setor não tem NENHUMA relação na matriz oficial (`cargo_setores_metadados`).
 *
 * Regra: ausência de relação não significa incompatibilidade — só que o METADADOS ainda não tem a
 * informação (ex.: MANUTENÇÃO, enquanto RHQUADROLOTCARGO estiver vazia). Setor sem nenhuma relação:
 *   - Admin/RH/supervisor master ATOR (quem opera, nunca o solicitante representado) pode escolher
 *     qualquer Cargo oficial do catálogo (`codigo_cargo IS NOT NULL`, ativo) só para aquela
 *     solicitação — nunca cria vínculo em `cargo_setores_metadados`;
 *   - usuário comum continua bloqueado com a mensagem de matriz incompleta.
 *
 * Cenários (numeração do §13 da correção):
 *   5  MANUTENÇÃO (Setor sem matriz) + Admin -> aceita Cargo oficial qualquer
 *   6  MANUTENÇÃO (Setor sem matriz) + RH -> aceita Cargo oficial qualquer
 *   7  MANUTENÇÃO (Setor sem matriz) + supervisor master -> aceita Cargo oficial qualquer
 *   8  MANUTENÇÃO (Setor sem matriz) + usuário comum -> bloqueia (mensagem de matriz incompleta)
 *   9  fallback nunca inclui Cargo sem `codigo_cargo` (legado) — nem no payload nem aceito no POST
 *  11  backend aceita Cargo oficial no fallback administrativo (idem 5, reforço explícito)
 *  12  backend rejeita Cargo legado no fallback administrativo
 *  13  backend rejeita Cargo fora da matriz quando o Setor POSSUI relações oficiais (ator admin
 *      não abre exceção nesse caso — fallback só existe quando a matriz do Setor está vazia)
 *
 * Os demais itens do §13 (1-4, 10, 14-18) já são cobertos por
 * integration_solicitacao_vaga_contexto_organizacional.php (M1-M6, H1-H3) e pela suíte JS.
 *
 * Sem rollback de transação (create() tem transação própria) — fixtures marcadas e removidas no
 * finally, como os demais testes de Solicitação de Vaga.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_solicitacao_vaga_fallback_administrativo (MySQL indisponivel)\n";
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

$mk = 'ZZFB_' . substr(md5(uniqid('', true)), 0, 6);
$sfx = strtolower($mk);
$criados = ['solicitacoes' => [], 'usuarios' => [], 'setores' => [], 'cargos' => []];

$limparSolicitacao = static function (int $id) use ($pdo): void {
    foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
};

try {
    $novoSetor = static function (string $tag) use ($pdo, $sfx, &$criados): int {
        $codigo = 'Z' . strtoupper(substr(md5('setor-' . $tag . $sfx), 0, 6));
        $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, 'RHMADEPLANT']);
        $id = (int)$pdo->lastInsertId();
        $criados['setores'][] = $id;
        return $id;
    };
    $novoCargo = static function (?string $codigo, string $tag) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, $codigo !== null ? 'RHMADEPLANT' : null]);
        $id = (int)$pdo->lastInsertId();
        $criados['cargos'][] = $id;
        return $id;
    };
    $novoVinculoMatriz = static function (int $cargoId, int $setorId) use ($pdo): void {
        $pdo->prepare('INSERT INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em) VALUES (?,?,?,NOW())')
            ->execute([$cargoId, $setorId, 'RHCONTRATOS']);
    };
    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $contextoService = new UsuarioContextoOrganizacionalService();
    $novoUsuario = static function (string $tag, string $role, bool $supervisor = false) use ($pdo, $sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . strtoupper($sfx), "{$sfx}+{$tag}@teste.local", $senha, $role);
        User::setActiveStatus($id, true);
        if ($supervisor) {
            $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
        }
        $criados['usuarios'][] = $id;
        return $id;
    };

    // Setor SEM nenhuma relação na matriz (o cenário "MANUTENÇÃO" desta correção).
    $setorSemMatriz = $novoSetor('manutencao-like');
    // Setor COM relação oficial (para o cenário 13 — fallback não vale onde a matriz existe).
    $setorComMatriz = $novoSetor('com-matriz');

    $cargoOficialA = $novoCargo('ZA' . substr($sfx, -6), 'cargo-oficial-a');
    $cargoOficialB = $novoCargo('ZB' . substr($sfx, -6), 'cargo-oficial-b');
    $cargoLegado = $novoCargo(null, 'cargo-legado');
    $novoVinculoMatriz($cargoOficialA, $setorComMatriz);

    $payloadBase = [
        'quantidade_vagas' => 1,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'pj',
        'salario_previsto' => 'R$ 5.000,00',
        'previsto_orcamento' => '1', 'jornada_trabalho' => '44h semanais',
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    // ---- 5/11: MANUTENÇÃO (sem matriz) + Admin -> aceita Cargo oficial qualquer ----------------
    $admin = $novoUsuario('admin', 'admin');
    $contextoService->definirContextoManual($admin, null, $setorSemMatriz, []);
    $idAdmin = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorSemMatriz, 'cargo_id' => $cargoOficialA]), $admin, '127.0.0.1');
    $criados['solicitacoes'][] = $idAdmin;
    $cargoGravadoAdmin = (int)$pdo->query("SELECT cargo_id FROM solicitacoes_vaga WHERE id = {$idAdmin}")->fetchColumn();
    $check($cargoGravadoAdmin === $cargoOficialA, '5/11 Admin: Setor sem matriz aceita Cargo oficial via fallback administrativo');

    // ---- 6: MANUTENÇÃO (sem matriz) + RH -> aceita Cargo oficial qualquer ----------------------
    $rh = $novoUsuario('rh', 'rh');
    $contextoService->definirContextoManual($rh, null, $setorSemMatriz, []);
    $idRh = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorSemMatriz, 'cargo_id' => $cargoOficialB]), $rh, '127.0.0.1');
    $criados['solicitacoes'][] = $idRh;
    $cargoGravadoRh = (int)$pdo->query("SELECT cargo_id FROM solicitacoes_vaga WHERE id = {$idRh}")->fetchColumn();
    $check($cargoGravadoRh === $cargoOficialB, '6 RH: Setor sem matriz aceita Cargo oficial via fallback administrativo');

    // ---- 7: MANUTENÇÃO (sem matriz) + supervisor master -> aceita Cargo oficial qualquer -------
    $supervisor = $novoUsuario('supervisor', 'viewer', true);
    $contextoService->definirContextoManual($supervisor, null, $setorSemMatriz, []);
    $idSupervisor = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorSemMatriz, 'cargo_id' => $cargoOficialA]), $supervisor, '127.0.0.1');
    $criados['solicitacoes'][] = $idSupervisor;
    $cargoGravadoSupervisor = (int)$pdo->query("SELECT cargo_id FROM solicitacoes_vaga WHERE id = {$idSupervisor}")->fetchColumn();
    $check($cargoGravadoSupervisor === $cargoOficialA, '7 Supervisor master: Setor sem matriz aceita Cargo oficial via fallback administrativo');

    // ---- 8: MANUTENÇÃO (sem matriz) + usuário comum -> bloqueia -------------------------------
    $comum = $novoUsuario('comum', 'viewer');
    User::setVagaAccess($comum, true, $admin);
    $contextoService->definirContextoManual($comum, null, $setorSemMatriz, []);
    $bloqueouComum = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorSemMatriz, 'cargo_id' => $cargoOficialA]), $comum, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueouComum = $e->getMessage() === 'Nenhum Cargo oficial está associado a este Setor no METADADOS.';
    }
    $check($bloqueouComum, '8 usuário comum continua bloqueado em Setor sem matriz (sem fallback)');

    // ---- 9: fallback nunca inclui Cargo sem codigo_cargo -----------------------------------
    $deps = SolicitacaoVaga::formDependencies($admin, $admin);
    $idsFallback = array_column($deps['cargos_fallback_administrativo'], 'id');
    $check(!in_array($cargoLegado, $idsFallback, true), '9 payload de fallback administrativo NÃO inclui Cargo legado (sem codigo_cargo)');
    $check(in_array($cargoOficialA, $idsFallback, true) && in_array($cargoOficialB, $idsFallback, true), '9 payload de fallback administrativo inclui os Cargos oficiais existentes');
    $check($deps['pode_fallback_cargo_administrativo'] === true, '9 pode_fallback_cargo_administrativo=true para ator admin');

    $depsComum = SolicitacaoVaga::formDependencies($comum, $comum);
    $check($depsComum['pode_fallback_cargo_administrativo'] === false && $depsComum['cargos_fallback_administrativo'] === [], '9 payload de fallback administrativo vazio/false para ator comum');

    // ---- 12: backend rejeita Cargo legado no fallback administrativo --------------------------
    $legadoRejeitadoNoFallback = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorSemMatriz, 'cargo_id' => $cargoLegado]), $admin, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $legadoRejeitadoNoFallback = $e->getMessage() === 'Selecione um Cargo oficial do catálogo do METADADOS.';
    }
    $check($legadoRejeitadoNoFallback, '12 Admin: Cargo legado é rejeitado mesmo no fallback administrativo');

    // ---- 13: fallback NÃO vale quando o Setor possui relações oficiais ------------------------
    $contextoService->definirContextoManual($admin, null, $setorComMatriz, [$setorSemMatriz]);
    $foraDaMatrizComAdmin = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorComMatriz, 'cargo_id' => $cargoOficialB]), $admin, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $foraDaMatrizComAdmin = $e->getMessage() === 'Selecione um Cargo vinculado ao Setor selecionado.';
    }
    $check($foraDaMatrizComAdmin, '13 Admin: Setor COM matriz continua exigindo Cargo vinculado — fallback não se aplica');
    $idAdminComMatriz = SolicitacaoVaga::create(array_merge($payloadBase, ['setor_id' => $setorComMatriz, 'cargo_id' => $cargoOficialA]), $admin, '127.0.0.1');
    $criados['solicitacoes'][] = $idAdminComMatriz;
    $check((int)$pdo->query("SELECT cargo_id FROM solicitacoes_vaga WHERE id = {$idAdminComMatriz}")->fetchColumn() === $cargoOficialA, '13 Admin: Cargo vinculado ao Setor com matriz é aceito normalmente');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nSOLICITACAO_VAGA_FALLBACK_ADMINISTRATIVO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['solicitacoes'] as $id) {
        $limparSolicitacao((int)$id);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE setor_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
