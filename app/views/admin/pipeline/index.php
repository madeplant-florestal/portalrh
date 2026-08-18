<div class="responsive-panel">
  <div class="responsive-header mb-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 sm:text-3xl">Kanban de Recrutamento e Seleção</h1>
      <p class="mt-1 text-sm text-gray-500">Fluxo completo do candidato, da nova inscrição até admissão, banco de talentos ou reprovação.</p>
    </div>
    <form method="GET" action="<?= $base ?>/admin/pipeline" class="w-full max-w-full md:w-auto">
      <label for="vaga_id" class="mb-2 block text-sm font-medium text-gray-700">Filtrar por vaga</label>
      <select name="vaga_id" id="vaga_id" data-autosubmit="1" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-ctgreen md:min-w-[18rem]">
        <option value="">Todas as Vagas</option>
        <?php foreach ($vagas as $v): ?>
          <option value="<?= $v['id'] ?>" <?= ($selectedVaga == $v['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($v['titulo']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="mb-4 flex flex-wrap items-center gap-3 text-xs text-gray-500">
    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 shadow-sm ring-1 ring-gray-200">
      <?= (int)($stageCount ?? count($kanban)) ?> etapas monitoradas
    </span>
    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 shadow-sm ring-1 ring-gray-200">
      Arraste e solte os cards para atualizar a etapa do candidato
    </span>
  </div>

  <div class="kanban-board min-h-[26rem] md:min-h-[36rem]">
    <?php foreach ($kanban as $stageId => $col): ?>
        <?php
        $borderColor = $col['stage']['cor'] ?? '#cccccc';
        $borderColorNormalized = strtolower(trim((string)$borderColor));
        if (in_array($borderColorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
            $borderColor = '#1d2d44';
        }
        ?>
        <div class="kanban-column-shell flex h-full flex-col rounded-xl border-t-4 bg-gray-100 shadow-sm" data-kanban-board-column="1" style="border-color: <?= $borderColor ?>">
            <div class="sticky top-0 z-10 flex items-center justify-between rounded-t-xl border-b bg-white p-3">
                <h3 class="font-semibold text-gray-700"><?= htmlspecialchars($col['stage']['nome']) ?></h3>
                <span class="bg-gray-200 text-gray-600 text-xs px-2 py-1 rounded-full" data-kanban-count="1"><?= count($col['items']) ?></span>
            </div>
            
            <div class="kanban-column flex-1 space-y-3 overflow-y-auto p-2" data-kanban-column="1" data-stage-id="<?= $stageId ?>" data-stage-slug="<?= Security::e($col['stage']['slug'] ?? '') ?>">
                <?php if (empty($col['items'])): ?>
                    <div class="rounded-lg border border-dashed border-gray-300 bg-white/70 px-4 py-6 text-center text-xs text-gray-400">
                        Nenhum candidato nesta etapa.
                    </div>
                <?php endif; ?>
                <?php foreach ($col['items'] as $c): ?>
                    <div class="group relative cursor-move rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-shadow hover:shadow-md" data-kanban-card="1" draggable="true" id="cand-<?= $c['id'] ?>" data-cand-id="<?= $c['id'] ?>"
                        data-cand-name="<?= Security::e($c['nome']) ?>"
                        data-interview-date="<?= Security::e(!empty($c['interview_date']) ? DateHelper::formatBrazilianDate((string)$c['interview_date']) : '') ?>"
                        data-interview-time="<?= Security::e(!empty($c['interview_time']) ? substr((string)$c['interview_time'], 0, 5) : '') ?>"
                        data-interview-location="<?= Security::e($c['interview_location'] ?? '') ?>"
                        data-interview-link="<?= Security::e($c['interview_link'] ?? '') ?>"
                        data-test-name="<?= Security::e($c['test_name'] ?? '') ?>"
                        data-deadline="<?= Security::e(!empty($c['deadline']) ? DateHelper::formatBrazilianDate((string)$c['deadline']) : '') ?>"
                        data-admission-date="<?= Security::e(!empty($c['admission_date']) ? DateHelper::formatBrazilianDate((string)$c['admission_date']) : '') ?>"
                        data-admission-notes="<?= Security::e($c['admission_notes'] ?? '') ?>"
                    >

                        <div class="flex justify-between items-start mb-2">
                            <h4 class="w-full truncate text-sm font-medium text-gray-900" title="<?= htmlspecialchars($c['nome']) ?>">
                                <?= htmlspecialchars($c['nome']) ?>
                            </h4>
                        </div>
                        
                        <p class="text-xs text-gray-500 mb-1 flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            <?= htmlspecialchars($c['vaga_titulo'] ?? 'Vaga não encontrada') ?>
                        </p>
                        <p class="text-xs text-gray-500 mb-1 flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12H8m8 0-3-3m3 3-3 3"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 18h10"/></svg>
                            <?= htmlspecialchars($c['stage_nome'] ?? $col['stage']['nome']) ?>
                        </p>
                        
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <a href="<?= $base ?>/admin/candidaturas/<?= $c['id'] ?>" class="text-xs font-medium text-ctgreen hover:text-ctdark hover:underline">Ver detalhes</a>
                            <span class="text-xs text-gray-400"><?= date('d/m', strtotime($c['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            </div>
        </div>
    <?php endforeach; ?>
  </div>

  <div class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4" data-stage-modal="1">
    <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
      <h3 class="text-lg font-semibold text-slate-800">Confirmar movimentação</h3>
      <p class="mt-1 text-sm text-slate-500">
        Mover <strong data-stage-modal-candidate-name="1"></strong> para
        <strong data-stage-modal-target-name="1"></strong>.
      </p>

      <div class="mt-4 hidden" data-stage-modal-error="1">
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" data-stage-modal-error-text="1"></div>
      </div>

      <div class="mt-4 space-y-4">
        <div class="hidden grid gap-4 sm:grid-cols-2" data-stage-modal-group="entrevista">
          <div>
            <label class="block text-sm font-medium text-slate-700">Data da entrevista *</label>
            <input type="text" data-stage-modal-field="interview_date" placeholder="DD/MM/AAAA" data-mask-date="1" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Horário da entrevista *</label>
            <input type="time" data-stage-modal-field="interview_time" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Local da entrevista</label>
            <input type="text" data-stage-modal-field="interview_location" placeholder="Sala, endereço ou observação" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Link da entrevista</label>
            <input type="url" data-stage-modal-field="interview_link" placeholder="https://..." class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <p class="sm:col-span-2 text-xs text-slate-500">Preencha local ou link da entrevista (pelo menos um é obrigatório).</p>
        </div>

        <div class="hidden grid gap-4 sm:grid-cols-2" data-stage-modal-group="testes">
          <div>
            <label class="block text-sm font-medium text-slate-700">Nome do teste *</label>
            <input type="text" data-stage-modal-field="test_name" placeholder="Ex.: Teste comportamental" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Prazo do teste *</label>
            <input type="text" data-stage-modal-field="deadline" placeholder="DD/MM/AAAA" data-mask-date="1" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
        </div>

        <div class="hidden space-y-4" data-stage-modal-group="admissao">
          <div>
            <label class="block text-sm font-medium text-slate-700">Data de admissão *</label>
            <input type="text" data-stage-modal-field="admission_date" placeholder="DD/MM/AAAA" data-mask-date="1" class="mt-1 w-full rounded border px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Observações da admissão *</label>
            <textarea data-stage-modal-field="admission_notes" rows="3" placeholder="Documentos, pendências ou orientações da admissão" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
          </div>
        </div>

        <div class="hidden space-y-4" data-stage-modal-group="reprovado">
          <div>
            <label class="block text-sm font-medium text-slate-700">Motivo da reprovação (uso interno) *</label>
            <textarea data-stage-modal-field="observacoes" rows="3" placeholder="Este texto nunca é enviado ao candidato" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" data-stage-modal-field="confirm" class="rounded border-slate-300 text-ctgreen focus:ring-ctgreen">
            Confirmo que desejo reprovar este candidato.
          </label>
        </div>

        <div class="hidden space-y-4" data-stage-modal-group="banco-de-talentos">
          <div>
            <label class="block text-sm font-medium text-slate-700">Observação (opcional)</label>
            <textarea data-stage-modal-field="observacoes" rows="3" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" data-stage-modal-field="confirm" class="rounded border-slate-300 text-ctgreen focus:ring-ctgreen">
            Confirmo que desejo mover este candidato para o Banco de Talentos.
          </label>
        </div>
      </div>

      <div class="mt-6 flex justify-end gap-3">
        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100" data-stage-modal-cancel="1">Cancelar</button>
        <button type="button" class="rounded-xl bg-ctgreen px-4 py-2 text-sm font-semibold text-white hover:bg-ctdark" data-stage-modal-confirm="1">Confirmar movimentação</button>
      </div>
    </div>
  </div>
</div>
