<?php

/**
 * Cobertura — Etapa 2B: a política de permissões por perfil é completa e é a ÚNICA fonte.
 *   - TODO código do catálogo (`permissoes`) tem classificação explícita para o viewer (um código novo sem decisão FALHA aqui —
 *     negação por padrão não basta: alguém precisa decidir);
 *   - as quatro listas do viewer não se sobrepõem e só contêm códigos que existem;
 *   - o modelo recomendado ⊆ permitidas e só contém dashboard de baixo risco de reidentificação;
 *   - cada dashboard permitido tem o endpoint exigindo exatamente essa permissão (consulta o código-fonte dos controllers);
 *   - `Authorization::usuarioTemPermissao` consulta a política; `updatePermissoes` valida a seleção ANTES de gravar; ninguém mais
 *     lê `usuario_permissoes` fora de Authorization;
 *   - o perfil Gestor segue inativo e o ENUM de roles não foi tocado.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$raiz = realpath(__DIR__ . '/../..');
$ref = new ReflectionClass('PermissaoPerfilPolicy');
$c = $ref->getConstants();
$listas = ['VIEWER_PERMITIDAS', 'TRANSITORIAS_GESTOR', 'PENDENTES_DECISAO', 'VIEWER_INCOMPATIVEIS'];

// 1. catálogo completo e listas disjuntas
$catalogo = Database::conn()->query('SELECT codigo FROM permissoes')->fetchAll(PDO::FETCH_COLUMN);
$todos = [];
foreach ($listas as $l) {
    foreach ($c[$l] as $codigo) {
        if (isset($todos[$codigo])) {
            $falhas[] = "código em duas listas ({$todos[$codigo]} e {$l}): {$codigo}";
        }
        $todos[$codigo] = $l;
    }
}
foreach (array_diff($catalogo, array_keys($todos)) as $codigo) {
    $falhas[] = "código do catálogo SEM classificação em PermissaoPerfilPolicy: {$codigo} (decida: permitida, transitória, pendente ou incompatível para o viewer)";
}
foreach (array_diff(array_keys($todos), $catalogo) as $codigo) {
    $falhas[] = "PermissaoPerfilPolicy classifica um código que não existe no catálogo: {$codigo}";
}

// 2. modelo recomendado
foreach ($c['VIEWER_RECOMENDADAS'] as $codigo) {
    if (!in_array($codigo, $c['VIEWER_PERMITIDAS'], true)) {
        $falhas[] = "recomendada fora das permitidas: {$codigo}";
    }
    if ((PermissaoPerfilPolicy::auditoriaDashboards()[$codigo]['reidentificacao'] ?? '') !== 'baixo') {
        $falhas[] = "recomendada com risco de reidentificação não-baixo: {$codigo}";
    }
}
if (array_keys(PermissaoPerfilPolicy::auditoriaDashboards()) !== array_values(array_unique(array_keys(PermissaoPerfilPolicy::auditoriaDashboards()))) || count(PermissaoPerfilPolicy::auditoriaDashboards()) !== count($c['VIEWER_PERMITIDAS'])) {
    $falhas[] = 'auditoriaDashboards deve cobrir exatamente os dashboards permitidos';
}

// 3. cada dashboard permitido tem o endpoint exigindo essa permissão
$fonte = static function (string $rel) use ($raiz): string {
    $src = '';
    foreach (token_get_all((string)file_get_contents($raiz . '/' . $rel)) as $t) {
        $src .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }
    return $src;
};
$gates = [
    'dashboard.visualizar' => 'app/controllers/AdminController.php',
    'indicadores_rh.visualizar' => 'app/controllers/AdminRhIndicadoresController.php',
    'dashboard_turnover.visualizar' => 'app/controllers/AdminDashboardTurnoverController.php',
    'dashboard_recrutamento.visualizar' => 'app/controllers/AdminDashboardRecrutamentoController.php',
    'dashboard_entrevista_desligamento.visualizar' => 'app/controllers/AdminDashboardEntrevistaDesligamentoController.php',
];
foreach ($c['VIEWER_PERMITIDAS'] as $codigo) {
    if (!isset($gates[$codigo])) {
        $falhas[] = "dashboard permitido sem endpoint mapeado neste teste: {$codigo}";
    } elseif (!str_contains($fonte($gates[$codigo]), "requirePermissao('{$codigo}')")) {
        $falhas[] = "{$gates[$codigo]} não exige {$codigo}";
    }
}

// 4. integração
if (!str_contains($fonte('app/core/Authorization.php'), 'PermissaoPerfilPolicy::permissaoEfetiva')) {
    $falhas[] = 'Authorization::usuarioTemPermissao deve consultar PermissaoPerfilPolicy::permissaoEfetiva';
}
$ctrl = $fonte('app/controllers/AdminUsuariosController.php');
$i = strpos($ctrl, 'function updatePermissoes');
$corpo = $i === false ? '' : substr($ctrl, $i, 3000);
$pv = strpos($corpo, 'PermissaoPerfilPolicy::validarSelecao');
$ps = strpos($corpo, 'Authorization::sincronizar');
if ($pv === false || $ps === false || $pv > $ps) {
    $falhas[] = 'updatePermissoes deve validar a seleção (PermissaoPerfilPolicy::validarSelecao) ANTES de Authorization::sincronizar';
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $arq) {
    if ($arq->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz) + 1));
    if (in_array($rel, ['app/core/Authorization.php', 'app/core/SchemaManager.php'], true) || str_contains($rel, '/vendor/')) {
        continue;
    }
    if (preg_match('/\busuario_permissoes\b/', $fonte($rel))) {
        $falhas[] = "{$rel} lê/grava usuario_permissoes direto — use Authorization/PermissaoPerfilPolicy";
    }
}

// 5. Gestor futuro e estrutura
if (PermissaoPerfilPolicy::PERFIL_GESTOR_ATIVO !== false) {
    $falhas[] = 'PERFIL_GESTOR_ATIVO deve permanecer false até a etapa própria do perfil Gestor';
}
$schema = (string)file_get_contents($raiz . '/database/schema.sql');
if (!preg_match("/role ENUM\('admin','rh','viewer'\)/", $schema)) {
    $falhas[] = "o ENUM de roles em database/schema.sql foi alterado (Gestor não pode existir no banco nesta etapa)";
}

echo 'Códigos classificados: ' . count($todos) . ' · catálogo: ' . count($catalogo) . "\n";
if ($falhas !== []) {
    fwrite(STDERR, implode("\n", $falhas) . "\n");
    exit(1);
}
echo "OK — política de permissões por perfil completa e única.\n";
