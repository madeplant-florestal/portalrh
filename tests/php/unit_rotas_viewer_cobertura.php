<?php

/**
 * Cobertura ESTÁTICA — Etapa 2A: nenhuma rota do Portal pode ficar aberta ao perfil `viewer` apenas pela ROLE.
 *
 * Varre as rotas de index.php (get/post → [Controller::class, 'metodo']) e o corpo de cada ação. Se o gate de entrada é
 * `Auth::requireRole([... 'viewer' ...])` e o corpo NÃO consulta permissão individual (`Authorization::`, `temPermissao`,
 * `usuarioTemPermissao`) nem restringe por escopo, a rota entra na lista de EXCEÇÕES — pequena, explícita e justificada
 * (catálogos/telas sem dado individual). Acrescentar uma rota nova aberta a viewer sem permissão FALHA este teste.
 * Uma exceção que deixou de ser necessária também falha (a lista tem que continuar verdadeira).
 *
 * Também confere que: (a) rotas de dados nominais/financeiros conhecidas NÃO aceitam viewer; (b) nenhum gate de segurança
 * em Auth/Authorization volta a tratar `is_supervisor` como bypass de qualquer role.
 */

$raiz = realpath(__DIR__ . '/../..');
$falhas = [];

// Ação (Controller::metodo) => motivo. Só telas de catálogo/cadastro e navegação, sem dado individual de pessoa.
$excecoes = [
    'AdminCentralController::index' => 'Central do Portal: só navegação; os cards já respeitam as permissões',
    'AdminManualController::index' => 'Manual de uso: texto estático',
    'AdminVagasController::index' => 'Catálogo de vagas publicadas (sem dado de candidato)',
    'AdminBeneficiosController::index' => 'Catálogo de benefícios',
    'AdminSetoresController::index' => 'Catálogo oficial de setores (espelho METADADOS, sem pessoa)',
    'AdminSetoresController::export' => 'Exporta o catálogo de setores (sem pessoa)',
    'AdminSolicitacoesVagaController::create' => 'Autorização POR OBJETO no model (SolicitacaoVaga::findAccessible/userCan*): o usuário só enxerga as próprias solicitações; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminSolicitacoesVagaController::store' => 'Autorização POR OBJETO no model (SolicitacaoVaga::findAccessible/userCan*): o usuário só enxerga as próprias solicitações; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminSolicitacoesVagaController::solicitanteContexto' => 'Autorização POR OBJETO no model (SolicitacaoVaga::findAccessible/userCan*): o usuário só enxerga as próprias solicitações; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminSolicitacoesVagaController::show' => 'Autorização POR OBJETO no model (SolicitacaoVaga::findAccessible/userCan*): o usuário só enxerga as próprias solicitações; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminSolicitacoesVagaController::addNota' => 'Autorização POR OBJETO no model (SolicitacaoVaga::findAccessible/userCan*): o usuário só enxerga as próprias solicitações; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminMovimentacoesPessoalController::index' => 'Autorização POR OBJETO no model (MovimentacaoPessoal::listForUser/findAccessible): não-admin/RH só vê o que ele mesmo solicitou; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminMovimentacoesPessoalController::create' => 'Autorização POR OBJETO no model (MovimentacaoPessoal::listForUser/findAccessible): não-admin/RH só vê o que ele mesmo solicitou; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminMovimentacoesPessoalController::store' => 'Autorização POR OBJETO no model (MovimentacaoPessoal::listForUser/findAccessible): não-admin/RH só vê o que ele mesmo solicitou; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminMovimentacoesPessoalController::show' => 'Autorização POR OBJETO no model (MovimentacaoPessoal::listForUser/findAccessible): não-admin/RH só vê o que ele mesmo solicitou; revisar junto com o perfil Gestor (Etapa 2C)',
    'AdminMovimentacoesPessoalController::update' => 'Autorização POR OBJETO no model (MovimentacaoPessoal::listForUser/findAccessible): não-admin/RH só vê o que ele mesmo solicitou; revisar junto com o perfil Gestor (Etapa 2C)',
];

// Rotas com dado nominal/financeiro: o gate de entrada NÃO pode incluir viewer.
$somenteAdminRh = [
    'AdminCandidaturasController::index', 'AdminCandidaturasController::show', 'AdminPipelineController::index',
    'AdminIndicacoesController::index', 'AdminIndicacoesController::statusApi', 'AdminIndicacoesController::contasReceberApi',
    'AdminIndicacoesController::conciliacaoApi', 'AdminIndicacoesController::relatoriosFinanceirosApi', 'AdminIndicacoesController::export',
    'AdminAvaliacoesController::index', 'AdminController::exportarColaboradores',
];

// Etapa 2C.1: ações que aceitam o perfil Gestor no gate de role (módulos com escopo setorial comprovado). A Avaliação de Experiência
// não usa requireRole — só permissão efetiva (que a PermissaoPerfilPolicy limita ao Gestor).
// Etapa 2C.2B: Solicitação de Vaga e Kanban — o Gestor entra com permissão individual (exigirPermissaoDoGestor) e escopo por setor da solicitação no model.
// A aprovação (approveLeader/approveRh) e o controle de RH/gerar vaga NÃO aceitam gestor.
$gestorAutorizado = [
    'AdminCentralController::index', 'AdminColaboradoresController::index',
    'AdminSolicitacoesVagaController::index', 'AdminSolicitacoesVagaController::create', 'AdminSolicitacoesVagaController::store',
    'AdminSolicitacoesVagaController::show', 'AdminSolicitacoesVagaController::addNota',
    'AdminSolicitacoesVagaKanbanController::index', 'AdminSolicitacoesVagaKanbanController::move',
    // Etapa 2C.2C: dashboard AGREGADO de Integração (permissão própria + escopo + k>=5). Aprovação de líder: handleApproval (privado) aceita gestor só na etapa de líder.
    'AdminDashboardIntegracaoAgregadoController::index', 'AdminDashboardIntegracaoAgregadoController::dados',
];

$rotas = (string)file_get_contents($raiz . '/index.php');
preg_match_all('/\$router->(?:get|post)\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[\s*(\w+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]/', $rotas, $m, PREG_SET_ORDER);
$acoes = [];
foreach ($m as $r) {
    $acoes[$r[1] . '::' . $r[2]] = [$r[1], $r[2]];
}

$corpoDe = static function (string $classe, string $metodo) use ($raiz): ?string {
    $arq = null;
    foreach (['controllers', 'core'] as $dir) {
        if (is_file("{$raiz}/app/{$dir}/{$classe}.php")) {
            $arq = "{$raiz}/app/{$dir}/{$classe}.php";
        }
    }
    if ($arq === null) {
        return null;
    }
    $src = (string)file_get_contents($arq);
    if (!preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $i = $mm[0][1] + strlen($mm[0][0]);
    $nivel = 1;
    $n = strlen($src);
    for ($j = $i; $j < $n && $nivel > 0; $j++) {
        $nivel += $src[$j] === '{' ? 1 : ($src[$j] === '}' ? -1 : 0);
    }
    return substr($src, $i, $j - $i);
};

$achadas = [];
foreach ($acoes as $chave => [$classe, $metodo]) {
    $corpo = $corpoDe($classe, $metodo);
    if ($corpo === null) {
        continue;
    }
    // comentários fora, para não confundir com o código
    $limpo = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $corpo);
    $abreViewer = (bool)preg_match('/Auth::requireRole\(\s*\[[^\]]*[\'"]viewer[\'"]/', $limpo);
    $temPermissao = (bool)preg_match('/Authorization::|temPermissao\(|usuarioTemPermissao\(|requirePermissao\(/', $limpo);
    // Etapa 3.2C.1: `Authorization::requireRoleOuPermissao(['admin','rh','viewer'], código)` também abre ao viewer SÓ pela role (a permissão vale para
    // quem não está na lista, ex.: Gestor). Conta como aberta a viewer e exige a mesma exceção justificada.
    $abreViewerRoleOuPerm = (bool)preg_match('/Authorization::requireRoleOuPermissao\(\s*\[[^\]]*[\'"]viewer[\'"]/', $limpo);
    $abreViewer = $abreViewer || $abreViewerRoleOuPerm;
    if (($abreViewer && !$temPermissao) || $abreViewerRoleOuPerm) {
        $achadas[$chave] = true;
    }
    if (in_array($chave, $somenteAdminRh, true) && $abreViewer) {
        $falhas[] = "rota com dado nominal/financeiro aberta a viewer: {$chave}";
    }
    // Etapa 2C.1: o perfil Gestor só entra em gates de módulos com escopo setorial comprovado (acesso só ao explicitamente autorizado)
    if (preg_match('/Auth::requireRole\(\s*\[[^\]]*[\'"]gestor[\'"]/', $limpo) && !in_array($chave, $gestorAutorizado, true)) {
        $falhas[] = "rota aceita o perfil gestor sem estar na lista autorizada da Etapa 2C.1: {$chave}";
    }
}
foreach ($gestorAutorizado as $chave) {
    $corpo = isset($acoes[$chave]) ? $corpoDe(...$acoes[$chave]) : null;
    if ($corpo === null || !preg_match('/Auth::requireRole\(\s*\[[^\]]*[\'"]gestor[\'"]/', $corpo)) {
        $falhas[] = "rota da lista do Gestor não aceita mais gestor (ou sumiu): {$chave}";
    }
}
foreach (array_keys($achadas) as $chave) {
    if (!isset($excecoes[$chave])) {
        $falhas[] = "rota aberta a viewer apenas pela role, sem permissão nem exceção justificada: {$chave}";
    }
}
foreach ($excecoes as $chave => $motivo) {
    if (!isset($achadas[$chave])) {
        $falhas[] = "exceção sem uso (remova ou revise): {$chave}";
    }
}
foreach ($somenteAdminRh as $chave) {
    if (!isset($acoes[$chave])) {
        $falhas[] = "rota protegida não encontrada em index.php (renomeada?): {$chave}";
    }
}

// is_supervisor não pode virar bypass fora da role admin
$auth = (string)file_get_contents($raiz . '/app/core/Auth.php');
$authz = (string)file_get_contents($raiz . '/app/core/Authorization.php');
foreach ([['Auth::requireRole', $auth, 'function requireRole'], ['Authorization::temAcessoPorRoleOuPermissao', $authz, 'function temAcessoPorRoleOuPermissao'], ['Authorization::requireRoleOuPermissao', $authz, 'function requireRoleOuPermissao']] as [$nome, $src, $marca]) {
    $i = strpos($src, $marca);
    $trecho = $i === false ? '' : substr($src, $i, 600);
    if (!str_contains($trecho, 'normalizarSupervisorDaSessao')) {
        $falhas[] = "{$nome} deve normalizar is_supervisor (só vale com role admin) antes de decidir";
    }
}
if (!preg_match('/\$_SESSION\[\'user_is_supervisor\'\]\s*=\s*\$isSupervisor\s*&&\s*\$role\s*===\s*\'admin\'/', $auth)) {
    $falhas[] = 'establishSession deve gravar user_is_supervisor somente quando a role é admin';
}

echo 'Ações roteadas analisadas: ' . count($acoes) . ' · abertas a viewer só por role (exceções): ' . count($achadas) . "\n";
if ($falhas !== []) {
    fwrite(STDERR, implode("\n", $falhas) . "\n");
    exit(1);
}
echo "OK — nenhuma rota nova abre dado ao viewer sem permissão; is_supervisor não é bypass.\n";
