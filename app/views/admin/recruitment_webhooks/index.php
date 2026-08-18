<?php
$historyItems = $history['items'] ?? [];
$page = (int)($history['page'] ?? 1);
$pages = (int)($history['pages'] ?? 1);
$queryBase = $base . '/admin/recruitment-webhooks';
$prevParams = ['page' => max(1, $page - 1)];
$nextParams = ['page' => min($pages, $page + 1)];
$queue = $queue ?? [];
$pendentes = (int)($queue['pending'] ?? 0) + (int)($queue['processing'] ?? 0);
$entregues = (int)($queue['processed'] ?? 0);
$falhas = (int)($queue['failed'] ?? 0);
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 sm:text-3xl">Webhooks do Recrutamento</h1>
      <p class="mt-1 text-sm text-gray-500">Integração única e global com o n8n para automações do Kanban de recrutamento.</p>
    </div>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-slate-800">Configuração da integração</h2>
      <p class="text-sm text-slate-500">Uma única URL e um único segredo para todas as empresas do grupo. A empresa da vaga é enviada apenas como informação de contexto no payload.</p>
    </div>

    <?php if (!empty($revealSecret)): ?>
      <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <p class="font-semibold">Segredo gerado — copie agora, ele não será exibido novamente:</p>
        <code class="mt-1 block break-all rounded bg-white px-3 py-2 text-xs text-slate-800"><?= Security::e($revealSecret['secret'] ?? '') ?></code>
      </div>
    <?php endif; ?>

    <?php if (!empty($testResult)): ?>
      <div class="rounded-xl border <?= !empty($testResult['ok']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-4 py-3 text-sm">
        <p class="font-semibold"><?= !empty($testResult['ok']) ? 'Teste entregue com sucesso' : 'Teste falhou' ?></p>
        <p class="mt-1 text-xs">
          Horário: <?= Security::e((string)($testResult['tested_at'] ?? '')) ?>
          <?php if (!empty($testResult['status_code'])): ?> · Status HTTP: <?= (int)$testResult['status_code'] ?><?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h3 class="text-base font-semibold text-slate-800">Configuração global</h3>
        <span class="rounded-full px-3 py-1 text-xs font-semibold <?= (int)($setting['enabled'] ?? 0) === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
          <?= (int)($setting['enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
        </span>
      </div>

      <form action="<?= $base ?>/admin/recruitment-webhooks/settings/save" method="post" class="mt-4 space-y-4">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="enabled" value="1" <?= (int)($setting['enabled'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-slate-300 text-ctgreen focus:ring-ctgreen">
          Habilitar envio de webhooks
        </label>
        <div>
          <label class="block text-sm font-medium text-slate-700">URL do webhook</label>
          <input type="url" name="webhook_url" value="<?= Security::e($setting['webhook_url'] ?? '') ?>" placeholder="https://n8n.exemplo.com/webhook/recrutamento" class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        </div>
        <div class="flex justify-end">
          <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Salvar configuração</button>
        </div>
      </form>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
        <div class="text-xs text-slate-500">
          Segredo de assinatura (HMAC-SHA256):
          <span class="font-semibold <?= !empty($setting['has_secret']) ? 'text-emerald-700' : 'text-amber-700' ?>">
            <?= !empty($setting['has_secret']) ? 'configurado' : 'não configurado' ?>
          </span>
        </div>
        <div class="flex gap-2">
          <form action="<?= $base ?>/admin/recruitment-webhooks/settings/regenerate-secret" method="post" onsubmit="return confirm('Gerar um novo segredo invalida o anterior. Continuar?');">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
              <?= !empty($setting['has_secret']) ? 'Regenerar segredo' : 'Gerar segredo' ?>
            </button>
          </form>
          <form action="<?= $base ?>/admin/recruitment-webhooks/test" method="post">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
              Testar webhook
            </button>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">Situação da fila</h2>
        <p class="text-sm text-slate-500">O envio nunca bloqueia a movimentação do candidato no Kanban.</p>
      </div>
      <form action="<?= $base ?>/admin/recruitment-webhooks/process-pending" method="post">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">
          Processar fila pendente
        </button>
      </form>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-slate-800"><?= $pendentes ?></div>
        <div class="text-xs text-slate-500">Pendentes</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-emerald-700"><?= $entregues ?></div>
        <div class="text-xs text-slate-500">Entregues</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-rose-700"><?= $falhas ?></div>
        <div class="text-xs text-slate-500">Falhas</div>
      </div>
    </div>
    <p class="text-xs text-slate-500">
      Último processamento com sucesso: <?= !empty($lastProcessedAt) ? date('d/m/Y H:i', strtotime((string)$lastProcessedAt)) : 'nenhum ainda' ?>
    </p>
  </section>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-slate-800">Histórico recente</h2>
      <p class="text-sm text-slate-500">Eventos enviados para o n8n, com situação, tentativas e reenvio manual.</p>
    </div>
    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead>
          <tr class="border-b">
            <th class="p-3 text-left">Evento</th>
            <th class="p-3 text-left">Empresa</th>
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
              <td class="p-3"><?= Security::e($payload['empresa']['nome'] ?? '-') ?></td>
              <td class="p-3">
                <div class="font-medium text-slate-800"><?= Security::e($payload['candidato']['nome'] ?? '-') ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= Security::e($payload['candidato']['email'] ?? '') ?></div>
              </td>
              <td class="p-3">
                <div class="text-slate-700"><?= Security::e(($payload['etapa']['anterior']['nome'] ?? '-') . ' -> ' . ($payload['etapa']['atual']['nome'] ?? '-')) ?></div>
                <div class="mt-1 text-xs text-slate-500"><?= Security::e($payload['vaga']['titulo'] ?? '') ?></div>
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
