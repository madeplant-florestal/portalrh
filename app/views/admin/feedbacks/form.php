<?php
/**
 * Formulário de Feedback e Desenvolvimento (Etapa 4, 2026-09). Estrutura OFICIAL preservada — escala
 * própria (Atende / Desenvolvimento Necessário, nunca 1–5) e textos de apoio por valor cultural
 * (accordion nativo <details>, compacto por padrão — §33). Mesmo padrão visual de
 * admin/avaliacoes-experiencia/form.php (cabeçalho, $campo/$rotulo/$titulo, ciência compartilhada).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';

$criar = $modo === 'criar';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2.5 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100 disabled:bg-background disabled:text-text-secondary';
$rotulo = 'block text-sm font-medium text-text-primary';
$titulo = 'text-sm font-bold uppercase tracking-wide text-text-secondary';
$concluido = !$criar && (string)$feedback['status'] === 'concluido';
$somenteLeitura = $concluido || !($podeAvaliar ?? true);
$disabled = $somenteLeitura ? 'disabled' : '';
$v = static fn(string $k): string => $criar ? '' : (string)($feedback[$k] ?? '');
?>
<div class="space-y-5">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'feedback', [
      'titulo' => $criar ? 'Novo Feedback' : 'Feedback — ' . (string)$feedback['snap_nome'],
      'descricao' => 'Reconhecimento, desenvolvimento, alinhamento ou acompanhamento — registro estruturado da conversa entre gestor e colaborador.',
  ], [['label' => $criar ? 'Novo Feedback' : (string)$feedback['snap_nome']]]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($_GET['ok'])): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e(Security::sanitizeString($_GET['ok'])) ?></div><?php endif; ?>

  <?php if ($criar): ?>
    <!-- Busca de colaborador -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Colaborador</h3>
      <?php if ($contrato === null): ?>
        <form method="get" action="<?= $base ?>/admin/feedbacks/novo" class="mt-2 flex flex-wrap items-end gap-2">
          <div class="flex-1 min-w-[220px]">
            <label for="busca" class="<?= $rotulo ?>">Buscar por nome</label>
            <input id="busca" type="text" name="busca" value="<?= Security::e($busca) ?>" class="<?= $campo ?>" placeholder="Nome do colaborador">
          </div>
          <button type="submit" class="<?= ui_btn('primario') ?>">Buscar</button>
        </form>
        <?php if ($contratosBusca !== []): ?>
          <ul class="mt-3 divide-y divide-border rounded-lg border border-border">
            <?php foreach ($contratosBusca as $c): ?>
              <li class="flex items-center justify-between p-3 text-sm">
                <span><?= Security::e((string)$c['nome']) ?> <span class="text-text-secondary">— <?= Security::e((string)($c['cargo'] ?: '—')) ?> · <?= Security::e((string)($c['empresa'] ?: '—')) ?></span></span>
                <a href="<?= $base ?>/admin/feedbacks/novo?metadados_id=<?= (int)$c['metadados_id'] ?>" class="<?= ui_btn('secundario') ?> h-8 px-3 text-xs">Selecionar</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($busca !== ''): ?>
          <p class="mt-3 text-sm text-text-secondary">Nenhum colaborador ativo encontrado para "<?= Security::e($busca) ?>".</p>
        <?php endif; ?>
      <?php else: ?>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div><dt class="text-xs text-text-secondary">Colaborador</dt><dd class="font-medium"><?= Security::e((string)$contrato['nome']) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Cargo</dt><dd><?= Security::e((string)($contrato['cargo'] ?: '—')) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Setor</dt><dd><?= Security::e((string)($contrato['setor'] ?: '—')) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Empresa</dt><dd><?= Security::e((string)($contrato['empresa'] ?: '—')) ?></dd></div>
        </dl>
        <a href="<?= $base ?>/admin/feedbacks/novo" class="mt-2 inline-block text-xs text-primary-700 hover:underline">Trocar colaborador</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$criar || $contrato !== null): ?>
  <form method="post" action="<?= $criar ? ($base . '/admin/feedbacks') : ($base . '/admin/feedbacks/' . (int)$feedback['id'] . '/editar') ?>" class="space-y-5">
    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <?php if ($criar): ?><input type="hidden" name="metadados_id" value="<?= (int)$contrato['metadados_id'] ?>"><?php endif; ?>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Identificação</h3>
      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php if ($criar && $escopoTotal): ?>
          <div>
            <label for="gestor_usuario_id" class="<?= $rotulo ?>">Gestor responsável</label>
            <select id="gestor_usuario_id" name="gestor_usuario_id" class="<?= $campo ?>">
              <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id'] ?>" <?= !empty($gestorSugerido) && (int)$gestorSugerido['id'] === (int)$u['id'] ? 'selected' : '' ?>><?= Security::e((string)$u['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php elseif (!$criar): ?>
          <div><dt class="text-xs text-text-secondary"></dt><label class="<?= $rotulo ?>">Gestor responsável</label><p class="mt-1 rounded-lg bg-background px-3 py-2.5 text-sm"><?= Security::e((string)$feedback['gestor_nome_snapshot']) ?></p></div>
        <?php endif; ?>
        <div>
          <label for="data_feedback" class="<?= $rotulo ?>">Data do Feedback <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="data_feedback" type="date" name="data_feedback" value="<?= Security::e($criar ? date('Y-m-d') : $v('data_feedback')) ?>" <?= $disabled ?> required class="<?= $campo ?>">
        </div>
        <div>
          <label for="tipo" class="<?= $rotulo ?>">Tipo de Feedback <span class="text-danger" aria-hidden="true">*</span></label>
          <select id="tipo" name="tipo" <?= $disabled ?> required class="<?= $campo ?>">
            <option value="">Selecione…</option>
            <?php foreach (FeedbackService::TIPOS as $k => $l): ?><option value="<?= $k ?>" <?= (!$criar && (string)$feedback['tipo'] === $k) ? 'selected' : '' ?>><?= Security::e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Pontos fortes</h3>
      <p class="mt-1 text-xs text-text-secondary">Quais comportamentos, atitudes ou resultados positivos o colaborador demonstrou neste período?</p>
      <textarea name="pontos_fortes" rows="4" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('pontos_fortes')) ?></textarea>
    </section>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Pontos de desenvolvimento</h3>
      <p class="mt-1 text-xs text-text-secondary">Quais comportamentos, habilidades ou atitudes podem ser desenvolvidos para fortalecer sua atuação?</p>
      <textarea name="pontos_desenvolvimento" rows="4" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('pontos_desenvolvimento')) ?></textarea>
    </section>

    <!-- Valores culturais -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Valores culturais</h3>
      <div class="mt-3 space-y-4">
        <?php foreach (FeedbackService::VALORES_CULTURAIS as $valorSlug => $grupo): $atual = $valores[$valorSlug] ?? ['avaliacao' => null, 'comentario' => null]; ?>
          <div class="rounded-lg border border-border p-3" data-valor-cultural="<?= $valorSlug ?>">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h4 class="text-sm font-bold text-text-primary"><?= Security::e($grupo['label']) ?></h4>
              <div class="flex gap-2">
                <?php foreach (FeedbackService::AVALIACAO_OPCOES as $k => $l): ?>
                  <label class="cursor-pointer">
                    <input type="radio" name="valor_<?= $valorSlug ?>" value="<?= $k ?>" <?= $atual['avaliacao'] === $k ? 'checked' : '' ?> <?= $disabled ?> data-valor-radio class="peer sr-only">
                    <span class="inline-flex h-8 items-center rounded-md border border-border px-3 text-xs font-semibold text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <details class="mt-2">
              <summary class="cursor-pointer text-xs font-semibold text-primary-700">ℹ️ Ver comportamentos esperados</summary>
              <div class="mt-1.5 rounded-md bg-background p-2.5 text-xs text-text-secondary">
                <p><?= Security::e($grupo['descricao']) ?></p>
                <ul class="mt-1.5 list-disc space-y-0.5 pl-4">
                  <?php foreach ($grupo['comportamentos'] as $c): ?><li><?= Security::e($c) ?></li><?php endforeach; ?>
                </ul>
              </div>
            </details>
            <div class="mt-2">
              <label class="text-xs font-medium text-text-primary">Comentário <span class="aviso-comentario-obrigatorio hidden text-danger">(obrigatório — Desenvolvimento Necessário)</span></label>
              <textarea name="comentario_<?= $valorSlug ?>" rows="2" <?= $disabled ?> class="<?= $campo ?>"><?= Security::e((string)($atual['comentario'] ?? '')) ?></textarea>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php ui_script_pagina('feedback-valores.js'); ?>
    </section>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Próximos passos</h3>
      <p class="mt-1 text-xs text-text-secondary">Quais ações ou comportamentos são esperados para o próximo período? (poderá alimentar um PDI futuramente, mediante confirmação)</p>
      <textarea name="proximos_passos" rows="4" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('proximos_passos')) ?></textarea>
    </section>

    <?php if (!$somenteLeitura): ?>
      <button type="submit" class="<?= ui_btn('secundario') ?>"><?= $criar ? 'Criar rascunho' : 'Salvar rascunho' ?></button>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <?php if (!$criar): ?>
    <!-- Espaço do colaborador -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Espaço do colaborador</h3>
      <p class="mt-1 text-xs text-text-secondary">Gostaria de compartilhar alguma percepção, dificuldade, sugestão ou necessidade de apoio? Preenchido de forma assistida — não altera as respostas do gestor.</p>
      <?php if (!empty($feedback['espaco_colaborador'])): ?>
        <div class="mt-2 rounded-lg bg-background p-3 text-sm text-text-primary"><?= nl2br(Security::e((string)$feedback['espaco_colaborador'])) ?></div>
        <p class="mt-1 text-xs text-text-secondary">Registrado em <?= Security::e(date('d/m/Y H:i', strtotime((string)$feedback['espaco_colaborador_preenchido_em']))) ?></p>
      <?php elseif ($podeAvaliar ?? true): ?>
        <form method="post" action="<?= $base ?>/admin/feedbacks/<?= (int)$feedback['id'] ?>/espaco-colaborador" class="mt-2 space-y-2">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <textarea name="espaco_colaborador" rows="3" class="<?= $campo ?>" required></textarea>
          <button type="submit" class="<?= ui_btn('secundario') ?>">Registrar relato do colaborador</button>
        </form>
      <?php else: ?>
        <p class="mt-2 text-sm text-text-muted">Ainda não preenchido.</p>
      <?php endif; ?>
    </section>

    <!-- Encerramento -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Encerramento</h3>
      <?php if ($concluido): ?>
        <dl class="mt-2 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-xs text-text-secondary">Resultado geral</dt><dd class="font-semibold"><?= Security::e(FeedbackService::RESULTADO_OPCOES[$feedback['resultado_geral']] ?? '—') ?></dd></div>
          <?php if (!empty($feedback['observacoes_finais'])): ?><div class="sm:col-span-2"><dt class="text-xs text-text-secondary">Observações finais</dt><dd><?= nl2br(Security::e((string)$feedback['observacoes_finais'])) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($podeAvaliar ?? false): ?>
          <form method="post" action="<?= $base ?>/admin/feedbacks/<?= (int)$feedback['id'] ?>/reabrir" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <div class="flex-1 min-w-[220px]">
              <label class="text-xs font-medium text-text-primary">Justificativa para reabrir (Admin/RH)</label>
              <input type="text" name="justificativa" class="<?= $campo ?>" required>
            </div>
            <button type="submit" class="<?= ui_btn('destrutivo') ?>">Reabrir feedback</button>
          </form>
        <?php endif; ?>
      <?php elseif ($podeAvaliar ?? true): ?>
        <form method="post" action="<?= $base ?>/admin/feedbacks/<?= (int)$feedback['id'] ?>/concluir" class="mt-2 space-y-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div>
            <label class="<?= $rotulo ?>">Resultado Geral <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="mt-1 flex flex-wrap gap-2">
              <?php foreach (FeedbackService::RESULTADO_OPCOES as $k => $l): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="resultado_geral" value="<?= $k ?>" required class="peer sr-only">
                  <span class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <label for="observacoes_finais" class="<?= $rotulo ?>">Observações Finais</label>
            <textarea id="observacoes_finais" name="observacoes_finais" rows="3" class="<?= $campo ?>"></textarea>
          </div>
          <button type="submit" class="<?= ui_btn('primario') ?>">Concluir feedback</button>
        </form>
      <?php endif; ?>
    </section>

    <?php
      $documentoTipo = 'feedback';
      $documentoId = (int)$feedback['id'];
      $status = (string)$feedback['status'];
      $nomeColaborador = (string)$feedback['snap_nome'];
      $acaoCiencia = $base . '/admin/feedbacks/' . $documentoId . '/ciencia';
      $podeRegistrarCiencia = $podeAvaliar ?? true;
      include APP_PATH . '/views/admin/partials/avaliacoes-desenvolvimento/_ciencia.php';
      $concluida = $concluido;
      include APP_PATH . '/views/admin/partials/avaliacoes-desenvolvimento/_pdi.php';
    ?>
  <?php endif; ?>
</div>
