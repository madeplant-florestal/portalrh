<?php

/**
 * Integração — Nova UI, Bloco H: Manual de Uso (documentação real, não genérica) + centralização de Avaliações. Fixtures
 * ZZPMA-* com limpeza. Prova, contra o banco e as fontes:
 *   - o Manual renderiza no AppShell V2 (sem sidebar), com breadcrumb, PageHeader, busca local e <title>; sem <script>
 *     executável nem handlers inline; a busca é externa (assets/manual.js, registrado por ui_script_pagina), idêntica
 *     em public/assets;
 *   - todos os módulos reais aparecem documentados, com âncoras estáveis (ids únicos) e um item por funcionalidade real;
 *   - nenhum módulo/permissão/conceito é inventado: os três tipos de avaliação (Avaliações de Desempenho, Avaliação após
 *     90 dias, Pesquisa de Experiência do Candidato) aparecem descritos com a rota real de cada um;
 *   - a tela de Avaliações ganhou um bloco "Outras avaliações do Portal" com links para os outros dois conceitos, sem
 *     alterar rota/campo/POST/permissão nenhuma; o link para a Pesquisa de Experiência só aparece com a permissão real;
 *   - "Avaliação de Período de Experiência" (pública, do candidato) e "Feedback e Desenvolvimento" continuam SEM
 *     implementação própria (nenhuma rota/controller novo) — nada foi criado além de navegação/documentação;
 *   - gates preservados: Avaliações continua sem permissão individual (só requireRole), Manual continua com o mesmo gate.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/ui-shell.php';

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};
$sessaoOriginal = $_SESSION;
$getOriginal = $_GET;
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$fonte = static fn(string $rel): string => (string)file_get_contents(BASE_PATH . '/' . $rel);
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, array $codigos = []) use ($pdo, &$criados, $senha, $suffix): array {
    $id = User::create('ZZPMA ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzpma.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    $criados[] = $id;
    if ($codigos !== []) {
        $ids = [];
        $st = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
        foreach ($codigos as $c) {
            $st->execute([$c]);
            $x = (int)$st->fetchColumn();
            if ($x > 0) {
                $ids[] = $x;
            }
        }
        Authorization::sincronizar($id, $ids);
    }
    return ['id' => $id, 'role' => $role];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZPMA Teste';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];
};

try {
    $viewer = $novoUsuario('Viewer', 'viewer');
    $comPesquisaExperiencia = $novoUsuario('Com Pesquisa Exp', 'rh', ['pesquisa_experiencia.visualizar']);
    $semPesquisaExperiencia = $novoUsuario('Sem Pesquisa Exp', 'rh');

    // ---- Manual: renderização e estrutura --------------------------------------------------------------------------------------
    $comoUsuario($viewer);
    ui_titulo_pagina(null, true);
    ui_script_pagina(null, true);
    $html = $renderizar(static fn() => (new AdminManualController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html), '(Manual) Renderiza sem Warning/Notice/Fatal');
    $check(str_contains($html, 'data-app-shell-v2') && !str_contains($html, 'data-admin-sidebar') && !str_contains($html, 'class="sidebar'), '(Manual) AppShell V2, sem sidebar antiga');
    $check(str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, '<h1') && str_contains($html, '<title>Manual de Uso'), '(Manual) Breadcrumb, título e <title> presentes');
    $check(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html) && !preg_match('/\son(click|submit|change|load|input|keyup|keydown)\s*=/i', $html), '(Manual) Sem <script> executável nem handlers inline');
    $check(str_contains($html, '/assets/manual.js') && str_contains($html, 'data-manual-busca') && str_contains($html, 'data-manual-busca-form'), '(Manual) Busca local presente e script externo registrado');
    $jsFonte = $fonte('assets/manual.js');
    $check(is_file(BASE_PATH . '/public/assets/manual.js') && $jsFonte === $fonte('public/assets/manual.js') && !preg_match('/<script/i', $jsFonte), '(Manual) manual.js idêntico em public/assets, sem marcação HTML dentro do JS');

    // ---- Manual: módulos reais documentados, não genéricos -----------------------------------------------------------------------
    $ancorasEsperadas = [
        'central-portal', 'people-analytics', 'indicadores-rh',
        'dashboard-recrutamento', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'webhooks',
        'solicitacao-vaga-visao-geral', 'solicitacao-vaga-criacao', 'solicitacao-vaga-aprovacao', 'solicitacao-vaga-kanban', 'solicitacao-vaga-vinculo-vaga',
        'colaboradores-lista', 'colaboradores-dados-rh', 'colaboradores-acesso',
        'usuarios-lista',
        'pdi-visao-geral',
        'pesquisa-integracao', 'pesquisa-reacao',
        'entrevista-desligamento', 'dashboard-entrevista-desligamento', 'dashboard-turnover',
        'avaliacoes-desempenho', 'avaliacao-90-dias', 'pesquisa-experiencia-candidato',
        'mensagens-modelos',
        'movimentacao-visao-geral',
        'cadastro-empresas', 'cadastro-setores', 'cadastro-cargos', 'cadastro-beneficios',
        'seguranca-perfis-permissoes',
    ];
    $faltando = [];
    foreach ($ancorasEsperadas as $ancora) {
        if (!str_contains($html, 'id="' . $ancora . '"')) {
            $faltando[] = $ancora;
        }
    }
    $check($faltando === [], '(Manual) Todos os módulos reais têm âncora própria e estável no conteúdo' . ($faltando === [] ? '' : ': faltam ' . implode(', ', $faltando)));
    $idsBrutos = [];
    preg_match_all('/<(?:section|details) id="([a-z0-9-]+)"/', $html, $idsBrutos);
    $check(count($idsBrutos[1]) === count(array_unique($idsBrutos[1])), '(Manual) Nenhuma âncora duplicada');
    $check(substr_count($html, '<details') >= 30, '(Manual) Pelo menos 30 funcionalidades documentadas (' . substr_count($html, '<details') . ' encontradas)');
    $check(!preg_match('/você pode gerenciar|Nesta tela você pode/i', $html), '(Manual) Sem fórmulas genéricas de placeholder ("nesta tela você pode gerenciar")');
    $check(str_contains($html, 'id="faq"') && substr_count($html, '<dt') >= 15, '(Manual) Seção de Perguntas Frequentes com pelo menos 15 perguntas (globais + por módulo)');

    // ---- Manual: conteúdo correto e sem regra inventada ---------------------------------------------------------------------------
    $check(str_contains($html, 'Gestor Imediato') && str_contains($html, 'Aprovador de Solicitação de Vaga') && str_contains($html, 'são dois campos'), '(Manual) Explica a diferença real entre Gestor Imediato e Aprovador de Solicitação de Vaga');
    $check(str_contains($html, 'CONTRATO, não') || str_contains($html, 'contrato, não'), '(Manual) Deixa claro que a listagem de Colaboradores é por contrato, não por pessoa');
    $check(str_contains($html, '020') && str_contains($html, 'Falecimento') && str_contains($html, 'nunca gera entrevista'), '(Manual) Documenta corretamente que o motivo 020 (Falecimento) nunca gera Entrevista de Desligamento');
    $check(str_contains($html, 'até 3 ações') && str_contains($html, 'pelo menos 1 ação'), '(Manual) Documenta o limite de 3 ações e o mínimo de 1 para iniciar o PDI');
    $check(str_contains($html, 'não tem pergunta de Voluntário/Involuntário') && !preg_match("/'nome' => 'Volunt[aá]rio'|'nome' => 'Involunt[aá]rio'/", $fonte('app/views/admin/manual.php')), '(Manual) A seção da Entrevista de Desligamento só MENCIONA Voluntário/Involuntário para dizer que a pesquisa NÃO usa essa classificação — nunca a documenta como um status ou campo real');
    $check(!preg_match('/Avaliação de (Período de )?Experiência.{0,40}(em breve|dispon[ií]vel em breve|implementad)/is', $html) && !str_contains($html, 'Feedback e Desenvolvimento'), '(Manual) Não documenta "Avaliação de Período de Experiência" nem "Feedback e Desenvolvimento" como funcionalidades próprias (não existem no sistema) — nenhum placeholder "em breve"');
    $check(!str_contains($fonte('app/controllers/AdminManualController.php'), 'Auth::requireRole') === false && str_contains($fonte('app/controllers/AdminManualController.php'), "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(Manual) Gate de role inalterado');

    // ---- Manual: fontes (tokens, sem inline) ------------------------------------------------------------------------------------
    $manualFonte = $fonte('app/views/admin/manual.php');
    $check(!preg_match('/#0d1321|#3e5c76|#1d2d44/i', $manualFonte) && !preg_match('/<script\b/i', $manualFonte) && !preg_match('/\son(click|submit|change|input|load)\s*=/i', $manualFonte) && str_contains($manualFonte, 'ui_breadcrumb') && str_contains($manualFonte, 'ui_page_header'), '(Manual) Fonte sem a paleta azul antiga, sem <script>/handlers inline, com os componentes V2');

    // ---- Avaliações: inventário e centralização --------------------------------------------------------------------------------
    ui_titulo_pagina(null, true);
    $comoUsuario($comPesquisaExperiencia);
    $_GET = [];
    $htmlAvalCom = $renderizar(static fn() => (new AdminAvaliacoesController())->index());
    $check(str_contains($htmlAvalCom, 'Outras avaliações do Portal'), '(Avaliações) A tela ganhou o bloco de centralização/orientação');
    $check(str_contains($htmlAvalCom, 'Avaliação após 90 dias') && str_contains($htmlAvalCom, '/admin/solicitacoes-vaga"'), '(Avaliações) Orienta para a Avaliação após 90 dias dentro de Solicitações de Vaga, sem duplicar a funcionalidade');
    $check(str_contains($htmlAvalCom, 'Pesquisa de Experiência do Candidato') && str_contains($htmlAvalCom, '/admin/candidaturas"'), '(Avaliações) Com a permissão, orienta para a Pesquisa de Experiência dentro de Candidaturas');
    $check(str_contains($htmlAvalCom, '/admin/manual#avaliacoes'), '(Avaliações) Link direto para a seção correspondente do Manual (âncora estável)');
    ui_titulo_pagina(null, true);
    $comoUsuario($semPesquisaExperiencia);
    $_GET = [];
    $htmlAvalSem = $renderizar(static fn() => (new AdminAvaliacoesController())->index());
    $check(!str_contains($htmlAvalSem, 'Pesquisa de Experiência do Candidato'), '(Avaliações) Sem a permissão de Pesquisa de Experiência, o link correspondente NÃO aparece — nenhum acesso foi ampliado');
    $check(str_contains($htmlAvalSem, 'Avaliação após 90 dias'), '(Avaliações) O link para a Avaliação após 90 dias aparece independentemente (não depende de permissão extra)');

    // ---- Avaliações: nenhuma rota/campo/permissão nova ---------------------------------------------------------------------------
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(Avaliações/Manual) Nenhuma permissão nova foi criada');
    $rotasAntes = (string)file_get_contents(BASE_PATH . '/index.php');
    $check(substr_count($rotasAntes, "AdminAvaliacoesController::class") === 6 && substr_count($rotasAntes, "AdminManualController::class") === 1, '(Avaliações/Manual) Nenhuma rota nova foi criada (6 rotas de Avaliações — index/novo GET+POST/editar GET+POST/excluir —, 1 de Manual, como já existiam)');
    $avalCtl = $fonte('app/controllers/AdminAvaliacoesController.php');
    $check(!str_contains($avalCtl, 'requirePermissao'), '(Avaliações) O controller continua sem gate de permissão individual (só requireRole) — inalterado');
    $check(!preg_match('/CREATE TABLE|ALTER TABLE|DROP TABLE/i', $fonte('app/views/admin/avaliacoes/index.php')), '(Avaliações) A view não contém nenhuma instrução de schema (nenhum banco foi tocado)');

    // ---- ModuleTabs: nenhuma megabarra artificial dentro de Avaliações -------------------------------------------------------------
    $check(!preg_match('/aria-label="(Formulários|Respostas|Resultados|Modelos|Aplicações)"/i', $htmlAvalCom), '(Avaliações) Nenhuma ModuleTabs artificial foi criada dentro da área (só existe uma rota real de Avaliações)');

    echo "\nPORTAL_MANUAL_AVALIACOES_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    $_GET = $getOriginal;
    if ($criados !== []) {
        $lu = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
