<?php
?>
<div class="mx-auto max-w-lg">
  <div class="rounded-ds-lg border border-border bg-surface p-6 text-center shadow-elevated sm:p-10">
    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success/10">
      <svg class="h-9 w-9 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
      </svg>
    </div>

    <h1 data-page-title="1" class="mt-5 text-2xl font-bold text-text-primary">Olá, <?= Security::e($primeiroNome) ?>!</h1>
    <p class="mt-1 text-ds-body font-medium text-text-secondary">📋 <?= Security::e($vaga['titulo']) ?></p>

    <div class="mt-5 space-y-3 text-ds-body text-text-secondary">
      <p>Recebemos sua candidatura com sucesso.</p>
      <p>Seu cadastro foi registrado e agora fará parte do processo interno de análise da vaga.</p>
      <p>Caso seu perfil seja selecionado para as próximas etapas do processo seletivo, entraremos em contato utilizando os dados informados em seu cadastro.</p>
    </div>

    <div class="mt-6 rounded-ds-md border border-border bg-surface-secondary p-4 text-left text-sm sm:flex sm:items-center sm:justify-between sm:text-center">
      <div>
        <span class="block text-xs uppercase tracking-wide text-text-muted">Protocolo</span>
        <span class="font-semibold text-text-primary"><?= Security::e($protocolo) ?></span>
      </div>
      <div class="mt-3 sm:mt-0">
        <span class="block text-xs uppercase tracking-wide text-text-muted">Data da inscrição</span>
        <span class="font-semibold text-text-primary"><?= Security::e($dataHoraCandidatura) ?></span>
      </div>
    </div>

    <div class="mt-6 rounded-ds-md border border-border p-4 text-left">
      <h2 class="text-sm font-semibold text-text-primary">Etapas do processo</h2>
      <ul class="mt-3 space-y-2 text-sm text-text-secondary">
        <li class="flex items-center gap-2"><span class="text-success">✔</span> Candidatura recebida</li>
        <li class="flex items-center gap-2 text-text-muted"><span>○</span> Triagem de currículos</li>
        <li class="flex items-center gap-2 text-text-muted"><span>○</span> Entrevista RH</li>
        <li class="flex items-center gap-2 text-text-muted"><span>○</span> Entrevista Gestor</li>
        <li class="flex items-center gap-2 text-text-muted"><span>○</span> Testes (quando aplicável)</li>
        <li class="flex items-center gap-2 text-text-muted"><span>○</span> Resultado final</li>
      </ul>
    </div>

    <p class="mt-6 text-sm text-text-muted">
      Seu currículo permanecerá em análise conforme os critérios da vaga. Caso seu perfil seja compatível com as próximas etapas do processo seletivo, nossa equipe realizará contato pelos dados informados durante a inscrição.
    </p>

    <a href="<?= $base ?>/vagas" class="mt-8 inline-flex h-11 w-full items-center justify-center rounded-ds-md bg-primary-700 px-6 text-ds-button font-semibold text-white transition hover:bg-primary-800 sm:w-auto">
      Voltar ao portal de vagas
    </a>
  </div>
</div>
