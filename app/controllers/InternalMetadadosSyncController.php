<?php

/**
 * Endpoint de finalidade única: receber o lote de sincronização METADADOS enviado por
 * scripts/sync_metadados_producao.php de dentro da rede Madeplant (ver
 * docs/claude/roadmap-tecnico.md, Fase 4 — sincronização segura de produção).
 *
 * Sem sessão, sem CSRF de formulário — autenticação é inteiramente via assinatura HMAC
 * (MetadadosSyncSignature), verificada dentro de MetadadosSyncIngestService. Fora do gate de
 * `/admin/*` do index.php de propósito: é uma rota máquina-a-máquina, não uma tela administrativa.
 *
 * Nunca renderiza HTML, nunca aceita GET (rota registrada só como POST), nunca executa SQL
 * arbitrário — o corpo da requisição é só dado, nunca comando.
 */
class InternalMetadadosSyncController extends Controller
{
    public function sync(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $corpoBruto = (string)file_get_contents('php://input');
            $headers = $this->readHeaders();

            $service = new MetadadosSyncIngestService();
            $resultado = $service->receberLote($corpoBruto, $headers);

            http_response_code($resultado['http_status']);
            echo json_encode($resultado['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            // Nunca expor stack trace ao chamador — só loga internamente.
            Logger::exception($e, 'ERROR', ['endpoint' => 'internal/metadados/colaboradores/sync']);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Falha interna ao processar a sincronização.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /** @return array<string,string> */
    private function readHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $nome = str_replace('_', '-', substr($key, 5));
                $headers[$nome] = (string)$value;
            }
        }
        return $headers;
    }
}
