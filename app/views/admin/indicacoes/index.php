<?php
$podeOperar = $podeOperar ?? true; // exportar e registrar/editar pagamento seguem admin/rh (o controller informa)
$sinalV2 = static function (array $s): array {
    $mapa = ['bg-ctgreen' => 'bg-success', 'bg-ctlight' => 'bg-warning', 'bg-ctdark' => 'bg-text-muted', 'text-ctgreen' => 'text-success', 'text-ctlight' => 'text-warning', 'text-ctdark' => 'text-text-secondary'];
    $s['dot'] = $mapa[$s['dot'] ?? ''] ?? ($s['dot'] ?? 'bg-text-muted');
    $s['text'] = $mapa[$s['text'] ?? ''] ?? ($s['text'] ?? 'text-text-primary');
    return $s;
};
require_once APP_PATH . '/views/partials/modulo-topo.php';
$queryBase = $base . '/admin/indicacoes';
$q = $filters['q'] ?? '';
$pagamento = $filters['pagamento'] ?? '';
$experiencia = $filters['experiencia'] ?? '';
$dataDe = $filters['data_de'] ?? '';
$dataAte = $filters['data_ate'] ?? '';
$indicador = $filters['indicador'] ?? '';
$params = [];
if ($q !== '') { $params['q'] = $q; }
if ($pagamento !== '') { $params['pagamento'] = $pagamento; }
if ($experiencia !== '') { $params['experiencia'] = $experiencia; }
if ($dataDe !== '') { $params['data_de'] = $dataDe; }
if ($dataAte !== '') { $params['data_ate'] = $dataAte; }
if ($indicador !== '') { $params['indicador'] = $indicador; }
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'indicacoes', [
      'titulo' => 'Programa de Indicações',
      'descricao' => 'Indicados cadastrados: ' . (int)$total,
  ]) ?>
  <div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mb-4 rounded-ds-md border border-danger/30 bg-danger/10 text-danger px-4 py-2 text-sm"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mb-4 rounded-ds-md border border-success/30 bg-success/10 text-success px-4 py-2 text-sm"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <form class="responsive-form-grid-6 mt-4" method="get" action="<?= $queryBase ?>">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-text-primary">Busca</label>
      <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome do candidato ou vaga" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-text-primary">Indicador</label>
      <input type="text" name="indicador" value="<?= Security::e($indicador) ?>" placeholder="Nome do colaborador indicador" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Pagamento</label>
      <select name="pagamento" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="pendente" <?= $pagamento === 'pendente' ? 'selected' : '' ?>>Pendente</option>
        <option value="pago" <?= $pagamento === 'pago' ? 'selected' : '' ?>>Pago</option>
        <option value="cancelado" <?= $pagamento === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
        <option value="em processo" <?= $pagamento === 'em processo' ? 'selected' : '' ?>>Em processo</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Experiência</label>
      <select name="experiencia" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="em_experiencia" <?= $experiencia === 'em_experiencia' ? 'selected' : '' ?>>Em experiência</option>
        <option value="concluida" <?= $experiencia === 'concluida' ? 'selected' : '' ?>>Concluída</option>
        <option value="nao_contratado" <?= $experiencia === 'nao_contratado' ? 'selected' : '' ?>>Não contratado</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">De</label>
      <input type="date" name="data_de" value="<?= Security::e($dataDe) ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Até</label>
      <input type="date" name="data_ate" value="<?= Security::e($dataAte) ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div class="md:col-span-6">
      <div class="responsive-form-actions">
      <button class="<?= ui_btn('primario') ?>">Filtrar</button>
      <a href="<?= $queryBase ?>" class="<?= ui_btn('ghost') ?>">Limpar</a>
      <?php if ($podeOperar): ?>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'excel'])) ?>" class="<?= ui_btn('secundario') ?>">Exportar Excel</a>
      <?php endif; ?>
      </div>
    </div>
  </form>

  <div class="hidden md:block mt-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-surface-secondary">
      <tr class="border-b">
        <th class="text-left p-3 font-medium text-text-secondary">Nome</th>
        <th class="text-left p-3 font-medium text-text-secondary">Colaborador</th>
        <th class="text-left p-3 font-medium text-text-secondary">Vaga</th>
        <th class="text-left p-3 font-medium text-text-secondary">Candidatura</th>
        <th class="text-left p-3 font-medium text-text-secondary">Etapa atual</th>
        <th class="text-left p-3 font-medium text-text-secondary">Contratado em</th>
        <th class="text-left p-3 font-medium text-text-secondary">Tempo</th>
        <th class="text-left p-3 font-medium text-text-secondary">Experiência (90 dias)</th>
        <th class="text-left p-3 font-medium text-text-secondary">Pagamento</th>
        <th class="text-left p-3 font-medium text-text-secondary">Ações</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-border">
      <?php foreach ($items as $item): ?>
        <?php
        $contratacao = $item['indicacao_data_contratacao'] ?? null;
        $fimExp = $item['indicacao_data_fim_experiencia'] ?? null;
        $dias = isset($item['dias_desde_contratacao']) ? (int)$item['dias_desde_contratacao'] : null;
        $expStatus = 'Não contratado';
        if (!empty($contratacao)) {
            $expStatus = (!empty($fimExp) && strtotime(date('Y-m-d')) > strtotime((string)$fimExp)) ? 'Experiência concluída' : 'Em experiência';
        }
        $pago = (int)($item['indicacao_pagamento_realizado'] ?? 0) === 1;
        $signal = $sinalV2($item['payment_signal'] ?? ['dot' => 'bg-text-muted', 'text' => 'text-text-primary']);
        $stageCor = $item['stage_cor'] ?? '#6b7280';
        $stageCorNormalized = strtolower(trim((string)$stageCor));
        if (in_array($stageCorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
            $stageCor = 'var(--color-primary-700)';
        }
        ?>
        <tr class="hover:bg-surface-secondary">
          <td class="p-3 font-medium text-text-primary"><?= Security::e($item['nome']) ?></td>
          <td class="p-3 text-text-primary"><?= Security::e($item['indicacao_colaborador_nome'] ?? '-') ?></td>
          <td class="p-3 text-text-primary"><?= Security::e($item['vaga_titulo'] ?? '-') ?></td>
          <td class="p-3 text-text-secondary"><?= !empty($item['created_at']) ? date('d/m/Y H:i', strtotime((string)$item['created_at'])) : '-' ?></td>
          <td class="p-3"><span class="whitespace-nowrap px-2 py-1 rounded-ds-sm text-xs font-semibold text-white" style="background-color: <?= Security::e($stageCor) ?>"><?= Security::e($item['stage_nome'] ?? 'Novo') ?></span></td>
          <td class="p-3 text-text-primary"><?= !empty($contratacao) ? date('d/m/Y', strtotime((string)$contratacao)) : '-' ?></td>
          <td class="p-3 text-text-primary"><?= $dias !== null ? $dias . ' dias' : '-' ?></td>
          <td class="p-3 text-text-primary"><?= Security::e($expStatus) ?></td>
          <td class="p-3">
            <span class="inline-flex items-center gap-2 text-xs font-semibold <?= Security::e($signal['text'] ?? 'text-text-primary') ?>">
              <span class="h-2.5 w-2.5 rounded-full <?= Security::e($signal['dot'] ?? 'bg-text-muted') ?>"></span>
              <?= $pago ? 'Pago em ' . date('d/m/Y', strtotime((string)$item['indicacao_data_pagamento'])) : 'Pendente' ?>
            </span>
          </td>
          <td class="p-3 whitespace-nowrap">
            <a href="<?= $base ?>/admin/candidaturas/<?= (int)$item['id'] ?>" class="text-primary-700 hover:text-primary-800 font-medium">Detalhes</a>
            <?php if (!$podeOperar): /* somente leitura: pagamento é admin/rh */ ?><?php elseif (!$pago): ?>
              <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar" method="post" class="inline ml-2" data-pagamento-form="<?= (int)$item['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <input type="hidden" name="payment_date" value="" data-payment-date-hidden="<?= (int)$item['id'] ?>">
                <input type="hidden" name="payment_method" value="" data-payment-method-hidden="<?= (int)$item['id'] ?>">
                <button type="button" class="text-primary-700 hover:text-primary-800 font-medium" data-pagamento-open="<?= (int)$item['id'] ?>">Marcar pago</button>
              </form>
            <?php else: ?>
              <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar/editar-data" method="post" class="inline ml-2" data-pagamento-edit-form="<?= (int)$item['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <input type="hidden" name="payment_date_edit" value="" data-payment-edit-date-hidden="<?= (int)$item['id'] ?>">
                <input type="hidden" name="payment_edit_reason" value="" data-payment-edit-reason-hidden="<?= (int)$item['id'] ?>">
                <button type="button" class="text-primary-700 hover:text-primary-800 font-medium" data-pagamento-edit-open="<?= (int)$item['id'] ?>">Editar data</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($items)): ?>
        <tr>
          <td colspan="10" class="p-6 text-center text-text-secondary">Nenhum candidato indicado encontrado.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($items as $item): ?>
      <?php
      $contratacao = $item['indicacao_data_contratacao'] ?? null;
      $fimExp = $item['indicacao_data_fim_experiencia'] ?? null;
      $dias = isset($item['dias_desde_contratacao']) ? (int)$item['dias_desde_contratacao'] : null;
      $expStatus = 'Não contratado';
      if (!empty($contratacao)) {
          $expStatus = (!empty($fimExp) && strtotime(date('Y-m-d')) > strtotime((string)$fimExp)) ? 'Experiência concluída' : 'Em experiência';
      }
      $pago = (int)($item['indicacao_pagamento_realizado'] ?? 0) === 1;
      $signal = $sinalV2($item['payment_signal'] ?? ['dot' => 'bg-text-muted', 'text' => 'text-text-primary']);
      ?>
      <div class="responsive-card">
        <div class="font-semibold text-text-primary"><?= Security::e($item['nome']) ?></div>
        <div class="text-sm text-text-secondary">Indicado por: <?= Security::e($item['indicacao_colaborador_nome'] ?? '-') ?></div>
        <div class="text-sm text-text-secondary"><?= Security::e($item['vaga_titulo'] ?? '-') ?></div>
        <div class="mt-2 text-xs text-text-secondary">Etapa: <?= Security::e($item['stage_nome'] ?? 'Novo') ?></div>
        <div class="mt-1 text-xs text-text-secondary">Contratado em: <?= !empty($contratacao) ? date('d/m/Y', strtotime((string)$contratacao)) : '-' ?></div>
        <div class="mt-1 text-xs text-text-secondary">Tempo: <?= $dias !== null ? $dias . ' dias' : '-' ?></div>
        <div class="mt-1 text-xs text-text-secondary">Experiência: <?= Security::e($expStatus) ?></div>
        <div class="mt-1 text-xs <?= Security::e($signal['text'] ?? 'text-text-primary') ?>">Pagamento: <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full <?= Security::e($signal['dot'] ?? 'bg-text-muted') ?>"></span><?= $pago ? 'Pago em ' . date('d/m/Y', strtotime((string)$item['indicacao_data_pagamento'])) : 'Pendente' ?></span></div>
        <div class="responsive-card-actions mt-3 text-sm">
          <a href="<?= $base ?>/admin/candidaturas/<?= (int)$item['id'] ?>" class="text-primary-700 hover:text-primary-800 font-medium">Detalhes</a>
          <?php if (!$podeOperar): /* somente leitura: pagamento é admin/rh */ ?><?php elseif (!$pago): ?>
            <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar" method="post" data-pagamento-form="<?= (int)$item['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <input type="hidden" name="payment_date" value="" data-payment-date-hidden="<?= (int)$item['id'] ?>">
              <input type="hidden" name="payment_method" value="" data-payment-method-hidden="<?= (int)$item['id'] ?>">
              <button type="button" class="text-primary-700 hover:text-primary-800 font-medium" data-pagamento-open="<?= (int)$item['id'] ?>">Marcar pago</button>
            </form>
          <?php else: ?>
            <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar/editar-data" method="post" data-pagamento-edit-form="<?= (int)$item['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <input type="hidden" name="payment_date_edit" value="" data-payment-edit-date-hidden="<?= (int)$item['id'] ?>">
              <input type="hidden" name="payment_edit_reason" value="" data-payment-edit-reason-hidden="<?= (int)$item['id'] ?>">
              <button type="button" class="text-primary-700 hover:text-primary-800 font-medium" data-pagamento-edit-open="<?= (int)$item['id'] ?>">Editar data</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (($pages ?? 1) > 1): ?>
    <div class="responsive-pagination mt-6 text-sm">
      <div class="text-text-secondary">Página <?= (int)$page ?> de <?= (int)$pages ?></div>
      <div class="responsive-form-actions">
        <?php
        $prev = max(1, (int)$page - 1);
        $next = min((int)$pages, (int)$page + 1);
        $prevParams = array_merge($params, ['page' => $prev]);
        $nextParams = array_merge($params, ['page' => $next]);
        ?>
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Anterior</a>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Próxima</a>
      </div>
    </div>
  <?php endif; ?>
</div>
</div>
<style>
  .ind-modal-overlay { position: fixed; inset: 0; z-index: 50; display: none; align-items: flex-end; justify-content: center; background: rgba(0, 0, 0, 0.45); padding: 4vw; }
  .ind-modal-overlay.is-open { display: flex; }
  .ind-modal-panel { width: 100%; max-width: 42rem; max-height: 88vh; overflow-y: auto; background: #fff; border-radius: 1rem; box-shadow: 0 16px 32px rgba(0, 0, 0, 0.18); padding: 1rem; }
  .ind-modal-title { color: var(--color-text-primary); font-size: 1.25rem; line-height: 1.4; font-weight: 700; }
  .ind-modal-text { color: var(--color-text-secondary); font-size: 0.9375rem; margin-top: 0.25rem; }
  .ind-modal-field { margin-top: 0.75rem; }
  .ind-modal-input { width: 100%; border: 1px solid var(--color-border); border-radius: 0.5rem; padding: 0.75rem; font-size: 1rem; min-height: 2.75rem; }
  .ind-modal-actions { position: sticky; bottom: 0; display: grid; grid-template-columns: 1fr; gap: 0.5rem; background: #fff; padding-top: 0.75rem; margin-top: 0.75rem; }
  .ind-modal-btn { min-height: 2.75rem; padding: 0.625rem 1rem; border-radius: 0.5rem; font-size: 0.9375rem; font-weight: 600; }
  .ind-modal-btn-secondary { border: 1px solid var(--color-border); color: var(--color-text-primary); background: #fff; }
  .ind-modal-btn-primary-green { color: #fff; background: var(--color-primary-700); }
  .ind-modal-btn-primary-indigo { color: #fff; background: var(--color-primary-700); }
  @media (min-width: 48rem) {
    .ind-modal-overlay { align-items: center; padding: 2rem; }
    .ind-modal-panel { padding: 1.25rem 1.5rem; max-height: 84vh; }
    .ind-modal-actions { grid-template-columns: 1fr 1fr; }
  }
  @media (min-width: 64rem) {
    .ind-modal-panel { max-width: 44rem; padding: 1.5rem; }
  }
</style>
<div id="pagamento-modal" class="ind-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" aria-describedby="payment-modal-desc">
  <div class="ind-modal-panel" tabindex="-1">
    <h3 id="payment-modal-title" class="ind-modal-title">Registrar pagamento</h3>
    <p id="payment-modal-desc" class="ind-modal-text">Informe a data efetiva do pagamento.</p>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-text-primary" for="payment-date-input">Data do pagamento</label>
      <input id="payment-date-input" type="text" inputmode="numeric" maxlength="10" class="ind-modal-input" placeholder="DD/MM/AAAA">
      <p id="payment-date-error" class="text-danger text-xs mt-1 hidden" aria-live="polite"></p>
    </div>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-text-primary" for="payment-method-input">Método de pagamento</label>
      <select id="payment-method-input" class="ind-modal-input">
        <option value="">Selecione</option>
        <option value="PIX">PIX</option>
        <option value="Transferência">Transferência</option>
        <option value="TED">TED</option>
        <option value="DOC">DOC</option>
        <option value="Dinheiro">Dinheiro</option>
        <option value="Outro">Outro</option>
      </select>
    </div>
    <div class="ind-modal-actions">
      <button type="button" id="payment-cancel" class="ind-modal-btn ind-modal-btn-secondary">Cancelar</button>
      <button type="button" id="payment-confirm" class="ind-modal-btn ind-modal-btn-primary-green">Confirmar</button>
    </div>
  </div>
</div>
<div id="pagamento-edit-modal" class="ind-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="payment-edit-modal-title" aria-describedby="payment-edit-modal-desc">
  <div class="ind-modal-panel" tabindex="-1">
    <h3 id="payment-edit-modal-title" class="ind-modal-title">Editar data de pagamento</h3>
    <p id="payment-edit-modal-desc" class="ind-modal-text">Atualize a data e informe o motivo da alteração.</p>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-text-primary" for="payment-edit-date-input">Nova data (DD/MM/AAAA)</label>
      <input id="payment-edit-date-input" type="text" inputmode="numeric" maxlength="10" class="ind-modal-input" placeholder="DD/MM/AAAA">
    </div>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-text-primary" for="payment-edit-reason-input">Motivo da alteração</label>
      <textarea id="payment-edit-reason-input" rows="3" class="ind-modal-input" placeholder="Descreva o motivo"></textarea>
      <p id="payment-edit-error" class="text-danger text-xs mt-1 hidden" aria-live="polite"></p>
    </div>
    <div class="ind-modal-actions">
      <button type="button" id="payment-edit-cancel" class="ind-modal-btn ind-modal-btn-secondary">Cancelar</button>
      <button type="button" id="payment-edit-confirm" class="ind-modal-btn ind-modal-btn-primary-indigo">Salvar alteração</button>
    </div>
  </div>
</div>
<?php ui_script_pagina('indicacoes.js'); // JS movido para assets/indicacoes.js (CSP: sem <script> inline) ?>
