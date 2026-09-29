<?php
/**
 * Bloco de Ciência — COMPARTILHADO entre Avaliação de Experiência e Feedback (§45 da Etapa 4). Espera
 * no escopo: $documentoTipo ('avaliacao_experiencia'|'feedback'), $documentoId, $ciencias (saída de
 * AvaliacoesDesenvolvimentoAuditoriaService::listarCiencias()), $status (rascunho|concluido|cancelado),
 * $nomeColaborador, $acaoCiencia (URL do POST), $podeRegistrarCiencia, $base.
 *
 * Ciência é registro INTERNO nesta fase (§44/§48) — nunca assinatura eletrônica/jurídica. Preparado
 * para assinatura futura (status_assinatura sempre "não solicitada" nesta rodada, §46/§47).
 */
$papeis = ['gestor' => 'Gestor', 'rh' => 'RH', 'colaborador' => 'Colaborador'];
?>
<section class="rounded-ds-lg border border-border bg-white p-4">
  <h3 class="text-sm font-bold uppercase tracking-wide text-text-secondary">Ciência</h3>
  <p class="mt-1 text-xs text-text-secondary">Registro interno de que o documento foi discutido/comunicado — não é assinatura eletrônica (a integração com assinador fica para uma rodada futura).</p>

  <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <?php foreach ($papeis as $papel => $rotulo): ?>
      <?php $c = $ciencias[$papel] ?? null; ?>
      <div class="rounded-lg border border-border bg-background p-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary"><?= Security::e($rotulo) ?></p>
        <?php if ($c !== null): ?>
          <p class="mt-1 text-sm font-medium text-text-primary"><?= Security::e((string)$c['nome_snapshot']) ?></p>
          <p class="text-xs text-text-secondary"><?= Security::e(date('d/m/Y H:i', strtotime((string)$c['registrado_em']))) ?></p>
        <?php else: ?>
          <p class="mt-1 text-sm text-text-muted">Ciência ainda não registrada</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($status === 'concluido' && $podeRegistrarCiencia && !isset($ciencias['colaborador'])): ?>
    <form method="post" action="<?= Security::e($acaoCiencia) ?>" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
      <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <div class="flex-1 min-w-[220px]">
        <label for="nome_colaborador" class="block text-xs font-medium text-text-primary">Registrar ciência do colaborador (assistida)</label>
        <input id="nome_colaborador" type="text" name="nome_colaborador" value="<?= Security::e($nomeColaborador) ?>" class="mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100" required>
      </div>
      <button type="submit" class="<?= ui_btn('secundario') ?>">Registrar ciência</button>
    </form>
  <?php elseif ($status !== 'concluido'): ?>
    <p class="mt-3 border-t border-border pt-3 text-xs text-text-muted">A ciência do colaborador só pode ser registrada depois da conclusão.</p>
  <?php endif; ?>
</section>
