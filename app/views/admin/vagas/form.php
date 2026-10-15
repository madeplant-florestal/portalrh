<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$editing = !empty($vaga);
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'vagas', [
      'titulo' => $editing ? 'Editar vaga' : 'Nova vaga',
      'descricao' => 'Cadastro manual de vaga (exceção ao fluxo Solicitação de Vaga → aprovação → rascunho → publicar).',
  ], [['label' => $editing ? 'Editar vaga' : 'Nova vaga', 'href' => null]]) ?>
  <div class="responsive-panel max-w-3xl">
  <?php if (!empty($error)): ?>
    <div class="mt-3 bg-danger/10 border border-danger/30 text-danger px-3 py-2 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <form class="mt-4 space-y-3" action="<?= $editing ? $base . '/admin/vagas/editar/' . (int)$vaga['id'] : $base . '/admin/vagas/novo' ?>" method="post">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <div>
      <label class="block text-ds-label text-text-primary">Título</label>
      <input type="text" name="titulo" value="<?= Security::e($vaga['titulo'] ?? '') ?>" required class="mt-1 w-full border rounded px-3 py-2" />
    </div>
    <div>
      <label class="block text-ds-label text-text-primary">Descrição</label>
      <textarea name="descricao" rows="4" class="mt-1 w-full border rounded px-3 py-2" required><?= Security::e($vaga['descricao'] ?? '') ?></textarea>
    </div>
    <div>
      <label class="block text-ds-label text-text-primary">Requisitos</label>
      <textarea name="requisitos" rows="3" class="mt-1 w-full border rounded px-3 py-2" required><?= Security::e($vaga['requisitos'] ?? '') ?></textarea>
    </div>
    <div class="grid gap-3 md:grid-cols-2">
      <div>
        <label class="block text-ds-label text-text-primary">Área</label>
        <input type="text" name="area" value="<?= Security::e($vaga['area'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
      </div>
      <div>
        <label class="block text-ds-label text-text-primary">Local</label>
        <input type="text" name="local" value="<?= Security::e($vaga['local'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
      </div>
    </div>
    <div>
      <label class="block text-ds-label text-text-primary">Empresa / Tenant</label>
      <select name="empresa_id" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">Usar tenant padrão do recrutamento</option>
        <?php foreach (($empresas ?? []) as $empresa): ?>
          <option value="<?= (int)$empresa['id'] ?>" <?= (int)($vaga['empresa_id'] ?? 0) === (int)$empresa['id'] ? 'selected' : '' ?>>
            <?= Security::e($empresa['nome']) ?><?= (int)($empresa['ativo'] ?? 1) === 1 ? '' : ' (inativa)' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-text-secondary">Quando a vaga estiver vinculada a uma empresa, o webhook utiliza esse tenant; caso contrário, utiliza a configuração padrão.</p>
    </div>
    <div>
      <label class="block text-ds-label text-text-primary">Benefícios oferecidos nesta vaga</label>
      <p class="mt-1 text-xs text-text-secondary">Selecione somente os benefícios aplicáveis a esta vaga. Apenas os itens marcados serão exibidos para os candidatos na página pública.</p>
      <?php $beneficiosCatalogo = $beneficiosCatalogo ?? []; $beneficioIdsSelecionados = $beneficioIdsSelecionados ?? []; ?>
      <?php if ($beneficiosCatalogo === []): ?>
        <p class="mt-2 text-sm text-text-secondary">Nenhum benefício ativo cadastrado no catálogo.</p>
      <?php else: ?>
        <div class="mt-2 grid gap-3 md:grid-cols-2">
          <?php foreach ($beneficiosCatalogo as $beneficio): ?>
            <label class="flex items-start gap-2 rounded border px-3 py-2 text-sm">
              <input type="checkbox" name="beneficio_ids[]" value="<?= (int)$beneficio['id'] ?>" <?= in_array((int)$beneficio['id'], $beneficioIdsSelecionados, true) ? 'checked' : '' ?> class="mt-0.5" />
              <span>
                <span class="font-medium text-text-primary"><?= Security::e($beneficio['nome']) ?></span>
                <?php if (!empty($beneficio['parceiro'])): ?>
                  <span class="text-text-muted"> • <?= Security::e($beneficio['parceiro']) ?></span>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <label class="inline-flex items-center space-x-2">
      <input type="checkbox" name="ativo" <?= !empty($vaga['ativo']) ? 'checked' : '' ?> />
      <span class="text-sm">Ativo</span>
    </label>
    <div class="responsive-form-actions pt-2">
      <button class="<?= ui_btn('primario') ?>">Salvar</button>
      <a href="<?= $base ?>/admin/vagas" class="<?= ui_btn('ghost') ?>">Cancelar</a>
    </div>
  </form>
  </div>
</div>
