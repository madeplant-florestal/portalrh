<?php
/**
 * Administração da Pesquisa de Reação — Treinamento de Integração. Empresa/Setor vêm sempre dos
 * códigos oficiais do METADADOS (PesquisaReacaoCampanha::opcoesEmpresaSetor()) — nunca cadastro
 * paralelo. Status exibido é sempre derivado (nunca uma coluna redundante): Desativada (ativa=0),
 * Expirada (expira_em <= agora), Ativa (caso contrário).
 */
require_once APP_PATH . '/views/partials/modulo-topo.php';
$formValues = $formValues ?? [];
$agora = new DateTimeImmutable('now');
?>
<div class="space-y-8">
  <?= ui_modulo_topo($base, 'integracao', 'pesquisas', [
      'titulo' => 'Pesquisas de Integração',
      'descricao' => 'Acompanhe as pesquisas aplicadas aos colaboradores e os resultados das integrações realizadas.',
  ]) ?>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if (!empty($podeVerIntegracao)): ?>
  <!-- BLOCO 1 — Pesquisa de Integração (respostas via QR Code). Instrumento próprio: NPS e notas NÃO
       se misturam com os da Pesquisa de Reação abaixo. Visível só com integracao_colaborador.visualizar. -->
  <section class="space-y-3" aria-labelledby="bloco-integracao-qr">
    <div>
      <h3 id="bloco-integracao-qr" class="text-base font-bold text-text-primary">Pesquisa de Integração — Respostas via QR Code</h3>
      <p class="text-xs text-text-secondary">Resultados por data de integração. O QR Code e a abertura/encerramento da integração ficam em <a href="<?= $base ?>/admin/pesquisa-integracao-qr" class="text-primary-700 underline">Integração via QR Code</a>.</p>
    </div>
    <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-text-secondary">
            <th class="p-3">Data da Integração</th>
            <th class="p-3">Respostas</th>
            <th class="p-3">NPS</th>
            <th class="p-3">Promotores</th>
            <th class="p-3">Neutros</th>
            <th class="p-3">Detratores</th>
            <th class="p-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (($resumoIntegracaoQr ?? []) as $r): ?>
            <tr class="border-b">
              <td class="p-3 text-text-primary"><?= Security::e(date('d/m/Y', strtotime((string)$r['data_integracao']))) ?></td>
              <td class="p-3 text-text-primary"><?= (int)$r['total'] ?></td>
              <td class="p-3 font-bold text-text-primary"><?= $r['nps'] === null ? '—' : Security::e(($r['nps'] > 0 ? '+' : '') . number_format((float)$r['nps'], 1, ',', '.')) ?></td>
              <td class="p-3 text-success"><?= (int)$r['promotores'] ?></td>
              <td class="p-3 text-warning"><?= (int)$r['neutros'] ?></td>
              <td class="p-3 text-danger"><?= (int)$r['detratores'] ?></td>
              <td class="p-3"><a href="<?= $base ?>/admin/pesquisas-reacao-integracao/integracao/<?= Security::e((string)$r['data_integracao']) ?>/resultados" class="text-primary-700 hover:underline">Ver resultados</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($resumoIntegracaoQr)): ?>
            <tr><td colspan="7" class="p-4 text-center text-text-secondary">Nenhuma resposta recebida via QR Code até o momento.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($podeVerReacao)): ?>
  <!-- BLOCO 2 — Pesquisa de Reação (campanhas). Visível só com pesquisa_reacao_integracao.visualizar. -->
  <section class="space-y-6" aria-labelledby="bloco-reacao">
  <div>
    <h3 id="bloco-reacao" class="text-base font-bold text-text-primary">Pesquisa de Reação — Treinamento de Integração</h3>
    <p class="text-xs text-text-secondary">Campanhas de link público para avaliação do Treinamento de Integração pelos colaboradores.</p>
  </div>

  <?php if (!empty($linkGerado)): ?>
    <div class="rounded-ds-md border border-primary-300 bg-primary-50 px-4 py-3">
      <p class="text-sm font-semibold text-primary-800">Link gerado com sucesso — copie agora, ele não será mostrado novamente:</p>
      <p class="mt-2 break-all rounded-ds-md bg-surface px-3 py-2 text-sm text-text-primary"><?= Security::e($linkGerado) ?></p>
    </div>
  <?php endif; ?>

  <?php if (!empty($podeGerenciar)): ?>
  <section class="rounded-ds-lg border border-border bg-surface p-4">
    <h3 class="text-sm font-bold text-text-primary">Gerar link</h3>
    <?php if (!empty($formError)): ?>
      <div class="mt-3 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($formError) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= $base ?>/admin/pesquisas-reacao-integracao" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">

      <div>
        <label for="campo-empresa" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Empresa</label>
        <select id="campo-empresa" name="codigo_empresa" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
          <option value="">Não informar</option>
          <?php foreach ($opcoesEmpresaSetor['empresas'] as $empresa): ?>
            <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= ($formValues['codigo_empresa'] ?? '') === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="campo-setor" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Área / Setor</label>
        <select id="campo-setor" name="codigo_setor" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
          <option value="">Não informar</option>
          <?php foreach ($opcoesEmpresaSetor['setores'] as $setor): ?>
            <option value="<?= Security::e($setor['codigo_setor']) ?>" <?= ($formValues['codigo_setor'] ?? '') === $setor['codigo_setor'] ? 'selected' : '' ?>><?= Security::e($setor['nome'] ?? $setor['codigo_setor']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="campo-data-integracao" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Data da Integração</label>
        <input id="campo-data-integracao" type="date" name="data_integracao" value="<?= Security::e((string)($formValues['data_integracao'] ?? '')) ?>" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
      </div>

      <div>
        <label for="campo-validade" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Validade do link</label>
        <select id="campo-validade" name="validade_dias" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary">
          <?php foreach ($validadesRapidas as $dias): ?>
            <option value="<?= (int)$dias ?>" <?= (int)($formValues['validade_dias'] ?? 7) === $dias ? 'selected' : '' ?>><?= (int)$dias ?> dia<?= $dias > 1 ? 's' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sm:col-span-2 lg:col-span-3">
        <label for="campo-expira-customizada" class="block text-xs font-semibold uppercase tracking-wide text-text-secondary">Ou data/hora exata de expiração <span class="font-normal normal-case">(opcional — se preenchida, tem prioridade sobre a validade rápida acima)</span></label>
        <input id="campo-expira-customizada" type="datetime-local" name="expira_em_customizada" value="<?= Security::e((string)($formValues['expira_em_customizada'] ?? '')) ?>" class="mt-1 w-full rounded-ds-md border border-border px-3 py-2 text-sm text-text-primary sm:max-w-xs">
      </div>

      <div class="flex items-end">
        <button type="submit" class="w-full <?= ui_btn('primario') ?>">Gerar link</button>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <div class="responsive-table-wrap rounded-ds-lg border border-border bg-surface shadow-resting">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-text-secondary">
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
              $status = 'Desativada'; $statusClasses = 'bg-surface-secondary text-text-muted';
          } elseif ($expiraEm <= $agora) {
              $status = 'Expirada'; $statusClasses = 'bg-warning/10 text-warning';
          } else {
              $status = 'Ativa'; $statusClasses = 'bg-primary-50 text-primary-800';
          }
        ?>
          <tr class="border-b align-top">
            <td class="p-3 text-text-primary"><?= Security::e((string)($c['empresa_nome_snapshot'] ?? $c['codigo_empresa'] ?? '—')) ?></td>
            <td class="p-3 text-text-primary"><?= Security::e((string)($c['setor_nome_snapshot'] ?? $c['codigo_setor'] ?? '—')) ?></td>
            <td class="p-3 text-text-secondary"><?= !empty($c['data_integracao']) ? Security::e(date('d/m/Y', strtotime((string)$c['data_integracao']))) : '—' ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(date('d/m/Y H:i', strtotime((string)$c['created_at']))) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e($expiraEm->format('d/m/Y H:i')) ?></td>
            <td class="p-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClasses ?>"><?= $status ?></span></td>
            <td class="p-3 text-text-primary"><?= (int)$c['total_respostas'] ?></td>
            <td class="p-3">
              <div class="flex flex-col gap-2">
                <a href="<?= $base ?>/admin/pesquisas-reacao-integracao/<?= (int)$c['id'] ?>/resultados" class="text-primary-700 hover:underline">Ver resultados</a>
                <?php if (!empty($podeGerenciar) && (int)$c['ativa'] === 1): ?>
                  <form method="post" action="<?= $base ?>/admin/pesquisas-reacao-integracao/<?= (int)$c['id'] ?>/desativar" data-confirm-message="Desativar esta campanha? Ela deixará de aceitar novas respostas.">
                    <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <button type="submit" class="text-danger hover:underline">Desativar</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($campanhas)): ?>
          <tr><td colspan="8" class="p-4 text-center text-text-secondary">Nenhuma campanha criada ainda.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </section>
  <?php endif; ?>
</div>
