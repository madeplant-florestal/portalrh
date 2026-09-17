<?php

/**
 * Página pública da Pesquisa de Integração — sem login, acessada por token aleatório (nunca
 * sequencial/derivado de CPF/ID/e-mail). Mesmo padrão de segurança de PesquisaExperienciaController.
 */
class PesquisaIntegracaoController extends Controller
{
    public function show(string $token): void
    {
        SchemaManager::ensure();
        PesquisaIntegracao::ensureSchema();
        $pesquisa = PesquisaIntegracao::findByRawToken($token);
        if ($pesquisa === null) {
            http_response_code(404);
            $this->view->render('pesquisa_integracao/show', ['encontrada' => false, 'concluida' => false, 'csrf' => '', 'token' => $token, 'error' => '']);
            return;
        }

        $this->view->render('pesquisa_integracao/show', [
            'encontrada' => true,
            'concluida' => !empty($pesquisa['respondida_em']),
            'csrf' => Security::csrfToken(),
            'token' => $token,
            'error' => '',
        ]);
    }

    public function store(string $token): void
    {
        SchemaManager::ensure();
        PesquisaIntegracao::ensureSchema();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $pesquisa = PesquisaIntegracao::findByRawToken($token);
        if ($pesquisa === null) {
            http_response_code(404);
            $this->view->render('pesquisa_integracao/show', ['encontrada' => false, 'concluida' => false, 'csrf' => '', 'token' => $token, 'error' => '']);
            return;
        }
        if (!empty($pesquisa['respondida_em'])) {
            $this->view->render('pesquisa_integracao/show', ['encontrada' => true, 'concluida' => true, 'csrf' => Security::csrfToken(), 'token' => $token, 'error' => '']);
            return;
        }

        $notaNps = $this->parseNota($_POST['nota_nps'] ?? null, 0, 10);
        $notaClareza = $this->parseNota($_POST['nota_clareza'] ?? null, 1, 5);
        $notaAcolhimento = $this->parseNota($_POST['nota_acolhimento'] ?? null, 1, 5);
        $notaNormas = $this->parseNota($_POST['nota_normas'] ?? null, 1, 5);
        $notaUtilidade = $this->parseNota($_POST['nota_utilidade'] ?? null, 1, 5);
        $notaSatisfacaoGeral = $this->parseNota($_POST['nota_satisfacao_geral'] ?? null, 1, 5);
        $comentarios = Security::sanitizeString($_POST['comentarios'] ?? '');

        if (
            $notaNps === null || $notaClareza === null || $notaAcolhimento === null
            || $notaNormas === null || $notaUtilidade === null || $notaSatisfacaoGeral === null
        ) {
            $this->view->render('pesquisa_integracao/show', [
                'encontrada' => true,
                'concluida' => false,
                'csrf' => Security::csrfToken(),
                'token' => $token,
                'error' => 'Responda a nota de recomendação (0 a 10) e todas as perguntas de satisfação (1 a 5) antes de enviar.',
            ]);
            return;
        }

        $result = PesquisaIntegracao::responder(
            (int)$pesquisa['id'],
            $notaNps,
            $notaClareza,
            $notaAcolhimento,
            $notaNormas,
            $notaUtilidade,
            $notaSatisfacaoGeral,
            $comentarios
        );
        if (!($result['ok'] ?? false)) {
            $this->view->render('pesquisa_integracao/show', [
                'encontrada' => true,
                'concluida' => false,
                'csrf' => Security::csrfToken(),
                'token' => $token,
                'error' => (string)($result['error'] ?? 'Não foi possível registrar sua avaliação.'),
            ]);
            return;
        }

        $this->view->render('pesquisa_integracao/show', ['encontrada' => true, 'concluida' => true, 'csrf' => Security::csrfToken(), 'token' => $token, 'error' => '']);
    }

    /** Nota inteira dentro de [$min, $max]. Qualquer outra coisa (vazio, fora da faixa, texto) é rejeitada. */
    private function parseNota($valor, int $min, int $max): ?int
    {
        if (!is_scalar($valor) || !ctype_digit((string)$valor)) {
            return null;
        }
        $n = (int)$valor;
        return ($n >= $min && $n <= $max) ? $n : null;
    }
}
