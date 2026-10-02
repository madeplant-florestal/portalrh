<?php

/**
 * Unitário — dashboard_grouped_bar_rows() (ajuste visual de 2026-10, pedido do RH): barras
 * horizontais agrupadas, substitui dashboard_grouped_columns() rotacionado só no gráfico
 * "Colaboradores por Setor — Comparativo" (app/views/admin/partials/dashboard/resultado.php).
 * Só arrays sintéticos, sem banco. Prova, contra a string HTML retornada:
 *   - todos os rótulos (inclusive "Setor não informado") aparecem por extenso, sem truncamento e
 *     sem nenhum `rotate(`/`<svg` — é HTML puro, nunca mais inclinado;
 *   - os mesmos valores das duas séries aparecem formatados, cada um associado à série correta
 *     (checado pelo texto do tooltip "Série — Rótulo: valor", nunca por posição solta);
 *   - a largura da barra é proporcional ao valor sobre o máximo global das duas séries (não por
 *     categoria) — maior valor = 100%, metade do valor = 50%;
 *   - clique-to-filter continua funcionando: atributos data-pa-dimensao/data-pa-valor e a classe
 *     dashboard-pa-clicavel aparecem no BLOCO da categoria (rótulo + as duas barras), nunca em
 *     cada barra isolada;
 *   - valor ausente (null) numa série não quebra nem inventa 0 — a barra daquela série
 *     simplesmente não é desenhada para aquela categoria (mesma convenção de
 *     dashboard_grouped_columns());
 *   - sem categorias, devolve string vazia (nenhum card vazio renderizado à toa).
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/admin/partials/chart-helpers.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

try {
    $labels = ['Administrativo Financeiro e Controladoria Compartilhado', 'Operações Florestais', 'Setor não informado'];
    $series = [
        ['label' => 'Período selecionado', 'color' => '#3B4822', 'values' => [40, 20, 4]],
        ['label' => 'Comparativo', 'color' => '#A9B885', 'values' => [30, 20, null]],
    ];
    $clique = [
        0 => ['dimensao' => 'setor', 'valor' => 'ADM', 'ativo' => false],
        1 => ['dimensao' => 'setor', 'valor' => 'OPF', 'ativo' => true],
        2 => ['dimensao' => 'setor', 'valor' => 'Não informado', 'ativo' => false],
    ];
    $html = dashboard_grouped_bar_rows($labels, $series, ['clique_categoria' => $clique]);

    foreach ($labels as $rotulo) {
        $check(str_contains($html, Security::e($rotulo)), "rótulo por extenso presente: \"{$rotulo}\"");
    }
    $check(!str_contains($html, 'rotate('), 'nenhum texto rotacionado (sem transform rotate)');
    $check(!str_contains($html, '<svg'), 'é HTML puro — nenhum elemento <svg> (diferente de dashboard_grouped_columns())');
    $check(!preg_match('/\p{L}{9,}…/u', $html), 'nenhum nome de setor truncado com reticências');

    $check(str_contains($html, 'title="Período selecionado — Administrativo Financeiro e Controladoria Compartilhado: 40"'), 'tooltip da 1ª série no 1º setor associa série+rótulo+valor corretamente (maior valor global = 100%)');
    $check(str_contains($html, 'width: 100.00%'), 'maior valor global (40) gera barra de 100% de largura');
    $check(str_contains($html, 'title="Comparativo — Administrativo Financeiro e Controladoria Compartilhado: 30"') && str_contains($html, 'width: 75.00%'), 'valor 30 sobre o máximo global 40 = 75% de largura, série Comparativo corretamente identificada');
    $check(str_contains($html, 'title="Período selecionado — Operações Florestais: 20"') && str_contains($html, 'width: 50.00%'), 'valor 20 sobre o máximo global 40 = 50% de largura');

    $check(str_contains($html, '#3B4822') && str_contains($html, '#A9B885'), 'as duas cores das séries aparecem no HTML (background-color inline)');

    $check(str_contains($html, 'title="Período selecionado — Setor não informado: 4"'), '"Setor não informado" recebe tooltip/valor normalmente, sem tratamento especial');
    $check(!str_contains($html, 'Comparativo — Setor não informado'), 'valor null (Comparativo do 3º setor) não é desenhado — nunca vira 0 inventado');

    $check(str_contains($html, 'data-pa-dimensao="setor"') && str_contains($html, 'data-pa-valor="ADM"'), 'clique-to-filter: atributos data-pa-* presentes no bloco da categoria');
    $check(str_contains($html, 'dashboard-pa-clicavel') && str_contains($html, 'dashboard-pa-clicavel--ativo'), 'clique-to-filter: classe de clicável presente, e a categoria ativa (Operações Florestais) ganha a classe --ativa');
    $check(substr_count($html, 'data-pa-dimensao="setor"') === 3, 'cada uma das 3 categorias tem exatamente UM bloco clicável (nunca uma por barra individual)');

    $check(dashboard_grouped_bar_rows([], []) === '', 'sem categorias, devolve string vazia — nenhum card vazio desenhado à toa');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nDASHBOARD_GROUPED_BAR_ROWS_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    exit(1);
}
