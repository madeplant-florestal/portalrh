<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
require_once APP_PATH . '/views/partials/matriz-permissoes.php';
ui_script_pagina('matriz-permissoes.js');
$old = $old ?? [];
$roleSel = (string)($old['role'] ?? 'viewer');
$base = Config::app()['base_url'] ?? '';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'pessoas', 'usuarios', [
      'titulo' => 'Cadastrar novo usuário',
      'descricao' => 'Cria o usuário do Portal. Vínculo com o METADADOS e contexto organizacional são definidos no detalhe, depois da criação.',
  ], [['label' => 'Novo usuário', 'href' => null]]) ?>
  <div class="responsive-panel">
  <?php if (!empty($error)): ?>
    <div class="mt-3 p-3 bg-danger/10 text-danger border border-danger/30 rounded text-sm">
      <?= Security::e($error) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-3 rounded-ds-md border border-success/30 bg-success/10 p-3 text-sm text-success">
      <?= Security::e($success) ?>
    </div>
  <?php endif; ?>
  <form class="mt-4 space-y-4" method="post" action="<?= $base ?>/admin/usuarios/novo">
    <div class="max-w-2xl space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
    <h3 class="border-b border-border pb-2 text-ds-h3 text-text-primary">Identidade e acesso</h3>
    <div>
      <label class="block text-sm text-text-primary">Nome</label>
      <input type="text" name="nome" value="<?= Security::e((string)($old['nome'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block text-sm text-text-primary">E-mail</label>
      <input type="email" name="email" value="<?= Security::e((string)($old['email'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block text-sm text-text-primary">Senha</label>
      <input type="password" name="senha" class="mt-1 w-full border rounded px-3 py-2" minlength="12" required>
      <p class="mt-1 text-xs text-text-secondary">Mínimo de 12 caracteres com maiúscula, minúscula, número e símbolo.</p>
    </div>
    <div>
      <label for="role" class="block text-sm text-text-primary">Perfil</label>
      <select id="role" name="role" class="mt-1 w-full border rounded px-3 py-2">
        <option value="viewer" <?= $roleSel === 'viewer' ? 'selected' : '' ?>>Leitor</option>
        <option value="rh" <?= $roleSel === 'rh' ? 'selected' : '' ?>>RH</option>
        <?php if (Autoridade::gestorDisponivel()): ?><option value="gestor" <?= $roleSel === 'gestor' ? 'selected' : '' ?>>Gestor</option><?php endif; ?>
        <option value="admin" <?= $roleSel === 'admin' ? 'selected' : '' ?>>Admin</option>
      </select>
      <p class="mt-1 text-xs text-text-secondary">Ao escolher o perfil, a matriz abaixo carrega o modelo recomendado. Você pode ajustar dentro do que o perfil permite.</p>
    </div>
    <h3 class="border-b border-border pb-2 pt-2 text-ds-h3 text-text-primary">Hierarquia</h3>
    <div>
      <label for="gestor_usuario_id" class="block text-sm text-text-primary">Gestor imediato <span class="text-text-muted">(opcional)</span></label>
      <select id="gestor_usuario_id" name="gestor_usuario_id" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">Sem gestor definido</option>
        <?php foreach (($gestorOptions ?? []) as $opt): ?>
          <option value="<?= (int)$opt['id'] ?>" <?= (int)($gestorSelecionado ?? 0) === (int)$opt['id'] ? 'selected' : '' ?>><?= Security::e(UsuarioGestorService::rotuloOpcao($opt)) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-text-secondary">Hierarquia própria do Portal (outro usuário ativo). Não é o aprovador de Solicitação de Vaga.</p>
    </div>
    <p class="text-xs text-text-secondary">
      Vínculo com o METADADOS e contexto organizacional (Cargo principal, Setores de atuação,
      aprovador) são definidos na tela de detalhe do usuário, após a criação. Setores gerenciados do Gestor também: nenhum setor é atribuído
      automaticamente.
    </p>
    </div>
    <h3 class="border-b border-border pb-2 pt-2 text-ds-h3 text-text-primary">Permissões de acesso</h3>
    <?= ui_matriz_permissoes($matriz, 'role') ?>
    <div class="responsive-form-actions pt-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Criar usuário</button>
      <a href="<?= $base ?>/admin/usuarios" class="<?= ui_btn('ghost') ?>">Cancelar</a>
    </div>
  </form>
  <form class="mt-6 pt-4 border-t" method="post" action="<?= $base ?>/admin/usuarios/supervisor/garantir">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
    <h3 class="text-sm font-semibold text-text-primary mb-2">Operação especial</h3>
    <p class="text-xs text-text-secondary mb-3">Cria ou atualiza o usuário Supervisor protegido com permissões irrestritas.</p>
    <button type="submit" class="<?= ui_btn('secundario') ?>">Garantir usuário Supervisor</button>
  </form>
  </div>
</div>
