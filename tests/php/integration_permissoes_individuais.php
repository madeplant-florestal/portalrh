<?php

/**
 * Integração — Sprint "Controle de Acesso por Permissões Individuais"
 * (migration 2026-09-15-permissoes-individuais.sql + Authorization).
 *
 * Prova que:
 *   - Admin tem acesso total via bypass CENTRALIZADO no resolver, sem depender de nenhuma linha
 *     em `usuario_permissoes`;
 *   - RH NÃO recebe acesso total só por `role = rh` — precisa de permissão individual como
 *     qualquer outro usuário;
 *   - o caso real do Gestor: com as 4 permissões do caso de uso, cria Solicitação e vê o Kanban,
 *     mas NÃO pode movimentar card (nem via `Authorization`, nem via a decisão real do
 *     `AdminSolicitacoesVagaKanbanController::move()`, reproduzida aqui via reflexão);
 *   - usuário sem nenhuma permissão não passa em `canCreate()` (reflexão sobre o método privado do
 *     controller, mesmo padrão de outros testes deste projeto — ex.: unit_download_filename.php);
 *   - compatibilidade: usuário com `pode_solicitar_vaga = 1` recebe `solicitacao_vaga.criar` ao
 *     rodar o seed idempotente (2026-09-15-permissoes-individuais-seed.sql);
 *   - `Authorization::sincronizar()` (Tela de Usuários): adiciona, remove, nunca duplica, rejeita
 *     ID inexistente/inativo.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$mk = 'ZZPERM_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => [], 'permissoes' => []];

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

$invocarCanCreate = static function (int $userId, ?string $role, bool $isSupervisor) {
    $controller = new AdminSolicitacoesVagaController();
    $method = new ReflectionMethod(AdminSolicitacoesVagaController::class, 'canCreate');
    $method->setAccessible(true);
    return $method->invoke($controller, $userId, $role, $isSupervisor);
};

// Aplica um arquivo .sql simples (sem procedures/triggers) removendo primeiro as linhas de
// comentário `--` inteiras — split ingênuo por ';' quebraria em comentários que citam SQL
// (ex.: "`role = rh`; supervisor..." no cabeçalho da migration desta sprint).
$aplicarSql = static function (string $caminho) use ($pdo): void {
    $sql = file_get_contents($caminho);
    $semComentarios = implode("\n", array_filter(
        explode("\n", (string)$sql),
        static fn (string $linha): bool => !str_starts_with(trim($linha), '--')
    ));
    foreach (array_filter(array_map('trim', explode(';', $semComentarios))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
};

try {
    // ---- 1. Admin: acesso total via bypass central, SEM nenhuma linha em usuario_permissoes ----
    $adminId = $mkUser('admin', 'admin');
    $check(
        Authorization::usuarioTemPermissao($adminId, 'kanban_vagas.movimentar') === true,
        'Admin tem kanban_vagas.movimentar mesmo sem nenhuma permissão individual atribuída'
    );
    $check(
        (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id = {$adminId}")->fetchColumn() === 0,
        'Bypass do Admin não grava nenhuma linha em usuario_permissoes (é decisão do resolver, não do dado)'
    );

    // ---- 2. RH: role = rh NÃO dá acesso total a permissão individual --------------------------
    $rhId = $mkUser('rh', 'rh');
    $check(
        Authorization::usuarioTemPermissao($rhId, 'kanban_vagas.movimentar') === false,
        'RH sem permissão individual NÃO tem kanban_vagas.movimentar só por role=rh'
    );
    $check(
        Authorization::usuarioTemPermissao($rhId, 'solicitacao_vaga.visualizar') === false,
        'RH sem permissão individual NÃO tem solicitacao_vaga.visualizar só por role=rh'
    );
    // RH continua criando Solicitação hoje pelo caminho antigo (userCanEditRh), preservado — não é
    // a permissão individual que autoriza o RH, é a regra legada intacta (ver canCreate()).
    $check(
        $invocarCanCreate($rhId, 'rh', false) === true,
        'RH continua podendo abrir Solicitação de Vaga pelo caminho legado (userCanEditRh), sem depender de permissão individual'
    );

    // ---- 3. Supervisor: is_supervisor = 1 NÃO dá acesso total a permissão individual ----------
    $supervisorId = $mkUser('sup', 'viewer');
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supervisorId]);
    $check(
        Authorization::usuarioTemPermissao($supervisorId, 'kanban_vagas.movimentar') === false,
        'Supervisor sem permissão individual NÃO tem kanban_vagas.movimentar só por is_supervisor=1'
    );

    // ---- 4. Caso real do Gestor -----------------------------------------------------------------
    $gestorId = $mkUser('gestor', 'viewer');
    $codigosGestor = ['solicitacao_vaga.visualizar', 'solicitacao_vaga.criar', 'kanban_vagas.visualizar', 'kanban_vagas.detalhes'];
    $idsPermissao = $pdo->query('SELECT id, codigo FROM permissoes')->fetchAll(PDO::FETCH_KEY_PAIR);
    $idsGestor = [];
    foreach ($codigosGestor as $codigo) {
        $id = array_search($codigo, $idsPermissao, true);
        $check($id !== false, "permissão '{$codigo}' existe no catálogo (seed aplicado)");
        if ($id !== false) {
            $idsGestor[] = (int)$id;
        }
    }
    $syncGestor = Authorization::sincronizar($gestorId, $idsGestor);
    $check(($syncGestor['ok'] ?? false) === true && (int)($syncGestor['total'] ?? 0) === count($idsGestor), 'sincronizar() concede as 4 permissões do caso de uso do Gestor');

    $check($invocarCanCreate($gestorId, 'viewer', false) === true, 'Gestor com solicitacao_vaga.criar consegue passar em canCreate() (via caminho aditivo da permissão individual)');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.visualizar') === true, 'Gestor vê o Kanban');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.detalhes') === true, 'Gestor abre detalhes do card');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.editar') === false, 'Gestor NÃO tem kanban_vagas.editar (não concedida)');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.movimentar') === false, 'Gestor NÃO tem kanban_vagas.movimentar (não concedida)');
    $check(Authorization::usuarioTemPermissao($gestorId, 'solicitacao_vaga.editar') === false, 'Gestor NÃO tem solicitacao_vaga.editar (não concedida)');
    $check(Authorization::usuarioTemPermissao($gestorId, 'solicitacao_vaga.cancelar') === false, 'Gestor NÃO tem solicitacao_vaga.cancelar (não concedida)');

    // Reproduz a decisão REAL de AdminSolicitacoesVagaKanbanController::move(): bloqueia
    // movimentação para quem não é admin/rh/supervisor e não tem kanban_vagas.movimentar.
    $podeMoverGestor = SolicitacaoVaga::userCanEditRh('viewer', false) || Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.movimentar');
    $check($podeMoverGestor === false, 'Tentativa direta de mover card pelo Gestor é rejeitada pela mesma checagem usada em move() (backend, não só UI)');

    // ---- 5. Usuário sem NENHUMA permissão -------------------------------------------------------
    $semPermId = $mkUser('semperm', 'viewer');
    $check($invocarCanCreate($semPermId, 'viewer', false) === false, 'Usuário sem pode_solicitar_vaga e sem permissão individual não passa em canCreate()');
    $check(Authorization::usuarioTemPermissao($semPermId, 'kanban_vagas.visualizar') === false, 'Usuário sem permissão nenhuma não tem kanban_vagas.visualizar');

    // ---- 6. Compatibilidade: pode_solicitar_vaga=1 -> ganha solicitacao_vaga.criar via seed ----
    $legadoId = $mkUser('legado', 'viewer');
    User::setVagaAccess($legadoId, true, $adminId);
    // Checagem "antes" via SQL direto (não por Authorization::usuarioTemPermissao()) para não
    // poluir o cache por-request da classe com um resultado que o seed, rodado logo abaixo, torna
    // stale — o cache é por design (1 request real nunca muda dado no meio do caminho).
    $antesDoSeed = (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = {$legadoId} AND p.codigo = 'solicitacao_vaga.criar'")->fetchColumn();
    $check($antesDoSeed === 0, 'ANTES do seed: pode_solicitar_vaga=1 ainda não implica a permissão individual');

    $caminhoSeed = __DIR__ . '/../../database/migrations/2026-09-15-permissoes-individuais-seed.sql';
    $check(is_file($caminhoSeed), 'arquivo de seed existe para o teste de compatibilidade');
    $aplicarSql($caminhoSeed);
    $check(Authorization::usuarioTemPermissao($legadoId, 'solicitacao_vaga.criar') === true, 'DEPOIS do seed: usuário com pode_solicitar_vaga=1 recebeu solicitacao_vaga.criar (nunca perde a capacidade na transição)');
    $check($invocarCanCreate($legadoId, 'viewer', false) === true, 'usuário legado continua conseguindo abrir Solicitação de Vaga após o seed');

    // Reexecutar o seed é idempotente (INSERT IGNORE) — não duplica nem falha.
    $aplicarSql($caminhoSeed);
    $totalVinculo = (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = {$legadoId} AND p.codigo = 'solicitacao_vaga.criar'")->fetchColumn();
    $check($totalVinculo === 1, 'reexecutar o seed é idempotente: não duplica o vínculo usuario_permissoes');

    // ---- 7. Authorization::sincronizar() — adiciona, remove, nunca duplica, rejeita inválido ---
    $telaId = $mkUser('tela', 'viewer');
    $codVisualizar = (int)array_search('solicitacao_vaga.visualizar', $idsPermissao, true);
    $codCriar = (int)array_search('solicitacao_vaga.criar', $idsPermissao, true);

    $r1 = Authorization::sincronizar($telaId, [$codVisualizar]);
    $check(($r1['ok'] ?? false) === true && (int)($r1['total'] ?? 0) === 1, 'sincronizar() concede 1 permissão');
    $check(count(Authorization::idsAtribuidos($telaId)) === 1, 'idsAtribuidos() reflete a concessão');

    $r2 = Authorization::sincronizar($telaId, [$codVisualizar, $codVisualizar, $codCriar]);
    $check(($r2['ok'] ?? false) === true && (int)($r2['total'] ?? 0) === 2, 'sincronizar() com ID repetido no payload não duplica (2 permissões distintas -> total=2)');
    $totalLinhas = (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id = {$telaId}")->fetchColumn();
    $check($totalLinhas === 2, 'usuario_permissoes tem exatamente 2 linhas para o usuário (sem duplicidade física)');

    $r3 = Authorization::sincronizar($telaId, [$codVisualizar]);
    $check((int)($r3['total'] ?? -1) === 1, 'sincronizar() remove o que não está mais marcado (volta a 1 permissão)');

    $r4 = Authorization::sincronizar($telaId, [999999999]);
    $check((int)($r4['total'] ?? -1) === 0, 'sincronizar() rejeita ID de permissão inexistente (filtrado, não grava)');

    $pdo->prepare('UPDATE permissoes SET ativo = 0 WHERE id = ?')->execute([$codCriar]);
    $criados['permissoes'][] = $codCriar; // reativar no finally
    $r5 = Authorization::sincronizar($telaId, [$codCriar]);
    $check((int)($r5['total'] ?? -1) === 0, 'sincronizar() rejeita ID de permissão INATIVA');
    $check(Authorization::usuarioTemPermissao($telaId, 'solicitacao_vaga.criar') === false, 'permissão inativa não é concedida nem reconhecida por usuarioTemPermissao()');

    // ---- 8. catalogoPorModulo() agrupa corretamente -------------------------------------------
    $catalogo = Authorization::catalogoPorModulo();
    $check(isset($catalogo['solicitacao_vaga']) && isset($catalogo['kanban_vagas']), 'catalogoPorModulo() agrupa por modulo (solicitacao_vaga e kanban_vagas presentes)');
    $check(count($catalogo['kanban_vagas'] ?? []) >= 3, 'módulo kanban_vagas retorna as permissões ativas cadastradas (>= 3, considerando a desativada acima)');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nPERMISSOES_INDIVIDUAIS_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['permissoes'] as $permId) {
        $pdo->prepare('UPDATE permissoes SET ativo = 1 WHERE id = ?')->execute([(int)$permId]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
