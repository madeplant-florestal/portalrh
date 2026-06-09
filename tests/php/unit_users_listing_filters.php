<?php
declare(strict_types=1);
set_time_limit(10);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP unit_users_listing_filters (MySQL indisponível)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$suffix = (string)time();
$emailA = "lista_a_{$suffix}@rhmadeplant.local";
$emailB = "lista_b_{$suffix}@rhmadeplant.local";
$emailC = "lista_c_{$suffix}@rhmadeplant.local";

$idA = User::create('Lista Admin', $emailA, password_hash('Abcd1234!xyz', PASSWORD_BCRYPT), 'admin');
$idB = User::create('Lista RH', $emailB, password_hash('Abcd1234!xyz', PASSWORD_BCRYPT), 'rh');
$idC = User::create('Lista Viewer', $emailC, password_hash('Abcd1234!xyz', PASSWORD_BCRYPT), 'viewer');

User::setActiveStatus($idA, true);
User::setActiveStatus($idB, false);
User::setActiveStatus($idC, true);

$resAll = User::paginateForAdmin(['q' => $suffix], 1, 20);
if (($resAll['total'] ?? 0) < 3) {
    fwrite(STDERR, "Falha: listagem geral não retornou usuários esperados.\n");
    exit(1);
}

$resRole = User::paginateForAdmin(['q' => $suffix, 'role' => 'rh'], 1, 20);
if (($resRole['total'] ?? 0) !== 1) {
    fwrite(STDERR, "Falha: filtro por role não retornou 1 usuário RH.\n");
    exit(1);
}

$resActive = User::paginateForAdmin(['q' => $suffix, 'status' => 'active'], 1, 20);
if (($resActive['total'] ?? 0) < 2) {
    fwrite(STDERR, "Falha: filtro por status ativo não retornou usuários esperados.\n");
    exit(1);
}

$resInactive = User::paginateForAdmin(['q' => $suffix, 'status' => 'inactive'], 1, 20);
if (($resInactive['total'] ?? 0) !== 1) {
    fwrite(STDERR, "Falha: filtro por status inativo não retornou 1 usuário.\n");
    exit(1);
}

$resPaged = User::paginateForAdmin(['q' => $suffix], 1, 2);
if (($resPaged['per_page'] ?? 0) !== 2 || ($resPaged['pages'] ?? 0) < 2) {
    fwrite(STDERR, "Falha: paginação não refletiu limite por página.\n");
    exit(1);
}

$pdo = Database::conn();
$stmt = $pdo->prepare('DELETE FROM usuarios WHERE id IN (?,?,?)');
$stmt->execute([$idA, $idB, $idC]);

echo "OK unit_users_listing_filters\n";
