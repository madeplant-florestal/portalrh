<?php
/**
 * Formulário da Avaliação do Período de Experiência (45/90 dias — Etapa 4, 2026-09). Mesmo padrão
 * visual de admin/pdis/form.php (cabeçalho de identificação, cards de seção, $campo/$rotulo/$titulo) —
 * mas a ESCALA (1–5) e a estrutura são específicas deste domínio (§4/§56: não uniformizar as escalas).
 */
require_once APP_PATH . '/views/partials/ui-shell.php';

$contrato = $ctx['contrato'];
$avaliacao = $ctx['avaliacao'];
$criterios = $ctx['criterios'];
$ciencias = $ctx['ciencias'];
$tipo = $ctx['tipo'];
$concluida = $avaliacao !== null && (string)$avaliacao['status'] === 'concluido';
$somenteLeitura = $concluida || !($podeAvaliar ?? false);

$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2.5 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100 disabled:bg-background disabled:text-text-secondary';
$rotulo = 'block text-sm font-medium text-text-primary';
$titulo = 'text-sm font-bold uppercase tracking-wide text-text-secondary';
$v = static fn(string $chave, string $default = ''): string => (string)($avaliacao[$chave] ?? $default);

$disabled = $somenteLeitura ? 'disabled' : '';
?>
<div class="space-y-5">
  <?= ui_breadcrumb([
      ['label' => 'Portal RH', 'href' => $base . '/admin'],
      ['label' => 'Avaliações e Desenvolvimento', 'href' => $base . '/admin/avaliacoes-desenvolvimento'],
      ['label' => 'Avaliação de Experiência', 'href' => $base . '/admin/avaliacoes-experiencia'],
      ['label' => (string)$contrato['nome']],
  ]) ?>
  <?= ui_page_header([
      'titulo' => 'Avaliação de Experiência — ' . AvaliacaoExperienciaService::TIPOS[$tipo] . ' — ' . (string)$contrato['nome'],
      'descricao' => 'Acompanhamento de adaptação, desempenho, alinhamento, comportamentos, competências e aderência aos valores da empresa.',
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($_GET['ok'])): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e(Security::sanitizeString($_GET['ok'])) ?></div><?php endif; ?>

  <!-- Cabeçalho / Identificação (mesmo padrão do PDI) -->
  <section class="rounded-ds-lg border border-border bg-white p-4">
    <h3 class="<?= $titulo ?>">Identificação</h3>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
      <div><dt class="text-xs text-text-secondary">Colaborador</dt><dd class="font-medium"><?= Security::e((string)$contrato['nome']) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Cargo</dt><dd><?= Security::e((string)($contrato['cargo'] ?: '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Setor</dt><dd><?= Security::e((string)($contrato['setor'] ?: '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Empresa / Unidade</dt><dd><?= Security::e((string)($contrato['empresa'] ?: '—')) ?><?= !empty($contrato['unidade']) && $contrato['unidade'] !== $contrato['empresa'] ? ' — ' . Security::e((string)$contrato['unidade']) : '' ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Admissão</dt><dd><?= !empty($contrato['admissao']) ? Security::e(date('d/m/Y', strtotime((string)$contrato['admissao']))) : '—' ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Gestor responsável</dt><dd><?= Security::e($avaliacao !== null ? (string)$avaliacao['gestor_nome_snapshot'] : (string)($ctx['gestor']['nome'] ?? 'A definir')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Tipo</dt><dd><span class="font-semibold"><?= Security::e(AvaliacaoExperienciaService::TIPOS[$tipo]) ?></span></dd></div>
      <div><dt class="text-xs text-text-secondary">Status</dt><dd>
        <?php
          $tomStatus = ['rascunho' => 'info', 'concluido' => 'success'];
          $rotuloStatus = $avaliacao === null ? 'Não iniciada' : ($concluida ? 'Concluída' : 'Em preenchimento');
        ?>
        <?= ui_badge($rotuloStatus, $avaliacao !== null ? ($tomStatus[$avaliacao['status']] ?? 'neutro') : 'neutro') ?>
      </dd></div>
    </dl>
    <p class="mt-3 text-xs text-text-secondary">Objetivo: acompanhar a adaptação, o desempenho, o alinhamento, os comportamentos, as competências e a aderência aos valores durante o período de experiência.</p>
  </section>

  <form method="post" action="<?= Security::e($base . '/admin/avaliacoes-experiencia/' . (int)$contrato['metadados_id'] . '/' . Security::e($tipo) . '/salvar') ?>" class="space-y-5">
    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">

    <!-- Valores culturais -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Valores culturais</h3>
      <p class="mt-1 text-xs text-text-secondary">Escala: <?php foreach (AvaliacaoExperienciaService::ESCALA as $n => $l): ?><span class="mr-2"><strong><?= $n ?></strong> = <?= Security::e($l) ?></span><?php endforeach; ?></p>

      <div class="mt-4 space-y-5">
        <?php foreach (AvaliacaoExperienciaService::VALORES_CULTURAIS as $valorSlug => $grupo): ?>
          <div class="rounded-lg border border-border p-3">
            <h4 class="text-sm font-bold text-text-primary"><?= Security::e($grupo['label']) ?></h4>
            <div class="mt-2 space-y-2.5">
              <?php foreach ($grupo['criterios'] as $i => $criterioTexto): $indice = $i + 1; $notaAtual = $criterios[$valorSlug][$indice] ?? null; ?>
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between">
                  <span class="text-sm text-text-primary"><?= Security::e($criterioTexto) ?></span>
                  <div class="flex gap-1" role="radiogroup" aria-label="<?= Security::e($criterioTexto) ?>">
                    <?php foreach (AvaliacaoExperienciaService::ESCALA as $n => $l): ?>
                      <label class="cursor-pointer">
                        <input type="radio" name="nota_<?= $valorSlug ?>_<?= $indice ?>" value="<?= $n ?>" <?= $notaAtual === $n ? 'checked' : '' ?> <?= $disabled ?> class="peer sr-only">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md border border-border text-xs font-semibold text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white" title="<?= Security::e($l) ?>"><?= $n ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-3">
              <label for="valores_comentario_<?= $valorSlug ?>" class="text-xs font-medium text-text-primary">Comentário do gestor</label>
              <textarea id="valores_comentario_<?= $valorSlug ?>" name="valores_comentario_<?= $valorSlug ?>" rows="2" <?= $disabled ?> class="<?= $campo ?>"><?= Security::e($v('valores_comentario_' . $valorSlug)) ?></textarea>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Avaliação técnica -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Avaliação técnica da função</h3>
      <p class="mt-2 text-sm text-text-primary">O colaborador demonstra capacidade técnica para executar a função?</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <?php foreach (AvaliacaoExperienciaService::TECNICA_OPCOES as $k => $l): ?>
          <label class="cursor-pointer">
            <input type="radio" name="tecnica_capacidade" value="<?= $k ?>" <?= $v('tecnica_capacidade') === $k ? 'checked' : '' ?> <?= $disabled ?> class="peer sr-only">
            <span class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label for="tecnica_comentarios" class="mt-3 block text-xs font-medium text-text-primary">Comentários</label>
      <textarea id="tecnica_comentarios" name="tecnica_comentarios" rows="2" <?= $disabled ?> class="<?= $campo ?>"><?= Security::e($v('tecnica_comentarios')) ?></textarea>
    </section>

    <!-- Desenvolvimento e adaptação -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Desenvolvimento e adaptação</h3>
      <p class="mt-2 text-sm text-text-primary">O colaborador demonstrou adaptação à cultura e rotina da empresa?</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <?php foreach (AvaliacaoExperienciaService::ADAPTACAO_OPCOES as $k => $l): ?>
          <label class="cursor-pointer">
            <input type="radio" name="adaptacao_nivel" value="<?= $k ?>" <?= $v('adaptacao_nivel') === $k ? 'checked' : '' ?> <?= $disabled ?> class="peer sr-only">
            <span class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label for="adaptacao_comentarios" class="mt-3 block text-xs font-medium text-text-primary">Comentários</label>
      <textarea id="adaptacao_comentarios" name="adaptacao_comentarios" rows="2" <?= $disabled ?> class="<?= $campo ?>"><?= Security::e($v('adaptacao_comentarios')) ?></textarea>
    </section>

    <!-- Feedback geral -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Feedback geral do gestor</h3>
      <p class="mt-1 text-xs text-text-secondary">Pontos fortes, oportunidades de melhoria e orientações para desenvolvimento.</p>
      <textarea name="feedback_geral" rows="5" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('feedback_geral')) ?></textarea>
    </section>

    <?php if (!$somenteLeitura): ?>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="<?= ui_btn('secundario') ?>">Salvar rascunho</button>
      </div>
    <?php endif; ?>
  </form>

  <!-- Parecer final -->
  <section class="rounded-ds-lg border border-border bg-white p-4">
    <h3 class="<?= $titulo ?>">Parecer final</h3>
    <?php if ($avaliacao === null): ?>
      <p class="mt-2 text-sm text-text-secondary">Salve um rascunho antes de registrar o parecer final.</p>
    <?php else: ?>
      <?php if ($concluida): ?>
        <dl class="mt-2 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-xs text-text-secondary">Parecer</dt><dd class="font-semibold"><?= Security::e(AvaliacaoExperienciaService::PARECER_OPCOES[$avaliacao['parecer']] ?? '—') ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Data da realização</dt><dd><?= Security::e(date('d/m/Y', strtotime((string)$avaliacao['data_realizacao']))) ?></dd></div>
          <?php if (!empty($avaliacao['parecer_justificativa'])): ?>
            <div class="sm:col-span-2"><dt class="text-xs text-text-secondary">Justificativa</dt><dd><?= nl2br(Security::e((string)$avaliacao['parecer_justificativa'])) ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if ($podeAvaliar ?? false): ?>
          <form method="post" action="<?= Security::e($base . '/admin/avaliacoes-experiencia/' . (int)$avaliacao['id'] . '/reabrir') ?>" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <div class="flex-1 min-w-[220px]">
              <label for="justificativa_reabrir" class="text-xs font-medium text-text-primary">Justificativa para reabrir (Admin/RH)</label>
              <input id="justificativa_reabrir" type="text" name="justificativa" class="<?= $campo ?>" required>
            </div>
            <button type="submit" class="<?= ui_btn('destrutivo') ?>">Reabrir avaliação</button>
          </form>
        <?php endif; ?>
      <?php elseif (!$somenteLeitura): ?>
        <form method="post" action="<?= Security::e($base . '/admin/avaliacoes-experiencia/' . (int)$avaliacao['id'] . '/concluir') ?>" class="mt-2 space-y-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div class="flex flex-wrap gap-2">
            <?php foreach (AvaliacaoExperienciaService::PARECER_OPCOES as $k => $l): ?>
              <label class="cursor-pointer">
                <input type="radio" name="parecer" value="<?= $k ?>" class="peer sr-only" data-parecer-opcao required>
                <span class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div>
            <label for="parecer_justificativa" class="text-xs font-medium text-text-primary">Justificativa <span class="text-text-muted">(obrigatória, exceto para "Apto para efetivação")</span></label>
            <textarea id="parecer_justificativa" name="parecer_justificativa" rows="3" class="<?= $campo ?>"></textarea>
          </div>
          <button type="submit" class="<?= ui_btn('primario') ?>">Concluir avaliação</button>
        </form>
        <?php ui_script_pagina('avaliacao-experiencia.js'); ?>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($avaliacao !== null): ?>
    <?php
      $documentoTipo = 'avaliacao_experiencia';
      $documentoId = (int)$avaliacao['id'];
      $status = (string)$avaliacao['status'];
      $nomeColaborador = (string)$avaliacao['snap_nome'];
      $acaoCiencia = $base . '/admin/avaliacoes-experiencia/' . $documentoId . '/ciencia';
      $podeRegistrarCiencia = $podeAvaliar ?? false;
      include APP_PATH . '/views/admin/partials/avaliacoes-desenvolvimento/_ciencia.php';
    ?>
  <?php endif; ?>
</div>
