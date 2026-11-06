<?php
/**
 * Carta Proposta — página pública, mobile-first, sem JavaScript e sem recursos externos (mesma
 * disciplina de entrevista_desligamento/publica.php). Nunca exibe CPF ou qualquer identificador
 * interno — só o necessário para o candidato revisar e decidir.
 */
$estado = $estado ?? 'invalido';
$contexto = $contexto ?? null;
$token = $token ?? '';
$erro = $erro ?? null;

$mensagens = [
    'invalido' => ['Link inválido', 'Não encontramos esta Carta Proposta. Verifique se o link foi copiado por completo ou solicite um novo ao RH da Madeplant.'],
    'indisponivel' => ['Link indisponível', 'Este link não está mais disponível. Solicite um novo ao RH da Madeplant.'],
    'proposta_vencida' => ['Proposta expirada', 'Esta Carta Proposta expirou. O prazo para avaliação e aceite foi encerrado. Caso tenha interesse em continuar o processo, entre em contato com a equipe de RH.'],
    'concluida' => ['Decisão registrada', 'Sua decisão foi registrada com sucesso. Em caso de dúvidas, entre em contato com o RH da Madeplant.'],
    'concluida_aceita' => ['Decisão registrada', 'Esta Carta Proposta já foi aceita por você. Seu aceite foi registrado com sucesso.'],
    'concluida_recusada' => ['Decisão registrada', 'Esta Carta Proposta já foi recusada. Sua decisão foi registrada com sucesso.'],
    'sessao_expirada' => ['Sessão expirada', 'Por segurança, sua sessão expirou antes do envio. Abra o link novamente para revisar e decidir.'],
    'bloqueado' => ['Muitas tentativas', 'Recebemos muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.'],
];
if ($estado === 'concluida') {
    $decisaoConcluida = (string)($contexto['decisao'] ?? '');
    $estado = $decisaoConcluida === 'aceita' ? 'concluida_aceita' : ($decisaoConcluida === 'recusada' ? 'concluida_recusada' : 'concluida');
}

$fmtData = static fn(?string $d): string => ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '';
$fmtMoeda = static fn($v): string => 'R$ ' . number_format((float)$v, 2, ',', '.');
?>
<div class="mx-auto max-w-lg px-4 py-6">

<?php if ($estado !== 'formulario' && isset($mensagens[$estado])): ?>
  <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
    <h1 class="text-lg font-semibold text-[#2B2E22]"><?= Security::e($mensagens[$estado][0]) ?></h1>
    <p class="mt-2 text-sm text-[#5B5F4E]"><?= Security::e($mensagens[$estado][1]) ?></p>
  </div>

<?php elseif ($estado === 'formulario' && $contexto !== null): ?>
  <div class="rounded-2xl border border-[#E2DFD0] bg-white p-5 shadow-sm sm:p-6">
    <div class="rounded-xl bg-[#3B4822] px-4 py-3 text-white">
      <h1 class="text-base font-bold">Carta Proposta</h1>
      <p class="mt-1 text-xs text-[#E4E9D6]">Olá, <?= Security::e((string)($contexto['nome_candidato'] ?? '')) ?>. Confira as condições abaixo antes de registrar sua decisão.</p>
    </div>

    <?php if ($erro !== null): ?>
      <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"><?= Security::e($erro) ?></div>
    <?php endif; ?>

    <?php if (!empty($contexto['pdf_nome_armazenado'])): ?>
      <a href="<?= $base ?? '' ?>/carta-proposta/<?= Security::e($token) ?>/pdf" class="mt-4 inline-flex items-center rounded-lg border border-[#3B4822] px-4 py-2 text-sm font-semibold text-[#3B4822] hover:bg-[#F2F4EC]">
        Abrir PDF da proposta
      </a>
    <?php endif; ?>

    <dl class="mt-4 space-y-2 text-sm">
      <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Cargo</dt><dd class="font-medium"><?= Security::e((string)($contexto['cargo'] ?? '')) ?></dd></div>
      <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Empresa</dt><dd class="font-medium"><?= Security::e((string)($contexto['empresa_contratante'] ?? '')) ?></dd></div>
      <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Salário proposto</dt><dd class="font-medium"><?= $fmtMoeda($contexto['salario_proposto'] ?? 0) ?></dd></div>
      <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Jornada de trabalho</dt><dd class="font-medium"><?= Security::e((string)($contexto['jornada_trabalho'] ?? '')) ?></dd></div>
      <?php if (!empty($contexto['local_trabalho'])): ?><div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Local de trabalho</dt><dd class="font-medium"><?= Security::e((string)$contexto['local_trabalho']) ?></dd></div><?php endif; ?>
      <?php if (!empty($contexto['previsao_inicio'])): ?><div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Previsão de início</dt><dd class="font-medium"><?= Security::e($fmtData($contexto['previsao_inicio'])) ?></dd></div><?php endif; ?>
      <div class="rounded-lg bg-[#F7F6F1] px-3 py-2.5"><dt class="text-xs text-[#5B5F4E]">Validade desta proposta</dt><dd class="font-medium"><?= Security::e($fmtData($contexto['validade_proposta'] ?? null)) ?></dd></div>
    </dl>

    <?php if (!empty($contexto['beneficios_texto'])): ?>
      <div class="mt-4">
        <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Benefícios</h2>
        <p class="mt-1 whitespace-pre-wrap text-sm"><?= Security::e((string)$contexto['beneficios_texto']) ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($contexto['observacoes'])): ?>
      <div class="mt-4">
        <h2 class="text-sm font-bold uppercase tracking-wide text-[#5B5F4E]">Observações</h2>
        <p class="mt-1 whitespace-pre-wrap text-sm"><?= Security::e((string)$contexto['observacoes']) ?></p>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $base ?? '' ?>/carta-proposta/<?= Security::e($token) ?>" class="mt-6 space-y-4 border-t border-[#E2DFD0] pt-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">

      <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="confirmo_leitura" value="1" required class="mt-0.5">
        <span>Declaro que li e compreendi as condições apresentadas nesta Carta Proposta.</span>
      </label>

      <div>
        <label for="motivo_recusa" class="block text-sm font-medium">Caso vá recusar, descreva o motivo <span class="font-normal text-[#5B5F4E]">(opcional)</span></label>
        <textarea id="motivo_recusa" name="motivo_recusa" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border border-[#E2DFD0] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#566B41] focus:ring-2 focus:ring-[#E4E9D6]"></textarea>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row">
        <button type="submit" name="decisao" value="aceita" class="flex-1 rounded-lg bg-[#3B4822] px-4 py-3 text-sm font-bold text-white">Aceito a proposta</button>
        <button type="submit" name="decisao" value="recusada" class="flex-1 rounded-lg border border-red-300 px-4 py-3 text-sm font-bold text-red-700">Recusar proposta</button>
      </div>
    </form>
  </div>

<?php else: ?>
  <div class="rounded-2xl border border-[#E2DFD0] bg-white p-6 text-center shadow-sm">
    <h1 class="text-lg font-semibold text-[#2B2E22]">Link inválido</h1>
    <p class="mt-2 text-sm text-[#5B5F4E]">Não encontramos esta Carta Proposta.</p>
  </div>
<?php endif; ?>

</div>
