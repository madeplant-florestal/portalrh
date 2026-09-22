<?php

/**
 * Administração da Entrevista de Desligamento. Autorização por PERMISSÃO INDIVIDUAL (Admin só pelo bypass
 * central, nunca por role):
 *   - entrevista_desligamento.visualizar → módulo e situação operacional (nunca respostas);
 *   - entrevista_desligamento.gerenciar  → gerar, regenerar, cancelar;
 *   - entrevista_desligamento.resultados → resultado individual identificado e indicadores de respostas.
 *
 * O link bruto é exibido UMA ÚNICA VEZ, na própria resposta do POST de gerar/regenerar (renderiza direto,
 * sem redirect) — o token nunca é persistido nem recuperável depois.
 */
class AdminEntrevistaDesligamentoController extends Controller
{
    private const PERM_VISUALIZAR = 'entrevista_desligamento.visualizar';
    private const PERM_GERENCIAR = 'entrevista_desligamento.gerenciar';
    private const PERM_RESULTADOS = 'entrevista_desligamento.resultados';

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao(self::PERM_VISUALIZAR);

        $dias = ctype_digit((string)($_GET['dias'] ?? '')) ? (int)$_GET['dias'] : 90;
        $this->renderIndex([
            'visao' => (string)($_GET['visao'] ?? 'elegiveis'),
            'busca' => mb_substr(Security::sanitizeString($_GET['busca'] ?? ''), 0, 60),
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'dias' => in_array($dias, EntrevistaDesligamentoService::DIAS_FILTRO, true) ? $dias : 90,
        ], [
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
        ]);
    }

    public function gerar(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao(self::PERM_GERENCIAR);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $metadadosId = ctype_digit((string)($_POST['metadados_id'] ?? '')) ? (int)$_POST['metadados_id'] : 0;
        $resultado = (new EntrevistaDesligamentoService())->gerar($metadadosId, (int)($_SESSION['user_id'] ?? 0), new DateTimeImmutable('now'));
        $this->responderComLink($resultado, 'Não foi possível gerar a entrevista.');
    }

    public function regenerar(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao(self::PERM_GERENCIAR);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $resultado = (new EntrevistaDesligamentoService())->regenerar((int)$id, (int)($_SESSION['user_id'] ?? 0), new DateTimeImmutable('now'));
        $this->responderComLink($resultado, 'Não foi possível regenerar o link.');
    }

    public function cancelar(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao(self::PERM_GERENCIAR);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $resultado = (new EntrevistaDesligamentoService())->cancelar((int)$id, (int)($_SESSION['user_id'] ?? 0), new DateTimeImmutable('now'));
        if (!($resultado['ok'] ?? false)) {
            redirect('/admin/entrevistas-desligamento?visao=pendente&erro=' . urlencode((string)($resultado['error'] ?? 'Não foi possível cancelar.')));
        }
        redirect('/admin/entrevistas-desligamento?visao=cancelada&ok=' . urlencode('Entrevista cancelada — o link deixou de funcionar.'));
    }

    public function resultado(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao(self::PERM_RESULTADOS);

        $entrevista = (new EntrevistaDesligamentoService())->resultadoIndividual((int)$id, new DateTimeImmutable('now'));
        if ($entrevista === null) {
            http_response_code(404);
            echo 'Entrevista não encontrada.';
            return;
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store');
        }
        $this->view->render('admin/entrevista_desligamento/resultado', ['entrevista' => $entrevista], 'layouts/app-shell');
    }

    /** Falha → volta à lista com a mensagem; sucesso → renderiza a lista de pendentes com o link (única exibição). */
    private function responderComLink(array $resultado, string $erroPadrao): void
    {
        if (!($resultado['ok'] ?? false)) {
            $this->renderIndex(['visao' => 'pendente'], ['flashError' => (string)($resultado['error'] ?? $erroPadrao)]);
            return;
        }
        $baseUrl = rtrim((string)(Config::app()['base_url'] ?? ''), '/');
        $this->renderIndex(['visao' => 'pendente'], ['linkGerado' => [
            'url' => $baseUrl . '/entrevista-desligamento/' . $resultado['token'],
            'nome' => (string)($resultado['nome'] ?? ''),
            'expira_em' => (string)($resultado['expira_em'] ?? ''),
        ]]);
    }

    private function renderIndex(array $filtros, array $extra = []): void
    {
        $agora = new DateTimeImmutable('now');
        $service = new EntrevistaDesligamentoService();
        $podeResultados = Authorization::temPermissao(self::PERM_RESULTADOS);

        $erro = null;
        $painel = null;
        $indicadores = null;
        try {
            $painel = $service->montarPainel($filtros, $agora);
            $indicadores = $service->indicadores($agora, $podeResultados);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminEntrevistaDesligamentoController']);
            $erro = 'Não foi possível carregar as Entrevistas de Desligamento agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/entrevista_desligamento/index', $extra + [
            'painel' => $painel,
            'indicadores' => $indicadores,
            'erro' => $erro,
            'filtros' => $filtros + ['busca' => '', 'codigo_empresa' => '', 'dias' => 90],
            'podeGerenciar' => Authorization::temPermissao(self::PERM_GERENCIAR),
            'podeResultados' => $podeResultados,
            'agora' => $agora,
            'linkGerado' => null,
            'flashSuccess' => '',
            'flashError' => '',
        ], 'layouts/app-shell');
    }
}
