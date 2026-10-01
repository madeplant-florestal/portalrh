<?php

/**
 * Formulário de Feedback e Desenvolvimento (Etapa 4, 2026-09). Mesma convenção de autorização de
 * AdminAvaliacoesExperienciaController/AdminPdisController: sem Auth::requireRole(), só permissão
 * individual + escopo por linha.
 */
class AdminFeedbacksController
{
    private View $view;

    public function __construct()
    {
        $this->view = new View();
    }

    private function exigirLogin(): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }
    }

    public function index(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('feedback.visualizar');

        $ator = FeedbackService::atorDaSessao();
        $service = new FeedbackService();
        $filtros = [
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
            'tipo' => Security::sanitizeString($_GET['tipo'] ?? ''),
            'busca' => Security::sanitizeString($_GET['busca'] ?? ''),
        ];
        $erro = null;
        $itens = [];
        try {
            $itens = $service->listar($filtros, $ator);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminFeedbacksController']);
            $erro = 'Não foi possível carregar os Feedbacks agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/feedbacks/index', [
            'itens' => $itens, 'erro' => $erro, 'filtros' => $filtros,
            'podeCriar' => Authorization::temPermissao('feedback.avaliar'),
            'flashOk' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function novo(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('feedback.avaliar');

        $ator = FeedbackService::atorDaSessao();
        $service = new FeedbackService();
        $metadadosId = (int)($_GET['metadados_id'] ?? 0);
        $busca = Security::sanitizeString($_GET['busca'] ?? '');
        $contrato = null;
        $gestorSugerido = null;
        if ($metadadosId > 0) {
            $ctx = $service->contratoParaCriacao($metadadosId, $ator);
            if ($ctx['ok'] ?? false) {
                $contrato = $ctx['contrato'];
                $gestorSugerido = $ctx['gestor'];
            }
        }

        $this->view->render('admin/feedbacks/form', [
            'modo' => 'criar', 'feedback' => null, 'valores' => [], 'ciencias' => [], 'eventos' => [],
            'contrato' => $contrato, 'gestorSugerido' => $gestorSugerido,
            'contratosBusca' => $busca !== '' ? $service->buscarContratos($busca) : [],
            'busca' => $busca,
            'usuarios' => $service->usuariosParaEscolha($ator),
            'escopoTotal' => FeedbackService::escopoTotal($ator),
            'erro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function store(): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $result = (new FeedbackService())->criar($_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        if (!($result['ok'] ?? false)) {
            $qs = http_build_query(['metadados_id' => $_POST['metadados_id'] ?? '', 'erro' => (string)($result['error'] ?? 'Erro.')]);
            redirect('/admin/feedbacks/novo?' . $qs);
        }
        redirect('/admin/feedbacks/' . (int)$result['id'] . '/editar?ok=' . urlencode('Rascunho criado.'));
    }

    public function editar(string $id): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('feedback.visualizar');

        $ator = FeedbackService::atorDaSessao();
        $service = new FeedbackService();
        $detalhe = $service->detalhe((int)$id, $ator);
        if ($detalhe === null) {
            redirect('/admin/feedbacks?erro=' . urlencode('Feedback não encontrado.'));
        }

        $this->view->render('admin/feedbacks/form', [
            'modo' => 'editar', 'feedback' => $detalhe['feedback'], 'valores' => $detalhe['valores'],
            'ciencias' => $detalhe['ciencias'], 'eventos' => $detalhe['eventos'],
            'contrato' => null, 'gestorSugerido' => null, 'contratosBusca' => [], 'busca' => '',
            'usuarios' => [], 'escopoTotal' => FeedbackService::escopoTotal($ator),
            'podeAvaliar' => Authorization::temPermissao('feedback.avaliar'),
            'podeGerarPdi' => Authorization::temPermissao('pdi.gerenciar'),
            'pdisRelacionados' => (new PdiRepository())->pdisPorOrigem('feedback', (int)$id),
            'erro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function atualizar(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $result = (new FeedbackService())->atualizar((int)$id, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/feedbacks/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Rascunho salvo.'))));
    }

    public function concluir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $result = (new FeedbackService())->concluir((int)$id, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/feedbacks/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Feedback concluído.'))));
    }

    public function espacoColaborador(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $result = (new FeedbackService())->registrarEspacoColaborador((int)$id, (string)($_POST['espaco_colaborador'] ?? ''), $ator, new DateTimeImmutable('now'));
        $destino = '/admin/feedbacks/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Espaço do colaborador registrado.'))));
    }

    public function ciencia(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $feedback = (new FeedbackRepository())->buscarPorId((int)$id);
        $nomeColaborador = Security::sanitizeString($_POST['nome_colaborador'] ?? ($feedback['snap_nome'] ?? ''));
        $result = (new FeedbackService())->registrarCienciaColaborador((int)$id, $nomeColaborador, $ator, new DateTimeImmutable('now'));
        $destino = '/admin/feedbacks/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Ciência registrada.'))));
    }

    public function reabrir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = FeedbackService::atorDaSessao();
        $result = (new FeedbackService())->reabrir((int)$id, (string)($_POST['justificativa'] ?? ''), $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/feedbacks/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Feedback reaberto.'))));
    }
}
