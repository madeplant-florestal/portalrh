<?php
/**
 * Mensagens do processo seletivo (Sprint "Módulo de Mensagens" — o Portal é a fonte oficial dos
 * textos; n8n deixa de guardar cópia própria). Autorização por permissão individual
 * (`mensagens.visualizar/criar/editar` — ver app/core/Authorization.php), nunca por
 * `role`/`is_supervisor` isoladamente.
 */
class AdminMensagensController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.visualizar');

        $this->view->render('admin/mensagens/index', [
            'mensagens' => Mensagem::all(),
            'podeCriar' => Authorization::temPermissao('mensagens.criar'),
            'podeEditar' => Authorization::temPermissao('mensagens.editar'),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/app-shell');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.criar');

        $this->view->render('admin/mensagens/form', $this->dadosDoFormulario('create', null, ''), 'layouts/app-shell');
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.criar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $data = $this->lerDadosDoPost();
        $data['codigo'] = Security::sanitizeString($_POST['codigo'] ?? '');

        $result = Mensagem::create($data);
        if (!($result['ok'] ?? false)) {
            $this->view->render(
                'admin/mensagens/form',
                $this->dadosDoFormulario('create', $data, (string)($result['error'] ?? 'Falha ao cadastrar a mensagem.')),
                'layouts/app-shell'
            );
            return;
        }

        redirect('/admin/mensagens?ok=' . urlencode('Mensagem cadastrada com sucesso.'));
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.editar');

        $mensagem = Mensagem::find((int)$id);
        if (!$mensagem) {
            http_response_code(404);
            echo 'Mensagem não encontrada';
            return;
        }

        $this->view->render('admin/mensagens/form', $this->dadosDoFormulario('edit', $mensagem, ''), 'layouts/app-shell');
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.editar');
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $existing = Mensagem::find((int)$id);
        if (!$existing) {
            http_response_code(404);
            echo 'Mensagem não encontrada';
            return;
        }

        // `codigo` NUNCA vem do POST aqui — Mensagem::update() nem aceita o campo. O código
        // técnico só existe uma vez, na criação (ver §3 da spec da sprint).
        $data = $this->lerDadosDoPost();

        $result = Mensagem::update((int)$id, $data);
        if (!($result['ok'] ?? false)) {
            $data['id'] = (int)$id;
            $data['codigo'] = $existing['codigo'];
            $this->view->render(
                'admin/mensagens/form',
                $this->dadosDoFormulario('edit', $data, (string)($result['error'] ?? 'Falha ao atualizar a mensagem.')),
                'layouts/app-shell'
            );
            return;
        }

        redirect('/admin/mensagens?ok=' . urlencode('Mensagem atualizada com sucesso.'));
    }

    /**
     * Título/descrição continuam por `sanitizeString()` (trim + strip_tags — campos curtos, baixo
     * risco de conteúdo legítimo com `<`/`>`). `conteudo` NÃO passa mais por `strip_tags()`: é
     * texto de WhatsApp e pode legitimamente conter `<`/`>` (ex.: "Local: <Sala 3>") — `strip_tags`
     * apagaria esse trecho sem aviso. A saída em HTML continua sempre escapada via `Security::e()`
     * (view) e nunca executada como HTML/JS — ver §16 da sprint.
     */
    private function lerDadosDoPost(): array
    {
        return [
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'conteudo' => trim((string)($_POST['conteudo'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];
    }

    /**
     * Dados comuns às telas de criação/edição: catálogo oficial de variáveis (§2 da sprint) e a
     * análise do conteúdo ATUAL já calculada no servidor — a seção "Variáveis utilizadas" não
     * depende de JavaScript rodar para mostrar o estado correto no carregamento inicial (corrige o
     * bug relatado na homologação: detecção client-side-only podia ficar defasada/nunca rodar).
     */
    private function dadosDoFormulario(string $mode, ?array $mensagem, string $error): array
    {
        return [
            'mode' => $mode,
            'mensagem' => $mensagem,
            'csrf' => Security::csrfToken(),
            'error' => $error,
            'catalogo' => MensagemService::catalogoVariaveis(),
            'analiseInicial' => MensagemService::analisarConteudo((string)($mensagem['conteudo'] ?? '')),
        ];
    }
}
