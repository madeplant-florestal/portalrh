<?php

/**
 * Fixture (NÃO é teste): cria/limpa, no banco de DESENVOLVIMENTO, os usuários ZZNAV* usados pelo teste de navegador da matriz de
 * permissões contra a aplicação REAL (tests/matriz-permissoes-real.spec.js).
 * Uso: php fixture_usuarios_navegador.php criar <prefixo>   → imprime JSON com e-mail do ator, senha e ids dos alvos
 *      php fixture_usuarios_navegador.php limpar <prefixo>
 * Só roda com ambiente de teste (ResiduosTeste::garantirAmbienteDeTeste) e só toca em e-mails <prefixo>.*@teste.local.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once __DIR__ . '/lib/ResiduosTeste.php';

[, $acao, $prefixo] = $argv + ['', '', ''];
if (!preg_match('/^zznav[a-z0-9]{4,12}$/', $prefixo)) {
    fwrite(STDERR, "prefixo inválido\n");
    exit(2);
}
$pdo = Database::conn();
ResiduosTeste::garantirAmbienteDeTeste($pdo);

if ($acao === 'limpar') {
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email LIKE ?');
    $st->execute([$prefixo . '.%@teste.local']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    echo "ok\n";
    exit(0);
}

$senha = 'Nav!Teste#2026x';
$hash = password_hash($senha, PASSWORD_BCRYPT);
$mk = static function (string $suf, string $role) use ($prefixo, $hash, $pdo): int {
    $id = User::create('ZZNAV ' . $suf, "{$prefixo}.{$suf}@teste.local", $hash, $role);
    User::setActiveStatus($id, true);
    $pdo->prepare('UPDATE usuarios SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')->execute([$id]);
    return $id;
};
$ator = $mk('ator', 'admin');
$viewer = $mk('viewer', 'viewer');
$gestor = $mk('gestor', 'gestor');
$admin = $mk('admin', 'admin');
$rh = $mk('rh', 'rh');
$grava = static function (int $uid, array $codigos) use ($pdo): void {
    foreach ($codigos as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) SELECT ?, id FROM permissoes WHERE codigo = ?')->execute([$uid, $c]);
    }
};
$grava($viewer, ['pdi.visualizar', 'empresas.criar']); // uma transitória + uma legada incompatível
$grava($gestor, ['colaboradores.visualizar']);
$grava($admin, ['pdi.visualizar', 'dashboard.visualizar']);
echo json_encode(['email' => "{$prefixo}.ator@teste.local", 'senha' => $senha, 'viewer' => $viewer, 'gestor' => $gestor, 'admin' => $admin, 'rh' => $rh], JSON_UNESCAPED_UNICODE) . "\n";
