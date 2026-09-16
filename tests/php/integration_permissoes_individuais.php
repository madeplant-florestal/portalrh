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
 *     ID inexistente/inativo;
 *   - CAPACIDADE (acessar o módulo Kanban) é independente de ESCOPO DE REGISTRO (quais
 *     solicitações um usuário pode ver): `AdminSolicitacoesVagaKanbanController::index()` exige de
 *     verdade `kanban_vagas.visualizar` no backend (não só esconde o botão/menu) — Admin pelo
 *     bypass central, RH/Supervisor só com a permissão concedida individualmente; `move()` não tem
 *     mais bypass de `role`/`is_supervisor`, só Admin (central) + `kanban_vagas.movimentar`. Um
 *     aprovador sem nenhuma permissão de Kanban continua acessando a Solicitação específica que
 *     precisa aprovar (via `findAccessible()`, inalterado), mas não o Kanban.
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
$criados = ['usuarios' => [], 'permissoes' => [], 'solicitacoes' => [], 'setores' => [], 'cargos' => []];

$limparSolicitacao = static function (int $id) use ($pdo): void {
    foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
};

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

// `index()`/`move()` chamam http_response_code()+exit()/return em caso de bloqueio — não dá para
// invocá-los diretamente num script de teste sem simular uma requisição HTTP completa (não há
// harness disso neste projeto). Em vez de inventar um, seguimos o mesmo padrão já usado em
// integration_solicitacao_vaga_contexto_organizacional.php: lê o CÓDIGO-FONTE do método via
// reflexão e confirma que a checagem certa está lá — combinado com testar a condição em si
// (Authorization::usuarioTemPermissao) isoladamente, cobre a mesma garantia sem exit() no meio do teste.
$corpoDoMetodo = static function (string $classe, string $metodo): string {
    $reflexao = new ReflectionMethod($classe, $metodo);
    $arquivo = new SplFileObject($reflexao->getFileName());
    $arquivo->seek($reflexao->getStartLine() - 1);
    $corpo = '';
    while ($arquivo->key() < $reflexao->getEndLine()) {
        $corpo .= $arquivo->current();
        $arquivo->next();
    }
    return $corpo;
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

    // Reproduz a decisão REAL de AdminSolicitacoesVagaKanbanController::move() — hoje SEM bypass
    // de role/is_supervisor, só Authorization (Admin entra pelo bypass central dela).
    $podeMoverGestor = Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.movimentar');
    $check($podeMoverGestor === false, 'Tentativa direta de mover card pelo Gestor é rejeitada pela mesma checagem usada em move() (backend, não só UI)');

    // ---- 4b. Kanban: kanban_vagas.visualizar é gate REAL de index(), não só cosmético ----------
    // Fonte-única do bypass de Admin é `Authorization` — nem role='rh' nem is_supervisor=1 dão
    // acesso ao módulo Kanban por si só; RH/Supervisor sem a permissão são bloqueados, com ela
    // acessam. Confirma também que `index()`/`move()` chamam a checagem certa no CÓDIGO (não só
    // que a condição em si retorna o valor certo isoladamente).
    $corpoIndex = $corpoDoMetodo(AdminSolicitacoesVagaKanbanController::class, 'index');
    $check(str_contains($corpoIndex, "Authorization::requirePermissao('kanban_vagas.visualizar')"), "index() do Kanban chama Authorization::requirePermissao('kanban_vagas.visualizar') no código-fonte");

    $corpoMove = $corpoDoMetodo(AdminSolicitacoesVagaKanbanController::class, 'move');
    $check(str_contains($corpoMove, "kanban_vagas.movimentar"), 'move() do Kanban checa kanban_vagas.movimentar no código-fonte');
    $check(!str_contains($corpoMove, 'userCanEditRh'), 'move() do Kanban NÃO tem mais bypass de role/is_supervisor (userCanEditRh) para movimentar — só Authorization');

    $check(Authorization::usuarioTemPermissao($adminId, 'kanban_vagas.visualizar') === true, '(1) Admin acessa o Kanban pelo bypass central');

    $check(Authorization::usuarioTemPermissao($rhId, 'kanban_vagas.visualizar') === false, '(2) RH sem kanban_vagas.visualizar é bloqueado (mesma checagem de index())');
    $syncRh = Authorization::sincronizar($rhId, [(int)array_search('kanban_vagas.visualizar', $idsPermissao, true)]);
    $check(($syncRh['ok'] ?? false) === true, 'concede kanban_vagas.visualizar ao RH para o próximo teste');
    $check(Authorization::usuarioTemPermissao($rhId, 'kanban_vagas.visualizar') === true, '(3) RH COM kanban_vagas.visualizar concedida individualmente acessa o Kanban');
    // Prova que role e permissão não se confundem: mesmo com a permissão de Kanban, RH continua
    // sem kanban_vagas.movimentar (não foi essa a permissão concedida).
    $check(Authorization::usuarioTemPermissao($rhId, 'kanban_vagas.movimentar') === false, 'RH com só kanban_vagas.visualizar continua sem poder movimentar (permissões são independentes)');

    $check(Authorization::usuarioTemPermissao($supervisorId, 'kanban_vagas.visualizar') === false, '(4) Supervisor sem kanban_vagas.visualizar é bloqueado');

    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.visualizar') === true, '(5) Gestor com kanban_vagas.visualizar concedida acessa o Kanban');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.detalhes') === true, '(6) Gestor com kanban_vagas.detalhes consegue abrir os detalhes permitidos');
    $check(Authorization::usuarioTemPermissao($gestorId, 'kanban_vagas.movimentar') === false, '(7) Gestor sem kanban_vagas.movimentar não movimenta (repetido aqui no contexto do gate de índex)');

    // (9) Aprovador sem NENHUMA permissão de Kanban continua acessando a Solicitação específica
    // que precisa aprovar (findAccessible — inalterado), mas não o módulo Kanban. Fixtures próprias
    // de Setor/Cargo oficiais + vínculo na matriz (mesmo padrão de integration_usuario_vaga_acesso.php),
    // não dependem de dado pré-existente em DEV.
    $aprovadorSemKanbanId = $mkUser('aprovsemkb', 'viewer');
    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZZS' . substr($mk, -5), 'SETOR ' . $mk, 'setor-' . strtolower($mk), 'RHMADEPLANT']);
    $setorTeste = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorTeste;

    $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZZC' . substr($mk, -5), 'CARGO ' . $mk, 'cargo-' . strtolower($mk), 'RHMADEPLANT']);
    $cargoTeste = (int)$pdo->lastInsertId();
    $criados['cargos'][] = $cargoTeste;

    $pdo->prepare('INSERT INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em) VALUES (?, ?, ?, NOW())')
        ->execute([$cargoTeste, $setorTeste, 'RHCONTRATOS']);

    User::setVagaAccess($gestorId, true, $aprovadorSemKanbanId);
    (new UsuarioContextoOrganizacionalService())->definirContextoManual($gestorId, null, $setorTeste, []);
    $payloadTeste = [
        'setor_id' => $setorTeste, 'quantidade_vagas' => 1, 'cargo_id' => $cargoTeste,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'pj', 'salario_previsto' => 'R$ 5.000,00',
        'previsto_orcamento' => '1', 'jornada_trabalho' => '44h semanais',
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];
    $idSolicitacaoTeste = SolicitacaoVaga::create($payloadTeste, $gestorId, '127.0.0.1');
    $criados['solicitacoes'][] = $idSolicitacaoTeste;

    $check(Authorization::usuarioTemPermissao($aprovadorSemKanbanId, 'kanban_vagas.visualizar') === false, '(9a) aprovador sem permissão nenhuma de Kanban não acessa o módulo Kanban');
    $acessoAprovador = SolicitacaoVaga::findAccessible($idSolicitacaoTeste, $aprovadorSemKanbanId, 'viewer', false);
    $check(is_array($acessoAprovador), '(9b) mas continua acessando, via findAccessible(), a Solicitação específica que precisa aprovar');

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
    foreach ($criados['solicitacoes'] as $id) {
        $limparSolicitacao((int)$id);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id = ?')->execute([(int)$id]);
    }
    // Usuários ANTES de Setores: `usuario_setores` tem FK RESTRICT em setor_id e só é removida em
    // cascata quando o próprio usuário é apagado (mesmo padrão de integration_usuario_vaga_acesso.php).
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
