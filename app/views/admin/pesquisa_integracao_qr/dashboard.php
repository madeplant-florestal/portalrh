<?php
/**
 * Dashboard de Integração/Onboarding (Etapa 8, 2026-10) — painel gerencial AGREGADO sobre as
 * respostas reais de `pesquisas_integracao` (fluxo QR + fluxo individual). Todo cálculo vem de
 * DashboardIntegracaoService — esta view só apresenta. Nunca CPF/nascimento; Nome/Cargo/Empresa só
 * aparecem junto aos comentários (mesmo padrão de resultados.php).
 */
require_once __DIR__ . '/../partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$inputClasses = 'rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100';
$cardClasses = 'min-w-0 rounded-ds-lg border border-border bg-surface p-4 shadow-resting';
$cardKpi = static function (string $rotulo, string $valor, string $sub = '') use ($cardClasses): string {
    return '<div class="' . $cardClasses . '"><p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">' . Security::e($rotulo) . '</p>'
        . '<p class="mt-1 text-2xl font-bold leading-tight text-text-primary">' . Security::e($valor) . '</p>'
        . ($sub !== '' ? '<p class="mt-0.5 text-[11px] text-text-secondary">' . Security::e($sub) . '</p>' : '') . '</div>';
};
$respostasTxt = static fn(int $n): string => $n . ($n === 1 ? ' resposta' : ' respostas');
$npsFmt = static fn(?float $v): string => $v === null ? 'Sem dados' : (($v > 0 ? '+' : '') . number_format($v, 1, ',', '.'));
$anoSelecionado = Security::sanitizeString($_GET['ano'] ?? '');
$mesSelecionado = Security::sanitizeString($_GET['mes'] ?? '');
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'integracao', 'dashboard', [
      'titulo' => 'Dashboard de Integração',
      'descricao' => 'Acompanhamento da experiência inicial do colaborador — NPS e satisfação com a integração/onboarding, agregados.',
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php foreach ($filtros['avisos'] ?? [] as $aviso): ?>
    <div class="rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning"><?= Security::e($aviso) ?></div>
  <?php endforeach; ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <form method="get" class="flex flex-wrap items-end gap-2">
      <div>
        <label for="filtro-ano" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Ano</label>
        <select id="filtro-ano" name="ano" data-autosubmit="1" class="<?= $inputClasses ?>">
          <option value="">Todo o período</option>
          <?php for ($anoOpcao = (int)$hoje->format('Y'); $anoOpcao >= (int)$hoje->format('Y') - 5; $anoOpcao--): ?>
            <option value="<?= $anoOpcao ?>" <?= $anoSelecionado === (string)$anoOpcao ? 'selected' : '' ?>><?= $anoOpcao ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="filtro-mes" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Mês</label>
        <select id="filtro-mes" name="mes" data-autosubmit="1" class="<?= $inputClasses ?>">
          <option value="">Todo o período</option>
          <?php foreach (['1' => 'Janeiro', '2' => 'Fevereiro', '3' => 'Março', '4' => 'Abril', '5' => 'Maio', '6' => 'Junho', '7' => 'Julho', '8' => 'Agosto', '9' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'] as $mesValor => $mesLabel): ?>
            <option value="<?= $mesValor ?>" <?= $mesSelecionado === $mesValor ? 'selected' : '' ?>><?= $mesLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="w-full text-[11px] text-text-muted">Ano + Mês = só aquele mês · Ano + "Todo o período" (no Mês) = o ano inteiro · os dois em "Todo o período" = todo o histórico</p>
      <div>
        <label for="filtro-empresa" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Empresa</label>
        <select id="filtro-empresa" name="empresa" class="<?= $inputClasses ?>">
          <option value="">Todas as empresas</option>
          <?php foreach ($opcoes['empresas'] as $e): ?>
            <option value="<?= Security::e($e['codigo']) ?>" <?= ($filtros['codigo_empresa'] ?? '') === $e['codigo'] ? 'selected' : '' ?>><?= Security::e($e['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-unidade" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Unidade</label>
        <select id="filtro-unidade" name="unidade" class="<?= $inputClasses ?>">
          <option value="">Todas as unidades</option>
          <?php foreach ($opcoes['unidades'] as $u): ?>
            <option value="<?= Security::e($u['chave']) ?>" <?= ($filtros['codigo_unidade'] ?? '') === $u['codigo_unidade'] ? 'selected' : '' ?>><?= Security::e($u['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-setor" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Setor</label>
        <select id="filtro-setor" name="setor" class="<?= $inputClasses ?>">
          <option value="">Todos os setores</option>
          <?php foreach ($opcoes['setores'] as $s): ?>
            <option value="<?= Security::e($s['codigo']) ?>" <?= ($filtros['codigo_setor'] ?? '') === $s['codigo'] ? 'selected' : '' ?>><?= Security::e($s['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="<?= ui_btn('primario') ?>">Aplicar</button>
      <a href="<?= $base ?>/admin/pesquisas-reacao-integracao/integracao/dashboard" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </form>
    <div class="mt-2 flex flex-wrap gap-1.5" aria-label="Atalhos de período">
      <?php foreach ($atalhos as $a): ?>
        <a href="?<?= http_build_query(['ano' => $a['ano'], 'mes' => $a['mes']] + array_filter(['empresa' => $filtros['codigo_empresa'] ?? '', 'unidade' => $_GET['unidade'] ?? '', 'setor' => $filtros['codigo_setor'] ?? ''])) ?>" class="rounded-full border border-border px-3 py-1 text-xs font-medium text-text-secondary hover:border-primary-700 hover:text-primary-700"><?= Security::e($a['rotulo']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($painel !== null): ?>
    <?php if ($painel['total_respostas'] === 0): ?>
      <div class="rounded-ds-lg border border-border bg-surface p-6 text-center">
        <p class="text-sm text-text-secondary">Nenhuma resposta de Integração no período/filtros selecionados.</p>
      </div>
    <?php else: ?>
      <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?= $cardKpi('Respostas recebidas', (string)$painel['total_respostas'], 'QR Code + fluxo individual') ?>
        <?= $cardKpi('NPS', $npsFmt($painel['nps']['nps']), $respostasTxt($painel['nps']['total']) . ' com nota de recomendação') ?>
        <?= $cardKpi('Satisfação geral (1–5)', $painel['satisfacao_geral']['media'] === null ? 'Sem dados' : number_format($painel['satisfacao_geral']['media'], 1, ',', '.'), $respostasTxt($painel['satisfacao_geral']['n'])) ?>
        <?= $cardKpi('Taxa de resposta — convite individual', $painel['fluxo_individual']['taxa_resposta'] === null ? 'Sem base' : number_format($painel['fluxo_individual']['taxa_resposta'], 1, ',', '.') . '%', $painel['fluxo_individual']['respondidas'] . ' de ' . $painel['fluxo_individual']['geradas'] . ' pesquisas geradas') ?>
      </section>
      <p class="text-[11px] text-text-muted">A Taxa de Resposta só existe para o fluxo individual (pesquisa gerada previamente pelo RH, com token próprio) — no fluxo coletivo por QR Code não há lista prévia de convidados, então não existe uma pesquisa "pendente" a comparar: toda resposta recebida já é 100% do que existe registrado.</p>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="<?= $cardClasses ?> lg:col-span-1">
          <h3 class="text-sm font-bold text-text-primary">Promotores · Neutros · Detratores</h3>
          <div class="mt-3 flex items-center justify-center">
            <?= dashboard_donut([
                ['label' => 'Promotores', 'value' => $painel['nps']['promotores'], 'color' => '#16a34a'],
                ['label' => 'Neutros', 'value' => $painel['nps']['neutros'], 'color' => '#d97706'],
                ['label' => 'Detratores', 'value' => $painel['nps']['detratores'], 'color' => '#dc2626'],
            ]) ?>
          </div>
          <?= dashboard_chart_legend([
              ['label' => 'Promotores (9–10) — ' . $painel['nps']['promotores'], 'color' => '#16a34a'],
              ['label' => 'Neutros (7–8) — ' . $painel['nps']['neutros'], 'color' => '#d97706'],
              ['label' => 'Detratores (0–6) — ' . $painel['nps']['detratores'], 'color' => '#dc2626'],
          ]) ?>
        </section>

        <section class="<?= $cardClasses ?> lg:col-span-2">
          <h3 class="text-sm font-bold text-text-primary">NPS por mês</h3>
          <p class="text-[11px] text-text-secondary">Agrupado pela data da integração (evento), não pela data da resposta.</p>
          <?php if (count($painel['mensal']['labels']) < 2): ?>
            <p class="mt-3 text-sm text-text-secondary">Dados insuficientes para evolução mensal (só <?= count($painel['mensal']['labels']) ?> mês no período).</p>
          <?php else: ?>
            <div class="mt-2"><?= dashboard_multi_line_chart(
                $painel['mensal']['labels'],
                [['label' => 'NPS', 'color' => '#2563eb', 'values' => $painel['mensal']['nps']]],
                '', 1, 'NPS por mês', ['min' => -100, 'max' => 100]
            ) ?></div>
          <?php endif; ?>
          <?= dashboard_data_table('NPS e volume por mês', ['Mês', 'NPS', 'Respostas'], array_map(
              static fn($label, $nps, $vol): array => [$label, $nps === null ? '—' : number_format($nps, 1, ',', '.'), (int)$vol],
              $painel['mensal']['labels'], $painel['mensal']['nps'], $painel['mensal']['volume']
          )) ?>
        </section>
      </div>

      <section class="<?= $cardClasses ?>">
        <h3 class="text-sm font-bold text-text-primary">Perguntas de satisfação</h3>
        <p class="text-[11px] text-text-secondary">Escala de 1 (muito insatisfeito) a 5 (muito satisfeito) — terminologia original do instrumento.</p>
        <div class="mt-3 space-y-3">
          <?php foreach ($painel['perguntas'] as $p): ?>
            <?= dashboard_bar_row(
                $p['rotulo'],
                (float)($p['media'] ?? 0),
                5.0,
                $p['media'] === null ? 'Sem dados' : number_format($p['media'], 1, ',', '.') . ' · ' . $respostasTxt($p['n'])
            ) ?>
          <?php endforeach; ?>
        </div>
        <?= dashboard_data_table(
            'Distribuição por pergunta',
            ['Pergunta', 'Média', 'Nota 1', 'Nota 2', 'Nota 3', 'Nota 4', 'Nota 5'],
            array_map(static fn(array $p): array => [
                $p['rotulo'], $p['media'] === null ? '—' : number_format($p['media'], 1, ',', '.'),
                $p['distribuicao'][1], $p['distribuicao'][2], $p['distribuicao'][3], $p['distribuicao'][4], $p['distribuicao'][5],
            ], $painel['perguntas'])
        ) ?>
      </section>

      <section class="<?= $cardClasses ?>">
        <h3 class="text-sm font-bold text-text-primary">Comentários recentes</h3>
        <?php if ($painel['comentarios'] === []): ?>
          <p class="mt-2 text-sm text-text-secondary">Nenhum comentário preenchido no período/filtros selecionados.</p>
        <?php else: ?>
          <div class="mt-3 space-y-3">
            <?php foreach ($painel['comentarios'] as $c): ?>
              <div class="rounded-ds-md bg-background p-3">
                <p class="text-xs font-semibold text-text-secondary">
                  <?= Security::e(implode(' · ', array_filter([(string)($c['nome'] ?? ''), (string)($c['cargo'] ?? ''), (string)($c['empresa'] ?? '')], static fn(string $v): bool => $v !== '')) ?: 'Colaborador não identificado') ?>
                  · <?= Security::e(date('d/m/Y H:i', strtotime($c['respondida_em']))) ?>
                </p>
                <p class="mt-1 text-sm text-text-primary"><?= nl2br(Security::e($c['comentario'])) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
