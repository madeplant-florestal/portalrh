<?php

/**
 * Assinatura HMAC-SHA256 do lote de sincronização RHMADEPLANT -> Portal RH em produção
 * (Fase 4). Mesmo esquema conceitual já usado para os webhooks de recrutamento
 * (RecruitmentWebhookDeliveryService::buildSignatureHeaders() — timestamp concatenado ao corpo,
 * HMAC-SHA256, prefixo "sha256="), reaproveitado aqui como classe compartilhada porque tanto o
 * sender (scripts/sync_metadados_producao.php) quanto o receptor
 * (InternalMetadadosSyncController/MetadadosSyncIngestService) precisam do mesmo cálculo.
 *
 * Nunca loga o segredo nem a assinatura completa — só o resultado da verificação.
 */
class MetadadosSyncSignature
{
    public const HEADER_TIMESTAMP = 'X-Metadados-Timestamp';
    public const HEADER_SIGNATURE = 'X-Metadados-Signature';

    /** Assinatura no formato enviado no cabeçalho (com o prefixo "sha256="). */
    public static function assinar(string $timestamp, string $corpoBruto, string $segredo): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $corpoBruto, $segredo);
    }

    /**
     * Verifica timestamp (janela de replay) + assinatura (comparação em tempo constante).
     * Nunca lança exceção — sempre retorna um motivo claro para logging/resposta HTTP, sem expor
     * o segredo nem a assinatura recebida.
     *
     * @return array{ok:bool, motivo:?string}
     */
    public static function verificar(
        ?string $timestampHeader,
        ?string $assinaturaHeader,
        string $corpoBruto,
        string $segredo,
        int $janelaSegundos,
        ?int $agora = null
    ): array {
        if ($segredo === '') {
            return ['ok' => false, 'motivo' => 'Segredo de sincronização não configurado no receptor.'];
        }
        if ($timestampHeader === null || $timestampHeader === '' || !ctype_digit($timestampHeader)) {
            return ['ok' => false, 'motivo' => 'Timestamp ausente ou inválido.'];
        }
        if ($assinaturaHeader === null || $assinaturaHeader === '') {
            return ['ok' => false, 'motivo' => 'Assinatura ausente.'];
        }

        $agora = $agora ?? time();
        $timestamp = (int)$timestampHeader;
        if (abs($agora - $timestamp) > $janelaSegundos) {
            return ['ok' => false, 'motivo' => 'Timestamp fora da janela de validade (possível replay ou relógio dessincronizado).'];
        }

        $esperada = self::assinar($timestampHeader, $corpoBruto, $segredo);
        if (!hash_equals($esperada, $assinaturaHeader)) {
            return ['ok' => false, 'motivo' => 'Assinatura inválida.'];
        }

        return ['ok' => true, 'motivo' => null];
    }
}
