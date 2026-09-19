<?php

/**
 * Entrevista de Desligamento — página PÚBLICA (sem login), acessada por token aleatório de 256 bits.
 * Instrumento próprio: não usa as tabelas/controllers das Pesquisas de Integração ou de Reação.
 *
 * Endurecimento específico (a entrevista é sensível e identificada): página sem NENHUM recurso de
 * terceiro (layout `publico-seguro`, só assets locais) — a URL com o token nunca é enviada a outro
 * domínio —, `Referrer-Policy: no-referrer` (cabeçalho + <meta>), `Cache-Control: no-store`, noindex,
 * CSRF no POST, PRG após o envio e rate limit de tentativas com token inexistente.
 *
 * O `Header always set` de public/.htaccess (quando ativo) pode sobrescrever Referrer-Policy/CSP: por isso
 * o <meta name="referrer"> e a ausência de recursos externos são as defesas efetivas; o CSP do servidor
 * já é compatível (sem script, sem estilo inline).
 */
class EntrevistaDesligamentoController extends Controller
{
    private const RL_SCOPE = 'entrevista_desligamento_token_invalido';
    /** Tentativas com token inexistente por IP, na janela, antes do bloqueio. Uso legítimo nunca falha lookup. */
    public const RL_MAX = 10;
    public const RL_JANELA = 600;
    public const RL_BLOQUEIO = 900;

    public static function enviarCabecalhosSeguros(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public static function limiteAtingido(): bool
    {
        return Security::rateLimitCheck(self::RL_SCOPE, self::chaveIp(), self::RL_MAX, self::RL_JANELA, self::RL_BLOQUEIO)['blocked'];
    }

    public static function registrarTokenInvalido(): void
    {
        $rl = Security::rateLimitCheck(self::RL_SCOPE, self::chaveIp(), self::RL_MAX, self::RL_JANELA, self::RL_BLOQUEIO);
        Security::rateLimitHit($rl['file'], $rl['data'], false, self::RL_BLOQUEIO, self::RL_MAX, self::RL_JANELA);
    }

    public static function limparLimite(): void
    {
        Security::rateLimitReset(self::RL_SCOPE, self::chaveIp());
    }

    private static function chaveIp(): string
    {
        return hash('sha256', Security::clientIp());
    }

    public function show(string $token): void
    {
        self::enviarCabecalhosSeguros();
        if (self::limiteAtingido()) {
            $this->renderizar('bloqueado', [], 429);
            return;
        }

        $resolvido = (new EntrevistaDesligamentoService())->resolverParaPagina($token, new DateTimeImmutable('now'));
        $this->renderizarEstado($resolvido['estado'], ['contexto' => $resolvido['contexto'], 'token' => $token, 'erros' => [], 'valores' => []]);
    }

    public function store(string $token): void
    {
        self::enviarCabecalhosSeguros();
        if (self::limiteAtingido()) {
            $this->renderizar('bloqueado', [], 429);
            return;
        }
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            $this->renderizar('sessao_expirada', ['token' => $token], 400);
            return;
        }

        $service = new EntrevistaDesligamentoService();
        $resultado = $service->registrarResposta($token, $_POST, new DateTimeImmutable('now'));

        if ($resultado['ok']) {
            // PRG: o refresh do navegador não reenvia o formulário; a URL agora mostra "concluída".
            redirect('/entrevista-desligamento/' . $token);
            return;
        }
        $this->renderizarEstado((string)$resultado['estado'], [
            'contexto' => $resultado['contexto'] ?? null,
            'token' => $token,
            'erros' => $resultado['erros'] ?? [],
            'valores' => $resultado['valores'] ?? [],
        ]);
    }

    private function renderizarEstado(string $estado, array $params): void
    {
        if ($estado === 'invalido') {
            self::registrarTokenInvalido();
            $this->renderizar('invalido', [], 404);
            return;
        }
        if ($estado === 'formulario') {
            $this->renderizar('formulario', $params + ['csrf' => Security::csrfToken()]);
            return;
        }
        $this->renderizar($estado);
    }

    private function renderizar(string $estado, array $params = [], int $status = 200): void
    {
        http_response_code($status);
        $this->view->render('entrevista_desligamento/publica', $params + ['estado' => $estado], 'layouts/publico-seguro');
    }
}
