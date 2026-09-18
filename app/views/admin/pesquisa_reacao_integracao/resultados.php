<?php
/**
 * Resultados de uma campanha da Pesquisa de Reação — Treinamento de Integração. NPS = %Promotores
 * - %Detratores (nunca a média das notas 0-10) — RhIndicadoresService::taxaTurnover() não se
 * aplica aqui (métrica diferente), cálculo próprio em PesquisaReacaoIntegracaoService::calcularResultados().
 */
$rotulos = PesquisaReacaoIntegracaoService::rotulosAvaliacoes();
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-[#2B2E22]">Resultados da campanha</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">
        <?= Security::e((string)($campanha['empresa_nome_snapshot'] ?? $campanha['codigo_empresa'] ?? 'Empresa não informada')) ?>
        · <?= Security::e((string)($campanha['setor_nome_snapshot'] ?? $campanha['codigo_setor'] ?? 'Área não informada')) ?>
        <?php if (!empty($campanha['data_integracao'])): ?>
          · Integração em <?= Security::e(date('d/m/Y', strtotime((string)$campanha['data_integracao']))) ?>
        <?php endif; ?>
      </p>
    </div>
    <a href="<?= $base ?>/admin/pesquisas-reacao-integracao" class="text-sm text-[#3B4822] hover:underline">&larr; Voltar para campanhas</a>
  </div>

  <?php if ($resultados['total'] === 0): ?>
    <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center">
      <p class="text-sm text-[#5B5F4E]">Nenhuma resposta recebida até o momento.</p>
    </div>
  <?php else: ?>

    <section class="grid grid-cols-2 gap-4 sm:grid-cols-5">
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#5B5F4E]">Total de Respostas</p>
        <p class="mt-1 text-2xl font-bold text-[#2B2E22]"><?= (int)$resultados['total'] ?></p>
      </div>
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#5B5F4E]">NPS</p>
        <p class="mt-1 text-2xl font-bold text-[#2B2E22]"><?= number_format((float)$resultados['nps'], 1, ',', '.') ?></p>
        <p class="mt-0.5 text-[11px] text-[#5B5F4E]">%Promotores − %Detratores</p>
      </div>
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#5B5F4E]">Promotores</p>
        <p class="mt-1 text-2xl font-bold text-[#2F7D5C]"><?= (int)$resultados['promotores'] ?></p>
      </div>
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#5B5F4E]">Neutros</p>
        <p class="mt-1 text-2xl font-bold text-[#8A6A3F]"><?= (int)$resultados['neutros'] ?></p>
      </div>
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#5B5F4E]">Detratores</p>
        <p class="mt-1 text-2xl font-bold text-[#B23B3B]"><?= (int)$resultados['detratores'] ?></p>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="text-sm font-bold text-[#2B2E22]">Médias — Avaliação da Integração</h3>
      <p class="text-[11px] text-[#5B5F4E]">Escala de 1 (discordo totalmente) a 5 (concordo totalmente)</p>
      <div class="mt-3 space-y-2">
        <?php foreach ($rotulos as $campo => $texto): ?>
          <div class="flex items-center justify-between gap-3 border-b border-[#E2DFD0] py-2 last:border-b-0">
            <span class="text-sm text-[#2B2E22]"><?= Security::e($texto) ?></span>
            <span class="text-sm font-bold text-[#2B2E22]"><?= number_format((float)($resultados['medias'][$campo] ?? 0), 1, ',', '.') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="text-sm font-bold text-[#2B2E22]">Respostas Abertas</h3>
      <div class="mt-3 space-y-4">
        <?php foreach ($resultados['abertas'] as $resposta): ?>
          <?php if (empty($resposta['mais_gostou']) && empty($resposta['poderia_melhorar']) && empty($resposta['informacao_faltante'])): continue; endif; ?>
          <div class="rounded-xl bg-[#F7F6F1] p-3">
            <p class="text-xs font-semibold text-[#5B5F4E]">
              <?= Security::e((string)($resposta['nome'] ?? '') !== '' ? (string)$resposta['nome'] : 'Anônimo') ?>
              · <?= Security::e(date('d/m/Y H:i', strtotime((string)$resposta['respondida_em']))) ?>
            </p>
            <?php if (!empty($resposta['mais_gostou'])): ?>
              <p class="mt-1 text-sm text-[#2B2E22]"><span class="font-medium">O que mais gostou:</span> <?= Security::e((string)$resposta['mais_gostou']) ?></p>
            <?php endif; ?>
            <?php if (!empty($resposta['poderia_melhorar'])): ?>
              <p class="mt-1 text-sm text-[#2B2E22]"><span class="font-medium">Poderia melhorar:</span> <?= Security::e((string)$resposta['poderia_melhorar']) ?></p>
            <?php endif; ?>
            <?php if (!empty($resposta['informacao_faltante'])): ?>
              <p class="mt-1 text-sm text-[#2B2E22]"><span class="font-medium">Informação que sentiu falta:</span> <?= Security::e((string)$resposta['informacao_faltante']) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (array_filter($resultados['abertas'], static fn(array $r) => !empty($r['mais_gostou']) || !empty($r['poderia_melhorar']) || !empty($r['informacao_faltante'])) === []): ?>
          <p class="text-sm text-[#5B5F4E]">Nenhuma resposta aberta preenchida até o momento.</p>
        <?php endif; ?>
      </div>
    </section>

  <?php endif; ?>
</div>
