<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isActive = !empty($user->email_verified_at);
$podeSolicitarVaga = (int)($user->pode_solicitar_vaga ?? 0) === 1;
$aprovador = $aprovador ?? null;
$aprovadorOptions = $aprovadorOptions ?? [];
$vinculoMetadados = $vinculoMetadados ?? null;
// RH (Sprint Solicitação de Vaga — Etapa 2) só administra o bloco de Contexto Organizacional
// (Cargo/Setores/vínculo METADADOS) desta tela. Acesso operacional (Solicitação de Vagas),
// status da conta e senha continuam exclusivos de Admin/supervisor.
$isAdminAtor = !empty($isAdminAtor);
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'pessoas', 'usuarios', [
      'titulo' => (string)$user->nome,
      'descricao' => 'Detalhes do usuário · ' . (string)$user->email,
      'badge' => ['texto' => $isActive ? 'Ativo' : 'Inativo', 'tom' => $isActive ? 'success' : 'neutro'],
  ], [['label' => (string)$user->nome, 'href' => null]]) ?>
<div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <!--
    Ajuste "Tela de Usuários + Cobertura Completa de Permissões" (§9-11) + correção de largura:
    reorganização de LAYOUT apenas — nenhum campo, regra de edição/trava ou endpoint mudou.
    `.responsive-panel` (classe do projeto) já é `width:100%; max-width:100%` dentro de `.content`
    (que por sua vez já ocupa 100% do espaço após a sidebar) — um `max-w-6xl` chegou a ser aplicado
    aqui numa rodada anterior e SOBRESCREVIA essa largura fluida, causando a área vazia à direita em
    telas largas. Removido: a página agora usa a mesma largura fluida de qualquer outra tela admin,
    sem limite fixo em pixels. Desktop (lg: 1024px+) usa duas colunas num grid de 9 partes — esquerda
    5/9 (~55%: Detalhes/Solicitação de Vagas/vínculo METADADOS), direita 4/9 (~45%: Contexto
    Organizacional/Permissões de acesso); abaixo de lg, volta a 1 coluna, sem scroll horizontal.
  -->
  <div class="mt-6 grid gap-6 lg:grid-cols-9" data-usuario-detalhe-colunas="1">
    <div class="lg:col-span-5 space-y-6" data-usuario-detalhe-coluna-esquerda="1">
  <section class="rounded-ds-md border border-border bg-surface-secondary p-5">
  <h3 class="text-lg font-semibold text-text-primary">Identidade e status</h3>
  <div class="mt-3 grid gap-4 text-sm md:grid-cols-2">
    <div>
      <div class="text-text-secondary">Nome completo</div>
      <div class="font-medium text-text-primary"><?= Security::e($user->nome) ?></div>
    </div>
    <div>
      <div class="text-text-secondary">E-mail</div>
      <div class="font-medium text-text-primary"><?= Security::e($user->email) ?></div>
    </div>
    <div>
      <div class="text-text-secondary">Permissão</div>
      <div class="font-medium text-text-primary"><?= Security::e(strtoupper($user->role)) ?></div>
    </div>
    <div>
      <div class="text-text-secondary">Status</div>
      <div class="mt-1"><?= ui_badge($isActive ? 'Ativo' : 'Inativo', $isActive ? 'success' : 'neutro') ?></div>
    </div>
    <div>
      <div class="text-text-secondary">Data de cadastro</div>
      <div class="font-medium text-text-primary"><?= !empty($user->created_at) ? date('d/m/Y H:i', strtotime((string)$user->created_at)) : '-' ?></div>
    </div>
    <div>
      <div class="text-text-secondary">Último reset de senha</div>
      <div class="font-medium text-text-primary"><?= !empty($user->last_password_reset_at) ? date('d/m/Y H:i', strtotime((string)$user->last_password_reset_at)) : '-' ?></div>
    </div>
  </div>
  </section>

  <?php
    // Gestor Imediato (usuarios.gestor_usuario_id): hierarquia própria do Portal, independente do aprovador de vaga.
    $gestor = $gestor ?? null;
    $gestorOptions = $gestorOptions ?? [];
    $gestorAtualId = $gestor !== null ? (int)$gestor['id'] : 0;
    $gestorAtualNaLista = false;
    foreach ($gestorOptions as $optGestor) {
        if ((int)$optGestor['id'] === $gestorAtualId) { $gestorAtualNaLista = true; }
    }
  ?>
  <section class="rounded-ds-md border border-border bg-surface-secondary p-5" id="gestor-imediato">
    <h3 class="text-lg font-semibold text-text-primary">Gestor imediato</h3>
    <p class="mt-1 text-sm text-text-secondary">
      Relação de hierarquia do Portal (outro usuário). É independente do aprovador de Solicitação de Vagas e não usa o cadastro legado de líderes.
    </p>
    <p class="mt-3 text-sm text-text-primary">
      <span class="text-text-secondary">Gestor configurado:</span>
      <?php if ($gestor === null): ?>
        <span class="font-medium">Sem gestor definido</span>
      <?php else: ?>
        <span class="font-medium"><?= Security::e((string)$gestor['nome']) ?></span>
        <span class="text-text-secondary">— <?= Security::e((string)$gestor['email']) ?></span>
        <?php if (empty($gestor['ativo'])): ?><?= ui_badge('Inativo', 'neutro', 'ml-1') ?><?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($liderados)): ?><span class="ml-2 text-xs text-text-secondary">· este usuário lidera <?= (int)$liderados ?> pessoa(s) diretamente</span><?php endif; ?>
    </p>
    <?php if ($gestor !== null && empty($gestor['ativo'])): ?>
      <p class="mt-2 rounded border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-warning">O gestor configurado está inativo. Ele foi mantido — substitua ou remova quando desejar.</p>
    <?php endif; ?>
    <?php if ($isAdminAtor): ?>
    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/gestor" method="post" class="mt-4 space-y-3">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label for="gestor_usuario_id" class="block text-sm font-medium text-text-primary">Alterar gestor imediato</label>
        <select id="gestor_usuario_id" name="gestor_usuario_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          <option value="">— Sem gestor definido</option>
          <?php if ($gestor !== null && !$gestorAtualNaLista): ?>
            <option value="<?= $gestorAtualId ?>" selected><?= Security::e((string)$gestor['nome'] . ' — ' . (string)$gestor['email']) ?> (atual<?= empty($gestor['ativo']) ? ', inativo' : '' ?>)</option>
          <?php endif; ?>
          <?php foreach ($gestorOptions as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>" <?= $gestorAtualId === (int)$opt['id'] ? 'selected' : '' ?>><?= Security::e(UsuarioGestorService::rotuloOpcao($opt)) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-xs text-text-secondary">Só usuários ativos, nunca o próprio usuário nem quem já é liderado dele (evita ciclos).</p>
      </div>
      <button class="<?= ui_btn('primario') ?>">Salvar gestor imediato</button>
    </form>
    <?php else: ?>
    <p class="mt-3 text-sm text-text-secondary">O gestor imediato é gerenciado por um administrador.</p>
    <?php endif; ?>
  </section>

  <section class="rounded-ds-md border border-border bg-surface-secondary p-5">
    <h3 class="text-lg font-semibold text-text-primary">Solicitação de Vagas</h3>
    <p class="mt-1 text-sm text-text-secondary">
      A autorização e a hierarquia de aprovação pertencem ao usuário — não dependem de existir em Colaboradores nem no METADADOS.
      Um gestor PJ/terceiro opera normalmente sem qualquer vínculo.
    </p>

    <?php if ($isAdminAtor): ?>
    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/vaga-acesso" method="post" class="mt-4 space-y-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <label class="flex items-center gap-3 text-sm">
        <input type="checkbox" name="pode_solicitar_vaga" value="1" <?= $podeSolicitarVaga ? 'checked' : '' ?> class="h-4 w-4 rounded border-border">
        <span class="font-medium text-text-primary">Pode solicitar vaga</span>
      </label>

      <div>
        <label class="block text-sm font-medium text-text-primary">Aprovador / líder imediato</label>
        <select name="aprovador_usuario_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          <option value="">— Sem aprovador configurado</option>
          <?php foreach ($aprovadorOptions as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>" <?= (int)($user->aprovador_usuario_id ?? 0) === (int)$opt['id'] ? 'selected' : '' ?>>
              <?= Security::e($opt['nome'] . ' (' . strtoupper((string)$opt['role']) . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1 text-xs text-text-secondary">
          Usado na 1ª etapa de aprovação (líder imediato). Se vazio: usuários comuns não conseguem concluir o envio;
          RH/Admin seguem direto para a etapa de RH.
        </p>
      </div>

      <button class="<?= ui_btn('primario') ?>">Salvar acesso a vagas</button>
    </form>
    <?php else: ?>
    <p class="mt-4 text-sm text-text-secondary">Acesso operacional a Solicitação de Vagas (autorização e aprovador) é gerenciado por um administrador.</p>
    <?php endif; ?>

    <div class="mt-6 border-t border-border pt-4">
      <div class="text-sm font-medium text-text-primary">Vínculo opcional com o METADADOS</div>
      <?php if ($vinculoMetadados): ?>
        <div class="mt-2 rounded-lg border border-border bg-white p-3 text-sm">
          <div class="font-semibold text-text-primary"><?= Security::e((string)$vinculoMetadados['nome']) ?></div>
          <div class="mt-1 text-text-secondary">
            <?= Security::e(trim(implode(' · ', array_filter([
              (string)($vinculoMetadados['empresa'] ?? ''),
              (string)($vinculoMetadados['unidade'] ?? ''),
              (string)($vinculoMetadados['setor'] ?? ''),
              (string)($vinculoMetadados['cargo'] ?? ''),
              'Contrato ' . (string)($vinculoMetadados['numero_contrato'] ?? ''),
            ])))) ?>
            <?= (int)($vinculoMetadados['ativo'] ?? 0) === 1 ? '' : ' — contrato desligado' ?>
          </div>
          <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/metadados-vinculo" method="post" class="mt-3"
                data-desvincular-metadados="1"
                data-confirm-message="Desvincular este usuário do METADADOS?&#10;&#10;O vínculo com o contrato oficial será removido. O Cargo e os Setores atuais serão preservados como contexto manual e poderão ser alterados posteriormente.">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="acao" value="desvincular">
            <input type="hidden" name="confirmar_desvinculo" value="1">
            <p class="mb-2 text-xs text-text-secondary">
              O vínculo com o contrato oficial será removido. O Cargo e os Setores atuais são
              preservados como contexto manual e passam a ser editáveis.
            </p>
            <button class="text-sm text-danger hover:text-danger">Desvincular do METADADOS</button>
          </form>
        </div>
      <?php else: ?>
        <p class="mt-1 text-xs text-text-secondary">Nenhum vínculo. O usuário funciona normalmente sem vínculo (caso PJ/terceiro).</p>
        <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/metadados-vinculo" method="post" class="mt-3 space-y-2" data-metadados-vinculo="1" data-busca-url="<?= $base ?>/admin/usuarios/metadados/buscar">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <input type="hidden" name="colaborador_metadados_id" value="" data-metadados-id="1">
          <input type="text" placeholder="Buscar pessoa/contrato ativo (nome, empresa, setor, cargo)" autocomplete="off"
                 class="w-full rounded border px-3 py-2 text-sm" data-metadados-busca="1">
          <div class="hidden rounded-lg border border-border bg-white text-sm" data-metadados-resultados="1"></div>
          <div class="hidden text-xs text-text-secondary" data-metadados-selecionado="1"></div>
          <button class="<?= ui_btn('primario') ?>" data-metadados-submit="1" disabled>Vincular contrato oficial</button>
        </form>
        <?php ui_script_pagina('usuarios.js'); // JS movido para assets/usuarios.js (CSP: sem <script> inline) ?>
      <?php endif; ?>
    </div>
  </section>
    </div>

    <div class="lg:col-span-4 space-y-6" data-usuario-detalhe-coluna-direita="1">
  <?php
    $ctx = $contexto ?? [];
    $cargosOficiais = $cargosOficiais ?? [];
    $setoresOficiais = $setoresOficiais ?? [];
    $cargoTravado = !empty($ctx['cargo_travado']);
    $setorPrincipalTravado = !empty($ctx['setor_principal_travado']);
    $vinculado = !empty($ctx['vinculado']);
    $principalId = $ctx['setor_principal']['setor_id'] ?? null;
    $adicionaisIds = array_map(static fn ($s) => (int)$s['setor_id'], $ctx['setores_adicionais'] ?? []);
    $rotuloCatalogo = static fn (array $r): string => trim((string)($r['descricao_oficial'] ?? '')) !== ''
        ? (string)$r['descricao_oficial'] : (string)$r['nome'];
  ?>
  <section class="rounded-ds-md border border-border bg-surface-secondary p-5">
    <h3 class="flex items-center gap-2 text-lg font-semibold text-text-primary">
      <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M12 9v3M6 15v-1a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/></svg>
      Contexto organizacional
    </h3>
    <p class="mt-1 text-sm text-text-secondary">
      Cargo e Setores usam exclusivamente os catálogos oficiais do METADADOS. Com vínculo a um
      contrato oficial, o Cargo principal e — quando o contrato informa — o Setor principal são
      herdados e ficam somente leitura.
    </p>

    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/contexto-organizacional" method="post" class="mt-4 space-y-5">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

      <div>
        <span class="block text-sm font-medium text-text-primary">Cargo principal</span>
        <?php if ($cargoTravado): ?>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <span class="font-medium text-text-primary"><?= Security::e((string)($ctx['cargo_rotulo'] ?? '—')) ?></span>
            <?= ui_badge('Herdado do METADADOS', 'primary') ?>
          </div>
          <p class="mt-1 text-xs text-text-secondary">Definido pelo contrato oficial vinculado. Não editável enquanto o vínculo existir.</p>
        <?php elseif ($vinculado && !empty($ctx['cargo_aviso'])): ?>
          <div class="mt-1 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-warning">
            <?= Security::e((string)$ctx['cargo_aviso']) ?>
          </div>
        <?php else: ?>
          <select name="cargo_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
            <option value="">— Sem cargo definido —</option>
            <?php foreach ($cargosOficiais as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($ctx['cargo_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= Security::e($rotuloCatalogo($c)) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-text-secondary">Somente cargos oficiais. Cargo não define autorização nesta fase.</p>
        <?php endif; ?>
      </div>

      <div>
        <span class="block text-sm font-medium text-text-primary">Setor principal</span>
        <?php if ($setorPrincipalTravado): ?>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <span class="font-medium text-text-primary"><?= Security::e((string)($ctx['setor_principal']['rotulo'] ?? '—')) ?></span>
            <?= ui_badge('Herdado do METADADOS', 'primary') ?>
          </div>
          <p class="mt-1 text-xs text-text-secondary">Definido pelo Setor oficial do contrato. Não substituível manualmente.</p>
        <?php else: ?>
          <?php if ($vinculado && !empty($ctx['setor_aviso'])): ?>
            <div class="mt-1 mb-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-warning">
              <?= Security::e((string)$ctx['setor_aviso']) ?> — selecione um Setor principal manualmente.
            </div>
          <?php endif; ?>
          <select name="setor_principal_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
            <option value="">— Sem setor principal —</option>
            <?php foreach ($setoresOficiais as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)($principalId ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                <?= Security::e($rotuloCatalogo($s)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <div>
        <span class="block text-sm font-medium text-text-primary">Setores adicionais de atuação</span>
        <p class="mt-1 text-xs text-text-secondary">Escopo extra concedido manualmente (origem MANUAL). O Setor principal não aparece aqui.</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
          <?php foreach ($setoresOficiais as $s): ?>
            <?php if ((int)$s['id'] === (int)($principalId ?? 0)) { continue; } ?>
            <label class="flex items-center gap-2 rounded-lg border border-border bg-white px-3 py-2 text-sm">
              <input type="checkbox" name="setores_adicionais[]" value="<?= (int)$s['id'] ?>"
                     class="h-4 w-4 rounded border-border"
                     <?= in_array((int)$s['id'], $adicionaisIds, true) ? 'checked' : '' ?>>
              <span class="text-text-primary"><?= Security::e($rotuloCatalogo($s)) ?></span>
            </label>
          <?php endforeach; ?>
          <?php if ($setoresOficiais === []): ?>
            <p class="text-sm text-text-secondary">Nenhum setor oficial disponível no catálogo.</p>
          <?php endif; ?>
        </div>
      </div>

      <button class="<?= ui_btn('primario') ?>">Salvar contexto organizacional</button>
    </form>
  </section>

  <?php if ($isAdminAtor): ?>
  <?php
    $catalogoPermissoes = $catalogoPermissoes ?? [];
    $permissoesAtribuidas = $permissoesAtribuidas ?? [];
    $rotuloModulo = [
      'solicitacao_vaga' => 'Solicitação de Vagas',
      'kanban_vagas' => 'Kanban de Vagas',
      'mensagens' => 'Mensagens',
      'comunicacoes' => 'Histórico de Comunicação',
      'pesquisa_experiencia' => 'Pesquisa de Experiência',
      'integracao_colaborador' => 'Integração do Colaborador',
    ];
  ?>
  <section class="rounded-ds-md border border-border bg-surface-secondary p-5">
    <h3 class="text-lg font-semibold text-text-primary">Permissões de acesso</h3>
    <p class="mt-1 text-sm text-text-secondary">
      Controla o que este usuário pode fazer em cada módulo, independentemente do papel (Permissão)
      dele. Administradores sempre têm acesso total; para os demais, só o que estiver marcado aqui.
    </p>

    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/permissoes" method="post" class="mt-4 space-y-5">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

      <div class="divide-y divide-border">
        <?php foreach ($catalogoPermissoes as $modulo => $itens): ?>
          <div class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:gap-4">
            <span class="text-sm font-medium text-text-primary sm:w-44 sm:shrink-0"><?= Security::e($rotuloModulo[$modulo] ?? ucfirst(str_replace('_', ' ', $modulo))) ?></span>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 sm:flex sm:flex-1 sm:flex-wrap sm:gap-x-5 sm:gap-y-2">
              <?php foreach ($itens as $permissao): ?>
                <label class="flex items-center gap-2 text-sm text-text-primary">
                  <input type="checkbox" name="permissao_ids[]" value="<?= (int)$permissao['id'] ?>"
                         class="h-4 w-4 shrink-0 rounded border-border"
                         <?= in_array((int)$permissao['id'], $permissoesAtribuidas, true) ? 'checked' : '' ?>>
                  <span><?= Security::e((string)$permissao['nome']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($catalogoPermissoes === []): ?>
        <p class="text-sm text-text-secondary">Nenhuma permissão cadastrada.</p>
      <?php endif; ?>

      <button class="<?= ui_btn('primario') ?>">Salvar permissões</button>
    </form>
  </section>
  <?php endif; ?>
    </div>
  </div>

  <?php if ($isAdminAtor): ?>
  <div class="responsive-form-actions mt-6">
    <form action="<?= $base ?>/admin/usuarios/<?= (int)$user->id ?>/status" method="post">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
      <button class="<?= ui_btn('primario') ?>">
        <?= $isActive ? 'Desativar usuário' : 'Ativar usuário' ?>
      </button>
    </form>
    <button type="button" id="open-password-modal" class="<?= ui_btn('secundario') ?>">
      Alterar Senha
    </button>
  </div>
  <?php endif; ?>
</div>
</div>
<?php if ($isAdminAtor): ?>
<div id="password-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="w-full max-w-md rounded bg-white p-6 shadow">
    <div class="responsive-header">
      <h3 class="text-lg font-semibold text-text-primary">Alterar senha do usuário</h3>
      <button type="button" id="close-password-modal" class="<?= ui_btn('secundario') ?>">Fechar</button>
    </div>
    <form id="password-change-form" class="mt-4 space-y-3" data-password-url="<?= $base ?>/api/admin/usuarios/<?= (int)$user->id ?>/password">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label class="block text-sm font-medium text-text-primary">Nova senha</label>
        <input type="password" name="new_password" required minlength="12" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Digite a nova senha">
      </div>
      <div class="text-sm text-text-secondary">Tem certeza que deseja alterar a senha deste usuário?</div>
      <div class="responsive-form-actions justify-end pt-2">
        <button type="button" id="cancel-password-modal" class="<?= ui_btn('secundario') ?>">Cancelar</button>
        <button type="submit" class="<?= ui_btn('primario') ?>">Confirmar alteração</button>
      </div>
    </form>
  </div>
</div>
<?php ui_script_pagina('usuarios.js'); // JS movido para assets/usuarios.js (CSP: sem <script> inline) ?>
<?php endif; ?>
