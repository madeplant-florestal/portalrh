<?php

/**
 * Resumo da área "Avaliações e Desenvolvimento" (§55 da Etapa 4, 2026-09; Avaliação de Desempenho
 * nativa adicionada na Etapa 6) — reaproveita INTEGRALMENTE AvaliacaoExperienciaService::
 * listarPendencias()/FeedbackService::listar()/AvaliacaoDesempenhoService::listar(), nenhum cálculo
 * paralelo. Não integra ainda ao People Analytics (§65).
 */
class AdminAvaliacoesDesenvolvimentoController
{
    private View $view;

    public function __construct()
    {
        $this->view = new View();
    }

    public function index(): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }

        $ator = AvaliacaoExperienciaService::atorDaSessao();
        $hoje = new DateTimeImmutable('today');
        $temExperiencia = Authorization::temPermissao('avaliacao_experiencia.visualizar');
        $temFeedback = Authorization::temPermissao('feedback.visualizar');
        $temPdi = Authorization::temPermissao('pdi.visualizar');
        $temDesempenho = Authorization::temPermissao('avaliacao_desempenho.visualizar');

        $contagemExperiencia = array_fill_keys(array_keys(AvaliacaoExperienciaService::ROTULOS_STATUS), 0);
        $feedbacksPorStatus = ['rascunho' => 0, 'concluido' => 0];
        $feedbacksDesenvolvimento = 0;
        $desempenhoPorStatus = array_fill_keys(array_keys(AvaliacaoDesempenhoService::ROTULOS_STATUS), 0);
        $erro = null;
        try {
            if ($temExperiencia) {
                $contagemExperiencia = (new AvaliacaoExperienciaService())->listarPendencias([], $ator, $hoje)['contagem'];
            }
            if ($temFeedback) {
                foreach ((new FeedbackService())->listar([], $ator) as $f) {
                    $feedbacksPorStatus[$f['status']] = ($feedbacksPorStatus[$f['status']] ?? 0) + 1;
                    if (($f['resultado_geral'] ?? null) === 'necessita_acompanhamento') {
                        $feedbacksDesenvolvimento++;
                    }
                }
            }
            if ($temDesempenho) {
                foreach ((new AvaliacaoDesempenhoService())->listar([], $ator) as $a) {
                    $desempenhoPorStatus[$a['status']] = ($desempenhoPorStatus[$a['status']] ?? 0) + 1;
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminAvaliacoesDesenvolvimentoController']);
            $erro = 'Não foi possível carregar o resumo agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/avaliacoes-desenvolvimento/index', [
            'erro' => $erro, 'temExperiencia' => $temExperiencia, 'temFeedback' => $temFeedback,
            'temPdi' => $temPdi, 'temDesempenho' => $temDesempenho,
            'contagemExperiencia' => $contagemExperiencia, 'feedbacksPorStatus' => $feedbacksPorStatus,
            'feedbacksDesenvolvimento' => $feedbacksDesenvolvimento, 'desempenhoPorStatus' => $desempenhoPorStatus,
        ], 'layouts/app-shell');
    }
}
