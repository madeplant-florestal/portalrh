<?php
$historyItems = $history['items'] ?? [];
$page = (int)($history['page'] ?? 1);
$pages = (int)($history['pages'] ?? 1);
$queryBase = $base . '/admin/recruitment-webhooks';
$prevParams = ['page' => max(1, $page - 1)];
$nextParams = ['page' => min($pages, $page + 1)];
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 sm:text-3xl">Webhooks do Recrutamento</h1>
      <p class="mt-1 text-sm text-gray-500">Configuração por tenant, histórico da fila e reenvio de eventos do Kanban.</p>
    </div>
    <form action="<?= $base ?>/admin/recruitment-webhooks/process-pending" method="post">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
      <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">
        Processar fila pendente
      </button>
    </form>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-slate-800">Configurações por tenant</h2>
      <p class="text-sm text-slate-500">O escopo padrão é usado quando a vaga ainda não estiver vinculada a uma empresa específica.</p>
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
      <?php foreach (($settings ?? []) as $setting): ?>
        <form action="<?= $base ?>/admin/recruitment-webhooks/settings/save" method="post" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
          <input type="hidden" name="scope_key" value="<?= Security::e($setting['scope_key'] ?? '') ?>">
          <input type="hidden" name="empresa_id" value="<?= (int)($setting['empresa_id'] ?? 0) ?>">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold text-slate-800"><?= Security::e($setting['scope_label'] ?? '') ?></h3>
              <p class="text-xs text-slate-500">
                <?= !empty($setting['is_default']) ? 'Fallback para vagas sem empresa definida.' : 'Configuração dedicada para a empresa vinculada à vaga.' ?>
              </p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= (int)($setting['enabled'] ?? 0) === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
              <?= (int)($setting['enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
            </span>
          </div>
          <div class="mt-4 space-y-4">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" name="enabled" value="1" <?= (int)($setting['enabled'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-slate-300 text-ctgreen focus:ring-ctgreen">
              Habilitar envio de webhooks
            </label>
            <div>
              <label class="block text-sm font-medium text-slate-700">URL do webhook</label>
              <input type="url" name="webhook_url" value="<?= Security::e($setting['webhook_url'] ?? '') ?>" placeholder="https://appmadeplant.com/api/webhooks/recrutamento" class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
            </div>
            <div class="flex justify-end">
              <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Salvar configuração</button>
            </div>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-slate-800">Histórico de envios</h2>
      <p class="text-sm text-slate-500">Fila preparada para integrações futuras com n8n, Slack, Teams, Evolution API e outros consumidores HTTP.</p>
    </div>
    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead>
          <tr class="border-b">
            <th class="p-3 text-left">Evento</th>
            <th class="p-3 text-left">Tenant</th>
            <th class="p-3 text-left">Candidato</th>
            <th class="p-3 text-left">Etapas</th>
            <th class="p-3 text-left">Status</th>
            <th class="p-3 text-left">Tentativas</th>
            <th class="p-3 text-left">Processado</th>
            <th class="p-3 text-left">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historyItems as $item): ?>
            <?php $payload = $item['payload'] ?? []; ?>
            <tr class="border-b align-top hover:bg-slate-50">
              <td class="p-3">
                <div class="font-semibold text-slate-800"><?= Security::e($item['event_type'] ?? '') ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= Security::e((string)($item['webhook_url'] ?? 'Sem URL')) ?></div>
              </td>
              <td class="p-3"><?= (int)($item['tenant_id'] ?? 0) > 0 ? (int)$item['tenant_id'] : '-' ?></td>
              <td class="p-3">
                <div class="font-medium text-slate-800"><?= Security::e($payload['candidate_name'] ?? '-') ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= Security::e($payload['candidate_email'] ?? '') ?></div>
              </td>
              <td class="p-3">
                <div class="text-slate-700"><?= Security::e(($payload['previous_stage'] ?? '-') . ' -> ' . ($payload['new_stage'] ?? '-')) ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= Security::e($payload['job_title'] ?? '') ?></div>
              </td>
              <td class="p-3">
                <?php $status = (string)($item['status'] ?? 'pending'); ?>
                <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $status === 'processed' ? 'bg-emerald-100 text-emerald-700' : ($status === 'failed' ? 'bg-rose-100 text-rose-700' : ($status === 'disabled' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700')) ?>">
                  <?= Security::e($status) ?>
                </span>
                <?php if (!empty($item['last_error'])): ?>
                  <div class="mt-2 text-xs text-rose-600"><?= Security::e($item['last_error']) ?></div>
                <?php endif; ?>
              </td>
              <td class="p-3"><?= (int)($item['retry_count'] ?? 0) ?></td>
              <td class="p-3">
                <div><?= !empty($item['processed_at']) ? date('d/m/Y H:i', strtotime((string)$item['processed_at'])) : '-' ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= date('d/m/Y H:i', strtotime((string)$item['created_at'])) ?></div>
              </td>
              <td class="p-3">
                <form action="<?= $base ?>/admin/recruitment-webhooks/events/<?= (int)$item['id'] ?>/retry" method="post">
                  <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
                  <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    Reenviar
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-end gap-2 text-sm text-slate-600">
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Anterior</a>
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= $page >= $pages ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Próxima</a>
      </div>
    <?php endif; ?>
  </section>
</div>
