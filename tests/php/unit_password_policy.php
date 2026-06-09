<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';

$weak = PasswordPolicy::validate('abc');
if ($weak['valid']) {
    fwrite(STDERR, "Falha: senha fraca não foi rejeitada.\n");
    exit(1);
}

$strong = PasswordPolicy::validate('Abcdef12345!');
if (!$strong['valid']) {
    fwrite(STDERR, "Falha: senha forte foi rejeitada.\n");
    exit(1);
}

echo "OK unit_password_policy\n";
