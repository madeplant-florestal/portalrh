<?php
/**
 * Resultados — Pesquisa de Integração (via QR Code) de UMA integração. Agregados primeiro; Nome/
 * Cargo/Empresa (espelho oficial) aparecem só junto aos comentários. Nunca CPF/nascimento.
 * NPS = %Promotores - %Detratores (nunca a média das notas).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$dataFormatada = date('d/m/Y', strtotime((string)$resultados['data_integracao']));
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'integracao', 'pesquisas', [
      'titulo' => 'Resultados — Pesquisa de Integração',
      'descricao' => 'Data da Integração: ' . $dataFormatada . ' · respostas recebidas pelo QR Code',
      'acao' => ['label' => 'Voltar para Pesquisas de Integração', 'href' => $base . '/admin/pesquisas-reacao-integracao'],
  ], [['label' => 'Integração de ' . $dataFormatada]]) ?>

  <?php if ($resultados['total'] === 0): ?>
    <div class="rounded-ds-lg border border-border bg-surface p-6 text-center">
      <p class="text-sm text-text-secondary">Nenhuma resposta recebida até o momento.</p>
    </div>
  <?php else: ?>
    <section class="grid grid-cols-2 gap-4 sm:grid-cols-5">
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Total de Respostas</p>
        <p class="mt-1 text-2xl font-bold text-text-primary"><?= (int)$resultados['total'] ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">NPS</p>
        <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format((float)$resultados['nps'], 1, ',', '.') ?></p>
        <p class="mt-0.5 text-[11px] text-text-secondary">%Promotores − %Detratores</p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Promotores</p>
        <p class="mt-1 text-2xl font-bold text-success"><?= (int)$resultados['promotores'] ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Neutros</p>
        <p class="mt-1 text-2xl font-bold text-warning"><?= (int)$resultados['neutros'] ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Detratores</p>
        <p class="mt-1 text-2xl font-bold text-danger"><?= (int)$resultados['detratores'] ?></p>
      </div>
    </section>

    <section class="rounded-ds-lg border border-border bg-surface p-4">
      <h3 class="text-sm font-bold text-text-primary">Perguntas de satisfação</h3>
      <p class="text-[11px] text-text-secondary">Escala de 1 (muito insatisfeito) a 5 (muito satisfeito) — média e distribuição das respostas</p>
      <div class="responsive-table-wrap mt-3">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b text-left text-text-secondary">
              <th class="p-3">Pergunta</th>
              <th class="p-3">Média</th>
              <?php for ($n = 1; $n <= 5; $n++): ?><th class="p-3 text-center">Nota <?= $n ?></th><?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resultados['perguntas'] as $pergunta): ?>
              <tr class="border-b">
                <td class="p-3 text-text-primary"><?= Security::e($pergunta['rotulo']) ?></td>
                <td class="p-3 font-bold text-text-primary"><?= number_format((float)$pergunta['media'], 1, ',', '.') ?></td>
                <?php for ($n = 1; $n <= 5; $n++): ?><td class="p-3 text-center text-text-secondary"><?= (int)$pergunta['distribuicao'][$n] ?></td><?php endfor; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="rounded-ds-lg border border-border bg-surface p-4">
      <h3 class="text-sm font-bold text-text-primary">Comentários dos colaboradores</h3>
      <?php if ($resultados['comentarios'] === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum comentário preenchido até o momento.</p>
      <?php else: ?>
        <div class="mt-3 space-y-3">
          <?php foreach ($resultados['comentarios'] as $c): ?>
            <div class="rounded-ds-md bg-background p-3">
              <p class="text-xs font-semibold text-text-secondary">
                <?= Security::e(implode(' · ', array_filter([(string)($c['nome'] ?? ''), (string)($c['cargo'] ?? ''), (string)($c['empresa'] ?? '')], static fn(string $v): bool => $v !== '')) ?: 'Colaborador não identificado') ?>
                · <?= Security::e(date('d/m/Y H:i', strtotime($c['respondida_em']))) ?>
              </p>
              <p class="mt-1 text-sm text-text-primary"><?= nl2br(Security::e($c['comentario'])) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
