<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$queryBase = $base . '/admin/usuarios';
$q = $filters['q'] ?? '';
$role = $filters['role'] ?? '';
$status = $filters['status'] ?? '';
$params = [];
if ($q !== '') { $params['q'] = $q; }
if ($role !== '') { $params['role'] = $role; }
if ($status !== '') { $params['status'] = $status; }
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'pessoas', 'usuarios', [
      'titulo' => 'Usuários e Acessos',
      'descricao' => 'Usuários do Portal, perfis de acesso, permissões individuais e vínculos com o cadastro oficial.',
      'acao' => ['label' => 'Cadastrar novo usuário', 'href' => $base . '/admin/usuarios/novo'],
  ]) ?>
  <div class="responsive-panel">
  <form class="responsive-form-grid-4 mt-4" method="get" action="<?= $queryBase ?>">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-text-primary">Busca</label>
      <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome ou e-mail" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Permissão</label>
      <select name="role" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="rh" <?= $role === 'rh' ? 'selected' : '' ?>>RH</option>
        <option value="viewer" <?= $role === 'viewer' ? 'selected' : '' ?>>Leitor</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Status</label>
      <select name="status" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativo</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativo</option>
      </select>
    </div>
    <div class="md:col-span-4 flex gap-2">
      <div class="responsive-form-actions">
        <button class="<?= ui_btn('primario') ?>">Filtrar</button>
        <a href="<?= $queryBase ?>" class="<?= ui_btn('secundario') ?>">Limpar</a>
      </div>
    </div>
  </form>

  <div class="mt-5 text-sm text-text-secondary">Total de usuários: <?= (int)$total ?></div>

  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
      <thead class="bg-surface-secondary">
      <tr class="border-b">
        <th class="text-left p-3 font-medium text-text-secondary">Nome</th>
        <th class="text-left p-3 font-medium text-text-secondary">E-mail</th>
        <th class="text-left p-3 font-medium text-text-secondary">Permissão</th>
        <th class="text-left p-3 font-medium text-text-secondary">Status</th>
        <th class="text-left p-3 font-medium text-text-secondary">Data de cadastro</th>
        <th class="text-left p-3 font-medium text-text-secondary">Ações</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-border">
      <?php foreach ($users as $u): ?>
        <?php $isActive = (int)($u['ativo'] ?? 0) === 1; ?>
        <tr class="hover:bg-surface-secondary">
          <td class="p-3 font-medium text-text-primary"><?= Security::e($u['nome']) ?></td>
          <td class="p-3 text-text-primary"><?= Security::e($u['email']) ?></td>
          <td class="p-3 text-text-primary">
            <?= Security::e(strtoupper((string)($u['role'] ?? ''))) ?>
            <?php if ((int)($u['pode_solicitar_vaga'] ?? 0) === 1): ?>
              <span class="ml-1 inline-flex rounded-full bg-primary-100 px-2 py-0.5 text-[11px] font-semibold text-primary-700" title="Autorizado a solicitar vagas">Solicita vaga</span>
            <?php endif; ?>
          </td>
          <td class="p-3">
            <?= ui_badge($isActive ? 'Ativo' : 'Inativo', $isActive ? 'success' : 'neutro') ?>
          </td>
          <td class="p-3 text-text-secondary"><?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime((string)$u['created_at'])) : '-' ?></td>
          <td class="p-3">
            <a href="<?= $base ?>/admin/usuarios/<?= (int)$u['id'] ?>" class="text-primary-700 hover:text-primary-800 font-medium">Visualizar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
        <tr>
          <td colspan="6" class="p-6 text-center text-text-secondary">Nenhum usuário encontrado.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($users as $u): ?>
      <?php $isActive = (int)($u['ativo'] ?? 0) === 1; ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-text-primary"><?= Security::e($u['nome']) ?></div>
        <div class="mt-1 text-sm text-text-secondary"><?= Security::e($u['email']) ?></div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <span class="text-xs font-medium text-text-secondary"><?= Security::e(strtoupper((string)($u['role'] ?? ''))) ?></span>
          <?php if ((int)($u['pode_solicitar_vaga'] ?? 0) === 1): ?>
            <span class="inline-flex rounded-full bg-primary-100 px-2 py-0.5 text-[11px] font-semibold text-primary-700">Solicita vaga</span>
          <?php endif; ?>
          <?= ui_badge($isActive ? 'Ativo' : 'Inativo', $isActive ? 'success' : 'neutro') ?>
        </div>
        <div class="mt-3 text-xs text-text-secondary">Cadastro: <?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime((string)$u['created_at'])) : '-' ?></div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/usuarios/<?= (int)$u['id'] ?>" class="text-primary-700 hover:text-primary-800 font-medium">Visualizar</a>
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
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="<?= ui_btn('secundario') ?> <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Anterior</a>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="<?= ui_btn('secundario') ?> <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Próxima</a>
      </div>
    </div>
  <?php endif; ?>
  </div>
</div>
