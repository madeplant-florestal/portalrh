<?php

/**
 * Cobertura ESTÁTICA — Etapa 2A-bis: a autoridade entre contas (Supervisor Geral > Administrador > RH > Viewer) é decidida
 * em UM lugar (Autoridade). Falha se:
 *   - o flag `is_supervisor` do BANCO (diferente da sessão `user_is_supervisor`) for lido fora dos arquivos autorizados;
 *   - alguma ação que muda conta (perfil, status, senha, exclusão, permissões, flag, vínculos, `garantir`) deixar de passar
 *     por Autoridade / pelos métodos `User::attempt*` (que a aplicam);
 *   - quem redefine senha (`definirSenhaHash`, `adminChangePassword`) não consultar Autoridade;
 *   - existir condição `role admin OU is_supervisor` fora da política central.
 * Comentários são removidos antes da varredura. A lista de arquivos autorizados é pequena e justificada.
 */

$raiz = realpath(__DIR__ . '/../../app');
$falhas = [];

$limpo = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1]) : $t;
    }
    return $out;
};
$ler = static fn(string $rel): string => $limpo((string)file_get_contents($raiz . '/' . $rel));

// 1. leitura do flag do BANCO só em arquivos autorizados
$autorizados = [
    'services/Autoridade.php' => 'a política central',
    'models/User.php' => 'persistência do flag e operações auditadas (conceder/revogar/ensure)',
    'core/SchemaManager.php' => 'cria a coluna',
    'core/Auth.php' => 'estabelece a sessão: grava o flag SOMENTE com role admin (testado em integration_autorizacao_etapa2a)',
    'models/SolicitacaoVaga.php' => 'único ponto: userAccessProfile() devolve o flag EFETIVO (Autoridade::flagEfetivo); ORDER BY de listagem',
    'views/admin/usuarios/show.php' => 'exibe o botão conceder/remover (a rota revalida em Autoridade)',
];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
$usaram = [];
foreach ($it as $arq) {
    if ($arq->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz) + 1));
    if (str_starts_with($rel, 'vendor/')) {
        continue;
    }
    $codigo = $limpo((string)file_get_contents($arq->getPathname()));
    // `is_supervisor` que NÃO é a chave de sessão `user_is_supervisor`
    if (preg_match('/(?<!user_)\bis_supervisor\b/', $codigo) && !isset($autorizados[$rel])) {
        // textos de ajuda do manual (strings) não são lógica
        if ($rel === 'views/admin/manual.php') {
            continue;
        }
        $falhas[] = "{$rel}: lê o flag is_supervisor do banco fora dos arquivos autorizados — use Autoridade::";
    }
    if (isset($autorizados[$rel])) {
        $usaram[$rel] = true;
    }
}
foreach (array_keys($autorizados) as $rel) {
    if (!isset($usaram[$rel])) {
        $falhas[] = "arquivo autorizado não existe mais: {$rel}";
    }
}

// 2. condições "role admin OU flag" fora da política
foreach (['controllers', 'models', 'services', 'core', 'repositories', 'views'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $arq) {
        if ($arq->getExtension() !== 'php') {
            continue;
        }
        $rel = $dir . '/' . str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz . '/' . $dir) + 1));
        if (isset($autorizados[$rel]) || $rel === 'views/admin/manual.php') {
            continue;
        }
        $codigo = $limpo((string)file_get_contents($arq->getPathname()));
        if (preg_match('/[\'"]admin[\'"][^;{]{0,120}(?<!user_)is_supervisor|(?<!user_)is_supervisor[^;{]{0,120}[\'"]admin[\'"]/', $codigo)) {
            $falhas[] = "{$rel}: condição 'admin' + is_supervisor fora de Autoridade";
        }
    }
}

// 3. pontos-chave da política
$sv = $ler('models/SolicitacaoVaga.php');
if (!str_contains($sv, "'is_supervisor' => Autoridade::flagEfetivo(\$row)")) {
    $falhas[] = 'SolicitacaoVaga::userAccessProfile deve devolver o flag EFETIVO (Autoridade::flagEfetivo)';
}
if (preg_match('/function isRhOrAdmin[^}]*is_supervisor/s', $sv)) {
    $falhas[] = 'SolicitacaoVaga::isRhOrAdmin não pode depender do flag isolado';
}
$mp = $ler('models/MovimentacaoPessoal.php');
if (preg_match('/function isRhOrAdmin[^}]*is_supervisor/s', $mp)) {
    $falhas[] = 'MovimentacaoPessoal::isRhOrAdmin não pode depender do flag isolado';
}
if (!str_contains($ler('models/User.php'), 'FOR UPDATE')) {
    $falhas[] = 'User deve travar os Supervisores Gerais ativos (FOR UPDATE) ao remover autoridade — proteção do último Supervisor Geral sob concorrência';
}
$user = $ler('models/User.php');
if (preg_match('/function ensureSupervisor.*?\n    }\n/s', $user, $mm) && preg_match('/senha_hash\s*=\s*\?/', $mm[0])) {
    $falhas[] = 'ensureSupervisor não pode redefinir a senha de conta existente';
}

// 4. ações que mudam conta passam pela política
$corpoDe = static function (string $src, string $metodo): string {
    if (!preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $i = $m[0][1] + strlen($m[0][0]);
    $n = 1;
    for ($j = $i, $len = strlen($src); $j < $len && $n > 0; $j++) {
        $n += $src[$j] === '{' ? 1 : ($src[$j] === '}' ? -1 : 0);
    }
    return substr($src, $i, $j - $i);
};
$usuarios = $ler('controllers/AdminUsuariosController.php');
$acoes = [
    'updateRole' => 'User::attemptRoleUpdate', 'delete' => 'User::attemptDelete', 'updateStatus' => 'User::attemptSetActive',
    'adminChangePasswordApi' => 'Autoridade::ehAdministrativo', 'alterarSupervisorGeral' => 'User::concederSupervisorGeral',
    'updatePermissoes' => 'Autoridade::podeGerenciar', 'updateGestor' => 'Autoridade::podeGerenciar', 'updateVagaAcesso' => 'Autoridade::podeGerenciar',
    'vincularMetadados' => 'Autoridade::podeGerenciarContexto', 'updateContextoOrganizacional' => 'Autoridade::podeGerenciarContexto',
    'updateSetoresGerenciados' => 'Autoridade::podeGerenciarContexto',
];
foreach ($acoes as $metodo => $esperado) {
    $corpo = $corpoDe($usuarios, $metodo);
    if ($corpo === '') {
        $falhas[] = "AdminUsuariosController::{$metodo} não encontrada";
    } elseif (!str_contains($corpo, $esperado)) {
        $falhas[] = "AdminUsuariosController::{$metodo} deve passar por {$esperado}";
    }
}
if (!str_contains($corpoDe($usuarios, 'alterarSupervisorGeral'), 'User::revogarSupervisorGeral')) {
    $falhas[] = 'alterarSupervisorGeral deve usar User::revogarSupervisorGeral';
}
if (!str_contains($ler('controllers/AdminSupervisorController.php'), 'Autoridade::ehSupervisorGeral')) {
    $falhas[] = 'AdminSupervisorController::ensure deve exigir Supervisor Geral (Autoridade::ehSupervisorGeral)';
}
$col = $corpoDe($ler('controllers/AdminColaboradoresController.php'), 'updateAcesso');
if (!str_contains($col, 'Autoridade::podeGerenciar') || !str_contains($col, 'Autoridade::ehAdministrativo')) {
    $falhas[] = 'Colaboradores → Acesso (redefinir_senha) deve consultar Autoridade antes de definir a senha';
}
if (!str_contains($ler('models/User.php'), 'Autoridade::podeGerenciar($actor, $target)')) {
    $falhas[] = 'User::adminChangePassword deve consultar Autoridade::podeGerenciar sobre a conta de destino';
}

// 5. quem chama definirSenhaHash / updatePassword
$autorizadosSenha = ['controllers/AdminColaboradoresController.php' => 'consulta Autoridade (acima)', 'controllers/PasswordRecoveryController.php' => 'recuperação por token (conta do próprio usuário)', 'models/User.php' => 'adminChangePassword (consulta Autoridade)'];
foreach ($it as $arq) {
    if ($arq->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz) + 1));
    if (str_starts_with($rel, 'vendor/')) {
        continue;
    }
    if (preg_match('/User::(definirSenhaHash|updatePassword)\(/', $limpo((string)file_get_contents($arq->getPathname()))) && !isset($autorizadosSenha[$rel])) {
        $falhas[] = "{$rel}: redefine senha de conta fora dos fluxos autorizados";
    }
}

echo 'Arquivos autorizados a ler o flag do banco: ' . count($autorizados) . "\n";
if ($falhas !== []) {
    fwrite(STDERR, implode("\n", $falhas) . "\n");
    exit(1);
}
echo "OK — a autoridade entre contas é decidida só em Autoridade.\n";
