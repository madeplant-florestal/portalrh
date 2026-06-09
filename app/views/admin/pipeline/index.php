<div class="responsive-panel">
  <div class="responsive-header mb-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 sm:text-3xl">Pipeline de Seleção</h1>
      <p class="mt-1 text-sm text-gray-500">Arraste os cards sem comprometer o layout em telas pequenas.</p>
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
            
            <div class="kanban-column flex-1 space-y-3 overflow-y-auto p-2" data-kanban-column="1" data-stage-id="<?= $stageId ?>">
                 
                <?php foreach ($col['items'] as $c): ?>
                    <div class="group relative cursor-move rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-shadow hover:shadow-md" data-kanban-card="1" draggable="true" id="cand-<?= $c['id'] ?>" data-cand-id="<?= $c['id'] ?>">
                        
                        <div class="flex justify-between items-start mb-2">
                            <h4 class="w-full truncate text-sm font-medium text-gray-900" title="<?= htmlspecialchars($c['nome']) ?>">
                                <?= htmlspecialchars($c['nome']) ?>
                            </h4>
                        </div>
                        
                        <p class="text-xs text-gray-500 mb-1 flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            <?= htmlspecialchars($c['vaga_titulo'] ?? 'Vaga não encontrada') ?>
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
</div>
