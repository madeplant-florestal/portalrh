<?php
/**
 * Diagnóstico de envio de e-mail (Supervisor Geral). Mostra a configuração EFETIVA sem segredos e permite UM envio de teste por vez, com texto fixo.
 * "Aceito pelo SMTP" NÃO garante entrega na caixa de entrada — a tela diz isso.
 */
require_once APP_PATH . '/views/partials/ui-shell.php';
$inputClasses = 'rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100';
$linha = static fn(string $rotulo, string $valor): string => '<tr class="border-t border-border"><td class="py-1.5 pr-4 text-text-secondary">' . Security::e($rotulo) . '</td><td class="py-1.5 font-medium text-text-primary">' . Security::e($valor) . '</td></tr>';
?>
<div class="space-y-4">
  <h1 class="text-xl font-bold text-text-primary">Diagnóstico de envio de e-mail</h1>
  <p class="text-sm text-text-secondary">Somente Supervisor Geral. Nenhuma senha é exibida. Um teste envia UMA mensagem de texto fixo para UM destinatário.</p>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-sm font-bold text-text-primary">Configuração efetiva</h2>
    <table class="mt-2 text-sm"><tbody>
      <?= $linha('Envio habilitado', $config['enabled'] ? 'sim' : 'NÃO (mail.enabled = false)') ?>
      <?= $linha('Driver', (string)$config['driver']) ?>
      <?= $linha('Remetente', (string)$config['from'] . ' (' . (string)$config['from_name'] . ')') ?>
      <?= $linha('Servidor SMTP', (string)$config['smtp_host'] . ':' . (int)$config['smtp_port'] . ' — ' . ((string)$config['smtp_secure'] === 'ssl' ? 'TLS implícito' : 'STARTTLS')) ?>
      <?= $linha('Usuário SMTP', $config['usuario_mascarado']) ?>
      <?= $linha('Senha SMTP configurada', $config['senha_configurada'] ? 'sim' : 'NÃO') ?>
    </tbody></table>
  </section>

  <?php if (!empty($resultado)): ?>
    <div class="rounded-lg border px-4 py-3 text-sm <?= $resultado['ok'] ? 'border-success/30 bg-success/10 text-success' : 'border-danger/30 bg-danger/10 text-danger' ?>" role="status">
      <p class="font-semibold"><?= $resultado['ok'] ? 'Aceito pelo servidor SMTP' : 'Falha: ' . Security::e((string)$resultado['etapa']) ?></p>
      <p class="mt-1"><?= Security::e((string)$resultado['mensagem']) ?><?= !empty($resultado['codigo_smtp']) ? ' (código SMTP ' . (int)$resultado['codigo_smtp'] . ')' : '' ?></p>
      <?php if ($resultado['ok']): ?><p class="mt-1">Isto confirma apenas que o servidor SMTP aceitou a mensagem. Confira a caixa de entrada E o spam do destinatário — a entrega também depende de SPF/DKIM do domínio e da política do destinatário.</p><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($erro)): ?><div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($erro) ?></div><?php endif; ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-sm font-bold text-text-primary">Enviar mensagem de teste</h2>
    <form method="post" action="<?= $base ?>/admin/usuarios/supervisor/email/testar" class="mt-2 flex flex-wrap items-end gap-2">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label for="destinatario" class="block text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Destinatário (uma caixa sua)</label>
        <input id="destinatario" name="destinatario" type="email" required maxlength="190" value="<?= Security::e((string)$destinatarioPadrao) ?>" class="<?= $inputClasses ?>">
      </div>
      <button type="submit" class="<?= ui_btn('primario') ?>">Enviar teste</button>
    </form>
    <p class="mt-2 text-[11px] text-text-muted">Limite: 5 testes por hora. Cada envio é auditado (sem o conteúdo).</p>
  </section>
</div>
