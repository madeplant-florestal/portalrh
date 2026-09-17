<?php
$cfg = Config::get();
$publicJobsUrl = (string)($cfg['app']['public_jobs_url'] ?? '');
if ($publicJobsUrl === '') {
    $publicJobsUrl = rtrim((string)($cfg['app']['base_url'] ?? ''), '/') . '/vagas';
}
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$basePath = rtrim((string)parse_url((string)($cfg['app']['base_url'] ?? ''), PHP_URL_PATH), '/');
if ($basePath !== '' && strncmp($requestPath, $basePath, strlen($basePath)) === 0) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$isCadastroOpen = preg_match('#^/admin/(empresas|setores|cargos|colaboradores|beneficios|usuarios|avaliacoes)#', $requestPath) === 1;
// Menu é só representação (§5 do ajuste "Cobertura Completa de Permissões") — todo item aqui é
// aditivo: mostra se o usuário JÁ tinha acesso pela role atual do módulo (replica exatamente o
// gate real do controller, nunca remove) OU se recebeu a permissão nova individualmente. Backend
// de cada controller segue inalterado nesta rodada (decisão aditiva confirmada explicitamente).
$isAdminOuSupervisor = Auth::role() === 'admin' || !empty($_SESSION['user_is_supervisor']);
$isStaff = $isAdminOuSupervisor || Auth::role() === 'rh';
// Dashboard/Indicadores de RH/Candidaturas/Manual/Vagas/Movimentação de Pessoal/a maioria dos
// filhos de Cadastros já são liberados por role a QUALQUER usuário autenticado
// (`Auth::requireRole([..., 'viewer'])` nos controllers correspondentes) — gatear esses itens por
// permissão seria um no-op hoje (role já cobre todo mundo), então ficam como já estavam. Só os
// itens abaixo, cujo backend hoje é admin/rh (sem 'viewer'), ganham a condição aditiva real.
$veAdminRh = static fn (string $codigo): bool => $isStaff || Authorization::temPermissao($codigo);
$sidebarLinkClass = static function (array $paths = [], string $extra = '') use ($requestPath): string {
    $isActive = false;
    foreach ($paths as $path) {
        $isExactOnly = $path === '/admin';
        if ($requestPath === $path || (!$isExactOnly && str_starts_with($requestPath, rtrim($path, '/') . '/'))) {
            $isActive = true;
            break;
        }
    }

    $classes = 'sidebar-link';
    if ($isActive) {
        $classes .= ' bg-white/10 text-white';
    }
    if ($extra !== '') {
        $classes .= ' ' . $extra;
    }

    return $classes;
};
?><div class="sidebar-inner">
  <div class="sidebar-brand-row border-b border-white/10 px-4 py-4">
    <div class="sidebar-brand">
      <img src="<?= $base ?>/assets/logo.png" alt="RH Madeplant" class="sidebar-brand-mark h-8 w-auto object-contain">
    </div>
    <div class="sidebar-actions">
      <button type="button" class="app-nav-toggle sidebar-collapse-toggle touch-target text-white" aria-label="Recolher menu" title="Recolher menu" data-admin-sidebar-collapse-toggle="1">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 18-6-6 6-6"/></svg>
      </button>
      <button type="button" class="app-nav-toggle sidebar-close touch-target text-white" aria-label="Fechar menu" title="Fechar menu" data-admin-menu-close="1">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M18 6l-12 12"/></svg>
      </button>
    </div>
  </div>
  <nav class="sidebar-nav" aria-label="Menu principal">
    <?php if (Auth::check()): ?>
      <?php if (Authorization::temPermissao('dashboard.visualizar')): ?>
      <a href="<?= $base ?>/admin" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin']) ?>" data-admin-menu-close="1" title="Dashboard" aria-label="Dashboard">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>
        <span class="sidebar-link-label">Dashboard</span>
      </a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/indicadores-rh" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/indicadores-rh']) ?>" data-admin-menu-close="1" title="Indicadores de RH" aria-label="Indicadores de RH">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
        <span class="sidebar-link-label">Indicadores de RH</span>
      </a>
      <?php if (Authorization::temPermissao('dashboard_recrutamento.visualizar')): ?>
      <a href="<?= $base ?>/admin/dashboard-recrutamento" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/dashboard-recrutamento']) ?>" data-admin-menu-close="1" title="Dashboard de Recrutamento e Seleção" aria-label="Dashboard de Recrutamento e Seleção">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3v18h18"/><path d="M8 17V11"/><path d="M13 17V7"/><path d="M18 17v-4"/></svg>
        <span class="sidebar-link-label">Recrutamento e Seleção</span>
      </a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/candidaturas" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/candidaturas']) ?>" data-admin-menu-close="1" title="Candidaturas" aria-label="Candidaturas">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="8" cy="8" r="3"/><circle cx="16" cy="12" r="3"/></svg>
        <span class="sidebar-link-label">Candidaturas</span>
      </a>
      <?php if ($veAdminRh('pipeline.visualizar')): ?>
      <a href="<?= $base ?>/admin/pipeline" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/pipeline']) ?>" data-admin-menu-close="1" title="Pipeline Kanban" aria-label="Pipeline Kanban">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
        <span class="sidebar-link-label">Pipeline Kanban</span>
      </a>
      <?php endif; ?>
      <?php if ($veAdminRh('recruitment_webhooks.visualizar')): ?>
      <a href="<?= $base ?>/admin/recruitment-webhooks" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/recruitment-webhooks']) ?>" data-admin-menu-close="1" title="Webhooks do recrutamento" aria-label="Webhooks do recrutamento">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12h6"/><path d="M14 6h6"/><path d="M14 18h6"/><circle cx="10" cy="12" r="2"/><circle cx="14" cy="6" r="2"/><circle cx="14" cy="18" r="2"/><path d="M11.7 10.9l1.6-1.8"/><path d="M11.7 13.1l1.6 1.8"/></svg>
        <span class="sidebar-link-label">Webhooks do recrutamento</span>
      </a>
      <?php endif; ?>
      <?php if (Authorization::temPermissao('mensagens.visualizar')): ?>
      <a href="<?= $base ?>/admin/mensagens" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/mensagens']) ?>" data-admin-menu-close="1" title="Mensagens" aria-label="Mensagens">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span class="sidebar-link-label">Mensagens</span>
      </a>
      <?php endif; ?>
      <?php
        // Cadastros é só um agrupador visual (§6 do ajuste) — não tem permissão própria. Aparece
        // se pelo menos UM filho for visível; cada filho usa a MESMA regra aditiva dos itens
        // principais. Empresas/Setores/Cargos/Benefícios/Avaliações já são liberados por role a
        // qualquer usuário autenticado hoje (sem 'viewer' faltando) — continuam sempre visíveis,
        // sem gate novo (gatear seria um no-op). Colaboradores é admin/rh só hoje — ganha o gate
        // aditivo real. Usuários fica EXCLUSIVAMENTE admin/supervisor, deliberadamente sem
        // permissão individual própria: é a própria tela que concede/edita permissões de todo
        // mundo — dar a ela uma permissão comum abriria caminho para autoconcessão de acesso.
        $veColaboradores = $veAdminRh('colaboradores.visualizar');
        $veUsuarios = $isAdminOuSupervisor;
        // Empresas/Setores/Cargos/Benefícios/Avaliações são sempre `true` hoje (role já libera
        // qualquer autenticado) — por isso o grupo sempre aparece na prática; a expressão fica
        // explícita (soma os 5 sempre-true + os 2 condicionais) para já valer no dia em que algum
        // desses 5 ganhar um gate próprio, sem precisar reescrever esta condição.
        $cadastrosFilhosVisiveis = [true, true, true, $veColaboradores, true, true, $veUsuarios];
        $veCadastros = in_array(true, $cadastrosFilhosVisiveis, true);
      ?>
      <?php if ($veCadastros): ?>
      <details class="sidebar-group rounded-xl border border-white/10 bg-white/5" <?= $isCadastroOpen ? 'open' : '' ?> data-sidebar-group="1">
        <summary class="sidebar-link sidebar-primary-link sidebar-group-summary cursor-pointer list-none" title="Cadastros" aria-label="Cadastros">
          <span class="flex items-center gap-3">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
            <span class="sidebar-link-label">Cadastros</span>
          </span>
          <svg class="sidebar-group-chevron h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="sidebar-group-panel space-y-1 px-2 pb-2">
          <a href="<?= $base ?>/admin/empresas" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/empresas'], 'pl-10') ?>" data-admin-menu-close="1" title="Empresas" aria-label="Empresas">
            <span class="sidebar-link-label">Empresas</span>
          </a>
          <a href="<?= $base ?>/admin/setores" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/setores'], 'pl-10') ?>" data-admin-menu-close="1" title="Setores" aria-label="Setores">
            <span class="sidebar-link-label">Setores</span>
          </a>
          <a href="<?= $base ?>/admin/cargos" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/cargos'], 'pl-10') ?>" data-admin-menu-close="1" title="Cargos" aria-label="Cargos">
            <span class="sidebar-link-label">Cargos</span>
          </a>
          <?php if ($veColaboradores): ?>
          <a href="<?= $base ?>/admin/colaboradores" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/colaboradores'], 'pl-10') ?>" data-admin-menu-close="1" title="Colaboradores" aria-label="Colaboradores">
            <span class="sidebar-link-label">Colaboradores</span>
          </a>
          <?php endif; ?>
          <a href="<?= $base ?>/admin/beneficios" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/beneficios'], 'pl-10') ?>" data-admin-menu-close="1" title="Benefícios" aria-label="Benefícios">
            <span class="sidebar-link-label">Benefícios</span>
          </a>
          <a href="<?= $base ?>/admin/avaliacoes" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/avaliacoes'], 'pl-10') ?>" data-admin-menu-close="1" title="Avaliações" aria-label="Avaliações">
            <span class="sidebar-link-label">Avaliações</span>
          </a>
          <?php if ($veUsuarios): ?>
            <a href="<?= $base ?>/admin/usuarios" class="sidebar-sub-link <?= $sidebarLinkClass(['/admin/usuarios'], 'pl-10') ?>" data-admin-menu-close="1" title="Usuários" aria-label="Usuários">
              <span class="sidebar-link-label">Usuários</span>
            </a>
          <?php endif; ?>
        </div>
      </details>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/vagas" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/vagas']) ?>" data-admin-menu-close="1" title="Vagas" aria-label="Vagas">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 8V6h6v2"/><rect x="3" y="8" width="18" height="12" rx="2"/></svg>
        <span class="sidebar-link-label">Vagas</span>
      </a>
      <?php
        // Item de menu é só representação — a rota continua protegida no backend
        // independentemente desta condição (AdminSolicitacoesVagaController/Kanban). Admin/RH/
        // supervisor sempre veem o item (compatível com o comportamento anterior); demais usuários
        // precisam de ao menos uma permissão do módulo, individual ou herdada de
        // `pode_solicitar_vaga` (a migration/seed já converge as duas fontes).
        $vePedidosDeVaga = Auth::role() === 'admin' || Auth::role() === 'rh' || !empty($_SESSION['user_is_supervisor'])
            || Authorization::temPermissao('solicitacao_vaga.visualizar')
            || Authorization::temPermissao('solicitacao_vaga.criar')
            || Authorization::temPermissao('kanban_vagas.visualizar');
      ?>
      <?php if ($vePedidosDeVaga): ?>
      <a href="<?= $base ?>/admin/solicitacoes-vaga" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/solicitacoes-vaga']) ?>" data-admin-menu-close="1" title="Solicitações de vaga" aria-label="Solicitações de vaga">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 6h10"/><path d="M8 12h10"/><path d="M8 18h10"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg>
        <span class="sidebar-link-label">Solicitações de vaga</span>
      </a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/movimentacoes-pessoal" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/movimentacoes-pessoal']) ?>" data-admin-menu-close="1" title="Movimentação de pessoal" aria-label="Movimentação de pessoal">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17V7"/><path d="M17 17V7"/><path d="M12 20V4"/><path d="M4 9h16"/><path d="M4 15h16"/></svg>
        <span class="sidebar-link-label">Movimentação de pessoal</span>
      </a>
      <?php if ($veAdminRh('indicacoes.visualizar')): ?>
      <a href="<?= $base ?>/admin/indicacoes" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/indicacoes']) ?>" data-admin-menu-close="1" title="Programa de Indicações" aria-label="Programa de Indicações">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l3.1 6.3 7 .9-5 4.8 1.2 6.9L12 18.6 5.7 22l1.2-6.9-5-4.8 7-.9L12 3z"/></svg>
        <span class="sidebar-link-label">Programa de Indicações</span>
      </a>
      <?php endif; ?>
      <a href="<?= Security::e($publicJobsUrl) ?>" target="_blank" rel="noopener noreferrer" class="sidebar-primary-link sidebar-link" data-admin-menu-close="1" title="Link vagas públicas" aria-label="Link vagas públicas">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="sidebar-link-label">Link vagas públicas</span>
      </a>
      <a href="<?= $base ?>/admin/manual" class="sidebar-primary-link <?= $sidebarLinkClass(['/admin/manual']) ?>" data-admin-menu-close="1" title="Manual de Uso" aria-label="Manual de Uso">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M4 4.5A2.5 2.5 0 016.5 7H20"/><path d="M6.5 7H20v12H6.5A2.5 2.5 0 014 16.5v-12z"/></svg>
        <span class="sidebar-link-label">Manual de Uso</span>
      </a>
      <a href="<?= $base ?>/admin/logout" class="sidebar-primary-link sidebar-link sidebar-link-danger" data-admin-menu-close="1" title="Sair" aria-label="Sair">
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
