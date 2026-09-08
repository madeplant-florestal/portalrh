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
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $vagas = Vaga::all();
        $this->view->render('admin/vagas/index', [
            'vagas' => $vagas,
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $csrf = Security::csrfToken();
        $this->view->render('admin/vagas/form', ['csrf' => $csrf, 'vaga' => null, 'empresas' => $this->empresaRepository->allOptions()], 'layouts/admin');
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
        if (!$data['titulo'] || !$data['descricao'] || !$data['requisitos']) {
            $this->view->render('admin/vagas/form', ['csrf' => Security::csrfToken(), 'vaga' => $data, 'error' => 'Preencha os campos obrigatórios', 'empresas' => $this->empresaRepository->allOptions()]);
            return;
        }
        try {
            $this->assertEmpresaValida($data['empresa_id']);
            Vaga::create($data);
            redirect('/admin/vagas');
        } catch (Throwable $e) {
            $this->view->render('admin/vagas/form', ['csrf' => Security::csrfToken(), 'vaga' => $data, 'error' => $e->getMessage(), 'empresas' => $this->empresaRepository->allOptions()], 'layouts/admin');
        }
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $vaga = Vaga::find((int)$id);
        if (!$vaga) { http_response_code(404); echo 'Vaga não encontrada'; return; }
        $csrf = Security::csrfToken();
        $this->view->render('admin/vagas/form', ['csrf' => $csrf, 'vaga' => $vaga, 'empresas' => $this->empresaRepository->allOptions()], 'layouts/admin');
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
        try {
            $this->assertEmpresaValida($data['empresa_id']);
            if (!Vaga::update((int)$id, $data)) { echo 'Falha ao atualizar'; return; }
            redirect('/admin/vagas');
        } catch (Throwable $e) {
            $data['id'] = (int)$id;
            $this->view->render('admin/vagas/form', ['csrf' => Security::csrfToken(), 'vaga' => $data, 'error' => $e->getMessage(), 'empresas' => $this->empresaRepository->allOptions()], 'layouts/admin');
        }
    }

    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);
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
