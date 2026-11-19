<?php
class AdminUsuariosController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'role' => Security::sanitizeString($_GET['role'] ?? ''),
            'status' => Security::sanitizeString($_GET['status'] ?? '')
        ];
        $result = User::paginateForAdmin($filters, $page, 10);
        $this->view->render('admin/usuarios/index', [
            'users' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'perPage' => $result['per_page'],
            'filters' => $filters,
            'csrf' => Security::csrfToken()
        ], 'layouts/app-shell');
    }

    public function create(): void
    {
        Auth::requireRole(['admin']);
        $csrf = Security::csrfToken();
        $success = isset($_GET['supervisor']) && $_GET['supervisor'] === 'ok'
            ? 'Usuário Supervisor criado/atualizado e protegido com sucesso.'
            : null;
        $this->view->render('admin/usuarios/create', [
            'csrf' => $csrf,
            'success' => $success,
            'gestorOptions' => (new UsuarioGestorService())->candidatos(null),
            // Etapa 3: matriz de permissões; o perfil inicial ('viewer') já vem com o modelo recomendado pela política.
            'matriz' => PermissaoMatrizService::construir('viewer', [], false, Autoridade::rolesAtribuiveis()),
            'old' => [],
        ], 'layouts/app-shell');
    }

    /**
     * Re-renderiza o cadastro com erro (mantém a lista de Gestor Imediato, os campos preenchidos — nunca a senha — e a seleção de
     * permissões feita).
     *
     * @param array{nome?:string,email?:string,role?:string,ids?:int[]} $old
     */
    private function renderCreateComErro(string $error, ?int $gestorSelecionado, array $old = []): void
    {
        $roleOld = in_array(($old['role'] ?? ''), Autoridade::rolesAtribuiveis(), true) ? (string)$old['role'] : 'viewer';
        $this->view->render('admin/usuarios/create', [
            'error' => $error,
            'csrf' => Security::csrfToken(),
            'gestorOptions' => (new UsuarioGestorService())->candidatos(null),
            'gestorSelecionado' => $gestorSelecionado,
            'matriz' => PermissaoMatrizService::construir($roleOld, [], false, Autoridade::rolesAtribuiveis(), $old['ids'] ?? null),
            'old' => ['nome' => (string)($old['nome'] ?? ''), 'email' => (string)($old['email'] ?? ''), 'role' => $roleOld],
        ], 'layouts/app-shell');
    }

    public function store(): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $nome = Security::sanitizeString($_POST['nome'] ?? '');
        $email = Security::sanitizeString($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $role = Security::sanitizeString($_POST['role'] ?? 'viewer');
        $idsPermissoes = array_values(array_unique(array_map('intval', (array)($_POST['permissao_ids'] ?? []))));
        $old = ['nome' => $nome, 'email' => $email, 'role' => $role, 'ids' => $idsPermissoes];
        $supervisorEmail = Config::app()['security']['supervisor_email'] ?? '';
        // Etapa 2C.1: whitelist dinâmica (inclui 'gestor' só se o schema suportar); perfil desconhecido é recusado, não vira viewer.
        if (!in_array($role, Autoridade::rolesAtribuiveis(), true)) {
            $this->renderCreateComErro('Perfil inválido.', null, $old);
            return;
        }
        // Gestor Imediato (opcional): validado no servidor ANTES de criar o usuário — nunca confia no <select>.
        $gestorId = ctype_digit((string)($_POST['gestor_usuario_id'] ?? '')) ? (int)$_POST['gestor_usuario_id'] : null;
        $gestorService = new UsuarioGestorService();
        if (!$nome || !$email || !$senha) {
            $this->renderCreateComErro('Preencha nome, e-mail e senha.', $gestorId, $old);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderCreateComErro('E-mail inválido.', $gestorId, $old);
            return;
        }
        if ($supervisorEmail !== '' && strcasecmp($email, $supervisorEmail) === 0) {
            $this->renderCreateComErro('Este e-mail é reservado ao usuário Supervisor protegido.', $gestorId, $old);
            return;
        }
        $policy = PasswordPolicy::validate($senha);
        if (!$policy['valid']) {
            $this->renderCreateComErro(implode(' ', $policy['errors']), $gestorId, $old);
            return;
        }
        $erroGestor = $gestorService->validarGestorNovoUsuario($gestorId);
        if ($erroGestor !== null) {
            $this->renderCreateComErro($erroGestor, $gestorId, $old);
            return;
        }
        // Etapa 3: a SELEÇÃO de permissões é validada pela política central ANTES de qualquer gravação (id inexistente/inativo e permissão
        // incompatível com o perfil recusam o cadastro inteiro — nada é descartado em silêncio). Admin/RH têm acesso global pelo perfil:
        // permissão individual no cadastro desses perfis é seleção forjada/inconsistente e também é recusada.
        $ator = Autoridade::atorDaSessao();
        if (!Autoridade::ehAdministrativo($ator)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }
        if ($idsPermissoes !== [] && PermissaoPerfilPolicy::perfilGlobal($role)) {
            $this->renderCreateComErro('Perfis Admin e RH têm acesso global pelo próprio perfil: não selecione permissões individuais no cadastro.', $gestorId, $old);
            return;
        }
        $validacao = PermissaoPerfilPolicy::validarSelecao($role, $idsPermissoes, []);
        if (!$validacao['ok']) {
            AuditLog::log($ator?->id, null, 'blocked_permissoes_update', 'Cadastro recusado (permissões): ' . implode(', ', array_merge($validacao['incompativeis'], $validacao['inativas'])), Security::clientIp());
            $this->renderCreateComErro($validacao['mensagem'], $gestorId, $old);
            return;
        }
        $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
        // Usuário + permissões numa transação única: qualquer falha desfaz tudo (nenhum usuário parcial, nenhuma permissão órfã).
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $novoId = User::create($nome, $email, $senhaHash, $role);
            if ($idsPermissoes !== []) {
                $gravou = Authorization::sincronizar($novoId, $idsPermissoes);
                if (!($gravou['ok'] ?? false)) {
                    throw new \RuntimeException((string)($gravou['error'] ?? 'Falha ao gravar as permissões.'));
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->renderCreateComErro('Falha ao criar usuário: ' . Security::e($e->getMessage()), $gestorId, $old);
            return;
        }
        if ($idsPermissoes !== []) {
            AuditLog::log($ator?->id, $novoId, 'permissoes_update', 'cadastro: total=' . count($idsPermissoes), Security::clientIp());
        }
        if ($gestorId !== null) {
            $resultado = $gestorService->definirGestor($novoId, $gestorId, (int)($_SESSION['user_id'] ?? 0) ?: null, Security::clientIp());
            if (!($resultado['ok'] ?? false)) {
                redirect('/admin/usuarios/' . $novoId . '?erro=' . urlencode('Usuário criado, mas o gestor imediato não foi definido: ' . (string)($resultado['error'] ?? '')));
            }
        }
        redirect('/admin/usuarios');
    }

    /**
     * Ajuste pós-Bloco 4 (2026-10, pedido do RH): RH também altera perfil (admin/rh/viewer) de
     * outro usuário — não só Admin/supervisor. `canManageUser()`/proteção de supervisor e a
     * whitelist de perfis permanecem como estavam. Autoelevação: o ator nunca altera o PRÓPRIO
     * perfil por aqui (vale também para Admin — evita autorrebaixamento acidental), então um RH
     * nunca consegue se promover a admin através desta ação.
     */
    public function updateRole(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $atorId = (int)($_SESSION['user_id'] ?? 0);
        if ((int)$id === $atorId) {
            http_response_code(403);
            echo 'Não é possível alterar o próprio perfil.';
            return;
        }
        // Etapa 2A-bis: perfil fora da whitelist é RECUSADO (antes virava 'viewer' em silêncio — um rebaixamento por valor forjado).
        $role = Security::sanitizeString($_POST['role'] ?? '');
        if (!in_array($role, Autoridade::rolesAtribuiveis(), true)) {
            http_response_code(400);
            echo 'Perfil inválido.';
            return;
        }
        $actor = User::findById($atorId);
        $ok = User::attemptRoleUpdate((int)$id, $role, $actor, Security::clientIp());
        if (!$ok) {
            http_response_code(User::$ultimoMotivo === 'nao_encontrado' ? 404 : 403);
            echo 'Operação não permitida.';
            return;
        }
        redirect('/admin/usuarios');
    }

    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        $ok = User::attemptDelete((int)$id, $actor, Security::clientIp());
        if (!$ok) {
            $motivo = User::$ultimoMotivo;
            http_response_code($motivo === 'ultimo_supervisor' ? 409 : ($motivo === 'nao_encontrado' ? 404 : 403));
            echo $motivo === 'ultimo_supervisor' ? 'Não é possível excluir o último Supervisor Geral ativo.' : 'Operação não permitida.';
            return;
        }
        redirect('/admin/usuarios');
    }

    /**
     * Detalhe do usuário. Admin gerencia tudo. RH (Sprint Solicitação de Vaga — Etapa 2) recebe
     * acesso restrito ao bloco de Contexto Organizacional (Cargo/Setores/vínculo METADADOS) desta
     * mesma tela — NÃO ganha CRUD completo de usuário. A view usa `isAdminAtor` para esconder as
     * seções fora desse escopo (Solicitação de Vagas / status / senha).
     */
    public function show(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        SchemaManager::ensure();
        $user = User::findById((int)$id);
        if (!$user) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }

        $aprovador = $user->aprovador_usuario_id ? User::findById((int)$user->aprovador_usuario_id) : null;
        $vinculoMetadados = null;
        if ($user->colaborador_metadados_id) {
            $vinculoMetadados = (new ColaboradorMetadadosConsultaRepository())->findById((int)$user->colaborador_metadados_id);
        }

        // Perfis que o ator PODE atribuir a este usuário (a UI só mostra o que Autoridade já permite; o backend revalida).
        $atorAutoridade = Autoridade::atorDaSessao();
        $rolesAtribuiveis = [];
        foreach (['admin' => 'Admin', 'rh' => 'RH', 'gestor' => 'Gestor', 'viewer' => 'Viewer'] as $valorPerfil => $rotuloPerfil) {
            if (Autoridade::podeAtribuirRole($atorAutoridade, $user, $valorPerfil)) {
                $rolesAtribuiveis[$valorPerfil] = $rotuloPerfil;
            }
        }

        $contextoService = new UsuarioContextoOrganizacionalService();
        // Gestor Imediato (relação própria do Portal, independente do aprovador acima).
        $gestorService = new UsuarioGestorService();

        $this->view->render('admin/usuarios/show', [
            'user' => $user,
            'csrf' => Security::csrfToken(),
            'gestor' => $gestorService->gestorDoUsuario((int)$user->id),
            'gestorOptions' => $gestorService->candidatos((int)$user->id),
            'liderados' => $gestorService->quantidadeSubordinadosDiretos((int)$user->id),
            'aprovador' => $aprovador,
            'aprovadorOptions' => User::candidatosAprovador((int)$user->id),
            'vinculoMetadados' => $vinculoMetadados,
            'contexto' => $contextoService->contextoDoUsuario((int)$user->id),
            'cargosOficiais' => (new CatalogoMetadadosRepository('cargos'))->listarOficiais(),
            'setoresOficiais' => (new CatalogoMetadadosRepository('setores'))->listarOficiais(),
            'isAdminAtor' => $this->isAdminAtor(),
            'podeAlterarPerfil' => $this->isAdminOuRhAtor() && $rolesAtribuiveis !== [],
            'rolesAtribuiveis' => $rolesAtribuiveis,
            'diagnosticoPermissoes' => PermissaoPerfilPolicy::diagnosticoLegado((string)$user->role, Authorization::codigosGravados((int)$user->id)),
            'alvoSupervisorGeral' => Autoridade::ehSupervisorGeral($user),
            'alvoInconsistente' => Autoridade::privilegioInconsistente($user),
            'podeAlterarSupervisorGeral' => Autoridade::podeAlterarSupervisorGeral($atorAutoridade) && (int)$user->id !== (int)($atorAutoridade->id ?? 0),
            'podeAlterarSetoresGerenciados' => $this->isAdminOuRhAtor(),
            'setoresGerenciados' => (new UsuarioSetoresGerenciadosService())->setoresDoUsuario((int)$user->id),
            'matriz' => PermissaoMatrizService::construir(
                (string)$user->role,
                Authorization::idsAtribuidos((int)$user->id),
                true,
                array_values(array_unique(array_merge(array_keys($rolesAtribuiveis), [PermissaoPerfilPolicy::normalizarRole($user->role)])))
            ),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/app-shell');
    }

    /** Admin (ou supervisor protegido) — distingue de RH, que só administra Contexto Organizacional. */
    private function isAdminAtor(): bool
    {
        return strtolower((string)Auth::role()) === 'admin' || !empty($_SESSION['user_is_supervisor']);
    }

    /**
     * Admin ou RH — usado SOMENTE para liberar a edição de Perfil (updateRole), ajuste pós-Bloco 4.
     * Não confundir com isAdminAtor(): as demais seções admin-only (CRUD, status, senha, exclusão)
     * continuam fora do escopo de RH.
     */
    private function isAdminOuRhAtor(): bool
    {
        $role = strtolower((string)Auth::role());
        return $role === 'admin' || $role === 'rh' || !empty($_SESSION['user_is_supervisor']);
    }

    /**
     * Salva o acesso ao fluxo de Solicitação de Vaga do usuário: autorização
     * (`pode_solicitar_vaga`) + aprovador/líder imediato (`aprovador_usuario_id`).
     */
    public function updateVagaAcesso(string $id): void
    {
        Auth::requireRole(['admin']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciar($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $pode = !empty($_POST['pode_solicitar_vaga']);
        $aprovadorId = ctype_digit((string)($_POST['aprovador_usuario_id'] ?? '')) ? (int)$_POST['aprovador_usuario_id'] : null;
        $result = User::setVagaAccess((int)$id, $pode, $aprovadorId, $actor, Security::clientIp());

        $back = '/admin/usuarios/' . (int)$id;
        if (!($result['ok'] ?? false)) {
            redirect($back . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao salvar o acesso a Solicitação de Vagas.')));
        }
        redirect($back . '?ok=' . urlencode('Acesso a Solicitação de Vagas atualizado.'));
    }

    /**
     * Define, troca ou remove o Gestor Imediato do usuário (`usuarios.gestor_usuario_id`). Mesma política da administração
     * de usuários (Admin/supervisor, CSRF, canManageUser). Independente do aprovador de Solicitação de Vaga.
     */
    public function updateGestor(string $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciar($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $gestorId = ctype_digit((string)($_POST['gestor_usuario_id'] ?? '')) ? (int)$_POST['gestor_usuario_id'] : null;
        $result = (new UsuarioGestorService())->definirGestor((int)$id, $gestorId, $actor?->id, Security::clientIp());

        $back = '/admin/usuarios/' . (int)$id;
        if (!($result['ok'] ?? false)) {
            redirect($back . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao salvar o gestor imediato.')));
        }
        redirect($back . '?ok=' . urlencode(($result['alterado'] ?? false) ? 'Gestor imediato atualizado.' : 'Nenhuma alteração no gestor imediato.'));
    }

    /**
     * Vincula (ação administrativa explícita) o usuário a um contrato oficial do METADADOS.
     * Admin e RH (Sprint Solicitação de Vaga — Etapa 2, bloco de Contexto Organizacional).
     */
    public function vincularMetadados(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciarContexto($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $back = '/admin/usuarios/' . (int)$id;
        $acao = Security::sanitizeString($_POST['acao'] ?? 'vincular');
        if ($acao === 'desvincular') {
            // Desvínculo nunca é silencioso: exige a confirmação explícita do administrador
            // (a tela mostra o aviso de que Cargo/Setores são preservados como contexto manual).
            if (($_POST['confirmar_desvinculo'] ?? '') !== '1') {
                redirect($back . '?erro=' . urlencode('Confirme a desvinculação do METADADOS para prosseguir.'));
            }
            User::desvincularMetadados((int)$id, $actor, Security::clientIp());
            redirect($back . '?ok=' . urlencode('Vínculo com o METADADOS removido. Cargo e Setores preservados como contexto manual.'));
        }

        $metadadosId = ctype_digit((string)($_POST['colaborador_metadados_id'] ?? '')) ? (int)$_POST['colaborador_metadados_id'] : 0;
        if ($metadadosId <= 0) {
            redirect($back . '?erro=' . urlencode('Selecione um contrato oficial para vincular.'));
        }
        $result = User::vincularMetadados((int)$id, $metadadosId, $actor, Security::clientIp());
        if (!($result['ok'] ?? false)) {
            redirect($back . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao vincular o contrato oficial.')));
        }
        redirect($back . '?ok=' . urlencode('Usuário vinculado ao contrato oficial do METADADOS.'));
    }

    /**
     * Salva o contexto organizacional do usuário: Cargo principal + Setor principal + Setores
     * adicionais, sempre a partir dos catálogos OFICIAIS do METADADOS. Campos herdados de um
     * contrato vinculado ficam travados (a validação final é do Service).
     * Admin e RH podem gerenciar — este é exatamente o bloco liberado para RH na Etapa 2.
     */
    public function updateContextoOrganizacional(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciarContexto($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $cargoId = ctype_digit((string)($_POST['cargo_id'] ?? '')) ? (int)$_POST['cargo_id'] : null;
        $setorPrincipalId = ctype_digit((string)($_POST['setor_principal_id'] ?? '')) ? (int)$_POST['setor_principal_id'] : null;
        $adicionais = array_values(array_filter(array_map(
            static fn ($v): int => (int)$v,
            (array)($_POST['setores_adicionais'] ?? [])
        ), static fn (int $v): bool => $v > 0));

        $result = (new UsuarioContextoOrganizacionalService())->definirContextoManual(
            (int)$id,
            $cargoId,
            $setorPrincipalId,
            $adicionais,
            $actor,
            Security::clientIp()
        );

        $back = '/admin/usuarios/' . (int)$id;
        if (!($result['ok'] ?? false)) {
            redirect($back . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao salvar o contexto organizacional.')));
        }
        redirect($back . '?ok=' . urlencode('Contexto organizacional atualizado.'));
    }

    /**
     * Salva os "Setores gerenciados" do usuário (Bloco 7, 2026-10, pedido do RH) — base do filtro
     * por Gestor no Dashboard de Integração/People Analytics. Admin/RH, mesmo gate de
     * updateContextoOrganizacional(); independente de `gestor_usuario_id` (Gestor Imediato) e de
     * `usuario_setores` (Setor principal/adicionais) — nenhuma das duas é lida ou alterada aqui.
     */
    public function updateSetoresGerenciados(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciarContexto($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $setorIds = array_values(array_filter(array_map(
            static fn ($v): int => (int)$v,
            (array)($_POST['setores_gerenciados'] ?? [])
        ), static fn (int $v): bool => $v > 0));

        $result = (new UsuarioSetoresGerenciadosService())->definirSetoresGerenciados((int)$id, $setorIds);

        $back = '/admin/usuarios/' . (int)$id;
        if (!($result['ok'] ?? false)) {
            redirect($back . '?erro=' . urlencode((string)($result['error'] ?? 'Falha ao salvar os setores gerenciados.')));
        }
        redirect($back . '?ok=' . urlencode('Setores gerenciados atualizados.'));
    }

    /**
     * Salva as PERMISSÕES INDIVIDUAIS do usuário (Sprint "Controle de Acesso por Permissões
     * Individuais"). Substitui o conjunto de `usuario_permissoes` do usuário pelos IDs recebidos —
     * `Authorization::sincronizar()` valida contra o catálogo (só aceita permissão existente e
     * ativa) e nunca duplica. Exclusivo de Admin: é o mecanismo de autorização em si, não o
     * contexto organizacional (que RH também administra).
     */
    public function updatePermissoes(string $id): void
    {
        Auth::requireRole(['admin']);
        SchemaManager::ensure();
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!Autoridade::podeGerenciar($actor, $target)) {
            http_response_code(403);
            echo 'Operação não permitida.';
            return;
        }

        $idsRecebidos = array_map(
            static fn ($v): int => (int)$v,
            (array)($_POST['permissao_ids'] ?? [])
        );
        $back = '/admin/usuarios/' . (int)$id;

        // Etapa 3: a matriz pode trazer o PERFIL junto (troca de perfil + permissões numa única gravação). Perfil ausente ou igual ao atual =
        // só permissões (contrato anterior). Perfil diferente exige a MESMA autoridade de /role (whitelist, sem autoalteração, sem
        // Supervisor Geral, hierarquia) e a seleção é validada contra o perfil NOVO.
        $roleAtual = PermissaoPerfilPolicy::normalizarRole($target->role);
        $roleRecebida = PermissaoPerfilPolicy::normalizarRole($_POST['role'] ?? '');
        $mudaRole = $roleRecebida !== '' && $roleRecebida !== $roleAtual;
        if ($mudaRole) {
            if (!in_array($roleRecebida, Autoridade::rolesAtribuiveis(), true)) {
                redirect($back . '?erro=' . urlencode('Perfil inválido.'));
            }
            if (!Autoridade::podeAtribuirRole($actor, $target, $roleRecebida)) {
                AuditLog::log($actor?->id, (int)$id, 'blocked_role_change', 'Tentativa bloqueada de alterar perfil (matriz): ' . $target->role . ' -> ' . $roleRecebida, Security::clientIp());
                http_response_code(403);
                echo 'Operação não permitida.';
                return;
            }
        }
        $roleEfetivo = $mudaRole ? $roleRecebida : (string)$target->role;
        // Etapa 2B: a SELEÇÃO FINAL é validada pela política central ANTES de qualquer gravação — id inexistente/inativo e permissão
        // incompatível com o perfil recusam o salvamento inteiro (nada parcial, nada descartado em silêncio; o que estava gravado
        // permanece como estava).
        $validacao = PermissaoPerfilPolicy::validarSelecao($roleEfetivo, $idsRecebidos, $mudaRole ? [] : Authorization::idsAtribuidos((int)$id));
        if (!$validacao['ok']) {
            AuditLog::log($actor?->id, (int)$id, 'blocked_permissoes_update', 'Seleção recusada: ' . implode(', ', array_merge($validacao['incompativeis'], $validacao['inativas'])), Security::clientIp());
            redirect($back . '?erro=' . urlencode($validacao['mensagem']));
        }

        // Perfil + permissões na MESMA transação: ou grava tudo, ou nada muda.
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            if ($mudaRole && !User::attemptRoleUpdate((int)$id, $roleRecebida, $actor, Security::clientIp())) {
                throw new \RuntimeException('Não foi possível alterar o perfil.');
            }
            $result = Authorization::sincronizar((int)$id, $idsRecebidos);
            if (!($result['ok'] ?? false)) {
                throw new \RuntimeException((string)($result['error'] ?? 'Falha ao salvar as permissões de acesso.'));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirect($back . '?erro=' . urlencode($e instanceof \RuntimeException ? $e->getMessage() : 'Falha ao salvar as permissões de acesso.'));
        }
        AuditLog::log($actor?->id, (int)$id, 'permissoes_update', 'total=' . (int)($result['total'] ?? 0) . ($mudaRole ? ' perfil=' . $roleAtual . '->' . $roleRecebida : ''), Security::clientIp());
        redirect($back . '?ok=' . urlencode($mudaRole ? 'Perfil e permissões de acesso atualizados.' : 'Permissões de acesso atualizadas.'));
    }

    /**
     * Busca JSON de contratos oficiais ATIVOS para o autocomplete do vínculo METADADOS. Sem CPF.
     * Admin e RH (mesmo escopo de `vincularMetadados`).
     */
    public function buscarMetadados(): void
    {
        Auth::requireRole(['admin', 'rh']);
        header('Content-Type: application/json; charset=UTF-8');
        $q = Security::sanitizeString($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
            return;
        }
        $resultado = (new ColaboradorMetadadosConsultaRepository())->paginate(
            ['q' => $q, 'situacao' => 'ativos'],
            1,
            20
        );
        $items = array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'nome' => (string)$row['nome'],
                'empresa' => (string)($row['empresa'] ?? ''),
                'unidade' => (string)($row['unidade'] ?? ''),
                'setor' => (string)($row['setor'] ?? ''),
                'cargo' => (string)($row['cargo'] ?? ''),
                'contrato' => (string)($row['numero_contrato'] ?? ''),
            ];
        }, $resultado['items']);
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Concede ou remove o flag de Supervisor Geral — a ÚNICA rota que escreve `is_supervisor` (fora o `garantir` do supervisor
     * configurado). Somente um Supervisor Geral (admin + flag) a executa; a decisão e as proteções (nunca o próprio, nunca o
     * último ativo, alvo admin) ficam em User/Autoridade; tudo é auditado.
     */
    public function alterarSupervisorGeral(string $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $actor = Autoridade::atorDaSessao();
        $acao = (string)($_POST['acao'] ?? '');
        $back = '/admin/usuarios/' . (int)$id;
        if (!in_array($acao, ['conceder', 'revogar'], true)) {
            http_response_code(400);
            echo 'Ação inválida.';
            return;
        }
        $ok = $acao === 'conceder'
            ? User::concederSupervisorGeral((int)$id, $actor, Security::clientIp())
            : User::revogarSupervisorGeral((int)$id, $actor, Security::clientIp());
        if (!$ok) {
            $motivo = User::$ultimoMotivo;
            if ($motivo === 'negado' || $motivo === 'nao_encontrado') {
                http_response_code($motivo === 'nao_encontrado' ? 404 : 403);
                echo 'Operação não permitida.';
                return;
            }
            $mensagens = [
                'ultimo_supervisor' => 'Não é possível remover o último Supervisor Geral ativo.',
                'autoalteracao' => 'Você não pode remover o seu próprio privilégio.',
                'alvo_nao_admin' => 'O Supervisor Geral exige perfil Admin. Altere o perfil antes, de forma explícita.',
                'ja_supervisor' => 'A conta já é Supervisor Geral.',
                'nao_supervisor' => 'A conta não é Supervisor Geral.',
            ];
            redirect($back . '?erro=' . urlencode($mensagens[$motivo] ?? 'Operação não realizada.'));
        }
        redirect($back . '?ok=' . urlencode($acao === 'conceder' ? 'Supervisor Geral concedido.' : 'Supervisor Geral removido.'));
    }

    public function updateStatus(string $id): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $active = (string)($_POST['active'] ?? '0') === '1';
        $target = User::findById((int)$id);
        if (!$target) {
            http_response_code(404);
            echo 'Usuário não encontrado';
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!User::attemptSetActive((int)$id, $active, $actor, Security::clientIp())) {
            $motivo = User::$ultimoMotivo;
            http_response_code($motivo === 'ultimo_supervisor' ? 409 : 403);
            echo $motivo === 'ultimo_supervisor' ? 'Não é possível desativar o último Supervisor Geral ativo.' : 'Operação não permitida.';
            return;
        }
        redirect('/admin/usuarios');
    }

    public function adminChangePasswordApi(string $id): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $actor = User::findById((int)($_SESSION['user_id'] ?? 0));
        if (!$actor) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Usuário autenticado não encontrado.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!Autoridade::ehAdministrativo($actor)) { // role admin (Supervisor Geral incluso); o flag isolado não conta
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Apenas administradores podem executar esta ação.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $csrf = (string)($payload['csrf'] ?? '');
        if (!Security::csrfCheck($csrf)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falha na verificação de segurança (CSRF).'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $newPassword = (string)($payload['new_password'] ?? '');
        $result = User::adminChangePassword((int)$id, $newPassword, $actor, Security::clientIp());
        $status = (int)($result['status'] ?? 500);
        http_response_code($status);
        if (!($result['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => (string)($result['error'] ?? 'Falha ao alterar senha.')], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode(['ok' => true, 'message' => 'Senha alterada com sucesso.'], JSON_UNESCAPED_UNICODE);
    }
}
