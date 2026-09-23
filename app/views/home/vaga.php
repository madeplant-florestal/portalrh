<?php
/**
 * Detalhe público de uma vaga — GET /vaga/{id}, HomeController::vaga(). Redesign puramente visual: mesmos dados
 * (Vaga::find, Beneficio::allActive), mesmo formulário de candidatura (POST /candidatar/{id}, mesmos name="" e
 * data-* que assets/public.js já lê para máscara de telefone/CPF — nada disso foi tocado, só a apresentação).
 */
?>
<!-- Faixa institucional do topo: sangra até a borda (isFullBleed) -->
<section class="relative overflow-hidden" style="background: linear-gradient(160deg, #2E3919 0%, #3B4822 100%);">
  <div class="relative mx-auto max-w-6xl px-4 py-10 sm:py-14">
    <a href="<?= $base ?>/vagas" class="inline-flex items-center gap-1.5 text-ds-label font-medium text-white/80 hover:text-white">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>
      Voltar às vagas
    </a>
    <?php if (trim((string)$vaga['area']) !== ''): ?>
      <p class="mt-5 text-ds-label font-semibold uppercase tracking-widest text-primary-100/80"><?= Security::e($vaga['area']) ?></p>
    <?php endif; ?>
    <h1 data-page-title="1" class="font-brand mt-2 max-w-2xl text-3xl font-extrabold leading-tight text-white sm:text-4xl">
      <?= Security::e($vaga['titulo']) ?>
    </h1>
    <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-ds-body text-white/85">
      <span class="flex items-center gap-1.5">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-6.1-7-11a7 7 0 1114 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
        <?= Security::e($vaga['local']) ?>
      </span>
      <?php if (!empty($vaga['empresa_nome'])): ?>
        <span class="flex items-center gap-1.5">
          <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1"/></svg>
          <?= Security::e($vaga['empresa_nome']) ?>
        </span>
      <?php endif; ?>
    </div>
    <a href="#candidatura" class="mt-8 inline-flex h-11 items-center justify-center rounded-ds-md bg-white px-6 text-ds-button font-semibold text-primary-800 transition hover:bg-support-beige">
      Candidatar-se a esta vaga
    </a>
  </div>
</section>

<div class="mx-auto max-w-6xl px-4 py-10 sm:py-14">
  <div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
      <div class="rounded-ds-lg border border-border bg-surface p-6 shadow-resting">
        <h2 class="text-ds-h3 font-bold text-text-primary">Sobre a oportunidade</h2>
        <p class="mt-3 whitespace-pre-line text-ds-body leading-relaxed text-text-secondary"><?= Security::e($vaga['descricao']) ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-6 shadow-resting">
        <h2 class="text-ds-h3 font-bold text-text-primary">Requisitos</h2>
        <p class="mt-3 whitespace-pre-line text-ds-body leading-relaxed text-text-secondary"><?= Security::e($vaga['requisitos']) ?></p>
      </div>
    </div>

    <div class="space-y-6">
      <div class="rounded-ds-lg border border-border bg-surface-secondary p-6">
        <h2 class="text-ds-h3 font-bold text-text-primary">Benefícios</h2>
        <?php if (!empty($beneficios)): ?>
          <div class="mt-4 space-y-3">
            <?php foreach ($beneficios as $b): ?>
              <div class="rounded-ds-md border border-border bg-surface p-3 text-sm">
                <?php if (!empty($b['logo_path'])): ?>
                  <img src="<?= $base ?>/uploads/logos/<?= Security::e($b['logo_path']) ?>" alt="Logo <?= Security::e($b['parceiro'] ?? $b['nome']) ?>" data-img-fallback-hide="1" class="mb-2 h-8 w-auto object-contain" />
                <?php endif; ?>
                <div class="flex items-center font-semibold text-text-primary">
                  <span><?= Security::e($b['nome']) ?></span>
                  <?php if (!empty($b['parceiro'])): ?>
                    <span class="ml-1 font-normal text-text-muted">• <?= Security::e($b['parceiro']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($b['descricao'])): ?>
                  <div class="mt-1 text-text-secondary"><?= Security::e($b['descricao']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="mt-2 text-ds-body text-text-secondary">Nenhum benefício ativo no momento.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div id="candidatura" class="mt-10 scroll-mt-6 rounded-ds-lg border border-border bg-surface p-6 shadow-resting sm:p-8">
    <h2 class="text-ds-h3 font-bold text-text-primary">Candidate-se para esta vaga</h2>
    <p class="mt-1 text-ds-body text-text-secondary">Preencha seus dados e anexe seu currículo em PDF. Leva menos de 3 minutos.</p>
    <form class="mt-6 space-y-5" action="<?= $base ?>/candidatar/<?= (int)$vaga['id'] ?>" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label class="block text-ds-label font-semibold text-text-primary">Nome completo</label>
        <input type="text" name="nome" required
               class="mt-1.5 h-11 w-full rounded-ds-md border border-border bg-surface px-3 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="block text-ds-label font-semibold text-text-primary">E-mail</label>
          <input type="email" name="email" required
                 class="mt-1.5 h-11 w-full rounded-ds-md border border-border bg-surface px-3 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100" />
        </div>
        <div>
          <label class="block text-ds-label font-semibold text-text-primary">Telefone</label>
          <input type="tel" name="telefone" required data-phone-input="1" maxlength="15" placeholder="(00) 00000-0000"
                 class="mt-1.5 h-11 w-full rounded-ds-md border border-border bg-surface px-3 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100" />
          <div data-phone-error="invalid" class="mt-1 hidden text-sm text-danger">Telefone inválido. Informe 11 dígitos (DDD + número).</div>
        </div>
      </div>
      <div>
        <label class="flex items-center text-ds-label font-semibold text-text-primary">
          CPF
          <span class="ml-1 cursor-help text-text-muted" title="Precisamos do seu CPF para evitar candidaturas duplicadas e garantir a integridade do processo seletivo">ℹ️</span>
        </label>
        <input type="text" name="cpf" id="cpf" maxlength="14" required data-cpf-input="1" placeholder="000.000.000-00"
               class="mt-1.5 h-11 w-full rounded-ds-md border border-border bg-surface px-3 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100" />
        <div id="cpf-error" data-cpf-error="exists" class="mt-1 hidden text-sm text-danger">Você já possui uma candidatura ativa. Aguarde o resultado antes de se candidatar novamente.</div>
        <div id="cpf-invalid" data-cpf-error="invalid" class="mt-1 hidden text-sm text-danger">CPF inválido. Verifique os dígitos.</div>
      </div>
      <div>
        <label class="block text-ds-label font-semibold text-text-primary">Cargo pretendido</label>
        <input type="text" name="cargo_pretendido_display" value="<?= Security::e($vaga['titulo']) ?>" disabled
               class="mt-1.5 h-11 w-full cursor-not-allowed rounded-ds-md border border-border bg-surface-secondary px-3 text-[14px] text-text-secondary" />
        <input type="hidden" name="cargo_pretendido" value="<?= Security::e($vaga['titulo']) ?>" />
      </div>
      <div>
        <label class="block text-ds-label font-semibold text-text-primary">Experiência</label>
        <textarea name="experiencia" rows="4" required
                  class="mt-1.5 w-full rounded-ds-md border border-border bg-surface px-3 py-2 text-[14px] text-text-primary focus:border-focus focus:outline-none focus:ring-2 focus:ring-primary-100"></textarea>
      </div>
      <div>
        <label class="block text-ds-label font-semibold text-text-primary">Currículo (PDF)</label>
        <input type="file" name="curriculo" accept="application/pdf" required class="mt-1.5 block w-full text-[14px] text-text-secondary" />
      </div>
      <button type="submit" class="inline-flex h-11 w-full items-center justify-center rounded-ds-md bg-primary-700 px-6 text-ds-button font-semibold text-white transition hover:bg-primary-800 sm:w-auto">
        Enviar candidatura
      </button>
    </form>
  </div>
</div>
