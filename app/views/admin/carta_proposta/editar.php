<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$c = $carta;
$cand = $candidatura;
$fmtDataInput = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('Y-m-d', strtotime($d)) : '';
$fmtDataHora = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y H:i', strtotime($d)) : '—';
$fmtData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
$editavel = $c['status'] === 'rascunho';
$rotulosStatus = [
    'rascunho' => 'Rascunho', 'gerada' => 'Gerada (PDF emitido)', 'enviada' => 'Enviada ao candidato',
    'aceita' => 'Aceita pelo candidato', 'recusada' => 'Recusada pelo candidato', 'substituida' => 'Substituída por versão posterior',
];
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'candidaturas', [
      'titulo' => 'Carta Proposta — ' . (string)($cand['nome'] ?? ''),
      'descricao' => 'Versão ' . (int)$c['versao'] . ' — status: ' . ($rotulosStatus[$c['status']] ?? $c['status']),
  ], [
      ['label' => (string)($cand['nome'] ?? ''), 'href' => $base . '/admin/candidaturas/' . (int)$c['candidatura_id']],
      ['label' => 'Carta Proposta'],
  ]) ?>

  <div class="responsive-panel">
    <?php if (!$editavel): ?>
      <div class="rounded-ds-md border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        Esta versão já foi emitida e não pode mais ser editada. Para corrigir algo, gere uma nova versão a partir da candidatura.
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>" class="mt-4 space-y-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-text-primary">Nome completo do candidato</label>
          <input type="text" name="nome_candidato" value="<?= Security::e((string)$c['nome_candidato']) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Cargo</label>
          <input type="text" name="cargo" value="<?= Security::e((string)$c['cargo']) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Setor</label>
          <input type="text" name="setor" value="<?= Security::e((string)($c['setor'] ?? '')) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Empresa contratante</label>
          <input type="text" name="empresa_contratante" value="<?= Security::e((string)$c['empresa_contratante']) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Salário proposto (R$)</label>
          <input type="text" name="salario_proposto" value="<?= Security::e(number_format((float)$c['salario_proposto'], 2, ',', '.')) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Jornada de trabalho</label>
          <input type="text" name="jornada_trabalho" value="<?= Security::e((string)$c['jornada_trabalho']) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required placeholder="Ex.: 44h semanais">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Escala/Horário</label>
          <input type="text" name="escala_horario" value="<?= Security::e((string)($c['escala_horario'] ?? '')) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="Ex.: Seg a sex, 08h às 18h">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Local de trabalho</label>
          <input type="text" name="local_trabalho" value="<?= Security::e((string)($c['local_trabalho'] ?? '')) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Previsão de início</label>
          <input type="date" name="previsao_inicio" value="<?= Security::e($fmtDataInput($c['previsao_inicio'] ?? null)) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Validade da proposta (prazo do aceite)</label>
          <input type="date" name="validade_proposta" value="<?= Security::e($fmtDataInput($c['validade_proposta'])) ?>" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm" required>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Benefícios aplicáveis à vaga</label>
        <p class="text-xs text-text-secondary">Pré-preenchido a partir dos benefícios cadastrados na vaga. Revise antes de emitir.</p>
        <textarea name="beneficios_texto" rows="5" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm"><?= Security::e((string)($c['beneficios_texto'] ?? '')) ?></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Observações complementares</label>
        <textarea name="observacoes" rows="3" <?= $editavel ? '' : 'disabled' ?> class="mt-1 w-full rounded border px-3 py-2 text-sm"><?= Security::e((string)($c['observacoes'] ?? '')) ?></textarea>
      </div>

      <?php if ($editavel): ?>
        <div class="responsive-form-actions justify-end">
          <button type="submit" class="border border-border px-4 py-2 rounded text-sm">Salvar rascunho</button>
          <button type="submit" formaction="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/emitir" class="bg-primary-700 text-white px-4 py-2 rounded text-sm">Emitir Carta Proposta (gera PDF)</button>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($c['status'] !== 'rascunho'): ?>
      <div class="mt-6 border-t pt-4">
        <h3 class="text-sm font-bold text-text-primary">PDF emitido em <?= Security::e($fmtDataHora($c['gerada_em'])) ?> por <?= Security::e((string)($c['gerada_por_nome'] ?? '')) ?></h3>
        <p class="mt-1 text-sm text-text-secondary">Prazo de validade (aceite pelo candidato): <strong><?= Security::e($fmtData($c['validade_proposta'])) ?></strong></p>
        <div class="mt-2 flex gap-3">
          <a href="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/pdf?inline=1" target="_blank" class="inline-flex items-center rounded border border-border px-4 py-2 text-sm">Visualizar PDF</a>
          <a href="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/pdf" class="inline-flex items-center rounded border border-border px-4 py-2 text-sm">Baixar PDF</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'gerada'): ?>
      <div class="mt-4 border-t pt-4 space-y-3">
        <form method="post" action="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/preparar-envio">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
          <button type="submit" class="border border-border px-4 py-2 rounded text-sm">Preparar Mensagem de Envio</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (!empty($c['comunicacao_id'])): ?>
      <?php
        $comunicacao = Comunicacao::find((int)$c['comunicacao_id']);
        $textareaId = 'mensagem-carta-proposta-' . (int)$c['id'];
      ?>
      <div class="mt-4 border-t pt-4">
        <div class="rounded-ds-md border border-border bg-surface-secondary p-4">
          <p class="text-sm font-semibold text-text-primary">
            <?= $c['status'] === 'gerada' ? 'Mensagem preparada — copie e envie pelo canal usual:' : 'Mensagem registrada:' ?>
          </p>
          <textarea id="<?= Security::e($textareaId) ?>" readonly rows="8" class="mt-2 w-full rounded border bg-white px-3 py-2 text-sm"><?= Security::e((string)($comunicacao['conteudo'] ?? '')) ?></textarea>
          <button type="button" data-copiar-destino="<?= Security::e($textareaId) ?>" class="mt-2 border border-border px-3 py-1.5 rounded text-xs">Copiar mensagem</button>
          <?php if ($c['status'] === 'gerada'): ?>
            <form method="post" action="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/confirmar-envio" class="mt-3">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
              <button type="submit" class="bg-primary-700 text-white px-4 py-2 rounded text-sm">Confirmar que o envio foi realizado</button>
            </form>
            <p class="mt-2 text-xs text-text-secondary">O link só fica acessível ao candidato depois de você confirmar o envio aqui.</p>
            <p class="mt-2 text-xs text-text-secondary">O Portal não anexa o PDF automaticamente ao envio. Baixe o PDF (botão "Visualizar PDF" acima) e anexe-o manualmente junto com este texto no WhatsApp ou e-mail.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'enviada'): ?>
      <div class="mt-4 border-t pt-4 text-sm text-text-secondary">
        Enviada em <?= Security::e($fmtDataHora($c['enviada_em'])) ?> por <?= Security::e((string)($c['enviada_por_nome'] ?? '')) ?>. Aguardando decisão do candidato.
      </div>
    <?php endif; ?>

    <?php if (in_array($c['status'], ['aceita', 'recusada'], true)): ?>
      <div class="mt-4 border-t pt-4 text-sm">
        <p class="font-semibold text-text-primary">Decisão: <?= Security::e($c['status'] === 'aceita' ? 'Aceita' : 'Recusada') ?></p>
        <p class="text-text-secondary">Registrada em <?= Security::e($fmtDataHora($c['decisao_em'])) ?></p>
        <?php if (!empty($c['motivo_recusa'])): ?>
          <p class="mt-1 text-text-secondary">Motivo informado: <?= nl2br(Security::e((string)$c['motivo_recusa'])) ?></p>
        <?php endif; ?>
        <?php if ($c['status'] === 'aceita'): ?>
          <?php if (!empty($c['comprovante_pdf_nome_armazenado'])): ?>
            <div class="mt-3 rounded-ds-md border border-border bg-surface-secondary p-3">
              <p class="text-text-secondary">Via com comprovante de aceite — identificador <strong><?= Security::e((string)$c['comprovante_identificador']) ?></strong>, gerada em <?= Security::e($fmtDataHora($c['comprovante_gerado_em'])) ?>.</p>
              <div class="mt-2 flex gap-3">
                <a href="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/comprovante?inline=1" target="_blank" class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Visualizar comprovante</a>
                <a href="<?= $base ?>/admin/cartas-propostas/<?= (int)$c['id'] ?>/comprovante" class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Baixar comprovante</a>
              </div>
            </div>
          <?php else: ?>
            <p class="mt-2 text-xs text-text-secondary">A via com comprovante de aceite ainda está sendo gerada. Atualize a página em alguns instantes.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (count($historico) > 1): ?>
    <div class="mt-4 rounded bg-surface-secondary p-6">
      <h3 class="text-lg font-semibold text-text-primary mb-3">Histórico de versões</h3>
      <ul class="divide-y divide-border">
        <?php foreach ($historico as $v): ?>
          <li class="flex items-center justify-between py-2 text-sm">
            <span>Versão <?= (int)$v['versao'] ?> — <?= Security::e($rotulosStatus[$v['status']] ?? $v['status']) ?></span>
            <a href="<?= $base ?>/admin/cartas-propostas/<?= (int)$v['id'] ?>/editar" class="text-primary-700 hover:underline">Ver</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php ui_script_pagina('carta_proposta.js'); // JS movido para assets/carta_proposta.js (CSP: sem <script> inline) ?>
