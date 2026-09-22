<?php
/**
 * Pesquisa de Integração via QR Code — administração. O QR codifica SOMENTE a URL pública estável
 * (/integracao): nenhum dado pessoal, colaborador, contrato, data ou token. Gerado localmente no
 * navegador (public/assets/qrcode.js, MIT) — nenhuma URL é enviada a serviços externos.
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
// Scripts locais (CSP script-src 'self'): registrados no <head> do AppShell V2, na ordem — qrcode.js antes de integracao-qr.js.
ui_script_pagina('qrcode.js');
ui_script_pagina('integracao-qr.js');
?>
<style>
  @media print {
    body * { visibility: hidden !important; }
    #qr-print-area, #qr-print-area * { visibility: visible !important; }
    #qr-print-area { position: fixed; top: 0; left: 0; right: 0; margin: 0; padding: 2.5rem 1.5rem; border: 0 !important; box-shadow: none !important; text-align: center; background: #fff; }
    #qr-print-area .qr-print-hide { display: none !important; }
  }
</style>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'integracao', 'qr', [
      'titulo' => 'Pesquisa de Integração via QR Code',
      'descricao' => 'QR Code único e reutilizável. O colaborador escaneia, se identifica por CPF + data de nascimento e responde a Pesquisa de Integração.',
  ]) ?>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <article id="qr-print-area" class="rounded-ds-lg border border-border bg-surface p-5 text-center">
      <div class="rounded-ds-md bg-primary-700 px-4 py-3 text-white">
        <h3 class="text-lg font-bold">Pesquisa de Integração</h3>
      </div>
      <p class="mt-4 text-sm text-text-primary">Escaneie o QR Code com a câmera do seu celular para responder à pesquisa.</p>
      <div data-qr-render="1" data-qr-url="<?= Security::e($urlPublica) ?>" class="mx-auto mt-4 w-64 max-w-full"></div>
      <p class="qr-print-hide mt-3 break-all text-xs text-text-secondary"><?= Security::e($urlPublica) ?></p>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-5">
      <h3 class="text-sm font-bold text-text-primary">Link público</h3>
      <input id="url-publica" type="text" readonly value="<?= Security::e($urlPublica) ?>" class="mt-2 w-full rounded-ds-md border border-border bg-background px-3 py-2 text-sm text-text-primary">
      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-copy-target="#url-publica" class="<?= ui_btn('primario') ?>">Copiar link</button>
        <button type="button" data-print="1" class="<?= ui_btn('secundario') ?>">Imprimir QR Code</button>
      </div>
      <p data-copy-feedback="1" class="mt-2 text-xs text-text-secondary" role="status" aria-live="polite"></p>
      <p class="mt-4 text-xs text-text-secondary">O QR Code é sempre o mesmo e pode ser impresso ou projetado nas próximas integrações. Ele contém somente este endereço — nenhum dado de colaborador.</p>
    </article>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-5">
    <h3 class="text-sm font-bold text-text-primary">Integração atual</h3>
    <?php if ($sessaoAberta !== null): ?>
      <p class="mt-2 text-sm text-text-primary">
        <span class="inline-flex rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-800">Aberta para respostas</span>
        Integração de <strong><?= Security::e(date('d/m/Y', strtotime((string)$sessaoAberta['data_integracao']))) ?></strong>
        <span class="text-text-secondary">· aberta em <?= Security::e(date('d/m/Y H:i', strtotime((string)$sessaoAberta['created_at']))) ?></span>
      </p>
      <?php if (!empty($podeGerenciar)): ?>
        <form method="post" action="<?= $base ?>/admin/pesquisa-integracao-qr/sessao/<?= (int)$sessaoAberta['id'] ?>/encerrar" class="mt-3" data-confirm-message="Encerrar esta integração? O QR Code deixará de receber respostas.">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <button type="submit" class="<?= ui_btn('secundario') ?> !border-danger/40 !text-danger hover:!bg-danger/10">Encerrar integração</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="mt-2 text-sm text-text-secondary">Nenhuma integração aberta. Enquanto isso, quem escanear o QR Code verá que não há pesquisa disponível.</p>
      <?php if (!empty($podeGerenciar)): ?>
        <form method="post" action="<?= $base ?>/admin/pesquisa-integracao-qr/sessao" class="mt-3 flex flex-wrap items-end gap-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div>
            <label for="campo-data-integracao" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Data da Integração</label>
            <input id="campo-data-integracao" type="date" name="data_integracao" required value="<?= Security::e(date('Y-m-d')) ?>" class="mt-1 rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
          </div>
          <button type="submit" class="<?= ui_btn('primario') ?>">Abrir integração</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-5">
    <h3 class="text-sm font-bold text-text-primary">Integrações recentes</h3>
    <div class="responsive-table-wrap mt-3 rounded-ds-md border border-border">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-text-secondary">
            <th class="p-3">Data da Integração</th>
            <th class="p-3">Status</th>
            <th class="p-3">Aberta em</th>
            <th class="p-3">Encerrada em</th>
            <th class="p-3">Respostas via QR</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessoes as $s): ?>
            <tr class="border-b">
              <td class="p-3 text-text-primary"><?= Security::e(date('d/m/Y', strtotime((string)$s['data_integracao']))) ?></td>
              <td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= (int)($s['aberta'] ?? 0) === 1 ? 'bg-primary-50 text-primary-800' : 'bg-surface-secondary text-text-muted' ?>"><?= (int)($s['aberta'] ?? 0) === 1 ? 'Aberta' : 'Encerrada' ?></span></td>
              <td class="p-3 text-text-secondary"><?= Security::e(date('d/m/Y H:i', strtotime((string)$s['created_at']))) ?></td>
              <td class="p-3 text-text-secondary"><?= !empty($s['encerrada_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$s['encerrada_em']))) : '—' ?></td>
              <td class="p-3 text-text-primary"><?= (int)$s['respostas_qr'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sessoes)): ?>
            <tr><td colspan="5" class="p-4 text-center text-text-secondary">Nenhuma integração aberta até agora.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
