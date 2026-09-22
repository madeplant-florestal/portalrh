<?php

/**
 * Administração da Pesquisa de Reação — Treinamento de Integração. Permissão individual é a
 * fonte efetiva de autorização (Admin só pelo bypass central já existente) — `.visualizar` para
 * listar/ver resultados, `.gerenciar` para gerar link/desativar campanha.
 */
class AdminPesquisaReacaoIntegracaoController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        // Central de Pesquisas de Integração: dois blocos com permissões INDEPENDENTES — a Pesquisa
        // de Reação (`pesquisa_reacao_integracao.visualizar`) e os resultados da Pesquisa de
        // Integração via QR (`integracao_colaborador.visualizar`). Sem nenhuma das duas, 403.
        $podeVerReacao = Authorization::temPermissao('pesquisa_reacao_integracao.visualizar');
        $podeVerIntegracao = Authorization::temPermissao('integracao_colaborador.visualizar');
        if (!$podeVerReacao && !$podeVerIntegracao) {
            Authorization::requirePermissao('pesquisa_reacao_integracao.visualizar');
        }

        $this->view->render('admin/pesquisa_reacao_integracao/index', [
            'campanhas' => $podeVerReacao ? PesquisaReacaoCampanha::listarComContagem() : [],
            'opcoesEmpresaSetor' => $podeVerReacao ? PesquisaReacaoCampanha::opcoesEmpresaSetor() : ['empresas' => [], 'setores' => []],
            'validadesRapidas' => PesquisaReacaoCampanha::VALIDADES_RAPIDAS,
            'podeVerReacao' => $podeVerReacao,
            'podeVerIntegracao' => $podeVerIntegracao,
            'resumoIntegracaoQr' => $podeVerIntegracao ? PesquisaIntegracaoResultadosService::resumoPorIntegracao() : [],
            'podeGerenciar' => $podeVerReacao && Authorization::temPermissao('pesquisa_reacao_integracao.gerenciar'),
            'linkGerado' => null,
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'formError' => '',
            'formValues' => [],
        ], 'layouts/app-shell');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('pesquisa_reacao_integracao.gerenciar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $resultado = PesquisaReacaoIntegracaoService::criarCampanha($_POST, $usuarioId);

        $dadosView = [
            'campanhas' => PesquisaReacaoCampanha::listarComContagem(),
            'opcoesEmpresaSetor' => PesquisaReacaoCampanha::opcoesEmpresaSetor(),
            'validadesRapidas' => PesquisaReacaoCampanha::VALIDADES_RAPIDAS,
            'podeVerReacao' => true,
            'podeVerIntegracao' => Authorization::temPermissao('integracao_colaborador.visualizar'),
            'resumoIntegracaoQr' => Authorization::temPermissao('integracao_colaborador.visualizar') ? PesquisaIntegracaoResultadosService::resumoPorIntegracao() : [],
            'podeGerenciar' => Authorization::temPermissao('pesquisa_reacao_integracao.gerenciar'),
            'linkGerado' => null,
            'flashError' => '',
            'flashSuccess' => '',
            'formError' => '',
            'formValues' => $_POST,
        ];

        if (!($resultado['ok'] ?? false)) {
            $dadosView['formError'] = (string)($resultado['error'] ?? 'Não foi possível gerar a campanha.');
            $this->view->render('admin/pesquisa_reacao_integracao/index', $dadosView, 'layouts/app-shell');
            return;
        }

        // Renderiza direto (nunca redireciona) para poder exibir o link gerado UMA ÚNICA VEZ — o
        // token bruto não é recuperável depois (mesmo padrão de PasswordReset/PesquisaIntegracao).
        $baseUrl = rtrim((string)(Config::app()['base_url'] ?? ''), '/');
        $dadosView['linkGerado'] = $baseUrl . '/pesquisa-reacao/' . $resultado['token'];
        $dadosView['formValues'] = [];
        $this->view->render('admin/pesquisa_reacao_integracao/index', $dadosView, 'layouts/app-shell');
    }

    public function desativar(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('pesquisa_reacao_integracao.gerenciar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $campanha = PesquisaReacaoCampanha::find((int)$id);
        if ($campanha === null) {
            redirect('/admin/pesquisas-reacao-integracao?erro=' . urlencode('Campanha não encontrada.'));
        }

        PesquisaReacaoCampanha::desativar((int)$id);
        redirect('/admin/pesquisas-reacao-integracao?ok=' . urlencode('Campanha desativada — não aceita mais novas respostas.'));
    }

    public function resultados(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('pesquisa_reacao_integracao.visualizar');

        $campanha = PesquisaReacaoCampanha::find((int)$id);
        if ($campanha === null) {
            http_response_code(404);
            echo 'Campanha não encontrada.';
            return;
        }

        $this->view->render('admin/pesquisa_reacao_integracao/resultados', [
            'campanha' => $campanha,
            'resultados' => PesquisaReacaoIntegracaoService::calcularResultados((int)$id),
        ], 'layouts/app-shell');
    }
}
