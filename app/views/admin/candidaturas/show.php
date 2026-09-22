<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<?php
  $stageColor = $c['stage_cor'] ?? '#ccc';
  $stageColorNormalized = strtolower(trim((string)$stageColor));
  if (in_array($stageColorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
      $stageColor = '#3B4822';
  }
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'candidaturas', [
      'titulo' => (string)$c['nome'],
      'descricao' => 'Candidatura à vaga: ' . (string)($c['vaga_titulo'] ?? '-'),
  ], [['label' => (string)$c['nome'], 'href' => null]]) ?>
  <div class="flex flex-wrap items-center gap-2 text-sm text-text-secondary">
    Etapa atual:
    <span class="inline-flex items-center rounded-ds-sm px-3 py-1.5 text-sm font-semibold text-white" style="background-color: <?= $stageColor ?>">
        <?= Security::e($c['stage_nome'] ?? 'Novo') ?>
    </span>
  </div>
  <div class="responsive-panel">
  <div class="grid gap-6 md:grid-cols-2">
      <div>
          <?php if (!empty($c['cpf'])): ?>
            <p class="text-text-secondary"><strong>CPF:</strong> <?= substr($c['cpf'], 0, 3) . '.' . substr($c['cpf'], 3, 3) . '.' . substr($c['cpf'], 6, 3) . '-' . substr($c['cpf'], 9, 2) ?></p>
          <?php endif; ?>
          <p class="mt-2"><strong>Cargo pretendido:</strong> <?= Security::e($c['cargo_pretendido'] ?? $c['vaga_titulo'] ?? '') ?></p>
          <p class="mt-2"><strong>E-mail:</strong> <?= Security::e($c['email']) ?></p>
          <p class="mt-2"><strong>Telefone:</strong> <?= Security::e(Phone::format($c['telefone'] ?? '')) ?></p>
          <p class="mt-2"><strong>Vaga:</strong> <?= Security::e($c['vaga_titulo'] ?? '-') ?></p>
      </div>
      <div>
          <p class="font-semibold text-text-primary">Experiência/Resumo:</p>
          <div class="mt-1 p-3 bg-surface-secondary border rounded text-sm text-text-primary max-h-52 md:h-32 overflow-y-auto">
              <?= nl2br(Security::e($c['experiencia'])) ?>
          </div>
          <div class="responsive-form-actions mt-4">
               <?php $hasResume = !empty($c['pdf_path']); ?>
               <?php if ($hasResume): ?>
               <a
                 href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download"
                 class="inline-flex items-center px-4 py-2 rounded shadow-sm bg-primary-700 text-white hover:bg-primary-800 active:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-100 transition touch-target w-full md:w-auto"
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
                 class="inline-flex items-center px-4 py-2 rounded shadow-sm bg-border text-text-secondary cursor-not-allowed"
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
               <button type="button" data-ai-analyze="1" class="inline-flex items-center px-4 py-2 border border-border shadow-sm text-sm font-medium rounded-md text-text-primary bg-white hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-100 touch-target w-full md:w-auto">
                  <svg class="mr-2 -ml-1 h-5 w-5 text-text-secondary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                   </svg>
                   Analisar com IA
               </button>
          </div>
      </div>
  </div>

  <div class="mt-8 border-t pt-6">
      <h3 class="text-lg font-medium text-text-primary">Atualizar Status / Pipeline</h3>
      <form class="mt-4 space-y-4" action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/atualizar" method="post">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-text-primary">Etapa do Processo</label>
              <select name="stage_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border focus:outline-none focus:ring-primary-100 focus:border-primary-700 sm:text-sm rounded-md border">
                <?php foreach ($stages as $st): ?>
                  <option value="<?= $st['id'] ?>" <?= ($c['stage_id'] ?? 1) == $st['id'] ? 'selected' : '' ?>>
                    <?= Security::e($st['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Programa de Indicações</label>
              <label class="mt-2 inline-flex items-center gap-2 text-sm text-text-primary">
                <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-border text-primary-700 focus:ring-primary-100" id="indicacao-colaborador-check">
                Candidato indicado por colaborador
              </label>
              <div class="mt-2 <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? '' : 'hidden' ?>" id="indicacao-colaborador-box">
                <label class="block text-xs font-medium text-text-secondary">Nome do colaborador que indicou</label>
                <input type="text" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Ex.: João da Silva">
              </div>
            </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Observações / Nota do Recrutador</label>
          <textarea name="observacoes" rows="3" class="mt-1 shadow-sm focus:ring-primary-100 focus:border-primary-700 block w-full sm:text-sm border-border rounded-md border" placeholder="Adicione uma nota sobre esta etapa..."></textarea>
        </div>
        <div class="rounded-xl border border-border bg-surface-secondary p-4">
          <h4 class="text-sm font-semibold text-text-primary">Dados adicionais da etapa</h4>
          <p class="mt-1 text-xs text-text-secondary">Preencha apenas os campos que se aplicam ao estágio atual para enriquecer o histórico e o payload do webhook.</p>
          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-text-primary">Data da entrevista</label>
              <input type="text" name="interview_date" value="<?= Security::e(!empty($c['interview_date']) ? DateHelper::formatBrazilianDate((string)$c['interview_date']) : '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="DD/MM/AAAA" data-mask-date="1">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Horário da entrevista</label>
              <input type="time" name="interview_time" value="<?= Security::e(!empty($c['interview_time']) ? substr((string)$c['interview_time'], 0, 5) : '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Local da entrevista</label>
              <input type="text" name="interview_location" value="<?= Security::e($c['interview_location'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="Sala, endereço ou observação">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Link da entrevista</label>
              <input type="url" name="interview_link" value="<?= Security::e($c['interview_link'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="https://...">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Nome do teste</label>
              <input type="text" name="test_name" value="<?= Security::e($c['test_name'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="Ex.: Teste comportamental">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Prazo do teste</label>
              <input type="text" name="deadline" value="<?= Security::e(!empty($c['deadline']) ? DateHelper::formatBrazilianDate((string)$c['deadline']) : '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="DD/MM/AAAA" data-mask-date="1">
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Data de admissão</label>
              <input type="text" name="admission_date" value="<?= Security::e(!empty($c['admission_date']) ? DateHelper::formatBrazilianDate((string)$c['admission_date']) : '') ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="DD/MM/AAAA" data-mask-date="1">
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-text-primary">Observações da admissão</label>
              <textarea name="admission_notes" rows="3" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="Documentos, pendências ou orientações da admissão"><?= Security::e($c['admission_notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
        <div class="responsive-form-actions justify-end">
          <a href="<?= $base ?>/admin/candidaturas" class="text-text-secondary hover:text-text-primary">Voltar</a>
          <button type="submit" class="bg-primary-700 text-white px-4 py-2 rounded hover:bg-primary-800 shadow-sm">Salvar Alterações</button>
        </div>
      </form>
  </div>
  <?php ui_script_pagina('candidaturas.js'); // JS movido para assets/candidaturas.js (CSP: sem <script> inline) ?>

  <!-- Histórico -->
  <?php if (!empty($historico)): ?>
  <div class="mt-8 rounded bg-surface-secondary p-6">
    <h3 class="text-lg font-semibold text-text-primary mb-4">Histórico de Movimentações</h3>
    <div class="flow-root">
      <ul role="list" class="-mb-8">
        <?php foreach ($historico as $idx => $h): ?>
        <li>
          <div class="relative pb-8">
            <?php if ($idx !== count($historico) - 1): ?>
              <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-border" aria-hidden="true"></span>
            <?php endif; ?>
            <div class="relative flex gap-3">
              <div>
                <span class="h-8 w-8 rounded-full bg-primary-700 flex items-center justify-center ring-8 ring-white">
                  <svg class="h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                  </svg>
                </span>
              </div>
              <div class="flex min-w-0 flex-1 flex-col gap-3 pt-1.5 sm:flex-row sm:justify-between sm:gap-4">
                <div>
                  <p class="text-sm text-text-secondary">
                      Alteração de status <span class="font-medium text-text-primary"><?= Security::e($h['status_anterior'] ?? '-') ?></span> para <span class="font-medium text-text-primary"><?= Security::e($h['status_novo']) ?></span>
                  </p>
                  <?php if (!empty($h['observacoes'])): ?>
                      <p class="mt-1 text-sm text-text-primary bg-white p-2 rounded border border-border"><?= nl2br(Security::e($h['observacoes'])) ?></p>
                  <?php endif; ?>
                </div>
                <div class="text-sm text-text-secondary sm:text-right">
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

  <!-- Histórico de Comunicação (Sprint "Histórico de Comunicação") -->
  <?php if (!empty($podeVerComunicacoes)): ?>
  <div class="mt-8 rounded bg-surface-secondary p-6">
    <h3 class="text-lg font-semibold text-text-primary mb-4">Histórico de Comunicação</h3>
    <?php if (empty($comunicacoes)): ?>
      <p class="text-sm text-text-secondary">Nenhuma comunicação registrada para este candidato ainda.</p>
    <?php else: ?>
    <div class="flow-root">
      <ul role="list" class="-mb-8">
        <?php foreach ($comunicacoes as $idx => $com): ?>
        <?php
          $situacaoLabel = ['preparada' => 'Preparada', 'enviada' => 'Enviada', 'erro' => 'Erro no envio'][$com['situacao']] ?? $com['situacao'];
          $entregue = !empty($com['entregue_em']);
          $visualizada = !empty($com['visualizada_em']);
          $detalheId = 'comunicacao-conteudo-' . (int)$com['id'];
        ?>
        <li>
          <div class="relative pb-8">
            <?php if ($idx !== count($comunicacoes) - 1): ?>
              <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-border" aria-hidden="true"></span>
            <?php endif; ?>
            <div class="relative flex gap-3">
              <div>
                <span class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center ring-8 ring-white">
                  <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </span>
              </div>
              <div class="flex min-w-0 flex-1 flex-col gap-2 pt-1.5 sm:flex-row sm:justify-between sm:gap-4">
                <div class="min-w-0 flex-1">
                  <p class="text-sm text-text-primary">
                    <span class="font-medium text-text-primary"><?= Security::e((string)($com['mensagem_titulo'] ?? 'Comunicação')) ?></span>
                    — <?= Security::e($situacaoLabel) ?>
                    <?= $entregue ? ' · Entregue' : '' ?>
                    <?= $visualizada ? ' · Visualizada' : '' ?>
                  </p>
                  <p class="mt-1 text-xs text-text-secondary">
                    Canal: <?= Security::e($com['canal']) ?>
                    · Responsável: <?= Security::e($com['usuario_nome'] ?? ($com['origem'] === 'AUTOMATICA' ? 'Automático' : '-')) ?>
                  </p>
                  <button type="button" class="mt-2 text-xs font-medium text-text-primary hover:text-primary-700" data-toggle-target="<?= Security::e($detalheId) ?>">Ver conteúdo completo</button>
                  <div id="<?= Security::e($detalheId) ?>" class="mt-2 hidden rounded border border-border bg-white p-3 text-sm text-text-primary whitespace-pre-wrap"><?= Security::e((string)$com['conteudo']) ?></div>
                  <?php if ($com['situacao'] === 'erro' && !empty($com['erro_mensagem'])): ?>
                    <p class="mt-1 text-xs text-danger">Erro: <?= Security::e((string)$com['erro_mensagem']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="text-sm text-text-secondary sm:text-right">
                  <time datetime="<?= $com['created_at'] ?>"><?= date('d/m/Y H:i', strtotime((string)$com['created_at'])) ?></time>
                </div>
              </div>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Pesquisa de Experiência (Sprint "Experiência do Candidato") -->
  <?php if (!empty($podeVerPesquisa)): ?>
  <div class="mt-8 rounded bg-surface-secondary p-6">
    <h3 class="text-lg font-semibold text-text-primary mb-4">Pesquisa de Experiência</h3>
    <?php if (empty($pesquisa) || empty($pesquisa['respondida_em'])): ?>
      <p class="text-sm text-text-secondary">O candidato ainda não respondeu à pesquisa de experiência.</p>
    <?php else: ?>
      <p class="text-xs text-text-secondary mb-3">Respondida em <?= date('d/m/Y H:i', strtotime((string)$pesquisa['respondida_em'])) ?></p>
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded border border-border bg-white p-3">
          <div class="text-xs text-text-secondary">Clareza das informações</div>
          <div class="mt-1 text-lg font-semibold text-text-primary"><?= (int)$pesquisa['nota_clareza'] ?> / 5</div>
        </div>
        <div class="rounded border border-border bg-white p-3">
          <div class="text-xs text-text-secondary">Tempo de retorno</div>
          <div class="mt-1 text-lg font-semibold text-text-primary"><?= (int)$pesquisa['nota_tempo_retorno'] ?> / 5</div>
        </div>
        <div class="rounded border border-border bg-white p-3">
          <div class="text-xs text-text-secondary">Atendimento recebido</div>
          <div class="mt-1 text-lg font-semibold text-text-primary"><?= (int)$pesquisa['nota_atendimento'] ?> / 5</div>
        </div>
      </div>
      <?php if (!empty($pesquisa['comentarios'])): ?>
        <div class="mt-3">
          <div class="text-xs text-text-secondary">Comentários</div>
          <div class="mt-1 rounded border border-border bg-white p-3 text-sm text-text-primary whitespace-pre-wrap"><?= Security::e((string)$pesquisa['comentarios']) ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  </div>
</div>
