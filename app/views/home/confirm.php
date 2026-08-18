<?php
?>
<div class="max-w-lg mx-auto">
  <div class="bg-white shadow-lg rounded-xl p-6 sm:p-10 text-center">
    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-ctgreen/10">
      <svg class="h-9 w-9 text-ctgreen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
      </svg>
    </div>

    <h1 class="mt-5 text-2xl font-semibold text-ctpblue">Olá, <?= Security::e($primeiroNome) ?>!</h1>
    <p class="mt-1 text-sm font-medium text-ctlight">📋 <?= Security::e($vaga['titulo']) ?></p>

    <div class="mt-5 space-y-3 text-gray-700">
      <p>Recebemos sua candidatura com sucesso.</p>
      <p>Seu cadastro foi registrado e agora fará parte do processo interno de análise da vaga.</p>
      <p>Caso seu perfil seja selecionado para as próximas etapas do processo seletivo, entraremos em contato utilizando os dados informados em seu cadastro.</p>
    </div>

    <div class="mt-6 rounded-lg border bg-gray-50 p-4 text-sm text-left sm:flex sm:items-center sm:justify-between sm:text-center">
      <div>
        <span class="block text-xs uppercase tracking-wide text-gray-500">Protocolo</span>
        <span class="font-semibold text-ctpblue"><?= Security::e($protocolo) ?></span>
      </div>
      <div class="mt-3 sm:mt-0">
        <span class="block text-xs uppercase tracking-wide text-gray-500">Data da inscrição</span>
        <span class="font-semibold text-ctpblue"><?= Security::e($dataHoraCandidatura) ?></span>
      </div>
    </div>

    <div class="mt-6 rounded-lg border p-4 text-left">
      <h2 class="text-sm font-semibold text-ctpblue">Etapas do processo</h2>
      <ul class="mt-3 space-y-2 text-sm text-gray-700">
        <li class="flex items-center gap-2"><span class="text-ctgreen">✔</span> Candidatura recebida</li>
        <li class="flex items-center gap-2 text-gray-500"><span>○</span> Triagem de currículos</li>
        <li class="flex items-center gap-2 text-gray-500"><span>○</span> Entrevista RH</li>
        <li class="flex items-center gap-2 text-gray-500"><span>○</span> Entrevista Gestor</li>
        <li class="flex items-center gap-2 text-gray-500"><span>○</span> Testes (quando aplicável)</li>
        <li class="flex items-center gap-2 text-gray-500"><span>○</span> Resultado final</li>
      </ul>
    </div>

    <p class="mt-6 text-sm text-gray-500">
      Seu currículo permanecerá em análise conforme os critérios da vaga. Caso seu perfil seja compatível com as próximas etapas do processo seletivo, nossa equipe realizará contato pelos dados informados durante a inscrição.
    </p>

    <a href="<?= $base ?>/vagas" class="ct-btn ct-btn-primary mt-8 w-full sm:w-auto">Voltar ao portal de vagas</a>
  </div>
</div>
