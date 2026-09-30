<?php
/**
 * Avaliação do Período de Experiência — lista de PENDÊNCIAS (Etapa 4, 2026-09). Derivada em tempo de
 * consulta (AvaliacaoExperienciaService::listarPendencias()) — não existe "tabela de pendências", só
 * as avaliações realmente salvas cruzadas com a janela 45/90 de cada contrato ativo.
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$rotulo = 'block text-xs font-semibold uppercase tracking-wide text-text-secondary';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary';
$f = $filtros ?? [];
$contagem = $lista['contagem'] ?? [];

$tomStatus = [
    'futura' => 'neutro', 'pendente' => 'warning', 'vencida' => 'danger', 'em_preenchimento' => 'info',
    'aguardando_ciencia' => 'info', 'realizada' => 'success', 'nao_aplicavel_desligado' => 'neutro', 'cancelada' => 'neutro',
];
$abaAtiva = $f['status'] !== '' ? $f['status'] : '';
$abas = [];
$abas[] = ['label' => 'Todas', 'href' => $base . '/admin/avaliacoes-experiencia', 'ativo' => $abaAtiva === ''];
foreach (['pendente', 'vencida', 'futura', 'em_preenchimento', 'aguardando_ciencia', 'realizada'] as $st) {
    $qtd = (int)($contagem[$st] ?? 0);
    $abas[] = [
        'label' => AvaliacaoExperienciaService::ROTULOS_STATUS[$st] . ' (' . $qtd . ')',
        'href' => $base . '/admin/avaliacoes-experiencia?status=' . $st,
        'ativo' => $abaAtiva === $st,
    ];
}
?>
<div class="space-y-5">
  <?= ui_modulo_topo($base, 'avaliacoes-desenvolvimento', 'avaliacao-experiencia', [
      'titulo' => 'Avaliação do Período de Experiência',
      'descricao' => 'Acompanhamento de adaptação, desempenho e alinhamento aos 45 e 90 dias. ' . (AvaliacaoExperienciaService::escopoTotal($ator) ? 'Você vê todos os colaboradores.' : 'Você vê os colaboradores em que é o gestor responsável.'),
  ]) ?>

  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <?= ui_module_tabs($abas, 'Filtro por status') ?>

  <form method="get" action="<?= $base ?>/admin/avaliacoes-experiencia" class="grid grid-cols-1 gap-3 rounded-ds-lg border border-border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
    <input type="hidden" name="status" value="<?= Security::e($f['status'] ?? '') ?>">
    <div>
      <label for="f-tipo" class="<?= $rotulo ?>">Tipo</label>
      <select id="f-tipo" name="tipo" class="<?= $campo ?>">
        <option value="">45 e 90 dias</option>
        <?php foreach (AvaliacaoExperienciaService::TIPOS as $k => $v): $k = (string)$k; ?><option value="<?= Security::e($k) ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-empresa" class="<?= $rotulo ?>">Empresa</label>
      <select id="f-empresa" name="empresa" class="<?= $campo ?>">
        <option value="">Todas</option>
        <?php foreach (($opcoes['empresas'] ?? []) as $e): ?><option value="<?= Security::e((string)$e['codigo_empresa']) ?>" <?= ($f['codigo_empresa'] ?? '') === (string)$e['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e((string)($e['empresa'] ?: $e['codigo_empresa'])) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="f-setor" class="<?= $rotulo ?>">Setor</label>
      <select id="f-setor" name="setor" class="<?= $campo ?>">
        <option value="">Todos</option>
        <?php foreach (($opcoes['setores'] ?? []) as $s): ?><option value="<?= Security::e((string)$s['codigo_setor']) ?>" <?= ($f['codigo_setor'] ?? '') === (string)$s['codigo_setor'] ? 'selected' : '' ?>><?= Security::e((string)($s['setor'] ?: $s['codigo_setor'])) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end gap-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Filtrar</button>
      <a href="<?= $base ?>/admin/avaliacoes-experiencia" class="<?= ui_btn('ghost') ?>">Limpar</a>
    </div>
  </form>

  <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Cargo</th>
          <th class="p-3">Setor</th>
          <th class="p-3">Gestor</th>
          <th class="p-3">Admissão</th>
          <th class="p-3">Tipo</th>
          <th class="p-3">Data prevista</th>
          <th class="p-3">Status</th>
          <th class="p-3">Resultado</th>
          <th class="p-3">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (($lista['itens'] ?? []) === []): ?>
          <tr><td colspan="10" class="p-6 text-center text-text-secondary">Nenhuma avaliação encontrada para os filtros selecionados.</td></tr>
        <?php else: ?>
          <?php foreach ($lista['itens'] as $item): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$item['nome']) ?></td>
              <td class="p-3"><?= Security::e((string)($item['cargo'] ?? '—') ?: '—') ?></td>
              <td class="p-3"><?= Security::e((string)($item['setor'] ?? '—') ?: '—') ?></td>
              <td class="p-3"><?= Security::e((string)($item['gestor_nome'] ?? 'Sem gestor definido')) ?></td>
              <td class="p-3"><?= Security::e(date('d/m/Y', strtotime((string)$item['admissao']))) ?></td>
              <td class="p-3"><?= Security::e(AvaliacaoExperienciaService::TIPOS[$item['tipo']]) ?></td>
              <td class="p-3"><?= Security::e($item['data_prevista']->format('d/m/Y')) ?></td>
              <td class="p-3"><?= ui_badge(AvaliacaoExperienciaService::ROTULOS_STATUS[$item['status']], $tomStatus[$item['status']] ?? 'neutro') ?></td>
              <td class="p-3"><?= Security::e($item['parecer'] !== null ? (AvaliacaoExperienciaService::PARECER_OPCOES[$item['parecer']] ?? (string)$item['parecer']) : '—') ?></td>
              <td class="p-3">
                <?php if (in_array($item['status'], ['nao_aplicavel_desligado', 'cancelada'], true)): ?>
                  <span class="text-xs text-text-muted">—</span>
                <?php else: ?>
                  <a href="<?= $base ?>/admin/avaliacoes-experiencia/<?= (int)$item['metadados_id'] ?>/<?= Security::e($item['tipo']) ?>" class="<?= ui_btn('secundario') ?> h-8 px-3 text-xs">
                    <?= in_array($item['status'], ['realizada', 'aguardando_ciencia'], true) ? 'Visualizar' : (($item['status'] === 'em_preenchimento') ? 'Continuar' : 'Avaliar') ?>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
