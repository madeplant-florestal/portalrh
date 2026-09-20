<?php
/**
 * Novo PDI — passo 1: escolher o colaborador (CONTRATO oficial do METADADOS). Sem CPF. Só contratos sem desligamento
 * efetivado. O `metadados_id` só trafega em URL/formulário administrativos (nunca em página pública).
 */
$campo = 'mt-1 w-full rounded-lg border border-[#E2DFD0] bg-white px-3 py-2 text-sm text-[#2B2E22]';
?>
<div class="responsive-panel space-y-5">
  <div class="responsive-header">
    <div>
      <a href="<?= $base ?>/admin/pdis" class="text-sm text-[#3B4822] hover:underline">&larr; PDIs</a>
      <h2 class="mt-1 text-xl font-semibold text-[#2B2E22]">Novo PDI — escolha o colaborador</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">Busque pelo nome, empresa ou unidade. O PDI fica vinculado ao contrato oficial do METADADOS.</p>
    </div>
  </div>

  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashErro) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/pdis/novo" class="flex flex-wrap items-end gap-3 rounded-2xl border border-[#E2DFD0] bg-white p-4">
    <div class="min-w-[14rem] flex-1">
      <label for="busca" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Colaborador</label>
      <input id="busca" type="text" name="busca" maxlength="60" value="<?= Security::e($busca) ?>" class="<?= $campo ?>" placeholder="Digite ao menos 2 letras" autofocus>
    </div>
    <button type="submit" class="rounded-lg bg-[#3B4822] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#2E3919]">Buscar</button>
  </form>

  <?php if ($busca !== '' && $contratos === []): ?>
    <p class="text-sm text-[#5B5F4E]">Nenhum contrato ativo encontrado para "<?= Security::e($busca) ?>".</p>
  <?php endif; ?>
  <?php if ($contratos !== []): ?>
    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead><tr class="border-b text-left text-[#5B5F4E]"><th class="p-3">Colaborador</th><th class="p-3">Empresa</th><th class="p-3">Unidade</th><th class="p-3">Cargo</th><th class="p-3">Admissão</th><th class="p-3"></th></tr></thead>
        <tbody>
          <?php foreach ($contratos as $c): ?>
            <tr class="border-b">
              <td class="p-3 font-medium text-[#2B2E22]"><?= Security::e((string)$c['nome']) ?></td>
              <td class="p-3 text-[#5B5F4E]"><?= Security::e((string)($c['empresa'] ?? '—')) ?></td>
              <td class="p-3 text-[#5B5F4E]"><?= Security::e((string)($c['unidade'] ?? '—')) ?></td>
              <td class="p-3 text-[#5B5F4E]"><?= Security::e((string)($c['cargo'] ?? '—')) ?></td>
              <td class="p-3 text-[#5B5F4E]"><?= !empty($c['admissao']) ? Security::e(date('d/m/Y', strtotime((string)$c['admissao']))) : '—' ?></td>
              <td class="p-3"><a href="<?= $base ?>/admin/pdis/novo?contrato=<?= (int)$c['metadados_id'] ?>" class="rounded-lg bg-[#3B4822] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#2E3919]">Selecionar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-[#5B5F4E]">Até 20 resultados. Refine a busca para localizar outro colaborador.</p>
  <?php endif; ?>
</div>
