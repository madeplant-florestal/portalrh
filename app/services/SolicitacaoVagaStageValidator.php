<?php
/**
 * Campos obrigatórios por etapa de destino do Kanban de Solicitações de Vaga.
 * Análogo a RecruitmentStageMetadataValidator (Kanban de candidatos), mas com regras
 * próprias — não compartilha etapas nem campos com aquele Kanban.
 */
class SolicitacaoVagaStageValidator
{
    private const FIELD_LABELS = [
        'motivo_cancelamento' => 'Motivo do cancelamento',
    ];

    public static function requiresMetadata(string $stageSlug): bool
    {
        return $stageSlug === 'cancelada';
    }

    /**
     * @return string[] chaves dos campos faltantes (ver self::FIELD_LABELS)
     */
    public static function missingFields(string $stageSlug, array $metadata): array
    {
        $missing = [];

        if ($stageSlug === 'cancelada' && self::isBlank($metadata['motivo_cancelamento'] ?? null)) {
            $missing[] = 'motivo_cancelamento';
        }

        return $missing;
    }

    public static function buildMessage(array $missingFields): string
    {
        if ($missingFields === []) {
            return '';
        }
        $labels = array_map(static fn (string $f): string => self::FIELD_LABELS[$f] ?? $f, $missingFields);
        return 'Preencha os campos obrigatórios da etapa: ' . implode(', ', $labels) . '.';
    }

    private static function isBlank($value): bool
    {
        return trim((string)$value) === '';
    }
}
