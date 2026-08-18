<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/core/bootstrap.php';

/**
 * Reconcilia a configuração antiga de webhooks por empresa (`recruitment_webhook_settings`)
 * para a configuração global única (`recruitment_webhook_global_settings`).
 *
 * Regra: nunca escolhe uma URL arbitrariamente entre múltiplas configurações diferentes.
 * Se houver ambiguidade (mais de uma URL distinta, ou mais de um segredo distinto para a
 * mesma URL), o script para e imprime a comparação para decisão manual — nada é gravado.
 *
 * Uso: php scripts/migrate_recruitment_webhook_to_global.php
 */

RecruitmentWebhookSchemaService::ensureSchema();

$pdo = Database::conn();

$rows = $pdo->query(
    "SELECT s.scope_key, s.empresa_id, s.enabled, s.webhook_url, s.webhook_secret_encrypted, e.nome AS empresa_nome
     FROM recruitment_webhook_settings s
     LEFT JOIN empresas e ON e.id = s.empresa_id
     ORDER BY s.scope_key ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$withUrl = array_values(array_filter($rows, static fn (array $r): bool => trim((string)($r['webhook_url'] ?? '')) !== ''));
$distinctUrls = array_values(array_unique(array_map(static fn (array $r): string => trim((string)$r['webhook_url']), $withUrl)));

echo "Configurações antigas encontradas: " . count($rows) . "\n";
echo "Configurações com URL preenchida: " . count($withUrl) . "\n";
echo "URLs distintas: " . count($distinctUrls) . "\n\n";

if (count($distinctUrls) === 0) {
    echo "Nada para migrar — nenhuma URL configurada nas linhas antigas.\n";
    echo "A configuração global permanece desabilitada/vazia (padrão já garantido por RecruitmentWebhookSchemaService).\n";
    exit(0);
}

if (count($distinctUrls) > 1) {
    fwrite(STDERR, "AMBIGUIDADE: existem " . count($distinctUrls) . " URLs diferentes configuradas. Não é seguro escolher uma automaticamente.\n\n");
    printComparisonTable($withUrl);
    fwrite(STDERR, "\nNada foi gravado. Decida manualmente qual URL deve ser a configuração global, depois\n");
    fwrite(STDERR, "salve-a diretamente na tela /admin/recruitment-webhooks (ela grava em recruitment_webhook_global_settings)\n");
    fwrite(STDERR, "e gere um novo segredo por lá.\n");
    exit(1);
}

$targetUrl = $distinctUrls[0];
$matchingRows = array_values(array_filter($withUrl, static fn (array $r): bool => trim((string)$r['webhook_url']) === $targetUrl));
$distinctSecrets = array_values(array_unique(array_map(
    static fn (array $r): string => (string)($r['webhook_secret_encrypted'] ?? ''),
    array_filter($matchingRows, static fn (array $r): bool => !empty($r['webhook_secret_encrypted']))
)));

if (count($distinctSecrets) > 1) {
    fwrite(STDERR, "AMBIGUIDADE: a mesma URL está associada a mais de um segredo distinto entre as configurações antigas.\n\n");
    printComparisonTable($matchingRows);
    fwrite(STDERR, "\nNão é seguro escolher um segredo automaticamente. Decida manualmente e gere um novo segredo pela interface após a migração.\n");
    exit(1);
}

$enabled = false;
foreach ($matchingRows as $row) {
    if ((int)($row['enabled'] ?? 0) === 1) {
        $enabled = true;
        break;
    }
}
$secretEncrypted = $distinctSecrets[0] ?? null;

$stmt = $pdo->prepare(
    'UPDATE recruitment_webhook_global_settings SET enabled = ?, webhook_url = ?, webhook_secret_encrypted = ? WHERE id = 1'
);
$stmt->execute([$enabled ? 1 : 0, $targetUrl, $secretEncrypted]);

echo "Migração concluída: configuração global atualizada.\n";
echo "  URL: " . $targetUrl . "\n";
echo "  Habilitado: " . ($enabled ? 'sim' : 'não') . "\n";
echo "  Segredo copiado: " . ($secretEncrypted !== null ? 'sim (cifrado, não decifrado neste processo)' : 'não havia segredo configurado') . "\n";
echo "\nA tabela antiga 'recruitment_webhook_settings' NÃO foi apagada — permanece para auditoria/rollback.\n";
exit(0);

function printComparisonTable(array $rows): void
{
    fwrite(STDERR, str_pad('scope_key', 20) . str_pad('empresa', 30) . str_pad('habilitado', 12) . str_pad('tem_segredo', 12) . "url\n");
    foreach ($rows as $row) {
        fwrite(STDERR,
            str_pad((string)$row['scope_key'], 20)
            . str_pad((string)($row['empresa_nome'] ?? '-'), 30)
            . str_pad((int)($row['enabled'] ?? 0) === 1 ? 'sim' : 'não', 12)
            . str_pad(!empty($row['webhook_secret_encrypted']) ? 'sim' : 'não', 12)
            . (string)$row['webhook_url'] . "\n"
        );
    }
}
