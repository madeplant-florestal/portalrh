<?php

/**
 * Sprint Solicitação de Vaga — Etapa 2 / Decisão D: RH administra SOMENTE o bloco de Contexto
 * Organizacional em AdminUsuariosController; o restante do CRUD de usuários continua admin-only.
 *
 * `Auth::requireRole()` termina o processo (`exit`) quando nega acesso, então não dá para invocar
 * os métodos do controller diretamente num script de teste único para provar a NEGAÇÃO de acesso.
 * Em vez disso, esta suíte verifica a DECLARAÇÃO da ACL (a chamada `Auth::requireRole([...])` no
 * início de cada método) — é exatamente essa linha, e só ela, que decide quem entra em cada ação.
 * `unit_auth_admin_access.php` já cobre o comportamento de `Auth::requireRole()` em si.
 */

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../app/controllers/AdminUsuariosController.php');
if ($source === false) {
    fwrite(STDERR, "Não foi possível ler AdminUsuariosController.php\n");
    exit(1);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

/** Extrai o corpo do método (da assinatura até o próximo "public function"/fim de classe). */
$corpoDoMetodo = static function (string $source, string $metodo): string {
    if (!preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\([^)]*\)\s*:\s*void\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inicio = $m[0][1] + strlen($m[0][0]);
    $prox = strpos($source, "\n    public function ", $inicio);
    $fim = $prox !== false ? $prox : strlen($source);
    return substr($source, $inicio, $fim - $inicio);
};

// ---- Bloco de Contexto Organizacional (Decisão D): admin + rh -------------
foreach (['show', 'vincularMetadados', 'updateContextoOrganizacional', 'buscarMetadados'] as $metodo) {
    $corpo = $corpoDoMetodo($source, $metodo);
    $check($corpo !== '', "método {$metodo} encontrado em AdminUsuariosController");
    $check(
        (bool)preg_match("/Auth::requireRole\(\['admin',\s*'rh'\]\)/", $corpo),
        "{$metodo}: Auth::requireRole(['admin','rh']) — RH administra Contexto Organizacional"
    );
}

// ---- Fora do escopo de RH: continuam admin-only ----------------------------
foreach (['index', 'create', 'store', 'updateRole', 'updateStatus', 'delete'] as $metodo) {
    $corpo = $corpoDoMetodo($source, $metodo);
    $check($corpo !== '', "método {$metodo} encontrado em AdminUsuariosController");
    $check(
        (bool)preg_match("/Auth::requireRole\(\['admin'\]\)/", $corpo),
        "{$metodo}: Auth::requireRole(['admin']) — RH NÃO recebe acesso (CRUD geral/perfil/status/senha)"
    );
    $check(
        !preg_match("/Auth::requireRole\(\['admin',\s*'rh'\]\)/", $corpo),
        "{$metodo}: não amplia a ACL para 'rh'"
    );
}

// ---- adminChangePasswordApi: continua checando admin/supervisor manualmente (não Auth::requireRole)
$corpoSenha = $corpoDoMetodo($source, 'adminChangePasswordApi');
$check($corpoSenha !== '', 'método adminChangePasswordApi encontrado em AdminUsuariosController');
$check(
    (bool)preg_match('/role.*===\s*\'admin\'.*is_supervisor/s', $corpoSenha) || (bool)preg_match("/=== 'admin'/", $corpoSenha),
    'adminChangePasswordApi: guarda manual de admin/supervisor preservada (RH não passa)'
);
$check(strpos($corpoSenha, "'rh'") === false, 'adminChangePasswordApi: "rh" não aparece na checagem de autorização');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}

echo "\nOK unit_admin_usuarios_rh_acl\n";
