<?php
/**
 * PDI — criação (a partir de um contrato oficial) e edição da ESTRUTURA do plano. Sem Área, sem anexos, sem
 * assinatura. Gestor escolhido explicitamente (Admin/RH); quem é só gestor cria/edita com o próprio usuário como gestor.
 */
$criar = $modo === 'criar';
$pdi = $detalhe['pdi'] ?? null;
$acaoForm = $criar ? $base . '/admin/pdis' : $base . '/admin/pdis/' . (int)$pdi['id'] . '/editar';
$v = static fn(string $k): string => (string)($valores[$k] ?? '');
$campo = 'mt-1 w-full rounded-lg border border-[#E2DFD0] bg-white px-3 py-2.5 text-sm text-[#2B2E22] outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]';
$rotulo = 'block text-sm font-medium text-[#2B2E22]';
$titulo = 'text-sm font-bold uppercase tracking-wide text-[#5B5F4E]';
$nome = $criar ? (string)$contrato['nome'] : (string)$pdi['snap_nome'];
$cargo = $criar ? (string)($contrato['cargo'] ?? '') : (string)($pdi['snap_cargo'] ?? '');
$empresa = $criar ? (string)($contrato['empresa'] ?? '') : (string)($pdi['snap_empresa'] ?? '');
$unidade = $criar ? (string)($contrato['unidade'] ?? '') : (string)($pdi['snap_unidade'] ?? '');
$admissao = $criar ? ($contrato['admissao'] ?? null) : ($pdi['snap_admissao'] ?? null);
?>
<div class="responsive-panel space-y-5">
  <div class="responsive-header">
    <div>
      <a href="<?= $base ?>/admin/pdis<?= $criar ? '' : '/' . (int)$pdi['id'] ?>" class="text-sm text-[#3B4822] hover:underline">&larr; <?= $criar ? 'PDIs' : 'Voltar ao PDI' ?></a>
      <h2 class="mt-1 text-xl font-semibold text-[#2B2E22]"><?= $criar ? 'Novo PDI' : 'Editar plano' ?> — <?= Security::e($nome) ?></h2>
      <p class="mt-1 text-sm text-[#5B5F4E]"><?= $criar ? 'O PDI nasce como rascunho e pode ser completado depois.' : 'Alterações de prazo, gestor, origem, competências e ações ficam registradas no histórico.' ?></p>
    </div>
  </div>

  <?php if ($erros !== []): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
      <p class="font-semibold">Confira os pontos abaixo:</p>
      <ul class="mt-1 list-disc pl-5"><?php foreach ($erros as $e): ?><li><?= Security::e((string)$e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= Security::e($acaoForm) ?>" class="space-y-6">
    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <?php if ($criar): ?><input type="hidden" name="metadados_id" value="<?= (int)$contrato['metadados_id'] ?>"><?php endif; ?>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="<?= $titulo ?>">Identificação</h3>
      <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs text-[#5B5F4E]">Colaborador</dt><dd class="font-medium"><?= Security::e($nome) ?></dd></div>
        <div><dt class="text-xs text-[#5B5F4E]">Cargo</dt><dd><?= Security::e($cargo !== '' ? $cargo : '—') ?></dd></div>
        <div><dt class="text-xs text-[#5B5F4E]">Empresa / Unidade</dt><dd><?= Security::e($empresa !== '' ? $empresa : '—') ?><?= $unidade !== '' && $unidade !== $empresa ? ' — ' . Security::e($unidade) : '' ?></dd></div>
        <div><dt class="text-xs text-[#5B5F4E]">Admissão</dt><dd><?= !empty($admissao) ? Security::e(date('d/m/Y', strtotime((string)$admissao))) : '—' ?></dd></div>
      </dl>
      <p class="mt-2 text-xs text-[#5B5F4E]">Dados oficiais do METADADOS (o snapshot da abertura fica preservado no PDI).</p>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
          <label for="gestor_usuario_id" class="<?= $rotulo ?>">Gestor responsável <span class="text-red-600" aria-hidden="true">*</span></label>
          <?php if ($escopoTotal): ?>
            <select id="gestor_usuario_id" name="gestor_usuario_id" required class="<?= $campo ?>">
              <option value="">Selecione um usuário do Portal…</option>
              <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $v('gestor_usuario_id') === (string)$u['id'] ? 'selected' : '' ?>><?= Security::e((string)$u['nome']) ?> (<?= Security::e((string)$u['role']) ?>)</option><?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-[#5B5F4E]">Escolha explícita — o sistema não infere o gestor. O gestor precisa da permissão pdi.visualizar para acompanhar.</p>
          <?php else: ?>
            <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-2.5 text-sm text-[#2B2E22]"><?= Security::e($criar ? 'Você (gestor responsável)' : (string)$pdi['gestor_nome_snapshot']) ?></p>
          <?php endif; ?>
        </div>
        <div>
          <label for="origem_tipo" class="<?= $rotulo ?>">Origem do PDI <span class="text-red-600" aria-hidden="true">*</span></label>
          <select id="origem_tipo" name="origem_tipo" required class="<?= $campo ?>">
            <option value="">Selecione…</option>
            <?php foreach (PdiService::ORIGENS as $k => $r): ?><option value="<?= Security::e($k) ?>" <?= $v('origem_tipo') === $k ? 'selected' : '' ?>><?= Security::e($r) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:col-span-4">
          <div>
            <label for="data_abertura" class="<?= $rotulo ?>">Data de abertura <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="data_abertura" type="date" name="data_abertura" required value="<?= Security::e($v('data_abertura')) ?>" class="<?= $campo ?>">
          </div>
          <div>
            <label for="data_prevista_conclusao" class="<?= $rotulo ?>">Data prevista para conclusão <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="data_prevista_conclusao" type="date" name="data_prevista_conclusao" required value="<?= Security::e($v('data_prevista_conclusao')) ?>" class="<?= $campo ?>">
          </div>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="<?= $titulo ?>">Desenvolvimento profissional</h3>
      <div class="mt-3 space-y-4">
        <div>
          <label for="pontos_fortes" class="<?= $rotulo ?>">Pontos fortes identificados</label>
          <p class="text-xs text-[#5B5F4E]">Quais comportamentos, habilidades ou resultados têm contribuído positivamente para o desempenho do colaborador?</p>
          <textarea id="pontos_fortes" name="pontos_fortes" rows="4" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e($v('pontos_fortes')) ?></textarea>
        </div>
        <div>
          <label for="oportunidades_desenvolvimento" class="<?= $rotulo ?>">Oportunidades de desenvolvimento</label>
          <p class="text-xs text-[#5B5F4E]">Quais aspectos podem ser aprimorados para potencializar a atuação profissional do colaborador?</p>
          <textarea id="oportunidades_desenvolvimento" name="oportunidades_desenvolvimento" rows="4" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e($v('oportunidades_desenvolvimento')) ?></textarea>
        </div>
        <div>
          <label for="competencias" class="<?= $rotulo ?>">Competências a desenvolver</label>
          <p class="text-xs text-[#5B5F4E]">Uma competência por linha (até <?= PdiService::MAX_COMPETENCIAS ?>). Texto livre nesta versão.</p>
          <textarea id="competencias" name="competencias" rows="3" class="<?= $campo ?>"><?= Security::e($v('competencias')) ?></textarea>
        </div>
        <div>
          <label for="objetivo_esperado" class="<?= $rotulo ?>">O que se espera alcançar ao final deste plano?</label>
          <textarea id="objetivo_esperado" name="objetivo_esperado" rows="3" maxlength="<?= PdiService::LIMITE_TEXTO ?>" class="<?= $campo ?>"><?= Security::e($v('objetivo_esperado')) ?></textarea>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
      <h3 class="<?= $titulo ?>">Plano de ação</h3>
      <p class="mt-1 text-xs text-[#5B5F4E]">Até <?= PdiService::MAX_ACOES ?> ações. Deixe em branco o que não for usar. Responsável "Gestor" usa o gestor do PDI e "Colaborador" usa o nome do colaborador; para RH/Outro, escolha um usuário do Portal ou informe o nome.</p>
      <div class="mt-3 space-y-4">
        <?php for ($n = 1; $n <= PdiService::MAX_ACOES; $n++): $a = (array)(($valores['acoes'] ?? [])[$n] ?? []); ?>
          <fieldset class="rounded-xl border border-[#E2DFD0] p-3">
            <legend class="px-1 text-xs font-semibold text-[#5B5F4E]">Ação <?= $n ?></legend>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div class="sm:col-span-2 lg:col-span-4">
                <label for="acao-<?= $n ?>-descricao" class="<?= $rotulo ?>">Ação de desenvolvimento</label>
                <input id="acao-<?= $n ?>-descricao" type="text" name="acoes[<?= $n ?>][descricao]" maxlength="<?= PdiService::LIMITE_DESCRICAO_ACAO ?>" value="<?= Security::e((string)($a['descricao'] ?? '')) ?>" class="<?= $campo ?>">
              </div>
              <div>
                <label for="acao-<?= $n ?>-tipo" class="<?= $rotulo ?>">Responsável</label>
                <select id="acao-<?= $n ?>-tipo" name="acoes[<?= $n ?>][responsavel_tipo]" class="<?= $campo ?>">
                  <?php foreach (PdiService::RESPONSAVEIS as $k => $r): ?><option value="<?= Security::e($k) ?>" <?= (string)($a['responsavel_tipo'] ?? 'colaborador') === $k ? 'selected' : '' ?>><?= Security::e($r) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="acao-<?= $n ?>-usuario" class="<?= $rotulo ?>">Usuário (RH/Outro)</label>
                <select id="acao-<?= $n ?>-usuario" name="acoes[<?= $n ?>][responsavel_usuario_id]" class="<?= $campo ?>">
                  <option value="">—</option>
                  <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)($a['responsavel_usuario_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= Security::e((string)$u['nome']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="acao-<?= $n ?>-nome" class="<?= $rotulo ?>">Nome (sem usuário)</label>
                <input id="acao-<?= $n ?>-nome" type="text" name="acoes[<?= $n ?>][responsavel_nome]" maxlength="<?= PdiService::LIMITE_NOME ?>" value="<?= Security::e((string)($a['responsavel_nome'] ?? '')) ?>" class="<?= $campo ?>">
              </div>
              <div>
                <label for="acao-<?= $n ?>-prazo" class="<?= $rotulo ?>">Prazo</label>
                <input id="acao-<?= $n ?>-prazo" type="date" name="acoes[<?= $n ?>][prazo]" value="<?= Security::e((string)($a['prazo'] ?? '')) ?>" class="<?= $campo ?>">
              </div>
            </div>
          </fieldset>
        <?php endfor; ?>
      </div>
    </section>

    <div class="flex flex-wrap gap-3">
      <button type="submit" class="rounded-xl bg-[#3B4822] px-5 py-3 text-sm font-semibold text-white hover:bg-[#2E3919]"><?= $criar ? 'Criar PDI (rascunho)' : 'Salvar plano' ?></button>
      <a href="<?= $base ?>/admin/pdis<?= $criar ? '' : '/' . (int)$pdi['id'] ?>" class="px-3 py-3 text-sm text-[#5B5F4E] hover:underline">Cancelar</a>
    </div>
  </form>
</div>
