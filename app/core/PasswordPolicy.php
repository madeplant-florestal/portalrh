<?php
class PasswordPolicy
{
    public static function validate(string $password): array
    {
        $errors = [];
        if (strlen($password) < 12) {
            $errors[] = 'A senha deve ter pelo menos 12 caracteres.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra maiúscula.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra minúscula.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos um número.';
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos um caractere especial.';
        }
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}

