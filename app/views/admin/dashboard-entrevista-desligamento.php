<?php
/**
 * Dashboard da Entrevista de Desligamento — só apresentação; todo cálculo vem de
 * DashboardEntrevistaDesligamentoService. AGREGADO: sem nome, token, metadados_id nem comentários individuais.
 *
 * Duas populações: desligamentos oficiais (contratos do METADADOS) e entrevistas RESPONDIDAS (denominador de cada
 * indicador de resposta = nº de respostas válidas, sempre exibido). Ausência de base é "Sem base" — nunca 0.
 */
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$num1 = static fn(?float $v): string => $v === null ? 'Sem base' : number_format($v, 1, ',', '.');
$pct = static fn(?float $v): string => $v === null ? 'Sem base' : number_format($v, 1, ',', '.') . '%';
$enpsFmt = static fn(?float $v): string => $v === null ? 'Sem base' : ($v > 0 ? '+' : '') . number_format($v, 1, ',', '.');
$respostas = static fn(int $n): string => $n . ($n === 1 ? ' resposta' : ' respostas');
$dataBr = static fn(DateTimeImmutable $d): string => $d->format('d/m/Y');
$inputClasses = 'rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100';
$cardClasses = 'min-w-0 rounded-ds-lg border border-border bg-surface p-4 shadow-resting';
$corBarra = 'bg-primary-700';
$cardKpi = static function (string $rotulo, string $valor, string $sub = '') use ($cardClasses): string {
    return '<div class="' . $cardClasses . '"><p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">' . Security::e($rotulo) . '</p>'
        . '<p class="mt-1 text-xl font-bold leading-tight text-text-primary">' . Security::e($valor) . '</p>'
        . ($sub !== '' ? '<p class="mt-0.5 text-[11px] text-text-secondary">' . Security::e($sub) . '</p>' : '') . '</div>';
};
$queryFiltros = static fn(array $extra): string => http_build_query(array_filter($extra + [
    'unidade' => $filtros['unidade_chave'] ?? '', 'cargo' => $filtros['codigo_cargo'] ?? '',
], static fn($v) => $v !== '' && $v !== null));
?>
<div class="space-y-4">

  <?= ui_modulo_topo($base, 'desligamento', 'dashboard-entrevista', [
      'titulo' => 'Dashboard da Entrevista de Desligamento',
      'descricao' => 'Cobertura, motivos declarados e experiência dos ex-colaboradores — indicadores agregados',
  ]) ?>
  <p class="text-ds-caption text-text-secondary">Última atualização do METADADOS: <strong class="text-text-primary"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></strong></p>
  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <form method="get" class="flex flex-wrap items-end gap-2">
      <div>
        <label for="filtro-inicio" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">De</label>
        <input id="filtro-inicio" type="date" name="inicio" value="<?= Security::e($filtros['inicio']->format('Y-m-d')) ?>" class="<?= $inputClasses ?>">
      </div>
      <div>
        <label for="filtro-fim" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Até</label>
        <input id="filtro-fim" type="date" name="fim" value="<?= Security::e($filtros['fim']->format('Y-m-d')) ?>" class="<?= $inputClasses ?>">
      </div>
      <div>
        <label for="filtro-unidade" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Unidade</label>
        <select id="filtro-unidade" name="unidade" class="<?= $inputClasses ?>">
          <option value="">Todas as unidades</option>
          <?php foreach ($opcoes['unidades'] as $u): ?>
            <option value="<?= Security::e($u['chave']) ?>" <?= ($filtros['unidade_chave'] ?? '') === $u['chave'] ? 'selected' : '' ?>><?= Security::e($u['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-cargo" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Cargo</label>
        <select id="filtro-cargo" name="cargo" class="<?= $inputClasses ?>">
          <option value="">Todos os cargos</option>
          <?php foreach ($opcoes['cargos'] as $c): ?>
            <option value="<?= Security::e($c['codigo']) ?>" <?= ($filtros['codigo_cargo'] ?? '') === $c['codigo'] ? 'selected' : '' ?>><?= Security::e($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="<?= ui_btn('primario') ?>">Aplicar</button>
      <a href="<?= $base ?>/admin/dashboard-entrevista-desligamento" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </form>
    <div class="mt-2 flex flex-wrap gap-1.5" aria-label="Atalhos de período">
      <?php foreach ($atalhos as $a): ?>
        <a href="<?= $base ?>/admin/dashboard-entrevista-desligamento?<?= Security::e($queryFiltros(['inicio' => $a['inicio'], 'fim' => $a['fim']])) ?>" class="rounded-full border border-border px-2.5 py-1 text-xs text-text-primary hover:bg-primary-50"><?= Security::e($a['rotulo']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php foreach (($filtros['avisos'] ?? []) as $aviso): ?>
      <p class="mt-1.5 rounded bg-warning/10 px-2 py-1 text-xs text-warning"><?= Security::e($aviso) ?></p>
    <?php endforeach; ?>
    <p class="mt-1.5 text-[11px] leading-snug text-text-secondary">
      Período: <?= Security::e($dataBr($filtros['inicio'])) ?> a <?= Security::e($dataBr($filtros['fim'])) ?> · competência = <strong>data de desligamento</strong> (não a data da resposta) ·
      Unidade e Cargo filtram todos os indicadores
    </p>
    <details class="mt-1.5">
      <summary class="cursor-pointer text-[11px] font-semibold text-primary-700">Como ler este painel</summary>
      <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[11px] leading-snug text-text-secondary">
        <li><strong>Desligamentos</strong>: contratos oficiais do METADADOS (unidade = contrato). <strong>Geradas / respondidas</strong>: entrevistas do módulo, pelo mês da demissão.</li>
        <li>Motivos, fatores, satisfação, eNPS, liderança, cultura e integração usam <strong>somente entrevistas respondidas</strong>; o número de respostas aparece junto de cada indicador.</li>
        <li>Taxa de resposta = respondidas ÷ geradas. eNPS = % promotores (9–10) − % detratores (0–6). Satisfação geral = média da nota de 0 a 10; demais blocos, média de 1 a 5.</li>
        <li>Tempo médio de permanência = média de (demissão − admissão) em dias ÷ 30,4375, em meses; contratos sem admissão válida ficam de fora.</li>
        <li>Falecimento (motivo oficial 020) não recebe entrevista e não entra na cobertura.</li>
        <li>Nesta versão não há análise por tipo de desligamento, Área ou Gestor (sem fonte oficial confiável).</li>
      </ul>
    </details>
  </section>

  <?php if ($erro !== null || $painel === null): ?>
    <section class="rounded-ds-lg border border-danger/30 bg-danger/10 p-4">
      <p class="text-sm font-semibold text-danger"><?= Security::e((string)$erro) ?></p>
    </section>
  <?php else: ?>
  <?php
    $x = $painel['executivo'];
    $cob = $x['cobertura'];
    $meses = $painel['mensal'];
    $muitosMeses = count($meses) > 18;
    $rotulosMes = [];
    foreach ($meses as $i => $m) {
        $rotulosMes[] = (!$muitosMeses || $i % 3 === 0) ? $m['rotulo'] : '';
    }
    $col = static fn(string $chave): array => array_map(static fn(array $m) => $m[$chave], $meses);
    $opcoesGrafico = ['valores' => !$muitosMeses];
  ?>

  <!-- Faixa 1 — visão executiva e cobertura -->
  <section class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7" aria-label="Visão executiva">
    <?= $cardKpi('Desligamentos', (string)$x['desligamentos'], 'contratos no período') ?>
    <?= $cardKpi('Entrevistas geradas', (string)$x['geradas']) ?>
    <?= $cardKpi('Respondidas', (string)$x['respondidas']) ?>
    <?= $cardKpi('Taxa de resposta', $pct($x['taxa_resposta']), 'respondidas ÷ geradas') ?>
    <?= $cardKpi('Satisfação geral', $x['satisfacao']['media'] === null ? 'Sem base' : $num1($x['satisfacao']['media']) . ' / 10', $respostas($x['satisfacao']['n'])) ?>
    <?= $cardKpi('eNPS', $enpsFmt($x['enps']['valor']), $respostas($x['enps']['n'])) ?>
    <?= $cardKpi('Permanência média', $x['permanencia']['meses'] === null ? 'Sem base' : $num1($x['permanencia']['meses']) . ' meses', $x['permanencia']['n'] . ' contrato(s)' . ($x['permanencia']['invalidos'] > 0 ? ' · ' . $x['permanencia']['invalidos'] . ' sem datas válidas' : '')) ?>
  </section>

  <section class="<?= $cardClasses ?>" aria-labelledby="cobertura-titulo">
    <h2 id="cobertura-titulo" class="text-sm font-bold text-text-primary">Cobertura da pesquisa</h2>
    <p class="text-[11px] text-text-secondary">Quantos desligamentos foram convertidos em entrevista gerada e em entrevista respondida.</p>
    <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
      <?= dashboard_bar_row('Entrevista gerada (desligamentos elegíveis)', (float)$cob['com_entrevista'], (float)$cob['elegiveis'], $cob['elegiveis'] > 0 ? $cob['com_entrevista'] . ' de ' . $cob['elegiveis'] . ' (' . $pct($cob['percentual']) . ')' : 'Sem base', $corBarra) ?>
      <?= dashboard_bar_row('Entrevista respondida (geradas)', (float)$x['respondidas'], (float)$x['geradas'], $x['geradas'] > 0 ? $x['respondidas'] . ' de ' . $x['geradas'] . ' (' . $pct($x['taxa_resposta']) . ')' : 'Sem base', $corBarra) ?>
    </div>
    <?php if ($cob['sem_entrevista'] > 0): ?>
      <p class="mt-2 rounded-ds-md bg-warning/10 px-3 py-2 text-xs font-medium text-warning">
        <?= (int)$cob['sem_entrevista'] ?> desligamento(s) elegível(is) ainda sem entrevista gerada no período — os indicadores de resposta abaixo não os representam.
        <?php if (Authorization::temPermissao('entrevista_desligamento.visualizar')): ?>
          <a href="<?= $base ?>/admin/entrevistas-desligamento" class="underline">Abrir Entrevistas de Desligamento</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($cob['nao_elegiveis'] > 0): ?>
      <p class="mt-1 text-[11px] text-text-secondary"><?= (int)$cob['nao_elegiveis'] ?> desligamento(s) não elegível(is) (Falecimento) fora da cobertura.</p>
    <?php endif; ?>
  </section>

  <!-- Faixa 2 — por que estamos perdendo pessoas? -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-text-primary">Top 5 motivos principais — declarados nas entrevistas</h2>
      <p class="text-[11px] text-text-secondary">Percepção do ex-colaborador (não é o motivo oficial do METADADOS). Base: <?= Security::e($respostas($painel['motivos']['total'])) ?>.</p>
      <div class="mt-3 space-y-3">
        <?php if ($painel['motivos']['itens'] === []): ?>
          <p class="text-sm text-text-secondary">Sem base — nenhuma entrevista respondida no período.</p>
        <?php endif; ?>
        <?php foreach ($painel['motivos']['itens'] as $m): ?>
          <?= dashboard_bar_row($m['rotulo'], (float)$m['quantidade'], (float)$painel['motivos']['itens'][0]['quantidade'], $m['quantidade'] . ' · ' . $pct($m['percentual']), $corBarra) ?>
        <?php endforeach; ?>
      </div>
      <?php if ($painel['motivos']['itens'] !== []): ?>
        <?= dashboard_data_table('Top 5 motivos declarados', ['Motivo', 'Respostas', '% das respostas'], array_map(static fn(array $m): array => [$m['rotulo'], (string)$m['quantidade'], $pct($m['percentual'])], $painel['motivos']['itens'])) ?>
      <?php endif; ?>
    </article>

    <article class="<?= $cardClasses ?>">
      <h2 class="text-sm font-bold text-text-primary">Fatores contribuintes mais recorrentes</h2>
      <p class="text-[11px] text-text-secondary">Seleção múltipla: uma entrevista pode marcar vários fatores, então os percentuais não somam 100%. Base: <?= Security::e($respostas($painel['fatores']['base'])) ?>.</p>
      <div class="mt-3 space-y-3">
        <?php if ($painel['fatores']['itens'] === []): ?>
          <p class="text-sm text-text-secondary">Sem base — nenhum fator marcado no período.</p>
        <?php endif; ?>
        <?php foreach ($painel['fatores']['itens'] as $fator): ?>
          <?= dashboard_bar_row($fator['rotulo'], (float)$fator['quantidade'], (float)$painel['fatores']['itens'][0]['quantidade'], $fator['quantidade'] . ' · ' . $pct($fator['percentual']), $corBarra) ?>
        <?php endforeach; ?>
      </div>
      <?php if ($painel['fatores']['itens'] !== []): ?>
        <?= dashboard_data_table('Fatores contribuintes', ['Fator', 'Marcações', '% das entrevistas respondidas'], array_map(static fn(array $f): array => [$f['rotulo'], (string)$f['quantidade'], $pct($f['percentual'])], $painel['fatores']['itens'])) ?>
      <?php endif; ?>
    </article>
  </section>

  <section class="<?= $cardClasses ?>" aria-labelledby="evolucao-titulo">
    <h2 id="evolucao-titulo" class="text-sm font-bold text-text-primary">Evolução mensal — pelo mês do desligamento</h2>
    <p class="text-[11px] text-text-secondary">A entrevista respondida em um mês posterior continua no mês da demissão. Mês sem ponto = sem base (nenhuma resposta ou entrevista gerada), nunca zero.<?= $painel['periodo']['bordas_parciais'] ? ' Os meses das pontas consideram só os dias do período selecionado.' : '' ?></p>
    <div class="mt-3 grid grid-cols-1 gap-4 2xl:grid-cols-2">
      <div class="min-w-0">
        <h3 class="text-xs font-bold text-text-primary">Desligamentos × entrevistas respondidas</h3>
        <div class="mt-1"><?= dashboard_chart_legend([['label' => 'Desligamentos', 'color' => '#A9B885'], ['label' => 'Respondidas', 'color' => '#3B4822']]) ?></div>
        <?= dashboard_grouped_columns($rotulosMes, [
            ['label' => 'Desligamentos', 'color' => '#A9B885', 'values' => $col('desligamentos')],
            ['label' => 'Respondidas', 'color' => '#3B4822', 'values' => $col('respondidas')],
        ], 'Desligamentos e entrevistas respondidas por mês', $opcoesGrafico) ?>
      </div>
      <div class="min-w-0">
        <h3 class="text-xs font-bold text-text-primary">Taxa de resposta (%)</h3>
        <?= dashboard_multi_line_chart($rotulosMes, [['label' => 'Taxa de resposta', 'color' => '#3B4822', 'values' => $col('taxa_resposta')]], '%', 1, 'Taxa de resposta por mês', ['min' => 0, 'max' => 100] + $opcoesGrafico) ?>
      </div>
      <div class="min-w-0">
        <h3 class="text-xs font-bold text-text-primary">eNPS (−100 a +100)</h3>
        <?= dashboard_multi_line_chart($rotulosMes, [['label' => 'eNPS', 'color' => '#3B4822', 'values' => $col('enps')]], '', 1, 'eNPS por mês', ['min' => -100, 'max' => 100] + $opcoesGrafico) ?>
      </div>
      <div class="min-w-0">
        <h3 class="text-xs font-bold text-text-primary">Satisfação geral (0 a 10)</h3>
        <?= dashboard_multi_line_chart($rotulosMes, [['label' => 'Satisfação geral', 'color' => '#3B4822', 'values' => $col('satisfacao')]], '', 1, 'Satisfação geral por mês', ['min' => 0, 'max' => 10] + $opcoesGrafico) ?>
      </div>
      <div class="min-w-0 2xl:col-span-2">
        <h3 class="text-xs font-bold text-text-primary">Avaliação média da liderança (1 a 5)</h3>
        <?php $semBaseLideranca = array_filter($col('lideranca'), static fn($v): bool => $v !== null) === []; // zero real (0.0) NÃO é ausência de base ?>
        <?php if ($semBaseLideranca): ?>
          <div class="mt-2 rounded-ds-md border border-dashed border-border bg-background px-4 py-6 text-center">
            <p class="text-sm font-semibold text-text-primary">Sem base no período selecionado</p>
            <p class="mt-1 text-xs text-text-secondary">Ainda não existem entrevistas respondidas suficientes para calcular a avaliação média da liderança.</p>
          </div>
        <?php else: ?>
          <?php /* Altura compacta (~300px no desktop): o gráfico ocupa a largura toda do card, e o SVG (proporção 720×280) escalaria sem limite. */ ?>
          <div class="mx-auto w-full max-w-[780px]">
            <?= dashboard_multi_line_chart($rotulosMes, [['label' => 'Liderança', 'color' => '#3B4822', 'values' => $col('lideranca')]], '', 1, 'Avaliação média da liderança por mês', ['min' => 1, 'max' => 5] + $opcoesGrafico) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?= dashboard_data_table('Evolução mensal', ['Mês', 'Desligamentos', 'Geradas', 'Respondidas', 'Taxa de resposta', 'Satisfação (0–10)', 'eNPS', 'Liderança (1–5)'],
        array_map(static fn(array $m): array => [$m['rotulo'], (string)$m['desligamentos'], (string)$m['geradas'], (string)$m['respondidas'], $pct($m['taxa_resposta']), $num1($m['satisfacao']), $enpsFmt($m['enps']), $num1($m['lideranca'])], $meses)) ?>
  </section>

  <!-- Faixa 3 — como foi a experiência? -->
  <section class="grid grid-cols-1 gap-4 2xl:grid-cols-3">
    <?php foreach ($painel['blocos'] as $bloco): ?>
      <article class="<?= $cardClasses ?>">
        <h2 class="text-sm font-bold text-text-primary"><?= Security::e($bloco['titulo']) ?></h2>
        <p class="mt-1 text-xl font-bold text-text-primary"><?= $bloco['media'] === null ? 'Sem base' : Security::e($num1($bloco['media'])) . ' <span class="text-sm font-medium text-text-secondary">/ 5 · ' . Security::e($respostas($bloco['n'])) . '</span>' ?></p>
        <div class="mt-3 space-y-3">
          <?php foreach ($bloco['dimensoes'] as $d): ?>
            <?= dashboard_bar_row($d['rotulo'], (float)($d['media'] ?? 0), 5.0, $d['media'] === null ? 'Sem base' : $num1($d['media']) . ' / 5', $corBarra) ?>
          <?php endforeach; ?>
        </div>
        <?= dashboard_data_table($bloco['titulo'], ['Dimensão', 'Média (1–5)', 'Respostas'], array_map(static fn(array $d): array => [$d['rotulo'], $num1($d['media']), (string)$d['n']], $bloco['dimensoes'])) ?>
      </article>
    <?php endforeach; ?>
  </section>

  <!-- Faixa 4 — onde estão os padrões? -->
  <?php
    $tabelaResumo = static function (string $titulo, string $rotuloNome, array $linhas, string $rodape = '') use ($cardClasses, $pct, $num1, $enpsFmt): string {
        $html = '<article class="' . $cardClasses . '"><h2 class="text-sm font-bold text-text-primary">' . Security::e($titulo) . '</h2>'
            . '<div class="mt-2 overflow-x-auto"><table class="min-w-full text-xs"><caption class="sr-only">' . Security::e($titulo) . '</caption>'
            . '<thead><tr class="border-b text-left text-text-secondary">';
        foreach ([$rotuloNome, 'Desligamentos', 'Geradas', 'Respondidas', 'Taxa de resposta', 'Satisfação (0–10)', 'eNPS'] as $h) {
            $html .= '<th scope="col" class="px-2 py-1.5 font-semibold">' . Security::e($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($linhas as $l) {
            $html .= '<tr class="border-b"><th scope="row" class="px-2 py-1.5 text-left font-medium text-text-primary">' . Security::e($l['nome']) . '</th>'
                . '<td class="px-2 py-1.5">' . (int)$l['desligamentos'] . '</td><td class="px-2 py-1.5">' . (int)$l['geradas'] . '</td><td class="px-2 py-1.5">' . (int)$l['respondidas'] . '</td>'
                . '<td class="px-2 py-1.5">' . Security::e($pct($l['taxa_resposta'])) . '</td><td class="px-2 py-1.5">' . Security::e($num1($l['satisfacao'])) . '</td>'
                . '<td class="px-2 py-1.5">' . Security::e($enpsFmt($l['enps'])) . '</td></tr>';
        }
        if ($linhas === []) {
            $html .= '<tr><td colspan="7" class="px-2 py-3 text-center text-text-secondary">Sem dados no período.</td></tr>';
        }
        return $html . '</tbody></table></div>' . ($rodape !== '' ? '<p class="mt-1 text-[11px] text-text-secondary">' . Security::e($rodape) . '</p>' : '') . '</article>';
    };
    $rodapeCargo = $painel['cargos']['total_grupos'] > count($painel['cargos']['itens'])
        ? 'Mostrando os ' . count($painel['cargos']['itens']) . ' cargos com mais desligamentos, de ' . $painel['cargos']['total_grupos'] . '.' : '';
  ?>
  <section class="grid grid-cols-1 gap-4">
    <?= $tabelaResumo('Resumo por Unidade', 'Unidade', $painel['unidades'], 'Satisfação e eNPS calculados sobre as entrevistas respondidas de cada unidade.') ?>
    <?= $tabelaResumo('Resumo por Cargo', 'Cargo', $painel['cargos']['itens'], $rodapeCargo) ?>
  </section>

  <?php endif; ?>
</div>
