<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'solicitacoes', [
      'titulo' => 'Kanban de Solicitações de Vaga',
      'descricao' => 'Acompanha a evolução operacional da vaga solicitada pelo gestor — independente do fluxo de aprovação líder/RH.',
  ], [['label' => 'Kanban', 'href' => null]]) ?>
  <form method="GET" action="<?= $base ?>/admin/solicitacoes-vaga/kanban" class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-border bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
    <div>
      <label class="mb-1 block text-xs font-medium text-text-secondary">Gestor solicitante</label>
      <select name="gestor_colaborador_id" data-autosubmit="1" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
        <option value="">Todos</option>
        <?php foreach ($gestores as $g): ?>
          <option value="<?= (int)$g['colaborador_id'] ?>" <?= (int)($filters['gestor_colaborador_id'] ?? 0) === (int)$g['colaborador_id'] ? 'selected' : '' ?>><?= Security::e($g['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-text-secondary">Área / Departamento</label>
      <select name="setor_id" data-autosubmit="1" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($setores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)($filters['setor_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= Security::e($s['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-text-secondary">Cargo</label>
      <select name="cargo_id" data-autosubmit="1" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
        <option value="">Todos</option>
        <?php foreach ($cargos as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($filters['cargo_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= Security::e($c['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-text-secondary">Período (de / até)</label>
      <div class="flex gap-2">
        <input type="text" name="data_de" placeholder="DD/MM/AAAA" data-mask-date="1" value="<?= Security::e(!empty($filters['data_de']) ? DateHelper::formatBrazilianDate($filters['data_de']) : '') ?>" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
        <input type="text" name="data_ate" placeholder="DD/MM/AAAA" data-mask-date="1" value="<?= Security::e(!empty($filters['data_ate']) ? DateHelper::formatBrazilianDate($filters['data_ate']) : '') ?>" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
      </div>
    </div>
    <div class="flex items-end gap-2">
      <button type="submit" class="<?= ui_btn('primario') ?> w-full">Filtrar</button>
      <a href="<?= $base ?>/admin/solicitacoes-vaga/kanban" class="<?= ui_btn('secundario') ?> w-full">Limpar</a>
    </div>
  </form>

  <div class="mb-4 flex flex-wrap items-center gap-3 text-xs text-text-secondary">
    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 shadow-sm ring-1 ring-border"><?= (int)$stageCount ?> etapas monitoradas</span>
    <?php if (!empty($podeMovimentar)): ?>
    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 shadow-sm ring-1 ring-border">Arraste e solte os cards para atualizar a situação operacional da vaga</span>
    <?php endif; ?>
  </div>

  <div class="sv-kanban-board min-h-[26rem] overflow-x-auto md:min-h-[36rem]">
    <?php foreach ($kanban as $stageId => $col): ?>
        <div class="sv-kanban-column-shell flex h-full flex-col rounded-xl border-t-4 bg-surface-secondary shadow-sm" data-sv-kanban-board-column="1" style="border-color: <?= Security::e($col['stage']['cor'] ?? '#cccccc') ?>">
            <div class="sticky top-0 z-10 flex items-center justify-between rounded-t-xl border-b bg-white p-3">
                <h3 class="font-semibold text-text-primary"><?= Security::e($col['stage']['nome']) ?></h3>
                <span class="bg-border text-text-secondary text-xs px-2 py-1 rounded-full" data-sv-kanban-count="1"><?= count($col['items']) ?></span>
            </div>

            <div class="sv-kanban-column flex-1 space-y-3 overflow-y-auto p-2" data-sv-kanban-column="1" data-stage-id="<?= (int)$stageId ?>" data-stage-slug="<?= Security::e($col['stage']['slug'] ?? '') ?>">
                <?php if (empty($col['items'])): ?>
                    <div class="rounded-lg border border-dashed border-border bg-white/70 px-4 py-6 text-center text-xs text-text-muted">Nenhuma solicitação nesta etapa.</div>
                <?php endif; ?>
                <?php foreach ($col['items'] as $item): ?>
                    <div class="group relative rounded-lg border border-border bg-white p-3 shadow-sm transition-shadow hover:shadow-md <?= !empty($podeMovimentar) ? 'cursor-move' : '' ?>" data-sv-kanban-card="1" <?= !empty($podeMovimentar) ? 'draggable="true"' : '' ?> data-sol-id="<?= (int)$item['id'] ?>">
                        <h4 class="w-full truncate text-sm font-medium text-text-primary" title="<?= Security::e($item['cargo_nome']) ?>"><?= Security::e($item['cargo_nome']) ?></h4>
                        <p class="mt-1 text-xs text-text-secondary">Área: <?= Security::e($item['setor_nome']) ?></p>
                        <p class="text-xs text-text-secondary">Gestor: <?= Security::e($item['gestor_nome']) ?></p>
                        <p class="text-xs text-text-secondary">Qtd. vagas: <?= (int)$item['quantidade_vagas'] ?></p>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <?php if (!empty($podeAbrirDetalhes)): ?>
                            <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$item['id'] ?>" class="text-xs font-medium text-primary-700 hover:text-primary-800 hover:underline">Ver detalhes</a>
                            <?php else: ?>
                            <span></span>
                            <?php endif; ?>
                            <span class="text-xs text-text-muted"><?= Security::e(date('d/m', strtotime((string)$item['created_at']))) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
  </div>

  <div class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4" data-sv-stage-modal="1">
    <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
      <h3 class="text-lg font-semibold text-text-primary">Confirmar cancelamento</h3>
      <p class="mt-1 text-sm text-text-secondary">Mover <strong data-sv-stage-modal-cargo="1"></strong> para <strong>Cancelada</strong>.</p>

      <div class="mt-4 hidden" data-sv-stage-modal-error="1">
        <div class="rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger" data-sv-stage-modal-error-text="1"></div>
      </div>

      <div class="mt-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-text-primary">Motivo do cancelamento *</label>
          <textarea data-sv-stage-modal-field="motivo_cancelamento" rows="3" placeholder="Obrigatório" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Observações complementares (opcional)</label>
          <textarea data-sv-stage-modal-field="observacao" rows="2" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
        </div>
      </div>

      <div class="mt-6 flex justify-end gap-3">
        <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-primary hover:bg-surface-secondary" data-sv-stage-modal-cancel="1">Cancelar</button>
        <button type="button" class="rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white hover:bg-danger/90" data-sv-stage-modal-confirm="1">Confirmar cancelamento</button>
      </div>
    </div>
  </div>
</div>
