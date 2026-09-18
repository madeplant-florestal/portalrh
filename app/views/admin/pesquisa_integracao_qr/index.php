<?php
/**
 * Pesquisa de Integração via QR Code — administração. O QR codifica SOMENTE a URL pública estável
 * (/integracao): nenhum dado pessoal, colaborador, contrato, data ou token. Gerado localmente no
 * navegador (public/assets/qrcode.js, MIT) — nenhuma URL é enviada a serviços externos.
 */
?>
<style>
  @media print {
    body * { visibility: hidden !important; }
    #qr-print-area, #qr-print-area * { visibility: visible !important; }
    #qr-print-area { position: fixed; top: 0; left: 0; right: 0; margin: 0; padding: 2.5rem 1.5rem; border: 0 !important; box-shadow: none !important; text-align: center; background: #fff; }
    #qr-print-area .qr-print-hide { display: none !important; }
  }
</style>
<script src="<?= $base ?>/assets/qrcode.js?v=<?= urlencode(Config::assetVersion('assets/qrcode.js')) ?>" defer></script>
<script src="<?= $base ?>/assets/integracao-qr.js?v=<?= urlencode(Config::assetVersion('assets/integracao-qr.js')) ?>" defer></script>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-[#2B2E22]">Pesquisa de Integração via QR Code</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">QR Code único e reutilizável. O colaborador escaneia, se identifica por CPF + data de nascimento e responde a Pesquisa de Integração.</p>
    </div>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <article id="qr-print-area" class="rounded-2xl border border-[#E2DFD0] bg-white p-5 text-center">
      <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
        <h3 class="text-lg font-bold">Pesquisa de Integração</h3>
      </div>
      <p class="mt-4 text-sm text-[#2B2E22]">Escaneie o QR Code com a câmera do seu celular para responder à pesquisa.</p>
      <div data-qr-render="1" data-qr-url="<?= Security::e($urlPublica) ?>" class="mx-auto mt-4 w-64 max-w-full"></div>
      <p class="qr-print-hide mt-3 break-all text-xs text-[#5B5F4E]"><?= Security::e($urlPublica) ?></p>
    </article>

    <article class="rounded-2xl border border-[#E2DFD0] bg-white p-5">
      <h3 class="text-sm font-bold text-[#2B2E22]">Link público</h3>
      <input id="url-publica" type="text" readonly value="<?= Security::e($urlPublica) ?>" class="mt-2 w-full rounded-lg border border-[#E2DFD0] bg-[#F7F6F1] px-3 py-2 text-sm text-[#2B2E22]">
      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-copy-target="#url-publica" class="rounded-lg bg-[#3B4822] px-4 py-2 text-sm font-semibold text-white hover:bg-[#2E3919]">Copiar link</button>
        <button type="button" data-print="1" class="rounded-lg border border-[#3B4822] px-4 py-2 text-sm font-semibold text-[#3B4822] hover:bg-[#F2F4EC]">Imprimir QR Code</button>
      </div>
      <p data-copy-feedback="1" class="mt-2 text-xs text-[#5B5F4E]" role="status" aria-live="polite"></p>
      <p class="mt-4 text-xs text-[#5B5F4E]">O QR Code é sempre o mesmo e pode ser impresso ou projetado nas próximas integrações. Ele contém somente este endereço — nenhum dado de colaborador.</p>
    </article>
  </section>

  <section class="rounded-2xl border border-[#E2DFD0] bg-white p-5">
    <h3 class="text-sm font-bold text-[#2B2E22]">Integração atual</h3>
    <?php if ($sessaoAberta !== null): ?>
      <p class="mt-2 text-sm text-[#2B2E22]">
        <span class="inline-flex rounded-full bg-[#F2F4EC] px-2.5 py-1 text-xs font-semibold text-[#2E3919]">Aberta para respostas</span>
        Integração de <strong><?= Security::e(date('d/m/Y', strtotime((string)$sessaoAberta['data_integracao']))) ?></strong>
        <span class="text-[#5B5F4E]">· aberta em <?= Security::e(date('d/m/Y H:i', strtotime((string)$sessaoAberta['created_at']))) ?></span>
      </p>
      <?php if (!empty($podeGerenciar)): ?>
        <form method="post" action="<?= $base ?>/admin/pesquisa-integracao-qr/sessao/<?= (int)$sessaoAberta['id'] ?>/encerrar" class="mt-3" onsubmit="return confirm('Encerrar esta integração? O QR Code deixará de receber respostas.');">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Encerrar integração</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="mt-2 text-sm text-[#5B5F4E]">Nenhuma integração aberta. Enquanto isso, quem escanear o QR Code verá que não há pesquisa disponível.</p>
      <?php if (!empty($podeGerenciar)): ?>
        <form method="post" action="<?= $base ?>/admin/pesquisa-integracao-qr/sessao" class="mt-3 flex flex-wrap items-end gap-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div>
            <label for="campo-data-integracao" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Data da Integração</label>
            <input id="campo-data-integracao" type="date" name="data_integracao" required value="<?= Security::e(date('Y-m-d')) ?>" class="mt-1 rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22]">
          </div>
          <button type="submit" class="rounded-lg bg-[#3B4822] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#2E3919]">Abrir integração</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="rounded-2xl border border-[#E2DFD0] bg-white p-5">
    <h3 class="text-sm font-bold text-[#2B2E22]">Integrações recentes</h3>
    <div class="responsive-table-wrap mt-3">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-[#5B5F4E]">
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
              <td class="p-3 text-[#2B2E22]"><?= Security::e(date('d/m/Y', strtotime((string)$s['data_integracao']))) ?></td>
              <td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= (int)($s['aberta'] ?? 0) === 1 ? 'bg-[#F2F4EC] text-[#2E3919]' : 'bg-slate-100 text-slate-600' ?>"><?= (int)($s['aberta'] ?? 0) === 1 ? 'Aberta' : 'Encerrada' ?></span></td>
              <td class="p-3 text-[#5B5F4E]"><?= Security::e(date('d/m/Y H:i', strtotime((string)$s['created_at']))) ?></td>
              <td class="p-3 text-[#5B5F4E]"><?= !empty($s['encerrada_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$s['encerrada_em']))) : '—' ?></td>
              <td class="p-3 text-[#2B2E22]"><?= (int)$s['respostas_qr'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sessoes)): ?>
            <tr><td colspan="5" class="p-4 text-center text-[#5B5F4E]">Nenhuma integração aberta até agora.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
