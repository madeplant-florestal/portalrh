<?php
/**
 * Lista de Feedbacks (Etapa 4, 2026-09) — no escopo do usuário: Admin/RH veem todos; os demais só os
 * feedbacks em que são o gestor responsável (mesmo padrão de admin/pdis/index.php).
 */
require_once APP_PATH . '/views/partials/ui-shell.php';
$rotulo = 'block text-xs font-semibold uppercase tracking-wide text-text-secondary';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
$f = $filtros ?? [];
$tomStatus = ['rascunho' => 'info', 'concluido' => 'success', 'cancelado' => 'neutro'];
$rotuloStatus = ['rascunho' => 'Em preenchimento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'];
?>
<div class="space-y-5">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Avaliações e Desenvolvimento', 'href' => $base . '/admin/avaliacoes-desenvolvimento'], ['label' => 'Feedback']]) ?>
  <?= ui_page_header([
      'titulo' => 'Feedback e Desenvolvimento',
      'descricao' => 'Reconhecimento, desenvolvimento, alinhamento e acompanhamento entre gestor e colaborador.',
      'acao' => !empty($podeCriar) ? ['label' => 'Novo Feedback', 'href' => $base . '/admin/feedbacks/novo'] : null,
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/feedbacks" class="grid grid-cols-1 gap-3 rounded-ds-lg border border-border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div>
      <label for="f-status" class="<?= $rotulo ?>">Status</label>
      <select id="f-status" name="status" class="<?= $campo ?>">
        <option value="">Todos</option>
        <option value="rascunho" <?= ($f['status'] ?? '') === 'rascunho' ? 'selected' : '' ?>>Em preenchimento</option>
        <option value="concluido" <?= ($f['status'] ?? '') === 'concluido' ? 'selected' : '' ?>>Concluído</option>
      </select>
    </div>
    <div>
      <label for="f-tipo" class="<?= $rotulo ?>">Tipo</label>
      <select id="f-tipo" name="tipo" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach (FeedbackService::TIPOS as $k => $v): ?><option value="<?= $k ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-busca" class="<?= $rotulo ?>">Colaborador</label>
      <input id="f-busca" type="text" name="busca" maxlength="60" value="<?= Security::e((string)($f['busca'] ?? '')) ?>" class="<?= $campo ?>" placeholder="Nome">
    </div>
    <div class="flex items-end gap-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Filtrar</button>
      <a href="<?= $base ?>/admin/feedbacks" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </div>
  </form>

  <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Gestor</th>
          <th class="p-3">Tipo</th>
          <th class="p-3">Data</th>
          <th class="p-3">Status</th>
          <th class="p-3">Resultado</th>
          <th class="p-3">Espaço do colaborador</th>
          <th class="p-3">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (($itens ?? []) === []): ?>
          <tr><td colspan="8" class="p-6 text-center text-text-secondary">Nenhum feedback encontrado para os filtros selecionados.</td></tr>
        <?php else: ?>
          <?php foreach ($itens as $i): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$i['snap_nome']) ?></td>
              <td class="p-3"><?= Security::e((string)$i['gestor_nome_snapshot']) ?></td>
              <td class="p-3"><?= Security::e(FeedbackService::TIPOS[$i['tipo']] ?? (string)$i['tipo']) ?></td>
              <td class="p-3"><?= Security::e(date('d/m/Y', strtotime((string)$i['data_feedback']))) ?></td>
              <td class="p-3"><?= ui_badge($rotuloStatus[$i['status']] ?? (string)$i['status'], $tomStatus[$i['status']] ?? 'neutro') ?></td>
              <td class="p-3"><?= Security::e($i['resultado_geral'] !== null ? (FeedbackService::RESULTADO_OPCOES[$i['resultado_geral']] ?? (string)$i['resultado_geral']) : '—') ?></td>
              <td class="p-3"><?= !empty($i['espaco_colaborador_preenchido_em']) ? ui_badge('Preenchido', 'success') : ui_badge('Pendente', 'neutro') ?></td>
              <td class="p-3">
                <a href="<?= $base ?>/admin/feedbacks/<?= (int)$i['id'] ?>/editar" class="<?= ui_btn('secundario') ?> h-8 px-3 text-xs"><?= $i['status'] === 'concluido' ? 'Visualizar' : 'Continuar' ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
