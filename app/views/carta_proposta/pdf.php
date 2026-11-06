<?php
/**
 * Template HTML -> PDF (dompdf) da Carta Proposta. Variáveis vêm de CartaPropostaPdfService::
 * renderizarHtml() via extract() do snapshot já congelado da proposta — nunca lê a vaga/candidatura
 * de novo (o PDF preserva exatamente as condições do momento da emissão desta versão).
 */
$fmtData = static function (?string $d): string {
    return ($d !== null && $d !== '' && strtotime($d) !== false) ? date('d/m/Y', strtotime($d)) : '—';
};
$fmtMoeda = static function ($v): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
};
// Sem imagem embutida de propósito: dompdf só embute PNG via GD, extensão PHP que não é garantida
// em todo ambiente de produção — cabeçalho em texto é mais simples e robusto (CLAUDE.md §2).
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
  .rodape { margin-top: 30px; font-size: 10px; color: #5B5F4E; }
</style>
</head>
<body>
  <div class="cabecalho">
    <div class="marca">MADEPLANT</div>
    <div class="titulo">Carta Proposta</div>
  </div>
  <div class="conteudo">
    <p>Prezado(a) <strong><?= Security::e((string)($nome_candidato ?? '')) ?></strong>,</p>
    <p>Temos a satisfação de apresentar a proposta de contratação com as seguintes condições:</p>

    <div class="secao">Dados da Oportunidade</div>
    <table class="dados">
      <tr><td class="rotulo">Empresa contratante</td><td><?= Security::e((string)($empresa_contratante ?? '')) ?></td></tr>
      <tr><td class="rotulo">Cargo</td><td><?= Security::e((string)($cargo ?? '')) ?></td></tr>
      <?php if (!empty($setor)): ?><tr><td class="rotulo">Setor</td><td><?= Security::e((string)$setor) ?></td></tr><?php endif; ?>
      <?php if (!empty($local_trabalho)): ?><tr><td class="rotulo">Local de trabalho</td><td><?= Security::e((string)$local_trabalho) ?></td></tr><?php endif; ?>
    </table>

    <div class="secao">Condições de Trabalho</div>
    <table class="dados">
      <tr><td class="rotulo">Salário proposto</td><td><?= $fmtMoeda($salario_proposto ?? 0) ?></td></tr>
      <tr><td class="rotulo">Jornada de trabalho</td><td><?= Security::e((string)($jornada_trabalho ?? '')) ?></td></tr>
      <?php if (!empty($escala_horario)): ?><tr><td class="rotulo">Escala/Horário</td><td><?= Security::e((string)$escala_horario) ?></td></tr><?php endif; ?>
      <?php if (!empty($previsao_inicio)): ?><tr><td class="rotulo">Previsão de início</td><td><?= $fmtData($previsao_inicio) ?></td></tr><?php endif; ?>
      <tr><td class="rotulo">Validade desta proposta</td><td><?= $fmtData($validade_proposta ?? null) ?></td></tr>
    </table>

    <?php if (!empty($beneficios_texto)): ?>
      <div class="secao">Benefícios</div>
      <div class="texto-livre"><?= nl2br(Security::e((string)$beneficios_texto)) ?></div>
    <?php endif; ?>

    <?php if (!empty($observacoes)): ?>
      <div class="secao">Observações</div>
      <div class="texto-livre"><?= nl2br(Security::e((string)$observacoes)) ?></div>
    <?php endif; ?>

    <p style="margin-top: 24px;">Ficamos à disposição para esclarecer qualquer dúvida sobre esta proposta.</p>
    <p>Atenciosamente,<br>Equipe de RH — Madeplant</p>

    <div class="rodape">Documento gerado eletronicamente pelo Portal RH Madeplant. O aceite ou a recusa desta proposta é registrado por meio de link individual enviado ao candidato.</div>
  </div>
</body>
</html>
