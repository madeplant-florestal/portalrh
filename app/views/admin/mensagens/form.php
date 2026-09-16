<?php
$isEdit = ($mode ?? 'create') === 'edit';
?>
<div class="responsive-panel max-w-3xl">
  <h2 class="text-xl font-semibold text-ctpblue mb-4"><?= $isEdit ? 'Editar mensagem' : 'Nova mensagem' ?></h2>

  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 bg-red-50 text-red-600 border border-red-200 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= $isEdit ? '/admin/mensagens/editar/' . (int)$mensagem['id'] : '/admin/mensagens/novo' ?>" method="post" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>" />

    <div>
      <label class="block text-sm font-medium text-gray-700">Código técnico <?= $isEdit ? '' : '*' ?></label>
      <?php if ($isEdit): ?>
        <div class="mt-1 flex items-center gap-2">
          <code class="rounded bg-gray-100 px-3 py-2 text-sm text-gray-700"><?= Security::e((string)($mensagem['codigo'] ?? '')) ?></code>
          <span class="text-xs text-gray-500">Não editável após a criação.</span>
        </div>
      <?php else: ?>
        <input type="text" name="codigo" value="<?= Security::e((string)($mensagem['codigo'] ?? '')) ?>"
               pattern="[a-z0-9_]+" placeholder="ex.: convocacao_entrevista_rh"
               class="mt-1 w-full border rounded px-3 py-2 font-mono text-sm" required />
        <p class="mt-1 text-xs text-gray-500">Somente letras minúsculas, números e underscore. Não pode ser alterado depois de criado.</p>
      <?php endif; ?>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Título *</label>
      <input type="text" name="titulo" value="<?= Security::e((string)($mensagem['titulo'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" required />
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Descrição</label>
      <textarea name="descricao" rows="2" class="mt-1 w-full border rounded px-3 py-2"><?= Security::e((string)($mensagem['descricao'] ?? '')) ?></textarea>
      <p class="mt-1 text-xs text-gray-500">Uso interno — não é enviada ao candidato.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Conteúdo *</label>
      <textarea name="conteudo" rows="12" data-mensagem-conteudo="1"
                class="mt-1 w-full border rounded px-3 py-2 font-mono text-sm" required><?= Security::e((string)($mensagem['conteudo'] ?? '')) ?></textarea>
      <p class="mt-1 text-xs text-gray-500">
        Use <code>[Nome do Placeholder]</code> para marcar um dado a ser preenchido depois (ex.: <code>[Nome]</code>, <code>[Data]</code>, <code>[Horário]</code>).
        Quebras de linha e emojis são preservados.
      </p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
      <div class="text-sm font-medium text-gray-700">Variáveis encontradas</div>
      <div class="mt-2 flex flex-wrap gap-2" data-mensagem-placeholders="1">
        <span class="text-sm text-gray-400">Nenhum placeholder identificado.</span>
      </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4" data-mensagem-preview-wrap="1" hidden>
      <div class="text-sm font-medium text-gray-700">Pré-visualização</div>
      <p class="mt-1 text-xs text-gray-500">Opcional — preencha valores de exemplo para ver o texto final. Nada aqui é salvo.</p>
      <div class="mt-3 grid gap-2 sm:grid-cols-2" data-mensagem-preview-inputs="1"></div>
      <div class="mt-3 rounded border border-gray-200 bg-white p-3 text-sm text-gray-800 whitespace-pre-wrap" data-mensagem-preview-text="1"></div>
    </div>

    <div class="flex items-center">
      <input type="checkbox" id="ativo" name="ativo" <?= (int)($mensagem['ativo'] ?? 1) === 1 ? 'checked' : '' ?> class="mr-2" />
      <label for="ativo" class="text-sm text-gray-700">Ativa</label>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark">Salvar</button>
      <a href="<?= $base ?>/admin/mensagens" class="text-ctpblue hover:text-ctgreen">Cancelar</a>
    </div>
  </form>
</div>
