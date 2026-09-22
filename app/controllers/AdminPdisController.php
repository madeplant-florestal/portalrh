<?php

/**
 * PDI — Plano de Desenvolvimento Individual (`/admin/pdis`), V1 ASSISTIDA por RH/Gestor. NÃO existe portal ou
 * rota do colaborador: o que é do colaborador é registrado por RH/Gestor e marcado como "em nome do colaborador".
 *
 * Autorização em duas camadas (nunca só role): permissão individual pdi.visualizar / pdi.gerenciar / pdi.acompanhar
 * (Admin só pelo bypass central; supervisor não tem tratamento especial) e ESCOPO POR LINHA aplicado pelo PdiService
 * (Admin e RH com permissão veem todos; os demais só os PDIs em que são o gestor responsável — PDI fora do escopo
 * responde como inexistente). Toda ação de escrita é POST
 * com CSRF e termina em redirect (PRG) com `?ok=`/`?erro=`.
 */
class AdminPdisController extends Controller
{
    public function index(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('pdi.visualizar');

        $ator = PdiService::atorDaSessao();
        $hoje = new DateTimeImmutable('today');
        $erro = null;
        $lista = ['itens' => [], 'filtros' => [], 'opcoes' => ['empresas' => [], 'unidades' => [], 'cargos' => [], 'gestores' => []]];
        try {
            $lista = (new PdiService())->listar($_GET, $ator, $hoje) + $lista;
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminPdisController']);
            $erro = 'Não foi possível carregar os PDIs agora. Tente novamente em instantes.';
        }
        $this->view->render('admin/pdis/index', [
            'lista' => $lista,
            'erro' => $erro,
            'hoje' => $hoje,
            'podeCriar' => Authorization::temPermissao('pdi.gerenciar'),
            'escopoTotal' => PdiService::escopoTotal($ator),
            'flashOk' => Security::sanitizeString($_GET['ok'] ?? ''),
            'flashErro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    /** Passo 1: buscar o colaborador (contrato oficial). Passo 2 (`?contrato=`): formulário do PDI. */
    public function novo(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('pdi.gerenciar');

        $ator = PdiService::atorDaSessao();
        $hoje = new DateTimeImmutable('today');
        $service = new PdiService();
        $contratoId = ctype_digit((string)($_GET['contrato'] ?? '')) ? (int)$_GET['contrato'] : 0;
        if ($contratoId > 0) {
            $contrato = $service->contratoParaCriacao($contratoId, $ator, $hoje);
            if ($contrato !== null) {
                $valores = $this->valoresPadrao($hoje, $ator);
                $sugestao = $this->gestorSugerido($contrato, $ator);
                if ($sugestao !== null) {
                    $valores['gestor_usuario_id'] = (string)$sugestao['id']; // só pré-seleciona: nada é gravado até o envio do formulário
                }
                $this->renderForm('criar', $contrato, null, $valores, [], $ator, $sugestao);
                return;
            }
        }
        $busca = Security::sanitizeString($_GET['busca'] ?? '');
        $this->view->render('admin/pdis/selecionar-contrato', [
            'busca' => $busca,
            'contratos' => $service->buscarContratos($busca, $ator, $hoje),
            'flashErro' => $contratoId > 0 ? 'Contrato não encontrado.' : Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function store(): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('pdi.gerenciar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $ator = PdiService::atorDaSessao();
        $agora = new DateTimeImmutable('now');
        $service = new PdiService();
        $resultado = $service->criar($_POST, $ator, $agora, Security::clientIp());
        if (!($resultado['ok'] ?? false)) {
            $contratoId = ctype_digit((string)($_POST['metadados_id'] ?? '')) ? (int)$_POST['metadados_id'] : 0;
            $contrato = $contratoId > 0 ? $service->contratoParaCriacao($contratoId, $ator, new DateTimeImmutable('today')) : null;
            if ($contrato === null) {
                redirect('/admin/pdis/novo?erro=' . urlencode((string)$resultado['error']));
            }
            $this->renderForm('criar', $contrato, null, $_POST, (array)($resultado['erros'] ?? []), $ator);
            return;
        }
        redirect('/admin/pdis/' . (int)$resultado['id'] . '?ok=' . urlencode('PDI criado como rascunho. Complete o plano e libere quando estiver pronto.'));
    }

    public function show(string $id): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('pdi.visualizar');

        $detalhe = (new PdiService())->detalhe((int)$id, PdiService::atorDaSessao(), new DateTimeImmutable('today'));
        if ($detalhe === null) {
            http_response_code(404);
            echo 'PDI não encontrado.';
            return;
        }
        $this->view->render('admin/pdis/show', [
            'd' => $detalhe,
            'hoje' => new DateTimeImmutable('today'),
            'flashOk' => Security::sanitizeString($_GET['ok'] ?? ''),
            'flashErro' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function editar(string $id): void
    {
        $this->exigirLogin();
        Authorization::requirePermissao('pdi.gerenciar');

        $ator = PdiService::atorDaSessao();
        $detalhe = (new PdiService())->detalhe((int)$id, $ator, new DateTimeImmutable('today'));
        if ($detalhe === null) {
            http_response_code(404);
            echo 'PDI não encontrado.';
            return;
        }
        if (!$detalhe['pode']['gerenciar']) {
            redirect('/admin/pdis/' . (int)$id . '?erro=' . urlencode('PDI ' . mb_strtolower(PdiService::STATUS[$detalhe['pdi']['status']]) . ': a edição comum está bloqueada.'));
        }
        $this->renderForm('editar', null, $detalhe, $this->valoresDoPdi($detalhe), [], $ator);
    }

    public function atualizar(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.gerenciar');
        if ($ator === null) {
            return;
        }
        $resultado = $service->atualizarEstrutura((int)$id, $_POST, $ator, new DateTimeImmutable('now'), Security::clientIp());
        if (!($resultado['ok'] ?? false)) {
            $detalhe = $service->detalhe((int)$id, $ator, new DateTimeImmutable('today'));
            if ($detalhe === null) {
                http_response_code(404);
                echo 'PDI não encontrado.';
                return;
            }
            $this->renderForm('editar', null, $detalhe, $_POST, (array)($resultado['erros'] ?? []), $ator);
            return;
        }
        redirect('/admin/pdis/' . (int)$id . '?ok=' . urlencode('Plano atualizado.'));
    }

    public function acompanhar(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $r = $service->adicionarAcompanhamento((int)$id, (string)($_POST['comentario'] ?? ''), !empty($_POST['em_nome_do_colaborador']), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'Acompanhamento registrado.', '#acompanhamentos');
    }

    public function espacoColaborador(string $id): void
    {
        [$ator, $service] = $this->entrarPost(null);
        if ($ator === null) {
            return;
        }
        $r = $service->registrarEspacoColaborador((int)$id, (string)($_POST['momento_profissional'] ?? ''), (string)($_POST['pontos_desenvolver_colaborador'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'Espaço do colaborador registrado em nome do colaborador.', '#espaco-colaborador');
    }

    public function evidencias(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $r = $service->registrarEvidencias((int)$id, (string)($_POST['evidencias_evolucao'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'Evidências atualizadas.', '#evidencias');
    }

    public function statusAcao(string $id, string $ordem): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $r = $service->alterarStatusAcao((int)$id, (int)$ordem, (string)($_POST['status'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'Status da ação atualizado.', '#plano-de-acao');
    }

    public function liberar(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $this->responder($service->liberar((int)$id, $ator, new DateTimeImmutable('now'), Security::clientIp()), (int)$id, 'PDI liberado (não iniciado).');
    }

    public function iniciar(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $this->responder($service->iniciar((int)$id, $ator, new DateTimeImmutable('now'), Security::clientIp()), (int)$id, 'PDI iniciado.');
    }

    public function concluir(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $r = $service->concluir((int)$id, (string)($_POST['avaliacao_final'] ?? ''), (string)($_POST['comentarios_finais'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'PDI concluído.', '#avaliacao-final');
    }

    public function reabrir(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $this->responder($service->reabrir((int)$id, (string)($_POST['motivo'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp()), (int)$id, 'PDI reaberto (em andamento).');
    }

    public function cancelar(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $this->responder($service->cancelar((int)$id, (string)($_POST['motivo'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp()), (int)$id, 'PDI cancelado.');
    }

    public function manterDesligado(string $id): void
    {
        [$ator, $service] = $this->entrarPost('pdi.acompanhar');
        if ($ator === null) {
            return;
        }
        $r = $service->manterAposDesligamento((int)$id, (string)($_POST['justificativa'] ?? ''), $ator, new DateTimeImmutable('now'), Security::clientIp());
        $this->responder($r, (int)$id, 'Decisão registrada: o PDI foi mantido.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Sessão obrigatória. Deliberadamente NÃO usa Auth::requireRole(): ele libera qualquer usuário sinalizado como
     * supervisor, e no PDI a autorização é só permissão individual (Admin pelo bypass central) + escopo por linha.
     */
    private function exigirLogin(): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }
    }

    /**
     * Entrada padrão de ações POST: role + permissão individual (`null` = pdi.gerenciar OU pdi.acompanhar, usado no
     * espaço do colaborador) + CSRF. Devolve [ator, service] ou [null, null] (resposta já enviada).
     */
    private function entrarPost(?string $permissao): array
    {
        $this->exigirLogin();
        if ($permissao !== null) {
            Authorization::requirePermissao($permissao);
        } elseif (!Authorization::temPermissao('pdi.gerenciar') && !Authorization::temPermissao('pdi.acompanhar')) {
            http_response_code(403);
            echo 'Acesso negado';
            return [null, null];
        }
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return [null, null];
        }
        return [PdiService::atorDaSessao(), new PdiService()];
    }

    private function responder(array $resultado, int $id, string $mensagemOk, string $ancora = ''): void
    {
        if (!($resultado['ok'] ?? false)) {
            $msg = mb_substr(implode(' • ', (array)($resultado['erros'] ?? [$resultado['error'] ?? 'Não foi possível concluir a ação.'])), 0, 500);
            redirect('/admin/pdis/' . $id . '?erro=' . urlencode($msg) . $ancora);
        }
        redirect('/admin/pdis/' . $id . '?ok=' . urlencode($mensagemOk) . $ancora);
    }

    private function valoresPadrao(DateTimeImmutable $hoje, array $ator): array
    {
        return [
            'origem_tipo' => '',
            'gestor_usuario_id' => PdiService::escopoTotal($ator) ? '' : (string)$ator['id'],
            'data_abertura' => $hoje->format('Y-m-d'),
            'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d'),
            'competencias' => '',
            'acoes' => [],
        ];
    }

    private function valoresDoPdi(array $d): array
    {
        $p = $d['pdi'];
        $acoes = [];
        foreach ($d['acoes'] as $a) {
            $acoes[(int)$a['ordem']] = [
                'descricao' => $a['descricao'],
                'responsavel_tipo' => $a['responsavel_tipo'],
                'responsavel_usuario_id' => $a['responsavel_usuario_id'] !== null ? (string)$a['responsavel_usuario_id'] : '',
                'responsavel_nome' => $a['responsavel_usuario_id'] === null && in_array($a['responsavel_tipo'], ['rh', 'outro'], true) ? (string)$a['responsavel_nome_snapshot'] : '',
                'prazo' => $a['prazo'],
            ];
        }
        return [
            'origem_tipo' => $p['origem_tipo'],
            'gestor_usuario_id' => (string)$p['gestor_usuario_id'],
            'data_abertura' => $p['data_abertura'],
            'data_prevista_conclusao' => $p['data_prevista_conclusao'],
            'pontos_fortes' => (string)$p['pontos_fortes'],
            'oportunidades_desenvolvimento' => (string)$p['oportunidades_desenvolvimento'],
            'objetivo_esperado' => (string)$p['objetivo_esperado'],
            'competencias' => implode("\n", array_column($d['competencias'], 'competencia_texto')),
            'acoes' => $acoes,
        ];
    }

    /**
     * Sugestão (opcional) de gestor ao criar PDI: Gestor Imediato do USUÁRIO do Portal ligado ao contrato
     * (`usuarios.colaborador_metadados_id`) — só a relação nova, só para Admin/RH e só se o gestor estiver entre as opções.
     * Não altera autorização nem persiste nada; RH/Admin pode trocar.
     */
    private function gestorSugerido(array $contrato, array $ator): ?array
    {
        $metadadosId = (int)($contrato['metadados_id'] ?? 0);
        if (!PdiService::escopoTotal($ator) || $metadadosId <= 0) {
            return null;
        }
        $sugestao = (new UsuarioGestorService())->gestorSugeridoParaContrato($metadadosId);
        if ($sugestao === null) {
            return null;
        }
        foreach ((new PdiService())->usuariosParaEscolha($ator) as $u) {
            if ((int)$u['id'] === $sugestao['id']) {
                return $sugestao;
            }
        }
        return null;
    }

    private function renderForm(string $modo, ?array $contrato, ?array $detalhe, array $valores, array $erros, array $ator, ?array $gestorSugerido = null): void
    {
        $this->view->render('admin/pdis/form', [
            'modo' => $modo,
            'contrato' => $contrato,
            'detalhe' => $detalhe,
            'valores' => $valores,
            'erros' => $erros,
            'usuarios' => (new PdiService())->usuariosParaEscolha($ator),
            'escopoTotal' => PdiService::escopoTotal($ator),
            'ator' => $ator,
            'gestorSugerido' => $gestorSugerido,
        ], 'layouts/app-shell');
    }
}
