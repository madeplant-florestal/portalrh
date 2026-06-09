<?php
?>
<div class="responsive-panel">
  <div class="responsive-header mb-6">
      <h2 class="text-2xl font-semibold text-ctpblue"><?= Security::e($c['nome']) ?></h2>
      <?php
      $stageColor = $c['stage_cor'] ?? '#ccc';
      $stageColorNormalized = strtolower(trim((string)$stageColor));
      if (in_array($stageColorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
          $stageColor = '#1d2d44';
      }
      ?>
      <span class="inline-flex min-h-[44px] items-center rounded px-3 py-2 text-sm font-semibold text-white" style="background-color: <?= $stageColor ?>">
          <?= Security::e($c['stage_nome'] ?? 'Novo') ?>
      </span>
  </div>
  
  <div class="grid gap-6 md:grid-cols-2">
      <div>
          <?php if (!empty($c['cpf'])): ?>
            <p class="text-gray-600"><strong>CPF:</strong> <?= substr($c['cpf'], 0, 3) . '.' . substr($c['cpf'], 3, 3) . '.' . substr($c['cpf'], 6, 3) . '-' . substr($c['cpf'], 9, 2) ?></p>
          <?php endif; ?>
          <p class="mt-2"><strong>Cargo pretendido:</strong> <?= Security::e($c['cargo_pretendido'] ?? $c['vaga_titulo'] ?? '') ?></p>
          <p class="mt-2"><strong>E-mail:</strong> <?= Security::e($c['email']) ?></p>
          <p class="mt-2"><strong>Telefone:</strong> <?= Security::e(Phone::format($c['telefone'] ?? '')) ?></p>
          <p class="mt-2"><strong>Vaga:</strong> <?= Security::e($c['vaga_titulo'] ?? '-') ?></p>
      </div>
      <div>
          <p class="font-semibold text-gray-700">Experiência/Resumo:</p>
          <div class="mt-1 p-3 bg-gray-50 border rounded text-sm text-gray-800 max-h-52 md:h-32 overflow-y-auto">
              <?= nl2br(Security::e($c['experiencia'])) ?>
          </div>
          <div class="responsive-form-actions mt-4">
               <?php $hasResume = !empty($c['pdf_path']); ?>
               <?php if ($hasResume): ?>
               <a
                 href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download"
                 class="inline-flex items-center px-4 py-2 rounded shadow-sm bg-ctgreen text-white hover:bg-ctdark active:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ctgreen transition touch-target w-full md:w-auto"
                 role="button"
                 aria-label="Baixar Currículo"
                 title="Baixar Currículo"
               >
                   <svg class="mr-2 -ml-1 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                   </svg>
                   Baixar Currículo
               </a>
               <?php else: ?>
               <span
                 class="inline-flex items-center px-4 py-2 rounded shadow-sm bg-gray-200 text-gray-500 cursor-not-allowed"
                 role="button"
                 aria-disabled="true"
                 aria-label="Baixar CV indisponível"
                 title="Baixar CV indisponível"
               >
                   <svg class="mr-2 -ml-1 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                   </svg>
                   Baixar CV
               </span>
               <?php endif; ?>
               
               <!-- Placeholder AI Analysis -->
               <button type="button" data-ai-analyze="1" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-ctpblue bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ctgreen touch-target w-full md:w-auto">
                  <svg class="mr-2 -ml-1 h-5 w-5 text-ctlight" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                   </svg>
                   Analisar com IA
               </button>
          </div>
      </div>
  </div>

  <div class="mt-8 border-t pt-6">
      <h3 class="text-lg font-medium text-gray-900">Atualizar Status / Pipeline</h3>
      <form class="mt-4 space-y-4" action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/atualizar" method="post">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700">Etapa do Processo</label>
              <select name="stage_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-ctgreen focus:border-ctgreen sm:text-sm rounded-md border">
                <?php foreach ($stages as $st): ?>
                  <option value="<?= $st['id'] ?>" <?= ($c['stage_id'] ?? 1) == $st['id'] ? 'selected' : '' ?>>
                    <?= Security::e($st['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Programa de Indicações</label>
              <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-gray-300 text-ctgreen focus:ring-ctgreen" id="indicacao-colaborador-check">
                Candidato indicado por colaborador
              </label>
              <div class="mt-2 <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? '' : 'hidden' ?>" id="indicacao-colaborador-box">
                <label class="block text-xs font-medium text-gray-600">Nome do colaborador que indicou</label>
                <input type="text" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Ex.: João da Silva">
              </div>
            </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Observações / Nota do Recrutador</label>
          <textarea name="observacoes" rows="3" class="mt-1 shadow-sm focus:ring-ctgreen focus:border-ctgreen block w-full sm:text-sm border-gray-300 rounded-md border" placeholder="Adicione uma nota sobre esta etapa..."></textarea>
        </div>
        <div class="responsive-form-actions justify-end">
          <a href="<?= $base ?>/admin/candidaturas" class="text-gray-600 hover:text-gray-900">Voltar</a>
          <button type="submit" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark shadow-sm">Salvar Alterações</button>
        </div>
      </form>
  </div>
  <script>
    (() => {
      const check = document.getElementById('indicacao-colaborador-check');
      const box = document.getElementById('indicacao-colaborador-box');
      if (!check || !box) return;
      const sync = () => {
        if (check.checked) {
          box.classList.remove('hidden');
        } else {
          box.classList.add('hidden');
          const input = box.querySelector('input[name="indicacao_colaborador_nome"]');
          if (input) input.value = '';
        }
      };
      check.addEventListener('change', sync);
      sync();
    })();
  </script>

  <!-- Histórico -->
  <?php if (!empty($historico)): ?>
  <div class="mt-8 rounded bg-gray-50 p-6">
    <h3 class="text-lg font-semibold text-ctpblue mb-4">Histórico de Movimentações</h3>
    <div class="flow-root">
      <ul role="list" class="-mb-8">
        <?php foreach ($historico as $idx => $h): ?>
        <li>
          <div class="relative pb-8">
            <?php if ($idx !== count($historico) - 1): ?>
              <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
            <?php endif; ?>
            <div class="relative flex gap-3">
              <div>
                <span class="h-8 w-8 rounded-full bg-ctgreen flex items-center justify-center ring-8 ring-white">
                  <svg class="h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                  </svg>
                </span>
              </div>
              <div class="flex min-w-0 flex-1 flex-col gap-3 pt-1.5 sm:flex-row sm:justify-between sm:gap-4">
                <div>
                  <p class="text-sm text-gray-500">
                      Alteração de status <span class="font-medium text-gray-900"><?= Security::e($h['status_anterior'] ?? '-') ?></span> para <span class="font-medium text-gray-900"><?= Security::e($h['status_novo']) ?></span>
                  </p>
                  <?php if (!empty($h['observacoes'])): ?>
                      <p class="mt-1 text-sm text-gray-700 bg-white p-2 rounded border border-gray-200"><?= nl2br(Security::e($h['observacoes'])) ?></p>
                  <?php endif; ?>
                </div>
                <div class="text-sm text-gray-500 sm:text-right">
                  <time datetime="<?= $h['created_at'] ?>"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></time>
                  <div class="text-xs">por <?= Security::e($h['usuario_nome'] ?? 'Sistema') ?></div>
                </div>
              </div>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>
</div>
