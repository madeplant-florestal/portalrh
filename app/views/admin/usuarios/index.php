<?php
$queryBase = $base . '/admin/usuarios';
$q = $filters['q'] ?? '';
$role = $filters['role'] ?? '';
$status = $filters['status'] ?? '';
$params = [];
if ($q !== '') { $params['q'] = $q; }
if ($role !== '') { $params['role'] = $role; }
if ($status !== '') { $params['status'] = $status; }
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Usuários</h2>
    <a href="<?= $base ?>/admin/usuarios/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Cadastrar novo usuário</a>
  </div>

  <form class="responsive-form-grid-4 mt-4" method="get" action="<?= $queryBase ?>">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Busca</label>
      <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome ou e-mail" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Permissão</label>
      <select name="role" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="rh" <?= $role === 'rh' ? 'selected' : '' ?>>RH</option>
        <option value="viewer" <?= $role === 'viewer' ? 'selected' : '' ?>>Leitor</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Status</label>
      <select name="status" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativo</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativo</option>
      </select>
    </div>
    <div class="md:col-span-4 flex gap-2">
      <div class="responsive-form-actions">
        <button class="bg-ctgreen px-4 py-2 text-sm text-white rounded hover:bg-ctdark">Filtrar</button>
        <a href="<?= $queryBase ?>" class="px-4 py-2 rounded border text-sm text-gray-600 hover:bg-gray-50">Limpar</a>
      </div>
    </div>
  </form>

  <div class="mt-5 text-sm text-gray-500">Total de usuários: <?= (int)$total ?></div>

  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead class="bg-gray-50">
      <tr class="border-b">
        <th class="text-left p-3 font-medium text-gray-500">Nome</th>
        <th class="text-left p-3 font-medium text-gray-500">E-mail</th>
        <th class="text-left p-3 font-medium text-gray-500">Permissão</th>
        <th class="text-left p-3 font-medium text-gray-500">Status</th>
        <th class="text-left p-3 font-medium text-gray-500">Data de cadastro</th>
        <th class="text-left p-3 font-medium text-gray-500">Ações</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
      <?php foreach ($users as $u): ?>
        <?php $isActive = (int)($u['ativo'] ?? 0) === 1; ?>
        <tr class="hover:bg-gray-50">
          <td class="p-3 font-medium text-gray-900"><?= Security::e($u['nome']) ?></td>
          <td class="p-3 text-gray-700"><?= Security::e($u['email']) ?></td>
          <td class="p-3 text-gray-700"><?= Security::e(strtoupper((string)($u['role'] ?? ''))) ?></td>
          <td class="p-3">
            <span class="ct-badge <?= $isActive ? 'ct-badge-active' : 'ct-badge-inactive' ?>">
              <?= $isActive ? 'Ativo' : 'Inativo' ?>
            </span>
          </td>
          <td class="p-3 text-gray-500"><?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime((string)$u['created_at'])) : '-' ?></td>
          <td class="p-3">
            <a href="<?= $base ?>/admin/usuarios/<?= (int)$u['id'] ?>" class="text-ctgreen hover:text-ctdark font-medium">Visualizar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
        <tr>
          <td colspan="6" class="p-6 text-center text-gray-500">Nenhum usuário encontrado.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($users as $u): ?>
      <?php $isActive = (int)($u['ativo'] ?? 0) === 1; ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-gray-900"><?= Security::e($u['nome']) ?></div>
        <div class="mt-1 text-sm text-gray-600"><?= Security::e($u['email']) ?></div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <span class="text-xs font-medium text-gray-500"><?= Security::e(strtoupper((string)($u['role'] ?? ''))) ?></span>
          <span class="ct-badge <?= $isActive ? 'ct-badge-active' : 'ct-badge-inactive' ?>">
            <?= $isActive ? 'Ativo' : 'Inativo' ?>
          </span>
        </div>
        <div class="mt-3 text-xs text-gray-500">Cadastro: <?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime((string)$u['created_at'])) : '-' ?></div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/usuarios/<?= (int)$u['id'] ?>" class="text-ctgreen hover:text-ctdark font-medium">Visualizar</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (($pages ?? 1) > 1): ?>
    <div class="responsive-pagination mt-6 text-sm">
      <div class="text-gray-500">Página <?= (int)$page ?> de <?= (int)$pages ?></div>
      <div class="responsive-form-actions">
        <?php
        $prev = max(1, (int)$page - 1);
        $next = min((int)$pages, (int)$page + 1);
        $prevParams = array_merge($params, ['page' => $prev]);
        $nextParams = array_merge($params, ['page' => $next]);
        ?>
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Anterior</a>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Próxima</a>
      </div>
    </div>
  <?php endif; ?>
</div>
