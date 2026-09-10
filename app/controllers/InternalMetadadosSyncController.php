<?php

/**
 * Endpoints de finalidade única: receber os lotes de sincronização METADADOS enviados por
 * scripts/sync_metadados_producao.php de dentro da rede Madeplant (ver
 * docs/claude/roadmap-tecnico.md, Fase 4 — sincronização segura de produção; Fase 5.1A —
 * dimensões Empresas/Unidades). Uma rota POST por dimensão:
 *   /internal/metadados/colaboradores/sync  -> MetadadosSyncIngestService
 *   /internal/metadados/empresas/sync       -> MetadadosDimensaoSyncIngestService('empresas')
 *   /internal/metadados/unidades/sync       -> MetadadosDimensaoSyncIngestService('unidades')
 *   /internal/metadados/setores/sync        -> MetadadosDimensaoSyncIngestService('setores')
 *   /internal/metadados/cargos/sync         -> MetadadosDimensaoSyncIngestService('cargos')
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
        $this->processar('colaboradores', static fn () => new MetadadosSyncIngestService());
    }

    /** Fase 5.1A — dimensão EMPRESAS (RHEMPRESAS). */
    public function empresas(): void
    {
        $this->processar('empresas', static fn () => new MetadadosDimensaoSyncIngestService('empresas'));
    }

    /** Fase 5.1A — dimensão UNIDADES (RHUNIDADES). */
    public function unidades(): void
    {
        $this->processar('unidades', static fn () => new MetadadosDimensaoSyncIngestService('unidades'));
    }

    /** Fase 5.2 — dimensão SETORES (RHSETORES). */
    public function setores(): void
    {
        $this->processar('setores', static fn () => new MetadadosDimensaoSyncIngestService('setores'));
    }

    /** Fase 5.2 — dimensão CARGOS (RHCARGOS). */
    public function cargos(): void
    {
        $this->processar('cargos', static fn () => new MetadadosDimensaoSyncIngestService('cargos'));
    }

    /**
     * @param callable():object $fabricaServico Fábrica do serviço de ingestão — recebe
     *        receberLote(string $corpoBruto, array $headers): array{http_status:int, body:array}.
     */
    private function processar(string $dimensao, callable $fabricaServico): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $corpoBruto = (string)file_get_contents('php://input');
            $headers = $this->readHeaders();

            $resultado = $fabricaServico()->receberLote($corpoBruto, $headers);

            http_response_code($resultado['http_status']);
            echo json_encode($resultado['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            // Nunca expor stack trace ao chamador — só loga internamente.
            Logger::exception($e, 'ERROR', ['endpoint' => "internal/metadados/{$dimensao}/sync"]);
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
