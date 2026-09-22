<?php

/**
 * Nova UI — Design System Portal RH, FASE 2: primitives do AppShell V2 (docs/claude/DESIGN-SYSTEM-PORTAL-RH.md §4, §8, §17).
 *
 * Publicação consolidada: `layouts/app-shell` é o ÚNICO shell administrativo (o antigo `layouts/admin`, com sidebar, foi
 * removido). Toda página administrativa usa `layouts/app-shell` e chama estes helpers dentro da própria view, informando
 * explicitamente o que quer renderizar (trilha, título, ação, abas).
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

if (!function_exists('ui_titulo_pagina')) {
    /**
     * Título do documento (`<title>`) das páginas do AppShell V2. A view é renderizada ANTES do layout, então o primeiro
     * `ui_page_header()` da página registra aqui o seu título e o layout o lê — sem exigir que cada controller o repasse.
     * Com argumento, grava (só se ainda vazio); sem argumento, lê.
     */
    function ui_titulo_pagina(?string $novo = null, bool $reiniciar = false): string
    {
        static $titulo = '';
        if ($reiniciar) {
            $titulo = '';
        }
        if ($novo !== null && $titulo === '') {
            $titulo = trim($novo);
        }
        return $titulo;
    }
}

if (!function_exists('ui_script_pagina')) {
    /**
     * Scripts ESPECÍFICOS da página (arquivos locais em `assets/`, servidos por `public/assets/`): a CSP do Portal é
     * `script-src 'self'` (sem 'unsafe-inline'), então nada de `<script>` inline nem `onclick=`/`onsubmit=` — o JS vive em
     * arquivo próprio e a view só o REGISTRA (`ui_script_pagina('candidaturas.js')`). O layout `app-shell` emite as tags `<script defer>`
     * (com `?v=` por mtime) depois de `admin.js`. Com argumento registra (aceita só `nome.js` simples, sem repetir); sem argumento lê.
     *
     * @return string[]
     */
    function ui_script_pagina(?string $arquivo = null, bool $reiniciar = false): array
    {
        static $scripts = [];
        if ($reiniciar) {
            $scripts = [];
        }
        if ($arquivo !== null && preg_match('/^[a-z0-9][a-z0-9._-]*\.js$/', $arquivo) === 1 && !in_array($arquivo, $scripts, true)) {
            $scripts[] = $arquivo;
        }
        return $scripts;
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
     *   - `marca`     bool    título na fonte institucional (NewBlack com fallback) — só para H1 de identidade (ex.: Central)
     * Desktop: título à esquerda, ação à direita. Mobile: a ação desce para baixo do título, em largura total.
     *
     * @param array<string,mixed> $opcoes
     */
    function ui_page_header(array $opcoes): string
    {
        ui_titulo_pagina((string)($opcoes['titulo'] ?? ''));
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
            . '<h1 class="' . (!empty($opcoes['marca']) ? 'font-brand ' : '') . 'text-2xl font-extrabold leading-tight text-text-primary md:text-ds-h1">' . Security::e((string)($opcoes['titulo'] ?? '')) . '</h1>';
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

if (!function_exists('ui_badge')) {
    /**
     * Badge de status (§7/§12): texto curto SEMPRE visível + cor semântica (nunca só cor). `$tom`: neutro | primary | success |
     * warning | danger | info. O texto do status é o do sistema — não é traduzido nem agrupado aqui.
     */
    function ui_badge(string $texto, string $tom = 'neutro', string $extra = ''): string
    {
        $tons = [
            'neutro' => 'bg-surface-secondary text-text-muted',
            'primary' => 'bg-primary-100 text-primary-700',
            'success' => 'bg-success/10 text-success',
            'warning' => 'bg-warning/10 text-warning',
            'danger' => 'bg-danger/10 text-danger',
            'info' => 'bg-info/10 text-info',
        ];
        return '<span class="inline-flex items-center whitespace-nowrap rounded-ds-sm px-2 py-1 text-ds-badge ' . ($tons[$tom] ?? $tons['neutro']) . ($extra !== '' ? ' ' . $extra : '') . '">' . Security::e($texto) . '</span>';
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
            if (!empty($aba['ativo']) && (!empty($aba['desabilitado']) || $href === '')) {
                // página atual que o usuário não pode reabrir por este link (ex.: lista restrita): mostra ativa, sem link
                $html .= '<span aria-current="page" class="' . $base . 'border-primary-700 text-primary-700">' . $label . '</span>';
            } elseif (!empty($aba['desabilitado']) || $href === '') {
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

if (!function_exists('ui_module_icon')) {
    /**
     * Ícones da Central: SVG local de traço (24px), decorativo (`aria-hidden`). Um único conjunto, sem biblioteca. Chave
     * desconhecida cai no ícone genérico (nunca quebra a tela).
     */
    function ui_module_icon(string $chave): string
    {
        $tracos = [
            'indicadores' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
            'recrutamento' => '<path d="M3 3v18h18"/><path d="M8 17V11"/><path d="M13 17V7"/><path d="M18 17v-4"/>',
            'vagas' => '<path d="M8 6h10"/><path d="M8 12h10"/><path d="M8 18h10"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
            'colaboradores' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-1a5 5 0 015-5h2a5 5 0 015 5v1"/><path d="M16 5.2a3 3 0 010 5.6"/><path d="M18 14.3a5 5 0 013 4.7v1"/>',
            'pdi' => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
            'integracao' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
            'desligamento' => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'mensagens' => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
            'cadastros' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
            'usuarios' => '<path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
            'generico' => '<rect x="4" y="4" width="16" height="16" rx="3"/>',
        ];
        return '<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . ($tracos[$chave] ?? $tracos['generico']) . '</svg>';
    }
}

if (!function_exists('ui_module_card')) {
    /**
     * ModuleCard (§5/§7 do Design System): a superfície inteira é UM link. Recebe só dados de apresentação — o chamador
     * decide se o card existe (sem permissão = não renderiza). `$card`: `titulo`, `descricao`, `href` (já com `$base`) e
     * `icone` (chave de `ui_module_icon`). Retorna um `<li>` para uso dentro de `ui_module_grid()`.
     *
     * @param array{titulo:string,descricao?:string,href:string,icone?:string} $card
     */
    function ui_module_card(array $card): string
    {
        $foco = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
        return '<li class="min-w-0"><a href="' . Security::e((string)($card['href'] ?? '#')) . '" class="group flex h-full flex-col gap-3 rounded-ds-lg border border-border bg-surface p-[22px] shadow-resting transition duration-150 hover:-translate-y-0.5 hover:border-primary-700 hover:shadow-elevated motion-reduce:transition-none motion-reduce:hover:translate-y-0 ' . $foco . '">'
            . '<span class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-[11px] bg-primary-100 text-primary-700">' . ui_module_icon((string)($card['icone'] ?? 'generico')) . '</span>'
            . '<h2 class="text-ds-h3 text-text-primary">' . Security::e((string)($card['titulo'] ?? '')) . '</h2>'
            . (!empty($card['descricao']) ? '<p class="line-clamp-2 text-[12.5px] leading-snug text-text-secondary">' . Security::e((string)$card['descricao']) . '</p>' : '')
            . '</a></li>';
    }
}

if (!function_exists('ui_module_grid')) {
    /**
     * ModuleGrid: `repeat(auto-fill, minmax(230px, 1fr))` com gap de 18px — o navegador decide as colunas (sem breakpoint por
     * quantidade de cards; `auto-fill` mantém 1 ou 2 cards alinhados, sem esticar). Lista vazia = string vazia: o estado vazio
     * é responsabilidade da página. Nenhum JavaScript.
     *
     * @param array<int,array<string,mixed>> $cards
     */
    function ui_module_grid(array $cards, string $rotulo = 'Módulos disponíveis'): string
    {
        if ($cards === []) {
            return '';
        }
        $html = '<ul class="grid grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-[18px]" aria-label="' . Security::e($rotulo) . '">';
        foreach ($cards as $card) {
            $html .= ui_module_card($card);
        }
        return $html . '</ul>';
    }
}

if (!function_exists('ui_btn')) {
    /**
     * Classes de botão/link-botão do Design System (§7): `primario` (uma ação principal por tela), `secundario` (borda),
     * `destrutivo` (`danger`) e `ghost` (baixa hierarquia). Altura 40px, radius md, foco visível. Uso: `class="<?= ui_btn('primario') ?>"`.
     */
    function ui_btn(string $variante = 'secundario'): string
    {
        $base = 'inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-ds-md px-4 text-ds-button transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-50 ';
        $variantes = [
            'primario' => 'bg-primary-700 text-white hover:bg-primary-800 active:bg-primary-900',
            'secundario' => 'border border-border bg-surface text-primary-700 hover:bg-surface-secondary',
            'destrutivo' => 'bg-danger text-white hover:bg-danger/90',
            'ghost' => 'text-primary-700 hover:bg-primary-50',
        ];
        return $base . ($variantes[$variante] ?? $variantes['secundario']);
    }
}
