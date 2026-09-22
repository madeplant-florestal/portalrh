<?php
$podeOperar = $podeOperar ?? true; // testar/reprocessar/reenviar/configurar exigem admin/rh (o controller informa)
require_once APP_PATH . '/views/partials/modulo-topo.php';
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
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'webhooks', [
      'titulo' => 'Webhooks do Recrutamento',
      'descricao' => 'Integração única e global com o n8n para automações do Kanban de recrutamento.',
  ]) ?>
  <div class="responsive-panel space-y-6">
  <?php if (!empty($flashError)): ?>
    <div class="rounded-xl border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-text-primary">Configuração da integração</h2>
      <p class="text-sm text-text-secondary">Uma única URL e um único segredo para todas as empresas do grupo. A empresa da vaga é enviada apenas como informação de contexto no payload.</p>
    </div>

    <?php if (!empty($revealSecret)): ?>
      <div class="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
        <p class="font-semibold">Segredo gerado — copie agora, ele não será exibido novamente:</p>
        <code class="mt-1 block break-all rounded bg-white px-3 py-2 text-xs text-text-primary"><?= Security::e($revealSecret['secret'] ?? '') ?></code>
      </div>
    <?php endif; ?>

    <?php if (!empty($testResult)): ?>
      <div class="rounded-xl border <?= !empty($testResult['ok']) ? 'border-success/30 bg-success/10 text-success' : 'border-danger/30 bg-danger/10 text-danger' ?> px-4 py-3 text-sm">
        <p class="font-semibold"><?= !empty($testResult['ok']) ? 'Teste entregue com sucesso' : 'Teste falhou' ?></p>
        <p class="mt-1 text-xs">
          Horário: <?= Security::e((string)($testResult['tested_at'] ?? '')) ?>
          <?php if (!empty($testResult['status_code'])): ?> · Status HTTP: <?= (int)$testResult['status_code'] ?><?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-border bg-white p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h3 class="text-base font-semibold text-text-primary">Configuração global</h3>
        <span class="rounded-full px-3 py-1 text-xs font-semibold <?= (int)($setting['enabled'] ?? 0) === 1 ? 'bg-success/10 text-success' : 'bg-surface-secondary text-text-secondary' ?>">
          <?= (int)($setting['enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
        </span>
      </div>

      <?php if (!$podeOperar): ?>
      <dl class="mt-4 space-y-2 text-sm">
        <div><dt class="text-text-secondary">Envio de webhooks</dt><dd class="font-medium text-text-primary"><?= (int)($setting['enabled'] ?? 0) === 1 ? 'Habilitado' : 'Desabilitado' ?></dd></div>
        <div><dt class="text-text-secondary">URL do webhook</dt><dd class="break-all font-medium text-text-primary"><?= Security::e((string)($setting['webhook_url'] ?? '') ?: '—') ?></dd></div>
        <p class="text-xs text-text-secondary">Somente visualização — apenas Admin/RH executam ações nesta tela.</p>
      </dl>
      <?php else: ?>
      <form action="<?= $base ?>/admin/recruitment-webhooks/settings/save" method="post" class="mt-4 space-y-4">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <label class="inline-flex items-center gap-2 text-sm text-text-primary">
          <input type="checkbox" name="enabled" value="1" <?= (int)($setting['enabled'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-border text-primary-700 focus:ring-primary-100">
          Habilitar envio de webhooks
        </label>
        <div>
          <label class="block text-sm font-medium text-text-primary">URL do webhook</label>
          <input type="url" name="webhook_url" value="<?= Security::e($setting['webhook_url'] ?? '') ?>" placeholder="https://n8n.exemplo.com/webhook/recrutamento" class="mt-1 w-full rounded-xl border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        </div>
        <div class="flex justify-end">
          <button type="submit" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-800">Salvar configuração</button>
        </div>
      </form>
      <?php endif; ?>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
        <div class="text-xs text-text-secondary">
          Segredo de assinatura (HMAC-SHA256):
          <span class="font-semibold <?= !empty($setting['has_secret']) ? 'text-success' : 'text-warning' ?>">
            <?= !empty($setting['has_secret']) ? 'configurado' : 'não configurado' ?>
          </span>
        </div>
        <?php if ($podeOperar): ?>
        <div class="flex gap-2">
          <form action="<?= $base ?>/admin/recruitment-webhooks/settings/regenerate-secret" method="post" data-confirm-message="Gerar um novo segredo invalida o anterior. Continuar?">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
            <button type="submit" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-text-primary hover:bg-surface-secondary">
              <?= !empty($setting['has_secret']) ? 'Regenerar segredo' : 'Gerar segredo' ?>
            </button>
          </form>
          <form action="<?= $base ?>/admin/recruitment-webhooks/test" method="post">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
            <button type="submit" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-text-primary hover:bg-surface-secondary">
              Testar webhook
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-text-primary">Situação da fila</h2>
        <p class="text-sm text-text-secondary">O envio nunca bloqueia a movimentação do candidato no Kanban.</p>
      </div>
      <?php if ($podeOperar): ?>
      <form action="<?= $base ?>/admin/recruitment-webhooks/process-pending" method="post">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary-700 px-4 py-3 text-sm font-medium text-white hover:bg-primary-800">
          Processar fila pendente
        </button>
      </form>
      <?php endif; ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
      <div class="rounded-2xl border border-border bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-text-primary"><?= $pendentes ?></div>
        <div class="text-xs text-text-secondary">Pendentes</div>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-success"><?= $entregues ?></div>
        <div class="text-xs text-text-secondary">Entregues</div>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 text-center shadow-sm">
        <div class="text-2xl font-bold text-danger"><?= $falhas ?></div>
        <div class="text-xs text-text-secondary">Falhas</div>
      </div>
    </div>
    <p class="text-xs text-text-secondary">
      Último processamento com sucesso: <?= !empty($lastProcessedAt) ? date('d/m/Y H:i', strtotime((string)$lastProcessedAt)) : 'nenhum ainda' ?>
    </p>
  </section>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-text-primary">Histórico recente</h2>
      <p class="text-sm text-text-secondary">Eventos enviados para o n8n, com situação, tentativas e reenvio manual.</p>
    </div>
    <div class="responsive-table-wrap">
      <table class="min-w-full text-sm">
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
            <tr class="border-b align-top hover:bg-surface-secondary">
              <td class="p-3">
                <div class="font-semibold text-text-primary"><?= Security::e($item['event_type'] ?? '') ?></div>
                <div class="mt-1 text-xs text-text-secondary"><?= Security::e((string)($item['webhook_url'] ?? 'Sem URL')) ?></div>
              </td>
              <td class="p-3"><?= Security::e($payload['empresa']['nome'] ?? '-') ?></td>
              <td class="p-3">
                <div class="font-medium text-text-primary"><?= Security::e($payload['candidato']['nome'] ?? '-') ?></div>
                <div class="mt-1 text-xs text-text-secondary"><?= Security::e($payload['candidato']['email'] ?? '') ?></div>
              </td>
              <td class="p-3">
                <div class="text-text-primary"><?= Security::e(($payload['etapa']['anterior']['nome'] ?? '-') . ' -> ' . ($payload['etapa']['atual']['nome'] ?? '-')) ?></div>
                <div class="mt-1 text-xs text-text-secondary"><?= Security::e($payload['vaga']['titulo'] ?? '') ?></div>
              </td>
              <td class="p-3">
                <?php $status = (string)($item['status'] ?? 'pending'); ?>
                <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $status === 'processed' ? 'bg-success/10 text-success' : ($status === 'failed' ? 'bg-danger/10 text-danger' : ($status === 'disabled' ? 'bg-warning/10 text-warning' : 'bg-surface-secondary text-text-primary')) ?>">
                  <?= Security::e($status) ?>
                </span>
                <?php if (!empty($item['last_error'])): ?>
                  <div class="mt-2 text-xs text-danger"><?= Security::e($item['last_error']) ?></div>
                <?php endif; ?>
              </td>
              <td class="p-3"><?= (int)($item['retry_count'] ?? 0) ?></td>
              <td class="p-3">
                <div><?= !empty($item['processed_at']) ? date('d/m/Y H:i', strtotime((string)$item['processed_at'])) : '-' ?></div>
                <div class="mt-1 text-xs text-text-secondary"><?= date('d/m/Y H:i', strtotime((string)$item['created_at'])) ?></div>
              </td>
              <td class="p-3">
                <?php if ($podeOperar): ?>
                <form action="<?= $base ?>/admin/recruitment-webhooks/events/<?= (int)$item['id'] ?>/retry" method="post">
                  <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
                  <button type="submit" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-text-primary hover:bg-surface-secondary">
                    Reenviar
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-end gap-2 text-sm text-text-secondary">
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Anterior</a>
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= $page >= $pages ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Próxima</a>
      </div>
    <?php endif; ?>
  </section>
  </div>
</div>
