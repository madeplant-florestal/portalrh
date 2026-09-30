<?php
/**
 * Lista de Avaliações de Desempenho (Etapa 6, 2026-09) — no escopo do usuário: Admin/RH veem todas;
 * os demais só as avaliações em que são o gestor responsável (mesmo padrão de feedbacks/index.php).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$rotulo = 'block text-xs font-semibold uppercase tracking-wide text-text-secondary';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
$f = $filtros ?? [];
$tomStatus = ['rascunho' => 'info', 'concluido' => 'success', 'cancelado' => 'neutro'];
?>
<div class="space-y-5">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'avaliacao-desempenho', [
      'titulo' => 'Avaliação de Desempenho',
      'descricao' => 'Avaliação periódica de desempenho por ciclo, com critérios, GAP e resultado final.',
      'acao' => !empty($podeCriar) ? ['label' => 'Nova Avaliação', 'href' => $base . '/admin/avaliacoes-desempenho/novo'] : null,
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/avaliacoes-desempenho" class="grid grid-cols-1 gap-3 rounded-ds-lg border border-border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div>
      <label for="f-status" class="<?= $rotulo ?>">Status</label>
      <select id="f-status" name="status" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach (AvaliacaoDesempenhoService::ROTULOS_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-busca" class="<?= $rotulo ?>">Colaborador</label>
      <input id="f-busca" type="text" name="busca" maxlength="60" value="<?= Security::e((string)($f['busca'] ?? '')) ?>" class="<?= $campo ?>" placeholder="Nome">
    </div>
    <div class="flex items-end gap-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Filtrar</button>
      <a href="<?= $base ?>/admin/avaliacoes-desempenho" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </div>
  </form>

  <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Gestor</th>
          <th class="p-3">Ciclo</th>
          <th class="p-3">Período</th>
          <th class="p-3">Status</th>
          <th class="p-3">Média atual</th>
          <th class="p-3">Critérios c/ GAP</th>
          <th class="p-3">Resultado</th>
          <th class="p-3">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (($itens ?? []) === []): ?>
          <tr><td colspan="9" class="p-6 text-center text-text-secondary">Nenhuma avaliação encontrada para os filtros selecionados.</td></tr>
        <?php else: ?>
          <?php foreach ($itens as $i): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$i['snap_nome']) ?></td>
              <td class="p-3"><?= Security::e((string)$i['gestor_nome_snapshot']) ?></td>
              <td class="p-3"><?= Security::e((string)$i['ciclo']) ?></td>
              <td class="p-3"><?= Security::e(date('d/m/Y', strtotime((string)$i['periodo_inicio']))) ?> – <?= Security::e(date('d/m/Y', strtotime((string)$i['periodo_fim']))) ?></td>
              <td class="p-3"><?= ui_badge(AvaliacaoDesempenhoService::ROTULOS_STATUS[$i['status']] ?? (string)$i['status'], $tomStatus[$i['status']] ?? 'neutro') ?></td>
              <td class="p-3"><?= $i['media_nota_atual'] !== null ? Security::e(number_format((float)$i['media_nota_atual'], 1, ',', '.')) : '—' ?></td>
              <td class="p-3"><?= (int)($i['qtd_criterios_gap'] ?? 0) ?></td>
              <td class="p-3"><?= Security::e($i['resultado_final'] !== null ? (AvaliacaoDesempenhoService::RESULTADO_OPCOES[$i['resultado_final']] ?? (string)$i['resultado_final']) : '—') ?></td>
              <td class="p-3">
                <a href="<?= $base ?>/admin/avaliacoes-desempenho/<?= (int)$i['id'] ?>/editar" class="<?= ui_btn('secundario') ?> h-8 px-3 text-xs"><?= $i['status'] !== 'rascunho' ? 'Visualizar' : 'Continuar' ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
