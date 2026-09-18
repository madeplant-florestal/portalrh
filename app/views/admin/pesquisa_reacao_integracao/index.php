<?php
/**
 * Administração da Pesquisa de Reação — Treinamento de Integração. Empresa/Setor vêm sempre dos
 * códigos oficiais do METADADOS (PesquisaReacaoCampanha::opcoesEmpresaSetor()) — nunca cadastro
 * paralelo. Status exibido é sempre derivado (nunca uma coluna redundante): Desativada (ativa=0),
 * Expirada (expira_em <= agora), Ativa (caso contrário).
 */
$formValues = $formValues ?? [];
$agora = new DateTimeImmutable('now');
?>
<div class="responsive-panel space-y-6">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-[#2B2E22]">Pesquisa de Reação — Treinamento de Integração</h2>
      <p class="mt-1 text-sm text-[#5B5F4E]">Campanhas de link público para avaliação do Treinamento de Integração pelos colaboradores.</p>
    </div>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if (!empty($linkGerado)): ?>
    <div class="rounded-xl border border-[#A9B885] bg-[#F2F4EC] px-4 py-3">
      <p class="text-sm font-semibold text-[#2E3919]">Link gerado com sucesso — copie agora, ele não será mostrado novamente:</p>
      <p class="mt-2 break-all rounded-lg bg-white px-3 py-2 text-sm text-[#2B2E22]"><?= Security::e($linkGerado) ?></p>
    </div>
  <?php endif; ?>

  <?php if (!empty($podeGerenciar)): ?>
  <section class="rounded-2xl border border-[#E2DFD0] bg-white p-4">
    <h3 class="text-sm font-bold text-[#2B2E22]">Gerar link</h3>
    <?php if (!empty($formError)): ?>
      <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($formError) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= $base ?>/admin/pesquisas-reacao-integracao" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">

      <div>
        <label for="campo-empresa" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Empresa</label>
        <select id="campo-empresa" name="codigo_empresa" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22]">
          <option value="">Não informar</option>
          <?php foreach ($opcoesEmpresaSetor['empresas'] as $empresa): ?>
            <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= ($formValues['codigo_empresa'] ?? '') === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="campo-setor" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Área / Setor</label>
        <select id="campo-setor" name="codigo_setor" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22]">
          <option value="">Não informar</option>
          <?php foreach ($opcoesEmpresaSetor['setores'] as $setor): ?>
            <option value="<?= Security::e($setor['codigo_setor']) ?>" <?= ($formValues['codigo_setor'] ?? '') === $setor['codigo_setor'] ? 'selected' : '' ?>><?= Security::e($setor['nome'] ?? $setor['codigo_setor']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="campo-data-integracao" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Data da Integração</label>
        <input id="campo-data-integracao" type="date" name="data_integracao" value="<?= Security::e((string)($formValues['data_integracao'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22]">
      </div>

      <div>
        <label for="campo-validade" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Validade do link</label>
        <select id="campo-validade" name="validade_dias" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22]">
          <?php foreach ($validadesRapidas as $dias): ?>
            <option value="<?= (int)$dias ?>" <?= (int)($formValues['validade_dias'] ?? 7) === $dias ? 'selected' : '' ?>><?= (int)$dias ?> dia<?= $dias > 1 ? 's' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sm:col-span-2 lg:col-span-3">
        <label for="campo-expira-customizada" class="block text-xs font-semibold uppercase tracking-wide text-[#5B5F4E]">Ou data/hora exata de expiração <span class="font-normal normal-case">(opcional — se preenchida, tem prioridade sobre a validade rápida acima)</span></label>
        <input id="campo-expira-customizada" type="datetime-local" name="expira_em_customizada" value="<?= Security::e((string)($formValues['expira_em_customizada'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2 text-sm text-[#2B2E22] sm:max-w-xs">
      </div>

      <div class="flex items-end">
        <button type="submit" class="w-full rounded-lg bg-[#3B4822] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#2E3919]">Gerar link</button>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <div class="responsive-table-wrap">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-[#5B5F4E]">
          <th class="p-3">Empresa</th>
          <th class="p-3">Área/Setor</th>
          <th class="p-3">Data da Integração</th>
          <th class="p-3">Criada em</th>
          <th class="p-3">Expira em</th>
          <th class="p-3">Status</th>
          <th class="p-3">Respostas</th>
          <th class="p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($campanhas ?? []) as $c):
          $expiraEm = new DateTimeImmutable((string)$c['expira_em']);
          if ((int)$c['ativa'] === 0) {
              $status = 'Desativada'; $statusClasses = 'bg-slate-100 text-slate-600';
          } elseif ($expiraEm <= $agora) {
              $status = 'Expirada'; $statusClasses = 'bg-amber-50 text-amber-700';
          } else {
              $status = 'Ativa'; $statusClasses = 'bg-[#F2F4EC] text-[#2E3919]';
          }
        ?>
          <tr class="border-b align-top">
            <td class="p-3 text-[#2B2E22]"><?= Security::e((string)($c['empresa_nome_snapshot'] ?? $c['codigo_empresa'] ?? '—')) ?></td>
            <td class="p-3 text-[#2B2E22]"><?= Security::e((string)($c['setor_nome_snapshot'] ?? $c['codigo_setor'] ?? '—')) ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= !empty($c['data_integracao']) ? Security::e(date('d/m/Y', strtotime((string)$c['data_integracao']))) : '—' ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e(date('d/m/Y H:i', strtotime((string)$c['created_at']))) ?></td>
            <td class="p-3 text-[#5B5F4E]"><?= Security::e($expiraEm->format('d/m/Y H:i')) ?></td>
            <td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClasses ?>"><?= $status ?></span></td>
            <td class="p-3 text-[#2B2E22]"><?= (int)$c['total_respostas'] ?></td>
            <td class="p-3">
              <div class="flex flex-col gap-2">
                <a href="<?= $base ?>/admin/pesquisas-reacao-integracao/<?= (int)$c['id'] ?>/resultados" class="text-[#3B4822] hover:underline">Ver resultados</a>
                <?php if (!empty($podeGerenciar) && (int)$c['ativa'] === 1): ?>
                  <form method="post" action="<?= $base ?>/admin/pesquisas-reacao-integracao/<?= (int)$c['id'] ?>/desativar" onsubmit="return confirm('Desativar esta campanha? Ela deixará de aceitar novas respostas.');">
                    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <button type="submit" class="text-red-600 hover:underline">Desativar</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($campanhas)): ?>
          <tr><td colspan="8" class="p-4 text-center text-[#5B5F4E]">Nenhuma campanha criada ainda.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
