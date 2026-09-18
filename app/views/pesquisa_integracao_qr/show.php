<?php
/**
 * Pesquisa de Integração — fluxo coletivo via QR Code (/integracao). Página pública, mobile-first,
 * identidade Madeplant (#3B4822). Etapas: identificacao (CPF + nascimento) -> confirmacao (Nome +
 * Cargo + Empresa) -> pesquisa (instrumento atual, inalterado) -> obrigado.
 * Nunca ecoa CPF/nascimento de volta na página.
 */
$estado = $estado ?? 'indisponivel';
$error = $error ?? '';
$criterios = PesquisaIntegracaoQrService::criteriosSatisfacao();
$cardClasses = 'rounded-2xl border border-[#E2DFD0] bg-white p-5 shadow-sm sm:p-6';
$inputClasses = 'mt-1 w-full rounded-lg border border-[#E2DFD0] px-3 py-3 text-base text-[#2B2E22] outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]';
$botaoPrimario = 'w-full rounded-xl bg-[#3B4822] px-4 py-3.5 text-base font-semibold text-white hover:bg-[#2E3919] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3B4822] focus-visible:ring-offset-2';
$opcaoClasses = 'flex flex-col items-center gap-1 rounded-lg border border-[#E2DFD0] py-3 text-sm font-medium text-[#2B2E22] has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:font-bold has-[:checked]:ring-2 has-[:checked]:ring-[#3B4822] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#3B4822]';
?>
<script src="<?= $base ?>/assets/integracao-qr.js?v=<?= urlencode(Config::assetVersion('assets/integracao-qr.js')) ?>" defer></script>
<div class="mx-auto max-w-lg px-4 py-6">

  <?php if ($estado === 'indisponivel'): ?>
    <div class="<?= $cardClasses ?> text-center">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Pesquisa indisponível</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Não existe pesquisa de integração disponível neste momento. Se você acabou de participar de uma integração, procure o RH.</p>
    </div>

  <?php elseif ($estado === 'obrigado'): ?>
    <div class="<?= $cardClasses ?> text-center">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Obrigado pela sua avaliação!</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Sua resposta foi registrada com sucesso. Sua opinião nos ajuda a melhorar o processo de integração de novos colaboradores.</p>
    </div>

  <?php elseif ($estado === 'duplicada'): ?>
    <div class="<?= $cardClasses ?> text-center">
      <h1 class="text-lg font-semibold text-[#2B2E22]">Resposta já registrada</h1>
      <p class="mt-2 text-sm text-[#5B5F4E]">Já recebemos uma resposta para este vínculo nesta integração. Obrigado pela participação!</p>
    </div>

  <?php elseif ($estado === 'identificacao'): ?>
    <div class="<?= $cardClasses ?>">
      <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
        <h1 class="text-base font-bold">Pesquisa de Integração</h1>
        <p class="mt-1 text-xs text-[#E4E9D6]">Passo 1 de 3 — identifique-se para responder.</p>
      </div>
      <?php if ($error !== ''): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert"><?= Security::e($error) ?></div>
      <?php endif; ?>
      <form action="<?= $base ?>/integracao/identificar" method="post" class="mt-5 space-y-4" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
        <div>
          <label for="campo-cpf" class="block text-sm font-medium text-[#2B2E22]">CPF <span class="text-red-600" aria-hidden="true">*</span></label>
          <input id="campo-cpf" type="text" name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" required data-cpf-mask="1" placeholder="000.000.000-00" class="<?= $inputClasses ?>">
        </div>
        <div>
          <label for="campo-nascimento" class="block text-sm font-medium text-[#2B2E22]">Data de nascimento <span class="text-red-600" aria-hidden="true">*</span></label>
          <input id="campo-nascimento" type="date" name="nascimento" autocomplete="off" required class="<?= $inputClasses ?>">
        </div>
        <p class="text-xs text-[#5B5F4E]">Usamos estes dados somente para localizar seu vínculo ativo na empresa. Eles não ficam gravados na pesquisa.</p>
        <button type="submit" class="<?= $botaoPrimario ?>">Continuar</button>
      </form>
    </div>

  <?php elseif ($estado === 'confirmacao'): ?>
    <?php $varios = count($contratos) > 1; ?>
    <div class="<?= $cardClasses ?>">
      <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
        <h1 class="text-base font-bold">Confirme seus dados</h1>
        <p class="mt-1 text-xs text-[#E4E9D6]">Passo 2 de 3</p>
      </div>
      <?php if ($error !== ''): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert"><?= Security::e($error) ?></div>
      <?php endif; ?>
      <form action="<?= $base ?>/integracao/confirmar" method="post" class="mt-5 space-y-4">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
        <div>
          <span class="block text-sm font-medium text-[#2B2E22]">Nome</span>
          <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-3 text-base font-semibold text-[#2B2E22]"><?= Security::e((string)$nome) ?></p>
        </div>
        <?php if (!$varios): ?>
          <div>
            <span class="block text-sm font-medium text-[#2B2E22]">Cargo</span>
            <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-3 text-sm text-[#5B5F4E]"><?= Security::e((string)($contratos[0]['cargo'] ?? '—')) ?></p>
          </div>
          <div>
            <span class="block text-sm font-medium text-[#2B2E22]">Empresa</span>
            <p class="mt-1 rounded-lg bg-[#F7F6F1] px-3 py-3 text-sm text-[#5B5F4E]"><?= Security::e((string)($contratos[0]['empresa'] ?? '—')) ?></p>
          </div>
        <?php else: ?>
          <fieldset>
            <legend class="text-sm font-medium text-[#2B2E22]">Você possui mais de um vínculo ativo. Selecione o correspondente a esta integração:</legend>
            <div class="mt-2 space-y-2">
              <?php foreach ($contratos as $contrato): ?>
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[#E2DFD0] p-3 has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:ring-2 has-[:checked]:ring-[#3B4822]">
                  <input type="radio" name="contrato_id" value="<?= (int)$contrato['id'] ?>" required class="mt-1 h-4 w-4">
                  <span class="text-sm text-[#2B2E22]">
                    <span class="block font-semibold"><?= Security::e((string)($contrato['empresa'] ?? '—')) ?></span>
                    <span class="block text-[#5B5F4E]"><?= Security::e((string)($contrato['cargo'] ?? '—')) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endif; ?>
        <button type="submit" class="<?= $botaoPrimario ?>">Confirmar e continuar</button>
        <a href="<?= $base ?>/integracao" class="block w-full rounded-xl border border-[#E2DFD0] px-4 py-3 text-center text-sm font-medium text-[#3B4822] hover:bg-[#F2F4EC]">Não sou eu / Corrigir CPF</a>
      </form>
    </div>

  <?php elseif ($estado === 'pesquisa'): ?>
    <div class="<?= $cardClasses ?>">
      <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
        <h1 class="text-base font-bold">Como foi sua experiência de integração?</h1>
        <p class="mt-1 text-xs text-[#E4E9D6]">Passo 3 de 3 — <?= Security::e((string)$contrato['nome']) ?> · <?= Security::e((string)($contrato['cargo'] ?? '')) ?> · <?= Security::e((string)($contrato['empresa'] ?? '')) ?></p>
      </div>
      <?php if ($error !== ''): ?>
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert"><?= Security::e($error) ?></div>
      <?php endif; ?>
      <form action="<?= $base ?>/integracao/responder" method="post" class="mt-5 space-y-6">
        <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

        <div>
          <span class="block text-sm font-medium text-[#2B2E22]">De 0 a 10, o quanto você recomendaria a experiência de integração da empresa para um novo colaborador? <span class="text-red-600" aria-hidden="true">*</span></span>
          <div class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-11" role="radiogroup" aria-label="Nota de recomendação, de 0 a 10" aria-required="true">
            <?php for ($n = 0; $n <= 10; $n++): ?>
              <label class="<?= $opcaoClasses ?>">
                <input type="radio" name="nota_nps" value="<?= $n ?>" required class="sr-only">
                <span><?= $n ?></span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="mt-1.5 flex justify-between text-xs text-[#5B5F4E]">
            <span>Não recomendaria</span>
            <span>Recomendaria muito</span>
          </div>
        </div>

        <?php foreach ($criterios as $campo => $rotulo): ?>
          <div>
            <span class="block text-sm font-medium text-[#2B2E22]"><?= Security::e($rotulo) ?> <span class="text-red-600" aria-hidden="true">*</span></span>
            <div class="mt-2 grid grid-cols-5 gap-2" role="radiogroup" aria-label="<?= Security::e($rotulo) ?>" aria-required="true">
              <?php for ($n = 1; $n <= 5; $n++): ?>
                <label class="<?= $opcaoClasses ?>">
                  <input type="radio" name="<?= Security::e($campo) ?>" value="<?= $n ?>" required class="sr-only">
                  <span>⭐ <?= $n ?></span>
                </label>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <p class="text-xs text-[#5B5F4E]">1 = Muito insatisfeito · 2 = Insatisfeito · 3 = Neutro · 4 = Satisfeito · 5 = Muito satisfeito</p>

        <div>
          <label for="campo-comentarios" class="block text-sm font-medium text-[#2B2E22]">Quer deixar algum comentário ou sugestão sobre sua integração? <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
          <textarea id="campo-comentarios" name="comentarios" rows="4" maxlength="2000" class="<?= $inputClasses ?>"></textarea>
        </div>

        <p class="text-xs text-[#5B5F4E]"><span class="text-red-600">*</span> Campo obrigatório</p>
        <button type="submit" class="<?= $botaoPrimario ?>">Enviar avaliação</button>
      </form>
    </div>
  <?php endif; ?>
</div>
