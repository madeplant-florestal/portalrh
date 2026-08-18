<?php
class RecruitmentWebhookUrlGuard
{
    public static function assertSafe(string $url): void
    {
        // TEMPORARIO (investigacao): log detalhado de cada etapa. Remover apos concluir o diagnostico.
        Logger::info('[INVESTIGACAO SSRF] assertSafe chamado', ['url_original' => $url]);

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            Logger::info('[INVESTIGACAO SSRF] rejeitado em parse_url/scheme/host', ['parts' => $parts]);
            throw new InvalidArgumentException('URL de webhook inválida.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        Logger::info('[INVESTIGACAO SSRF] parse_url OK', ['scheme' => $scheme, 'host' => $parts['host'], 'path' => $parts['path'] ?? null]);

        if (!in_array($scheme, ['http', 'https'], true)) {
            Logger::info('[INVESTIGACAO SSRF] rejeitado por scheme invalido', ['scheme' => $scheme]);
            throw new InvalidArgumentException('URL de webhook deve usar http ou https.');
        }

        if (self::privateTargetsAllowed()) {
            Logger::info('[INVESTIGACAO SSRF] permitido via webhook_allow_private_targets=true, retornando sem checar IP');
            return;
        }

        $host = strtolower((string)$parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            Logger::info('[INVESTIGACAO SSRF] rejeitado por host local', ['host' => $host]);
            throw new InvalidArgumentException('URL de webhook não pode apontar para um host local (proteção contra SSRF).');
        }

        $ips = self::resolveIps($host);
        Logger::info('[INVESTIGACAO SSRF] resolveIps concluido', ['host' => $host, 'ips_resolvidos' => $ips]);

        if ($ips === []) {
            Logger::info('[INVESTIGACAO SSRF] rejeitado por falha de resolucao DNS', ['host' => $host]);
            throw new InvalidArgumentException('Não foi possível resolver o host do webhook.');
        }

        foreach ($ips as $ip) {
            $allowed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            Logger::info('[INVESTIGACAO SSRF] verificacao de faixa de IP', ['ip' => $ip, 'permitido' => $allowed]);
            if (!$allowed) {
                Logger::info('[INVESTIGACAO SSRF] rejeitado por IP privado/reservado', ['ip' => $ip]);
                throw new InvalidArgumentException('URL de webhook aponta para um endereço de rede privado/reservado, o que não é permitido por segurança (SSRF).');
            }
        }

        Logger::info('[INVESTIGACAO SSRF] assertSafe PASSOU, nenhuma rejeicao', ['url_original' => $url]);
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
