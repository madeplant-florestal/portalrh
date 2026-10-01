<?php
/**
 * Formulário da Avaliação de Desempenho NATIVA (Etapa 6, 2026-09). Critérios em slots fixos
 * (`criterios[n][...]`, n de 1 a AvaliacaoDesempenhoService::MAX_CRITERIOS) — mesma convenção sem-JS
 * de admin/pdis/form.php (`acoes[n][...]`). Escala 1–5 SEMPRE exibida com os rótulos de
 * AvaliacaoDesempenhoService::ESCALA_LABELS (nunca só o número — §4). `resultado_final` é sempre
 * escolha explícita do avaliador, nunca sugerido a partir da média (§6).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';

$criar = $modo === 'criar';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2.5 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100 disabled:bg-background disabled:text-text-secondary';
$rotulo = 'block text-sm font-medium text-text-primary';
$titulo = 'text-sm font-bold uppercase tracking-wide text-text-secondary';
$concluida = !$criar && (string)$avaliacao['status'] === 'concluido';
$cancelada = !$criar && (string)$avaliacao['status'] === 'cancelado';
$somenteLeitura = $concluida || $cancelada || !($podeAvaliar ?? true);
$disabled = $somenteLeitura ? 'disabled' : '';
$v = static fn(string $k): string => $criar ? '' : (string)($avaliacao[$k] ?? '');
$existentes = array_values($criterios ?? []);
?>
<div class="space-y-5">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'avaliacao-desempenho', [
      'titulo' => $criar ? 'Nova Avaliação de Desempenho' : 'Avaliação de Desempenho — ' . (string)$avaliacao['snap_nome'],
      'descricao' => 'Avaliação estruturada por critérios/competências, com GAP entre nota atual e esperada, e resultado final explícito do avaliador.',
  ], [['label' => $criar ? 'Nova Avaliação' : (string)$avaliacao['snap_nome']]]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($_GET['ok'])): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e(Security::sanitizeString($_GET['ok'])) ?></div><?php endif; ?>

  <?php if ($criar): ?>
    <!-- Busca de colaborador -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Colaborador</h3>
      <?php if ($contrato === null): ?>
        <form method="get" action="<?= $base ?>/admin/avaliacoes-desempenho/novo" class="mt-2 flex flex-wrap items-end gap-2">
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
                <a href="<?= $base ?>/admin/avaliacoes-desempenho/novo?metadados_id=<?= (int)$c['metadados_id'] ?>" class="<?= ui_btn('secundario') ?> h-8 px-3 text-xs">Selecionar</a>
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
        <a href="<?= $base ?>/admin/avaliacoes-desempenho/novo" class="mt-2 inline-block text-xs text-primary-700 hover:underline">Trocar colaborador</a>
        <?php if (($avaliacoesAnteriores ?? []) !== []): ?>
          <p class="mt-3 text-xs text-text-secondary">Este colaborador já possui <?= count($avaliacoesAnteriores) ?> avaliação(ões) anterior(es) — uma nova avaliação extraordinária ainda é permitida.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$criar || $contrato !== null): ?>
  <form method="post" action="<?= $criar ? ($base . '/admin/avaliacoes-desempenho') : ($base . '/admin/avaliacoes-desempenho/' . (int)$avaliacao['id'] . '/editar') ?>" class="space-y-5">
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
          <div><label class="<?= $rotulo ?>">Gestor responsável</label><p class="mt-1 rounded-lg bg-background px-3 py-2.5 text-sm"><?= Security::e((string)$avaliacao['gestor_nome_snapshot']) ?></p></div>
        <?php endif; ?>
        <div>
          <label for="ciclo" class="<?= $rotulo ?>">Ciclo <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="ciclo" type="text" name="ciclo" maxlength="60" value="<?= Security::e($v('ciclo')) ?>" <?= $disabled ?> required class="<?= $campo ?>" placeholder="Ex.: 2026.2, Anual 2026">
        </div>
        <div>
          <label for="periodo_inicio" class="<?= $rotulo ?>">Período — início <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="periodo_inicio" type="date" name="periodo_inicio" value="<?= Security::e($v('periodo_inicio')) ?>" <?= $disabled ?> required class="<?= $campo ?>">
        </div>
        <div>
          <label for="periodo_fim" class="<?= $rotulo ?>">Período — fim <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="periodo_fim" type="date" name="periodo_fim" value="<?= Security::e($v('periodo_fim')) ?>" <?= $disabled ?> required class="<?= $campo ?>">
        </div>
      </div>
      <?php if (!$criar): ?>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4 border-t border-border pt-3">
          <div><dt class="text-xs text-text-secondary">Cargo</dt><dd><?= Security::e((string)($avaliacao['snap_cargo'] ?: '—')) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Setor</dt><dd><?= Security::e((string)($avaliacao['snap_setor'] ?: '—')) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Empresa</dt><dd><?= Security::e((string)($avaliacao['snap_empresa'] ?: '—')) ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Status</dt><dd><?= ui_badge(AvaliacaoDesempenhoService::ROTULOS_STATUS[$avaliacao['status']] ?? (string)$avaliacao['status'], ['rascunho' => 'info', 'concluido' => 'success', 'cancelado' => 'neutro'][$avaliacao['status']] ?? 'neutro') ?></dd></div>
        </dl>
      <?php endif; ?>
    </section>

    <!-- Critérios / competências -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Critérios avaliados</h3>
      <p class="mt-1 text-xs text-text-secondary">Escala: <?php foreach (AvaliacaoDesempenhoService::ESCALA_LABELS as $n => $l): ?><span class="mr-3"><strong><?= $n ?></strong> = <?= Security::e($l) ?></span><?php endforeach; ?></p>
      <p class="mt-1 text-xs text-text-secondary">Preencha um critério por linha (até <?= AvaliacaoDesempenhoService::MAX_CRITERIOS ?>). Deixe em branco o que não for usar.</p>

      <div class="mt-3 space-y-4">
        <?php for ($n = 1; $n <= AvaliacaoDesempenhoService::MAX_CRITERIOS; $n++): $cr = $existentes[$n - 1] ?? []; ?>
          <fieldset class="rounded-ds-md border border-border p-3">
            <legend class="px-1 text-xs font-semibold text-text-secondary">Critério <?= $n ?></legend>
            <div>
              <label for="criterio-<?= $n ?>-texto" class="<?= $rotulo ?>">Competência / critério</label>
              <input id="criterio-<?= $n ?>-texto" type="text" name="criterios[<?= $n ?>][competencia_texto]" maxlength="150" value="<?= Security::e((string)($cr['competencia_texto'] ?? '')) ?>" <?= $disabled ?> class="<?= $campo ?>">
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <span class="<?= $rotulo ?>">Nota atual</span>
                <div class="mt-1 flex gap-1" role="radiogroup" aria-label="Nota atual do critério <?= $n ?>">
                  <?php foreach (AvaliacaoDesempenhoService::ESCALA_LABELS as $nota => $l): ?>
                    <label class="cursor-pointer">
                      <input type="radio" name="criterios[<?= $n ?>][nota_atual]" value="<?= $nota ?>" <?= (int)($cr['nota_atual'] ?? 0) === $nota ? 'checked' : '' ?> <?= $disabled ?> class="peer sr-only">
                      <span class="flex h-8 w-8 items-center justify-center rounded-md border border-border text-xs font-semibold text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white" title="<?= Security::e($l) ?>"><?= $nota ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div>
                <span class="<?= $rotulo ?>">Nota esperada</span>
                <div class="mt-1 flex gap-1" role="radiogroup" aria-label="Nota esperada do critério <?= $n ?>">
                  <?php foreach (AvaliacaoDesempenhoService::ESCALA_LABELS as $nota => $l): ?>
                    <label class="cursor-pointer">
                      <input type="radio" name="criterios[<?= $n ?>][nota_esperada]" value="<?= $nota ?>" <?= (int)($cr['nota_esperada'] ?? 0) === $nota ? 'checked' : '' ?> <?= $disabled ?> class="peer sr-only">
                      <span class="flex h-8 w-8 items-center justify-center rounded-md border border-border text-xs font-semibold text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white" title="<?= Security::e($l) ?>"><?= $nota ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div class="mt-3">
              <label for="criterio-<?= $n ?>-comentario" class="text-xs font-medium text-text-primary">Comentário</label>
              <textarea id="criterio-<?= $n ?>-comentario" name="criterios[<?= $n ?>][comentario]" rows="2" <?= $disabled ?> class="<?= $campo ?>"><?= Security::e((string)($cr['comentario'] ?? '')) ?></textarea>
            </div>
          </fieldset>
        <?php endfor; ?>
      </div>
    </section>

    <?php if (!$criar && ($indicadores ?? null) !== null): ?>
      <!-- Indicadores derivados — SOMENTE EXIBIÇÃO, nunca persistidos nem viram resultado_final automaticamente -->
      <section class="rounded-ds-lg border border-border bg-white p-4">
        <h3 class="<?= $titulo ?>">Indicadores derivados <span class="font-normal normal-case text-text-muted">(calculados a partir dos critérios, apenas para referência)</span></h3>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
          <div><dt class="text-xs text-text-secondary">Média nota atual</dt><dd class="font-semibold"><?= $indicadores['media_nota_atual'] !== null ? Security::e(number_format((float)$indicadores['media_nota_atual'], 1, ',', '.')) : '—' ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Média nota esperada</dt><dd class="font-semibold"><?= $indicadores['media_nota_esperada'] !== null ? Security::e(number_format((float)$indicadores['media_nota_esperada'], 1, ',', '.')) : '—' ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Critérios com GAP</dt><dd class="font-semibold"><?= (int)$indicadores['qtd_com_gap'] ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Maior GAP</dt><dd class="font-semibold"><?= (int)$indicadores['maior_gap'] ?></dd></div>
          <div><dt class="text-xs text-text-secondary">% critérios atendidos</dt><dd class="font-semibold"><?= $indicadores['percentual_atendidos'] !== null ? Security::e(number_format((float)$indicadores['percentual_atendidos'], 1, ',', '.')) . '%' : '—' ?></dd></div>
        </dl>
      </section>
    <?php endif; ?>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Pontos fortes</h3>
      <textarea name="pontos_fortes" rows="3" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('pontos_fortes')) ?></textarea>
    </section>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">GAPs identificados <span class="font-normal normal-case text-text-muted">(resumo textual)</span></h3>
      <textarea name="gaps_identificados" rows="3" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('gaps_identificados')) ?></textarea>
    </section>

    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Plano de ação sugerido</h3>
      <p class="mt-1 text-xs text-text-secondary">Rascunho textual — não cria um PDI automaticamente nesta versão.</p>
      <textarea name="plano_acao_sugerido" rows="3" <?= $disabled ?> class="mt-2 <?= $campo ?>"><?= Security::e($v('plano_acao_sugerido')) ?></textarea>
    </section>

    <?php if (!$somenteLeitura): ?>
      <button type="submit" class="<?= ui_btn('secundario') ?>"><?= $criar ? 'Criar rascunho' : 'Salvar rascunho' ?></button>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <?php if (!$criar): ?>
    <!-- Encerramento -->
    <section class="rounded-ds-lg border border-border bg-white p-4">
      <h3 class="<?= $titulo ?>">Encerramento</h3>
      <?php if ($concluida): ?>
        <dl class="mt-2 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-xs text-text-secondary">Resultado final</dt><dd class="font-semibold"><?= Security::e(AvaliacaoDesempenhoService::RESULTADO_OPCOES[$avaliacao['resultado_final']] ?? '—') ?></dd></div>
          <div><dt class="text-xs text-text-secondary">Data da realização</dt><dd><?= Security::e(date('d/m/Y', strtotime((string)$avaliacao['data_realizacao']))) ?></dd></div>
          <?php if (!empty($avaliacao['parecer_comentario'])): ?>
            <div class="sm:col-span-2"><dt class="text-xs text-text-secondary">Parecer</dt><dd><?= nl2br(Security::e((string)$avaliacao['parecer_comentario'])) ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if ($podeAvaliar ?? false): ?>
          <form method="post" action="<?= $base ?>/admin/avaliacoes-desempenho/<?= (int)$avaliacao['id'] ?>/reabrir" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <div class="flex-1 min-w-[220px]">
              <label class="text-xs font-medium text-text-primary">Justificativa para reabrir (Admin/RH)</label>
              <input type="text" name="justificativa" class="<?= $campo ?>" required>
            </div>
            <button type="submit" class="<?= ui_btn('destrutivo') ?>">Reabrir avaliação</button>
          </form>
        <?php endif; ?>
      <?php elseif ($cancelada): ?>
        <p class="mt-2 text-sm text-text-secondary">Esta avaliação foi cancelada e não pode mais ser editada ou concluída.</p>
      <?php elseif ($podeAvaliar ?? true): ?>
        <form method="post" action="<?= $base ?>/admin/avaliacoes-desempenho/<?= (int)$avaliacao['id'] ?>/concluir" class="mt-2 space-y-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div>
            <label class="<?= $rotulo ?>">Resultado Final <span class="text-danger" aria-hidden="true">*</span></label>
            <p class="text-xs text-text-secondary">Julgamento do avaliador — as notas e o GAP são apoio, não substituem esta decisão.</p>
            <div class="mt-1 flex flex-wrap gap-2">
              <?php foreach (AvaliacaoDesempenhoService::RESULTADO_OPCOES as $k => $l): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="resultado_final" value="<?= $k ?>" required class="peer sr-only">
                  <span class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm text-text-secondary peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:text-white"><?= Security::e($l) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <label for="parecer_comentario" class="<?= $rotulo ?>">Parecer</label>
            <textarea id="parecer_comentario" name="parecer_comentario" rows="3" class="<?= $campo ?>"></textarea>
          </div>
          <button type="submit" class="<?= ui_btn('primario') ?>">Concluir avaliação</button>
        </form>

        <form method="post" action="<?= $base ?>/admin/avaliacoes-desempenho/<?= (int)$avaliacao['id'] ?>/cancelar" class="mt-4 flex flex-wrap items-end gap-2 border-t border-border pt-3">
          <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div class="flex-1 min-w-[220px]">
            <label class="text-xs font-medium text-text-primary">Motivo do cancelamento</label>
            <input type="text" name="motivo" class="<?= $campo ?>" required>
          </div>
          <button type="submit" class="<?= ui_btn('destrutivo') ?>">Cancelar avaliação</button>
        </form>
      <?php endif; ?>
    </section>

    <?php
      $documentoTipo = 'avaliacao_desempenho';
      $documentoId = (int)$avaliacao['id'];
      $status = (string)$avaliacao['status'];
      $nomeColaborador = (string)$avaliacao['snap_nome'];
      $acaoCiencia = $base . '/admin/avaliacoes-desempenho/' . $documentoId . '/ciencia';
      $podeRegistrarCiencia = $podeAvaliar ?? true;
      include APP_PATH . '/views/admin/partials/avaliacoes-desenvolvimento/_ciencia.php';
      include APP_PATH . '/views/admin/partials/avaliacoes-desenvolvimento/_pdi.php';
    ?>
  <?php endif; ?>
</div>
