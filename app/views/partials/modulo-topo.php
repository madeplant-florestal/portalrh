<?php

/**
 * Topo padrão de página de módulo migrado (Design System §8): Breadcrumb → PageHeader → ModuleTabs. Cola os helpers de
 * `ui-shell.php` com as abas de `PortalNavegacaoService` (fonte única de visibilidade; nunca mostra aba que leve a 403).
 *
 * `ui_modulo_topo($base, $modulo, $abaAtiva, $pagina, $trilha)`:
 *   - `$modulo`    chave em `PortalNavegacaoService::definicaoAbas()` (hoje: `recrutamento`);
 *   - `$abaAtiva`  chave da aba da página (a aba atual sempre aparece);
 *   - `$pagina`    opções de `ui_page_header()` (titulo, descricao, badge, acao...);
 *   - `$trilha`    itens EXTRAS depois da aba, para telas de detalhe: [['label' => 'Vaga X', 'href' => null]]. O último
 *                  item é a página atual. Sem trilha, a própria aba é a página atual.
 * Trilha: Portal RH (/admin) › <Módulo> (entrada lógica) › <Aba> [› detalhe...].
 */
require_once __DIR__ . '/ui-shell.php';

if (!function_exists('ui_modulo_topo')) {
    /**
     * @param array<string,mixed> $pagina
     * @param array<int,array{label:string,href?:?string}> $trilha
     */
    function ui_modulo_topo(string $base, string $modulo, string $abaAtiva, array $pagina, array $trilha = []): string
    {
        $svc = new PortalNavegacaoService();
        $definicao = PortalNavegacaoService::definicaoAbas()[$modulo] ?? null;
        if ($definicao === null) {
            return ui_page_header($pagina);
        }
        $abas = $svc->abas($modulo, $abaAtiva);
        $entrada = $svc->entradaDoModulo($modulo);
        $rotuloAba = '';
        $hrefAba = '';
        $abaAtivaAcessivel = true;
        foreach ($abas as $aba) {
            if ($aba['chave'] === $abaAtiva) {
                $rotuloAba = $aba['label'];
                $hrefAba = $base . $aba['href'];
                $abaAtivaAcessivel = $aba['acessivel'];
            }
        }
        $migalhas = [['label' => 'Portal RH', 'href' => $base . '/admin']];
        if ($definicao['trilha_modulo'] ?? true) {
            $migalhas[] = ['label' => $definicao['titulo'], 'href' => $entrada !== null ? $base . $entrada : null];
        }
        if ($rotuloAba !== '') {
            // a aba só vira link no breadcrumb se houver detalhe depois E o usuário puder abri-la
            $migalhas[] = ['label' => $rotuloAba, 'href' => $trilha !== [] && $abaAtivaAcessivel ? $hrefAba : null];
        }
        foreach ($trilha as $item) {
            $migalhas[] = $item;
        }
        $tabs = array_map(static fn(array $a): array => ['label' => $a['label'], 'href' => $base . $a['href'], 'ativo' => $a['ativo'], 'desabilitado' => !$a['acessivel']], $abas);
        return '<div class="space-y-4">'
            . ui_breadcrumb($migalhas)
            . ui_page_header($pagina)
            . ui_module_tabs($tabs, $definicao['titulo'])
            . '</div>';
    }
}
