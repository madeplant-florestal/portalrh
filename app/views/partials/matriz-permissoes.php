<?php
/**
 * Matriz horizontal de permissões (Etapa 3) — compartilhada por `admin/usuarios/create` e `admin/usuarios/show`.
 * Dados de PermissaoMatrizService::construir(); comportamento em assets/matriz-permissoes.js. Os checkboxes continuam sendo
 * `permissao_ids[]` (id real da permissão): o backend valida tudo de novo (política central) — nada aqui é barreira de segurança.
 * Texto de interface (rótulos) nunca é identificador: a célula carrega id e código.
 *
 * @var array  $matriz       resultado de PermissaoMatrizService::construir()
 * @var string $seletorPerfil id do <select name="role"> que dirige a matriz
 */
if (!function_exists('ui_matriz_permissoes')) {
    function ui_matriz_permissoes(array $matriz, string $seletorPerfil): string
    {
        $e = static fn($v): string => Security::e((string)$v);
        $perfisJson = json_encode($matriz['perfis'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $colunas = $matriz['colunas'];
        $nCol = count($colunas) + 1;
        ob_start(); ?>
<div data-matriz="1" data-perfis="<?= $e($perfisJson) ?>" data-perfil="<?= $e($matriz['perfil']) ?>" data-edicao="<?= $matriz['edicao'] ? '1' : '0' ?>" data-seletor-perfil="<?= $e($seletorPerfil) ?>" class="space-y-3">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <p class="text-sm text-text-primary" role="status" aria-live="polite">
      <?php if ($matriz['global']): ?>
        <strong data-matriz-contador><?= (int)$matriz['selecionadas'] ?> atribuições individuais</strong>
        <span class="text-text-secondary" data-matriz-total-texto>gravada(s) — sem efeito prático: o acesso é global pelo perfil e não depende delas</span>
      <?php else: ?>
        <strong data-matriz-contador><?= (int)$matriz['selecionadas'] ?> selecionadas</strong>
        <span class="text-text-secondary" data-matriz-total-texto>de <?= (int)$matriz['total'] ?> permissões do catálogo</span>
      <?php endif; ?>
    </p>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Seleção em lote">
      <button type="button" data-matriz-acao="marcar-todas" class="<?= ui_btn('secundario') ?>">Marcar todas</button>
      <button type="button" data-matriz-acao="desmarcar-todas" class="<?= ui_btn('secundario') ?>">Desmarcar todas</button>
      <button type="button" data-matriz-acao="restaurar-modelo" class="<?= ui_btn('ghost') ?>">Restaurar modelo do perfil</button>
    </div>
  </div>

  <div data-matriz-aviso-global class="<?= $matriz['global'] ? '' : 'hidden ' ?>rounded-ds-md border border-primary-100 bg-primary-50 px-3 py-2 text-xs text-text-primary">
    <strong>Perfil de acesso global.</strong> Admin e RH acessam todos os módulos pelo próprio perfil: não é preciso marcar permissões individuais
    e as ações em lote ficam desativadas. As restrições hierárquicas (Supervisor Geral, Admin) continuam valendo e não dependem desta matriz.
  </div>
  <div data-matriz-aviso-viewer class="hidden rounded-ds-md border border-border bg-surface px-3 py-2 text-xs text-text-secondary">
    Leitor: só dashboards agregados auditados. Os dashboards com risco de reidentificação não são marcados automaticamente, e as ações em lote
    não marcam as permissões operacionais de transição — marque-as uma a uma, se necessário.
  </div>
  <div data-matriz-aviso-legado class="<?= $matriz['legado'] === [] ? 'hidden ' : '' ?>rounded-ds-md border border-danger/30 bg-danger/10 px-3 py-2 text-xs text-danger" role="alert">
    Permissões gravadas e incompatíveis com o perfil (sem efeito; <strong>não são reativadas</strong>). O salvamento só é aceito depois de
    desmarcá-las: <span data-matriz-legado-lista><?= $e(implode(', ', $matriz['legado'])) ?></span>
  </div>

  <ul class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-text-secondary" aria-label="Legenda">
    <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm border border-primary-700 bg-primary-700"></span> Marcada (efetiva)</li>
    <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm border border-border bg-surface"></span> Livre</li>
    <li class="flex items-center gap-1"><span aria-hidden="true">★</span> Modelo recomendado</li>
    <li class="flex items-center gap-1"><span aria-hidden="true" class="text-warning">◐</span> Transição (uso atual do Leitor)</li>
    <li class="flex items-center gap-1"><span aria-hidden="true" class="text-danger">⚠</span> Gravada sem efeito (incompatível)</li>
    <li class="flex items-center gap-1"><span aria-hidden="true">—</span> Não aplicável ao perfil</li>
  </ul>

  <div class="overflow-x-auto rounded-ds-md border border-border" tabindex="0" role="region" aria-label="Matriz de permissões (role horizontalmente em telas pequenas)">
    <table class="w-full min-w-[56rem] border-collapse text-sm">
      <thead class="bg-surface-secondary">
        <tr>
          <th scope="col" class="sticky left-0 z-10 w-56 min-w-[14rem] bg-surface-secondary px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-text-secondary">Módulo</th>
          <?php foreach ($colunas as $rotulo): ?>
            <th scope="col" class="px-2 py-2 text-center text-xs font-bold uppercase tracking-wide text-text-secondary"><?= $e($rotulo) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <?php foreach ($matriz['areas'] as $area): ?>
        <tbody data-matriz-area="<?= $e($area['chave']) ?>">
          <tr class="bg-primary-50">
            <th scope="colgroup" colspan="<?= (int)$nCol ?>" class="px-3 py-1.5 text-left text-xs font-bold text-text-primary">
              <span class="mr-3"><?= $e($area['rotulo']) ?></span>
              <button type="button" data-matriz-acao="marcar-grupo" class="mr-2 text-[11px] font-semibold text-primary-700 underline">Marcar grupo</button>
              <button type="button" data-matriz-acao="desmarcar-grupo" class="text-[11px] font-semibold text-primary-700 underline">Limpar grupo</button>
            </th>
          </tr>
          <?php foreach ($area['linhas'] as $linha): ?>
            <tr class="border-t border-border" data-matriz-linha="<?= $e($linha['modulo']) ?>" data-modulo-catalogo="<?= $e(implode(',', $linha['modulo_catalogo'])) ?>">
              <th scope="row" class="sticky left-0 z-10 bg-surface px-3 py-2.5 text-left font-medium text-text-primary">
                <?= $e($linha['rotulo']) ?>
                <button type="button" data-matriz-acao="alternar-linha" class="ml-2 text-[11px] font-normal text-primary-700 underline" aria-label="Alternar todas as permissões de <?= $e($linha['rotulo']) ?>">linha</button>
              </th>
              <?php foreach ($colunas as $acao => $rotuloAcao): ?>
                <td class="px-2 py-2.5 text-center">
                  <?php $c = $linha['celulas'][$acao] ?? null; ?>
                  <?php if ($c === null): ?>
                    <span class="text-text-muted" aria-hidden="true">·</span>
                  <?php else:
                      $desabilitada = !$c['aplicavel'] && !$c['legado_incompativel'];
                      $rotuloCelula = $linha['rotulo'] . ' — ' . $c['nome']; ?>
                    <label class="inline-flex items-center gap-1" title="<?= $e($c['codigo']) ?>">
                      <input type="checkbox"
                             <?= $desabilitada ? '' : 'name="permissao_ids[]"' ?>
                             value="<?= (int)$c['id'] ?>"
                             data-matriz-celula="1"
                             data-perm-id="<?= (int)$c['id'] ?>"
                             data-codigo="<?= $e($c['codigo']) ?>"
                             data-classe="<?= $e($c['classe']) ?>"
                             data-legado="<?= $c['legado_incompativel'] ? '1' : '0' ?>"
                             aria-label="<?= $e($rotuloCelula) ?>"
                             class="h-4 w-4 shrink-0 rounded border-border accent-primary-700 <?= $c['legado_incompativel'] ? 'ring-2 ring-danger' : '' ?>"
                             <?= $c['marcada'] ? 'checked' : '' ?>
                             <?= $desabilitada ? 'disabled' : '' ?>>
                      <span class="w-3 text-[11px] leading-none" aria-hidden="true" data-matriz-marcador="1"><?php
                          if ($c['legado_incompativel']) { echo '<span class="text-danger">⚠</span>'; }
                          elseif ($desabilitada) { echo '—'; }
                          elseif ($c['classe'] === PermissaoPerfilPolicy::TRANSITORIA_GESTOR) { echo '<span class="text-warning">◐</span>'; }
                          elseif ($c['recomendada']) { echo '★'; }
                      ?></span>
                    </label>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php
        return (string)ob_get_clean();
    }
}
