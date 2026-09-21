<?php

/**
 * Unitário — Nova UI, Fase 2: AppShell V2 (layouts/app-shell + partials/ui-shell). Prova que:
 *   - Header V2: identidade + usuário + saída, SEM menu de módulos; nome escapado; iniciais; usuário ausente = "Usuário";
 *   - Breadcrumb (1, 2 e 3 níveis): último item = página atual sem link e com aria-current; anteriores clicáveis; escapado;
 *   - PageHeader: com e sem ação/badge/descrição; ação só existe se a view a informa; texto sempre escapado;
 *   - ModuleTabs: aba ativa com aria-current, aba desabilitada, lista vazia, rolagem contida no componente;
 *   - Layout V2: gutters da Fase 1, sem sidebar, mesmos metas/scripts do layout atual, título escapado;
 *   - compatibilidade: é OPT-IN (nenhum controller/view/rota usa o layout novo), o layout atual e a sidebar seguem intactos,
 *     os componentes não consultam permissão/sessão/banco, e só usam tokens do Design System (sem hex solto, sem @font-face).
 * Verificação por comportamento/código — não depende do estado do Git.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/ui-shell.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};
$semComentarios = static function (string $arquivo): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$conta = static fn(string $agulha, string $palheiro): int => substr_count($palheiro, $agulha);

// ---- iniciais ---------------------------------------------------------------------------------------------------------
$check(ui_iniciais('Ana Maria Souza') === 'AS' && ui_iniciais('fabio ozuna') === 'FO' && ui_iniciais('Madonna') === 'M' && ui_iniciais('  ') === 'U' && ui_iniciais('Émerson Ávila') === 'ÉÁ', '(iniciais) Primeira e última letra do nome, maiúsculas, com acentos; vazio = "U"');

// ---- header ------------------------------------------------------------------------------------------------------------
$h = ui_header_v2('/admin', '/assets/logo-escura.png', '/admin/logout', 'Fabio <script>alert(1)</script> Lima');
$check(str_contains($h, '<header') && str_contains($h, 'h-16') && str_contains($h, 'Portal RH') && str_contains($h, '/assets/logo-escura.png') && str_contains($h, 'alt="Madeplant"'), '(header) 64px, marca Madeplant existente + texto "Portal RH"');
$check(!str_contains($h, '<script>') && str_contains($h, '&lt;script&gt;'), '(header) Nome do usuário é escapado');
$check($conta('<a ', $h) === 2 && str_contains($h, 'href="/admin"') && str_contains($h, 'href="/admin/logout"') && !str_contains($h, '<nav') && !str_contains($h, '<ul'), '(header) Só identidade e usuário: apenas o link de início e o de saída — sem menu de módulos');
$check(str_contains($h, 'aria-label="Sair"') && str_contains($h, 'h-11') && str_contains($h, 'min-w-11') && str_contains($h, 'sr-only'), '(header) Saída acessível (label + alvo 44px) e nome do usuário disponível a leitores de tela');
$check(str_contains($h, 'hidden') && str_contains($h, 'md:block') && str_contains($h, 'md:inline'), '(header) Mobile oculta o nome e o texto "Sair"; mantém avatar e ação');
$check(str_contains(ui_header_v2('/a', '/l', '/s', ''), 'Usuário: Usuário') && str_contains(ui_header_v2('/a', '/l', '/s', ''), '>U<'), '(header) Sem nome na sessão: fallback "Usuário"');

// ---- breadcrumb --------------------------------------------------------------------------------------------------------
$b1 = ui_breadcrumb([['label' => 'Portal RH']]);
$check($conta('aria-current="page"', $b1) === 1 && !str_contains($b1, '<a ') && !str_contains($b1, '›'), '(breadcrumb 1 nível) Só a página atual, sem link e sem separador');
$b2 = ui_breadcrumb([['label' => 'Portal RH', 'href' => '/admin'], ['label' => 'Recrutamento e Seleção']]);
$check($conta('<a ', $b2) === 1 && $conta('aria-current="page"', $b2) === 1 && $conta('›', $b2) === 1, '(breadcrumb 2 níveis) Primeiro clicável, atual sem link, um separador');
$b3 = ui_breadcrumb([['label' => 'Portal RH', 'href' => '/admin'], ['label' => 'Recrutamento <b>', 'href' => '/admin/vagas'], ['label' => 'Pipeline', 'href' => '/nao-deve-virar-link']]);
$check($conta('<a ', $b3) === 2 && !str_contains($b3, '/nao-deve-virar-link') && $conta('aria-current="page"', $b3) === 1 && $conta('›', $b3) === 2 && str_contains($b3, 'Recrutamento &lt;b&gt;'), '(breadcrumb 3 níveis) Anteriores clicáveis; o atual nunca é link (mesmo com href); texto escapado');
$check(str_contains($b3, 'aria-label="Trilha de navegação"') && str_contains($b3, '<ol') && str_contains($b3, 'flex-wrap') && $conta('aria-hidden="true">›', $b3) === 2, '(breadcrumb) nav rotulado, lista semântica, quebra de linha responsiva e separador oculto de leitores de tela');
$check(ui_breadcrumb([]) === '' && $conta('<a ', ui_breadcrumb([['label' => 'Portal RH'], ['label' => 'Sem link', 'href' => ''], ['label' => 'Atual']])) === 0, '(breadcrumb) Vazio = nada; item intermediário sem href vira texto simples');

// ---- page header -------------------------------------------------------------------------------------------------------
$p0 = ui_page_header(['titulo' => 'Usuários']);
$check(str_contains($p0, '<h1') && str_contains($p0, 'Usuários') && !str_contains($p0, '<a ') && !str_contains($p0, '<p '), '(page header) Só título: sem ação, badge, eyebrow nem descrição');
$p1 = ui_page_header(['eyebrow' => 'Recrutamento', 'titulo' => 'Vagas <i>', 'descricao' => 'Desc <b>x</b>', 'badge' => ['texto' => 'Em andamento', 'tom' => 'info'], 'acao' => ['label' => '+ Nova', 'href' => '/admin/nova']]);
$check(str_contains($p1, 'Recrutamento') && str_contains($p1, 'Vagas &lt;i&gt;') && str_contains($p1, 'Desc &lt;b&gt;') && str_contains($p1, 'Em andamento') && str_contains($p1, 'text-info') && $conta('<a ', $p1) === 1 && str_contains($p1, 'href="/admin/nova"') && str_contains($p1, '+ Nova'), '(page header) Completo: eyebrow, título, descrição, badge com texto e ação; tudo escapado');
$check(str_contains($p1, 'sm:flex-row') && str_contains($p1, 'flex-col') && str_contains($p1, 'w-full') && str_contains($p1, 'sm:w-auto') && str_contains($p1, 'h-11'), '(page header) Ação à direita no desktop; no mobile desce e ocupa a largura (alvo 44px)');
$check(!str_contains(ui_page_header(['titulo' => 'X', 'acao' => ['label' => 'Só label']]), '<a ') && !str_contains(ui_page_header(['titulo' => 'X', 'acao' => ['href' => '/x']]), '<a '), '(page header) Ação incompleta (sem label ou sem href) não é renderizada');
$check(str_contains(ui_page_header(['titulo' => 'X', 'badge' => ['texto' => 'Novo', 'tom' => 'inexistente']]), 'bg-surface-secondary') && str_contains(ui_page_header(['titulo' => 'X', 'badge' => ['texto' => 'Novo']]), 'Novo'), '(page header) Tom de badge desconhecido cai no neutro; o texto do status sempre aparece');

// ---- module tabs -------------------------------------------------------------------------------------------------------
$t = ui_module_tabs([['label' => 'Dashboard', 'href' => '/a', 'ativo' => true], ['label' => 'Candidaturas <b>', 'href' => '/b'], ['label' => 'Em breve', 'desabilitado' => true], ['label' => 'Sem link']], 'Recrutamento');
$check($conta('aria-current="page"', $t) === 1 && str_contains($t, 'border-primary-700') && str_contains($t, 'text-primary-700') && str_contains($t, 'border-b-[2.5px]'), '(tabs) Aba ativa: primary-700 + borda inferior de 2,5px + aria-current');
$check($conta('<a ', $t) === 2 && $conta('aria-disabled="true"', $t) === 2 && str_contains($t, 'Candidaturas &lt;b&gt;'), '(tabs) Desabilitada e sem href não são links; texto escapado');
$check(str_contains($t, 'aria-label="Recrutamento"') && str_contains($t, '<nav') && str_contains($t, 'overflow-x-auto') && str_contains($t, 'min-w-0') && str_contains($t, 'whitespace-nowrap'), '(tabs) nav rotulado; rolagem horizontal contida no componente (a página não ganha scroll)');
$check(ui_module_tabs([]) === '', '(tabs) Sem abas = nada');

// ---- layout V2 ---------------------------------------------------------------------------------------------------------
$view = new View();
$_SESSION['user_name'] = 'Maria Teste';
$html = $view->renderPartial('layouts/app-shell', ['base' => '', 'tituloPagina' => 'Vagas <x>', 'content' => '<p id="conteudo-teste">olá</p>']);
$check(str_contains($html, 'data-app-shell-v2="1"') && str_contains($html, '<main id="conteudo"') && str_contains($html, 'id="conteudo-teste"') && str_contains($html, 'px-gutter') && str_contains($html, 'MT'), '(layout) Header V2 + conteúdo dentro de <main> com os gutters da Fase 1 (px-gutter) e usuário da sessão');
$check(preg_match('#<main[^>]*max-w-#', $html) === 0 && !str_contains($html, 'data-admin-sidebar') && !str_contains($html, 'class="sidebar') && !str_contains($html, 'app-header"'), '(layout) Sem largura máxima global e sem sidebar/header do layout atual');
$check(str_contains($html, '<title>Vagas &lt;x&gt; — Portal RH</title>') && str_contains($html, 'name="csrf-token"') && str_contains($html, 'name="app-base"') && preg_match('#/assets/tailwind\.css\?v=\d+#', $html) === 1 && str_contains($html, 'assets/admin.js?v=') && str_contains($html, 'assets/phone-utils.js?v='), '(layout) Título escapado; mesmos metas e scripts do layout atual; CSS versionado por mtime');
$check(str_contains($html, 'href="#conteudo"') && str_contains($html, 'Ir para o conteúdo') && str_contains($html, 'href="/admin/logout"'), '(layout) Link "pular para o conteúdo" e saída existente (/admin/logout)');
unset($_SESSION['user_name']);
$semTitulo = $view->renderPartial('layouts/app-shell', ['base' => '', 'content' => 'x']);
$check(str_contains($semTitulo, '<title>Portal RH</title>') && str_contains($semTitulo, 'Usuário: Usuário'), '(layout) Sem título e sem usuário na sessão: valores padrão');

// ---- compatibilidade / escopo ------------------------------------------------------------------------------------------
$usaLayoutNovo = [];
foreach (['app/controllers', 'app/views', 'app/core', 'app/services'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        if ($arq->isFile() && $arq->getExtension() === 'php' && !in_array($arq->getFilename(), ['app-shell.php', 'ui-shell.php'], true)) {
            $c = (string)file_get_contents($arq->getPathname());
            if (str_contains($c, 'layouts/app-shell') || str_contains($c, 'ui_header_v2') || str_contains($c, 'ui-shell.php')) {
                $usaLayoutNovo[] = str_replace(BASE_PATH . '/', '', $arq->getPathname());
            }
        }
    }
}
$check($usaLayoutNovo === [] && !str_contains((string)file_get_contents(BASE_PATH . '/index.php'), 'app-shell'), '(opt-in) Nenhum controller, view, rota ou serviço usa o AppShell V2 ainda — as telas atuais seguem no layout antigo');
$admin = (string)file_get_contents(APP_PATH . '/views/layouts/admin.php');
$check(str_contains($admin, 'layouts/sidebar.php') && str_contains($admin, 'data-admin-sidebar="1"') && str_contains($admin, 'data-admin-header="1"') && !str_contains($admin, 'ui-shell') && str_contains((string)file_get_contents(APP_PATH . '/views/layouts/sidebar.php'), '/admin/logout'), '(compatibilidade) layouts/admin e a sidebar continuam com a estrutura atual e sem dependência do shell novo');
foreach (['app/views/partials/ui-shell.php', 'app/views/layouts/app-shell.php'] as $arq) {
    $c = $semComentarios(BASE_PATH . '/' . $arq);
    $check(!preg_match('/Authorization::|Auth::|Database::|\$_GET|\$_POST|\bPDO\b|->prepare\(/', $c), "(permissões) {$arq} não consulta permissão, perfil nem banco — só renderiza o que a página informa");
    $check(!preg_match('/bg-\[#|text-\[#|border-\[#|style="|#[0-9A-Fa-f]{6}\b/', $c) && !str_contains($c, 'ct-btn') && !str_contains($c, 'ctdark') && !str_contains($c, 'Montserrat'), "(tokens) {$arq} usa só tokens do Design System — sem hex solto, sem classes ct* nem Montserrat");
}
$css = (string)file_get_contents(BASE_PATH . '/assets/tailwind-input.css');
$check(!str_contains($css, '@font-face') && !str_contains((string)file_get_contents(BASE_PATH . '/public/assets/tailwind.css'), 'NewBlack.woff'), '(NewBlack) Sem @font-face nem referência a arquivo inexistente: fallback de sistema');
$check(is_file(BASE_PATH . '/public/assets/logo-escura.png'), '(marca) O logotipo reutilizado existe em public/assets');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_UI_SHELL_OK\n";
