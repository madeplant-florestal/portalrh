<?php

/**
 * Avaliação do Período de Experiência (45/90 dias — Etapa 4, 2026-09). Deliberadamente NÃO usa
 * Auth::requireRole() (mesma razão do PDI): autorização é só permissão individual (Admin pelo bypass
 * central) + escopo por linha (gestor responsável).
 */
class AdminAvaliacoesExperienciaController
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
        Authorization::requirePermissao('avaliacao_experiencia.visualizar');

        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $hoje = new DateTimeImmutable('today');
        $service = new AvaliacaoExperienciaService();
        $filtros = [
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
            'tipo' => Security::sanitizeString($_GET['tipo'] ?? ''),
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_setor' => Security::sanitizeString($_GET['setor'] ?? ''),
        ];
        $erro = null;
        $lista = ['itens' => [], 'contagem' => array_fill_keys(array_keys(AvaliacaoExperienciaService::ROTULOS_STATUS), 0)];
        $opcoes = ['empresas' => [], 'setores' => []];
        try {
            $opcoes = $service->opcoesFiltro();
            $lista = $service->listarPendencias($filtros, $ator, $hoje);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminAvaliacoesExperienciaController']);
            $erro = 'Não foi possível carregar as Avaliações de Experiência agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/avaliacoes-experiencia/index', [
            'lista' => $lista, 'erro' => $erro, 'filtros' => $filtros, 'opcoes' => $opcoes, 'hoje' => $hoje, 'ator' => $ator,
            'flashOk' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function form(string $metadadosId, string $tipo): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('avaliacao_experiencia.visualizar');

        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $service = new AvaliacaoExperienciaService();
        $ctx = $service->contratoParaAvaliar((int)$metadadosId, $tipo, $ator);
        if (!($ctx['ok'] ?? false)) {
            redirect('/admin/avaliacoes-experiencia?erro=' . urlencode((string)($ctx['error'] ?? 'Não encontrado.')));
        }

        $this->view->render('admin/avaliacoes-experiencia/form', [
            'ctx' => $ctx, 'ator' => $ator,
            'podeAvaliar' => Authorization::temPermissao('avaliacao_experiencia.avaliar'),
            'escopoTotal' => AvaliacaoExperienciaService::escopoTotal($ator),
            'erro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function salvar(string $metadadosId, string $tipo): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $result = (new AvaliacaoExperienciaService())->salvarRascunho((int)$metadadosId, $tipo, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        if (!($result['ok'] ?? false)) {
            redirect('/admin/avaliacoes-experiencia/' . (int)$metadadosId . '/' . $tipo . '?erro=' . urlencode((string)($result['error'] ?? 'Erro.')));
        }
        redirect('/admin/avaliacoes-experiencia/' . (int)$metadadosId . '/' . $tipo . '?ok=' . urlencode('Rascunho salvo.'));
    }

    public function concluir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $avaliacaoId = (int)$id;
        $service = new AvaliacaoExperienciaService();
        $detalhe = $service->detalhe($avaliacaoId, $ator);
        $result = $service->concluir($avaliacaoId, $_POST, $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = $detalhe !== null ? '/admin/avaliacoes-experiencia/' . (int)$detalhe['avaliacao']['metadados_id'] . '/' . (string)$detalhe['avaliacao']['tipo'] : '/admin/avaliacoes-experiencia';
        if (!($result['ok'] ?? false)) {
            redirect($destino . '?erro=' . urlencode((string)($result['error'] ?? 'Erro.')));
        }
        redirect($destino . '?ok=' . urlencode('Avaliação concluída.'));
    }

    public function ciencia(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $avaliacaoId = (int)$id;
        $service = new AvaliacaoExperienciaService();
        $detalhe = $service->detalhe($avaliacaoId, $ator);
        $nomeColaborador = Security::sanitizeString($_POST['nome_colaborador'] ?? ($detalhe['avaliacao']['snap_nome'] ?? ''));
        $result = $service->registrarCienciaColaborador($avaliacaoId, $nomeColaborador, $ator, new DateTimeImmutable('now'));
        $destino = $detalhe !== null ? '/admin/avaliacoes-experiencia/' . (int)$detalhe['avaliacao']['metadados_id'] . '/' . (string)$detalhe['avaliacao']['tipo'] : '/admin/avaliacoes-experiencia';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Ciência registrada.'))));
    }

    public function reabrir(string $id): void
    {
        $this->exigirLogin();
        Security::csrfCheck($_POST['csrf'] ?? '');
        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $avaliacaoId = (int)$id;
        $service = new AvaliacaoExperienciaService();
        $detalhe = $service->detalhe($avaliacaoId, $ator);
        $result = $service->reabrir($avaliacaoId, (string)($_POST['justificativa'] ?? ''), $ator, new DateTimeImmutable('now'), $_SERVER['REMOTE_ADDR'] ?? null);
        $destino = $detalhe !== null ? '/admin/avaliacoes-experiencia/' . (int)$detalhe['avaliacao']['metadados_id'] . '/' . (string)$detalhe['avaliacao']['tipo'] : '/admin/avaliacoes-experiencia';
        redirect($destino . ((!($result['ok'] ?? false)) ? ('?erro=' . urlencode((string)($result['error'] ?? 'Erro.'))) : ('?ok=' . urlencode('Avaliação reaberta.'))));
    }
}
