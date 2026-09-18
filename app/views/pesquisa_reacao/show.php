<?php
/**
 * Pesquisa de Reação — Treinamento de Integração. Página pública, mobile-first, identidade
 * Madeplant (verde institucional #3B4822). Instrumento DIFERENTE de pesquisa_integracao/show.php —
 * não reaproveita suas perguntas.
 */
$estado = $estado ?? 'invalido';
$campanha = $campanha ?? null;

$afirmacoes = PesquisaReacaoIntegracaoService::rotulosAvaliacoes();
$perguntasAbertas = [
    'mais_gostou' => 'O que você mais gostou na integração?',
    'poderia_melhorar' => 'O que poderia ser melhorado?',
    'informacao_faltante' => 'Existe alguma informação que você sentiu falta durante a integração?',
];
?>
<div class="mx-auto max-w-lg px-4 py-6">

  <?php if ($estado === 'invalido'): ?>
    <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Link inválido</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Não encontramos esta pesquisa. Verifique se o link foi copiado corretamente.</p>
    </div>

  <?php elseif ($estado === 'encerrada'): ?>
    <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Pesquisa encerrada</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Esta pesquisa foi encerrada e não está mais disponível para respostas.</p>
    </div>

  <?php elseif ($estado === 'obrigado'): ?>
    <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Obrigado pela sua participação!</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Sua resposta foi registrada com sucesso e contribuirá para a melhoria contínua do nosso processo de integração.</p>
    </div>

  <?php else: /* formulario */ ?>
    <div class="rounded-2xl border border-[#E2DFD0] bg-white p-5 shadow-sm sm:p-6">
      <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
        <h1 class="text-base font-bold">Pesquisa de Reação - Treinamento de Integração</h1>
        <p class="mt-1 text-xs text-[#E4E9D6]">Avaliar a percepção dos colaboradores sobre o processo de integração, identificando oportunidades de melhoria e acompanhando a experiência dos novos profissionais.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert"><?= Security::e($error) ?></div>
      <?php endif; ?>

      <form action="<?= $base ?>/pesquisa-reacao/<?= Security::e($token) ?>" method="post" class="mt-5 space-y-8">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

        <!-- Identificação -->
        <section>
          <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Identificação</h2>
          <div class="mt-3 space-y-3">
            <div>
              <label for="campo-nome" class="block text-sm font-medium text-[#2B2E22]">Nome <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
              <input id="campo-nome" type="text" name="nome" maxlength="120" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2.5 text-sm text-[#2B2E22] outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]" placeholder="Se preferir, participe anonimamente">
            </div>
            <?php if (!empty($campanha['empresa_nome_snapshot']) || !empty($campanha['codigo_empresa'])): ?>
              <div>
                <span class="block text-sm font-medium text-[#2B2E22]">Empresa</span>
                <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-2.5 text-sm text-[#5B5F4E]"><?= Security::e((string)($campanha['empresa_nome_snapshot'] ?? $campanha['codigo_empresa'])) ?></p>
              </div>
            <?php endif; ?>
            <?php if (!empty($campanha['setor_nome_snapshot']) || !empty($campanha['codigo_setor'])): ?>
              <div>
                <span class="block text-sm font-medium text-[#2B2E22]">Área</span>
                <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-2.5 text-sm text-[#5B5F4E]"><?= Security::e((string)($campanha['setor_nome_snapshot'] ?? $campanha['codigo_setor'])) ?></p>
              </div>
            <?php endif; ?>
            <?php if (!empty($campanha['data_integracao'])): ?>
              <div>
                <span class="block text-sm font-medium text-[#2B2E22]">Data da Integração</span>
                <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-2.5 text-sm text-[#5B5F4E]"><?= Security::e(date('d/m/Y', strtotime((string)$campanha['data_integracao']))) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Pergunta NPS -->
        <section>
          <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Pergunta NPS</h2>
          <p class="mt-2 text-sm font-medium text-[#2B2E22]">Em uma escala de 0 a 10, o quanto você recomendaria o Treinamento de Integração da Madeplant para um novo colaborador? <span class="text-red-600" aria-hidden="true">*</span></p>
          <div class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-11" role="radiogroup" aria-label="Nota de recomendação, de 0 a 10" aria-required="true">
            <?php for ($n = 0; $n <= 10; $n++): ?>
              <label class="flex flex-col items-center gap-1 rounded-lg border border-[#E2DFD0] py-3 text-sm font-medium text-[#2B2E22] has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:font-bold has-[:checked]:ring-2 has-[:checked]:ring-[#3B4822] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#3B4822]">
                <input type="radio" name="nota_nps" value="<?= $n ?>" required class="sr-only">
                <span><?= $n ?></span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="mt-1.5 flex justify-between text-xs text-[#5B5F4E]">
            <span>Não recomendaria</span>
            <span>Recomendaria muito</span>
          </div>
        </section>

        <!-- Avaliação da Integração -->
        <section>
          <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Avaliação da Integração</h2>
          <p class="mt-1 text-xs text-[#5B5F4E]">1 = Discordo totalmente · 2 = Discordo parcialmente · 3 = Nem concordo nem discordo · 4 = Concordo · 5 = Concordo totalmente</p>
          <div class="mt-3 space-y-5">
            <?php foreach ($afirmacoes as $campo => $texto): ?>
              <div>
                <p class="text-sm font-medium text-[#2B2E22]"><?= Security::e($texto) ?> <span class="text-red-600" aria-hidden="true">*</span></p>
                <div class="mt-2 grid grid-cols-5 gap-2" role="radiogroup" aria-label="<?= Security::e($texto) ?>" aria-required="true">
                  <?php for ($n = 1; $n <= 5; $n++): ?>
                    <label class="flex flex-col items-center gap-1 rounded-lg border border-[#E2DFD0] py-3 text-sm font-medium text-[#2B2E22] has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:font-bold has-[:checked]:ring-2 has-[:checked]:ring-[#3B4822]">
                      <input type="radio" name="<?= Security::e($campo) ?>" value="<?= $n ?>" required class="sr-only">
                      <span><?= $n ?></span>
                    </label>
                  <?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- Perguntas Abertas -->
        <section>
          <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Perguntas Abertas</h2>
          <div class="mt-3 space-y-4">
            <?php foreach ($perguntasAbertas as $campo => $texto): ?>
              <div>
                <label for="campo-<?= Security::e($campo) ?>" class="block text-sm font-medium text-[#2B2E22]"><?= Security::e($texto) ?> <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
                <textarea id="campo-<?= Security::e($campo) ?>" name="<?= Security::e($campo) ?>" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-2.5 text-sm text-[#2B2E22] outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]"></textarea>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <p class="text-xs text-[#5B5F4E]"><span class="text-red-600">*</span> Campo obrigatório</p>

        <button type="submit" class="w-full rounded-xl bg-[#3B4822] px-4 py-3.5 text-base font-semibold text-white hover:bg-[#2E3919] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3B4822] focus-visible:ring-offset-2">Enviar pesquisa</button>
      </form>
    </div>
  <?php endif; ?>
</div>
