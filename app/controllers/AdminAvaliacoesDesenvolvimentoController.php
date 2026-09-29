<?php

/**
 * Resumo da área "Avaliações e Desenvolvimento" (§55 da Etapa 4, 2026-09) — reaproveita
 * INTEGRALMENTE AvaliacaoExperienciaService::listarPendencias()/FeedbackService::listar(), nenhum
 * cálculo paralelo. Não integra ainda ao People Analytics (§65) nem realinha a Central (§64).
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

        $contagemExperiencia = array_fill_keys(array_keys(AvaliacaoExperienciaService::ROTULOS_STATUS), 0);
        $feedbacksPorStatus = ['rascunho' => 0, 'concluido' => 0];
        $feedbacksDesenvolvimento = 0;
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
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminAvaliacoesDesenvolvimentoController']);
            $erro = 'Não foi possível carregar o resumo agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/avaliacoes-desenvolvimento/index', [
            'erro' => $erro, 'temExperiencia' => $temExperiencia, 'temFeedback' => $temFeedback,
            'contagemExperiencia' => $contagemExperiencia, 'feedbacksPorStatus' => $feedbacksPorStatus,
            'feedbacksDesenvolvimento' => $feedbacksDesenvolvimento,
        ], 'layouts/app-shell');
    }
}
