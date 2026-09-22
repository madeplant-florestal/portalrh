<?php
/**
 * Novo PDI — passo 1: escolher o colaborador (CONTRATO oficial do METADADOS). Sem CPF. Só contratos sem desligamento
 * efetivado. O `metadados_id` só trafega em URL/formulário administrativos (nunca em página pública).
 */
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
?>
<?php require_once __DIR__ . '/_helpers.php'; ?>
<div class="space-y-5">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'PDI', 'href' => $base . '/admin/pdis'], ['label' => 'Novo PDI']]) ?>
  <?= ui_page_header([
      'titulo' => 'Novo PDI — escolha o colaborador',
      'descricao' => 'Busque pelo nome, empresa ou unidade. O PDI fica vinculado ao contrato oficial do METADADOS.',
  ]) ?>
  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashErro) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/pdis/novo" class="flex flex-wrap items-end gap-3 rounded-ds-lg border border-border bg-white p-4">
    <div class="min-w-[14rem] flex-1">
      <label for="busca" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Colaborador</label>
      <input id="busca" type="text" name="busca" maxlength="60" value="<?= Security::e($busca) ?>" class="<?= $campo ?>" placeholder="Digite ao menos 2 letras" autofocus>
    </div>
    <button type="submit" class="<?= ui_btn('primario') ?>">Buscar</button>
  </form>

  <?php if ($busca !== '' && $contratos === []): ?>
    <p class="text-sm text-text-secondary">Nenhum contrato ativo encontrado para "<?= Security::e($busca) ?>".</p>
  <?php endif; ?>
  <?php if ($contratos !== []): ?>
    <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
      <table class="min-w-full text-sm">
        <thead><tr class="border-b text-left text-text-secondary"><th class="p-3">Colaborador</th><th class="p-3">Empresa</th><th class="p-3">Unidade</th><th class="p-3">Cargo</th><th class="p-3">Admissão</th><th class="p-3"></th></tr></thead>
        <tbody>
          <?php foreach ($contratos as $c): ?>
            <tr class="border-b">
              <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$c['nome']) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['empresa'] ?? '—')) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['unidade'] ?? '—')) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['cargo'] ?? '—')) ?></td>
              <td class="p-3 text-text-secondary"><?= !empty($c['admissao']) ? Security::e(date('d/m/Y', strtotime((string)$c['admissao']))) : '—' ?></td>
              <td class="p-3"><a href="<?= $base ?>/admin/pdis/novo?contrato=<?= (int)$c['metadados_id'] ?>" class="<?= ui_btn('primario') ?>">Selecionar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-text-secondary">Até 20 resultados. Refine a busca para localizar outro colaborador.</p>
  <?php endif; ?>
</div>
