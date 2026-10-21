<?php

/**
 * Valida o endpoint de destino do sender de sincronização METADADOS
 * (scripts/sync_metadados_producao.php) ANTES de montar/enviar qualquer requisição.
 *
 * Regra (correção 2026-10, auditoria de materialização Cargo×Setor): HTTPS continua obrigatório
 * para qualquer host normal — isso nunca é enfraquecido. HTTP só é aceito quando o host é
 * EXPLICITAMENTE um destino local de desenvolvimento (`localhost`, `127.0.0.1` ou host terminado
 * em `.test`, ex.: `rhmadeplant.test` do Laragon) — nunca para produção, nunca para outro domínio
 * ou IP externo. Nunca desabilita verificação TLS (CURLOPT_SSL_VERIFYPEER): esta classe só decide
 * se o ESQUEMA/host são aceitáveis antes da chamada existir — a verificação TLS de uma chamada
 * HTTPS real continua inteiramente a cargo do cURL padrão.
 */
class MetadadosSyncEndpointValidator
{
    private const HOSTS_LOCAIS_EXATOS = ['localhost', '127.0.0.1'];
    private const SUFIXO_LOCAL = '.test';

    public static function permitido(string $url): bool
    {
        $partes = parse_url($url);
        if ($partes === false) {
            return false;
        }

        $esquema = strtolower((string)($partes['scheme'] ?? ''));
        $host = strtolower((string)($partes['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($esquema === 'https') {
            return true;
        }

        if ($esquema === 'http') {
            return in_array($host, self::HOSTS_LOCAIS_EXATOS, true) || str_ends_with($host, self::SUFIXO_LOCAL);
        }

        return false;
    }
}
