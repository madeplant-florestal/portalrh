<?php
/**
 * Resultados — Pesquisa de Integração (via QR Code) de UMA integração. Agregados primeiro; Nome/
 * Cargo/Empresa (espelho oficial) aparecem só junto aos comentários. Nunca CPF/nascimento.
 * NPS = %Promotores - %Detratores (nunca a média das notas).
 */
$dataFormatada = date('d/m/Y', strtotime((string)$resultados['data_integracao']));
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-[#2B2E22]">Resultados — Pesquisa de Integração</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]"><strong>Data da Integração:</strong> <?= Security::e($dataFormatada) ?> · respostas recebidas pelo QR Code</p>
    </div>
    <a href="<?= $base ?>/admin/pesquisas-reacao-integracao" class="text-sm text-[#3B4822] hover:underline">&larr; Voltar para Pesquisas de Integração</a>
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
      <h3 class="text-sm font-bold text-[#2B2E22]">Perguntas de satisfação</h3>
      <p class="text-[11px] text-[#5B5F4E]">Escala de 1 (muito insatisfeito) a 5 (muito satisfeito) — média e distribuição das respostas</p>
      <div class="responsive-table-wrap mt-3">
        <table class="mobile-table-desktop min-w-full text-sm">
          <thead>
            <tr class="border-b text-left text-[#5B5F4E]">
              <th class="p-3">Pergunta</th>
              <th class="p-3">Média</th>
              <?php for ($n = 1; $n <= 5; $n++): ?><th class="p-3 text-center">Nota <?= $n ?></th><?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resultados['perguntas'] as $pergunta): ?>
              <tr class="border-b">
                <td class="p-3 text-[#2B2E22]"><?= Security::e($pergunta['rotulo']) ?></td>
                <td class="p-3 font-bold text-[#2B2E22]"><?= number_format((float)$pergunta['media'], 1, ',', '.') ?></td>
                <?php for ($n = 1; $n <= 5; $n++): ?><td class="p-3 text-center text-[#5B5F4E]"><?= (int)$pergunta['distribuicao'][$n] ?></td><?php endfor; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="text-sm font-bold text-[#2B2E22]">Comentários dos colaboradores</h3>
      <?php if ($resultados['comentarios'] === []): ?>
        <p class="mt-2 text-sm text-[#5B5F4E]">Nenhum comentário preenchido até o momento.</p>
      <?php else: ?>
        <div class="mt-3 space-y-3">
          <?php foreach ($resultados['comentarios'] as $c): ?>
            <div class="rounded-xl bg-[#F7F6F1] p-3">
              <p class="text-xs font-semibold text-[#5B5F4E]">
                <?= Security::e(implode(' · ', array_filter([(string)($c['nome'] ?? ''), (string)($c['cargo'] ?? ''), (string)($c['empresa'] ?? '')], static fn(string $v): bool => $v !== '')) ?: 'Colaborador não identificado') ?>
                · <?= Security::e(date('d/m/Y H:i', strtotime($c['respondida_em']))) ?>
              </p>
              <p class="mt-1 text-sm text-[#2B2E22]"><?= nl2br(Security::e($c['comentario'])) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
