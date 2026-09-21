<?php

/**
 * Nova UI — Design System Portal RH, FASE 2: primitives do AppShell V2 (docs/claude/DESIGN-SYSTEM-PORTAL-RH.md §4, §8, §17).
 *
 * OPT-IN: nenhuma tela atual usa estes helpers. As telas existentes continuam no layout `layouts/admin` (com a sidebar);
 * uma página migrada passa a usar `layouts/app-shell` e chama estes helpers dentro da própria view, informando explicitamente
 * o que quer renderizar (trilha, título, ação, abas).
 *
 * Contrato: as funções só RENDERIZAM o que a página fornece — não consultam permissão, perfil, sessão nem banco, e não
 * inferem nada pela URL. Quem decide se um item/ação existe é a view/controller. Todo texto é escapado com Security::e();
 * `href` deve chegar pronto (com o `$base` já aplicado pela view). Só usam tokens do Design System (Fase 1).
 *
 * Padrão idêntico a `admin/partials/chart-helpers.php`: funções com guarda `function_exists`, que RETORNAM string.
 */

if (!function_exists('ui_iniciais')) {
    /** Iniciais para o avatar: primeira letra do primeiro e do último nome (ex.: "Ana Maria Souza" → "AS"). */
    function ui_iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($partes === []) {
            return 'U';
        }
        $primeira = mb_strtoupper(mb_substr($partes[0], 0, 1));
        $ultima = count($partes) > 1 ? mb_strtoupper(mb_substr($partes[count($partes) - 1], 0, 1)) : '';
        return $primeira . $ultima;
    }
}

if (!function_exists('ui_header_v2')) {
    /**
     * Header global (64px): identidade + contexto do Portal à esquerda; usuário e saída à direita. NÃO tem menu de módulos
     * (a navegação entre módulos virá pela Central). `$hrefInicio` é o destino do logotipo (hoje `/admin`).
     */
    function ui_header_v2(string $hrefInicio, string $hrefLogo, string $hrefSair, string $nomeUsuario): string
    {
        $nome = trim($nomeUsuario) !== '' ? trim($nomeUsuario) : 'Usuário';
        $foco = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
        return '<header class="sticky top-0 z-40 flex h-16 items-center justify-between gap-3 border-b border-border bg-surface px-gutter shadow-resting" data-app-header="1">'
            . '<a href="' . Security::e($hrefInicio) . '" class="flex min-w-0 items-center gap-3 rounded-ds-md ' . $foco . '">'
            . '<img src="' . Security::e($hrefLogo) . '" alt="Madeplant" class="h-8 w-auto shrink-0">'
            . '<span class="hidden h-6 w-px bg-border xs:block" aria-hidden="true"></span>'
            . '<span class="truncate font-brand text-ds-h3 text-primary-700">Portal RH</span>'
            . '</a>'
            . '<div class="flex shrink-0 items-center gap-2">'
            . '<div class="flex items-center gap-3">'
            . '<span class="sr-only">Usuário: ' . Security::e($nome) . '</span>'
            . '<span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100 text-ds-label text-primary-700" aria-hidden="true">' . Security::e(ui_iniciais($nome)) . '</span>'
            . '<span class="hidden max-w-56 truncate text-ds-label text-text-primary md:block" aria-hidden="true">' . Security::e($nome) . '</span>'
            . '</div>'
            . '<a href="' . Security::e($hrefSair) . '" class="inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-ds-md px-3 text-ds-button text-primary-700 hover:bg-primary-50 ' . $foco . '" aria-label="Sair">'
            . '<svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>'
            . '<span class="hidden md:inline">Sair</span>'
            . '</a>'
            . '</div>'
            . '</header>';
    }
}

if (!function_exists('ui_breadcrumb')) {
    /**
     * Trilha explícita. `$itens`: [['label' => 'Portal RH', 'href' => '/admin'], ['label' => 'Recrutamento', 'href' => '...'],
     * ['label' => 'Pipeline Kanban']]. O ÚLTIMO item é a página atual: texto escuro, sem link e com aria-current="page"
     * (mesmo que traga `href`). Itens anteriores sem `href` também viram texto simples.
     *
     * @param array<int,array{label:string,href?:?string}> $itens
     */
    function ui_breadcrumb(array $itens): string
    {
        $itens = array_values($itens);
        if ($itens === []) {
            return '';
        }
        $ultimo = count($itens) - 1;
        $html = '<nav aria-label="Trilha de navegação" class="text-ds-caption"><ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-text-muted">';
        foreach ($itens as $i => $item) {
            $label = Security::e((string)($item['label'] ?? ''));
            $href = (string)($item['href'] ?? '');
            $html .= '<li class="flex items-center gap-2">';
            if ($i === $ultimo) {
                $html .= '<span aria-current="page" class="font-semibold text-text-primary">' . $label . '</span>';
            } elseif ($href !== '') {
                $html .= '<a href="' . Security::e($href) . '" class="rounded-ds-sm text-primary-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">' . $label . '</a>';
            } else {
                $html .= '<span>' . $label . '</span>';
            }
            if ($i !== $ultimo) {
                $html .= '<span aria-hidden="true">›</span>';
            }
            $html .= '</li>';
        }
        return $html . '</ol></nav>';
    }
}

if (!function_exists('ui_page_header')) {
    /**
     * Cabeçalho de página. `$opcoes` (todas opcionais, exceto `titulo`):
     *   - `titulo`    string  (H1)
     *   - `eyebrow`   string  contexto curto acima do título
     *   - `descricao` string  linha curta abaixo do título
     *   - `badge`     ['texto' => string, 'tom' => 'neutro'|'primary'|'success'|'warning'|'danger'|'info']  (texto sempre visível)
     *   - `acao`      ['label' => string, 'href' => string]  ação principal (uma por tela); a view decide se ela existe
     * Desktop: título à esquerda, ação à direita. Mobile: a ação desce para baixo do título, em largura total.
     *
     * @param array<string,mixed> $opcoes
     */
    function ui_page_header(array $opcoes): string
    {
        $tons = [
            'neutro' => 'bg-surface-secondary text-text-muted',
            'primary' => 'bg-primary-100 text-primary-700',
            'success' => 'bg-success/10 text-success',
            'warning' => 'bg-warning/10 text-warning',
            'danger' => 'bg-danger/10 text-danger',
            'info' => 'bg-info/10 text-info',
        ];
        $html = '<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">'
            . '<div class="min-w-0">';
        if (!empty($opcoes['eyebrow'])) {
            $html .= '<p class="text-ds-caption font-semibold uppercase tracking-wide text-text-muted">' . Security::e((string)$opcoes['eyebrow']) . '</p>';
        }
        $html .= '<div class="flex flex-wrap items-center gap-x-3 gap-y-2">'
            . '<h1 class="text-2xl font-extrabold leading-tight text-text-primary md:text-ds-h1">' . Security::e((string)($opcoes['titulo'] ?? '')) . '</h1>';
        if (!empty($opcoes['badge']['texto'])) {
            $tom = $tons[(string)($opcoes['badge']['tom'] ?? 'neutro')] ?? $tons['neutro'];
            $html .= '<span class="inline-flex items-center rounded-ds-sm px-2 py-1 text-ds-badge ' . $tom . '">' . Security::e((string)$opcoes['badge']['texto']) . '</span>';
        }
        $html .= '</div>';
        if (!empty($opcoes['descricao'])) {
            $html .= '<p class="mt-1 text-ds-body text-text-secondary">' . Security::e((string)$opcoes['descricao']) . '</p>';
        }
        $html .= '</div>';
        if (!empty($opcoes['acao']['label']) && !empty($opcoes['acao']['href'])) {
            $html .= '<a href="' . Security::e((string)$opcoes['acao']['href']) . '" class="inline-flex h-11 w-full shrink-0 items-center justify-center rounded-ds-md bg-primary-700 px-4 text-ds-button text-white hover:bg-primary-800 active:bg-primary-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus sm:w-auto">'
                . Security::e((string)$opcoes['acao']['label']) . '</a>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('ui_module_tabs')) {
    /**
     * Navegação interna de um módulo (nunca outra sidebar). `$abas`: [['label' => 'Dashboard', 'href' => '...',
     * 'ativo' => true], ['label' => 'Webhooks', 'href' => '...'], ['label' => 'Em breve', 'desabilitado' => true]].
     * Aba ativa: `primary-700` + borda inferior de 2,5px + aria-current="page". No mobile a lista rola horizontalmente
     * DENTRO do componente — a página nunca ganha scroll horizontal.
     *
     * @param array<int,array{label:string,href?:?string,ativo?:bool,desabilitado?:bool}> $abas
     */
    function ui_module_tabs(array $abas, string $rotulo = 'Navegação do módulo'): string
    {
        if ($abas === []) {
            return '';
        }
        $foco = 'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus';
        $base = 'inline-flex h-11 items-center whitespace-nowrap border-b-[2.5px] px-4 text-ds-label ';
        $html = '<nav aria-label="' . Security::e($rotulo) . '" class="min-w-0 border-b border-border"><ul class="-mb-px flex overflow-x-auto">';
        foreach ($abas as $aba) {
            $label = Security::e((string)($aba['label'] ?? ''));
            $href = (string)($aba['href'] ?? '');
            $html .= '<li class="shrink-0">';
            if (!empty($aba['desabilitado']) || $href === '') {
                $html .= '<span aria-disabled="true" class="' . $base . 'cursor-not-allowed border-transparent text-text-muted opacity-50">' . $label . '</span>';
            } elseif (!empty($aba['ativo'])) {
                $html .= '<a href="' . Security::e($href) . '" aria-current="page" class="' . $base . 'border-primary-700 text-primary-700 ' . $foco . '">' . $label . '</a>';
            } else {
                $html .= '<a href="' . Security::e($href) . '" class="' . $base . 'border-transparent text-text-secondary hover:border-primary-300 hover:text-primary-700 ' . $foco . '">' . $label . '</a>';
            }
            $html .= '</li>';
        }
        return $html . '</ul></nav>';
    }
}
