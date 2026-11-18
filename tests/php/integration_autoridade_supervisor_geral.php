<?php

/**
 * Integração — Etapa 2A-bis (2026-11): hierarquia Supervisor Geral > Administrador > RH > (Gestor) > Visualizador.
 *
 * Prova, com banco real e requisições em processos próprios, que:
 *   - a política central (Autoridade) decide quem gerencia quem e quem atribui qual perfil;
 *   - Administrador NÃO redefine a senha, não altera, não desativa nem exclui o Supervisor Geral (HTTP, API JSON e
 *     Colaboradores → Acesso); RH nunca promove a admin, nem toca em administradores; viewer/RH com is_supervisor=1 não
 *     ganham nada pelo flag;
 *   - o último Supervisor Geral ativo não é excluído, desativado nem rebaixado, inclusive sob CONCORRÊNCIA;
 *   - /admin/usuarios/supervisor/garantir só vale para Supervisor Geral e nunca redefine a senha de conta existente;
 *   - toda operação sensível (e a tentativa bloqueada) é auditada.
 * Supervisores já existentes no banco local são desativados durante o teste e RESTAURADOS no fim.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$criados = [];
$restaurar = [];            // id => email_verified_at original dos supervisores pré-existentes
$criouColaboradores = [];
$senhaForte = 'Qw7!zK9#mPx2';

$novo = static function (string $apelido, string $role, int $flag, bool $ativo = true) use ($pdo, $senha, $suffix, &$criados): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['ZZSG ' . $apelido, strtolower($apelido) . '.zzsg.' . $suffix . '@teste.local', $senha, $role, $flag, $ativo ? date('Y-m-d H:i:s') : null]);
    $id = (int)$pdo->lastInsertId();
    $criados[] = $id;
    return $id;
};
$linha = static fn(int $id): array => $pdo->query('SELECT role, is_supervisor, email_verified_at, senha_hash FROM usuarios WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
$existe = static fn(int $id): bool => (bool)$pdo->query('SELECT 1 FROM usuarios WHERE id = ' . $id)->fetchColumn();
$auditoria = static function (string $acao, int $alvo) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM auditoria_usuarios WHERE action = ? AND target_usuario_id = ?');
    $st->execute([$acao, $alvo]);
    return (int)$st->fetchColumn();
};
$requisitar = static function (int $userId, string $role, bool $sessaoSupervisor, string $controller, string $metodo, array $post = [], array $args = [], array $get = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sessaoSupervisor ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m)) {
        $status = (int)$m[1];
        $saida = substr($saida, 0, -strlen($m[0]));
    }
    return [$status, $saida];
};

try {
    // isola o banco local: supervisores pré-existentes ficam inativos durante o teste (restaurados no finally)
    foreach ($pdo->query("SELECT id, email_verified_at FROM usuarios WHERE role = 'admin' AND is_supervisor = 1 AND email_verified_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $restaurar[(int)$r['id']] = $r['email_verified_at'];
    }
    if ($restaurar !== []) {
        $pdo->exec('UPDATE usuarios SET email_verified_at = NULL WHERE id IN (' . implode(',', array_keys($restaurar)) . ')');
    }

    $sgA = $novo('sga', 'admin', 1);
    $sgB = $novo('sgb', 'admin', 1);
    $adm1 = $novo('adm1', 'admin', 0);
    $adm2 = $novo('adm2', 'admin', 0);
    $rh1 = $novo('rh1', 'rh', 0);
    $rh2 = $novo('rh2', 'rh', 0);
    $vw1 = $novo('vw1', 'viewer', 0);
    $vw2 = $novo('vw2', 'viewer', 0);
    $vwFlag = $novo('vwflag', 'viewer', 1);     // inconsistente: flag em viewer
    $rhFlag = $novo('rhflag', 'rh', 1);         // inconsistente: flag em RH
    $u = static fn(int $id): ?User => User::findById($id);

    // ---- 1. política pura -------------------------------------------------------------------------------------------------
    $check(Autoridade::nivel($u($sgA)) === Autoridade::SUPERVISOR_GERAL && Autoridade::nivel($u($adm1)) === Autoridade::ADMIN && Autoridade::nivel($u($rh1)) === Autoridade::RH && Autoridade::nivel($u($vw1)) === Autoridade::VIEWER, '(nível) admin+flag = Supervisor Geral; admin = Administrador; rh; viewer');
    $check(Autoridade::nivel($u($vwFlag)) === Autoridade::VIEWER && Autoridade::nivel($u($rhFlag)) === Autoridade::RH && !Autoridade::ehSupervisorGeral($u($vwFlag)) && !Autoridade::ehSupervisorGeral($u($rhFlag)), '(nível) is_supervisor em viewer/RH NÃO vira Supervisor Geral');
    $check(Autoridade::privilegioInconsistente($u($vwFlag)) && Autoridade::privilegioInconsistente($u($rhFlag)) && !Autoridade::privilegioInconsistente($u($sgA)) && !Autoridade::privilegioInconsistente($u($adm1)), '(inconsistência) flag fora de admin é sinalizado; Supervisor Geral e admin não');
    $matriz = [
        // [ator, alvo, podeGerenciar]
        [$sgA, $sgB, true], [$sgA, $adm1, true], [$sgA, $rh1, true], [$sgA, $vw1, true],
        [$adm1, $sgA, false], [$adm1, $adm2, true], [$adm1, $rh1, true], [$adm1, $vw1, true],
        [$rh1, $adm1, false], [$rh1, $sgA, false], [$rh1, $rh2, true], [$rh1, $vw1, true],
        [$vw1, $vw2, false], [$vw1, $rh1, false], [$vwFlag, $vw1, false], [$vwFlag, $sgA, false], [$vwFlag, $adm1, false],
        [$rhFlag, $sgA, false], [$rhFlag, $adm1, false], [$rhFlag, $vw1, true],
    ];
    $ruim = [];
    foreach ($matriz as [$a, $t, $esperado]) {
        if (Autoridade::podeGerenciar($u($a), $u($t)) !== $esperado) {
            $ruim[] = "{$u($a)->nome} -> {$u($t)->nome}";
        }
    }
    $check($ruim === [], '(podeGerenciar) matriz ator × alvo' . ($ruim === [] ? '' : ': divergências ' . implode(' | ', $ruim)));
    $check(Autoridade::podeAtribuirRole($u($rh1), $u($vw1), 'rh') && Autoridade::podeAtribuirRole($u($rh1), $u($rh2), 'viewer') && !Autoridade::podeAtribuirRole($u($rh1), $u($vw1), 'admin') && !Autoridade::podeAtribuirRole($u($rh1), $u($adm1), 'viewer') && !Autoridade::podeAtribuirRole($u($rh1), $u($sgA), 'viewer'), '(perfil) RH: só rh<->viewer, nunca promove a admin nem rebaixa admin/Supervisor Geral');
    $check(Autoridade::podeAtribuirRole($u($adm1), $u($adm2), 'viewer') && Autoridade::podeAtribuirRole($u($adm1), $u($vw1), 'admin') && !Autoridade::podeAtribuirRole($u($adm1), $u($sgA), 'viewer') && Autoridade::podeAtribuirRole($u($sgA), $u($adm1), 'rh'), '(perfil) Administrador altera outros admins comuns mas não o Supervisor Geral; Supervisor Geral altera admins');
    $check(!Autoridade::podeAtribuirRole($u($adm1), $u($adm1), 'viewer') && !Autoridade::podeAtribuirRole($u($sgA), $u($sgA), 'viewer') && !Autoridade::podeAtribuirRole($u($rh1), $u($rh1), 'admin'), '(perfil) ninguém altera o PRÓPRIO perfil');
    $check(!Autoridade::podeAtribuirRole($u($sgA), $u($sgB), 'viewer'), '(perfil) o perfil de um Supervisor Geral só muda depois de revogar o flag (nunca indiretamente)');
    $check(!Autoridade::podeAtribuirRole($u($vwFlag), $u($vw1), 'rh') && Autoridade::podeAtribuirRole($u($sgA), $u($vw1), 'gestor') === Autoridade::gestorDisponivel() && !Autoridade::podeAtribuirRole($u($sgA), $u($vw1), 'superadmin'), '(perfil) flag em viewer não atribui nada; perfil fora da whitelist é recusado');
    $check(Autoridade::podeAlterarSupervisorGeral($u($sgA)) && !Autoridade::podeAlterarSupervisorGeral($u($adm1)) && !Autoridade::podeAlterarSupervisorGeral($u($rhFlag)) && !Autoridade::podeAlterarSupervisorGeral($u($vwFlag)), '(flag) só o Supervisor Geral altera o flag');
    $check(Autoridade::flagEfetivo($u($vwFlag)) === 0 && Autoridade::flagEfetivo($u($sgA)) === 1, '(flag efetivo) leitores legados recebem 1 apenas para o Supervisor Geral de fato');

    // ---- 2. operações do model --------------------------------------------------------------------------------------------
    $check(User::attemptRoleUpdate($vw1, 'rh', $u($rh1), '127.0.0.1') && $linha($vw1)['role'] === 'rh' && $auditoria('role_change', $vw1) >= 1, '(perfil) RH altera viewer -> rh e a mudança é auditada');
    $check(User::attemptRoleUpdate($vw1, 'viewer', $u($rh1), '127.0.0.1') && $linha($vw1)['role'] === 'viewer', '(perfil) RH altera rh -> viewer');
    $check(!User::attemptRoleUpdate($vw1, 'admin', $u($rh1), '127.0.0.1') && $linha($vw1)['role'] === 'viewer' && $auditoria('blocked_role_change', $vw1) === 1, '(perfil) RH NÃO promove a admin (bloqueio auditado)');
    $check(!User::attemptRoleUpdate($adm1, 'viewer', $u($rh1), '127.0.0.1') && $linha($adm1)['role'] === 'admin', '(perfil) RH NÃO rebaixa administrador');
    $check(!User::attemptRoleUpdate($sgA, 'viewer', $u($adm1), '127.0.0.1') && $linha($sgA)['role'] === 'admin' && $linha($sgA)['is_supervisor'] == 1, '(perfil) Administrador NÃO altera o Supervisor Geral');
    $check(!User::attemptRoleUpdate($vw2, 'admin', $u($vwFlag), '127.0.0.1') && !User::attemptRoleUpdate($vw2, 'admin', $u($rhFlag), '127.0.0.1') && $linha($vw2)['role'] === 'viewer', '(perfil) viewer/RH com is_supervisor=1 NÃO elevam ninguém pelo flag');
    $check(User::attemptRoleUpdate($adm2, 'rh', $u($adm1), '127.0.0.1') && User::attemptRoleUpdate($adm2, 'admin', $u($adm1), '127.0.0.1'), '(perfil) Administrador altera outro administrador comum');
    $check(User::attemptRoleUpdate($adm2, 'viewer', $u($sgA), '127.0.0.1') && User::attemptRoleUpdate($adm2, 'admin', $u($sgA), '127.0.0.1'), '(perfil) Supervisor Geral administra administradores');

    // senhas
    $hashAntes = $linha($sgA)['senha_hash'];
    $r = User::adminChangePassword($sgA, $senhaForte, $u($adm1), '127.0.0.1');
    $check(($r['status'] ?? 0) === 403 && $linha($sgA)['senha_hash'] === $hashAntes && $auditoria('blocked_password_change', $sgA) === 1, '(senha) Administrador NÃO redefine a senha do Supervisor Geral (403 + auditoria, hash intacto)');
    $r = User::adminChangePassword($adm1, $senhaForte, $u($rh1), '127.0.0.1');
    $check(($r['status'] ?? 0) === 403, '(senha) RH não redefine senha de administrador');
    $r = User::adminChangePassword($vw1, $senhaForte, $u($rh1), '127.0.0.1');
    $check(($r['status'] ?? 0) === 403, '(senha) RH não redefine senha administrativa nem de um viewer (a função é só de admin)');
    foreach ([[$vwFlag, 'viewer com is_supervisor=1'], [$rhFlag, 'RH com is_supervisor=1']] as [$idAtor, $rot]) {
        $r = User::adminChangePassword($adm1, $senhaForte, $u($idAtor), '127.0.0.1');
        $check(($r['status'] ?? 0) === 403, "(senha) {$rot} não redefine senha pelo flag");
    }
    $r = User::adminChangePassword($adm2, $senhaForte, $u($adm1), '127.0.0.1');
    $check(($r['status'] ?? 0) === 200 && $auditoria('admin_password_change', $adm2) === 1, '(senha) Administrador redefine senha de outro administrador comum (auditado, sem registrar a senha)');
    $r = User::adminChangePassword($sgB, $senhaForte, $u($sgA), '127.0.0.1');
    $check(($r['status'] ?? 0) === 200, '(senha) Supervisor Geral redefine senha de outro Supervisor Geral');
    $detalhes = (string)$pdo->query("SELECT GROUP_CONCAT(details) FROM auditoria_usuarios WHERE action LIKE '%password%'")->fetchColumn();
    $check(!str_contains($detalhes, $senhaForte), '(auditoria) nenhuma senha é registrada');

    // exclusão e status
    $check(!User::attemptDelete($sgA, $u($adm1), '127.0.0.1') && $existe($sgA) && $auditoria('blocked_user_delete', $sgA) === 1, '(exclusão) Administrador NÃO exclui o Supervisor Geral (auditado)');
    $check(!User::attemptDelete($adm1, $u($adm1), '127.0.0.1') && $existe($adm1), '(exclusão) autoexclusão é bloqueada');
    $check(!User::attemptDelete($sgA, $u($sgA), '127.0.0.1') && $existe($sgA), '(exclusão) autoexclusão de Supervisor Geral é bloqueada');
    $check(!User::attemptDelete($adm2, $u($rh1), '127.0.0.1') && $existe($adm2), '(exclusão) RH não exclui administrador');
    $check(!User::attemptSetActive($sgA, false, $u($adm1), '127.0.0.1') && $linha($sgA)['email_verified_at'] !== null, '(status) Administrador NÃO desativa o Supervisor Geral');
    $check(!User::attemptSetActive($adm1, false, $u($adm1), '127.0.0.1'), '(status) ninguém desativa a própria conta pela via administrativa');
    $check(User::attemptSetActive($vw2, false, $u($adm1), '127.0.0.1') && $linha($vw2)['email_verified_at'] === null && User::attemptSetActive($vw2, true, $u($adm1), '127.0.0.1') && $auditoria('user_status_change', $vw2) === 2, '(status) Administrador ativa/desativa usuário comum (auditado)');
    $check(!User::attemptSetActive($adm2, false, $u($rh1), '127.0.0.1'), '(status) RH não desativa administrador');

    // flag: concessão/revogação
    $check(!User::concederSupervisorGeral($adm2, $u($adm1), '127.0.0.1') && $linha($adm2)['is_supervisor'] == 0 && $auditoria('blocked_supervisor_grant', $adm2) === 1, '(flag) Administrador NÃO concede Supervisor Geral (auditado)');
    $check(!User::concederSupervisorGeral($rh2, $u($sgA), '127.0.0.1') && $linha($rh2)['is_supervisor'] == 0 && User::$ultimoMotivo === 'alvo_nao_admin', '(flag) não se concede o flag a conta que não é admin (e a role não é mudada por baixo)');
    $check(User::concederSupervisorGeral($adm2, $u($sgA), '127.0.0.1') && $linha($adm2)['is_supervisor'] == 1 && $auditoria('supervisor_grant', $adm2) === 1, '(flag) Supervisor Geral concede o flag a um admin (auditado)');
    $check(!User::revogarSupervisorGeral($adm2, $u($adm1), '127.0.0.1') && $linha($adm2)['is_supervisor'] == 1, '(flag) Administrador NÃO remove o flag de ninguém');
    $check(!User::revogarSupervisorGeral($sgA, $u($sgA), '127.0.0.1') && $linha($sgA)['is_supervisor'] == 1 && User::$ultimoMotivo === 'autoalteracao', '(flag) ninguém remove o PRÓPRIO privilégio');
    $check(User::revogarSupervisorGeral($adm2, $u($sgA), '127.0.0.1') && $linha($adm2)['is_supervisor'] == 0 && $auditoria('supervisor_revoke', $adm2) === 1, '(flag) Supervisor Geral remove o flag de outro (auditado)');

    // ---- 3. último Supervisor Geral ativo ---------------------------------------------------------------------------------
    // ativos agora: sgA, sgB. O ator "sgC" é Supervisor Geral INATIVO (só para poder agir no model, sem contar como ativo).
    $sgC = $novo('sgc', 'admin', 1, false);
    $check(User::attemptDelete($sgB, $u($sgC), '127.0.0.1') && !$existe($sgB), '(último) com 2 ativos, excluir um é permitido');
    $check(!User::attemptDelete($sgA, $u($sgC), '127.0.0.1') && $existe($sgA) && User::$ultimoMotivo === 'ultimo_supervisor', '(último) NÃO exclui o último Supervisor Geral ativo');
    $check(!User::attemptSetActive($sgA, false, $u($sgC), '127.0.0.1') && $linha($sgA)['email_verified_at'] !== null && User::$ultimoMotivo === 'ultimo_supervisor', '(último) NÃO desativa o último Supervisor Geral ativo');
    $check(!User::revogarSupervisorGeral($sgA, $u($sgC), '127.0.0.1') && $linha($sgA)['is_supervisor'] == 1 && User::$ultimoMotivo === 'ultimo_supervisor', '(último) NÃO remove o privilégio do último Supervisor Geral ativo');
    $check($auditoria('blocked_user_delete', $sgA) >= 1 && $auditoria('blocked_status_change', $sgA) >= 1 && $auditoria('blocked_supervisor_revoke', $sgA) >= 1, '(último) cada tentativa bloqueada fica auditada');

    // concorrência: 2 supervisores ativos, dois processos tentam excluir UM CADA, ao mesmo tempo; só um pode vencer
    $rodadas = 4;
    $sobreviventes = [];
    for ($i = 0; $i < $rodadas; $i++) {
        $p = $novo('par' . $i . 'a', 'admin', 1);
        $q = $novo('par' . $i . 'b', 'admin', 1);
        $pdo->exec('UPDATE usuarios SET email_verified_at = NULL WHERE id = ' . $sgA); // isola: só p e q ativos
        $inicio = microtime(true) + 1.2;
        $procs = [];
        foreach ([[$p, $q], [$q, $p]] as [$alvo, $outro]) {
            // o ator é o outro supervisor do par? não: usa sgC (inativo) para que ambos sejam "gerenciáveis" e só a trava decida
            $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixture_remover_supervisor.php', (string)$sgC, (string)$alvo, (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
            $pipesDe[] = $pp;
        }
        $saidas = [];
        foreach ($pipesDe as $k => $pp) {
            $saidas[] = trim((string)stream_get_contents($pp[1]));
            stream_get_contents($pp[2]);
        }
        foreach ($procs as $pr) {
            proc_close($pr);
        }
        $pipesDe = [];
        $vivos = (int)$existe($p) + (int)$existe($q);
        $oks = count(array_filter($saidas, static fn(string $s): bool => $s === 'OK'));
        $check($vivos === 1 && $oks === 1, "(concorrência, rodada " . ($i + 1) . ") dois processos tentam excluir os dois últimos Supervisores Gerais ao mesmo tempo: exatamente um é excluído (saídas: " . implode(' / ', $saidas) . ')');
        $pdo->exec('UPDATE usuarios SET email_verified_at = NOW() WHERE id = ' . $sgA);
        foreach ([$p, $q] as $x) {
            if ($existe($x)) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$x]);
            }
        }
    }
    // sgA volta a ser o único ativo para as próximas seções
    $pdo->exec('UPDATE usuarios SET email_verified_at = NOW() WHERE id = ' . $sgA);

    // ---- 4. HTTP: API de senha, perfil, exclusão, status ------------------------------------------------------------------
    $sgD = $novo('sgd', 'admin', 1);           // segundo supervisor ativo: permite as operações legítimas abaixo
    $api = static fn(int $ator, string $role, bool $sess, int $alvo, ?array $corpo = null): array => $requisitar($ator, $role, $sess, 'AdminUsuariosController', 'adminChangePasswordApi', $corpo ?? ['new_password' => $senhaForte], [(string)$alvo]);

    $h = $linha($sgA)['senha_hash'];
    [$st] = $api($adm1, 'admin', false, $sgA);
    $check($st === 403 && $linha($sgA)['senha_hash'] === $h, '(HTTP API senha) Administrador → Supervisor Geral: 403, hash intacto');
    [$st] = $api($adm1, 'admin', false, $sgA, ['new_password' => $senhaForte, 'is_supervisor' => 1]);
    $check($st === 403, '(HTTP API senha) parâmetros extras (is_supervisor) não mudam o resultado');
    foreach ([[$rh1, 'rh', false, 'RH'], [$rhFlag, 'rh', true, 'RH com is_supervisor=1'], [$vwFlag, 'viewer', true, 'viewer com is_supervisor=1'], [$vw1, 'viewer', false, 'viewer']] as [$idAtor, $role, $sess, $rot]) {
        $h2 = $linha($adm1)['senha_hash'];
        [$st] = $api($idAtor, $role, $sess, $adm1);
        $check($st === 403 && $linha($adm1)['senha_hash'] === $h2, "(HTTP API senha) {$rot} → Administrador: 403, hash intacto");
    }
    [$st] = $api($adm1, 'admin', false, $adm2);
    $check($st === 200, '(HTTP API senha) Administrador → outro administrador comum: 200');
    [$st] = $api($sgA, 'admin', true, $adm1);
    $check($st === 200, '(HTTP API senha) Supervisor Geral → administrador: 200');
    [$st] = $api($sgA, 'admin', true, $sgD);
    $check($st === 200, '(HTTP API senha) Supervisor Geral → outro Supervisor Geral: 200');

    $rota = static fn(int $ator, string $role, bool $sess, string $metodo, int $alvo, array $post): array => $requisitar($ator, $role, $sess, 'AdminUsuariosController', $metodo, $post === [] ? ['_' => '1'] : $post, [(string)$alvo]);
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $vw2, ['role' => 'admin']);
    $check($st === 403 && $linha($vw2)['role'] === 'viewer', '(HTTP perfil) RH tentando promover usuário a admin: 403');
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $adm1, ['role' => 'viewer']);
    $check($st === 403 && $linha($adm1)['role'] === 'admin', '(HTTP perfil) RH tentando rebaixar administrador: 403');
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $vw2, ['role' => 'rh']);
    $check($linha($vw2)['role'] === 'rh', '(HTTP perfil) RH altera viewer -> rh');
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $vw2, ['role' => 'viewer']);
    $check($linha($vw2)['role'] === 'viewer', '(HTTP perfil) RH altera rh -> viewer');
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $vw2, ['role' => 'superadmin']);
    $check($st === 400 && $linha($vw2)['role'] === 'viewer', '(HTTP perfil) perfil inventado é recusado com 400 (não vira viewer em silêncio)');
    [$st] = $rota($rh1, 'rh', false, 'updateRole', $rh1, ['role' => 'admin']);
    $check($st === 403 && $linha($rh1)['role'] === 'rh', '(HTTP perfil) autoelevação do RH: 403');
    [$st] = $rota($adm1, 'admin', false, 'updateRole', $sgA, ['role' => 'viewer']);
    $check($st === 403 && $linha($sgA)['role'] === 'admin', '(HTTP perfil) Administrador tentando rebaixar Supervisor Geral: 403');
    [$st] = $rota($vwFlag, 'viewer', true, 'updateRole', $vw2, ['role' => 'admin']);
    $check($st === 403 && $linha($vw2)['role'] === 'viewer', '(HTTP perfil) viewer com is_supervisor=1 (inclusive na sessão): 403');
    [$st] = $rota($rhFlag, 'rh', true, 'updateRole', $adm1, ['role' => 'viewer']);
    $check($st === 403 && $linha($adm1)['role'] === 'admin', '(HTTP perfil) RH com is_supervisor=1 não rebaixa administrador: 403');

    [$st] = $rota($adm1, 'admin', false, 'delete', $sgA, []);
    $check($st === 403 && $existe($sgA), '(HTTP exclusão) Administrador → Supervisor Geral: 403');
    [$st] = $rota($adm1, 'admin', false, 'delete', $adm1, []);
    $check($st === 403 && $existe($adm1), '(HTTP exclusão) autoexclusão: 403');
    [$st] = $rota($rh1, 'rh', false, 'delete', $vw2, []);
    $check($st === 403 && $existe($vw2), '(HTTP exclusão) RH não exclui (rota é de admin)');
    [$st] = $rota($adm1, 'admin', false, 'updateStatus', $sgA, ['active' => '0']);
    $check($st === 403 && $linha($sgA)['email_verified_at'] !== null, '(HTTP status) Administrador não desativa Supervisor Geral: 403');
    [$st] = $rota($adm1, 'admin', false, 'updatePermissoes', $sgA, ['permissao_ids' => []]);
    $check($st === 403, '(HTTP permissões) Administrador não altera permissões do Supervisor Geral: 403');
    [$st] = $rota($adm1, 'admin', false, 'alterarSupervisorGeral', $adm2, ['acao' => 'conceder']);
    $check($st === 403 && $linha($adm2)['is_supervisor'] == 0, '(HTTP flag) Administrador tentando conceder Supervisor Geral: 403');
    [$st] = $rota($vwFlag, 'viewer', true, 'alterarSupervisorGeral', $vw2, ['acao' => 'conceder']);
    $check($st === 403 && $linha($vw2)['is_supervisor'] == 0, '(HTTP flag) viewer com is_supervisor=1 tentando conceder: 403');
    [$st] = $rota($sgA, 'admin', true, 'alterarSupervisorGeral', $adm2, ['acao' => 'conceder']);
    $check($linha($adm2)['is_supervisor'] == 1, '(HTTP flag) Supervisor Geral concede o flag a um admin');
    [$st] = $rota($sgA, 'admin', true, 'alterarSupervisorGeral', $adm2, ['acao' => 'revogar']);
    $check($linha($adm2)['is_supervisor'] == 0, '(HTTP flag) Supervisor Geral remove o flag');
    [$st] = $rota($sgA, 'admin', true, 'alterarSupervisorGeral', $adm2, ['acao' => 'xyz']);
    $check($st === 400, '(HTTP flag) ação inválida: 400');
    [$st] = $rota($sgA, 'admin', true, 'alterarSupervisorGeral', $sgA, ['acao' => 'revogar']);
    $check($linha($sgA)['is_supervisor'] == 1, '(HTTP flag) Supervisor Geral não remove o próprio privilégio');
    [$st] = $rota($adm1, 'admin', false, 'delete', $adm2, []);
    $check(!$existe($adm2), '(HTTP exclusão) Administrador exclui outro administrador comum (permitido pela política)');

    // ---- 5. garantir ------------------------------------------------------------------------------------------------------
    $emailCfg = (string)(Config::app()['security']['supervisor_email'] ?? '');
    if ($emailCfg !== '') {
        $existente = User::findByEmail($emailCfg);
        $criouCfg = false;
        if (!$existente) {
            $cfgId = User::ensureSupervisor('Supervisor', $emailCfg, (string)Config::app()['security']['supervisor_password']);
            $criouCfg = true;
        } else {
            $cfgId = (int)$existente->id;
            $restaurar[$cfgId] = $restaurar[$cfgId] ?? $existente->email_verified_at;
        }
        $hCfg = $linha($cfgId)['senha_hash'];
        $pdo->exec('UPDATE usuarios SET senha_hash = ' . $pdo->quote(password_hash('SenhaPropriaDoSupervisor#1', PASSWORD_BCRYPT)) . ' WHERE id = ' . $cfgId);
        $hCfg = $linha($cfgId)['senha_hash'];
        [$st, $corpo] = $requisitar($adm1, 'admin', false, 'AdminSupervisorController', 'ensure', ['x' => '1']);
        $check($st === 403 && $linha($cfgId)['senha_hash'] === $hCfg, '(garantir) Administrador comum: 403 e a senha do supervisor configurado NÃO muda');
        [$st] = $requisitar($vwFlag, 'viewer', true, 'AdminSupervisorController', 'ensure', ['x' => '1']);
        $check($st === 403 && $linha($cfgId)['senha_hash'] === $hCfg, '(garantir) viewer com is_supervisor=1: 403');
        [$st] = $requisitar($rhFlag, 'rh', true, 'AdminSupervisorController', 'ensure', ['x' => '1']);
        $check($st === 403 && $linha($cfgId)['senha_hash'] === $hCfg, '(garantir) RH com is_supervisor=1: 403');
        [$st] = $requisitar($sgA, 'admin', true, 'AdminSupervisorController', 'ensure', ['x' => '1']);
        $check($linha($cfgId)['senha_hash'] === $hCfg && $linha($cfgId)['role'] === 'admin' && $linha($cfgId)['is_supervisor'] == 1 && $auditoria('supervisor_ensured', $cfgId) >= 1, '(garantir) Supervisor Geral: restaura role/flag, NÃO redefine a senha de conta existente, auditado');
        if ($criouCfg) {
            $criados[] = $cfgId;
        }
    } else {
        echo "  [skip] supervisor_email não configurado\n";
    }

    // ---- 6. Colaboradores → Acesso: senha temporária ---------------------------------------------------------------------
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
    if ($cargoId > 0) {
        $repo = new UsuarioColaboradorRepository();
        $colab = static function (string $rot, int $usuarioId) use ($pdo, $suffix, $cargoId, $repo, &$criouColaboradores): int {
            $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id) VALUES (?, ?, ?)')->execute(['ZZSG Colab ' . $rot, 'zzsg-' . $rot . '-' . $suffix, $cargoId]);
            $id = (int)$pdo->lastInsertId();
            $criouColaboradores[] = $id;
            $repo->vincular($id, $usuarioId);
            return $id;
        };
        $adm3 = $novo('adm3', 'admin', 0);
        $cSg = $colab('sg', $sgD);
        $cAdm = $colab('adm', $adm3);
        $cVw = $colab('vw', $vw1);
        $acesso = static fn(int $ator, string $role, bool $sess, int $colaborador): array => $requisitar($ator, $role, $sess, 'AdminColaboradoresController', 'updateAcesso', ['acao' => 'redefinir_senha'], [(string)$colaborador]);
        $h = $linha($sgD)['senha_hash'];
        [$st] = $acesso($adm1, 'admin', false, $cSg);
        $check($st === 403 && $linha($sgD)['senha_hash'] === $h && $auditoria('blocked_password_change', $sgD) >= 1, '(Colaboradores → Acesso) Administrador NÃO gera senha temporária para o Supervisor Geral vinculado (403, hash intacto)');
        [$st] = $acesso($rh1, 'rh', false, $cAdm);
        $check($st === 403, '(Colaboradores → Acesso) RH não redefine senha (nem de administrador)');
        [$st] = $acesso($rhFlag, 'rh', true, $cSg);
        $check($st === 403 && $linha($sgD)['senha_hash'] === $h, '(Colaboradores → Acesso) RH com is_supervisor=1: 403');
        [$st] = $acesso($vwFlag, 'viewer', true, $cVw);
        $check($st === 403, '(Colaboradores → Acesso) viewer com is_supervisor=1: 403');
        $h3 = $linha($adm3)['senha_hash'];
        $acesso($adm1, 'admin', false, $cAdm);
        $check($linha($adm3)['senha_hash'] !== $h3 && $auditoria('admin_password_change', $adm3) >= 1, '(Colaboradores → Acesso) Administrador gera senha temporária para administrador comum (auditado)');
        $acesso($sgA, 'admin', true, $cSg);
        $check($linha($sgD)['senha_hash'] !== $h, '(Colaboradores → Acesso) Supervisor Geral gera senha temporária para outro Supervisor Geral');
    } else {
        echo "  [skip] sem cargos no banco local\n";
    }
} finally {
    if ($criouColaboradores !== []) {
        $ph = implode(',', array_map('intval', $criouColaboradores));
        $pdo->exec("DELETE FROM usuario_colaboradores WHERE colaborador_id IN ($ph)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($ph)");
    }
    if ($criados !== []) {
        $pdo->exec('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados)) . ')');
    }
    foreach ($restaurar as $id => $verificado) {
        $pdo->prepare('UPDATE usuarios SET email_verified_at = ? WHERE id = ?')->execute([$verificado, $id]);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — hierarquia de autoridade (Supervisor Geral).\n";
