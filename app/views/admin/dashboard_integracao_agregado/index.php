<?php
/**
 * Dashboard AGREGADO de Integração (Etapa 2C.2C). Toda a privacidade (mínimo de 5, supressão complementar, sem comentários/individuais) é
 * decidida em DashboardIntegracaoAgregadoService — esta view só apresenta o que o serviço devolveu (estrutura fechada).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';

$inputClasses = 'rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100';
$cardClasses = 'min-w-0 rounded-ds-lg border border-border bg-surface p-4 shadow-resting';
$kpi = static function (string $rotulo, string $valor, string $sub = '') use ($cardClasses): string {
    return '<div class="' . $cardClasses . '"><p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">' . Security::e($rotulo) . '</p>'
        . '<p class="mt-1 text-2xl font-bold leading-tight text-text-primary">' . Security::e($valor) . '</p>'
        . ($sub !== '' ? '<p class="mt-0.5 text-[11px] text-text-secondary">' . Security::e($sub) . '</p>' : '') . '</div>';
};
$npsFmt = static fn(?float $v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format($v, 1, ',', '.'));
$numFmt = static fn(?float $v): string => $v === null ? '—' : number_format($v, 1, ',', '.');
$f = $painel['filtros'] ?? ['ano' => '', 'mes' => '', 'empresa' => '', 'unidade' => '', 'setor' => '', 'avisos' => []];
$opcoes = $painel['opcoes'] ?? ['empresas' => [], 'unidades' => [], 'setores' => []];
$minimo = (int)($painel['minimo'] ?? 5);
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'integracao', 'agregado', [
      'titulo' => 'Dashboard de Integração — indicadores agregados',
      'descricao' => 'Indicadores dos setores que você gerencia. Só são exibidos grupos com pelo menos ' . $minimo . ' respondentes distintos; nunca há comentários nem respostas individuais.',
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <form method="get" class="flex flex-wrap items-end gap-2">
      <div>
        <label for="filtro-ano" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Ano</label>
        <select id="filtro-ano" name="ano" class="<?= $inputClasses ?>">
          <option value="">Todo o período</option>
          <?php for ($a = (int)$hoje->format('Y'); $a >= (int)$hoje->format('Y') - 5; $a--): ?>
            <option value="<?= $a ?>" <?= $f['ano'] === (string)$a ? 'selected' : '' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="filtro-mes" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Mês</label>
        <select id="filtro-mes" name="mes" class="<?= $inputClasses ?>">
          <option value="">Todos</option>
          <?php foreach (['1' => 'Janeiro', '2' => 'Fevereiro', '3' => 'Março', '4' => 'Abril', '5' => 'Maio', '6' => 'Junho', '7' => 'Julho', '8' => 'Agosto', '9' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $f['mes'] === (string)$v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-empresa" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Empresa</label>
        <select id="filtro-empresa" name="empresa" class="<?= $inputClasses ?>">
          <option value="">Todas</option>
          <?php foreach ($opcoes['empresas'] as $e): ?>
            <option value="<?= Security::e($e['codigo']) ?>" <?= $f['empresa'] === $e['codigo'] ? 'selected' : '' ?>><?= Security::e($e['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-unidade" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Unidade</label>
        <select id="filtro-unidade" name="unidade" class="<?= $inputClasses ?>">
          <option value="">Todas</option>
          <?php foreach ($opcoes['unidades'] as $u): ?>
            <option value="<?= Security::e($u['chave']) ?>" <?= $f['unidade'] === $u['codigo_unidade'] ? 'selected' : '' ?>><?= Security::e($u['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filtro-setor" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Setor</label>
        <select id="filtro-setor" name="setor" class="<?= $inputClasses ?>">
          <option value="">Todos os meus setores</option>
          <?php foreach ($opcoes['setores'] as $s): ?>
            <option value="<?= Security::e($s['codigo']) ?>" <?= $f['setor'] === $s['codigo'] ? 'selected' : '' ?>><?= Security::e($s['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="<?= ui_btn('primario') ?>">Aplicar</button>
      <a href="<?= $base ?>/admin/dashboard-integracao-agregado" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </form>
  </section>

  <?php if ($painel !== null && empty($painel['suficiente'])): ?>
    <div class="rounded-ds-lg border border-border bg-surface p-6 text-center" role="status">
      <?php if (($painel['motivo'] ?? '') === 'sem_escopo'): ?>
        <p class="text-sm text-text-secondary">Você ainda não gerencia nenhum setor — não há indicadores para exibir.</p>
      <?php else: ?>
        <p class="text-sm font-semibold text-text-primary">Dados insuficientes</p>
        <p class="mt-1 text-sm text-text-secondary">Para preservar a privacidade, os indicadores só são exibidos com pelo menos <?= $minimo ?> respondentes distintos e quando a combinação de filtros puder ser apresentada com segurança. Tente um período ou agrupamento mais amplo.</p>
      <?php endif; ?>
    </div>
  <?php elseif ($painel !== null): ?>
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <?= $kpi('Respondentes', (string)$painel['respondentes'], 'pessoas distintas') ?>
      <?= $kpi('NPS', $npsFmt($painel['nps']['nps']), $painel['nps']['promotores'] . ' promotores · ' . $painel['nps']['neutros'] . ' neutros · ' . $painel['nps']['detratores'] . ' detratores') ?>
      <?= $kpi('Satisfação geral (1–5)', $numFmt($painel['satisfacao_geral'])) ?>
      <?= $kpi('Integrações realizadas', $painel['integracoes_realizadas'] === null ? 'Omitido' : (string)$painel['integracoes_realizadas'], $painel['integracoes_realizadas'] === null ? 'menos de ' . $minimo . ' no filtro' : '') ?>
    </section>

    <section class="<?= $cardClasses ?>">
      <h3 class="text-sm font-bold text-text-primary">Perguntas da pesquisa</h3>
      <div class="mt-2 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-[11px] uppercase text-text-secondary"><th class="py-1 pr-4">Pergunta</th><th class="py-1 pr-4">Média</th><th class="py-1">Notas 1 · 2 · 3 · 4 · 5</th></tr></thead><tbody>
        <?php foreach ($painel['perguntas'] as $p): ?>
          <tr class="border-t border-border"><td class="py-1.5 pr-4"><?= Security::e($p['rotulo']) ?></td><td class="py-1.5 pr-4"><?= $numFmt($p['media']) ?></td><td class="py-1.5"><?= Security::e(implode(' · ', $p['distribuicao'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <section class="<?= $cardClasses ?>">
        <h3 class="text-sm font-bold text-text-primary">Por setor</h3>
        <?php if ($painel['por_setor']['grupos'] === []): ?>
          <p class="mt-2 text-sm text-text-secondary">Nenhum setor com <?= $minimo ?> ou mais respondentes para exibir separadamente.</p>
        <?php else: ?>
          <div class="mt-2 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-[11px] uppercase text-text-secondary"><th class="py-1 pr-4">Setor</th><th class="py-1 pr-4">Respondentes</th><th class="py-1 pr-4">NPS</th><th class="py-1">Satisfação</th></tr></thead><tbody>
            <?php foreach ($painel['por_setor']['grupos'] as $g): ?>
              <tr class="border-t border-border"><td class="py-1.5 pr-4"><?= Security::e($g['rotulo']) ?></td><td class="py-1.5 pr-4"><?= (int)$g['respondentes'] ?></td><td class="py-1.5 pr-4"><?= $npsFmt($g['nps']) ?></td><td class="py-1.5"><?= $numFmt($g['satisfacao']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
        <?php if ($painel['por_setor']['suprimidos'] > 0): ?><p class="mt-2 text-[11px] text-text-muted">Há agrupamentos omitidos por terem poucos respondentes (proteção de privacidade).</p><?php endif; ?>
      </section>

      <section class="<?= $cardClasses ?>">
        <h3 class="text-sm font-bold text-text-primary">Por mês da integração</h3>
        <div class="mt-2 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-[11px] uppercase text-text-secondary"><th class="py-1 pr-4">Mês</th><th class="py-1 pr-4">Respondentes</th><th class="py-1 pr-4">NPS</th><th class="py-1">Satisfação</th></tr></thead><tbody>
          <?php foreach ($painel['mensal'] as $m): ?>
            <?php if ($m['suprimido']): ?>
              <tr class="border-t border-border text-text-muted"><td class="py-1.5 pr-4"><?= Security::e($m['rotulo']) ?></td><td class="py-1.5" colspan="3">omitido (poucos respondentes)</td></tr>
            <?php else: ?>
              <tr class="border-t border-border"><td class="py-1.5 pr-4"><?= Security::e($m['rotulo']) ?></td><td class="py-1.5 pr-4"><?= (int)$m['respondentes'] ?></td><td class="py-1.5 pr-4"><?= $npsFmt($m['nps']) ?></td><td class="py-1.5"><?= $numFmt($m['satisfacao']) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
    </div>

    <?php if ($painel['fluxo_individual'] !== null): ?>
      <p class="text-sm text-text-secondary">Convites individuais: <?= (int)$painel['fluxo_individual']['geradas'] ?> gerados, <?= (int)$painel['fluxo_individual']['respondidas'] ?> respondidos (<?= number_format((float)$painel['fluxo_individual']['taxa_resposta'], 1, ',', '.') ?>%).</p>
    <?php endif; ?>
    <p class="text-[11px] text-text-muted">O mínimo de <?= $minimo ?> respondentes é uma proteção inicial, não uma garantia absoluta de anonimato. Não use estes números para identificar pessoas.</p>
  <?php endif; ?>
</div>
