<?php
$cfg = Config::get();
$publicJobsUrl = (string)($cfg['app']['public_jobs_url'] ?? '');
if ($publicJobsUrl === '') {
    $publicJobsUrl = rtrim((string)($cfg['app']['base_url'] ?? ''), '/') . '/vagas';
}
?><div class="sidebar-inner">
  <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4">
    <img src="<?= $base ?>/assets/logo.png" alt="RH Madeplant" class="h-8 w-auto object-contain">
    <button type="button" class="app-nav-toggle sidebar-close touch-target text-white" aria-label="Fechar menu" data-admin-menu-close="1">
      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M18 6l-12 12"/></svg>
    </button>
  </div>
  <nav class="sidebar-nav" aria-label="Menu principal">
    <?php if (Auth::check()): ?>
      <a href="<?= $base ?>/admin" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>
        <span class="sidebar-link-label">Dashboard</span>
      </a>
      <a href="<?= Security::e($publicJobsUrl) ?>" target="_blank" rel="noopener noreferrer" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="sidebar-link-label">Link vagas públicas</span>
      </a>
      <a href="<?= $base ?>/admin/colaboradores" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 19a4 4 0 0 0-8 0"/><circle cx="12" cy="11" r="3"/><path d="M5 19a3 3 0 0 1 3-3"/><path d="M19 19a3 3 0 0 0-3-3"/></svg>
        <span class="sidebar-link-label">Colaboradores</span>
      </a>
      <a href="<?= $base ?>/admin/vagas" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 8V6h6v2"/><rect x="3" y="8" width="18" height="12" rx="2"/></svg>
        <span class="sidebar-link-label">Vagas</span>
      </a>
      <a href="<?= $base ?>/admin/candidaturas" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="8" cy="8" r="3"/><circle cx="16" cy="12" r="3"/></svg>
        <span class="sidebar-link-label">Candidaturas</span>
      </a>
      <a href="<?= $base ?>/admin/indicacoes" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l3.1 6.3 7 .9-5 4.8 1.2 6.9L12 18.6 5.7 22l1.2-6.9-5-4.8 7-.9L12 3z"/></svg>
        <span class="sidebar-link-label">Programa de Indicações</span>
      </a>
      <a href="<?= $base ?>/admin/pipeline" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
        <span class="sidebar-link-label">Pipeline Kanban</span>
      </a>
      <a href="<?= $base ?>/admin/beneficios" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 8v12"/><path d="M7 8c0-2 2-3 5-3s5 1 5 3"/></svg>
        <span class="sidebar-link-label">Benefícios</span>
      </a>
      <?php if (Auth::role() === 'admin'): ?>
      <a href="<?= $base ?>/admin/usuarios" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 4v16M4 12h16"/></svg>
        <span class="sidebar-link-label">Usuários</span>
      </a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/manual" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M4 4.5A2.5 2.5 0 016.5 7H20"/><path d="M6.5 7H20v12H6.5A2.5 2.5 0 014 16.5v-12z"/></svg>
        <span class="sidebar-link-label">Manual de Uso</span>
      </a>
      <a href="<?= $base ?>/admin/logout" class="sidebar-link sidebar-link-danger" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="14" height="16" rx="2"/><path d="M12 12h8M16 8l4 4-4 4"/></svg>
        <span class="sidebar-link-label">Sair</span>
      </a>
    <?php else: ?>
      <a href="<?= Security::e($publicJobsUrl) ?>" target="_blank" rel="noopener noreferrer" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 8V6h6v2"/><rect x="3" y="8" width="18" height="12" rx="2"/></svg>
        <span class="sidebar-link-label">Vagas</span>
      </a>
      <a href="<?= $base ?>/admin/login" class="sidebar-link" data-admin-menu-close="1">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
        <span class="sidebar-link-label">Área administrativa</span>
      </a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <span>RH Madeplant</span>
  </div>
</div>
