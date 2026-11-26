<?php

/**
 * Entrada de senha para scripts de linha de comando — SEM valor padrão e SEM argumento de linha de comando (argumentos ficam no
 * histórico do shell e na lista de processos).
 *
 * Fonte, nesta ordem:
 *   1. variável de ambiente indicada (ex.: RH_NOVA_SENHA="..." php scripts/reset_password_cli.php email@...);
 *   2. digitação interativa (terminal), pedida duas vezes; sem eco quando o sistema permite (stty).
 * Sem senha => o script ENCERRA (exit 1) sem criar/alterar nada. A senha passa pela mesma PasswordPolicy do Portal.
 * Nenhuma mensagem deste arquivo exibe a senha nem o hash.
 */

require_once __DIR__ . '/../../app/core/PasswordPolicy.php';

function senhaCliObrigatoria(string $variavelAmbiente): string
{
    $senha = getenv($variavelAmbiente);
    if (!is_string($senha) || $senha === '') {
        $senha = senhaCliDigitada();
    }
    if ($senha === '') {
        senhaCliAbortar("Senha não informada. Defina {$variavelAmbiente} no ambiente ou execute em um terminal interativo. Nada foi alterado.");
    }
    $politica = PasswordPolicy::validate($senha);
    if (!($politica['valid'] ?? false)) {
        senhaCliAbortar('Senha recusada pela política de senhas: ' . implode(' ', $politica['errors'] ?? []) . ' Nada foi alterado.');
    }
    return $senha;
}

/** Recusa senha passada como argumento (posição informada) — orienta o uso seguro. */
function senhaCliRecusarArgumento(array $argv, int $posicao, string $variavelAmbiente): void
{
    if (isset($argv[$posicao]) && !str_starts_with((string)$argv[$posicao], '--')) {
        senhaCliAbortar("Não informe a senha como argumento (fica no histórico do terminal). Use a variável {$variavelAmbiente} ou a digitação interativa. Nada foi alterado.");
    }
}

function senhaCliDigitada(): string
{
    if (!defined('STDIN') || !function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
        return '';
    }
    $ler = static function (string $rotulo): string {
        fwrite(STDERR, $rotulo);
        $semEco = DIRECTORY_SEPARATOR === '/' && @shell_exec('stty -echo 2>/dev/null') !== null;
        $linha = fgets(STDIN);
        if ($semEco) {
            @shell_exec('stty echo 2>/dev/null');
            fwrite(STDERR, PHP_EOL);
        }
        return rtrim((string)$linha, "\r\n");
    };
    if (DIRECTORY_SEPARATOR !== '/') {
        fwrite(STDERR, "Atenção: neste terminal a senha digitada pode ficar visível na tela.\n");
    }
    $senha = $ler('Nova senha: ');
    if ($senha === '') {
        return '';
    }
    if ($ler('Confirme a senha: ') !== $senha) {
        senhaCliAbortar('As senhas digitadas não conferem. Nada foi alterado.');
    }
    return $senha;
}

function senhaCliAbortar(string $mensagem): void
{
    fwrite(STDERR, 'ERRO: ' . $mensagem . PHP_EOL);
    exit(1);
}
