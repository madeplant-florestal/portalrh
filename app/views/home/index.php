<?php
/**
 * Vitrine pública de vagas ("Trabalhe Conosco") — GET /vagas, HomeController::index().
 * Redesign puramente visual/organizacional: fonte de dados continua Vaga::allActive() (só `ativo=1`), mesmos campos
 * (titulo/descricao/requisitos/area/local/empresa_nome), mesmo destino de card (`/vaga/{id}`). Nenhuma rota, filtro
 * de banco ou regra de exibição nova — a busca/filtro abaixo é 100% client-side (assets/vagas-publicas.js) sobre a
 * mesma lista já carregada nesta página.
 *
 * Referência visual: madeplant.com.br/trabalhe-conosco (composição do hero, cards de diferenciais, CTA final, rodapé
 * institucional) — adaptada aos tokens do Design System Portal RH (`primary-*`/`support-*`, já numa paleta
 * verde-oliva/terracota muito próxima da marca real) em vez de copiada literalmente.
 */
$areas = [];
foreach ($vagas as $v) {
    $a = trim((string)($v['area'] ?? ''));
    if ($a !== '') {
        $areas[$a] = true;
    }
}
$areas = array_keys($areas);
sort($areas);
$totalVagas = count($vagas);

/**
 * Missão/Visão/Valores — CONTEÚDO PROVISÓRIO (texto genérico enquanto o material oficial da Madeplant não é
 * definido). Isolado num array só para trocar depois sem tocar no HTML/loop abaixo: basta editar `texto`/`titulo`.
 * Não é CMS nem configuração de banco — é só um array estático, como pedido.
 */
$missaoVisaoValores = [
    [
        'titulo' => 'Missão',
        'texto' => 'Transformar desafios em soluções eficientes e sustentáveis, valorizando pessoas, inovação e excelência em cada etapa do nosso trabalho.',
        // Alvo/direção
        'icone' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r=".5" fill="currentColor" stroke="none"/>',
    ],
    [
        'titulo' => 'Visão',
        'texto' => 'Ser referência em nosso segmento pela qualidade das nossas operações, pelo desenvolvimento das pessoas e pela capacidade de evoluir continuamente.',
        // Olho/horizonte
        'icone' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    ],
    [
        'titulo' => 'Valores',
        'texto' => 'Ética, respeito, segurança, responsabilidade, colaboração, inovação e compromisso com resultados sustentáveis.',
        // Escudo
        'icone' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>',
    ],
];
?>
<!-- Hero institucional: sangra até a borda da viewport (isFullBleed em layouts/main.php) -->
<section class="relative overflow-hidden" style="background: linear-gradient(160deg, #2E3919 0%, #3B4822 55%, #4D3726 100%);">
  <img src="<?= $base ?>/assets/Isotipolinear.png" alt="" aria-hidden="true"
       class="pointer-events-none absolute -right-16 -bottom-16 h-72 w-72 opacity-10 sm:h-96 sm:w-96">
  <div class="relative mx-auto max-w-6xl px-4 py-16 sm:py-20">
    <p class="text-ds-label font-semibold uppercase tracking-widest text-primary-100/80">Trabalhe conosco</p>
    <h1 data-page-title="1" class="font-brand mt-3 max-w-2xl text-3xl font-extrabold leading-tight text-white sm:text-4xl md:text-5xl">
      Construa sua carreira na Madeplant
    </h1>
    <p class="mt-4 max-w-xl text-ds-body text-white/85">
      Confira as oportunidades abertas e candidate-se em poucos minutos. Toda vaga publicada aqui é uma posição real,
      em análise pela nossa equipe de Recursos Humanos.
    </p>
    <div class="mt-8 flex flex-wrap items-center gap-4">
      <a href="#vagas-abertas" class="inline-flex h-11 items-center justify-center rounded-ds-md bg-white px-6 text-ds-button font-semibold text-primary-800 transition hover:bg-support-beige">
        Ver vagas abertas
      </a>
      <span class="text-ds-label text-white/80">
        <?= $totalVagas === 1 ? '1 vaga aberta agora' : "{$totalVagas} vagas abertas agora" ?>
      </span>
    </div>
  </div>
</section>

<!-- Marca → propósito → oportunidades: Missão/Visão/Valores entre o hero e a listagem de vagas -->
<section class="bg-support-beige">
  <div class="mx-auto max-w-6xl px-4 py-14 sm:py-16">
    <div class="mx-auto max-w-2xl text-center">
      <p class="text-ds-label font-semibold uppercase tracking-widest text-primary-700">O que nos move</p>
      <h2 class="mt-2 text-2xl font-bold text-text-primary sm:text-3xl">
        Conheça os princípios que orientam nossa forma de trabalhar, crescer e construir resultados.
      </h2>
    </div>
    <div class="mt-10 grid gap-5 sm:grid-cols-3">
      <?php foreach ($missaoVisaoValores as $item): ?>
        <article class="rounded-ds-lg border border-border bg-surface p-6">
          <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary-100 text-primary-700">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><?= $item['icone'] ?></svg>
          </span>
          <h3 class="mt-4 text-lg font-bold text-text-primary"><?= Security::e($item['titulo']) ?></h3>
          <p class="mt-2 text-ds-body text-text-secondary"><?= Security::e($item['texto']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="mx-auto max-w-6xl px-4 py-10 sm:py-14">
  <?php if (!empty($erro)): ?>
    <div class="mb-6 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div>
  <?php endif; ?>

  <div id="vagas-abertas" class="scroll-mt-6">
    <?php if ($totalVagas === 0): ?>
      <div class="mx-auto max-w-md rounded-ds-lg border border-border bg-surface px-6 py-12 text-center shadow-resting">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-surface-secondary text-text-muted">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7H4a1 1 0 00-1 1v11a1 1 0 001 1h16a1 1 0 001-1V8a1 1 0 00-1-1z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
        </span>
        <h2 class="mt-4 text-lg font-bold text-text-primary">Nenhuma vaga aberta no momento</h2>
        <p class="mt-2 text-ds-body text-text-secondary">
          Não temos oportunidades ativas agora, mas nosso time está sempre crescendo. Volte em breve para conferir novas vagas.
        </p>
      </div>
    <?php else: ?>
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 class="text-xl font-bold text-text-primary">Vagas abertas</h2>
          <p class="mt-1 text-ds-body text-text-secondary">Use a busca para encontrar a oportunidade certa para você.</p>
        </div>
      </div>

      <?php if ($totalVagas > 1): ?>
        <div data-vagas-filtro="1" class="mt-6 flex flex-col gap-3 rounded-ds-lg border border-border bg-surface p-4 shadow-resting sm:flex-row sm:items-center">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
            <input
              type="search"
              data-vagas-filtro-texto="1"
              placeholder="Buscar por cargo, área ou local..."
              class="h-11 w-full rounded-ds-md border border-border bg-surface pl-9 pr-3 text-[14px] text-text-primary placeholder:text-text-muted focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100"
            >
          </div>
          <?php if (count($areas) > 1): ?>
            <select data-vagas-filtro-area="1" class="h-11 w-full rounded-ds-md border border-border bg-surface px-3 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100 sm:w-56">
              <option value="">Todas as áreas</option>
              <?php foreach ($areas as $a): ?>
                <option value="<?= Security::e($a) ?>"><?= Security::e($a) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div data-vagas-lista="1" class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($vagas as $v): ?>
          <?php
          $resumo = trim((string)($v['descricao'] ?? '')) !== '' ? $v['descricao'] : (string)($v['requisitos'] ?? '');
          $resumo = trim(preg_replace('/\s+/', ' ', $resumo));
          ?>
          <article
            data-vaga-card="1"
            data-vaga-busca="<?= Security::e(mb_strtolower($v['titulo'] . ' ' . $v['area'] . ' ' . $v['local'])) ?>"
            data-vaga-area="<?= Security::e($v['area']) ?>"
            class="group flex flex-col rounded-ds-lg border border-border bg-surface p-6 shadow-resting transition duration-150 hover:-translate-y-0.5 hover:border-primary-700 hover:shadow-elevated motion-reduce:transition-none"
          >
            <?php if (trim((string)$v['area']) !== ''): ?>
              <span class="inline-flex w-fit items-center rounded-ds-sm bg-primary-100 px-2 py-1 text-ds-badge text-primary-700"><?= Security::e($v['area']) ?></span>
            <?php endif; ?>
            <h3 class="mt-4 text-lg font-bold leading-snug text-text-primary"><?= Security::e($v['titulo']) ?></h3>
            <p class="mt-2 flex items-center gap-1.5 text-ds-caption text-text-secondary">
              <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-6.1-7-11a7 7 0 1114 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
              <?= Security::e($v['local']) ?><?= !empty($v['empresa_nome']) ? ' • ' . Security::e($v['empresa_nome']) : '' ?>
            </p>
            <?php if ($resumo !== ''): ?>
              <p class="mt-3 line-clamp-3 text-ds-body text-text-secondary"><?= Security::e($resumo) ?></p>
            <?php endif; ?>
            <div class="mt-5 border-t border-border pt-4">
              <a href="<?= $base ?>/vaga/<?= (int)$v['id'] ?>" class="inline-flex h-10 w-full items-center justify-center rounded-ds-md bg-primary-700 px-4 text-ds-button font-semibold text-white transition hover:bg-primary-800">
                Ver detalhes e candidatar-se
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div data-vagas-sem-resultado="1" class="mt-6 hidden rounded-ds-lg border border-border bg-surface-secondary px-6 py-10 text-center">
        <p class="text-ds-body font-semibold text-text-primary">Nenhuma vaga encontrada para essa busca.</p>
        <p class="mt-1 text-ds-caption text-text-secondary">Tente outro termo ou limpe o filtro de área.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="<?= $base ?>/assets/vagas-publicas.js?v=<?= urlencode(Config::assetVersion('assets/vagas-publicas.js')) ?>" defer></script>
