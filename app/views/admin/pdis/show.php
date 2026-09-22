<?php
/**
 * Detalhe do PDI — processo de acompanhamento em seções: Identificação, Desenvolvimento profissional, Competências,
 * Plano de ação, Espaço do colaborador (registrado ASSISTIDAMENTE), Acompanhamentos (append-only), Evidências,
 * Avaliação final e Histórico. Sem JavaScript: ações sensíveis usam <details>. Atraso é só sinalização.
 */
require_once __DIR__ . '/_helpers.php';
$pdi = $d['pdi'];
$pode = $d['pode'];
$status = (string)$pdi['status'];
$id = (int)$pdi['id'];
$csrf = Security::csrfToken();
$card = 'p-5';
$titulo = 'text-sm font-bold uppercase tracking-wide text-text-secondary';
$campo = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100';
$botao = ui_btn('primario');
$botaoSec = ui_btn('secundario');
$texto = static fn(?string $t): string => $t !== null && trim($t) !== '' ? nl2br(Security::e($t)) : '<span class="text-text-secondary">Não informado</span>';
$postForm = static fn(string $rota): string => '<form method="post" action="' . Security::e($base . '/admin/pdis/' . $rota) . '">'
    . '<input type="hidden" name="csrf" value="' . Security::e(Security::csrfToken()) . '">';
$emAndamento = $status === 'em_andamento';
$aberto = in_array($status, ['nao_iniciado', 'em_andamento'], true);
$tomStatus = ['rascunho' => 'neutro', 'nao_iniciado' => 'primary', 'em_andamento' => 'info', 'concluido' => 'success', 'cancelado' => 'danger'][$status] ?? 'neutro';
?>
<div class="space-y-5">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'PDI', 'href' => $base . '/admin/pdis'], ['label' => (string)$pdi['snap_nome']]]) ?>
  <?= ui_page_header([
      'titulo' => 'PDI — ' . (string)$pdi['snap_nome'],
      'descricao' => 'Previsão: ' . pdi_data_br($pdi['data_prevista_conclusao']) . ($status === 'concluido' ? ' · concluído em ' . pdi_data_br($pdi['data_real_conclusao']) : ''),
      'badge' => ['texto' => (string)(PdiService::STATUS[$status] ?? $status), 'tom' => $tomStatus],
  ]) ?>
  <div class="flex flex-wrap items-center gap-2">
    <?= pdi_atraso_badge($d['prazo']) ?>
    <?php if (!empty($pode['gerenciar'])): ?><a href="<?= $base ?>/admin/pdis/<?= $id ?>/editar" class="<?= $botaoSec ?>">Editar plano</a><?php endif; ?>
    <?php if (!empty($pode['acompanhar']) && $status === 'rascunho'): ?>
      <?= $postForm($id . '/liberar') ?><button type="submit" class="<?= $botaoSec ?>">Liberar (não iniciado)</button></form>
    <?php endif; ?>
    <?php if (!empty($pode['acompanhar']) && in_array($status, ['rascunho', 'nao_iniciado'], true)): ?>
      <?= $postForm($id . '/iniciar') ?><button type="submit" class="<?= $botao ?>">Iniciar PDI</button></form>
    <?php endif; ?>
  </div>
  <nav aria-label="Seções do PDI" class="flex flex-wrap gap-x-4 gap-y-1 text-ds-caption text-primary-700">
    <?php foreach (['identificacao' => 'Identificação', 'desenvolvimento' => 'Desenvolvimento', 'competencias' => 'Competências', 'plano-de-acao' => 'Plano de ação', 'acompanhamentos' => 'Acompanhamentos', 'avaliacao-final' => 'Avaliação final', 'historico' => 'Histórico'] as $ancora => $rotuloAncora): ?>
      <a href="#<?= $ancora ?>" class="rounded-ds-sm hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"><?= $rotuloAncora ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!empty($flashErro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashErro) ?></div><?php endif; ?>
  <?php if (!empty($flashOk)): ?><div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashOk) ?></div><?php endif; ?>

  <?php if ($status === 'concluido'): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success" role="status"><span class="font-semibold">PDI concluído.</span> A edição comum está bloqueada; o histórico, os acompanhamentos e a avaliação final permanecem visíveis.</div>
  <?php elseif ($status === 'cancelado'): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger" role="status"><span class="font-semibold">PDI cancelado.</span> O histórico foi preservado.</div>
  <?php endif; ?>

  <?php foreach ($d['divergencias']['itens'] as $div): ?>
    <div class="rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning"><span class="font-semibold">Atenção (METADADOS):</span> <?= Security::e($div['mensagem']) ?> O registro da abertura foi preservado.</div>
  <?php endforeach; ?>
  <?php if (!empty($d['gestor_sem_permissao'])): ?>
    <div class="rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">O gestor responsável não possui a permissão <strong>pdi.visualizar</strong> e, por isso, não consegue abrir este PDI.</div>
  <?php endif; ?>
  <?php if ($d['divergencias']['desligado'] && !empty($pode['acompanhar']) && in_array($status, PdiService::STATUS_EDITAVEIS, true)): ?>
    <section class="rounded-ds-lg border border-warning/30 bg-warning/10 p-4" aria-label="Contrato desligado">
      <h3 class="text-sm font-bold text-text-primary">Contrato desligado — decisão do RH</h3>
      <p class="mt-1 text-sm text-text-secondary">O PDI não foi encerrado automaticamente. Conclua ou cancele o plano abaixo, ou registre a decisão de mantê-lo.</p>
      <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-primary-700">Manter o PDI</summary>
        <?= $postForm($id . '/manter-desligado') ?>
          <label for="just-manter" class="mt-2 block text-sm font-medium">Justificativa</label>
          <textarea id="just-manter" name="justificativa" required rows="2" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"></textarea>
          <button type="submit" class="mt-2 <?= $botaoSec ?>">Registrar decisão</button>
        </form>
      </details>
    </section>
  <?php endif; ?>

  <div class="divide-y divide-border rounded-ds-lg border border-border bg-surface shadow-resting">
  <section id="identificacao" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Identificação</h3>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
      <div><dt class="text-xs text-text-secondary">Colaborador</dt><dd class="font-medium"><?= Security::e((string)$pdi['snap_nome']) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Cargo (na abertura)</dt><dd><?= Security::e((string)($pdi['snap_cargo'] ?? '—')) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Empresa / Unidade</dt><dd><?= Security::e((string)($pdi['snap_empresa'] ?? '—')) ?><?= !empty($pdi['snap_unidade']) && $pdi['snap_unidade'] !== $pdi['snap_empresa'] ? ' — ' . Security::e((string)$pdi['snap_unidade']) : '' ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Admissão</dt><dd><?= Security::e(pdi_data_br($pdi['snap_admissao'])) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Gestor responsável</dt><dd class="font-medium"><?= Security::e((string)$pdi['gestor_nome_snapshot']) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Origem</dt><dd><?= Security::e(PdiService::ORIGENS[$pdi['origem_tipo']] ?? (string)$pdi['origem_tipo']) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Abertura</dt><dd><?= Security::e(pdi_data_br($pdi['data_abertura'])) ?></dd></div>
      <div><dt class="text-xs text-text-secondary">Prevista para conclusão</dt><dd><?= Security::e(pdi_data_br($pdi['data_prevista_conclusao'])) ?></dd></div>
    </dl>
    <p class="mt-2 text-xs text-text-secondary">Criado por <?= Security::e((string)($pdi['criado_por_nome'] ?? '—')) ?> em <?= Security::e(pdi_data_hora_br($pdi['criado_em'])) ?>. Dados do colaborador conforme o METADADOS na abertura.</p>
  </section>

  <section id="desenvolvimento" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Desenvolvimento profissional</h3>
    <div class="mt-3 space-y-3 text-sm">
      <div><h4 class="text-xs font-semibold text-text-secondary">Pontos fortes identificados</h4><p class="mt-1"><?= $texto($pdi['pontos_fortes']) ?></p></div>
      <div><h4 class="text-xs font-semibold text-text-secondary">Oportunidades de desenvolvimento</h4><p class="mt-1"><?= $texto($pdi['oportunidades_desenvolvimento']) ?></p></div>
      <div><h4 class="text-xs font-semibold text-text-secondary">O que se espera alcançar</h4><p class="mt-1"><?= $texto($pdi['objetivo_esperado']) ?></p></div>
    </div>
  </section>

  <section id="competencias" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Competências a desenvolver</h3>
    <?php if ($d['competencias'] === []): ?><p class="mt-2 text-sm text-text-secondary">Nenhuma competência informada.</p><?php else: ?>
      <ul class="mt-3 flex flex-wrap gap-2"><?php foreach ($d['competencias'] as $c): ?><li class="rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-800"><?= Security::e((string)$c['competencia_texto']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

  <section id="plano-de-acao" class="<?= $card ?>">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="<?= $titulo ?>">Plano de ação</h3>
      <?= pdi_barra_progresso($d['progresso']) ?>
    </div>
    <?php if ($d['acoes'] === []): ?><p class="mt-2 text-sm text-text-secondary">Nenhuma ação cadastrada. Para iniciar o PDI é preciso ao menos 1 ação.</p><?php endif; ?>
    <div class="mt-3 space-y-3">
      <?php foreach ($d['acoes'] as $a): ?>
        <article class="rounded-ds-md border border-border p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-sm font-medium text-text-primary"><span class="text-text-secondary">Ação <?= (int)$a['ordem'] ?>.</span> <?= Security::e((string)$a['descricao']) ?></p>
            <div class="flex items-center gap-2"><?= pdi_status_acao_badge((string)$a['status']) ?>
              <?php if (!empty($a['atrasada'])): ?><span class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-semibold text-warning">Atrasada</span><?php endif; ?></div>
          </div>
          <p class="mt-1 text-xs text-text-secondary">Responsável: <?= Security::e(PdiService::RESPONSAVEIS[$a['responsavel_tipo']] ?? '') ?><?= !empty($a['responsavel_nome_snapshot']) ? ' — ' . Security::e((string)$a['responsavel_nome_snapshot']) : '' ?> · Prazo: <?= Security::e(pdi_data_br($a['prazo'])) ?></p>
          <?php if (!empty($pode['acompanhar']) && $emAndamento): ?>
            <?= $postForm($id . '/acoes/' . (int)$a['ordem'] . '/status') ?>
              <div class="mt-2 flex flex-wrap items-center gap-2">
                <label for="st-<?= (int)$a['ordem'] ?>" class="sr-only">Status da ação <?= (int)$a['ordem'] ?></label>
                <select id="st-<?= (int)$a['ordem'] ?>" name="status" class="rounded-lg border border-border bg-white px-2 py-1.5 text-xs">
                  <?php foreach (PdiService::STATUS_ACAO as $k => $r): ?><option value="<?= Security::e($k) ?>" <?= (string)$a['status'] === $k ? 'selected' : '' ?>><?= Security::e($r) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="rounded-lg border border-primary-700 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-50">Atualizar status</button>
              </div>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="espaco-colaborador" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Espaço do colaborador</h3>
    <p class="mt-1 rounded-lg bg-background px-3 py-2 text-xs text-text-secondary">O colaborador não acessa o Portal. O conteúdo abaixo é <strong>registrado por RH/Gestor em nome do colaborador</strong> e cada registro fica no histórico com quem o inseriu.</p>
    <div class="mt-3 space-y-3 text-sm">
      <div><h4 class="text-xs font-semibold text-text-secondary">Como você avalia seu momento profissional?</h4><p class="mt-1"><?= $texto($pdi['momento_profissional']) ?></p></div>
      <div><h4 class="text-xs font-semibold text-text-secondary">Quais pontos acredita que pode desenvolver para alcançar melhores resultados e ampliar sua contribuição para a equipe e para a empresa?</h4><p class="mt-1"><?= $texto($pdi['pontos_desenvolver_colaborador']) ?></p></div>
    </div>
    <?php if (!empty($pode['espaco'])): ?>
      <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-primary-700">Registrar em nome do colaborador</summary>
        <?= $postForm($id . '/espaco-colaborador') ?>
          <label for="momento" class="mt-2 block text-sm font-medium">Momento profissional</label>
          <textarea id="momento" name="momento_profissional" rows="3" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e((string)$pdi['momento_profissional']) ?></textarea>
          <label for="pontos-colab" class="mt-2 block text-sm font-medium">Pontos que acredita poder desenvolver</label>
          <textarea id="pontos-colab" name="pontos_desenvolver_colaborador" rows="3" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e((string)$pdi['pontos_desenvolver_colaborador']) ?></textarea>
          <button type="submit" class="mt-2 <?= $botaoSec ?>">Salvar (registrado em nome do colaborador)</button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <section id="acompanhamentos" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Acompanhamentos</h3>
    <p class="mt-1 text-xs text-text-secondary">Comentários não são editados nem apagados. Para corrigir, registre um novo comentário.</p>
    <?php if (!empty($pode['acompanhar']) && $aberto): ?>
      <?= $postForm($id . '/acompanhamentos') ?>
        <label for="comentario" class="mt-3 block text-sm font-medium">Novo acompanhamento</label>
        <textarea id="comentario" name="comentario" required rows="3" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"></textarea>
        <label class="mt-2 flex items-center gap-2 text-sm text-text-primary"><input type="checkbox" name="em_nome_do_colaborador" value="1" class="h-4 w-4 accent-primary-700"> Registrar em nome do colaborador (relato do colaborador, inserido por mim)</label>
        <button type="submit" class="mt-2 <?= $botao ?>">Registrar acompanhamento</button>
      </form>
    <?php endif; ?>
    <ol class="mt-4 space-y-3">
      <?php foreach ($d['acompanhamentos'] as $c): ?>
        <li class="rounded-ds-md border border-border p-3">
          <p class="text-xs text-text-secondary">
            <?php if (!empty($c['registrado_em_nome_do_colaborador'])): ?><span class="rounded-full bg-primary-100 px-2 py-0.5 font-semibold text-primary-800">Relato do colaborador</span> registrado por <?= Security::e((string)$c['autor_nome']) ?>
            <?php else: ?><span class="font-semibold text-text-primary"><?= Security::e((string)$c['autor_nome']) ?></span> (<?= Security::e((string)$c['autor_papel']) ?>)<?php endif; ?>
            · <?= Security::e(pdi_data_hora_br($c['criado_em'])) ?>
          </p>
          <p class="mt-1 text-sm text-text-primary"><?= nl2br(Security::e((string)$c['comentario'])) ?></p>
        </li>
      <?php endforeach; ?>
      <?php if ($d['acompanhamentos'] === []): ?><li class="text-sm text-text-secondary">Nenhum acompanhamento registrado.</li><?php endif; ?>
    </ol>
  </section>

  <section id="evidencias" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Evidências de evolução</h3>
    <p class="mt-1 text-xs text-text-secondary">Quais resultados, comportamentos ou entregas demonstram evolução durante o período de desenvolvimento? (somente texto)</p>
    <p class="mt-2 text-sm"><?= $texto($pdi['evidencias_evolucao']) ?></p>
    <?php if (!empty($pode['acompanhar']) && $aberto): ?>
      <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-primary-700">Registrar evidências</summary>
        <?= $postForm($id . '/evidencias') ?>
          <textarea name="evidencias_evolucao" aria-label="Evidências de evolução" rows="4" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e((string)$pdi['evidencias_evolucao']) ?></textarea>
          <button type="submit" class="mt-2 <?= $botaoSec ?>">Salvar evidências</button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <section id="avaliacao-final" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Avaliação final</h3>
    <?php if ($status === 'concluido'): ?>
      <p class="mt-2 text-lg font-semibold text-text-primary"><?= Security::e(PdiService::AVALIACOES_FINAIS[$pdi['avaliacao_final']] ?? '') ?></p>
      <p class="mt-1 text-sm"><?= $texto($pdi['comentarios_finais']) ?></p>
      <p class="mt-1 text-xs text-text-secondary">Concluído em <?= Security::e(pdi_data_br($pdi['data_real_conclusao'])) ?>. A edição comum está bloqueada.</p>
      <?php if (!empty($pode['reabrir'])): ?>
        <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-primary-700">Reabrir PDI (Admin/RH)</summary>
          <?= $postForm($id . '/reabrir') ?>
            <label for="motivo-reabrir" class="mt-2 block text-sm font-medium">Motivo da reabertura</label>
            <textarea id="motivo-reabrir" name="motivo" required rows="2" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"></textarea>
            <p class="mt-1 text-xs text-text-secondary">A avaliação final e a data real de conclusão são limpas (ficam preservadas no histórico).</p>
            <button type="submit" class="mt-2 <?= $botaoSec ?>">Reabrir</button>
          </form>
        </details>
      <?php endif; ?>
    <?php elseif ($status === 'cancelado'): ?>
      <p class="mt-2 text-sm text-text-secondary">PDI cancelado — sem avaliação final.</p>
    <?php elseif ($emAndamento && !empty($pode['acompanhar'])): ?>
      <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-primary-700">Concluir PDI</summary>
        <?= $postForm($id . '/concluir') ?>
          <label for="avaliacao_final" class="mt-2 block text-sm font-medium">Avaliação final <span class="text-danger" aria-hidden="true">*</span></label>
          <select id="avaliacao_final" name="avaliacao_final" required class="<?= $campo ?>">
            <option value="">Selecione…</option>
            <?php foreach (PdiService::AVALIACOES_FINAIS as $k => $r): ?><option value="<?= Security::e($k) ?>"><?= Security::e($r) ?></option><?php endforeach; ?>
          </select>
          <label for="comentarios_finais" class="mt-2 block text-sm font-medium">Comentários finais</label>
          <textarea id="comentarios_finais" name="comentarios_finais" rows="3" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"></textarea>
          <button type="submit" class="mt-2 <?= $botao ?>">Concluir PDI</button>
        </form>
      </details>
    <?php else: ?>
      <p class="mt-2 text-sm text-text-secondary">A avaliação final é registrada ao concluir o PDI (somente a partir de "Em andamento").</p>
    <?php endif; ?>
    <?php if (!empty($pode['acompanhar']) && in_array($status, PdiService::STATUS_EDITAVEIS, true)): ?>
      <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-danger">Cancelar PDI</summary>
        <?= $postForm($id . '/cancelar') ?>
          <label for="motivo-cancelar" class="mt-2 block text-sm font-medium">Motivo do cancelamento</label>
          <textarea id="motivo-cancelar" name="motivo" required rows="2" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"></textarea>
          <button type="submit" class="mt-2 <?= ui_btn('destrutivo') ?>">Confirmar cancelamento</button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <section id="historico" class="<?= $card ?>">
    <h3 class="<?= $titulo ?>">Histórico</h3>
    <p class="mt-1 text-xs text-text-secondary">Trilha de auditoria (somente leitura): quem fez, quando e o que mudou.</p>
    <ol class="relative mt-4 space-y-4 border-l border-border pl-5">
      <?php foreach ($d['eventos'] as $e): ?>
        <li class="relative">
          <span class="absolute -left-[25px] top-1.5 h-2.5 w-2.5 rounded-full bg-primary-700 ring-4 ring-surface" aria-hidden="true"></span>
          <p class="text-sm font-medium text-text-primary"><?= Security::e(PdiService::rotuloEvento((string)$e['tipo_evento'])) ?><?= !empty($e['campo']) && !in_array($e['tipo_evento'], ['criacao', 'liberacao', 'inicio', 'conclusao', 'reabertura', 'cancelamento', 'acompanhamento'], true) ? ' <span class="font-normal text-text-secondary">(' . Security::e((string)$e['campo']) . ')</span>' : '' ?></p>
          <p class="text-xs text-text-secondary"><?= Security::e((string)($e['ator_nome'] ?? '—')) ?> (<?= Security::e((string)$e['ator_papel']) ?>) · <?= Security::e(pdi_data_hora_br($e['criado_em'])) ?><?= !empty($e['registrado_em_nome_do_colaborador']) ? ' · <span class="font-semibold text-primary-800">registrado em nome do colaborador</span>' : '' ?></p>
          <?php if ($e['tipo_evento'] !== 'acompanhamento' && ($e['valor_anterior'] !== null || $e['valor_novo'] !== null)): ?>
            <p class="mt-1 text-xs text-text-primary">
              <?php if ($e['valor_anterior'] !== null): ?><span class="text-text-secondary">de</span> <?= nl2br(Security::e(mb_strimwidth(PdiService::formatarValorEvento($e['campo'], $e['valor_anterior']), 0, 240, '…'))) ?> <?php endif; ?>
              <?php if ($e['valor_novo'] !== null): ?><span class="text-text-secondary">para</span> <?= nl2br(Security::e(mb_strimwidth(PdiService::formatarValorEvento($e['campo'], $e['valor_novo']), 0, 240, '…'))) ?><?php endif; ?>
            </p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
  </div>
</div>
