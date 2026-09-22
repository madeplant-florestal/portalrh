<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isEdit = ($mode ?? 'create') === 'edit';
$catalogo = $catalogo ?? [];
$analiseInicial = $analiseInicial ?? ['reconhecidas' => [], 'desconhecidas' => []];
// Mapa chave -> {nome,descricao} do catálogo, para rotular as badges de "Variáveis utilizadas"
// sem duplicar a lista (a mesma fonte — MensagemService::catalogoVariaveis() — alimenta os
// botões de inserção e os rótulos abaixo).
$catalogoPorChave = [];
foreach ($catalogo as $item) {
    $catalogoPorChave[$item['chave']] = $item;
}
?>
<div class="space-y-4 max-w-3xl">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Mensagens', 'href' => $base . '/admin/mensagens'], ['label' => $isEdit ? 'Editar mensagem' : 'Nova mensagem']]) ?>
  <?= ui_page_header(['titulo' => $isEdit ? 'Editar mensagem' : 'Nova mensagem', 'descricao' => 'Modelo de mensagem do processo seletivo.']) ?>
  <div class="responsive-panel">

  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 bg-danger/10 text-danger border border-danger/30 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= $isEdit ? '/admin/mensagens/editar/' . (int)$mensagem['id'] : '/admin/mensagens/novo' ?>" method="post" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>" />

    <div>
      <label class="block text-sm font-medium text-text-primary">Código técnico <?= $isEdit ? '' : '*' ?></label>
      <?php if ($isEdit): ?>
        <div class="mt-1 flex items-center gap-2">
          <code class="rounded bg-surface-secondary px-3 py-2 text-sm text-text-primary"><?= Security::e((string)($mensagem['codigo'] ?? '')) ?></code>
          <span class="text-xs text-text-secondary">Não editável após a criação.</span>
        </div>
      <?php else: ?>
        <input type="text" name="codigo" value="<?= Security::e((string)($mensagem['codigo'] ?? '')) ?>"
               pattern="[a-z0-9_]+" placeholder="ex.: convocacao_entrevista_rh"
               class="mt-1 w-full border rounded px-3 py-2 font-mono text-sm" required />
        <p class="mt-1 text-xs text-text-secondary">Somente letras minúsculas, números e underscore. Não pode ser alterado depois de criado.</p>
      <?php endif; ?>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Título *</label>
      <input type="text" name="titulo" value="<?= Security::e((string)($mensagem['titulo'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" required />
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Descrição</label>
      <textarea name="descricao" rows="2" class="mt-1 w-full border rounded px-3 py-2"><?= Security::e((string)($mensagem['descricao'] ?? '')) ?></textarea>
      <p class="mt-1 text-xs text-text-secondary">Uso interno — não é enviada ao candidato.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Conteúdo *</label>
      <textarea name="conteudo" rows="12" data-mensagem-conteudo="1"
                class="mt-1 w-full border rounded px-3 py-2 font-mono text-sm" required><?= Security::e((string)($mensagem['conteudo'] ?? '')) ?></textarea>
      <p class="mt-1 text-xs text-text-secondary">Quebras de linha e emojis são preservados.</p>
    </div>

    <script type="application/json" data-mensagem-catalogo="1"><?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

    <div class="rounded-ds-md border border-border bg-background p-4">
      <div class="text-sm font-medium text-text-primary">Variáveis automáticas</div>
      <p class="mt-1 text-xs text-text-secondary">Clique em uma variável para inseri-la na mensagem. No momento do envio, o sistema substituirá automaticamente a variável pelos dados correspondentes do candidato ou do agendamento.</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <?php foreach ($catalogo as $item): ?>
          <button type="button" data-mensagem-var-insert="1" data-placeholder="<?= Security::e((string)$item['placeholder']) ?>"
                  title="<?= Security::e((string)$item['descricao']) ?>"
                  class="inline-flex items-center rounded-full border border-primary-700 px-3 py-1 text-xs font-medium text-primary-700 hover:bg-primary-700 hover:text-white">
            + <?= Security::e((string)$item['nome']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rounded-ds-md border border-border bg-background p-4">
      <div class="text-sm font-medium text-text-primary">Variáveis utilizadas nesta mensagem</div>
      <div class="mt-2 flex flex-wrap gap-2" data-mensagem-placeholders="1">
        <?php if ($analiseInicial['reconhecidas'] === [] && $analiseInicial['desconhecidas'] === []): ?>
          <span class="text-sm text-text-muted" data-mensagem-placeholders-vazio="1">Nenhuma variável utilizada.</span>
        <?php endif; ?>
        <?php foreach ($analiseInicial['reconhecidas'] as $chave): ?>
          <span class="inline-flex items-center rounded-full border border-success/30 bg-success/10 px-3 py-1 text-xs font-medium text-success">
            <?= Security::e((string)($catalogoPorChave[$chave]['nome'] ?? $chave)) ?> — [<?= Security::e($chave) ?>]
          </span>
        <?php endforeach; ?>
        <?php foreach ($analiseInicial['desconhecidas'] as $chave): ?>
          <span class="inline-flex items-center rounded-full border border-warning/30 bg-warning/10 px-3 py-1 text-xs font-medium text-warning">
            Variável não reconhecida: [<?= Security::e($chave) ?>]
          </span>
        <?php endforeach; ?>
      </div>
      <?php if ($analiseInicial['desconhecidas'] !== []): ?>
        <p class="mt-2 text-xs text-warning">Estas variáveis não pertencem ao catálogo oficial e não serão preenchidas automaticamente. Use as Variáveis Automáticas acima, ou uma mensagem com variável não reconhecida não pode ficar Ativa.</p>
      <?php endif; ?>
    </div>

    <div class="rounded-ds-md border border-border bg-background p-4" data-mensagem-preview-wrap="1" hidden>
      <div class="text-sm font-medium text-text-primary">Pré-visualização</div>
      <p class="mt-1 text-xs text-text-secondary">Opcional — preencha valores de exemplo para ver o texto final. Nada aqui é salvo.</p>
      <div class="mt-3 grid gap-2 sm:grid-cols-2" data-mensagem-preview-inputs="1"></div>
      <div class="mt-3 rounded border border-border bg-surface p-3 text-sm text-text-primary whitespace-pre-wrap" data-mensagem-preview-text="1"></div>
    </div>

    <div class="flex items-center">
      <input type="checkbox" id="ativo" name="ativo" <?= (int)($mensagem['ativo'] ?? 1) === 1 ? 'checked' : '' ?> class="mr-2" />
      <label for="ativo" class="text-sm text-text-primary">Ativa</label>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Salvar</button>
      <a href="<?= $base ?>/admin/mensagens" class="text-primary-700 hover:text-primary-700">Cancelar</a>
    </div>
  </form>
</div>
</div>
