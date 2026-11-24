<?php
/**
 * Etapa 3.2C.1 — listagem de candidaturas do GESTOR (somente leitura). Recebe só a projeção segura (id, nome, vaga, setor, etapa, status,
 * data): sem e-mail, telefone, CPF, currículo, indicação nem observações, e SEM links para detalhe, PDF, documentos, Kanban ou formulários.
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$query = static function (array $extra) use ($filters): string {
    $q = array_filter(array_merge($filters, $extra), static fn($v) => $v !== 0 && $v !== '' && $v !== null);
    return $q === [] ? '' : '?' . http_build_query($q);
};
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'candidaturas', [
      'titulo' => 'Candidaturas',
      'descricao' => 'Candidatos das vagas dos setores que você gerencia (consulta). Contatos, currículos e documentos ficam com o RH.',
  ]) ?>
  <div class="responsive-panel">
  <form class="responsive-form-grid-4 mt-4" method="get" data-testid="filtros-candidaturas-setorial">
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-vaga">Vaga</label>
      <select id="f-vaga" name="vaga_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($vagas as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= (int)($filters['vaga_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= Security::e($v['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-setor">Setor</label>
      <select id="f-setor" name="setor_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos os meus setores</option>
        <?php foreach ($setores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)($filters['setor_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= Security::e($s['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-etapa">Etapa</label>
      <select id="f-etapa" name="stage_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($stages as $st): ?>
          <option value="<?= (int)$st['id'] ?>" <?= (int)($filters['stage_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>><?= Security::e($st['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-busca">Nome do candidato</label>
      <input id="f-busca" type="search" name="busca" maxlength="100" value="<?= Security::e($filters['busca'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-de">De</label>
      <input id="f-de" type="date" name="data_de" value="<?= Security::e($filters['data_de'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary" for="f-ate">Até</label>
      <div class="mt-1 flex flex-col gap-2 sm:flex-row">
        <input id="f-ate" type="date" name="data_ate" value="<?= Security::e($filters['data_ate'] ?? '') ?>" class="w-full flex-1 border rounded px-3 py-2 text-sm" />
        <button class="<?= ui_btn('primario') ?>">Filtrar</button>
      </div>
    </div>
  </form>

  <p class="mt-4 text-sm text-text-secondary" data-testid="total-candidaturas"><?= (int)$total ?> candidatura(s) encontrada(s).</p>

  <?php if (empty($candidaturas)): ?>
    <div class="mt-4 rounded border border-border bg-surface-secondary px-4 py-6 text-center text-sm text-text-secondary" data-testid="vazio-candidaturas">
      Nenhuma candidatura encontrada nas vagas dos setores que você gerencia.
    </div>
  <?php else: ?>
  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
      <thead class="bg-surface-secondary">
        <tr class="border-b">
          <th class="text-left p-3 font-medium text-text-secondary">#</th>
          <th class="text-left p-3 font-medium text-text-secondary">Candidato</th>
          <th class="text-left p-3 font-medium text-text-secondary">Vaga</th>
          <th class="text-left p-3 font-medium text-text-secondary">Setor</th>
          <th class="text-left p-3 font-medium text-text-secondary">Etapa</th>
          <th class="text-left p-3 font-medium text-text-secondary">Status</th>
          <th class="text-left p-3 font-medium text-text-secondary">Data</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border">
        <?php foreach ($candidaturas as $c): ?>
          <tr class="hover:bg-surface-secondary">
            <td class="p-3 text-text-secondary"><?= (int)$c['id'] ?></td>
            <td class="p-3 font-medium text-text-primary"><?= Security::e($c['nome']) ?></td>
            <td class="p-3 text-text-primary"><?= Security::e($c['vaga_titulo'] ?? '-') ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e($c['vaga_setor_nome'] ?? '-') ?></td>
            <td class="p-3"><span class="whitespace-nowrap px-2 py-1 rounded-ds-sm text-xs font-semibold text-white" style="background-color: <?= Security::e(preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($c['stage_cor'] ?? '')) ? $c['stage_cor'] : '#6b7280') ?>;"><?= Security::e($c['stage_nome'] ?? 'Sem etapa') ?></span></td>
            <td class="p-3 text-text-secondary"><?= Security::e(Candidatura::statusLabel((string)($c['status'] ?? ''))) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(substr((string)$c['created_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="responsive-card-list md:hidden">
      <?php foreach ($candidaturas as $c): ?>
        <div class="responsive-card">
          <div class="text-base font-semibold text-text-primary"><?= Security::e($c['nome']) ?></div>
          <div class="mt-1 text-sm text-text-secondary">Vaga: <?= Security::e($c['vaga_titulo'] ?? '-') ?></div>
          <div class="mt-1 text-sm text-text-secondary">Setor: <?= Security::e($c['vaga_setor_nome'] ?? '-') ?></div>
          <div class="mt-1 text-sm text-text-secondary">Etapa: <?= Security::e($c['stage_nome'] ?? 'Sem etapa') ?> · <?= Security::e(Candidatura::statusLabel((string)($c['status'] ?? ''))) ?></div>
          <div class="mt-1 text-xs text-text-muted">#<?= (int)$c['id'] ?> · <?= Security::e(substr((string)$c['created_at'], 0, 10)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($paginas > 1): ?>
    <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Paginação" data-testid="paginacao-candidaturas">
      <?php if ($pagina > 1): ?><a class="text-primary-700 hover:underline" href="<?= $base ?>/admin/candidaturas<?= Security::e($query(['page' => $pagina - 1])) ?>">Anterior</a><?php else: ?><span></span><?php endif; ?>
      <span class="text-text-secondary">Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span>
      <?php if ($pagina < $paginas): ?><a class="text-primary-700 hover:underline" href="<?= $base ?>/admin/candidaturas<?= Security::e($query(['page' => $pagina + 1])) ?>">Próxima</a><?php else: ?><span></span><?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
