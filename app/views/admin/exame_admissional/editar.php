<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$e = $exame;
$cand = $candidatura;
$fmtDataInput = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('Y-m-d', strtotime($d)) : '';
$fmtDataHora = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y H:i', strtotime($d)) : '—';
$rotulosStatus = [
    'rascunho' => 'Rascunho', 'mensagem_preparada' => 'Mensagem preparada', 'enviado' => 'Envio confirmado pelo RH',
];
$rotulosEvento = [
    'criado' => 'Agendamento criado', 'atualizado' => 'Dados atualizados', 'reagendado' => 'Reagendado (preparação anterior invalidada)',
    'mensagem_preparada' => 'Mensagem preparada', 'envio_confirmado' => 'Envio confirmado',
];
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'candidaturas', [
      'titulo' => 'Exame Admissional — ' . (string)($cand['nome'] ?? ''),
      'descricao' => 'Status: ' . ($rotulosStatus[$e['status']] ?? $e['status']),
  ], [
      ['label' => (string)($cand['nome'] ?? ''), 'href' => $base . '/admin/candidaturas/' . (int)$e['candidatura_id']],
      ['label' => 'Exame Admissional'],
  ]) ?>

  <div class="responsive-panel">
    <?php if ($e['status'] !== 'rascunho'): ?>
      <div class="rounded-ds-md border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        Alterar clínica, endereço, data, horário ou telefone agora invalida a mensagem já preparada/enviada — será necessário preparar e confirmar o envio de novo.
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $base ?>/admin/exames-admissionais/<?= (int)$e['id'] ?>" class="mt-4 space-y-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-text-primary">Clínica</label>
          <input type="text" name="clinica" value="<?= Security::e((string)($e['clinica'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Telefone de contato</label>
          <input type="text" name="telefone_contato" value="<?= Security::e((string)($e['telefone_contato'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-text-primary">Endereço</label>
          <input type="text" name="endereco" value="<?= Security::e((string)($e['endereco'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Data do exame</label>
          <input type="date" name="data_exame" value="<?= Security::e($fmtDataInput($e['data_exame'])) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Horário</label>
          <input type="time" name="horario" value="<?= Security::e(substr((string)($e['horario'] ?? ''), 0, 5)) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
      </div>
      <div class="responsive-form-actions justify-end">
        <button type="submit" class="border border-border px-4 py-2 rounded text-sm">Salvar</button>
        <button type="submit" formaction="<?= $base ?>/admin/exames-admissionais/<?= (int)$e['id'] ?>/preparar-envio" class="bg-primary-700 text-white px-4 py-2 rounded text-sm">Salvar e Preparar Mensagem</button>
      </div>
    </form>

    <?php if (in_array($e['status'], ['mensagem_preparada', 'enviado'], true) && !empty($e['comunicacao_id'])): ?>
      <?php $comunicacao = Comunicacao::find((int)$e['comunicacao_id']); ?>
      <div class="mt-6 rounded-ds-md border border-border bg-surface-secondary p-4">
        <p class="text-sm font-semibold text-text-primary">Mensagem preparada — copie e envie pelo canal usual:</p>
        <textarea readonly rows="8" class="mt-2 w-full rounded border bg-white px-3 py-2 text-sm"><?= Security::e((string)($comunicacao['conteudo'] ?? '')) ?></textarea>
        <?php if ($e['status'] === 'mensagem_preparada'): ?>
          <form method="post" action="<?= $base ?>/admin/exames-admissionais/<?= (int)$e['id'] ?>/confirmar-envio" class="mt-3">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
            <button type="submit" class="bg-primary-700 text-white px-4 py-2 rounded text-sm">Confirmar que o envio foi realizado</button>
          </form>
        <?php else: ?>
          <p class="mt-2 text-sm text-text-secondary">Envio confirmado em <?= Security::e($fmtDataHora($e['enviado_em'])) ?> por <?= Security::e((string)($e['enviado_por_nome'] ?? '')) ?>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($historico)): ?>
      <div class="mt-6 border-t pt-4">
        <h3 class="text-sm font-bold text-text-primary">Histórico de alterações</h3>
        <ul class="mt-2 divide-y divide-border rounded border border-border bg-white">
          <?php foreach ($historico as $h): ?>
            <li class="px-4 py-2 text-sm">
              <span class="font-medium"><?= Security::e($rotulosEvento[$h['tipo_evento']] ?? $h['tipo_evento']) ?></span>
              — <?= Security::e($fmtDataHora($h['created_at'])) ?>
              <?php if (!empty($h['usuario_nome'])): ?> por <?= Security::e((string)$h['usuario_nome']) ?><?php endif; ?>
              <?php if (!empty($h['observacoes'])): ?><p class="text-text-secondary"><?= Security::e((string)$h['observacoes']) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
