<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Sem PII, sem segredo real — segredo fictício só para este teste.
$segredo = 'segredo-fictício-de-teste';
$corpo = '{"registros":[]}';
$agora = 1_700_000_000;

try {
    // Caso 1 — assinatura válida dentro da janela é aceita.
    $timestamp = (string)$agora;
    $assinatura = MetadadosSyncSignature::assinar($timestamp, $corpo, $segredo);
    $r1 = MetadadosSyncSignature::verificar($timestamp, $assinatura, $corpo, $segredo, 300, $agora);
    $assert($r1['ok'] === true, 'Caso 1: assinatura válida dentro da janela deveria ser aceita: ' . ($r1['motivo'] ?? ''));

    // Caso 2 — assinatura inválida (segredo errado) é recusada.
    $assinaturaErrada = MetadadosSyncSignature::assinar($timestamp, $corpo, 'outro-segredo');
    $r2 = MetadadosSyncSignature::verificar($timestamp, $assinaturaErrada, $corpo, $segredo, 300, $agora);
    $assert($r2['ok'] === false, 'Caso 2: assinatura com segredo errado deveria ser recusada.');

    // Caso 3 — corpo alterado depois de assinado invalida a assinatura (a assinatura cobre o corpo).
    $r3 = MetadadosSyncSignature::verificar($timestamp, $assinatura, $corpo . 'X', $segredo, 300, $agora);
    $assert($r3['ok'] === false, 'Caso 3: corpo alterado depois da assinatura deveria invalidar a verificação.');

    // Caso 4 — timestamp expirado (muito no passado) é recusado.
    $timestampExpirado = (string)($agora - 600);
    $assinaturaExpirada = MetadadosSyncSignature::assinar($timestampExpirado, $corpo, $segredo);
    $r4 = MetadadosSyncSignature::verificar($timestampExpirado, $assinaturaExpirada, $corpo, $segredo, 300, $agora);
    $assert($r4['ok'] === false, 'Caso 4: timestamp expirado (600s no passado, janela de 300s) deveria ser recusado.');

    // Caso 5 — timestamp futuro fora da tolerância também é recusado (nunca só "no passado").
    $timestampFuturo = (string)($agora + 600);
    $assinaturaFutura = MetadadosSyncSignature::assinar($timestampFuturo, $corpo, $segredo);
    $r5 = MetadadosSyncSignature::verificar($timestampFuturo, $assinaturaFutura, $corpo, $segredo, 300, $agora);
    $assert($r5['ok'] === false, 'Caso 5: timestamp futuro fora da tolerância deveria ser recusado.');

    // Caso 6 — timestamp dentro da tolerância, mas próximo da borda, ainda é aceito.
    $timestampBorda = (string)($agora + 250);
    $assinaturaBorda = MetadadosSyncSignature::assinar($timestampBorda, $corpo, $segredo);
    $r6 = MetadadosSyncSignature::verificar($timestampBorda, $assinaturaBorda, $corpo, $segredo, 300, $agora);
    $assert($r6['ok'] === true, 'Caso 6: timestamp dentro da janela (mesmo perto da borda) deveria ser aceito.');

    // Caso 7 — timestamp ausente/não numérico é recusado sem lançar exceção.
    $r7 = MetadadosSyncSignature::verificar(null, $assinatura, $corpo, $segredo, 300, $agora);
    $assert($r7['ok'] === false, 'Caso 7: timestamp ausente deveria ser recusado.');
    $r7b = MetadadosSyncSignature::verificar('não-numérico', $assinatura, $corpo, $segredo, 300, $agora);
    $assert($r7b['ok'] === false, 'Caso 7b: timestamp não numérico deveria ser recusado.');

    // Caso 8 — assinatura ausente é recusada.
    $r8 = MetadadosSyncSignature::verificar($timestamp, null, $corpo, $segredo, 300, $agora);
    $assert($r8['ok'] === false, 'Caso 8: assinatura ausente deveria ser recusada.');

    // Caso 9 — segredo vazio no receptor (não configurado) nunca autentica nada, mesmo com
    // assinatura "correta" calculada com uma string vazia — proteção contra configuração ausente.
    $assinaturaComSegredoVazio = MetadadosSyncSignature::assinar($timestamp, $corpo, '');
    $r9 = MetadadosSyncSignature::verificar($timestamp, $assinaturaComSegredoVazio, $corpo, '', 300, $agora);
    $assert($r9['ok'] === false, 'Caso 9: segredo vazio no receptor nunca deveria autenticar, mesmo com assinatura calculada da mesma forma.');

    // Extra — o motivo nunca expõe a assinatura nem o segredo (mensagem genérica).
    $assert(strpos((string)$r2['motivo'], $segredo) === false, 'Extra: motivo de falha nunca deveria conter o segredo.');
    $assert(strpos((string)$r2['motivo'], $assinaturaErrada) === false, 'Extra: motivo de falha nunca deveria conter a assinatura recebida.');

    echo "OK unit_metadados_sync_signature\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
