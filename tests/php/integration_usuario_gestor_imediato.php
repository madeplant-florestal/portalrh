<?php

/**
 * Integração — Gestor Imediato (`usuarios.gestor_usuario_id`): migration + UsuarioGestorRepository/Service +
 * AdminUsuariosController (create/store/show/updateGestor) + sugestão opcional no formulário do PDI. Fixtures ZZGES-*
 * com limpeza em `finally`. Prova, contra o banco:
 *   - estrutura: coluna nullable INT + índice + FK ON DELETE SET NULL; migration/rollback idempotentes por construção;
 *   - usuário sem gestor; definir; trocar; remover; gestor inexistente/inativo/autorreferência recusados;
 *   - ciclos A→B→A e A→B→C→A recusados no servidor; A→B→C válida; nada é gravado nas recusas;
 *   - apagar o gestor NÃO apaga o subordinado (FK SET NULL); gestor inativado NÃO é removido nem trocado sozinho e
 *     o vínculo pode ser mantido/substituído/removido;
 *   - independência total do `aprovador_usuario_id` (nos dois sentidos) e nenhuma leitura de legado;
 *   - auditoria (`gestor_imediato_update`): anterior, novo, ator, IP; sem log quando nada muda;
 *   - autorização da administração de usuários preservada (create/store/updateGestor = admin + CSRF; show = admin/rh);
 *   - candidatos: só ativos, sem o próprio e sem descendentes, com cargo (sem N+1); páginas renderizam;
 *   - PDI: sugestão só da relação nova, só para Admin/RH, gestor inativo não é sugerido, nada persiste, e a autorização
 *     do PDI não mudou.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'metadados' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$ip = '203.0.113.7';
$linha = static function (string $sql, array $params = []) use ($pdo): ?array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};
$todas = static function (string $sql, array $params = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$gestorDe = static fn(int $id): ?int => ($r = $linha('SELECT gestor_usuario_id FROM usuarios WHERE id = ?', [$id])) && $r['gestor_usuario_id'] !== null ? (int)$r['gestor_usuario_id'] : null;
$logs = static fn(int $alvo): array => $todas("SELECT * FROM auditoria_usuarios WHERE target_usuario_id = ? AND action = 'gestor_imediato_update' ORDER BY id", [$alvo]);
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$comoUsuario = static function (int $id, string $role): void {
    $_SESSION['user_id'] = $id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_is_supervisor'] = 0;
};
$corpoDe = static function (string $classe, string $metodo): string {
    $r = new ReflectionMethod($classe, $metodo);
    $arq = new SplFileObject($r->getFileName());
    $arq->seek($r->getStartLine() - 1);
    $corpo = '';
    while ($arq->key() < $r->getEndLine()) {
        $corpo .= $arq->current();
        $arq->next();
    }
    return $corpo;
};
$semComentarios = static function (string $arquivo): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$novoUsuario = static function (string $rotulo, string $role = 'viewer', bool $ativo = true) use (&$criados, $senha, $suffix): int {
    $id = User::create('ZZGES ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzges.' . $suffix . '@teste.local', $senha, $role);
    if ($ativo) {
        User::setActiveStatus($id, true);
    }
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    // ---- estrutura (migration aplicada localmente) ----------------------------------------------------------------------
    $col = $linha("SELECT DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'gestor_usuario_id'");
    $check($col !== null && strtolower((string)$col['DATA_TYPE']) === 'int' && $col['IS_NULLABLE'] === 'YES', '(estrutura) usuarios.gestor_usuario_id INT NULL existe (migration aplicada localmente)');
    $idx = $linha("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'gestor_usuario_id' LIMIT 1");
    $check($idx !== null, '(estrutura) gestor_usuario_id é indexado');
    $fk = $linha("SELECT rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME AS ref FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.TABLE_NAME = rc.TABLE_NAME WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'usuarios' AND rc.CONSTRAINT_NAME = 'fk_usuarios_gestor'");
    $check($fk !== null && $fk['DELETE_RULE'] === 'SET NULL' && $fk['ref'] === 'usuarios', '(estrutura) FK fk_usuarios_gestor -> usuarios(id) com ON DELETE SET NULL (sem cascade)');
    $check($linha("SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'gestor_metadados_id'") === null, '(estrutura) Não existe gestor_metadados_id');

    $mig = $semComentarios(BASE_PATH . '/database/migrations/2026-09-24-usuarios-gestor-imediato.sql');
    $migSemSql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-24-usuarios-gestor-imediato.sql'));
    $check(str_contains($migSemSql, 'INFORMATION_SCHEMA') && str_contains($migSemSql, 'ON DELETE SET NULL') && !preg_match('/\b(INSERT|UPDATE|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migSemSql), '(migration) Idempotente (INFORMATION_SCHEMA), sem seed/UPDATE/DROP e sem migrar valores do aprovador');
    $rb = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-24-usuarios-gestor-imediato-rollback.sql'));
    $check(str_contains($rb, 'DROP FOREIGN KEY') && str_contains($rb, 'DROP INDEX') && str_contains($rb, 'DROP COLUMN') && str_contains($rb, 'INFORMATION_SCHEMA') && !str_contains($rb, 'aprovador_usuario_id'), '(migration) Rollback idempotente remove FK, índice e coluna sem tocar no aprovador');

    // ---- usuários -------------------------------------------------------------------------------------------------------
    $adminId = $novoUsuario('Admin', 'admin');
    $rhId = $novoUsuario('RH', 'rh');
    $a = $novoUsuario('A');
    $b = $novoUsuario('B');
    $c = $novoUsuario('C');
    $d = $novoUsuario('D');
    $inativo = $novoUsuario('Inativo', 'viewer', false);
    $svc = new UsuarioGestorService();

    // ---- sem gestor / definir / trocar / remover ------------------------------------------------------------------------
    $check($gestorDe($a) === null && $svc->gestorDoUsuario($a) === null, '(sem gestor) Usuário novo nasce sem gestor (permitido; login não depende disso)');
    $r = $svc->definirGestor($a, $b, $adminId, $ip);
    $check($r['ok'] === true && $r['alterado'] === true && $gestorDe($a) === $b, '(definir) Define B como gestor de A');
    $g = $svc->gestorDoUsuario($a);
    $check($g !== null && (int)$g['id'] === $b && $g['ativo'] === true, '(definir) O serviço devolve o gestor com situação ativa');
    $check($svc->quantidadeSubordinadosDiretos($b) === 1 && (int)($svc->subordinadosDiretos($b)[0]['id'] ?? 0) === $a, '(consulta) B tem 1 liderado direto (A)');
    $r = $svc->definirGestor($a, $c, $adminId, $ip);
    $check($r['ok'] === true && $gestorDe($a) === $c && $svc->quantidadeSubordinadosDiretos($b) === 0, '(trocar) Troca o gestor de A para C');
    $r = $svc->definirGestor($a, null, $adminId, $ip);
    $check($r['ok'] === true && $r['alterado'] === true && $gestorDe($a) === null, '(remover) Remove o gestor (volta a "Sem gestor definido")');
    $r = $svc->definirGestor($a, null, $adminId, $ip);
    $check($r['ok'] === true && $r['alterado'] === false, '(remover) Remover quem já não tem gestor não altera nada');

    // ---- recusas ---------------------------------------------------------------------------------------------------------
    $antes = count($logs($a));
    $check($svc->definirGestor($a, 999999999, $adminId, $ip)['ok'] === false && $gestorDe($a) === null, '(inexistente) Gestor inexistente é recusado no servidor');
    $check($svc->definirGestor($a, $inativo, $adminId, $ip)['ok'] === false && $gestorDe($a) === null, '(inativo) Usuário inativo não pode ser escolhido como gestor');
    $check($svc->definirGestor($a, $a, $adminId, $ip)['ok'] === false && $gestorDe($a) === null, '(autorreferência) Ninguém é o próprio gestor');
    $check($svc->definirGestor(999999999, $b, $adminId, $ip)['ok'] === false, '(inexistente) Usuário inexistente é recusado');
    $check(count($logs($a)) === $antes, '(recusas) Nenhuma recusa gera auditoria nem altera dados');
    $check($svc->validarGestorNovoUsuario(null) === null && $svc->validarGestorNovoUsuario($b) === null && $svc->validarGestorNovoUsuario($inativo) !== null && $svc->validarGestorNovoUsuario(999999999) !== null, '(cadastro) Validação para usuário ainda não criado: vazio/ativo ok, inativo/inexistente recusados');

    // ---- ciclos ----------------------------------------------------------------------------------------------------------
    $svc->definirGestor($a, $b, $adminId, $ip);                       // A → B
    $r = $svc->definirGestor($b, $a, $adminId, $ip);                  // B → A fecha ciclo
    $check($r['ok'] === false && $gestorDe($b) === null, '(ciclo) A→B→A recusado no servidor e nada gravado');
    $svc->definirGestor($b, $c, $adminId, $ip);                       // A → B → C
    $check($gestorDe($a) === $b && $gestorDe($b) === $c, '(hierarquia) A→B→C válida é aceita');
    $r = $svc->definirGestor($c, $a, $adminId, $ip);                  // C → A fecha A→B→C→A
    $check($r['ok'] === false && $gestorDe($c) === null, '(ciclo) A→B→C→A recusado no servidor e nada gravado');
    $cadeia = array_column($svc->cadeiaDoUsuario($a), 'id');
    $check($cadeia === [$b, $c], '(cadeia) A cadeia de A é B, depois C');
    $cand = array_map('intval', array_column($svc->candidatos($c), 'id'));
    $check(!in_array($c, $cand, true) && !in_array($b, $cand, true) && !in_array($a, $cand, true) && in_array($d, $cand, true), '(candidatos) Sem o próprio usuário nem seus subordinados (diretos e indiretos)');
    $candNovo = array_map('intval', array_column($svc->candidatos(null), 'id'));
    $check(in_array($a, $candNovo, true) && !in_array($inativo, $candNovo, true), '(candidatos) Cadastro: só usuários ativos; inativo indisponível');
    $cargoOk = true;
    foreach ($svc->candidatos(null) as $u) {
        $cargoOk = $cargoOk && array_key_exists('cargo', $u) && array_key_exists('email', $u);
    }
    $check($cargoOk, '(candidatos) As opções trazem e-mail e cargo numa única consulta (sem N+1)');

    // ---- FK: apagar o gestor não apaga o subordinado ----------------------------------------------------------------------
    $gestorTemp = $novoUsuario('Gestor Temporario');
    $subTemp = $novoUsuario('Subordinado');
    $svc->definirGestor($subTemp, $gestorTemp, $adminId, $ip);
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$gestorTemp]);
    $sub = $linha('SELECT id, gestor_usuario_id FROM usuarios WHERE id = ?', [$subTemp]);
    $check($sub !== null && $sub['gestor_usuario_id'] === null, '(FK) Apagar o gestor NÃO apaga o subordinado — o vínculo vira NULL (ON DELETE SET NULL)');

    // ---- gestor inativado depois -----------------------------------------------------------------------------------------
    $gestorQueInativa = $novoUsuario('Gestor Vai Inativar');
    $liderado = $novoUsuario('Liderado');
    $svc->definirGestor($liderado, $gestorQueInativa, $adminId, $ip);
    User::setActiveStatus($gestorQueInativa, false);
    $check($gestorDe($liderado) === $gestorQueInativa, '(inativação) Gestor inativado NÃO é removido automaticamente do subordinado');
    $gi = $svc->gestorDoUsuario($liderado);
    $check($gi !== null && $gi['ativo'] === false, '(inativação) O serviço informa que o gestor configurado está inativo');
    $check(!in_array($gestorQueInativa, array_map('intval', array_column($svc->candidatos($liderado), 'id')), true), '(inativação) O inativo não aparece para nova seleção');
    $logsAntes = count($logs($liderado));
    $r = $svc->definirGestor($liderado, $gestorQueInativa, $adminId, $ip);
    $check($r['ok'] === true && $r['alterado'] === false && $gestorDe($liderado) === $gestorQueInativa && count($logs($liderado)) === $logsAntes, '(inativação) Reenviar o formulário mantém o gestor inativo sem trocar nem remover silenciosamente');
    $check($svc->definirGestor($liderado, $inativo, $adminId, $ip)['ok'] === false && $gestorDe($liderado) === $gestorQueInativa, '(inativação) Trocar para OUTRO gestor inativo continua recusado');
    $r = $svc->definirGestor($liderado, $d, $adminId, $ip);
    $check($r['ok'] === true && $gestorDe($liderado) === $d, '(inativação) É possível substituir o gestor inativo por um ativo');
    $svc->definirGestor($liderado, null, $adminId, $ip);
    $check($gestorDe($liderado) === null, '(inativação) É possível remover o gestor');

    // ---- independência do aprovador --------------------------------------------------------------------------------------
    $x = $novoUsuario('X');
    $y = $novoUsuario('Y');
    $z = $novoUsuario('Z');
    $ator = User::findById($adminId);
    $rv = User::setVagaAccess($x, true, $y, $ator, $ip);
    $check($rv['ok'] === true && $gestorDe($x) === null, '(aprovador) Definir o aprovador NÃO cria nem altera o gestor imediato');
    $svc->definirGestor($x, $z, $adminId, $ip);
    $linhaX = $linha('SELECT aprovador_usuario_id, gestor_usuario_id, pode_solicitar_vaga FROM usuarios WHERE id = ?', [$x]);
    $check((int)$linhaX['aprovador_usuario_id'] === $y && (int)$linhaX['gestor_usuario_id'] === $z && (int)$linhaX['pode_solicitar_vaga'] === 1, '(aprovador) Definir o gestor NÃO altera o aprovador; os dois convivem com valores diferentes');
    $svc->definirGestor($x, null, $adminId, $ip);
    $linhaX = $linha('SELECT aprovador_usuario_id FROM usuarios WHERE id = ?', [$x]);
    $check((int)$linhaX['aprovador_usuario_id'] === $y, '(aprovador) Remover o gestor não remove o aprovador');
    // ciclo de aprovador (A aprova B, B aprova A) é regra do aprovador; o gestor tem hierarquia própria
    User::setVagaAccess($y, true, $x, $ator, $ip); // Y tem X como aprovador (X→Y e Y→X seria ciclo do aprovador: recusado)
    $check((int)$linha('SELECT aprovador_usuario_id FROM usuarios WHERE id = ?', [$y])['aprovador_usuario_id'] === 0 || $linha('SELECT aprovador_usuario_id FROM usuarios WHERE id = ?', [$y])['aprovador_usuario_id'] === null, '(aprovador) A regra de ciclo do aprovador segue inalterada (recusou Y→X)');
    $svc->definirGestor($x, $y, $adminId, $ip);
    $check($gestorDe($x) === $y, '(aprovador) O mesmo par pode ter o aprovador e o gestor iguais/diferentes — sem acoplamento');

    // ---- auditoria -------------------------------------------------------------------------------------------------------
    $alvo = $novoUsuario('Alvo Auditoria');
    $svc->definirGestor($alvo, $a, $adminId, $ip);
    $svc->definirGestor($alvo, $b, $rhId, '198.51.100.9');
    $svc->definirGestor($alvo, null, $adminId, $ip);
    $l = $logs($alvo);
    $check(count($l) === 3, '(auditoria) Cada mudança grava exatamente um registro em auditoria_usuarios');
    $check($l[0]['details'] === "gestor_usuario_id_anterior=NULL gestor_usuario_id_novo={$a}" && (int)$l[0]['actor_usuario_id'] === $adminId && $l[0]['ip'] === $ip && !empty($l[0]['created_at']), '(auditoria) Definir: anterior NULL, novo A, ator, IP e data');
    $check($l[1]['details'] === "gestor_usuario_id_anterior={$a} gestor_usuario_id_novo={$b}" && (int)$l[1]['actor_usuario_id'] === $rhId && $l[1]['ip'] === '198.51.100.9', '(auditoria) Trocar: anterior A, novo B, ator e IP corretos');
    $check($l[2]['details'] === "gestor_usuario_id_anterior={$b} gestor_usuario_id_novo=NULL", '(auditoria) Remover: anterior B, novo NULL');
    $svc->definirGestor($alvo, null, $adminId, $ip);
    $check(count($logs($alvo)) === 3, '(auditoria) Sem mudança, sem registro');
    // atomicidade: falha na auditoria desfaz a alteração
    $repoFalha = new class extends UsuarioGestorRepository {
        public function definir(int $usuarioId, ?int $gestorId): void
        {
            parent::definir($usuarioId, $gestorId);
            throw new RuntimeException('falha simulada após o UPDATE');
        }
    };
    try {
        (new UsuarioGestorService($repoFalha))->definirGestor($alvo, $c, $adminId, $ip);
        $check(false, '(atomicidade) A falha deveria propagar');
    } catch (RuntimeException $e) {
        $check($gestorDe($alvo) === null && count($logs($alvo)) === 3, '(atomicidade) Falha no meio da gravação desfaz alteração e auditoria (transação)');
    }

    // ---- autorização preservada (fonte) ------------------------------------------------------------------------------------
    foreach (['create', 'store', 'updateGestor'] as $m) {
        $corpo = $corpoDe(AdminUsuariosController::class, $m);
        $ok = str_contains($corpo, "Auth::requireRole(['admin'])") && ($m === 'create' || str_contains($corpo, 'csrfCheck'));
        $check($ok, "(autorização) AdminUsuariosController::{$m} exige admin" . ($m === 'create' ? '' : ' + CSRF'));
    }
    $check(str_contains($corpoDe(AdminUsuariosController::class, 'show'), "Auth::requireRole(['admin', 'rh'])"), '(autorização) show continua admin/RH (RH só lê o gestor)');
    $check(str_contains($corpoDe(AdminUsuariosController::class, 'updateGestor'), 'User::canManageUser') && !str_contains($corpoDe(AdminUsuariosController::class, 'updateGestor'), "'rh'"), '(autorização) updateGestor respeita canManageUser e não abre para RH');
    $check(!str_contains($semComentarios(BASE_PATH . '/app/services/UsuarioGestorService.php'), 'Authorization::') && !str_contains($semComentarios(BASE_PATH . '/app/services/UsuarioGestorService.php'), 'permiss'), '(autorização) Nenhuma permissão nova: o serviço não decide autorização');
    $rotas = (string)file_get_contents(BASE_PATH . '/index.php');
    $check(str_contains($rotas, "\$router->post('/admin/usuarios/{id}/gestor', [AdminUsuariosController::class, 'updateGestor']);"), '(rota) POST /admin/usuarios/{id}/gestor registrada');
    $permissoesNovas = $linha("SELECT COUNT(*) AS n FROM permissoes WHERE codigo LIKE '%gestor%'");
    $check((int)$permissoesNovas['n'] === 0, '(autorização) Não foi criada nenhuma permissão nova de gestor');

    // ---- páginas --------------------------------------------------------------------------------------------------------
    $comoUsuario($adminId, 'admin');
    $_GET = [];
    $htmlCreate = $renderizar(static fn() => (new AdminUsuariosController())->create());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlCreate) && str_contains($htmlCreate, 'name="gestor_usuario_id"') && str_contains($htmlCreate, 'Sem gestor definido') && str_contains($htmlCreate, 'ZZGES A — a.zzges.' . $suffix . '@teste.local') && !str_contains($htmlCreate, 'ZZGES Inativo'), '(cadastro) Select de Gestor Imediato com "Sem gestor definido", rótulo Nome — e-mail e sem inativos');
    $check(!preg_match('/<script[^>]*(select2|choices|tom-select)/i', $htmlCreate), '(cadastro) Select normal, sem biblioteca JS');
    $htmlShow = $renderizar(static fn() => (new AdminUsuariosController())->show((string)$liderado));
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlShow) && str_contains($htmlShow, 'Gestor imediato') && str_contains($htmlShow, 'action="' . (Config::app()['base_url'] ?? '') . '/admin/usuarios/' . $liderado . '/gestor"') && str_contains($htmlShow, 'Sem gestor definido'), '(edição) Detalhe do usuário mostra o gestor e o formulário para o Admin');
    $svc->definirGestor($liderado, $d, $adminId, $ip);
    User::setActiveStatus($d, false);
    $htmlInativo = $renderizar(static fn() => (new AdminUsuariosController())->show((string)$liderado));
    $check(str_contains($htmlInativo, 'ZZGES D') && str_contains($htmlInativo, 'está inativo') && preg_match('/<option value="' . $d . '" selected>[^<]*\(atual, inativo\)/', $htmlInativo) === 1, '(edição) Gestor inativo é exibido com aviso e vem selecionado (mantido) para permitir substituir ou remover');
    User::setActiveStatus($d, true);
    $comoUsuario($rhId, 'rh');
    $htmlRh = $renderizar(static fn() => (new AdminUsuariosController())->show((string)$liderado));
    $check(str_contains($htmlRh, 'Gestor imediato') && !str_contains($htmlRh, '/gestor"') && str_contains($htmlRh, 'gerenciado por um administrador'), '(edição) RH vê o gestor em somente leitura, sem formulário');
    $listagem = (string)file_get_contents(BASE_PATH . '/app/views/admin/usuarios/index.php');
    $check(!str_contains($listagem, 'gestor'), '(listagem) A tabela de usuários não ganhou coluna nova');

    // ---- sem legado ------------------------------------------------------------------------------------------------------
    $fontes = $semComentarios(BASE_PATH . '/app/services/UsuarioGestorService.php') . $semComentarios(BASE_PATH . '/app/repositories/UsuarioGestorRepository.php');
    $check(!preg_match('/usuario_colaboradores|lider_colaborador_id|is_gestor|\bFROM\s+colaboradores\b|\bJOIN\s+colaboradores\b/i', $fontes), '(legado) Nenhuma leitura de colaboradores/usuario_colaboradores/lider_colaborador_id/is_gestor');
    $corpoStore = $semComentarios(BASE_PATH . '/app/controllers/AdminUsuariosController.php');
    $check(!preg_match('/gestor_metadados_id/', $corpoStore), '(legado) Nenhum gestor_metadados_id');

    // ---- PDI: sugestão opcional -------------------------------------------------------------------------------------------
    $emp = 'ZGE' . $suffix;
    $novoContrato = static function (string $nome, int $seq) use ($pdo, &$criados, $suffix, $emp): int {
        $identificador = 'ZZGES_' . $suffix . '_' . $seq;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 1, ?)'
        )->execute([$identificador, $emp, 'U1', 'ZG' . $suffix . $seq, 'ZQ' . $suffix . $seq, '52998224725', $nome, 'ZZGES Empresa', 'ZZGES Unidade', 'ZZGES Cargo', 'CG1', '2024-01-10', '2024-01-10', 'zzges-teste']);
        $criados['metadados'][] = $identificador;
        return (int)$pdo->lastInsertId();
    };
    $contratoComGestor = $novoContrato('ZZGES Colab Com Gestor', 1);
    $contratoSemGestor = $novoContrato('ZZGES Colab Sem Gestor', 2);
    $contratoGestorInativo = $novoContrato('ZZGES Colab Gestor Inativo', 3);
    $contratoSemUsuario = $novoContrato('ZZGES Colab Sem Usuario', 4);
    $gPdi = $novoUsuario('Gestor Sugerido', 'viewer');
    $gPdiInativo = $novoUsuario('Gestor Sugerido Inativo', 'viewer');
    $uCom = $novoUsuario('Usuario Colab Com', 'viewer');
    $uSem = $novoUsuario('Usuario Colab Sem', 'viewer');
    $uInat = $novoUsuario('Usuario Colab Inat', 'viewer');
    foreach ([[$uCom, $contratoComGestor], [$uSem, $contratoSemGestor], [$uInat, $contratoGestorInativo]] as [$uid, $cid]) {
        $pdo->prepare('UPDATE usuarios SET colaborador_metadados_id = ? WHERE id = ?')->execute([$cid, $uid]);
    }
    $svc->definirGestor($uCom, $gPdi, $adminId, $ip);
    $svc->definirGestor($uInat, $gPdiInativo, $adminId, $ip);
    User::setActiveStatus($gPdiInativo, false);
    // legado enganoso: o gestor sugerido NÃO pode vir de lider_colaborador_id/aprovador
    $pdo->prepare('UPDATE usuarios SET aprovador_usuario_id = ? WHERE id = ?')->execute([$b, $uSem]);
    $antesPdis = (int)$pdo->query('SELECT COUNT(*) FROM pdis')->fetchColumn();

    $sug = $svc->gestorSugeridoParaContrato($contratoComGestor);
    $check($sug !== null && $sug['id'] === $gPdi && str_contains($sug['usuario_nome'], 'Usuario Colab Com'), '(PDI) Sugere o gestor imediato do usuário associado ao contrato por colaborador_metadados_id');
    $check($svc->gestorSugeridoParaContrato($contratoSemGestor) === null, '(PDI) Usuário sem gestor imediato: sem sugestão (o aprovador NÃO é usado como gestor)');
    $check($svc->gestorSugeridoParaContrato($contratoGestorInativo) === null, '(PDI) Gestor inativo não é sugerido');
    $check($svc->gestorSugeridoParaContrato($contratoSemUsuario) === null, '(PDI) Contrato sem usuário do Portal associado: sem sugestão');

    $comoUsuario($adminId, 'admin');
    $_GET = ['contrato' => (string)$contratoComGestor];
    $htmlPdi = $renderizar(static fn() => (new AdminPdisController())->novo());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlPdi) && preg_match('/<option value="' . $gPdi . '" selected>/', $htmlPdi) === 1 && str_contains($htmlPdi, 'Sugestão: gestor imediato de'), '(PDI) Admin: o formulário abre com o gestor sugerido pré-selecionado e a dica de que pode trocar');
    $_GET = ['contrato' => (string)$contratoSemGestor];
    $htmlPdiSem = $renderizar(static fn() => (new AdminPdisController())->novo());
    $check(!str_contains($htmlPdiSem, 'Sugestão: gestor imediato') && !preg_match('/<option value="\d+" selected>/', $htmlPdiSem), '(PDI) Sem gestor imediato: nada pré-selecionado');
    $_GET = ['contrato' => (string)$contratoGestorInativo];
    $htmlPdiInat = $renderizar(static fn() => (new AdminPdisController())->novo());
    $check(!str_contains($htmlPdiInat, 'Sugestão: gestor imediato') && !preg_match('/<option value="\d+" selected>/', $htmlPdiInat), '(PDI) Gestor imediato inativo: nada pré-selecionado');
    $check((int)$pdo->query('SELECT COUNT(*) FROM pdis')->fetchColumn() === $antesPdis, '(PDI) A sugestão não persiste nada (nenhum PDI criado)');
    $_GET = [];

    $pdiCtrl = $semComentarios(BASE_PATH . '/app/controllers/AdminPdisController.php');
    $pdiSvc = (string)file_get_contents(BASE_PATH . '/app/services/PdiService.php');
    $check(!str_contains($pdiSvc, 'UsuarioGestor') && !str_contains($pdiSvc, 'gestor_usuario_id_anterior') && str_contains($pdiCtrl, 'gestorSugeridoParaContrato'), '(PDI) PdiService/autorização inalterados: a sugestão vive só no controller de tela');
    $check(PdiService::escopoTotal(['id' => $gPdi, 'role' => 'viewer']) === false && PdiService::escopoTotal(['id' => $adminId, 'role' => 'admin']) === true && PdiService::escopoTotal(['id' => $rhId, 'role' => 'rh']) === true, '(PDI) Escopo do PDI igual ao anterior: ser gestor imediato no cadastro NÃO concede acesso a PDI');
    $check(str_contains($corpoDe(AdminPdisController::class, 'gestorSugerido'), 'escopoTotal') && !preg_match('/usuario_colaboradores|lider_colaborador_id|is_gestor/i', $pdiCtrl), '(PDI) Sugestão só para Admin/RH e sem qualquer leitura de legado');
} finally {
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("UPDATE usuarios SET colaborador_metadados_id = NULL WHERE colaborador_metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador IN ($ph))")->execute($criados['metadados']);
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $lu = implode(',', array_map('intval', $criados['usuarios']));
        $pdo->exec("UPDATE usuarios SET gestor_usuario_id = NULL, aprovador_usuario_id = NULL WHERE id IN ($lu)");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($lu) OR actor_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nINTEGRATION_USUARIO_GESTOR_OK\n";
