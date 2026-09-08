<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_usuario_colaborador_acesso (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
$temColuna = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_colaboradores' AND COLUMN_NAME = 'pode_solicitar_vaga'"
)->fetchColumn();
if ($temColuna === 0) {
    echo "SKIP integration_usuario_colaborador_acesso (migration 2026-09-08-usuario-colaboradores-pode-solicitar-vaga.sql nao aplicada)\n";
    exit(0);
}

// Colaboradores existentes ainda SEM vínculo de acesso.
$colabs = $pdo->query(
    "SELECT c.id FROM colaboradores c
     LEFT JOIN usuario_colaboradores uc ON uc.colaborador_id = c.id
     WHERE uc.id IS NULL ORDER BY c.id ASC LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);
if (count($colabs) < 2) {
    echo "SKIP integration_usuario_colaborador_acesso (colaboradores sem vinculo insuficientes)\n";
    exit(0);
}
[$colabA, $colabB] = array_map('intval', $colabs);

$sfx = substr((string)time(), -6) . random_int(100, 999);
$emailA = "zz.acesso.a.{$sfx}@example.test";
$emailB = "zz.acesso.b.{$sfx}@example.test";
$hash = password_hash('Xx1!' . bin2hex(random_bytes(6)), PASSWORD_BCRYPT);
$userA = User::create('ZZ ACESSO A ' . $sfx, $emailA, $hash, 'viewer');
$userB = User::create('ZZ ACESSO B ' . $sfx, $emailB, $hash, 'viewer');

$limpar = static function () use ($pdo, $userA, $userB): void {
    $pdo->prepare('DELETE FROM usuario_colaboradores WHERE usuario_id IN (?, ?)')->execute([$userA, $userB]);
    $pdo->prepare('DELETE FROM usuarios WHERE id IN (?, ?)')->execute([$userA, $userB]);
};

try {
    $repo = new UsuarioColaboradorRepository($pdo);

    // 1) vincular cria o vínculo com flags zeradas
    $vId = $repo->vincular($colabA, $userA);
    $assert($vId > 0, 'Caso 1: vincular deveria retornar o id do vínculo.');
    $v = $repo->findByColaboradorId($colabA);
    $assert($v !== null && (int)$v['usuario_id'] === $userA, 'Caso 1: vínculo persistido.');
    $assert((int)$v['is_gestor'] === 0 && (int)$v['pode_solicitar_vaga'] === 0 && (int)$v['ativo'] === 1, 'Caso 1: flags nascem 0, ativo 1.');

    // 2) idempotente para o mesmo par
    $assert($repo->vincular($colabA, $userA) === $vId, 'Caso 2: revincular o mesmo par é idempotente.');

    // 3) colaborador já vinculado a outro usuário -> erro
    $err = false;
    try {
        $repo->vincular($colabA, $userB);
    } catch (\RuntimeException $e) {
        $err = true;
    }
    $assert($err, 'Caso 3: vincular colaborador já vinculado a outro usuário deveria falhar.');

    // 4) usuário já vinculado a outro colaborador -> erro
    $err = false;
    try {
        $repo->vincular($colabB, $userA);
    } catch (\RuntimeException $e) {
        $err = true;
    }
    $assert($err, 'Caso 4: vincular usuário já vinculado a outro colaborador deveria falhar.');

    // 5) atualizarPapeis
    $repo->vincular($colabB, $userB);
    $repo->atualizarPapeis($colabB, ['is_gestor' => 1, 'pode_solicitar_vaga' => 1, 'is_rh' => 0, 'lider_colaborador_id' => $colabA]);
    $vB = $repo->findByColaboradorId($colabB);
    $assert((int)$vB['is_gestor'] === 1 && (int)$vB['pode_solicitar_vaga'] === 1, 'Caso 5: flags atualizadas.');
    $assert((int)$vB['lider_colaborador_id'] === $colabA, 'Caso 5: líder imediato definido.');
    $repo->atualizarPapeis($colabB, ['lider_colaborador_id' => null]);
    $assert($repo->findByColaboradorId($colabB)['lider_colaborador_id'] === null, 'Caso 5: líder imediato pode ser removido (NULL).');

    // 6) desativar preserva o vínculo
    $repo->atualizarPapeis($colabB, ['ativo' => 0]);
    $vB = $repo->findByColaboradorId($colabB);
    $assert($vB !== null && (int)$vB['ativo'] === 0, 'Caso 6: desativar mantém a linha, só ativo=0.');

    // 7) colaboradoresComAcessoAtivo só traz ativos
    $ativos = array_column($repo->colaboradoresComAcessoAtivo(), 'colaborador_id');
    $assert(in_array($colabA, $ativos, true), 'Caso 7: colabA (ativo) aparece.');
    $assert(!in_array($colabB, $ativos, true), 'Caso 7: colabB (desativado) não aparece.');

    echo "OK integration_usuario_colaborador_acesso\n";
} finally {
    $limpar();
}
