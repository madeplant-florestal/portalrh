<?php
/**
 * Lista de PDIs (Plano de Desenvolvimento Individual) — no escopo do usuário: Admin/RH veem todos; os demais só os
 * PDIs em que são o gestor responsável. Sem Área. Atraso é só sinalização (nunca muda o status).
 */
require_once __DIR__ . '/_helpers.php';
$f = $lista['filtros'] ?? [];
$op = $lista['opcoes'] ?? ['empresas' => [], 'unidades' => [], 'cargos' => [], 'gestores' => []];
$campo = 'mt-1 w-full rounded-lg border border-[#E2DFD0] bg-white px-3 py-2 text-sm text-[#2B2E22]';
$rotulo = 'block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]';
$unidadeSelecionada = !empty($f['unidade']) ? $f['unidade']['codigo_empresa'] . '|' . $f['unidade']['codigo_unidade'] : '';
?>
<div class="responsive-panel space-y-5">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-[#2B2E22]">PDI — Plano de Desenvolvimento Individual</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">Processo de desenvolvimento acompanhado por RH e gestor. <?= $escopoTotal ? 'Você vê todos os PDIs.' : 'Você vê os PDIs em que é o gestor responsável.' ?></p>
    </div>
    <?php if (!empty($podeCriar)): ?>
      <a href="<?= $base ?>/admin/pdis/novo" class="rounded-lg bg-[#3B4822] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#2E3919]">Novo PDI</a>
    <?php endif; ?>
  </div>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashErro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/pdis" class="grid grid-cols-1 gap-3 rounded-2xl border border-[#E2DFD0] bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div>
      <label for="f-status" class="<?= $rotulo ?>">Status</label>
      <select id="f-status" name="status" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach (PdiService::STATUS as $k => $v): ?><option value="<?= Security::e($k) ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-prazo" class="<?= $rotulo ?>">Prazo</label>
      <select id="f-prazo" name="prazo" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach (PdiService::PRAZOS_FILTRO as $k => $v): ?><option value="<?= Security::e($k) ?>" <?= ($f['prazo'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-busca" class="<?= $rotulo ?>">Colaborador</label>
      <input id="f-busca" type="text" name="busca" maxlength="60" value="<?= Security::e((string)($f['busca'] ?? '')) ?>" class="<?= $campo ?>" placeholder="Nome">
    </div>
    <div>
      <label for="f-origem" class="<?= $rotulo ?>">Origem</label>
      <select id="f-origem" name="origem" class="<?= $campo ?>">
        <option value="">Todas</option>
        <?php foreach (PdiService::ORIGENS as $k => $v): ?><option value="<?= Security::e($k) ?>" <?= ($f['origem'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-empresa" class="<?= $rotulo ?>">Empresa</label>
      <select id="f-empresa" name="empresa" class="<?= $campo ?>">
        <option value="">Todas</option>
        <?php foreach ($op['empresas'] as $e): ?><option value="<?= Security::e((string)$e['codigo']) ?>" <?= ($f['empresa'] ?? '') === (string)$e['codigo'] ? 'selected' : '' ?>><?= Security::e((string)($e['nome'] ?: $e['codigo'])) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-unidade" class="<?= $rotulo ?>">Unidade</label>
      <select id="f-unidade" name="unidade" class="<?= $campo ?>">
        <option value="">Todas</option>
        <?php foreach ($op['unidades'] as $u): $chave = $u['codigo_empresa'] . '|' . $u['codigo_unidade']; ?>
          <option value="<?= Security::e($chave) ?>" <?= $unidadeSelecionada === $chave ? 'selected' : '' ?>><?= Security::e((string)($u['nome'] ?: $u['codigo_unidade'])) ?><?= !empty($u['empresa']) && $u['empresa'] !== $u['nome'] ? ' — ' . Security::e((string)$u['empresa']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-cargo" class="<?= $rotulo ?>">Cargo</label>
      <select id="f-cargo" name="cargo" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach ($op['cargos'] as $c): ?><option value="<?= Security::e((string)$c['codigo']) ?>" <?= ($f['cargo'] ?? '') === (string)$c['codigo'] ? 'selected' : '' ?>><?= Security::e((string)($c['nome'] ?: $c['codigo'])) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-gestor" class="<?= $rotulo ?>">Gestor</label>
      <select id="f-gestor" name="gestor" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach ($op['gestores'] as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)($f['gestor'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= Security::e((string)$g['nome']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
      <button type="submit" class="rounded-lg bg-[#3B4822] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#2E3919]">Filtrar</button>
      <a href="<?= $base ?>/admin/pdis" class="px-2 py-2 text-sm text-[#3B4822] hover:underline">Limpar</a>
    </div>
  </form>

  <div class="responsive-table-wrap">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-[#5B5F4E]">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Gestor</th>
          <th class="p-3">Origem</th>
          <th class="p-3">Status</th>
          <th class="p-3">Abertura</th>
          <th class="p-3">Previsão</th>
          <th class="p-3">Plano de ação</th>
          <th class="p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lista['itens'] as $p): ?>
          <tr class="border-b align-top">
            <td class="p-3 font-medium text-[#2B2E22]"><?= Security::e((string)$p['snap_nome']) ?>
              <span class="block text-xs font-normal text-[#5B5F4E]"><?= Security::e((string)($p['snap_cargo'] ?? '—')) ?> · <?= Security::e((string)($p['snap_unidade'] ?? $p['snap_empresa'] ?? '')) ?></span></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e((string)$p['gestor_nome_snapshot']) ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e(PdiService::ORIGENS[$p['origem_tipo']] ?? (string)$p['origem_tipo']) ?></td>
            <td class="p-3"><?= pdi_status_badge((string)$p['status']) ?>
              <?php if (!empty($p['prazo']['atrasado'])): ?><span class="mt-1 block"><?= pdi_atraso_badge($p['prazo']) ?></span><?php endif; ?>
              <?php if ((string)$p['status'] === 'concluido'): ?><span class="mt-1 block text-xs text-[#5B5F4E]"><?= Security::e(PdiService::AVALIACOES_FINAIS[$p['avaliacao_final']] ?? '') ?></span><?php endif; ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e(pdi_data_br($p['data_abertura'])) ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e(pdi_data_br($p['data_prevista_conclusao'])) ?></td>
            <td class="p-3"><?= pdi_barra_progresso($p['progresso']) ?>
              <?php if ($p['acoes_atrasadas'] > 0): ?><span class="mt-1 block text-[11px] font-semibold text-amber-800"><?= (int)$p['acoes_atrasadas'] ?> ação(ões) atrasada(s)</span><?php endif; ?></td>
            <td class="p-3"><a href="<?= $base ?>/admin/pdis/<?= (int)$p['id'] ?>" class="text-[#3B4822] hover:underline">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($lista['itens'])): ?>
          <tr><td colspan="8" class="p-4 text-center text-[#5B5F4E]">Nenhum PDI encontrado para os filtros informados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($lista['itens']) >= PdiService::LIMITE_LISTAGEM): ?>
    <p class="text-xs text-[#5B5F4E]">Mostrando os primeiros <?= PdiService::LIMITE_LISTAGEM ?> PDIs. Refine os filtros para localizar os demais.</p>
  <?php endif; ?>
</div>
