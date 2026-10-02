<?php

/**
 * Unitário — ACL de AdminUsuariosController::updateSetoresGerenciados() (Bloco 7, 2026-10, pedido
 * do RH). Mesmo padrão de unit_admin_usuarios_rh_acl.php: prova pela DECLARAÇÃO do gate
 * (Auth::requireRole() no início do método) — é essa linha que decide quem entra, não um teste de
 * comportamento via HTTP.
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

$corpoDoMetodo = static function (string $source, string $metodo): string {
    if (!preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\([^)]*\)\s*:\s*void\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inicio = $m[0][1] + strlen($m[0][0]);
    $prox = strpos($source, "\n    public function ", $inicio);
    $fim = $prox !== false ? $prox : strlen($source);
    return substr($source, $inicio, $fim - $inicio);
};

$corpo = $corpoDoMetodo($source, 'updateSetoresGerenciados');
$check($corpo !== '', 'método updateSetoresGerenciados encontrado em AdminUsuariosController');
$check(
    (bool)preg_match("/Auth::requireRole\(\['admin',\s*'rh'\]\)/", $corpo),
    "updateSetoresGerenciados: Auth::requireRole(['admin','rh']) — admin e RH podem alterar, igual ao Contexto Organizacional"
);
$check(str_contains($corpo, 'Security::csrfCheck'), 'updateSetoresGerenciados: valida CSRF');
$check(str_contains($corpo, 'User::canManageUser'), 'updateSetoresGerenciados: respeita a proteção de supervisor (canManageUser), mesmo padrão das demais ações de usuário');
$check(str_contains($corpo, 'UsuarioSetoresGerenciadosService'), 'updateSetoresGerenciados: delega a escrita ao serviço dedicado (nenhum INSERT/UPDATE direto no controller)');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}

echo "\nOK unit_usuario_setores_gerenciados_acl\n";
