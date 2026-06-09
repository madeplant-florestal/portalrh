<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$_SESSION = [];
if (Auth::canAccessAdmin()) {
    fwrite(STDERR, "Falha: sessão vazia não deveria acessar admin.\n");
    exit(1);
}

$_SESSION['user'] = true;
$_SESSION['user_id'] = 101;
$_SESSION['user_role'] = 'candidate';
$_SESSION['user_is_supervisor'] = 0;
if (Auth::canAccessAdmin()) {
    fwrite(STDERR, "Falha: role candidate não deveria acessar admin.\n");
    exit(1);
}

$_SESSION['user_role'] = 'viewer';
if (!Auth::canAccessAdmin()) {
    fwrite(STDERR, "Falha: role viewer deveria acessar admin.\n");
    exit(1);
}

$_SESSION['user_role'] = 'candidate';
$_SESSION['user_is_supervisor'] = 1;
if (!Auth::canAccessAdmin()) {
    fwrite(STDERR, "Falha: supervisor deveria acessar admin.\n");
    exit(1);
}

echo "OK unit_auth_admin_access\n";
