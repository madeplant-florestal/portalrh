<?php
/**
 * Resultado INDIVIDUAL identificado da Entrevista de Desligamento — informação sensível: só com
 * `entrevista_desligamento.resultados`. O motivo oficial (snapshot do METADADOS) e o motivo declarado
 * pelo ex-colaborador são independentes e aparecem separados.
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$e = $entrevista;
$fmtData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
$fmtDataHora = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y H:i', strtotime($d)) : '—';
$escala = EntrevistaDesligamentoService::ESCALA_1_5;
$respondida = $e['situacao'] === 'respondida';
$motivoOficial = implode(' — ', array_filter([trim((string)($e['snap_motivo_codigo'] ?? '')), trim((string)($e['snap_motivo_descricao'] ?? ''))], static fn(string $p): bool => $p !== ''));
$classesEnps = ['Promotor' => 'bg-success/10 text-success', 'Neutro' => 'bg-warning/10 text-warning', 'Detrator' => 'bg-danger/10 text-danger'];
$texto = static function (?string $t): string {
    return $t !== null && trim($t) !== '' ? nl2br(Security::e($t)) : '<span class="text-text-secondary">Não informado</span>';
};
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'desligamento', 'entrevistas', [
      'titulo' => 'Entrevista de Desligamento — ' . (string)$e['snap_nome'],
      'descricao' => 'Resultado individual identificado. Informação sensível: não compartilhe com liderança.',
  ], [['label' => 'Resultado individual']]) ?>

  <?php foreach ($e['divergencias'] as $msg): ?>
    <div class="rounded-ds-md border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">Divergência com o METADADOS: <?= Security::e($msg) ?> O snapshot da geração foi preservado.</div>
  <?php endforeach; ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4">
    <h3 class="text-sm font-bold text-text-primary">Identificação (snapshot na geração)</h3>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
      <div><dt class="text-xs text-text-secondary">Empresa</dt><dd><?= Security::e((string)($e['snap_empresa'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Unidade</dt><dd><?= Security::e((string)($e['snap_unidade'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Cargo</dt><dd><?= Security::e((string)($e['snap_cargo'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Admissão</dt><dd><?= Security::e($fmtData($e['snap_admissao'])) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Desligamento</dt><dd><?= Security::e($fmtData($e['snap_demissao'])) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Tempo de empresa</dt><dd><?= Security::e((string)($e['tempo_empresa'] ?? '—')) ?></dd></div>
      <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-text-secondary">Motivo OFICIAL do desligamento (METADADOS)</dt><dd><?= Security::e($motivoOficial !== '' ? $motivoOficial : '—') ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Link gerado em</dt><dd><?= Security::e($fmtDataHora($e['gerada_em'])) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Respondida em</dt><dd><?= Security::e($fmtDataHora($e['respondida_em'])) ?></dd></div>
    </dl>
  </section>

  <?php if (!$respondida): ?>
    <div class="rounded-ds-md border border-border bg-surface px-4 py-3 text-sm text-text-secondary">Esta entrevista ainda não foi respondida (situação: <?= Security::e((string)$e['situacao']) ?>).</div>
  <?php else: ?>
    <section class="rounded-ds-lg border border-border bg-surface p-4">
      <h3 class="text-sm font-bold text-text-primary">Motivo declarado pelo ex-colaborador</h3>
      <p class="mt-2 text-sm font-medium"><?= Security::e(EntrevistaDesligamentoService::MOTIVOS_DECLARADOS[$e['motivo_principal']] ?? (string)$e['motivo_principal']) ?></p>
      <p class="mt-2 text-sm"><?= $texto($e['motivo_descricao']) ?></p>
      <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-text-secondary">Fatores contribuintes</h4>
      <?php if ($e['fatores'] === []): ?>
        <p class="mt-1 text-sm text-text-secondary">Nenhum fator marcado.</p>
      <?php else: ?>
        <ul class="mt-1 flex flex-wrap gap-2">
          <?php foreach ($e['fatores'] as $fator): ?>
            <li class="rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-800"><?= Security::e(EntrevistaDesligamentoService::FATORES_CONTRIBUINTES[$fator] ?? $fator) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $secao): ?>
      <section class="rounded-ds-lg border border-border bg-surface p-4">
        <h3 class="text-sm font-bold text-text-primary"><?= Security::e($secao['titulo']) ?></h3>
        <table class="mt-2 min-w-full text-sm">
          <tbody>
            <?php foreach ($secao['itens'] as $campo => $enunciado): $nota = (int)$e[$campo]; ?>
              <tr class="border-b last:border-b-0">
                <td class="py-2 pr-3 text-text-primary"><?= Security::e($enunciado) ?></td>
                <td class="whitespace-nowrap py-2 text-right font-semibold text-text-primary"><?= $nota ?> <span class="font-normal text-text-secondary"><?= $secao['escala'] === 'satisfacao' ? '— ' . Security::e($escala[$nota] ?? '') : '/ 5' ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <h3 class="text-sm font-bold text-text-primary">Experiência geral</h3>
        <p class="mt-2 text-2xl font-bold text-text-primary"><?= (int)$e['experiencia_geral'] ?><span class="text-sm font-normal text-text-secondary"> / 10</span></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4">
        <h3 class="text-sm font-bold text-text-primary">eNPS</h3>
        <p class="mt-2 text-2xl font-bold text-text-primary"><?= (int)$e['enps'] ?><span class="text-sm font-normal text-text-secondary"> / 10</span>
          <span class="ml-2 inline-flex rounded-full px-2.5 py-1 align-middle text-xs font-semibold <?= $classesEnps[$e['enps_classificacao']] ?? '' ?>"><?= Security::e((string)$e['enps_classificacao']) ?></span></p>
      </div>
    </section>

    <section class="rounded-ds-lg border border-border bg-surface p-4 space-y-4">
      <h3 class="text-sm font-bold text-text-primary">Perguntas abertas</h3>
      <?php foreach (EntrevistaDesligamentoService::PERGUNTAS_ABERTAS as $campo => $pergunta): ?>
        <div>
          <h4 class="text-xs font-semibold uppercase tracking-wide text-text-secondary"><?= Security::e($pergunta) ?></h4>
          <p class="mt-1 text-sm"><?= $texto($e[$campo]) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
