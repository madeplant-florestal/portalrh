<?php
declare(strict_types=1);

/**
 * Correção (2026-10): o Portal exigia a política real de senha (PasswordPolicy::validate — 12
 * caracteres, maiúscula, minúscula, número, caractere especial) mas só exibia as regras na tela
 * de "Cadastrar novo usuário". O modal "Alterar Senha" (admin/usuarios/show.php) e a tela de
 * redefinição self-service (admin/password_recovery_reset.php) não diziam nada ao usuário — só
 * rejeitavam depois do envio. Não inventamos política nova: o texto de ajuda adicionado é
 * idêntico, nas três telas, à regra real já aplicada pelo backend.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

$check = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
};

$textoAjuda = 'Mínimo de 12 caracteres com maiúscula, minúscula, número e símbolo.';

try {
    // --- Caso 1: a política REAL do backend (fonte única da verdade) -----------------------
    $check((new ReflectionMethod('PasswordPolicy', 'validate'))->isStatic(), 'Caso 1: PasswordPolicy::validate deveria ser estático.');
    $regras = [
        '' => 'curta demais (0 chars)',
        str_repeat('a', 11) => '11 chars, só minúscula',
        str_repeat('a', 12) => '12 chars, sem maiúscula/número/símbolo',
        'Aaaaaaaaaaaa' => '12 chars, maiúscula+minúscula, sem número/símbolo',
        'Aaaaaaaaaaa1' => '12 chars, falta símbolo',
    ];
    foreach ($regras as $senha => $descricao) {
        $r = PasswordPolicy::validate($senha);
        $check($r['valid'] === false && $r['errors'] !== [], "Caso 1: '{$descricao}' deveria ser rejeitada com mensagem(ns) específica(s).");
    }
    $valida = PasswordPolicy::validate('Abcdefgh123!');
    $check($valida['valid'] === true && $valida['errors'] === [], 'Caso 1: senha que cumpre as 5 regras deveria ser aceita.');

    // --- Caso 2: mensagem de erro é ESPECÍFICA por regra, nunca genérica -------------------
    $semMaiuscula = PasswordPolicy::validate('abcdefgh123!');
    $check(
        in_array('A senha deve conter pelo menos uma letra maiúscula.', $semMaiuscula['errors'], true),
        'Caso 2: faltando maiúscula, a mensagem deveria nomear exatamente essa regra (nunca "Senha inválida").'
    );
    foreach ($semMaiuscula['errors'] as $erro) {
        $check(strtolower($erro) !== 'senha inválida' && strtolower($erro) !== 'senha invalida', 'Caso 2: nenhuma mensagem deveria ser o genérico "Senha inválida".');
    }

    // --- Caso 3: as 3 telas que coletam senha mostram a MESMA regra real -------------------
    $telas = [
        'app/views/admin/usuarios/create.php' => 'Cadastrar novo usuário',
        'app/views/admin/usuarios/show.php' => 'Modal "Alterar Senha"',
        'app/views/admin/password_recovery_reset.php' => 'Redefinição self-service (esqueci minha senha)',
    ];
    foreach ($telas as $arquivo => $rotulo) {
        $fonte = (string)file_get_contents(BASE_PATH . '/' . $arquivo);
        $check(strpos($fonte, $textoAjuda) !== false, "Caso 3: {$rotulo} ({$arquivo}) deveria mostrar '{$textoAjuda}'.");
        $check(strpos($fonte, 'minlength="12"') !== false, "Caso 3: {$rotulo} ({$arquivo}) deveria ter minlength=\"12\" no campo de senha (reflete a regra real no HTML).");
    }

    // --- Caso 4: confirmação de senha já existe onde o backend exige (self-service) --------
    $fonteRecovery = (string)file_get_contents(BASE_PATH . '/app/views/admin/password_recovery_reset.php');
    $check(strpos($fonteRecovery, 'name="password_confirm"') !== false, 'Caso 4: a redefinição self-service deveria ter campo de confirmação de senha.');
    $corpoReset = (function (): string {
        $r = new ReflectionMethod('PasswordRecoveryController', 'performReset');
        $linhas = file((string)$r->getFileName());
        return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    })();
    $check(strpos($corpoReset, 'As senhas não conferem') !== false, 'Caso 4: o backend deveria rejeitar com mensagem clara quando senha e confirmação não baterem.');

    // --- Caso 5: a mensagem de erro do backend chega íntegra até a tela/JS -----------------
    $corpoStore = (function (): string {
        $r = new ReflectionMethod('AdminUsuariosController', 'store');
        $linhas = file((string)$r->getFileName());
        return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    })();
    $check(strpos($corpoStore, "implode(' ', \$policy['errors'])") !== false, 'Caso 5: o cadastro deveria repassar TODAS as mensagens específicas da política, não um texto fixo.');
    $jsUsuarios = (string)file_get_contents(BASE_PATH . '/assets/usuarios.js');
    $check(strpos($jsUsuarios, 'data.error') !== false, 'Caso 5: o JS do modal "Alterar Senha" deveria exibir data.error (a mensagem específica vinda do backend), não um texto fixo.');

    // --- Caso 6: editar usuário (role/gestor/contexto/setores/permissões/status) NUNCA ------
    // --- mexe em senha — trocar senha continua sendo uma ação separada e explícita ---------
    $metodosDeEdicaoQueNaoSaoSenha = ['updateRole', 'updateVagaAcesso', 'updateGestor', 'updateContextoOrganizacional', 'updateSetoresGerenciados', 'updatePermissoes', 'updateStatus'];
    foreach ($metodosDeEdicaoQueNaoSaoSenha as $metodo) {
        $r = new ReflectionMethod('AdminUsuariosController', $metodo);
        $linhas = file((string)$r->getFileName());
        $corpo = implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
        $check(
            stripos($corpo, 'senha') === false && stripos($corpo, 'password') === false,
            "Caso 6: AdminUsuariosController::{$metodo}() não deveria tocar em senha — editar usuário não pode forçar troca de senha."
        );
    }

    echo "OK integration_diretrizes_senha_ui\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
