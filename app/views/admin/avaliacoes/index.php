<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$queryBase = $base . '/admin/avaliacoes';
$q = (string)($filters['q'] ?? '');
$colaboradorId = (int)($filters['colaborador_id'] ?? 0);
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'cadastros', 'avaliacoes', ['titulo' => 'Avaliações de desempenho', 'descricao' => 'Cadastre e mantenha as avaliações que alimentam os fluxos de movimentação de pessoal.']) ?>
  <div class="flex flex-wrap items-center gap-2"><a href="<?= $base ?>/admin/avaliacoes/novo" class="<?= ui_btn('primario') ?>">Nova avaliação</a></div>

  <?php
  // Centralização das Avaliações (Bloco H): esta tela cobre Avaliações de Desempenho. Os outros dois tipos de "avaliação"
  // do Portal vivem dentro de outros fluxos — este bloco só ORIENTA para onde ir, sem duplicar rota/dado/lógica nenhuma.
  $podeVerPesquisaExperiencia = Authorization::temPermissao('pesquisa_experiencia.visualizar');
  ?>
  <details class="rounded-ds-lg border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
    <summary class="cursor-pointer font-semibold text-text-primary">Outras avaliações do Portal</summary>
    <ul class="mt-2 list-disc space-y-1 pl-5">
      <li>Precisa da <strong>Avaliação após 90 dias</strong> de uma contratação? Ela fica na seção "Controle interno RH" do detalhe de uma <a href="<?= $base ?>/admin/solicitacoes-vaga" class="text-primary-700 hover:underline">Solicitação de Vaga</a> já aprovada.</li>
      <?php if ($podeVerPesquisaExperiencia): ?>
        <li>Precisa da <strong>Pesquisa de Experiência do Candidato</strong> (respondida pelo próprio candidato)? Ela fica no detalhe da <a href="<?= $base ?>/admin/candidaturas" class="text-primary-700 hover:underline">candidatura</a> correspondente, dentro de Recrutamento e Seleção.</li>
      <?php endif; ?>
    </ul>
    <a href="<?= $base ?>/admin/manual#avaliacoes" class="mt-2 inline-block text-primary-700 hover:underline">Ver a explicação completa no Manual de Uso →</a>
  </details>
  <div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="mt-4 rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-text-primary">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Colaborador, título ou período" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Colaborador</label>
        <select name="colaborador_id" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todos</option>
          <?php foreach ($colaboradorOptions as $item): ?>
            <option value="<?= (int)$item['id'] ?>" <?= $colaboradorId === (int)$item['id'] ? 'selected' : '' ?>>
              <?= Security::e($item['nome'] . (!empty($item['matricula']) ? ' - ' . $item['matricula'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end gap-3">
        <button class="<?= ui_btn('primario') ?>">Filtrar</button>
        <a href="<?= $queryBase ?>" class="<?= ui_btn('secundario') ?>">Limpar</a>
      </div>
    </form>
  </div>

  <div class="responsive-table-wrap mt-4">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Cargo</th>
          <th class="p-3">Título</th>
          <th class="p-3">Período</th>
          <th class="p-3">Nota</th>
          <th class="p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($avaliacoes ?? []) as $avaliacao): ?>
          <tr class="border-b">
            <td class="p-3 font-medium text-text-primary"><?= Security::e($avaliacao['colaborador_nome']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['cargo_nome']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['titulo']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['periodo_referencia'] ?? '') ?></td>
            <td class="p-3"><?= $avaliacao['nota'] !== null ? Security::e(number_format((float)$avaliacao['nota'], 2, ',', '.')) : '—' ?></td>
            <td class="p-3">
              <div class="responsive-card-actions">
                <a href="<?= $base ?>/admin/avaliacoes/editar/<?= (int)$avaliacao['id'] ?>" class="text-primary-700 hover:text-primary-700">Editar</a>
                <form action="<?= $base ?>/admin/avaliacoes/excluir/<?= (int)$avaliacao['id'] ?>" method="post" class="inline" data-confirm-message="Excluir esta avaliação?">
                  <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                  <button type="submit" class="text-danger hover:text-danger">Excluir</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($avaliacoes)): ?>
          <tr>
            <td colspan="6" class="p-6 text-center text-sm text-text-secondary">Nenhuma avaliação encontrada para os filtros informados.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
