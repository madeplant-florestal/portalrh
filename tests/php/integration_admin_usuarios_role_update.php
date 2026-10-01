<?php

/**
 * Integração — Bloco 4 (2026-10, pedido do RH), item 2: alteração de perfil pela tela de usuário
 * (AdminUsuariosController::updateRole() + User::attemptRoleUpdate()/canManageUser()). O endpoint e
 * a validação já existiam (ver unit_admin_usuarios_rh_acl.php para a ACL do controller); esta suíte
 * cobre o comportamento do backend que a nova UI (app/views/admin/usuarios/show.php) passou a expor.
 * Fixtures ZZROLE-* com limpeza em `finally`. Prova, contra o banco:
 *   - perfil pode ser alterado (admin muda role de um usuário comum) e a mudança persiste;
 *   - usuário protegido (is_supervisor=1) não tem o perfil alterado por um ator não-supervisor
 *     (User::canManageUser()) — nem supervisor, nem role são tocados — e fica registrado em
 *     auditoria_usuarios (blocked_role_change);
 *   - um ator supervisor PODE alterar o perfil de outro supervisor;
 *   - role fora do whitelist (admin/rh/viewer) nunca é o que o controller grava (mesma regra de
 *     AdminUsuariosController::updateRole());
 *   - Ajuste pós-Bloco 4 (2026-10, pedido do RH): updateRole() passou a aceitar admin/rh (não só
 *     admin); a autoelevação indevida agora é impedida por um guard explícito no controller — o
 *     ator nunca altera o PRÓPRIO perfil por este endpoint, independentemente da role.
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
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$ip = '203.0.113.9';

$roleDe = static function (int $id) use ($pdo): ?string {
    $stmt = $pdo->prepare('SELECT role FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (string)$r;
};
$logsBloqueio = static function (int $alvo) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT * FROM auditoria_usuarios WHERE target_usuario_id = ? AND action = 'blocked_role_change' ORDER BY id");
    $stmt->execute([$alvo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

try {
    // ---- Fixtures --------------------------------------------------------------------------------
    $adminAtorId = User::create('ZZROLE Admin Ator', 'admin.zzrole.' . $suffix . '@teste.local', $senha, 'admin');
    $criados[] = $adminAtorId;
    $adminAtor = User::findById($adminAtorId);

    $comumId = User::create('ZZROLE Comum', 'comum.zzrole.' . $suffix . '@teste.local', $senha, 'viewer');
    $criados[] = $comumId;

    $supervisorAlvoId = User::create('ZZROLE Supervisor Alvo', 'supalvo.zzrole.' . $suffix . '@teste.local', $senha, 'rh');
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supervisorAlvoId]);
    $criados[] = $supervisorAlvoId;

    $supervisorAtorId = User::create('ZZROLE Supervisor Ator', 'supator.zzrole.' . $suffix . '@teste.local', $senha, 'viewer');
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supervisorAtorId]);
    $criados[] = $supervisorAtorId;
    $supervisorAtor = User::findById($supervisorAtorId);

    // ---- 1) Perfil pode ser alterado e a mudança persiste -----------------------------------------
    $check($roleDe($comumId) === 'viewer', 'fixture: ZZROLE Comum nasce com role=viewer');
    $ok1 = User::attemptRoleUpdate($comumId, 'rh', $adminAtor, $ip);
    $check($ok1 === true, '(perfil pode ser alterado) attemptRoleUpdate() retorna true para usuário comum');
    $check($roleDe($comumId) === 'rh', '(persistência) role gravada no banco é \'rh\' após a alteração');

    $ok1b = User::attemptRoleUpdate($comumId, 'admin', $adminAtor, $ip);
    $check($ok1b === true && $roleDe($comumId) === 'admin', '(persistência) uma segunda alteração (rh -> admin) também persiste — não é um efeito de criação única');

    // ---- 2) Usuário protegido (supervisor) não tem o perfil alterado por ator não-supervisor -------
    $roleOriginalSupervisor = $roleDe($supervisorAlvoId);
    $check(User::canManageUser($adminAtor, User::findById($supervisorAlvoId)) === false, '(proteção) canManageUser() recusa ator admin comum contra alvo supervisor');
    $ok2 = User::attemptRoleUpdate($supervisorAlvoId, 'viewer', $adminAtor, $ip);
    $check($ok2 === false, '(proteção) attemptRoleUpdate() recusa alterar o perfil de um supervisor quando o ator não é supervisor');
    $check($roleDe($supervisorAlvoId) === $roleOriginalSupervisor, '(proteção) role do supervisor-alvo permanece inalterada após a tentativa recusada');
    $logs2 = $logsBloqueio($supervisorAlvoId);
    $check(count($logs2) === 1 && (int)$logs2[0]['actor_usuario_id'] === $adminAtorId, '(auditoria) blocked_role_change registrado com o ator correto em auditoria_usuarios');

    // ---- 3) Ator supervisor PODE alterar o perfil de outro supervisor ------------------------------
    $check(User::canManageUser($supervisorAtor, User::findById($supervisorAlvoId)) === true, '(supervisor-sobre-supervisor) canManageUser() permite quando o ator também é supervisor');
    $ok3 = User::attemptRoleUpdate($supervisorAlvoId, 'viewer', $supervisorAtor, $ip);
    $check($ok3 === true && $roleDe($supervisorAlvoId) === 'viewer', '(supervisor-sobre-supervisor) attemptRoleUpdate() grava a alteração quando o ator é supervisor');

    // ---- 4) Whitelist de perfil e gate de ACL são responsabilidade do controller (updateRole) ------
    $corpoController = (string)file_get_contents(APP_PATH . '/controllers/AdminUsuariosController.php');
    $corpoUpdateRole = (static function (string $source): string {
        $r = new ReflectionMethod(AdminUsuariosController::class, 'updateRole');
        $linhas = file($r->getFileName());
        return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    })($corpoController);
    $check(
        (bool)preg_match("/in_array\(\\\$role,\s*\['admin',\s*'rh',\s*'viewer'\],\s*true\)/", $corpoUpdateRole),
        '(whitelist) AdminUsuariosController::updateRole() restringe o valor recebido a admin/rh/viewer antes de chamar attemptRoleUpdate()'
    );
    $check(
        (bool)preg_match("/Auth::requireRole\(\['admin',\s*'rh'\]\)/", $corpoUpdateRole),
        "(Ajuste pós-Bloco 4) updateRole() agora aceita admin/rh — RH também altera Perfil"
    );
    $check(
        str_contains($corpoUpdateRole, '$atorId') && str_contains($corpoUpdateRole, '(int)$id === $atorId') && str_contains($corpoUpdateRole, 'Não é possível alterar o próprio perfil'),
        '(autoelevação) updateRole() recusa explicitamente o ator alterar o PRÓPRIO perfil, antes de chegar em attemptRoleUpdate() — vale para admin e para RH'
    );

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nADMIN_USUARIOS_ROLE_UPDATE_OK\n";
} finally {
    foreach (array_reverse($criados) as $id) {
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
}
