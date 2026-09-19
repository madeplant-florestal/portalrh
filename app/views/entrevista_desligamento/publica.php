<?php
/**
 * Entrevista de Desligamento — página pública, mobile-first, sem JavaScript e sem recursos externos.
 * Mostra só o contexto mínimo do SNAPSHOT (primeiro nome, cargo, datas, tempo de empresa): nunca CPF,
 * nascimento, salário, códigos internos nem o nome completo.
 */
$estado = $estado ?? 'invalido';
$contexto = $contexto ?? null;
$valores = $valores ?? [];
$erros = $erros ?? [];
$token = $token ?? '';

$mensagens = [
    'invalido' => ['Link inválido', 'Não encontramos esta entrevista. Verifique se o link foi copiado por completo ou solicite um novo ao RH da Madeplant.'],
    'indisponivel' => ['Link indisponível', 'Este link não está mais disponível. Se você ainda deseja participar, solicite um novo link ao RH da Madeplant.'],
    'concluida' => ['Entrevista concluída', 'Obrigado pela sua participação! Suas respostas foram registradas e nos ajudarão a melhorar. Desejamos muito sucesso na sua trajetória.'],
    'bloqueado' => ['Muitas tentativas', 'Recebemos muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.'],
    'sessao_expirada' => ['Sessão expirada', 'Por segurança, sua sessão expirou antes do envio. Abra o link novamente para preencher a entrevista.'],
];

$valor = static fn(string $campo): string => (string)($valores[$campo] ?? '');
$marcado = static fn(string $campo, string $opcao): bool => (string)($valores[$campo] ?? '') === $opcao;
$fatoresMarcados = is_array($valores['fatores'] ?? null) ? $valores['fatores'] : [];
$formatarData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '';

$cartaoBotao = 'flex flex-col items-center gap-1 rounded-lg border border-[#E2DFD0] bg-white py-3 text-sm font-medium text-[#2B2E22] has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:font-bold has-[:checked]:ring-2 has-[:checked]:ring-[#3B4822] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#3B4822]';
$cartaoLista = 'flex items-center gap-3 rounded-lg border border-[#E2DFD0] bg-white px-3 py-3 text-sm text-[#2B2E22] has-[:checked]:border-[#3B4822] has-[:checked]:bg-[#F2F4EC] has-[:checked]:font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#3B4822]';
$campoTexto = 'mt-1 w-full rounded-lg border border-[#E2DFD0] bg-white px-3 py-2.5 text-sm text-[#2B2E22] outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]';
$tituloSecao = 'text-sm font-bold uppercase tracking-wide text-[#5B5F4E]';
?>
<div class="mx-auto max-w-lg px-4 py-6">

<?php if (isset($mensagens[$estado])): ?>
  <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
    <h1 class="text-lg font-semibold text-[#2B2E22]"><?= Security::e($mensagens[$estado][0]) ?></h1>
    <p class="mt-2 text-sm text-[#5B5F4E]"><?= Security::e($mensagens[$estado][1]) ?></p>
  </div>

<?php else: /* formulario */ ?>
  <div class="rounded-2xl border border-[#E2DFD0] bg-white p-5 shadow-sm sm:p-6">
    <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
      <h1 class="text-base font-bold">Entrevista de Desligamento</h1>
      <p class="mt-1 text-xs text-[#E4E9D6]">Olá, <?= Security::e((string)($contexto['primeiro_nome'] ?? '')) ?>. Sua opinião sincera nos ajuda a construir uma Madeplant melhor. Suas respostas são identificadas e ficam disponíveis apenas para pessoas autorizadas do RH.</p>
    </div>

    <?php if ($erros !== []): ?>
      <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        <p class="font-semibold">Confira os pontos abaixo antes de enviar:</p>
        <ul class="mt-1 list-disc pl-5">
          <?php foreach ($erros as $erro): ?><li><?= Security::e((string)$erro) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form action="<?= Security::e((string)($base ?? '')) ?>/entrevista-desligamento/<?= Security::e($token) ?>" method="post" class="mt-5 space-y-8">
      <input type="hidden" name="csrf" value="<?= Security::e((string)($csrf ?? '')) ?>">

      <section>
        <h2 class="<?= $tituloSecao ?>">Seu vínculo com a empresa</h2>
        <p class="mt-1 text-xs text-[#5B5F4E]">Informações do nosso cadastro, apenas para conferência.</p>
        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
          <?php if (!empty($contexto['cargo'])): ?>
            <div class="col-span-2 rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Cargo</dt><dd class="font-medium"><?= Security::e((string)$contexto['cargo']) ?></dd></div>
          <?php endif; ?>
          <?php if ($formatarData($contexto['admissao'] ?? null) !== ''): ?>
            <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Admissão</dt><dd class="font-medium"><?= Security::e($formatarData($contexto['admissao'])) ?></dd></div>
          <?php endif; ?>
          <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Desligamento</dt><dd class="font-medium"><?= Security::e($formatarData($contexto['demissao'] ?? null)) ?></dd></div>
          <?php if (!empty($contexto['tempo_empresa'])): ?>
            <div class="col-span-2 rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Tempo de empresa</dt><dd class="font-medium"><?= Security::e((string)$contexto['tempo_empresa']) ?></dd></div>
          <?php endif; ?>
        </dl>
      </section>

      <section>
        <h2 class="<?= $tituloSecao ?>">Motivo principal do desligamento</h2>
        <p class="mt-2 text-sm font-medium">Na sua percepção, qual foi o principal motivo? <span class="text-red-600" aria-hidden="true">*</span></p>
        <div class="mt-3 grid gap-2" role="radiogroup" aria-label="Motivo principal do desligamento" aria-required="true">
          <?php foreach (EntrevistaDesligamentoService::MOTIVOS_DECLARADOS as $codigo => $rotulo): ?>
            <label class="<?= $cartaoLista ?>">
              <input type="radio" name="motivo_principal" value="<?= Security::e($codigo) ?>" required class="h-4 w-4 accent-[#3B4822]" <?= $marcado('motivo_principal', $codigo) ? 'checked' : '' ?>>
              <span><?= Security::e($rotulo) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <label for="motivo_descricao" class="mt-4 block text-sm font-medium">Descreva brevemente <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
        <textarea id="motivo_descricao" name="motivo_descricao" rows="3" maxlength="<?= EntrevistaDesligamentoService::LIMITE_TEXTO ?>" class="<?= $campoTexto ?>"><?= Security::e($valor('motivo_descricao')) ?></textarea>
      </section>

      <section>
        <h2 class="<?= $tituloSecao ?>">Fatores contribuintes</h2>
        <p class="mt-2 text-sm font-medium">Além do motivo principal, o que mais contribuiu? <span class="font-normal text-[#5B5F4E]">(marque quantos quiser)</span></p>
        <div class="mt-3 grid gap-2">
          <?php foreach (EntrevistaDesligamentoService::FATORES_CONTRIBUINTES as $codigo => $rotulo): ?>
            <label class="<?= $cartaoLista ?>">
              <input type="checkbox" name="fatores[]" value="<?= Security::e($codigo) ?>" class="h-4 w-4 accent-[#3B4822]" <?= in_array($codigo, $fatoresMarcados, true) ? 'checked' : '' ?>>
              <span><?= Security::e($rotulo) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <?php foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $chaveSecao => $secao): ?>
        <section>
          <h2 class="<?= $tituloSecao ?>"><?= Security::e($secao['titulo']) ?></h2>
          <?php if ($chaveSecao === 'cultura'): ?>
            <p class="mt-2 text-sm font-medium"><?= Security::e(EntrevistaDesligamentoService::PERGUNTA_CULTURA_PRATICA) ?></p>
          <?php endif; ?>
          <?php if ($secao['escala'] === 'satisfacao'): ?>
            <p class="mt-1 text-xs text-[#5B5F4E]">1 = Muito insatisfeito · 2 = Insatisfeito · 3 = Neutro · 4 = Satisfeito · 5 = Muito satisfeito</p>
          <?php else: ?>
            <p class="mt-1 text-xs text-[#5B5F4E]"><?= Security::e(EntrevistaDesligamentoService::LEGENDA_ESCALA_NEUTRA) ?></p>
          <?php endif; ?>
          <div class="mt-3 space-y-5">
            <?php foreach ($secao['itens'] as $campo => $enunciado): ?>
              <div>
                <p class="text-sm font-medium"><?= Security::e($enunciado) ?> <span class="text-red-600" aria-hidden="true">*</span></p>
                <div class="mt-2 grid grid-cols-5 gap-2" role="radiogroup" aria-label="<?= Security::e($enunciado) ?>" aria-required="true">
                  <?php for ($n = 1; $n <= 5; $n++): ?>
                    <label class="<?= $cartaoBotao ?>">
                      <input type="radio" name="<?= Security::e($campo) ?>" value="<?= $n ?>" required class="sr-only" <?= $marcado($campo, (string)$n) ? 'checked' : '' ?>>
                      <span><?= $n ?></span>
                    </label>
                  <?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php
      $escalas0a10 = [
          'experiencia_geral' => ['Experiência geral', EntrevistaDesligamentoService::PERGUNTA_EXPERIENCIA_GERAL, 'Muito ruim', 'Excelente'],
          'enps' => ['Recomendação', EntrevistaDesligamentoService::PERGUNTA_ENPS, 'Não recomendaria', 'Recomendaria muito'],
      ];
      foreach ($escalas0a10 as $campo => [$titulo, $pergunta, $pontaMin, $pontaMax]): ?>
        <section>
          <h2 class="<?= $tituloSecao ?>"><?= Security::e($titulo) ?></h2>
          <p class="mt-2 text-sm font-medium"><?= Security::e($pergunta) ?> <span class="text-red-600" aria-hidden="true">*</span></p>
          <div class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-11" role="radiogroup" aria-label="<?= Security::e($pergunta) ?>" aria-required="true">
            <?php for ($n = 0; $n <= 10; $n++): ?>
              <label class="<?= $cartaoBotao ?>">
                <input type="radio" name="<?= Security::e($campo) ?>" value="<?= $n ?>" required class="sr-only" <?= $marcado($campo, (string)$n) ? 'checked' : '' ?>>
                <span><?= $n ?></span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="mt-1.5 flex justify-between text-xs text-[#5B5F4E]"><span><?= Security::e($pontaMin) ?></span><span><?= Security::e($pontaMax) ?></span></div>
        </section>
      <?php endforeach; ?>

      <section>
        <h2 class="<?= $tituloSecao ?>">Para finalizar</h2>
        <div class="mt-3 space-y-4">
          <?php foreach (EntrevistaDesligamentoService::PERGUNTAS_ABERTAS as $campo => $pergunta): ?>
            <div>
              <label for="<?= Security::e($campo) ?>" class="block text-sm font-medium"><?= Security::e($pergunta) ?> <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
              <textarea id="<?= Security::e($campo) ?>" name="<?= Security::e($campo) ?>" rows="3" maxlength="<?= EntrevistaDesligamentoService::LIMITE_TEXTO ?>" class="<?= $campoTexto ?>"><?= Security::e($valor($campo)) ?></textarea>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <p class="text-xs text-[#5B5F4E]"><span class="text-red-600">*</span> Campo obrigatório</p>

      <button type="submit" class="w-full rounded-xl bg-[#3B4822] px-4 py-3.5 text-base font-semibold text-white hover:bg-[#2E3919] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3B4822] focus-visible:ring-offset-2">Enviar entrevista</button>
    </form>
  </div>
<?php endif; ?>

</div>
