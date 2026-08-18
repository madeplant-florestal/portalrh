<?php
class DateHelper
{
    public static function parseBrazilianDate(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('d/m/Y', $value);
        if (!$date) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->setTime(0, 0, 0);
    }

    public static function formatBrazilianDate(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Formata um datetime "YYYY-MM-DD HH:MM:SS" (formato de retorno do MySQL) como
     * "DD/MM/AAAA às HH:MM", via regex ao invés de DateTime/timezone — o valor é
     * exibido exatamente como foi persistido, sem qualquer conversão de fuso.
     */
    public static function formatBrazilianDateTime(?string $value): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $value, $m)) {
            return '';
        }
        [, $ano, $mes, $dia, $hora, $min] = $m;
        return "{$dia}/{$mes}/{$ano} às {$hora}:{$min}";
    }

    public static function toDatabaseDate(?string $value): ?string
    {
        $date = self::parseBrazilianDate($value);
        return $date ? $date->format('Y-m-d') : null;
    }

    public static function daysBetween(string $startDate, string $endDate): ?int
    {
        try {
            $start = new DateTimeImmutable($startDate);
            $end = new DateTimeImmutable($endDate);
        } catch (Throwable $e) {
            return null;
        }

        return (int)$start->diff($end)->format('%r%a');
    }
}
