<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
/** @var array $colaborador @var ?array $vinculo @var ?object $usuario @var array $lideres @var bool $isAdmin @var ?string $senhaTemp */
$temVinculo = is_array($vinculo);
$acessoAtivo = $temVinculo && (int)($vinculo['ativo'] ?? 0) === 1;
$base = $base ?? '';
$acaoUrl = $base . '/admin/colaboradores/' . (int)$colaborador['id'] . '/acesso';
?>
<div class="max-w-4xl space-y-6">
  <?= ui_modulo_topo($base, 'pessoas', 'colaboradores', [
      'titulo' => 'Acesso e liderança',
      'descricao' => 'Regras operacionais do Portal — nunca inferidas do METADADOS.',
  ], [['label' => (string)($colaborador['nome'] ?? 'Colaborador'), 'href' => null], ['label' => 'Acesso e liderança', 'href' => null]]) ?>
  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($senhaTemp)): ?>
    <div class="rounded-ds-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
      <p class="font-semibold">Senha temporária — copie agora, não será exibida de novo:</p>
      <code class="mt-1 inline-block select-all rounded bg-white px-3 py-1 text-base font-bold tracking-wide text-text-primary border border-warning/30"><?= Security::e($senhaTemp) ?></code>
      <p class="mt-2 text-xs">Entregue ao líder por um canal seguro. Ele pode trocá-la depois em "Esqueci minha senha".</p>
    </div>
  <?php endif; ?>

  <div class="rounded-ds-lg border border-border bg-white p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-text-secondary">Colaborador oficial</h2>
    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
      <div><dt class="text-text-secondary">Nome</dt><dd class="font-medium text-text-primary"><?= Security::e($colaborador['nome']) ?></dd></div>
      <div><dt class="text-text-secondary">Cargo</dt><dd class="text-text-primary"><?= Security::e($colaborador['cargo_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-text-secondary">Setor</dt><dd class="text-text-primary"><?= Security::e($colaborador['setor_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-text-secondary">Empresa</dt><dd class="text-text-primary"><?= Security::e($colaborador['empresa_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-text-secondary">Vínculo METADADOS</dt><dd class="text-text-primary"><?= !empty($colaborador['metadados_id']) ? 'Sim' : 'Não' ?></dd></div>
      <div><dt class="text-text-secondary">Situação</dt><dd class="text-text-primary"><?= (int)($colaborador['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></dd></div>
    </dl>
  </div>

  <?php if (!$temVinculo): ?>
    <div class="rounded-ds-lg border border-border bg-white p-5 shadow-sm">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-text-secondary">Criar acesso ao Portal</h2>
      <?php if (!$isAdmin): ?>
        <p class="mt-3 text-sm text-text-secondary">Apenas administradores podem criar ou vincular um usuário de acesso. Peça a um administrador.</p>
      <?php else: ?>
        <form method="post" action="<?= $acaoUrl ?>" class="mt-4 space-y-4">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <div>
            <label class="block text-sm font-medium text-text-primary">E-mail de acesso</label>
            <input type="email" name="email" required class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm" placeholder="lider@madeplant.com.br">
          </div>
          <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-text-primary">Papéis</legend>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_gestor" value="1" checked> É líder (gestor)</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pode_solicitar_vaga" value="1" checked> Pode abrir Solicitação de Vaga</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_rh" value="1"> Perfil de RH no fluxo de solicitação</label>
          </fieldset>
          <div>
            <label class="block text-sm font-medium text-text-primary">Líder imediato (aprova as solicitações deste líder)</label>
            <select name="lider_colaborador_id" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm">
              <option value="">— Sem líder definido (roteia por setor) —</option>
              <?php foreach ($lideres as $l): ?>
                <?php if ((int)$l['colaborador_id'] === (int)$colaborador['id']) continue; ?>
                <option value="<?= (int)$l['colaborador_id'] ?>"><?= Security::e($l['nome']) ?> — <?= Security::e($l['cargo_nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex flex-wrap gap-2">
            <button name="acao" value="criar_acesso" class="<?= ui_btn('primario') ?>">Criar acesso e gerar senha</button>
            <button name="acao" value="vincular_existente" class="rounded-ds-md border border-border px-4 py-2 text-sm font-semibold text-text-primary hover:bg-surface-secondary">Vincular usuário existente (por e-mail)</button>
          </div>
          <p class="text-xs text-text-secondary">"Criar acesso" cria um usuário <code>viewer</code> ativo com senha temporária. O líder acessa o Portal e usa apenas Solicitação de Vaga.</p>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="rounded-ds-lg border border-border bg-white p-5 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-text-secondary">Acesso vinculado</h2>
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $acessoAtivo ? 'bg-success/10 text-success' : 'bg-surface-secondary text-text-secondary' ?>"><?= $acessoAtivo ? 'Ativo' : 'Desativado' ?></span>
      </div>
      <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div><dt class="text-text-secondary">Usuário</dt><dd class="font-medium text-text-primary"><?= Security::e($usuario->nome ?? '—') ?></dd></div>
        <div><dt class="text-text-secondary">E-mail</dt><dd class="text-text-primary"><?= Security::e($usuario->email ?? '—') ?></dd></div>
        <div><dt class="text-text-secondary">Login ativo</dt><dd class="text-text-primary"><?= !empty($usuario->email_verified_at) ? 'Sim' : 'Não (inativo)' ?></dd></div>
        <div><dt class="text-text-secondary">Papel do sistema</dt><dd class="text-text-primary"><?= Security::e($usuario->role ?? '—') ?></dd></div>
      </dl>

      <form method="post" action="<?= $acaoUrl ?>" class="mt-5 space-y-4 border-t border-border pt-5">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
        <fieldset class="space-y-2">
          <legend class="text-sm font-medium text-text-primary">Papéis</legend>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_gestor" value="1" <?= (int)($vinculo['is_gestor'] ?? 0) === 1 ? 'checked' : '' ?>> É líder (gestor)</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pode_solicitar_vaga" value="1" <?= (int)($vinculo['pode_solicitar_vaga'] ?? 0) === 1 ? 'checked' : '' ?>> Pode abrir Solicitação de Vaga</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_rh" value="1" <?= (int)($vinculo['is_rh'] ?? 0) === 1 ? 'checked' : '' ?>> Perfil de RH no fluxo de solicitação</label>
        </fieldset>
        <div>
          <label class="block text-sm font-medium text-text-primary">Líder imediato</label>
          <select name="lider_colaborador_id" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm">
            <option value="">— Sem líder definido (roteia por setor) —</option>
            <?php foreach ($lideres as $l): ?>
              <?php if ((int)$l['colaborador_id'] === (int)$colaborador['id']) continue; ?>
              <option value="<?= (int)$l['colaborador_id'] ?>" <?= (int)($vinculo['lider_colaborador_id'] ?? 0) === (int)$l['colaborador_id'] ? 'selected' : '' ?>><?= Security::e($l['nome']) ?> — <?= Security::e($l['cargo_nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-wrap gap-2">
          <button name="acao" value="atualizar" class="<?= ui_btn('primario') ?>">Salvar permissões</button>
          <?php if ($acessoAtivo): ?>
            <button name="acao" value="desativar_acesso" class="rounded-ds-md border border-danger/30 px-4 py-2 text-sm font-semibold text-danger hover:bg-danger/10" data-confirm-message="Desativar o acesso deste líder? O vínculo e o histórico são preservados.">Desativar acesso</button>
          <?php else: ?>
            <button name="acao" value="reativar" class="rounded-ds-md border border-success/30 px-4 py-2 text-sm font-semibold text-success hover:bg-success/10">Reativar acesso</button>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <button name="acao" value="redefinir_senha" class="rounded-ds-md border border-border px-4 py-2 text-sm font-semibold text-text-primary hover:bg-surface-secondary" data-confirm-message="Gerar uma nova senha temporária para este acesso?">Redefinir senha</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>
