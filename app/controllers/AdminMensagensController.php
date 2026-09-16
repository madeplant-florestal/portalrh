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
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('mensagens.criar');

        $this->view->render('admin/mensagens/form', [
            'mode' => 'create',
            'mensagem' => null,
            'csrf' => Security::csrfToken(),
            'error' => '',
        ], 'layouts/admin');
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

        $data = [
            'codigo' => Security::sanitizeString($_POST['codigo'] ?? ''),
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            // sanitizeString() só faz trim()+strip_tags() — preserva quebras de linha, espaços
            // internos e emoji (texto de WhatsApp), só remove tags HTML por defesa em profundidade
            // (a saída também é sempre escapada com Security::e() em toda view — ver §16).
            'conteudo' => Security::sanitizeString($_POST['conteudo'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        $result = Mensagem::create($data);
        if (!($result['ok'] ?? false)) {
            $this->view->render('admin/mensagens/form', [
                'mode' => 'create',
                'mensagem' => $data,
                'csrf' => Security::csrfToken(),
                'error' => (string)($result['error'] ?? 'Falha ao cadastrar a mensagem.'),
            ], 'layouts/admin');
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

        $this->view->render('admin/mensagens/form', [
            'mode' => 'edit',
            'mensagem' => $mensagem,
            'csrf' => Security::csrfToken(),
            'error' => '',
        ], 'layouts/admin');
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

        $data = [
            // `codigo` NUNCA vem do POST aqui — Mensagem::update() nem aceita o campo. O código
            // técnico só existe uma vez, na criação (ver §3 da spec da sprint).
            'titulo' => Security::sanitizeString($_POST['titulo'] ?? ''),
            'descricao' => Security::sanitizeString($_POST['descricao'] ?? ''),
            'conteudo' => Security::sanitizeString($_POST['conteudo'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        $result = Mensagem::update((int)$id, $data);
        if (!($result['ok'] ?? false)) {
            $data['id'] = (int)$id;
            $data['codigo'] = $existing['codigo'];
            $this->view->render('admin/mensagens/form', [
                'mode' => 'edit',
                'mensagem' => $data,
                'csrf' => Security::csrfToken(),
                'error' => (string)($result['error'] ?? 'Falha ao atualizar a mensagem.'),
            ], 'layouts/admin');
            return;
        }

        redirect('/admin/mensagens?ok=' . urlencode('Mensagem atualizada com sucesso.'));
    }
}
