<?php

/**
 * Administração do fluxo coletivo (QR Code) da Pesquisa de Integração: exibir o QR oficial (URL
 * pública estável `/integracao`, sem dados pessoais), abrir/encerrar a integração atual (a data da
 * sessão aberta é a `integracao_data_relacionada` das respostas). Reaproveita as permissões
 * individuais de Integração já existentes (`integracao_colaborador.visualizar`/`.editar`) — é a
 * mesma área funcional, não uma capacidade nova.
 */
class AdminPesquisaIntegracaoQrController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.visualizar');

        $this->renderIndex(Security::sanitizeString($_GET['erro'] ?? ''), Security::sanitizeString($_GET['ok'] ?? ''));
    }

    public function abrir(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.editar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $data = DateTimeImmutable::createFromFormat('!Y-m-d', Security::sanitizeString($_POST['data_integracao'] ?? ''));
        $erros = DateTimeImmutable::getLastErrors();
        if ($data === false || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0)) || (int)$data->format('Y') < 2000) {
            $this->renderIndex('Informe uma Data da Integração válida.', '');
            return;
        }

        $resultado = SessaoIntegracao::abrir($data->format('Y-m-d'), (int)($_SESSION['user_id'] ?? 0));
        if (!($resultado['ok'] ?? false)) {
            $this->renderIndex((string)$resultado['error'], '');
            return;
        }
        redirect('/admin/pesquisa-integracao-qr?ok=' . urlencode('Integração aberta para receber respostas pelo QR Code.'));
    }

    public function encerrar(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.editar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        SessaoIntegracao::encerrar((int)$id);
        redirect('/admin/pesquisa-integracao-qr?ok=' . urlencode('Integração encerrada — o QR Code não recebe mais respostas até abrir outra.'));
    }

    private function renderIndex(string $flashError, string $flashSuccess): void
    {
        $baseUrl = rtrim((string)(Config::app()['base_url'] ?? ''), '/');
        $this->view->render('admin/pesquisa_integracao_qr/index', [
            'urlPublica' => $baseUrl . '/integracao',
            'sessaoAberta' => SessaoIntegracao::abertaAtual(),
            'sessoes' => SessaoIntegracao::listarRecentes(10),
            'podeGerenciar' => Authorization::temPermissao('integracao_colaborador.editar'),
            'flashError' => $flashError,
            'flashSuccess' => $flashSuccess,
        ], 'layouts/admin');
    }
}
