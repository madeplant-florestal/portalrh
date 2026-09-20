<?php

/**
 * Unitário — Gestor Imediato (UsuarioGestorService: regras puras sobre o mapa id => gestor, sem banco). Prova:
 *   - autorreferência e ciclos A→B→A / A→B→C→A bloqueados; hierarquia A→B→C válida;
 *   - proteção contra laço em dados corrompidos (não trava e não repete);
 *   - cadeia acima, descendentes (diretos e indiretos) sem N+1 (tudo em memória);
 *   - rótulo "Nome — e-mail (Cargo)";
 *   - fontes: a relação nova não lê legado nem aprovador.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$S = UsuarioGestorService::class;

// ---- ciclo ----------------------------------------------------------------------------------------------------------
$check($S::criaCiclo([1 => null], 1, 1) === true, '(ciclo) Autorreferência bloqueada');
$check($S::criaCiclo([1 => null, 2 => 1], 1, 2) === true, '(ciclo) A→B→A bloqueado (B já é liderado por A)');
$check($S::criaCiclo([1 => null, 2 => 3, 3 => 1], 1, 2) === true, '(ciclo) A→B→C→A bloqueado (B→C→A já existe)');
$check($S::criaCiclo([1 => null, 2 => 3, 3 => null], 1, 2) === false, '(ciclo) A→B→C válida (sem retorno ao usuário)');
$check($S::criaCiclo([1 => null, 2 => null], 1, 2) === false, '(ciclo) Gestor sem gestor próprio é válido');
$check($S::criaCiclo([1 => null, 2 => 3, 3 => 4, 4 => 5, 5 => 1], 1, 2) === true, '(ciclo) Ciclo longo A→B→C→D→E→A bloqueado');

// ---- dados corrompidos ----------------------------------------------------------------------------------------------
$corrompido = [1 => null, 2 => 3, 3 => 2]; // laço 2↔3 que não envolve o usuário 1
$check($S::cadeiaAcima($corrompido, 2) === [3], '(corrompido) A cadeia acima para no laço em vez de repetir para sempre');
$check($S::criaCiclo($corrompido, 1, 2) === false, '(corrompido) Laço pré-existente que não passa pelo usuário não trava nem bloqueia a definição');
$check($S::descendentes($corrompido, 2) === [3], '(corrompido) Descendentes não repetem nem travam no laço');
$longa = [];
for ($i = 1; $i <= 500; $i++) {
    $longa[$i] = $i < 500 ? $i + 1 : null;
}
$check(count($S::cadeiaAcima($longa, 1)) === $S::MAX_PROFUNDIDADE, '(corrompido) Cadeia muito longa é truncada no teto de profundidade');

// ---- cadeia e descendentes ------------------------------------------------------------------------------------------
$mapa = [1 => null, 2 => 1, 3 => 2, 4 => 2, 5 => null];
$check($S::cadeiaAcima($mapa, 3) === [2, 1], '(cadeia) Cadeia acima de C: B, depois A');
$check($S::cadeiaAcima($mapa, 1) === [] && $S::cadeiaAcima($mapa, 99) === [], '(cadeia) Sem gestor ou usuário desconhecido: cadeia vazia');
$desc = $S::descendentes($mapa, 1);
sort($desc);
$check($desc === [2, 3, 4], '(descendentes) Subordinados diretos e indiretos de A');
$check($S::descendentes($mapa, 3) === [] && $S::descendentes($mapa, 5) === [], '(descendentes) Folha/sem liderados: vazio');

// ---- rótulo ---------------------------------------------------------------------------------------------------------
$check($S::rotuloOpcao(['nome' => 'Ana', 'email' => 'ana@x.com', 'cargo' => 'Analista']) === 'Ana — ana@x.com (Analista)', '(rótulo) Nome — e-mail (Cargo)');
$check($S::rotuloOpcao(['nome' => 'Ana', 'email' => 'ana@x.com', 'cargo' => null]) === 'Ana — ana@x.com', '(rótulo) Sem cargo: só nome e e-mail');

// ---- fontes ---------------------------------------------------------------------------------------------------------
$codigo = static function (string $arquivo): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
foreach (['app/services/UsuarioGestorService.php', 'app/repositories/UsuarioGestorRepository.php'] as $arq) {
    $c = $codigo(BASE_PATH . '/' . $arq);
    $check(!preg_match('/usuario_colaboradores|lider_colaborador_id|is_gestor|FROM\s+colaboradores\b|JOIN\s+colaboradores\b|aprovador_usuario_id|gestor_metadados_id/i', $c), "(fonte) {$arq} não usa legado, aprovador nem gestor_metadados_id");
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_USUARIO_GESTOR_OK\n";
