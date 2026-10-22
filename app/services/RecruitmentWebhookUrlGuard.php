<?php
class RecruitmentWebhookUrlGuard
{
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL de webhook inválida.');
        }

        $scheme = strtolower((string)$parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL de webhook deve usar http ou https.');
        }

        if (self::privateTargetsAllowed()) {
            return;
        }

        $host = strtolower((string)$parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('URL de webhook não pode apontar para um host local (proteção contra SSRF).');
        }

        $ips = self::resolveIps($host);

        if ($ips === []) {
            throw new InvalidArgumentException('Não foi possível resolver o host do webhook.');
        }

        foreach ($ips as $ip) {
            $allowed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (!$allowed) {
                throw new InvalidArgumentException('URL de webhook aponta para um endereço de rede privado/reservado, o que não é permitido por segurança (SSRF).');
            }
        }
    }

    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string)$record['ipv6'];
                }
            }
        }

        return array_unique($ips);
    }

    private static function privateTargetsAllowed(): bool
    {
        $config = Config::get();
        return (bool)($config['security']['webhook_allow_private_targets'] ?? false);
    }
}
