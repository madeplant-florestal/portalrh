<?php
/**
 * Página PÚBLICA da Pesquisa de Experiência do Candidato (Sprint "Experiência do Candidato") —
 * sem login, acessada via token aleatório (`/experiencia/{token}`). Nunca expõe nome, CPF,
 * telefone, e-mail, vaga ou qualquer outro dado interno do candidato/processo — só o formulário
 * de avaliação em si (ver §26 da sprint).
 */
class PesquisaExperienciaController extends Controller
{
    public function show(string $token): void
    {
        SchemaManager::ensure();
        PesquisaExperiencia::ensureSchema();
        $pesquisa = PesquisaExperiencia::findByRawToken($token);
        if ($pesquisa === null) {
            http_response_code(404);
            $this->view->render('pesquisa_experiencia/show', ['encontrada' => false, 'concluida' => false, 'csrf' => '', 'token' => $token, 'error' => '']);
            return;
        }

        $this->view->render('pesquisa_experiencia/show', [
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
        PesquisaExperiencia::ensureSchema();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $pesquisa = PesquisaExperiencia::findByRawToken($token);
        if ($pesquisa === null) {
            http_response_code(404);
            $this->view->render('pesquisa_experiencia/show', ['encontrada' => false, 'concluida' => false, 'csrf' => '', 'token' => $token, 'error' => '']);
            return;
        }
        if (!empty($pesquisa['respondida_em'])) {
            $this->view->render('pesquisa_experiencia/show', ['encontrada' => true, 'concluida' => true, 'csrf' => Security::csrfToken(), 'token' => $token, 'error' => '']);
            return;
        }

        $notaClareza = $this->parseNota($_POST['nota_clareza'] ?? null);
        $notaTempoRetorno = $this->parseNota($_POST['nota_tempo_retorno'] ?? null);
        $notaAtendimento = $this->parseNota($_POST['nota_atendimento'] ?? null);
        $comentarios = Security::sanitizeString($_POST['comentarios'] ?? '');

        if ($notaClareza === null || $notaTempoRetorno === null || $notaAtendimento === null) {
            $this->view->render('pesquisa_experiencia/show', [
                'encontrada' => true,
                'concluida' => false,
                'csrf' => Security::csrfToken(),
                'token' => $token,
                'error' => 'Avalie os três critérios com uma nota de 1 a 5 antes de enviar.',
            ]);
            return;
        }

        $result = PesquisaExperiencia::responder((int)$pesquisa['id'], $notaClareza, $notaTempoRetorno, $notaAtendimento, $comentarios);
        if (!($result['ok'] ?? false)) {
            $this->view->render('pesquisa_experiencia/show', [
                'encontrada' => true,
                'concluida' => false,
                'csrf' => Security::csrfToken(),
                'token' => $token,
                'error' => (string)($result['error'] ?? 'Não foi possível registrar sua avaliação.'),
            ]);
            return;
        }

        $this->view->render('pesquisa_experiencia/show', ['encontrada' => true, 'concluida' => true, 'csrf' => Security::csrfToken(), 'token' => $token, 'error' => '']);
    }

    /** Nota inteira 1–5. Qualquer outra coisa (vazio, 0, 6, texto) é rejeitada. */
    private function parseNota($valor): ?int
    {
        if (!is_scalar($valor) || !ctype_digit((string)$valor)) {
            return null;
        }
        $n = (int)$valor;
        return ($n >= 1 && $n <= 5) ? $n : null;
    }
}
