<?php

/**
 * Detalhamento administrativo dos resultados da Pesquisa de Integração respondida via QR Code, por
 * data de integração. A URL vive sob a central `/admin/pesquisas-reacao-integracao`, mas o domínio
 * de permissão é o da Integração (`integracao_colaborador.visualizar`) — possuir só a permissão da
 * Pesquisa de Reação NÃO dá acesso a estes dados.
 */
class AdminPesquisaIntegracaoResultadosController extends Controller
{
    public function resultados(string $data): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.visualizar');

        $dataYmd = PesquisaIntegracaoResultadosService::dataValida($data);
        if ($dataYmd === null) {
            http_response_code(404);
            echo 'Integração não encontrada.';
            return;
        }

        $this->view->render('admin/pesquisa_integracao_qr/resultados', [
            'resultados' => PesquisaIntegracaoResultadosService::resultadosDaIntegracao($dataYmd),
        ], 'layouts/app-shell');
    }
}
