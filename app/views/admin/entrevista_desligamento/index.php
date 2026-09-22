<?php
/**
 * Administração da Entrevista de Desligamento. Situação operacional apenas (nunca respostas): as
 * respostas individuais exigem `entrevista_desligamento.resultados`. Situação derivada de timestamps.
 * O link bruto só aparece uma vez, no bloco `$linkGerado` (resposta do POST de gerar/regenerar).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$painel = $painel ?? null;
$indicadores = $indicadores ?? null;
$filtros = $filtros ?? [];
$visao = (string)($painel['visao'] ?? 'elegiveis');
$fmtData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
$fmtDataHora = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y H:i', strtotime($d)) : '—';

$abas = [
    'elegiveis' => ['Elegíveis', (int)($painel['elegiveis_total'] ?? 0)],
    'pendente' => ['Pendentes', (int)($painel['contagem']['pendentes'] ?? 0)],
    'respondida' => ['Respondidas', (int)($painel['contagem']['respondidas'] ?? 0)],
    'expirada' => ['Expiradas', (int)($painel['contagem']['expiradas'] ?? 0)],
    'cancelada' => ['Canceladas', (int)($painel['contagem']['canceladas'] ?? 0)],
];
$badges = [
    'pendente' => ['Pendente', 'bg-primary-50 text-primary-800'],
    'respondida' => ['Respondida', 'bg-success/10 text-success'],
    'expirada' => ['Expirada', 'bg-warning/10 text-warning'],
    'cancelada' => ['Cancelada', 'bg-surface-secondary text-text-muted'],
];
$querySemVisao = static fn(array $extra): string => http_build_query(array_filter($extra, static fn($v) => $v !== '' && $v !== null));
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'desligamento', 'entrevistas', [
      'titulo' => 'Entrevistas de Desligamento',
      'descricao' => 'Contratos oficiais desligados (METADADOS). Gere o link, copie e envie ao ex-colaborador pelo canal que preferir — o link é exibido uma única vez.',
  ]) ?>

  <?php if (!empty($erro)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert"><?= Security::e($erro) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if (!empty($linkGerado)): ?>
    <div class="rounded-ds-md border border-primary-300 bg-primary-50 px-4 py-3">
      <p class="text-sm font-semibold text-primary-800">Link gerado para <?= Security::e((string)$linkGerado['nome']) ?> — copie agora, ele não será mostrado novamente:</p>
      <p class="mt-2 break-all rounded-ds-md bg-surface px-3 py-2 text-sm text-text-primary"><?= Security::e((string)$linkGerado['url']) ?></p>
      <p class="mt-2 text-xs text-text-secondary">Válido até <?= Security::e($fmtDataHora($linkGerado['expira_em'])) ?>. Se perder o link, use “Regenerar link” (o anterior deixa de funcionar).</p>
    </div>
  <?php endif; ?>

  <?php if ($indicadores !== null): ?>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" aria-label="Resumo">
      <?php
      $cards = [
          ['Geradas', (string)$indicadores['geradas']],
          ['Pendentes', (string)$indicadores['pendentes']],
          ['Respondidas', (string)$indicadores['respondidas']],
          ['Expiradas', (string)$indicadores['expiradas']],
          ['Taxa de resposta', $indicadores['taxa_resposta'] === null ? '—' : number_format((float)$indicadores['taxa_resposta'], 1, ',', '.') . '%'],
      ];
      if (!empty($podeResultados)) {
          $enps = $indicadores['enps'];
          $cards[] = ['eNPS', $enps === null ? '—' : ($enps > 0 ? '+' : '') . number_format((float)$enps, 1, ',', '.')];
      }
      foreach ($cards as [$rotulo, $valorCard]): ?>
        <div class="rounded-ds-md border border-border bg-surface px-3 py-2.5">
          <p class="text-xs text-text-secondary"><?= Security::e($rotulo) ?></p>
          <p class="text-lg font-bold text-text-primary"><?= Security::e($valorCard) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
    <?php if (!empty($podeResultados) && !empty($indicadores['enps_distribuicao']) && $indicadores['enps_distribuicao']['total'] > 0): $dist = $indicadores['enps_distribuicao']; ?>
      <p class="text-xs text-text-secondary">eNPS sobre <?= (int)$dist['total'] ?> resposta(s): <?= (int)$dist['promotores'] ?> promotor(es) · <?= (int)$dist['neutros'] ?> neutro(s) · <?= (int)$dist['detratores'] ?> detrator(es). Taxa de resposta = respondidas ÷ geradas.</p>
    <?php endif; ?>
  <?php endif; ?>

  <nav class="flex flex-wrap gap-2" aria-label="Situação">
    <?php foreach ($abas as $chave => [$rotuloAba, $qtd]): $ativa = $visao === $chave; ?>
      <a href="<?= $base ?>/admin/entrevistas-desligamento?<?= Security::e($querySemVisao(['visao' => $chave])) ?>"
         class="rounded-full border px-3.5 py-1.5 text-sm <?= $ativa ? 'border-primary-700 bg-primary-700 font-semibold text-white' : 'border-border bg-surface text-text-primary hover:bg-primary-50' ?>"
         <?= $ativa ? 'aria-current="page"' : '' ?>><?= Security::e($rotuloAba) ?> (<?= $qtd ?>)</a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="<?= $base ?>/admin/entrevistas-desligamento" class="grid grid-cols-1 gap-3 rounded-ds-lg border border-border bg-surface p-4 shadow-resting sm:grid-cols-2 lg:grid-cols-4">
    <input type="hidden" name="visao" value="<?= Security::e($visao) ?>">
    <div>
      <label for="filtro-busca" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Nome</label>
      <input id="filtro-busca" type="text" name="busca" maxlength="60" value="<?= Security::e((string)($filtros['busca'] ?? '')) ?>" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
    </div>
    <div>
      <label for="filtro-empresa" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Empresa</label>
      <select id="filtro-empresa" name="empresa" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
        <option value="">Todas</option>
        <?php foreach (($painel['empresas'] ?? []) as $empresa): ?>
          <option value="<?= Security::e((string)$empresa['codigo_empresa']) ?>" <?= (string)($filtros['codigo_empresa'] ?? '') === (string)$empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e((string)($empresa['empresa'] ?: $empresa['codigo_empresa'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($visao === 'elegiveis'): ?>
      <div>
        <label for="filtro-dias" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Desligados nos últimos</label>
        <select id="filtro-dias" name="dias" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
          <?php foreach (EntrevistaDesligamentoService::DIAS_FILTRO as $d): ?>
            <option value="<?= $d ?>" <?= (int)($filtros['dias'] ?? 90) === $d ? 'selected' : '' ?>><?= $d === 0 ? 'Todo o histórico' : $d . ' dias' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="flex items-end">
      <button type="submit" class="w-full <?= ui_btn('primario') ?>">Filtrar</button>
    </div>
  </form>

  <?php if ($painel !== null && $visao === 'elegiveis'): ?>
    <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-text-secondary">
            <th class="p-3">Colaborador</th>
            <th class="p-3">Empresa</th>
            <th class="p-3">Cargo</th>
            <th class="p-3">Desligamento</th>
            <th class="p-3">Motivo oficial</th>
            <th class="p-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($painel['itens'] as $c): ?>
            <tr class="border-b align-top">
              <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$c['nome']) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['empresa'] ?? '—')) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['cargo'] ?? '—')) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e($fmtData($c['demissao'])) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($c['motivo_rescisao_descricao'] ?? '—')) ?></td>
              <td class="p-3">
                <?php if (!empty($podeGerenciar)): ?>
                  <form method="post" action="<?= $base ?>/admin/entrevistas-desligamento/gerar">
                    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <input type="hidden" name="metadados_id" value="<?= (int)$c['metadados_id'] ?>">
                    <button type="submit" class="rounded-ds-md bg-primary-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-800">Gerar entrevista</button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-text-secondary">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($painel['itens'])): ?>
            <tr><td colspan="6" class="p-4 text-center text-text-secondary">Nenhum desligamento elegível sem entrevista para os filtros informados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (($painel['total_visao'] ?? 0) > count($painel['itens'])): ?>
      <p class="text-xs text-text-secondary">Mostrando os <?= count($painel['itens']) ?> desligamentos mais recentes de <?= (int)$painel['total_visao'] ?>. Refine os filtros para localizar os demais.</p>
    <?php endif; ?>
    <p class="text-xs text-text-secondary">Elegível: desligamento já efetivado no METADADOS e motivo oficial diferente de Falecimento.</p>

  <?php elseif ($painel !== null): ?>
    <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-text-secondary">
            <th class="p-3">Colaborador</th>
            <th class="p-3">Empresa / Cargo</th>
            <th class="p-3">Desligamento</th>
            <th class="p-3">Situação</th>
            <th class="p-3">Gerada em</th>
            <th class="p-3">Expira em</th>
            <th class="p-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($painel['itens'] as $e): [$rotuloSituacao, $classeSituacao] = $badges[$e['situacao']]; ?>
            <tr class="border-b align-top">
              <td class="p-3 font-medium text-text-primary">
                <?= Security::e((string)$e['snap_nome']) ?>
                <?php if (!empty($e['tempo_empresa'])): ?><span class="block text-xs font-normal text-text-secondary">Tempo de empresa: <?= Security::e((string)$e['tempo_empresa']) ?></span><?php endif; ?>
                <?php foreach ($e['divergencias'] as $msg): ?>
                  <span class="mt-1 block rounded bg-warning/10 px-2 py-1 text-xs font-normal text-warning">Divergência com o METADADOS: <?= Security::e($msg) ?></span>
                <?php endforeach; ?>
              </td>
              <td class="p-3 text-text-secondary"><?= Security::e((string)($e['snap_empresa'] ?? '—')) ?><span class="block text-xs"><?= Security::e((string)($e['snap_cargo'] ?? '—')) ?></span></td>
              <td class="p-3 text-text-secondary"><?= Security::e($fmtData($e['snap_demissao'])) ?><span class="block text-xs"><?= Security::e((string)($e['snap_motivo_descricao'] ?? '')) ?></span></td>
              <td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= $classeSituacao ?>"><?= Security::e($rotuloSituacao) ?></span>
                <?php if ($e['situacao'] === 'respondida'): ?><span class="mt-1 block text-xs text-text-secondary"><?= Security::e($fmtDataHora($e['respondida_em'])) ?></span><?php endif; ?>
              </td>
              <td class="p-3 text-text-secondary"><?= Security::e($fmtDataHora($e['gerada_em'])) ?><span class="block text-xs"><?= Security::e((string)($e['gerada_por_nome'] ?? '')) ?><?= (int)$e['regeneracoes'] > 0 ? ' · regenerada ' . (int)$e['regeneracoes'] . 'x' : '' ?></span></td>
              <td class="p-3 text-text-secondary"><?= Security::e($fmtDataHora($e['expira_em'])) ?></td>
              <td class="p-3">
                <div class="flex flex-col gap-2">
                  <?php if ($e['situacao'] === 'respondida' && !empty($podeResultados)): ?>
                    <a href="<?= $base ?>/admin/entrevistas-desligamento/<?= (int)$e['id'] ?>/resultado" class="text-primary-700 hover:underline">Ver resultado</a>
                  <?php endif; ?>
                  <?php if (!empty($podeGerenciar) && $e['situacao'] !== 'respondida'): ?>
                    <form method="post" action="<?= $base ?>/admin/entrevistas-desligamento/<?= (int)$e['id'] ?>/regenerar">
                      <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                      <button type="submit" class="text-primary-700 hover:underline">Regenerar link</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!empty($podeGerenciar) && $e['situacao'] === 'pendente'): ?>
                    <details>
                      <summary class="cursor-pointer text-danger hover:underline">Cancelar</summary>
                      <form method="post" action="<?= $base ?>/admin/entrevistas-desligamento/<?= (int)$e['id'] ?>/cancelar" class="mt-1">
                        <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                        <p class="text-xs text-text-secondary">O link deixará de funcionar.</p>
                        <button type="submit" class="mt-1 rounded bg-danger px-2.5 py-1 text-xs font-semibold text-white hover:bg-danger/90">Confirmar cancelamento</button>
                      </form>
                    </details>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($painel['itens'])): ?>
            <tr><td colspan="7" class="p-4 text-center text-text-secondary">Nenhuma entrevista nesta situação.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
