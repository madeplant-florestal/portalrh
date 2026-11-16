<?php

/**
 * GET /api/public/vagas — vagas abertas em JSON, somente leitura e sem login, para o site
 * institucional (madeplant.com.br/trabalhe-conosco). Ver VagasPublicasApiService.
 *
 * Superfície mínima: só GET (o Router devolve 404 para qualquer outro método), nenhum parâmetro
 * de entrada (sem filtros, sem ORDER BY dinâmico) e nenhum campo interno no retorno.
 */
class PublicVagasApiController
{
    /** Cache HTTP: o navegador/CDN reaproveita a resposta por ~90s — RH publica/encerra e o site reflete em segundos. */
    private const CACHE_SEGUNDOS = 90;

    public function index(): void
    {
        // O bootstrap abre sessão para o Portal inteiro; esta rota pública não usa sessão: libera o
        // lock e remove o cookie/cabeçalhos "nocache" para a resposta poder ser cacheada.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Vary: Origin');
        $origem = VagasPublicasApiService::origemCorsPermitida($_SERVER['HTTP_ORIGIN'] ?? null);
        if ($origem !== null) {
            header('Access-Control-Allow-Origin: ' . $origem);
        }

        try {
            $baseUrl = (string)(Config::get()['app']['base_url'] ?? '');
            $payload = VagasPublicasApiService::montarPayload(Vaga::allActive(), $baseUrl);
            header('Cache-Control: public, max-age=' . self::CACHE_SEGUNDOS . ', stale-while-revalidate=60');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            error_log('[api/public/vagas] ' . $e->getMessage());
            http_response_code(500);
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'error' => 'indisponivel'], JSON_UNESCAPED_UNICODE);
        }
    }
}
