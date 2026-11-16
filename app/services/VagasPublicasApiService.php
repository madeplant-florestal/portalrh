<?php

/**
 * API pública somente-leitura de vagas (GET /api/public/vagas) — monta o payload consumido pelo
 * site institucional (madeplant.com.br/trabalhe-conosco).
 *
 * Fonte única: as linhas vêm de `Vaga::allActive()`, a MESMA consulta que alimenta a vitrine
 * pública `/vagas` (HomeController::index) — nenhuma regra paralela de "vaga aberta". Este
 * serviço só PROJETA essas linhas no contrato mínimo da API: nunca devolve a linha inteira.
 * Funções puras (sem banco/superglobais) para serem testáveis sem infraestrutura.
 */
class VagasPublicasApiService
{
    /** Origens que podem ler a API pelo navegador (dados públicos; a política é só por coerência). */
    private const ORIGENS_PERMITIDAS = [
        'https://madeplant.com.br',
        'https://www.madeplant.com.br',
    ];

    /** Desenvolvimento local do site institucional (Vite dev/preview). */
    private const ORIGENS_LOCAIS = '#^http://(localhost|127\.0\.0\.1):(5173|5180|4173)$#';

    /** Teto do resumo enviado — o site decide quantas linhas exibir; aqui só limita o tamanho do payload. */
    private const RESUMO_MAX = 600;

    /**
     * @param array<int, array<string, mixed>> $vagas linhas de Vaga::allActive()
     * @return array{ok: bool, total: int, generated_at: string, vagas: array<int, array<string, mixed>>}
     */
    public static function montarPayload(array $vagas, string $baseUrl, ?\DateTimeImmutable $agora = null): array
    {
        $agora = $agora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $itens = [];
        foreach ($vagas as $v) {
            $id = (int)($v['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $itens[] = [
                'id' => $id,
                'titulo' => self::texto($v['titulo'] ?? ''),
                'area' => self::textoOuNulo($v['area'] ?? ''),
                'empresa' => self::textoOuNulo($v['empresa_nome'] ?? ''),
                'local' => self::textoOuNulo($v['local'] ?? ''),
                'resumo' => self::resumo($v),
                'url' => rtrim($baseUrl, '/') . '/vaga/' . $id,
            ];
        }

        return [
            'ok' => true,
            'total' => count($itens),
            'generated_at' => $agora->format('Y-m-d\TH:i:s\Z'),
            'vagas' => $itens,
        ];
    }

    /** Mesmo critério do card de /vagas: descrição, ou requisitos quando a descrição está vazia. */
    public static function resumo(array $vaga): ?string
    {
        $bruto = trim((string)($vaga['descricao'] ?? '')) !== '' ? (string)$vaga['descricao'] : (string)($vaga['requisitos'] ?? '');
        $limpo = self::texto($bruto);
        if ($limpo === '') {
            return null;
        }
        if (mb_strlen($limpo) <= self::RESUMO_MAX) {
            return $limpo;
        }
        $corte = mb_substr($limpo, 0, self::RESUMO_MAX);
        $espaco = mb_strrpos($corte, ' ');
        if ($espaco !== false && $espaco > (int)(self::RESUMO_MAX * 0.6)) {
            $corte = mb_substr($corte, 0, $espaco);
        }
        return rtrim($corte, " \t\n\r.,;:-") . '…';
    }

    /** Devolve a origem a ecoar em Access-Control-Allow-Origin, ou null (sem cabeçalho CORS). */
    public static function origemCorsPermitida(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }
        if (in_array($origin, self::ORIGENS_PERMITIDAS, true)) {
            return $origin;
        }
        return preg_match(self::ORIGENS_LOCAIS, $origin) === 1 ? $origin : null;
    }

    private static function texto(mixed $valor): string
    {
        $semTags = strip_tags((string)$valor);
        $semTags = html_entity_decode($semTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $semTags));
    }

    private static function textoOuNulo(mixed $valor): ?string
    {
        $t = self::texto($valor);
        return $t === '' ? null : $t;
    }
}
