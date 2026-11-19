<?php

/**
 * Integração — Etapa 2C.2C: aprovação de Solicitação de Vaga pelo Gestor, SOMENTE na etapa de líder.
 * Condições simultâneas: permissão `solicitacao_vaga.aprovar_lider` + designação formal + Setor Gerenciado + etapa correta + CSRF + auditoria.
 * Provas: gestor designado/autorizado aprova e reprova; não designado, sem setor, de outro setor, sem permissão são recusados; aprovação de RH, salto de
 * etapa, solicitação cancelada/fechada/já decidida e IDs forjados são recusados; viewer designado segue funcionando (compatibilidade temporária);
 * Admin/RH/Supervisor Geral preservados; CSRF inválido = 400; auditoria registrada.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/fixture_setor_gerenciado.php';

SchemaManager::ensure();
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

$suf = substr((string)time(), -5) . random_int(10, 99);
$c = ['usuarios' => [], 'setores' => [], 'solicitacoes' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$permId = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $permId[$r['codigo']] = (int)$r['id'];
}
$novo = static function (string $apelido, string $role, int $flag, array $perms) use ($pdo, $senha, $suf, &$c, $permId): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZA ' . $apelido, strtolower($apelido) . '.zza.' . $suf . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $c['usuarios'][] = $id;
    foreach ($perms as $p) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $permId[$p]]);
    }
    return $id;
};
$cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
$stage = (int)(SolicitacaoVagaStage::findBySlug('em-aprovacao')['id'] ?? 0);
$mkSolic = static function (int $setorId, int $solicitanteId, ?int $designado, string $status = 'pendente_lider') use ($pdo, $cargoId, $stage, &$c): int {
    $pdo->prepare(
        "INSERT INTO solicitacoes_vaga (setor_id, quantidade_vagas, cargo_id, solicitante_usuario_id, tipo_vaga, tipo_contratacao, salario_previsto, previsto_orcamento, jornada_trabalho, escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio, urgencia, status_fluxo, situacao_kanban_id)
         VALUES (?, 1, ?, ?, 'nova_posicao', 'clt', 5000, 1, '44h', 'medio', '', 'operacional', ?, 'media', ?, ?)"
    )->execute([$setorId, $cargoId, $solicitanteId, date('Y-m-d', strtotime('+30 days')), $status, $stage]);
    $id = (int)$pdo->lastInsertId();
    $c['solicitacoes'][] = $id;
    $pdo->prepare("INSERT INTO solicitacao_vaga_aprovacoes (solicitacao_id, etapa, destinatario_usuario_id, status) VALUES (?, 'lider_imediato', ?, 'pendente')")->execute([$id, $designado]);
    $pdo->prepare("INSERT INTO solicitacao_vaga_aprovacoes (solicitacao_id, etapa, destinatario_usuario_id, status) VALUES (?, 'rh', NULL, 'pendente')")->execute([$id]);
    return $id;
};
$requisitar = static function (int $userId, string $role, bool $sess, string $controller, string $metodo, array $post = [], array $args = []): array {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sess ? '1' : '0', $controller, $metodo, '[]', json_encode($args), $post === [] ? '' : json_encode($post)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
    return [$status, $saida];
};
$v = static fn(string $sql, array $p = []): mixed => (function () use ($pdo, $sql, $p) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
})();
$statusDe = static fn(int $id): string => (string)$v('SELECT status_fluxo FROM solicitacoes_vaga WHERE id = ?', [$id]);
$etapa = static fn(int $id, string $e): string => (string)$v('SELECT status FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = ? AND etapa = ?', [$id, $e]);
$aprovar = static fn(int $sol, int $user, string $step = 'lider_imediato', string $dec = 'aprovado'): array => SolicitacaoVaga::approve($sol, $step, $user, false, false, $dec, 'ZZA', '127.0.0.1');

try {
    $sA = zzSetorTeste($pdo, 'AA' . substr($suf, -5), 'ZZA');
    $sB = zzSetorTeste($pdo, 'AB' . substr($suf, -5), 'ZZA');
    $c['setores'] = [$sA, $sB];
    $perm = ['solicitacao_vaga.aprovar_lider', 'solicitacao_vaga.visualizar'];
    $gD = $novo('gdesignado', 'gestor', 0, $perm);
    $gND = $novo('gnaodesig', 'gestor', 0, $perm);
    $gSS = $novo('gsemsetor', 'gestor', 0, $perm);
    $gOut = $novo('goutro', 'gestor', 0, $perm);
    $gSP = $novo('gsemperm', 'gestor', 0, ['solicitacao_vaga.visualizar']);
    $gF = $novo('gflag', 'gestor', 1, $perm);
    $vL = $novo('vlegado', 'viewer', 0, []);
    $rh = $novo('rh', 'rh', 0, []);
    $adm = $novo('adm', 'admin', 0, []);
    $sg = $novo('sg', 'admin', 1, []);
    foreach ([$gD, $gND, $gSP, $gF] as $u) {
        zzGerenciar($pdo, $u, $sA);
    }
    zzGerenciar($pdo, $gOut, $sB);

    // ---- 1. aprovação e reprovação (caminho feliz) ------------------------------------------------------------------------------------------
    $s1 = $mkSolic($sA, $rh, $gD);
    $r = $aprovar($s1, $gD);
    $check(($r['ok'] ?? false) === true && $statusDe($s1) === 'pendente_rh' && $etapa($s1, 'lider_imediato') === 'aprovado' && (int)$v("SELECT aprovador_usuario_id FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = ? AND etapa = 'lider_imediato'", [$s1]) === $gD && $etapa($s1, 'rh') === 'pendente', '(1) Gestor designado, com permissão e do setor: aprova a etapa de líder — a solicitação passa a aguardar o RH (a etapa de RH segue pendente)');
    $check((int)$v("SELECT COUNT(*) FROM solicitacao_vaga_auditoria WHERE solicitacao_id = ? AND event_type = 'approval_lider_imediato' AND actor_usuario_id = ?", [$s1, $gD]) === 1, '(1) a decisão fica na auditoria (evento approval_lider_imediato, autor = o Gestor)');
    $s2 = $mkSolic($sA, $rh, $gD);
    $r = $aprovar($s2, $gD, 'lider_imediato', 'reprovado');
    $check(($r['ok'] ?? false) === true && $statusDe($s2) === 'reprovada_lider' && $etapa($s2, 'lider_imediato') === 'reprovado', '(1) o Gestor também REPROVA na etapa de líder (status reprovada_lider)');
    $check(($aprovar($s1, $gD)['ok'] ?? true) === false, '(1) decisão repetida é recusada (etapa já registrada)');

    // ---- 2. condições que faltam ----------------------------------------------------------------------------------------------------------------
    $s3 = $mkSolic($sA, $rh, $gD); // designado = gD
    $semEfeito = static fn(int $s): bool => $statusDe($s) === 'pendente_lider' && $etapa($s, 'lider_imediato') === 'pendente';
    $check(($aprovar($s3, $gND)['ok'] ?? true) === false && $semEfeito($s3), '(2) Gestor do setor e com permissão, mas NÃO designado: recusado');
    $s4 = $mkSolic($sA, $rh, $gSS);
    $check(($aprovar($s4, $gSS)['ok'] ?? true) === false && $semEfeito($s4), '(2) Gestor designado, mas SEM setores gerenciados: recusado (nunca acesso global)');
    $s5 = $mkSolic($sA, $rh, $gOut);
    $check(($aprovar($s5, $gOut)['ok'] ?? true) === false && $semEfeito($s5), '(2) Gestor designado, mas que gerencia OUTRO setor: recusado (a mensagem é a de "não encontrada")');
    $s6 = $mkSolic($sA, $rh, $gSP);
    $check(($aprovar($s6, $gSP)['ok'] ?? true) === false && $semEfeito($s6), '(2) Gestor designado e do setor, mas SEM a permissão solicitacao_vaga.aprovar_lider: recusado');
    $s7 = $mkSolic($sA, $rh, $gF);
    $r7 = SolicitacaoVaga::approve($s7, 'lider_imediato', $gF, true, true, 'aprovado', 'x', '127.0.0.1'); // tenta se passar por RH/supervisor pelos parâmetros
    $check(($r7['ok'] ?? false) === true && $statusDe($s7) === 'pendente_rh', '(2) Gestor com is_supervisor=1 segue as MESMAS regras (designado + setor + permissão): aprova só por elas, os parâmetros de RH/supervisor são ignorados');
    $s7b = $mkSolic($sA, $rh, $gND);
    $r7b = SolicitacaoVaga::approve($s7b, 'lider_imediato', $gF, true, true, 'aprovado', 'x', '127.0.0.1');
    $check(($r7b['ok'] ?? true) === false && $semEfeito($s7b), '(2) Gestor com flag de supervisor NÃO aprova solicitação para a qual não foi designado (o flag não é atalho)');

    // ---- 3. etapa errada / RH / pular etapas / estados ---------------------------------------------------------------------------------------------
    $s8 = $mkSolic($sA, $rh, $gD);
    $r8 = $aprovar($s8, $gD, 'rh');
    $check(($r8['ok'] ?? true) === false && $etapa($s8, 'rh') === 'pendente' && $semEfeito($s8), '(3) o Gestor NÃO aprova em nome do RH (etapa de RH recusada, nada muda)');
    $s9 = $mkSolic($sA, $rh, $gD, 'pendente_rh'); // inconsistência: etapa de líder pendente, mas o fluxo já está no RH
    $check(($aprovar($s9, $gD)['ok'] ?? true) === false && $etapa($s9, 'lider_imediato') === 'pendente', '(3) etapa incorreta (solicitação já fora da etapa do líder): recusado');
    $s10 = $mkSolic($sA, $rh, $gD);
    $pdo->prepare('UPDATE solicitacoes_vaga SET cancelada_em = NOW() WHERE id = ?')->execute([$s10]);
    $s11 = $mkSolic($sA, $rh, $gD);
    $pdo->prepare('UPDATE solicitacoes_vaga SET fechada_em = NOW() WHERE id = ?')->execute([$s11]);
    $check(($aprovar($s10, $gD)['ok'] ?? true) === false && ($aprovar($s11, $gD)['ok'] ?? true) === false && $etapa($s10, 'lider_imediato') === 'pendente' && $etapa($s11, 'lider_imediato') === 'pendente', '(3) solicitação cancelada ou fechada: o Gestor não aprova');
    $check(($aprovar(2147480000, $gD)['ok'] ?? true) === false && ($aprovar($s3, $gD, 'etapa_inventada')['ok'] ?? true) === false && ($aprovar($s3, $gD, 'lider_imediato', 'talvez')['ok'] ?? true) === false && $semEfeito($s3), '(3) ID inexistente, etapa inventada e decisão inventada: recusados');

    // ---- 4. compatibilidade e perfis globais ----------------------------------------------------------------------------------------------------------
    $s12 = $mkSolic($sA, $rh, $vL);
    $check(($aprovar($s12, $vL)['ok'] ?? false) === true && $statusDe($s12) === 'pendente_rh', '(4) Viewer DESIGNADO continua aprovando a etapa de líder (compatibilidade temporária)');
    $s13 = $mkSolic($sA, $rh, $gD);
    $rRh = SolicitacaoVaga::approve($s13, 'lider_imediato', $rh, true, false, 'aprovado', 'x', '127.0.0.1');
    $check(($rRh['ok'] ?? true) === false && $semEfeito($s13), '(4) RH não designado continua NÃO aprovando a etapa de líder (regra existente preservada)');
    $rSg = SolicitacaoVaga::approve($s13, 'lider_imediato', $sg, true, true, 'aprovado', 'x', '127.0.0.1');
    $check(($rSg['ok'] ?? false) === true && $statusDe($s13) === 'pendente_rh', '(4) Supervisor Geral continua podendo aprovar a etapa de líder (regra existente preservada)');

    // ---- 5. HTTP: permissão, CSRF, IDs forjados, RH ---------------------------------------------------------------------------------------------------------
    $h1 = $mkSolic($sA, $rh, $gD);
    [$st] = $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['csrf' => 'forjado', 'decision' => 'aprovado'], [(string)$h1]);
    $check($st === 400 && $semEfeito($h1), '(5) CSRF inválido = 400 e nada muda');
    [$st] = $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveRh', ['decision' => 'aprovado'], [(string)$h1]);
    $check($st === 403 && $semEfeito($h1) && $etapa($h1, 'rh') === 'pendente', '(5) rota de aprovação de RH: 403 ao Gestor');
    [$st] = $requisitar($gSP, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'aprovado'], [(string)$h1]);
    $check($st === 403 && $semEfeito($h1), '(5) sem a permissão: 403');
    $hOutro = $mkSolic($sB, $rh, $gD); // designado, mas de OUTRO setor (id forjado na URL)
    $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'aprovado'], [(string)$hOutro]);
    $check($semEfeito($hOutro), '(5) ID forjado de solicitação de outro setor: nada é aprovado');
    $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'aprovado', 'solicitacao_id' => (string)$hOutro, 'setor_id' => (string)$sB], [(string)$h1]);
    $check($statusDe($h1) === 'pendente_rh' && $semEfeito($hOutro), '(5) com CSRF válido, designação e setor: aprova a solicitação DA URL; parâmetros de id/setor no corpo são ignorados');
    $h2 = $mkSolic($sA, $rh, $gD);
    [$st] = $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'inventada'], [(string)$h2]);
    $check($semEfeito($h2), '(5) decisão forjada no POST é recusada');
    $hv = $mkSolic($sA, $rh, $vL);
    $requisitar($vL, 'viewer', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'aprovado'], [(string)$hv]);
    $check($statusDe($hv) === 'pendente_rh', '(5) HTTP: viewer designado segue aprovando (compatibilidade)');
    [$st] = $requisitar($gD, 'gestor', false, 'AdminSolicitacoesVagaController', 'updateRh', ['x' => '1'], [(string)$h2]);
    $check($st === 403, '(5) controle de RH e geração de vaga seguem Admin/RH (403 ao Gestor)');

    // ---- 6. política ----------------------------------------------------------------------------------------------------------------------------------------
    $P = 'PermissaoPerfilPolicy';
    $check(PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.aprovar_lider') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('viewer', 'solicitacao_vaga.aprovar_lider') === $P::INCOMPATIVEL && (int)$v("SELECT COUNT(*) FROM permissoes WHERE codigo = 'solicitacao_vaga.aprovar_lider'") === 1, '(6) política: aprovar_lider é do Gestor, incompatível com o Viewer; o código existe no catálogo');
    $check((int)$v("SELECT COUNT(*) FROM usuario_permissoes up JOIN permissoes p ON p.id = up.permissao_id WHERE p.codigo = 'solicitacao_vaga.aprovar_lider' AND up.usuario_id NOT IN (" . implode(',', array_map('intval', $c['usuarios'])) . ')') === 0, '(6) ninguém além dos usuários de teste recebeu a permissão (a migration não concede a ninguém)');
} finally {
    if ($c['solicitacoes'] !== []) {
        $pdo->exec('DELETE FROM solicitacoes_vaga WHERE id IN (' . implode(',', array_map('intval', $c['solicitacoes'])) . ')');
    }
    if ($c['usuarios'] !== []) {
        $lu = implode(',', array_map('intval', $c['usuarios']));
        $pdo->exec("DELETE FROM solicitacoes_vaga WHERE solicitante_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE actor_usuario_id IN ({$lu}) OR target_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
    zzLimparSetores($pdo, $c['setores']);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — aprovação de líder pelo Gestor.\n";
