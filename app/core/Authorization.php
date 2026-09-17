<?php
/**
 * Resolver central de PERMISSÕES INDIVIDUAIS (Sprint "Controle de Acesso por Permissões
 * Individuais", migration 2026-09-15-permissoes-individuais.sql).
 *
 * A fonte efetiva de autorização funcional de módulos NOVOS do Portal é a permissão individual
 * atribuída ao usuário (`usuario_permissoes`), não mais um booleano novo por módulo em `usuarios`.
 * `usuarios.role`/`is_supervisor`/`pode_solicitar_vaga` continuam existindo por compatibilidade
 * temporária (ver App::canCreate() e Auth::requireRole()), mas módulos novos não devem criar
 * colunas booleanas equivalentes — usam um código `modulo.acao` cadastrado em `permissoes`.
 *
 * Bypass de Admin: centralizado EXCLUSIVAMENTE em `usuarioTemPermissao()` abaixo. Nunca replicar
 * `if role === admin` em controllers/views para decidir permissão individual — sempre passar por
 * esta classe. RH (`role = rh`) e supervisor (`is_supervisor = 1`) NÃO recebem bypass aqui: para
 * eles, autorização de módulos novos depende de permissão individual como qualquer outro usuário.
 *
 * Segurança: toda validação é feita no BACKEND. Esconder menu/botão é só UI — nunca a defesa real.
 * Acesso direto sem autorização usa a MESMA convenção 403 de `Auth::requireRole()` (não inventa
 * uma segunda).
 */
class Authorization
{
    /** Cache por processo (1 request) — evita reconsultar o banco a cada verificação. */
    private static array $permissoesPorUsuario = [];

    public static function requirePermissao(string $codigo): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        if (!self::usuarioTemPermissao($usuarioId, $codigo)) {
            http_response_code(403);
            echo 'Acesso negado';
            exit;
        }
    }

    /** Permissão do usuário da SESSÃO atual. */
    public static function temPermissao(string $codigo): bool
    {
        return self::usuarioTemPermissao((int)($_SESSION['user_id'] ?? 0), $codigo);
    }

    /**
     * Bypass de Admin fica SOMENTE aqui. `usuarios.role = 'admin'` sempre tem acesso total,
     * independente de linhas em `usuario_permissoes` — evita que o rollout desta sprint tire
     * acesso de um administrador por falta de seed.
     */
    public static function usuarioTemPermissao(int $usuarioId, string $codigo): bool
    {
        if ($usuarioId <= 0) {
            return false;
        }
        // Rede de segurança: garante `permissoes`/`usuario_permissoes` em instalações onde a
        // migration ainda não rodou. Centralizado aqui (em vez de em cada controller/view que usa
        // permissão) — chamada é idempotente e barata (SchemaManager::ensure() só age 1x/request).
        SchemaManager::ensure();
        if (self::usuarioEhAdmin($usuarioId)) {
            return true;
        }
        $concedidas = self::permissoesConcedidas($usuarioId);
        return in_array($codigo, $concedidas, true);
    }

    private static function usuarioEhAdmin(int $usuarioId): bool
    {
        if ($usuarioId === (int)($_SESSION['user_id'] ?? 0)) {
            $role = strtolower(trim((string)(Auth::role() ?? '')));
            return $role === 'admin';
        }
        $stmt = Database::conn()->prepare('SELECT role FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        return strtolower(trim((string)$stmt->fetchColumn())) === 'admin';
    }

    /** Códigos de permissão ATIVA concedidos ao usuário — cacheado por request. */
    private static function permissoesConcedidas(int $usuarioId): array
    {
        if (isset(self::$permissoesPorUsuario[$usuarioId])) {
            return self::$permissoesPorUsuario[$usuarioId];
        }
        $stmt = Database::conn()->prepare(
            'SELECT p.codigo
             FROM usuario_permissoes up
             INNER JOIN permissoes p ON p.id = up.permissao_id
             WHERE up.usuario_id = ? AND p.ativo = 1'
        );
        $stmt->execute([$usuarioId]);
        $codigos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::$permissoesPorUsuario[$usuarioId] = $codigos;
        return $codigos;
    }

    /** Catálogo completo de permissões ativas, agrupado por módulo — para a Tela de Usuários. */
    public static function catalogoPorModulo(): array
    {
        // ORDER BY ordem (não `modulo` alfabético): a ordem de exibição dos módulos na Tela de
        // Usuários segue a ordem de cadastro em `permissoes` (o seed já cadastra
        // solicitacao_vaga antes de kanban_vagas) — PHP preserva a ordem de inserção das chaves
        // do array `$porModulo` abaixo, então o 1º módulo que aparecer no resultset vira o 1º grupo.
        $stmt = Database::conn()->query(
            'SELECT id, codigo, modulo, nome, descricao, ordem
             FROM permissoes WHERE ativo = 1 ORDER BY ordem ASC, id ASC'
        );
        $porModulo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porModulo[$row['modulo']][] = $row;
        }
        return $porModulo;
    }

    /** IDs de permissão atualmente atribuídos a um usuário — para pré-marcar os checkboxes. */
    public static function idsAtribuidos(int $usuarioId): array
    {
        $stmt = Database::conn()->prepare('SELECT permissao_id FROM usuario_permissoes WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Sincroniza as permissões de um usuário com a lista de IDs recebida do formulário: valida
     * contra o catálogo (só aceita permissão existente e ativa), remove o que não está mais
     * marcado, insere o que faltar, nunca duplica. Transacional.
     */
    public static function sincronizar(int $usuarioId, array $idsRecebidos): array
    {
        $idsValidos = self::idsPermissoesAtivas();
        $idsFiltrados = array_values(array_unique(array_filter(
            array_map('intval', $idsRecebidos),
            static fn (int $id): bool => in_array($id, $idsValidos, true)
        )));

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$usuarioId]);
            if ($idsFiltrados !== []) {
                $stmt = $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)');
                foreach ($idsFiltrados as $permissaoId) {
                    $stmt->execute([$usuarioId, $permissaoId]);
                }
            }
            $pdo->commit();
        } catch (Throwable) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Falha ao salvar as permissões do usuário.'];
        }

        unset(self::$permissoesPorUsuario[$usuarioId]);
        return ['ok' => true, 'total' => count($idsFiltrados)];
    }

    private static function idsPermissoesAtivas(): array
    {
        $stmt = Database::conn()->query('SELECT id FROM permissoes WHERE ativo = 1');
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Resolução centralizada do destino inicial após login — usa a SESSÃO já estabelecida por
     * `Auth::establishSession()`. `/admin` (People Analytics) exige `dashboard.visualizar` desde
     * a correção deste problema: um usuário autenticado sem essa permissão não pode mais cair
     * direto num 403 só por ter feito login. Nunca inventa permissão nova para isto — cada
     * candidato abaixo usa exatamente a mesma condição de acesso já aplicada no controller/sidebar
     * da rota correspondente, em ordem de especificidade (mais funcional primeiro), terminando
     * numa rota aberta por role a admin/rh/viewer (garantida hoje, nunca gera loop de redirect).
     */
    public static function primeiraRotaAcessivel(): string
    {
        if (self::temPermissao('dashboard.visualizar')) {
            return '/admin';
        }
        if (self::temPermissao('dashboard_recrutamento.visualizar')) {
            return '/admin/dashboard-recrutamento';
        }

        $isAdminOuSupervisor = Auth::role() === 'admin' || !empty($_SESSION['user_is_supervisor']);
        $isStaff = $isAdminOuSupervisor || Auth::role() === 'rh';

        // Mesma condição de "Solicitações de vaga" já usada na sidebar ($vePedidosDeVaga) — é o
        // destino funcionalmente mais relevante para um usuário (ex.: gestor) cujas únicas
        // permissões são de Solicitação de Vaga/Kanban.
        if (
            $isStaff
            || self::temPermissao('solicitacao_vaga.visualizar')
            || self::temPermissao('solicitacao_vaga.criar')
            || self::temPermissao('kanban_vagas.visualizar')
        ) {
            return '/admin/solicitacoes-vaga';
        }

        // Candidaturas (AdminCandidaturasController::index) é aberta por role a admin/rh/viewer
        // sem nenhum gate de permissão individual hoje — cobre qualquer sessão autenticada válida
        // (o papel é sempre um destes três, ver Auth::establishSession()).
        if (in_array(Auth::role(), ['admin', 'rh', 'viewer'], true)) {
            return '/admin/candidaturas';
        }

        // Rede de segurança final: Manual de Uso é aberto por role a qualquer sessão autenticada
        // e nunca deveria ser necessário chegar aqui — evita loop de redirect caso surja um papel
        // fora de admin/rh/viewer no futuro.
        return '/admin/manual';
    }
}
