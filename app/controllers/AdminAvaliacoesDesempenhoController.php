<?php

/**
 * Avaliação de Desempenho NATIVA (Etapa 6, 2026-09). Mesma convenção de autorização de
 * AdminAvaliacoesExperienciaController/AdminFeedbacksController/AdminPdisController: sem
 * Auth::requireRole(), só permissão individual + escopo por linha (gestor responsável).
 * NÃO usa/depende do legado `/admin/avaliacoes` (AdminAvaliacoesController) — ver AvaliacaoDesempenhoService.
 */
class AdminAvaliacoesDesempenhoController
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
        Authorization::requirePermissao('avaliacao_desempenho.visualizar');

        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $service = new AvaliacaoDesempenhoService();
        $filtros = [
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
            'busca' => Security::sanitizeString($_GET['busca'] ?? ''),
        ];
        $erro = null;
        $itens = [];
        try {
            $itens = $service->listar($filtros, $ator);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminAvaliacoesDesempenhoController']);
            $erro = 'Não foi possível carregar as Avaliações de Desempenho agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/avaliacoes-desempenho/index', [
            'itens' => $itens, 'erro' => $erro, 'filtros' => $filtros,
            'podeCriar' => Authorization::temPermissao('avaliacao_desempenho.avaliar'),
            'flashOk' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function novo(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('avaliacao_desempenho.avaliar');

        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $service = new AvaliacaoDesempenhoService();
        $metadadosId = (int)($_GET['metadados_id'] ?? 0);
        $busca = Security::sanitizeString($_GET['busca'] ?? '');
        $contrato = null;
        $gestorSugerido = null;
        $avaliacoesAnteriores = [];
        if ($metadadosId > 0) {
            $ctx = $service->novoParaContrato($metadadosId, $ator);
            if ($ctx['ok'] ?? false) {
                $contrato = $ctx['contrato'];
                $gestorSugerido = $ctx['gestor'];
                $avaliacoesAnteriores = $ctx['avaliacoesAnteriores'];
            }
        }

        $this->view->render('admin/avaliacoes-desempenho/form', [
            'modo' => 'criar', 'avaliacao' => null, 'criterios' => [], 'indicadores' => null, 'ciencias' => [], 'eventos' => [],
            'contrato' => $contrato, 'gestorSugerido' => $gestorSugerido, 'avaliacoesAnteriores' => $avaliacoesAnteriores,
            'contratosBusca' => $busca !== '' ? $service->buscarContratos($busca) : [],
            'busca' => $busca,
            'usuarios' => $service->usuariosParaEscolha($ator),
            'escopoTotal' => AvaliacaoDesempenhoService::escopoTotal($ator),
            'podeAvaliar' => Authorization::temPermissao('avaliacao_desempenho.avaliar'),
            'erro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function store(): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $result = (new AvaliacaoDesempenhoService())->criar($_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        if (!($result['ok'] ?? false)) {
            $qs = http_build_query(['metadados_id' => $_POST['metadados_id'] ?? '', 'erro' => (string)($result['error'] ?? 'Erro.')]);
            redirect('/admin/avaliacoes-desempenho/novo?' . $qs);
        }
        redirect('/admin/avaliacoes-desempenho/' . (int)$result['id'] . '/editar?ok=' . urlencode('Rascunho criado.'));
    }

    public function editar(string $id): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('avaliacao_desempenho.visualizar');

        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $service = new AvaliacaoDesempenhoService();
        $detalhe = $service->detalhe((int)$id, $ator);
        if ($detalhe === null) {
            redirect('/admin/avaliacoes-desempenho?erro=' . urlencode('Avaliação não encontrada.'));
        }

        $this->view->render('admin/avaliacoes-desempenho/form', [
            'modo' => 'editar', 'avaliacao' => $detalhe['avaliacao'], 'criterios' => $detalhe['criterios'],
            'indicadores' => $detalhe['indicadores'], 'ciencias' => $detalhe['ciencias'], 'eventos' => $detalhe['eventos'],
            'contrato' => null, 'gestorSugerido' => null, 'avaliacoesAnteriores' => [], 'contratosBusca' => [], 'busca' => '',
            'usuarios' => [], 'escopoTotal' => AvaliacaoDesempenhoService::escopoTotal($ator),
            'podeAvaliar' => Authorization::temPermissao('avaliacao_desempenho.avaliar'),
            'podeGerarPdi' => Authorization::temPermissao('pdi.gerenciar'),
            'pdisRelacionados' => (new PdiRepository())->pdisPorOrigem('avaliacao_desempenho', (int)$id),
            'erro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function atualizar(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $result = (new AvaliacaoDesempenhoService())->atualizar((int)$id, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/avaliacoes-desempenho/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Rascunho salvo.'))));
    }

    public function concluir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $result = (new AvaliacaoDesempenhoService())->concluir((int)$id, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/avaliacoes-desempenho/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Avaliação concluída.'))));
    }

    public function cancelar(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $result = (new AvaliacaoDesempenhoService())->cancelar((int)$id, (string)($_POST['motivo'] ?? ''), $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/avaliacoes-desempenho/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Avaliação cancelada.'))));
    }

    public function ciencia(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $avaliacao = (new AvaliacaoDesempenhoRepository())->buscarPorId((int)$id);
        $nomeColaborador = Security::sanitizeString($_POST['nome_colaborador'] ?? ($avaliacao['snap_nome'] ?? ''));
        $result = (new AvaliacaoDesempenhoService())->registrarCienciaColaborador((int)$id, $nomeColaborador, $ator, new DateTimeImmutable('now'));
        $destino = '/admin/avaliacoes-desempenho/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Ciência registrada.'))));
    }

    public function reabrir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoDesempenhoService::atorDaSessao();
        $result = (new AvaliacaoDesempenhoService())->reabrir((int)$id, (string)($_POST['justificativa'] ?? ''), $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = '/admin/avaliacoes-desempenho/' . (int)$id . '/editar';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Avaliação reaberta.'))));
    }
}
