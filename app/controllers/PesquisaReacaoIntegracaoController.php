<?php

/**
 * Página pública da Pesquisa de Reação — Treinamento de Integração — sem login, acessada por
 * token aleatório (nunca sequencial/derivado de CPF/ID/e-mail). Mesmo padrão de segurança de
 * PesquisaIntegracaoController, mas com uma diferença deliberada: uma campanha aceita N respostas
 * (nunca há gate de "já foi respondida" — cada envio é uma participação independente, e pode ser
 * anônima).
 *
 * Empresa/Setor/Data da Integração vêm SEMPRE da campanha resolvida pelo token — nunca do body do
 * POST, que não é uma fonte confiável para esse contexto.
 */
class PesquisaReacaoIntegracaoController extends Controller
{
    public function show(string $token): void
    {
        $campanha = PesquisaReacaoCampanha::findByRawToken($token);

        if ($_GET['enviado'] ?? '' === '1') {
            // Pós-redirect do POST bem-sucedido (PRG) — evita repost acidental por refresh do
            // navegador. Mostra "obrigado" mesmo que a campanha tenha expirado/desativado no
            // instante seguinte ao envio (a resposta já foi gravada antes disso).
            $this->view->render('pesquisa_reacao/show', ['estado' => 'obrigado', 'token' => $token, 'campanha' => null, 'csrf' => '', 'error' => '']);
            return;
        }

        if ($campanha === null) {
            http_response_code(404);
            $this->view->render('pesquisa_reacao/show', ['estado' => 'invalido', 'token' => $token, 'campanha' => null, 'csrf' => '', 'error' => '']);
            return;
        }

        if (!PesquisaReacaoCampanha::aceitaRespostas($campanha)) {
            $this->view->render('pesquisa_reacao/show', ['estado' => 'encerrada', 'token' => $token, 'campanha' => $campanha, 'csrf' => '', 'error' => '']);
            return;
        }

        $this->view->render('pesquisa_reacao/show', [
            'estado' => 'formulario',
            'token' => $token,
            'campanha' => $campanha,
            'csrf' => Security::csrfToken(),
            'error' => '',
        ]);
    }

    public function store(string $token): void
    {
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $campanha = PesquisaReacaoCampanha::findByRawToken($token);
        if ($campanha === null) {
            http_response_code(404);
            $this->view->render('pesquisa_reacao/show', ['estado' => 'invalido', 'token' => $token, 'campanha' => null, 'csrf' => '', 'error' => '']);
            return;
        }

        // Validado SEMPRE no backend, tanto no GET (exibição) quanto aqui no POST — nunca confia
        // que o navegador respeitou o estado visto na tela anterior.
        if (!PesquisaReacaoCampanha::aceitaRespostas($campanha)) {
            $this->view->render('pesquisa_reacao/show', ['estado' => 'encerrada', 'token' => $token, 'campanha' => $campanha, 'csrf' => '', 'error' => '']);
            return;
        }

        $resultado = PesquisaReacaoIntegracaoService::registrarResposta($campanha, $_POST);
        if (!($resultado['ok'] ?? false)) {
            $this->view->render('pesquisa_reacao/show', [
                'estado' => 'formulario',
                'token' => $token,
                'campanha' => $campanha,
                'csrf' => Security::csrfToken(),
                'error' => (string)($resultado['error'] ?? 'Não foi possível registrar sua resposta.'),
            ]);
            return;
        }

        // POST/Redirect/GET: evita reenvio duplicado por F5 na tela de agradecimento.
        redirect('/pesquisa-reacao/' . $token . '?enviado=1');
    }
}
