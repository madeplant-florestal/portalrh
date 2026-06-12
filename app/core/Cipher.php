<?php
class Cipher
{
    private const ALGO = 'AES-256-CBC';

    public static function encrypt(?string $plainText): ?string
    {
        $plainText = $plainText === null ? null : trim($plainText);
        if ($plainText === null || $plainText === '') {
            return null;
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('A extensão OpenSSL é obrigatória para criptografar dados sensíveis.');
        }

        $ivLength = openssl_cipher_iv_length(self::ALGO);
        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($plainText, self::ALGO, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Falha ao criptografar o conteúdo informado.');
        }

        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(?string $payload): ?string
    {
        $payload = $payload === null ? null : trim($payload);
        if ($payload === null || $payload === '') {
            return null;
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('A extensão OpenSSL é obrigatória para descriptografar dados sensíveis.');
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::ALGO);
        if (strlen($decoded) <= $ivLength) {
            return null;
        }

        $iv = substr($decoded, 0, $ivLength);
        $cipherText = substr($decoded, $ivLength);
        $plainText = openssl_decrypt($cipherText, self::ALGO, self::key(), OPENSSL_RAW_DATA, $iv);
        return $plainText === false ? null : $plainText;
    }

    private static function key(): string
    {
        $config = Config::get();
        $explicitKey = trim((string)($config['security']['data_encryption_key'] ?? ''));
        if ($explicitKey !== '') {
            return hash('sha256', $explicitKey, true);
        }

        $fallbackMaterial = implode('|', [
            (string)($config['security']['session_name'] ?? 'RHMADEPLANTSESSID'),
            (string)($config['security']['csrf_key'] ?? 'csrf_token'),
            (string)($config['database']['pass'] ?? ''),
            (string)($config['app']['name'] ?? 'RH Madeplant'),
        ]);

        return hash('sha256', $fallbackMaterial, true);
    }
}
