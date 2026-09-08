<?php
/** @var array $colaborador @var ?array $vinculo @var ?object $usuario @var array $lideres @var bool $isAdmin @var ?string $senhaTemp */
$temVinculo = is_array($vinculo);
$acessoAtivo = $temVinculo && (int)($vinculo['ativo'] ?? 0) === 1;
$base = $base ?? '';
$acaoUrl = $base . '/admin/colaboradores/' . (int)$colaborador['id'] . '/acesso';
?>
<div class="mx-auto max-w-3xl space-y-6">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Acesso e liderança</h1>
      <p class="text-sm text-slate-500">Regras operacionais do Portal — nunca inferidas do METADADOS.</p>
    </div>
    <a href="<?= $base ?>/admin/colaboradores" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Voltar</a>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($senhaTemp)): ?>
    <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      <p class="font-semibold">Senha temporária — copie agora, não será exibida de novo:</p>
      <code class="mt-1 inline-block select-all rounded bg-white px-3 py-1 text-base font-bold tracking-wide text-slate-900 border border-amber-300"><?= Security::e($senhaTemp) ?></code>
      <p class="mt-2 text-xs">Entregue ao líder por um canal seguro. Ele pode trocá-la depois em "Esqueci minha senha".</p>
    </div>
  <?php endif; ?>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Colaborador oficial</h2>
    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
      <div><dt class="text-slate-500">Nome</dt><dd class="font-medium text-slate-900"><?= Security::e($colaborador['nome']) ?></dd></div>
      <div><dt class="text-slate-500">Cargo</dt><dd class="text-slate-800"><?= Security::e($colaborador['cargo_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-slate-500">Setor</dt><dd class="text-slate-800"><?= Security::e($colaborador['setor_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-slate-500">Empresa</dt><dd class="text-slate-800"><?= Security::e($colaborador['empresa_nome'] ?? '—') ?></dd></div>
      <div><dt class="text-slate-500">Vínculo METADADOS</dt><dd class="text-slate-800"><?= !empty($colaborador['metadados_id']) ? 'Sim' : 'Não' ?></dd></div>
      <div><dt class="text-slate-500">Situação</dt><dd class="text-slate-800"><?= (int)($colaborador['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></dd></div>
    </dl>
  </div>

  <?php if (!$temVinculo): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Criar acesso ao Portal</h2>
      <?php if (!$isAdmin): ?>
        <p class="mt-3 text-sm text-slate-500">Apenas administradores podem criar ou vincular um usuário de acesso. Peça a um administrador.</p>
      <?php else: ?>
        <form method="post" action="<?= $acaoUrl ?>" class="mt-4 space-y-4">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <div>
            <label class="block text-sm font-medium text-slate-700">E-mail de acesso</label>
            <input type="email" name="email" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="lider@madeplant.com.br">
          </div>
          <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Papéis</legend>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_gestor" value="1" checked> É líder (gestor)</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pode_solicitar_vaga" value="1" checked> Pode abrir Solicitação de Vaga</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_rh" value="1"> Perfil de RH no fluxo de solicitação</label>
          </fieldset>
          <div>
            <label class="block text-sm font-medium text-slate-700">Líder imediato (aprova as solicitações deste líder)</label>
            <select name="lider_colaborador_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
              <option value="">— Sem líder definido (roteia por setor) —</option>
              <?php foreach ($lideres as $l): ?>
                <?php if ((int)$l['colaborador_id'] === (int)$colaborador['id']) continue; ?>
                <option value="<?= (int)$l['colaborador_id'] ?>"><?= Security::e($l['nome']) ?> — <?= Security::e($l['cargo_nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex flex-wrap gap-2">
            <button name="acao" value="criar_acesso" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Criar acesso e gerar senha</button>
            <button name="acao" value="vincular_existente" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Vincular usuário existente (por e-mail)</button>
          </div>
          <p class="text-xs text-slate-500">"Criar acesso" cria um usuário <code>viewer</code> ativo com senha temporária. O líder acessa o Portal e usa apenas Solicitação de Vaga.</p>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Acesso vinculado</h2>
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $acessoAtivo ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>"><?= $acessoAtivo ? 'Ativo' : 'Desativado' ?></span>
      </div>
      <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Usuário</dt><dd class="font-medium text-slate-900"><?= Security::e($usuario->nome ?? '—') ?></dd></div>
        <div><dt class="text-slate-500">E-mail</dt><dd class="text-slate-800"><?= Security::e($usuario->email ?? '—') ?></dd></div>
        <div><dt class="text-slate-500">Login ativo</dt><dd class="text-slate-800"><?= !empty($usuario->email_verified_at) ? 'Sim' : 'Não (inativo)' ?></dd></div>
        <div><dt class="text-slate-500">Papel do sistema</dt><dd class="text-slate-800"><?= Security::e($usuario->role ?? '—') ?></dd></div>
      </dl>

      <form method="post" action="<?= $acaoUrl ?>" class="mt-5 space-y-4 border-t border-slate-100 pt-5">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
        <fieldset class="space-y-2">
          <legend class="text-sm font-medium text-slate-700">Papéis</legend>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_gestor" value="1" <?= (int)($vinculo['is_gestor'] ?? 0) === 1 ? 'checked' : '' ?>> É líder (gestor)</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pode_solicitar_vaga" value="1" <?= (int)($vinculo['pode_solicitar_vaga'] ?? 0) === 1 ? 'checked' : '' ?>> Pode abrir Solicitação de Vaga</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_rh" value="1" <?= (int)($vinculo['is_rh'] ?? 0) === 1 ? 'checked' : '' ?>> Perfil de RH no fluxo de solicitação</label>
        </fieldset>
        <div>
          <label class="block text-sm font-medium text-slate-700">Líder imediato</label>
          <select name="lider_colaborador_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="">— Sem líder definido (roteia por setor) —</option>
            <?php foreach ($lideres as $l): ?>
              <?php if ((int)$l['colaborador_id'] === (int)$colaborador['id']) continue; ?>
              <option value="<?= (int)$l['colaborador_id'] ?>" <?= (int)($vinculo['lider_colaborador_id'] ?? 0) === (int)$l['colaborador_id'] ? 'selected' : '' ?>><?= Security::e($l['nome']) ?> — <?= Security::e($l['cargo_nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-wrap gap-2">
          <button name="acao" value="atualizar" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Salvar permissões</button>
          <?php if ($acessoAtivo): ?>
            <button name="acao" value="desativar_acesso" class="rounded-xl border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" onclick="return confirm('Desativar o acesso deste líder? O vínculo e o histórico são preservados.')">Desativar acesso</button>
          <?php else: ?>
            <button name="acao" value="reativar" class="rounded-xl border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Reativar acesso</button>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <button name="acao" value="redefinir_senha" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" onclick="return confirm('Gerar uma nova senha temporária para este acesso?')">Redefinir senha</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>
