<?php

/**
 * Carta Proposta — página PÚBLICA (sem login), acessada por token aleatório de 256 bits. Mesmo
 * endurecimento da Entrevista de Desligamento (EntrevistaDesligamentoController): layout
 * `publico-seguro` (sem recurso de terceiro), Referrer-Policy no-referrer, Cache-Control no-store,
 * noindex, CSRF no POST, PRG após o envio, rate limit de tentativas com token inexistente.
 */
class CartaPropostaController extends Controller
{
    private const RL_SCOPE = 'carta_proposta_token_invalido';
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

        $resolvido = (new CartaPropostaService())->resolverParaPagina($token, new DateTimeImmutable('now'));
        $this->renderizarEstado($resolvido['estado'], ['contexto' => $resolvido['contexto'], 'token' => $token, 'erro' => null]);
    }

    public function decidir(string $token): void
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

        $decisao = (string)($_POST['decisao'] ?? '');
        $motivoRecusa = $decisao === 'recusada' ? Security::sanitizeString($_POST['motivo_recusa'] ?? '') : null;
        if (!in_array($_POST['confirmo_leitura'] ?? '', ['1'], true)) {
            $resolvido = (new CartaPropostaService())->resolverParaPagina($token, new DateTimeImmutable('now'));
            $this->renderizarEstado($resolvido['estado'], [
                'contexto' => $resolvido['contexto'],
                'token' => $token,
                'erro' => 'Confirme que leu e concorda com as condições apresentadas antes de registrar sua decisão.',
            ]);
            return;
        }

        $resultado = (new CartaPropostaService())->registrarDecisao(
            $token,
            $decisao,
            $motivoRecusa,
            Security::clientIp(),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            new DateTimeImmutable('now')
        );

        if ($resultado['ok'] ?? false) {
            // PRG: evita reenvio da decisão em um refresh do navegador.
            redirect('/carta-proposta/' . $token);
            return;
        }
        $this->renderizarEstado((string)($resultado['estado'] ?? 'invalido'), [
            'contexto' => null,
            'token' => $token,
            'erro' => $resultado['erro'] ?? null,
        ]);
    }

    /**
     * PDF da proposta original — mesmo gate único de acesso da página (CartaPropostaService::
     * resolverAcessoPorToken()), nunca duplicado. Só disponível enquanto o estado é 'formulario':
     * uma vez decidida (aceita/recusada) ou vencida a validade, o link de decisão já utilizado
     * nunca mais expõe o PDF — a via aceita é recuperada por outro mecanismo (RH), nunca por aqui.
     */
    public function pdf(string $token): void
    {
        self::enviarCabecalhosSeguros();
        $resolvido = (new CartaPropostaService())->resolverAcessoPorToken($token, new DateTimeImmutable('now'));
        if ($resolvido['estado'] !== 'formulario' || $resolvido['carta'] === null || empty($resolvido['carta']['pdf_nome_armazenado'])) {
            http_response_code(404); echo 'Não encontrado'; return;
        }
        $carta = $resolvido['carta'];
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas';
        $file = $dir . DIRECTORY_SEPARATOR . basename((string)$carta['pdf_nome_armazenado']);
        $real = realpath($file);
        $realDir = realpath($dir);
        if ($real === false || $realDir === false || strpos($real, $realDir) !== 0 || !is_file($real)) {
            http_response_code(404); echo 'Não encontrado'; return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="carta-proposta.pdf"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    private function renderizarEstado(string $estado, array $params): void
    {
        if ($estado === 'invalido') {
            self::registrarTokenInvalido();
            $this->renderizar('invalido', [], 404);
            return;
        }
        $this->renderizar($estado, $params + ['csrf' => Security::csrfToken()]);
    }

    private function renderizar(string $estado, array $params = [], int $status = 200): void
    {
        http_response_code($status);
        $this->view->render('carta_proposta/publica', $params + ['estado' => $estado, 'titulo' => 'Carta Proposta | Madeplant'], 'layouts/publico-seguro');
    }
}
