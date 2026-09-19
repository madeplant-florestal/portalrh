<?php
/**
 * Resultado INDIVIDUAL identificado da Entrevista de Desligamento — informação sensível: só com
 * `entrevista_desligamento.resultados`. O motivo oficial (snapshot do METADADOS) e o motivo declarado
 * pelo ex-colaborador são independentes e aparecem separados.
 */
$e = $entrevista;
$fmtData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
$fmtDataHora = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y H:i', strtotime($d)) : '—';
$escala = EntrevistaDesligamentoService::ESCALA_1_5;
$respondida = $e['situacao'] === 'respondida';
$motivoOficial = implode(' — ', array_filter([trim((string)($e['snap_motivo_codigo'] ?? '')), trim((string)($e['snap_motivo_descricao'] ?? ''))], static fn(string $p): bool => $p !== ''));
$classesEnps = ['Promotor' => 'bg-green-50 text-green-700', 'Neutro' => 'bg-amber-50 text-amber-700', 'Detrator' => 'bg-red-50 text-red-700'];
$texto = static function (?string $t): string {
    return $t !== null && trim($t) !== '' ? nl2br(Security::e($t)) : '<span class="text-[#5B5F4E]">Não informado</span>';
};
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <a href="<?= $base ?>/admin/entrevistas-desligamento?visao=respondida" class="text-sm text-[#3B4822] hover:underline">&larr; Entrevistas de Desligamento</a>
      <h2 class="mt-1 text-xl font-semibold text-[#2B2E22]">Entrevista de Desligamento — <?= Security::e((string)$e['snap_nome']) ?></h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">Resultado individual identificado. Informação sensível: não compartilhe com liderança.</p>
    </div>
  </div>

  <?php foreach ($e['divergencias'] as $msg): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Divergência com o METADADOS: <?= Security::e($msg) ?> O snapshot da geração foi preservado.</div>
  <?php endforeach; ?>

  <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
    <h3 class="text-sm font-bold text-[#2B2E22]">Identificação (snapshot na geração)</h3>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
      <div><dt class="text-xs text-[#5B5F4E]">Empresa</dt><dd><?= Security::e((string)($e['snap_empresa'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Unidade</dt><dd><?= Security::e((string)($e['snap_unidade'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Cargo</dt><dd><?= Security::e((string)($e['snap_cargo'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Admissão</dt><dd><?= Security::e($fmtData($e['snap_admissao'])) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Desligamento</dt><dd><?= Security::e($fmtData($e['snap_demissao'])) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Tempo de empresa</dt><dd><?= Security::e((string)($e['tempo_empresa'] ?? '—')) ?></dd></div>
      <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-[#5B5F4E]">Motivo OFICIAL do desligamento (METADADOS)</dt><dd><?= Security::e($motivoOficial !== '' ? $motivoOficial : '—') ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Link gerado em</dt><dd><?= Security::e($fmtDataHora($e['gerada_em'])) ?></dd></div>
      <div><dt class="text-xs text-[#5B5F4E]">Respondida em</dt><dd><?= Security::e($fmtDataHora($e['respondida_em'])) ?></dd></div>
    </dl>
  </section>

  <?php if (!$respondida): ?>
    <div class="rounded-lg border border-[#E2DFD0] bg-white px-4 py-3 text-sm text-[#5B5F4E]">Esta entrevista ainda não foi respondida (situação: <?= Security::e((string)$e['situacao']) ?>).</div>
  <?php else: ?>
    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="text-sm font-bold text-[#2B2E22]">Motivo declarado pelo ex-colaborador</h3>
      <p class="mt-2 text-sm font-medium"><?= Security::e(EntrevistaDesligamentoService::MOTIVOS_DECLARADOS[$e['motivo_principal']] ?? (string)$e['motivo_principal']) ?></p>
      <p class="mt-2 text-sm"><?= $texto($e['motivo_descricao']) ?></p>
      <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Fatores contribuintes</h4>
      <?php if ($e['fatores'] === []): ?>
        <p class="mt-1 text-sm text-[#5B5F4E]">Nenhum fator marcado.</p>
      <?php else: ?>
        <ul class="mt-1 flex flex-wrap gap-2">
          <?php foreach ($e['fatores'] as $fator): ?>
            <li class="rounded-full bg-[#F2F4EC] px-3 py-1 text-xs font-semibold text-[#2E3919]"><?= Security::e(EntrevistaDesligamentoService::FATORES_CONTRIBUINTES[$fator] ?? $fator) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $secao): ?>
      <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <h3 class="text-sm font-bold text-[#2B2E22]"><?= Security::e($secao['titulo']) ?></h3>
        <table class="mt-2 min-w-full text-sm">
          <tbody>
            <?php foreach ($secao['itens'] as $campo => $enunciado): $nota = (int)$e[$campo]; ?>
              <tr class="border-b last:border-b-0">
                <td class="py-2 pr-3 text-[#2B2E22]"><?= Security::e($enunciado) ?></td>
                <td class="whitespace-nowrap py-2 text-right font-semibold text-[#2B2E22]"><?= $nota ?> <span class="font-normal text-[#5B5F4E]"><?= $secao['escala'] === 'satisfacao' ? '— ' . Security::e($escala[$nota] ?? '') : '/ 5' ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <h3 class="text-sm font-bold text-[#2B2E22]">Experiência geral</h3>
        <p class="mt-2 text-2xl font-bold text-[#2B2E22]"><?= (int)$e['experiencia_geral'] ?><span class="text-sm font-normal text-[#5B5F4E]"> / 10</span></p>
      </div>
      <div class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
        <h3 class="text-sm font-bold text-[#2B2E22]">eNPS</h3>
        <p class="mt-2 text-2xl font-bold text-[#2B2E22]"><?= (int)$e['enps'] ?><span class="text-sm font-normal text-[#5B5F4E]"> / 10</span>
          <span class="ml-2 inline-flex rounded-full px-2.5 py-1 align-middle text-xs font-semibold <?= $classesEnps[$e['enps_classificacao']] ?? '' ?>"><?= Security::e((string)$e['enps_classificacao']) ?></span></p>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4 space-y-4">
      <h3 class="text-sm font-bold text-[#2B2E22]">Perguntas abertas</h3>
      <?php foreach (EntrevistaDesligamentoService::PERGUNTAS_ABERTAS as $campo => $pergunta): ?>
        <div>
          <h4 class="text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]"><?= Security::e($pergunta) ?></h4>
          <p class="mt-1 text-sm"><?= $texto($e[$campo]) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
