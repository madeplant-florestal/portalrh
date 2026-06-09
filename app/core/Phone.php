<?php
class Phone
{
    public static function digits(?string $input): string
    {
        $raw = trim((string)$input);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '55') && (strlen($digits) === 13 || strlen($digits) === 12)) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    public static function normalize(?string $input): ?string
    {
        $digits = self::digits($input);
        if ($digits === '') {
            return null;
        }
        if (!preg_match('/^\d{11}$/', $digits)) {
            return null;
        }
        return $digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    public static function format(?string $input): string
    {
        $digits = self::normalize($input);
        if ($digits === null) {
            return trim((string)$input);
        }
        $ddd = substr($digits, 0, 2);
        $p1 = substr($digits, 2, 5);
        $p2 = substr($digits, 7, 4);
        return '(' . $ddd . ') ' . $p1 . '-' . $p2;
    }
}
