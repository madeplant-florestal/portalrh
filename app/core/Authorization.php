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

    /**
     * O usuário da SESSÃO tem acesso pela ROLE informada (ou é supervisor, como em `Auth::requireRole`) OU pela permissão
     * individual? Lógica pura, sem efeito colateral — usada por `requireRoleOuPermissao()` e por testes.
     *
     * Modelo ADITIVO deliberado (ver a seed 2026-09-16-permissoes-cobertura-portal): a permissão individual passa a liberar o
     * acesso de quem NÃO tem a role, sem retirar o de quem já entrava pela role. Admin entra pelo bypass central de
     * `temPermissao` (e também está na lista de roles).
     *
     * @param string[] $roles
     */
    public static function temAcessoPorRoleOuPermissao(array $roles, string $codigo): bool
    {
        if (!empty($_SESSION['user_is_supervisor'])) {
            return true;
        }
        if (!Auth::check()) {
            return false;
        }
        $role = strtolower(trim((string)(Auth::role() ?? '')));
        if (in_array($role, array_map(static fn($r) => strtolower(trim((string)$r)), $roles), true)) {
            return true;
        }
        return self::temPermissao($codigo);
    }

    /**
     * Gate de ENTRADA de tela: libera quem tem a role OU a permissão individual `$codigo` (ex.: `pipeline.visualizar`). Sem
     * sessão → /login; sem acesso → 403 (mesma convenção de `Auth::requireRole`). Use SÓ na leitura/entrada da funcionalidade;
     * ações sensíveis (escrita, pagamento, configuração, reenvio) mantêm o próprio `Auth::requireRole`.
     *
     * @param string[] $roles
     */
    public static function requireRoleOuPermissao(array $roles, string $codigo): void
    {
        if (empty($_SESSION['user_is_supervisor']) && !Auth::check()) {
            redirect('/login');
        }
        if (!self::temAcessoPorRoleOuPermissao($roles, $codigo)) {
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
     * `Auth::establishSession()`. Histórico: `/admin` era o dashboard People Analytics (exige `dashboard.visualizar`), então
     * este método escolhia um destino acessível por permissão para ninguém cair num 403 logo após o login. Com a Nova UI,
     * `/admin` é a Central do Portal (aberta a qualquer sessão autenticada; os cards já respeitam as permissões) e o
     * dashboard foi para `/admin/dashboard` — o destino passou a ser sempre a Central, sem loop de redirect.
     */
    public static function primeiraRotaAcessivel(): string
    {
        // Nova UI: a entrada do Portal é a CENTRAL (`/admin`, AdminCentralController) — não tem permissão própria, é aberta a
        // qualquer sessão autenticada com role admin/rh/viewer (ou supervisor, que passa por Auth::requireRole) e mostra só
        // os módulos que o usuário pode acessar. Por isso o destino deixou de depender de permissão individual: o que antes
        // exigia escolher entre dashboard.visualizar, dashboard_recrutamento, solicitações ou candidaturas agora é decidido
        // pelos cards (PortalNavegacaoService). O Dashboard (People Analytics) mudou para `/admin/dashboard` e mantém o gate.
        if (!empty($_SESSION['user_is_supervisor']) || in_array(Auth::role(), ['admin', 'rh', 'viewer'], true)) {
            return '/admin';
        }

        // Rede de segurança final (papel fora de admin/rh/viewer, que não deveria existir — ver Auth::establishSession()):
        // o Manual de Uso é aberto por role e nunca redireciona, então não há loop.
        return '/admin/manual';
    }
}
