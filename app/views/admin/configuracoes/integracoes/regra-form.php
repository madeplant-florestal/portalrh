<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isEdit = ($mode ?? 'create') === 'edit';
$regra = $regra ?? [];
$eventos = $eventos ?? [];
$etapas = $etapas ?? [];
$mensagensAtivas = $mensagensAtivas ?? [];
$eventoSelecionado = (string)($regra['event_type'] ?? '');
$etapaSelecionada = (string)($regra['pipeline_stage_id'] ?? '');
$mensagemSelecionada = (string)($regra['mensagem_id'] ?? '');
$canalSelecionado = (string)($regra['canal'] ?? 'whatsapp');
?>
<div class="space-y-4 max-w-2xl">
  <?= ui_modulo_topo($base, 'configuracoes', 'integracoes', [
      'titulo' => $isEdit ? 'Editar regra de comunicação' : 'Nova regra de comunicação',
      'descricao' => 'Associa um evento (e etapa, quando aplicável) a um modelo já cadastrado em Mensagens.',
  ], [['label' => $isEdit ? 'Editar regra' : 'Nova regra']]) ?>
  <div class="responsive-panel">

  <?php if (!empty($error)): ?>
    <div class="mb-4 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= $isEdit ? '/admin/configuracoes/integracoes/regras/' . (int)$regra['id'] . '/editar' : '/admin/configuracoes/integracoes/regras/nova' ?>" method="post" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

    <div>
      <label class="block text-sm font-medium text-text-primary">Evento *</label>
      <select name="event_type" required class="mt-1 w-full rounded-xl border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Selecione...</option>
        <?php foreach ($eventos as $codigo => $info): ?>
          <option value="<?= Security::e($codigo) ?>" <?= $eventoSelecionado === $codigo ? 'selected' : '' ?>><?= Security::e($info['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-text-secondary">"Movimentação de etapa" exige a seleção de uma etapa abaixo; "Candidatura criada" nunca tem etapa.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Etapa do pipeline</label>
      <select name="pipeline_stage_id" class="mt-1 w-full rounded-xl border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">— Nenhuma (evento sem etapa) —</option>
        <?php foreach ($etapas as $etapa): ?>
          <option value="<?= (int)$etapa['id'] ?>" <?= $etapaSelecionada === (string)$etapa['id'] ? 'selected' : '' ?>><?= Security::e((string)$etapa['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Modelo de mensagem *</label>
      <select name="mensagem_id" required class="mt-1 w-full rounded-xl border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Selecione...</option>
        <?php foreach ($mensagensAtivas as $mensagem): ?>
          <option value="<?= (int)$mensagem['id'] ?>" <?= $mensagemSelecionada === (string)$mensagem['id'] ? 'selected' : '' ?>>
            <?= Security::e((string)$mensagem['titulo']) ?> (<?= Security::e((string)$mensagem['codigo']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-text-secondary">Só modelos ativos de <a href="<?= $base ?>/admin/configuracoes/mensagens" class="text-primary-700 underline hover:text-primary-800">Mensagens</a> aparecem aqui. O texto do modelo continua sendo editado só lá.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Canal *</label>
      <select name="canal" required class="mt-1 w-full rounded-xl border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="whatsapp" <?= $canalSelecionado === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
      </select>
    </div>

    <div class="flex items-center">
      <input type="checkbox" id="ativo" name="ativo" <?= (int)($regra['ativo'] ?? 1) === 1 ? 'checked' : '' ?> class="mr-2">
      <label for="ativo" class="text-sm text-text-primary">Ativa</label>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Salvar</button>
      <a href="<?= $base ?>/admin/configuracoes/integracoes" class="text-primary-700 hover:text-primary-700">Cancelar</a>
    </div>
  </form>
  </div>
</div>
