<?php
class RecruitmentStageMetadataValidator
{
    private const FIELD_LABELS = [
        'interview_date' => 'Data da entrevista',
        'interview_time' => 'Horário da entrevista',
        'interview_location_or_link' => 'Local ou link da entrevista',
        'test_name' => 'Nome do teste',
        'deadline' => 'Prazo do teste',
        'admission_date' => 'Data de admissão',
        'admission_notes' => 'Observações da admissão',
        'observacoes' => 'Motivo da reprovação (observações)',
        'confirm' => 'Confirmação da movimentação',
    ];

    public static function requiresMetadata(string $stageSlug): bool
    {
        return in_array($stageSlug, [
            'entrevista-rh', 'entrevista-gestor', 'testes', 'admissao', 'reprovado', 'banco-de-talentos',
        ], true);
    }

    /**
     * @param mixed $observacoes
     * @return string[] chaves dos campos faltantes (ver self::FIELD_LABELS)
     */
    public static function missingFields(string $stageSlug, array $metadata, $observacoes = null): array
    {
        $missing = [];

        switch ($stageSlug) {
            case 'entrevista-rh':
            case 'entrevista-gestor':
                if (self::isBlank($metadata['interview_date'] ?? null)) {
                    $missing[] = 'interview_date';
                }
                if (self::isBlank($metadata['interview_time'] ?? null)) {
                    $missing[] = 'interview_time';
                }
                if (self::isBlank($metadata['interview_location'] ?? null) && self::isBlank($metadata['interview_link'] ?? null)) {
                    $missing[] = 'interview_location_or_link';
                }
                break;

            case 'testes':
                if (self::isBlank($metadata['test_name'] ?? null)) {
                    $missing[] = 'test_name';
                }
                if (self::isBlank($metadata['deadline'] ?? null)) {
                    $missing[] = 'deadline';
                }
                break;

            case 'admissao':
                if (self::isBlank($metadata['admission_date'] ?? null)) {
                    $missing[] = 'admission_date';
                }
                if (self::isBlank($metadata['admission_notes'] ?? null)) {
                    $missing[] = 'admission_notes';
                }
                break;

            case 'reprovado':
                if (self::isBlank($observacoes)) {
                    $missing[] = 'observacoes';
                }
                if (empty($metadata['confirm'])) {
                    $missing[] = 'confirm';
                }
                break;

            case 'banco-de-talentos':
                if (empty($metadata['confirm'])) {
                    $missing[] = 'confirm';
                }
                break;
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
