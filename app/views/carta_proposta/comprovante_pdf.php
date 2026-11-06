<?php
/**
 * Template HTML -> PDF (dompdf) da VIA com comprovante de aceite — documento SEPARADO da Carta
 * Proposta original (CartaPropostaPdfService::gerarComprovante()), gerado só depois de um aceite
 * já confirmado no banco. Reafirma as condições aceitas e junta a evidência do aceite ELETRÔNICO
 * (nunca chamado de "assinatura digital certificada" — o sistema não implementa certificação
 * digital, só registro de aceite eletrônico com IP/user agent/data e hora, preservados no banco).
 */
$fmtData = static function (?string $d): string {
    return ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
};
$fmtDataHora = static function (?string $d): string {
    return ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y \à\s H:i', strtotime($d)) : '—';
};
$fmtMoeda = static function ($v): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
};
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #2B2E22; margin: 0; }
  .cabecalho { background: #3B4822; color: #fff; padding: 18px 28px; }
  .cabecalho .marca { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
  .titulo { font-size: 18px; font-weight: bold; margin-top: 10px; }
  .conteudo { padding: 24px 28px; }
  table.dados { width: 100%; border-collapse: collapse; margin-top: 10px; }
  table.dados td { padding: 6px 4px; border-bottom: 1px solid #E2DFD0; vertical-align: top; }
  table.dados td.rotulo { width: 38%; color: #5B5F4E; font-weight: bold; }
  .secao { margin-top: 18px; font-size: 13px; font-weight: bold; color: #3B4822; border-bottom: 2px solid #3B4822; padding-bottom: 4px; }
  .texto-livre { white-space: pre-wrap; margin-top: 6px; }
  .evidencia { margin-top: 16px; background: #F7F6F1; border: 1px solid #E2DFD0; border-radius: 6px; padding: 14px 16px; }
  .rodape { margin-top: 30px; font-size: 10px; color: #5B5F4E; }
</style>
</head>
<body>
  <div class="cabecalho">
    <div class="marca">MADEPLANT</div>
    <div class="titulo">Via com Comprovante de Aceite Eletrônico</div>
  </div>
  <div class="conteudo">
    <p>Este documento comprova o aceite eletrônico, pelo(a) candidato(a) <strong><?= Security::e((string)($nome_candidato ?? '')) ?></strong>,
       das condições apresentadas na Carta Proposta — versão <?= (int)($versao ?? 0) ?>.</p>

    <div class="evidencia">
      <strong>Evidência do Aceite Eletrônico</strong>
      <table class="dados" style="margin-top: 8px;">
        <tr><td class="rotulo">Identificador da decisão</td><td><?= Security::e((string)($comprovante_identificador ?? '')) ?></td></tr>
        <tr><td class="rotulo">Data e horário do aceite</td><td><?= $fmtDataHora($decisao_em ?? null) ?></td></tr>
        <tr><td class="rotulo">Versão da proposta aceita</td><td><?= (int)($versao ?? 0) ?></td></tr>
      </table>
      <p style="margin-top: 8px;">O(a) candidato(a) declarou, por meio eletrônico, estar de acordo com as condições
         apresentadas abaixo, registrando seu aceite através de link individual e seguro enviado pela equipe de RH.
         Evidências técnicas complementares (endereço IP e navegador de origem) ficam preservadas no sistema do
         Portal RH Madeplant para fins de auditoria interna.</p>
    </div>

    <div class="secao">Condições Aceitas</div>
    <table class="dados">
      <tr><td class="rotulo">Empresa contratante</td><td><?= Security::e((string)($empresa_contratante ?? '')) ?></td></tr>
      <tr><td class="rotulo">Cargo</td><td><?= Security::e((string)($cargo ?? '')) ?></td></tr>
      <?php if (!empty($setor)): ?><tr><td class="rotulo">Setor</td><td><?= Security::e((string)$setor) ?></td></tr><?php endif; ?>
      <?php if (!empty($local_trabalho)): ?><tr><td class="rotulo">Local de trabalho</td><td><?= Security::e((string)$local_trabalho) ?></td></tr><?php endif; ?>
      <tr><td class="rotulo">Salário proposto</td><td><?= $fmtMoeda($salario_proposto ?? 0) ?></td></tr>
      <tr><td class="rotulo">Jornada de trabalho</td><td><?= Security::e((string)($jornada_trabalho ?? '')) ?></td></tr>
      <?php if (!empty($escala_horario)): ?><tr><td class="rotulo">Escala/Horário</td><td><?= Security::e((string)$escala_horario) ?></td></tr><?php endif; ?>
      <?php if (!empty($previsao_inicio)): ?><tr><td class="rotulo">Previsão de início</td><td><?= $fmtData($previsao_inicio) ?></td></tr><?php endif; ?>
    </table>

    <?php if (!empty($beneficios_texto)): ?>
      <div class="secao">Benefícios</div>
      <div class="texto-livre"><?= nl2br(Security::e((string)$beneficios_texto)) ?></div>
    <?php endif; ?>

    <div class="rodape">
      Documento gerado eletronicamente pelo Portal RH Madeplant a partir do registro de aceite da Carta Proposta
      — versão <?= (int)($versao ?? 0) ?>. Este documento não substitui a Carta Proposta original emitida pelo RH,
      que permanece preservada e disponível no perfil da candidatura.
    </div>
  </div>
</body>
</html>
