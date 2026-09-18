<?php

/**
 * Páginas PÚBLICAS do fluxo coletivo (QR Code) da Pesquisa de Integração — sem login. O QR aponta
 * sempre para `/integracao` (URL estável, sem nenhum dado pessoal). Etapas: 1) CPF + Data de
 * Nascimento, 2) confirmação de Nome + Cargo + Empresa (e escolha do vínculo se houver mais de um
 * contrato ativo), 3) Pesquisa, 4) agradecimento.
 *
 * Distinto de PesquisaIntegracaoController (`/integracao/{token}`, fluxo individual gerado pelo RH,
 * que continua funcionando integralmente). As rotas estáticas abaixo têm precedência no Router
 * sobre `/integracao/{token}` (tokens reais são 64 hex e nunca coincidem com esses segmentos).
 *
 * Nenhuma página coloca CPF/nascimento em URL, cookie ou log; falhas de identificação usam sempre
 * a mesma mensagem genérica.
 */
class PesquisaIntegracaoQrController extends Controller
{
    /** GET /integracao — sempre começa do zero (descarta qualquer identificação anterior). */
    public function inicio(): void
    {
        PesquisaIntegracaoQrService::limparContexto();
        $this->renderIdentificacao('');
    }

    /** POST /integracao/identificar */
    public function identificar(): void
    {
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $sessao = SessaoIntegracao::abertaAtual();
        if ($sessao === null) {
            $this->render('indisponivel');
            return;
        }

        $espera = PesquisaIntegracaoQrService::segundosBloqueado();
        if ($espera > 0) {
            http_response_code(429);
            $this->renderIdentificacao('Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.');
            return;
        }

        $resultado = PesquisaIntegracaoQrService::identificar((string)($_POST['cpf'] ?? ''), (string)($_POST['nascimento'] ?? ''));
        if (!($resultado['ok'] ?? false)) {
            if (($resultado['motivo'] ?? '') === 'formato') {
                $this->renderIdentificacao('Informe um CPF válido e uma data de nascimento válida.');
                return;
            }
            PesquisaIntegracaoQrService::registrarFalhaIdentificacao();
            $this->renderIdentificacao(PesquisaIntegracaoQrService::MSG_FALHA_IDENTIFICACAO);
            return;
        }

        PesquisaIntegracaoQrService::iniciarContexto($resultado['contratos'], (int)$sessao['id']);
        redirect('/integracao/confirmar');
    }

    /** GET /integracao/confirmar */
    public function confirmar(): void
    {
        $ctx = PesquisaIntegracaoQrService::contextoAtivo();
        if ($ctx === null) {
            redirect('/integracao');
        }
        $contratos = PesquisaIntegracaoQrService::contratosDoContexto($ctx);
        if ($contratos === []) {
            PesquisaIntegracaoQrService::limparContexto();
            redirect('/integracao');
        }
        $this->render('confirmacao', ['contratos' => $contratos, 'nome' => (string)$contratos[0]['nome']]);
    }

    /** POST /integracao/confirmar */
    public function confirmarSelecao(): void
    {
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $ctx = PesquisaIntegracaoQrService::contextoAtivo();
        if ($ctx === null) {
            redirect('/integracao');
        }

        // Um único vínculo é selecionado automaticamente ao identificar; havendo vários, o
        // navegador só envia qual deles — o backend aceita apenas ids do conjunto já validado.
        $escolhido = ctype_digit((string)($_POST['contrato_id'] ?? '')) ? (int)$_POST['contrato_id'] : ($ctx['selecionado'] ?? 0);
        if (!PesquisaIntegracaoQrService::selecionarContrato((int)$escolhido)) {
            $contratos = PesquisaIntegracaoQrService::contratosDoContexto($ctx);
            if ($contratos === []) {
                PesquisaIntegracaoQrService::limparContexto();
                redirect('/integracao');
            }
            $this->render('confirmacao', [
                'contratos' => $contratos,
                'nome' => (string)$contratos[0]['nome'],
                'error' => 'Selecione o vínculo correspondente a esta integração.',
            ]);
            return;
        }
        redirect('/integracao/responder');
    }

    /** GET /integracao/responder */
    public function responder(): void
    {
        $ctx = PesquisaIntegracaoQrService::contextoAtivo();
        $selecionado = (int)($ctx['selecionado'] ?? 0);
        $contrato = $ctx !== null && $selecionado > 0 && in_array($selecionado, $ctx['candidatos'], true)
            ? PesquisaIntegracaoQr::contratoAtivoPorId($selecionado)
            : null;
        if ($contrato === null) {
            redirect('/integracao');
        }
        $this->render('pesquisa', ['contrato' => $contrato]);
    }

    /** POST /integracao/responder */
    public function enviar(): void
    {
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }

        $resultado = PesquisaIntegracaoQrService::registrarResposta($_POST);
        if ($resultado['ok'] ?? false) {
            redirect('/integracao/obrigado');
        }

        switch ($resultado['codigo'] ?? '') {
            case 'duplicada':
                $this->render('duplicada');
                return;
            case 'invalido':
                $ctx = PesquisaIntegracaoQrService::contextoAtivo();
                $contrato = $ctx !== null ? PesquisaIntegracaoQr::contratoAtivoPorId((int)($ctx['selecionado'] ?? 0)) : null;
                if ($contrato === null) {
                    redirect('/integracao');
                }
                $this->render('pesquisa', ['contrato' => $contrato, 'error' => (string)$resultado['error']]);
                return;
            default:
                // Contexto expirado, integração encerrada ou vínculo que deixou de estar ativo.
                PesquisaIntegracaoQrService::limparContexto();
                $this->render('indisponivel');
        }
    }

    /** GET /integracao/obrigado */
    public function obrigado(): void
    {
        $this->render('obrigado');
    }

    private function renderIdentificacao(string $error): void
    {
        if (SessaoIntegracao::abertaAtual() === null) {
            $this->render('indisponivel');
            return;
        }
        $this->render('identificacao', ['error' => $error]);
    }

    private function render(string $estado, array $extra = []): void
    {
        $this->view->render('pesquisa_integracao_qr/show', array_merge([
            'estado' => $estado,
            'csrf' => Security::csrfToken(),
            'error' => '',
            'noIndex' => true,
        ], $extra));
    }
}
