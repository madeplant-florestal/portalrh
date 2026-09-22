<?php
/**
 * Lista de PDIs (Plano de Desenvolvimento Individual) — no escopo do usuário: Admin/RH veem todos; os demais só os
 * PDIs em que são o gestor responsável. Sem Área. Atraso é só sinalização (nunca muda o status).
 */
require_once __DIR__ . '/_helpers.php';
$f = $lista['filtros'] ?? [];
$op = $lista['opcoes'] ?? ['empresas' => [], 'unidades' => [], 'cargos' => [], 'gestores' => []];
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
$rotulo = 'block text-xs font-semibold uppercase tracking-wide text-text-secondary';
$unidadeSelecionada = !empty($f['unidade']) ? $f['unidade']['codigo_empresa'] . '|' . $f['unidade']['codigo_unidade'] : '';
?>
<div class="space-y-5">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'PDI']]) ?>
  <?= ui_page_header([
      'titulo' => 'PDI — Plano de Desenvolvimento Individual',
      'descricao' => 'Processo de desenvolvimento acompanhado por RH e gestor. ' . ($escopoTotal ? 'Você vê todos os PDIs.' : 'Você vê os PDIs em que é o gestor responsável.'),
      'acao' => !empty($podeCriar) ? ['label' => 'Novo PDI', 'href' => $base . '/admin/pdis/novo'] : null,
  ]) ?>
  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashErro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <form method="get" action="<?= $base ?>/admin/pdis" class="grid grid-cols-1 gap-3 rounded-ds-lg border border-border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
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
      <button type="submit" class="<?= ui_btn('primario') ?>">Filtrar</button>
      <a href="<?= $base ?>/admin/pdis" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </div>
  </form>

  <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
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
            <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$p['snap_nome']) ?>
              <span class="block text-xs font-normal text-text-secondary"><?= Security::e((string)($p['snap_cargo'] ?? '—')) ?> · <?= Security::e((string)($p['snap_unidade'] ?? $p['snap_empresa'] ?? '')) ?></span></td>
            <td class="p-3 text-text-secondary"><?= Security::e((string)$p['gestor_nome_snapshot']) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(PdiService::ORIGENS[$p['origem_tipo']] ?? (string)$p['origem_tipo']) ?></td>
            <td class="p-3"><?= pdi_status_badge((string)$p['status']) ?>
              <?php if (!empty($p['prazo']['atrasado'])): ?><span class="mt-1 block"><?= pdi_atraso_badge($p['prazo']) ?></span><?php endif; ?>
              <?php if ((string)$p['status'] === 'concluido'): ?><span class="mt-1 block text-xs text-text-secondary"><?= Security::e(PdiService::AVALIACOES_FINAIS[$p['avaliacao_final']] ?? '') ?></span><?php endif; ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(pdi_data_br($p['data_abertura'])) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(pdi_data_br($p['data_prevista_conclusao'])) ?></td>
            <td class="p-3"><?= pdi_barra_progresso($p['progresso']) ?>
              <?php if ($p['acoes_atrasadas'] > 0): ?><span class="mt-1 block text-[11px] font-semibold text-warning"><?= (int)$p['acoes_atrasadas'] ?> ação(ões) atrasada(s)</span><?php endif; ?></td>
            <td class="p-3"><a href="<?= $base ?>/admin/pdis/<?= (int)$p['id'] ?>" class="text-primary-700 hover:underline">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($lista['itens'])): ?>
          <tr><td colspan="8" class="p-4 text-center text-text-secondary">Nenhum PDI encontrado para os filtros informados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($lista['itens']) >= PdiService::LIMITE_LISTAGEM): ?>
    <p class="text-xs text-text-secondary">Mostrando os primeiros <?= PdiService::LIMITE_LISTAGEM ?> PDIs. Refine os filtros para localizar os demais.</p>
  <?php endif; ?>
</div>
