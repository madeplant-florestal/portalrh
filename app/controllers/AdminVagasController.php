<?php
class AdminVagasController extends Controller
{
    private EmpresaRepository $empresaRepository;

    public function __construct()
    {
        parent::__construct();
        RecruitmentWebhookSchemaService::ensureSchema();
        $this->empresaRepository = new EmpresaRepository();
    }

    public function index(): void
    {
        // Etapa 3.2C.1: Gestor com `vagas.visualizar` EFETIVA consulta SOMENTE as vagas dos Setores Gerenciados (`vagas.setor_id`), em
        // modo somente leitura. Admin/RH/Supervisor/Viewer seguem exatamente como antes (requireRole + lista global).
        Authorization::requireRoleOuPermissao(['admin', 'rh', 'viewer'], 'vagas.visualizar');
        $acesso = RecrutamentoLeituraSetorialService::paraVagas((int)($_SESSION['user_id'] ?? 0), !empty($_SESSION['user_is_supervisor']), Auth::role());
        if ($acesso['modo'] === RecrutamentoLeituraSetorialService::MODO_NEGADO) {
            http_response_code(403);
            echo 'Acesso negado';
            return;
        }
        $setorial = $acesso['modo'] === RecrutamentoLeituraSetorialService::MODO_SETORIAL;
        $vagas = Vaga::all($setorial ? $acesso['escopo'] : null);
        $this->view->render('admin/vagas/index', [
            'vagas' => $vagas,
            'somenteLeitura' => $setorial,
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $csrf = Security::csrfToken();
        $this->view->render('admin/vagas/form', [
            'csrf' => $csrf,
            'vaga' => null,
            'empresas' => $this->empresaRepository->allOptions(),
            'beneficiosCatalogo' => Beneficio::allActive(),
            'beneficioIdsSelecionados' => [],
        ] + $this->setorView(null), 'layouts/app-shell');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }
        $data = [
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'requisitos' => Security::sanitizeString($_POST['requisitos'] ?? ''),
            'area' => Security::sanitizeString($_POST['area'] ?? ''),
            'local' => Security::sanitizeString($_POST['local'] ?? ''),
            'empresa_id' => $_POST['empresa_id'] ?? null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];
        $beneficioIds = array_map('intval', $_POST['beneficio_ids'] ?? []);
        $postadoSetor = $_POST['setor_id'] ?? '';
        $renderErro = function (string $erro) use ($data, $beneficioIds, $postadoSetor): void {
            $this->view->render('admin/vagas/form', [
                'csrf' => Security::csrfToken(),
                'vaga' => $data,
                'error' => $erro,
                'empresas' => $this->empresaRepository->allOptions(),
                'beneficiosCatalogo' => Beneficio::allActive(),
                'beneficioIdsSelecionados' => $beneficioIds,
            ] + $this->setorView(null, $postadoSetor), 'layouts/app-shell');
        };
        if (!$data['titulo'] || !$data['descricao'] || !$data['requisitos']) {
            $renderErro('Preencha os campos obrigatórios');
            return;
        }
        // Etapa 3.2A: setor responsável OBRIGATÓRIO e validado no servidor (nunca só pelo HTML): o id do POST é revalidado contra o cadastro oficial.
        $svc = new VagaSetorService();
        $validacaoSetor = $svc->validarParaCriacao($postadoSetor);
        if (!$validacaoSetor['ok']) {
            $renderErro((string)$validacaoSetor['error']);
            return;
        }
        try {
            $this->assertEmpresaValida($data['empresa_id']);
            $data['setor_id'] = $validacaoSetor['setor_id'];
            $vagaId = Vaga::create($data);
            Vaga::syncBeneficios($vagaId, $beneficioIds);
            if ($validacaoSetor['setor_id'] !== null) {
                $svc->registrarAlteracao($vagaId, null, $validacaoSetor['setor_id'], (int)($_SESSION['user_id'] ?? 0) ?: null, Security::clientIp(), 'definido');
            }
            redirect('/admin/vagas');
        } catch (Throwable $e) {
            $renderErro($e->getMessage());
        }
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $vaga = Vaga::find((int)$id);
        if (!$vaga) { http_response_code(404); echo 'Vaga não encontrada'; return; }
        $csrf = Security::csrfToken();
        $this->view->render('admin/vagas/form', [
            'csrf' => $csrf,
            'vaga' => $vaga,
            'empresas' => $this->empresaRepository->allOptions(),
            'beneficiosCatalogo' => Beneficio::allActive(),
            'beneficioIdsSelecionados' => Vaga::beneficioIdsSelecionados((int)$vaga['id']),
        ] + $this->setorView($vaga), 'layouts/app-shell');
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }
        $data = [
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'requisitos' => Security::sanitizeString($_POST['requisitos'] ?? ''),
            'area' => Security::sanitizeString($_POST['area'] ?? ''),
            'local' => Security::sanitizeString($_POST['local'] ?? ''),
            'empresa_id' => $_POST['empresa_id'] ?? null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];
        $beneficioIds = array_map('intval', $_POST['beneficio_ids'] ?? []);
        $atual = Vaga::find((int)$id);
        if (!$atual) { http_response_code(404); echo 'Vaga não encontrada'; return; }
        $postadoSetor = $_POST['setor_id'] ?? '';
        $svc = new VagaSetorService();
        // Etapa 3.2A: o setor ATUAL vem do banco (nunca do POST); trocar o setor de vaga com candidaturas exige confirmação explícita;
        // vaga originada de solicitação nunca diverge do setor da solicitação.
        $validacaoSetor = $svc->validarParaEdicao($atual, $postadoSetor, !empty($_POST['confirmar_mudanca_setor']));
        try {
            if (!$validacaoSetor['ok']) {
                throw new InvalidArgumentException((string)$validacaoSetor['error']);
            }
            $this->assertEmpresaValida($data['empresa_id']);
            if (VagaSetorService::schemaSuportaSetor()) {
                $data['setor_id'] = $validacaoSetor['setor_id'];
            }
            if (!Vaga::update((int)$id, $data)) { echo 'Falha ao atualizar'; return; }
            Vaga::syncBeneficios((int)$id, $beneficioIds);
            if (VagaSetorService::schemaSuportaSetor() && ($validacaoSetor['mudou'] || $validacaoSetor['regularizou'])) {
                $svc->registrarAlteracao((int)$id, $validacaoSetor['anterior'], $validacaoSetor['setor_id'], (int)($_SESSION['user_id'] ?? 0) ?: null, Security::clientIp(), $validacaoSetor['mudou'] ? 'alterado' : 'regularizado');
            }
            redirect('/admin/vagas');
        } catch (Throwable $e) {
            $data['id'] = (int)$id;
            $data['setor_id'] = $atual['setor_id'] ?? null;
            $data['solicitacao_vaga_id'] = $atual['solicitacao_vaga_id'] ?? null;
            $this->view->render('admin/vagas/form', [
                'csrf' => Security::csrfToken(),
                'vaga' => $data,
                'error' => $e->getMessage(),
                'empresas' => $this->empresaRepository->allOptions(),
                'beneficiosCatalogo' => Beneficio::allActive(),
                'beneficioIdsSelecionados' => $beneficioIds,
            ] + $this->setorView($data, $postadoSetor) + ['setorMudancaPendente' => !empty($validacaoSetor['mudou'])], 'layouts/app-shell');
        }
    }

    public function delete(string $id): void
    {
        // Bloco 4 (2026-10, pedido do RH): ver AdminAvaliacoesController::delete().
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }
        Vaga::delete((int)$id);
        redirect('/admin/vagas');
    }

    /**
     * Publica um rascunho de vaga (originado de Solicitação de Vaga aprovada): `ativo = 1`,
     * `publicada_em` carimbado. A partir daí a vaga aparece na página pública. Idempotente.
     */
    public function publicar(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) { http_response_code(400); echo 'CSRF inválido'; return; }

        $result = (new SolicitacaoVagaPublicacaoService())->publicar(
            (int)$id,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            Security::clientIp()
        );
        if (!($result['ok'] ?? false)) {
            redirect('/admin/vagas?erro=' . urlencode((string)($result['error'] ?? 'Falha ao publicar a vaga.')));
        }
        $msg = ($result['ja_publicada'] ?? false) ? 'Esta vaga já estava publicada.' : 'Vaga publicada. Já está no site.';
        redirect('/admin/vagas?ok=' . urlencode($msg));
    }

    /**
     * Dados do campo "Setor responsável" para o formulário (Etapa 3.2A): setores oficiais ativos + o setor atual da vaga (mesmo que
     * hoje inativo, para não sumir) + dependências que tornam a troca sensível. A tela só reflete; a validação é do VagaSetorService.
     */
    private function setorView(?array $vaga, mixed $postado = null): array
    {
        if (!VagaSetorService::schemaSuportaSetor()) {
            return ['setorAtivoNoSchema' => false];
        }
        $svc = new VagaSetorService();
        $opcoes = [];
        foreach ($svc->setoresElegiveis() as $o) {
            $opcoes[(int)$o['id']] = VagaSetorService::rotulo($o);
        }
        $atual = isset($vaga['setor_id']) && $vaga['setor_id'] !== null && $vaga['setor_id'] !== '' ? (int)$vaga['setor_id'] : null;
        if ($atual !== null && !isset($opcoes[$atual])) {
            $s = $svc->setorPorId($atual);
            if ($s !== null) {
                $opcoes[$atual] = VagaSetorService::rotulo($s) . ' (inativo/fora do cadastro oficial)';
            }
        }
        $vagaId = isset($vaga['id']) ? (int)$vaga['id'] : 0;
        $deps = $vagaId > 0 ? $svc->dependencias($vagaId) : ['candidaturas' => 0, 'solicitacao' => false, 'beneficios' => 0];
        $solicitacaoSetor = $deps['solicitacao'] && !empty($vaga['solicitacao_vaga_id']) ? $svc->setorDaSolicitacao((int)$vaga['solicitacao_vaga_id']) : null;
        if ($solicitacaoSetor !== null && !isset($opcoes[$solicitacaoSetor]) && ($s = $svc->setorPorId($solicitacaoSetor)) !== null) {
            $opcoes[$solicitacaoSetor] = VagaSetorService::rotulo($s);
        }
        return [
            'setorAtivoNoSchema' => true,
            'setorOpcoes' => $opcoes,
            'setorSelecionado' => $postado !== null ? (ctype_digit((string)$postado) && (int)$postado > 0 ? (int)$postado : null) : $atual,
            'setorAtualId' => $atual,
            'setorDependencias' => $deps,
            'setorDaSolicitacao' => $solicitacaoSetor,
        ];
    }

    private function assertEmpresaValida($empresaId): void
    {
        if ($empresaId === null || $empresaId === '') {
            return;
        }
        $normalized = (int)$empresaId;
        if ($normalized <= 0 || !$this->empresaRepository->exists($normalized)) {
            throw new InvalidArgumentException('Empresa inválida para vincular à vaga.');
        }
    }
}
