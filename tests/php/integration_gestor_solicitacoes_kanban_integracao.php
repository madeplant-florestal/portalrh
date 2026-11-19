<?php

/**
 * Integração — Etapa 2C.2B: Solicitações de Vaga, Kanban de Vagas, Integração do Colaborador e Avaliação de Experiência (avaliar).
 *
 * Provas: (1) Solicitação/Kanban do Gestor = SOMENTE setor da solicitação ∈ Setores Gerenciados (solicitante/aprovador NÃO dão acesso;
 * o flag de supervisor não amplia); viewer legado segue pela regra antiga; Admin/RH/Supervisor Geral globais; criação revalida o setor
 * no backend; movimentação e anotação revalidam a CADA POST; listas de apoio sem dado de outro setor; aprovação de líder fechada ao
 * Gestor; (2) Integração do Colaborador fechada ao Viewer (mesmo com permissões gravadas) e ao Gestor; Pipeline/Candidaturas fechados;
 * (3) Experiência: Gestor avalia só no setor, CSRF, reabertura só Admin/RH, responsável forjado ignorado.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/fixture_setor_gerenciado.php';

SchemaManager::ensure();
SolicitacaoVaga::ensureSchema();
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

/** Serve `php://input` a partir de uma string (o CLI não tem corpo) — só durante a chamada do controller. */
class ZzEntradaPhp
{
    public static string $corpo = '';
    public $context;
    private int $pos = 0;
    public function stream_open($path, $mode, $options, &$opened): bool { return true; }
    public function stream_read($n) { $r = substr(self::$corpo, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen(self::$corpo); }
    public function stream_stat() { return []; }
}

$suf = substr((string)time(), -5) . random_int(10, 99);
$c = ['usuarios' => [], 'setores' => [], 'metadados' => [], 'solicitacoes' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$permId = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $permId[$r['codigo']] = (int)$r['id'];
}
$permsSol = ['solicitacao_vaga.visualizar', 'solicitacao_vaga.criar', 'kanban_vagas.visualizar', 'kanban_vagas.detalhes', 'kanban_vagas.movimentar'];
$permsGestor = array_merge($permsSol, ['avaliacao_experiencia.visualizar', 'avaliacao_experiencia.avaliar']);

$novo = static function (string $apelido, string $role, int $flag, array $perms) use ($pdo, $senha, $suf, &$c, $permId): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZS ' . $apelido, strtolower($apelido) . '.zzs.' . $suf . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $c['usuarios'][] = $id;
    foreach ($perms as $p) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $permId[$p]]);
    }
    return $id;
};
$mkSetor = static function (string $cod) use ($pdo, &$c): int {
    $id = zzSetorTeste($pdo, $cod, 'ZZS');
    $c['setores'][] = $id;
    return $id;
};
$cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
$stageId = static fn(string $slug): int => (int)(SolicitacaoVagaStage::findBySlug($slug)['id'] ?? 0);
$mkSolic = static function (int $setorId, int $solicitanteId, ?int $aprovadorLider = null) use ($pdo, $cargoId, &$c, $stageId): int {
    $pdo->prepare(
        "INSERT INTO solicitacoes_vaga (setor_id, quantidade_vagas, cargo_id, solicitante_usuario_id, tipo_vaga, tipo_contratacao, salario_previsto, previsto_orcamento, jornada_trabalho, escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio, urgencia, status_fluxo, situacao_kanban_id)
         VALUES (?, 1, ?, ?, 'nova_posicao', 'clt', 5000, 1, '44h', 'medio', '', 'operacional', ?, 'media', 'pendente_lider', ?)"
    )->execute([$setorId, $cargoId, $solicitanteId, date('Y-m-d', strtotime('+30 days')), $stageId('em-aprovacao')]);
    $id = (int)$pdo->lastInsertId();
    $c['solicitacoes'][] = $id;
    if ($aprovadorLider !== null) {
        $pdo->prepare("INSERT INTO solicitacao_vaga_aprovacoes (solicitacao_id, etapa, destinatario_usuario_id, status) VALUES (?, 'lider_imediato', ?, 'pendente')")->execute([$id, $aprovadorLider]);
    }
    return $id;
};
$mkContrato = static function (string $rotulo, string $codSetor) use ($pdo, $suf, &$c): int {
    static $n = 0;
    $n++;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa, unidade, codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute(["ZZS{$suf}-{$n}", 'ZZSEMP', 'UNI', "ZZSC{$suf}{$n}", "ZZSP{$suf}{$n}", "ZZS2B {$rotulo}", 'ZZS Empresa', 'ZZS Unidade', $codSetor, 'ZZS Setor', 'ZZS Cargo', 'ZZSCG', date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-30 days')), 'zzs-teste']);
    $id = (int)$pdo->lastInsertId();
    $c['metadados'][] = $id;
    return $id;
};
$requisitar = static function (int $userId, string $role, bool $sess, string $controller, string $metodo, array $post = [], array $args = [], array $get = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sess ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m)) {
        $status = (int)$m[1];
        $saida = substr($saida, 0, -strlen($m[0]));
    }
    return [$status, $saida];
};
$valor = static function (string $sql, array $p = []) use ($pdo): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
};
$ids = static fn(array $linhas): array => array_map('intval', array_column($linhas, 'id'));

try {
    $codA = 'SA' . substr($suf, -5);
    $codB = 'SB' . substr($suf, -5);
    $sA = $mkSetor($codA);
    $sB = $mkSetor($codB);

    $g1 = $novo('g1', 'gestor', 0, $permsGestor);
    $gM = $novo('gmulti', 'gestor', 0, $permsGestor);
    $g0 = $novo('gzero', 'gestor', 0, $permsGestor);
    $gNP = $novo('gnp', 'gestor', 0, []);
    $gF = $novo('gflag', 'gestor', 1, $permsGestor);
    $vL = $novo('vlegado', 'viewer', 0, array_merge($permsSol, ['integracao_colaborador.visualizar', 'integracao_colaborador.editar']));
    $rh = $novo('rh', 'rh', 0, []);
    $adm = $novo('adm', 'admin', 0, []);
    $sg = $novo('sg', 'admin', 1, []);
    foreach ([$g1, $gM, $gF, $vL, $gNP] as $u) {
        zzGerenciar($pdo, $u, $sA);
    }
    zzGerenciar($pdo, $gM, $sB);

    // sA: do setor A (solicitante RH). sB: de OUTRO setor, tendo o g1 como SOLICITANTE. sB2: de outro setor, g1 como APROVADOR designado.
    $sa = $mkSolic($sA, $rh);
    $sb = $mkSolic($sB, $g1);
    $sb2 = $mkSolic($sB, $rh, $g1);
    $sv = $mkSolic($sB, $vL); // viewer legado é o solicitante de uma solicitação do setor B
    $papel = static fn(int $id, string $role = 'gestor'): array => ['id' => $id, 'role' => $role];

    // ---- 1. listagem / consulta por ID (model) ----------------------------------------------------------------------------------------
    $minhas = static fn(array $lista): array => array_values(array_intersect($lista, [$sa, $sb, $sb2, $sv]));
    $so = static function (array $lista, array $esperado) use ($minhas): bool {
        $a = $minhas($lista);
        sort($a);
        sort($esperado);
        return $a === $esperado;
    };
    $check($so($ids(SolicitacaoVaga::allForUser($g1, 'gestor', false)), [$sa]), '(1) Gestor de 1 setor: a lista traz só a solicitação do setor — NÃO a de outro setor onde ele é solicitante nem aprovador');
    $check($so($ids(SolicitacaoVaga::allForUser($gM, 'gestor', false)), [$sa, $sb, $sb2, $sv]), '(1) Gestor de 2 setores: vê as solicitações dos dois');
    $check($so($ids(SolicitacaoVaga::allForUser($g0, 'gestor', false)), []) && $so($ids(SolicitacaoVaga::allForKanban($g0, 'gestor', false)), []), '(1) Gestor SEM setores: conjunto vazio (lista e Kanban) — nunca acesso global');
    $check($so($ids(SolicitacaoVaga::allForUser($gF, 'gestor', true)), [$sa]) && $so($ids(SolicitacaoVaga::allForKanban($gF, 'gestor', true)), [$sa]), '(1) Gestor com is_supervisor=1 (inclusive passando o flag ao model): escopo igual ao do Gestor comum');
    $check(SolicitacaoVaga::findAccessible($sa, $g1, 'gestor', false) !== null && SolicitacaoVaga::findAccessible($sb, $g1, 'gestor', false) === null && SolicitacaoVaga::findAccessible($sb2, $g1, 'gestor', false) === null, '(1) Consulta por ID: do setor ok; de outro setor = não encontrada (mesmo sendo solicitante ou aprovador designado)');
    $check($so($ids(SolicitacaoVaga::allForKanban($g1, 'gestor', false, ['setor_id' => $sB])), []) && $so($ids(SolicitacaoVaga::allForKanban($g1, 'gestor', false, ['setor_id' => $sA])), [$sa]), '(1) Kanban: filtro de setor forjado (outro setor) não amplia o escopo');
    foreach ([['adm', $adm, 'admin', false], ['rh', $rh, 'rh', false], ['sg', $sg, 'admin', true]] as [$n, $u, $r, $f]) {
        $check($so($ids(SolicitacaoVaga::allForUser($u, $r, $f)), [$sa, $sb, $sb2, $sv]) && SolicitacaoVaga::findAccessible($sb, $u, $r, $f) !== null, "(2) {$n}: acesso global");
    }
    $check($so($ids(SolicitacaoVaga::allForUser($vL, 'viewer', false)), [$sv]) && SolicitacaoVaga::findAccessible($sa, $vL, 'viewer', false) === null, '(3) Viewer legado: regra antiga preservada (só as próprias), sem acesso novo');

    // ---- 2. HTTP: permissão, escopo, JSON/HTML sem vazamento ------------------------------------------------------------------------------
    $nomeSetorA = 'ZZS ' . $codA; // nome do setor criado por zzSetorTeste()
    $nomeSetorB = 'ZZS ' . $codB;
    [$st, $html] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'index');
    $check($st === 200 && str_contains($html, '/admin/solicitacoes-vaga/' . $sa) && !str_contains($html, '/admin/solicitacoes-vaga/' . $sb . '"') && !str_contains($html, $nomeSetorB), '(4) HTTP lista: só a solicitação do setor');
    foreach ([['AdminSolicitacoesVagaController', 'index', []], ['AdminSolicitacoesVagaKanbanController', 'index', []], ['AdminSolicitacoesVagaController', 'show', [(string)$sa]], ['AdminSolicitacoesVagaController', 'create', []]] as [$ctrl, $m, $args]) {
        [$st] = $requisitar($gNP, 'gestor', false, $ctrl, $m, [], $args);
        $check($st === 403, "(4) Gestor SEM permissão: {$ctrl}::{$m} = 403");
    }
    [$st, $html] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaKanbanController', 'index');
    $check($st === 200 && str_contains($html, $nomeSetorA) && !str_contains($html, $nomeSetorB), '(5) HTTP Kanban: só cards do setor; o filtro de Setor não lista o outro setor');
    [, $htmlG0] = $requisitar($g0, 'gestor', false, 'AdminSolicitacoesVagaKanbanController', 'index');
    $check(!str_contains($htmlG0, $nomeSetorA) && !str_contains($htmlG0, $nomeSetorB), '(5) HTTP Kanban: Gestor sem setores não recebe nenhuma solicitação');
    [$st] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'show', [], [(string)$sb]);
    [$st2, $h2] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'show', [], [(string)$sa]);
    $check($st === 404 && $st2 === 200 && !str_contains($h2, $nomeSetorB), '(4) URL direta: outra solicitação = 404; a do setor abre');
    $dep = SolicitacaoVaga::formDependencies($g1, $g1);
    $setoresDep = array_map(static fn(array $s): int => (int)$s['id'], $dep['setores']);
    $check($setoresDep === [$sA] && array_diff(array_map(static fn(array $x): int => (int)$x['setor_id'], $dep['colaboradores']), [$sA]) === [] && array_diff(array_map(static fn(array $x): int => (int)$x['setor_id'], $dep['gestores']), [$sA]) === [], '(5) listas de apoio (setores/colaboradores/gestores) do Gestor só com o Setor Gerenciado');
    $depAdm = SolicitacaoVaga::formDependencies($adm, $adm);
    $check(count($depAdm['setores']) > count($dep['setores']), '(5) Admin segue vendo a lista global de setores (inalterado)');

    // ---- 3. escrita: anotação, movimentação, aprovação, CSRF ---------------------------------------------------------------------------------
    $nNotas = static fn(int $id): int => (int)$valor('SELECT COUNT(*) FROM solicitacao_vaga_kanban_historico WHERE solicitacao_id = ?', [$id]);
    $a0 = $nNotas($sa);
    $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'addNota', ['texto' => 'NOTA-OK'], [(string)$sa]);
    $b0 = $nNotas($sb);
    [$st] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'addNota', ['texto' => 'NOTA-INVASAO'], [(string)$sb]);
    $check($nNotas($sa) === $a0 + 1 && $nNotas($sb) === $b0 && $st === 404, '(6) anotação: grava no setor do Gestor; solicitação de outro setor = 404 e nada gravado');
    [$st] = $requisitar($gNP, 'gestor', false, 'AdminSolicitacoesVagaController', 'addNota', ['texto' => 'SEM-PERM'], [(string)$sa]);
    [$st2] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'addNota', ['csrf' => 'forjado', 'texto' => 'CSRF'], [(string)$sa]);
    $check($st === 403 && $st2 === 400 && $nNotas($sa) === $a0 + 1, '(6) anotação: sem permissão = 403; CSRF inválido = 400; nada gravado');
    [$st] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'store', ['csrf' => 'forjado', 'setor_id' => (string)$sB]);
    $check($st === 400, '(6) criação com CSRF inválido = 400');
    // movimentação (JSON em php://input) — o controller é chamado no próprio processo
    $mover = static function (int $userId, int $solicitacaoId, int $stage, string $csrf = '') use ($pdo): array {
        $_SESSION = ['user' => true, 'user_id' => $userId, 'user_role' => (string)$pdo->query("SELECT role FROM usuarios WHERE id = {$userId}")->fetchColumn(), 'user_is_supervisor' => false];
        $_SESSION['csrf_token'] = 'tok-teste';
        ZzEntradaPhp::$corpo = json_encode(['csrf' => $csrf !== '' ? $csrf : 'tok-teste', 'solicitacao_id' => $solicitacaoId, 'stage_id' => $stage]);
        http_response_code(200);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', 'ZzEntradaPhp');
        ob_start();
        try {
            (new AdminSolicitacoesVagaKanbanController())->move();
        } finally {
            $saida = (string)ob_get_clean();
            stream_wrapper_restore('php');
        }
        return [http_response_code() ?: 200, $saida];
    };
    $etapaDe = static fn(int $id): int => (int)$valor('SELECT situacao_kanban_id FROM solicitacoes_vaga WHERE id = ?', [$id]);
    $alvo = $stageId('em-recrutamento');
    [$st] = $mover($g1, $sb, $alvo);
    $check($st === 404 && $etapaDe($sb) === $stageId('em-aprovacao'), '(7) Kanban move: solicitação de OUTRO setor (onde ele é solicitante) = 404 e não é movida');
    [$st] = $mover($g1, $sa, $alvo);
    $check($st === 200 && $etapaDe($sa) === $alvo && $nNotas($sa) >= $a0 + 2, '(7) Kanban move: no setor do Gestor move e registra o histórico');
    [$st] = $mover($gNP, $sa, $stageId('em-aprovacao'));
    [$st2] = $mover($g1, $sa, $stageId('em-aprovacao'), 'forjado');
    $check($st === 403 && $st2 === 400 && $etapaDe($sa) === $alvo, '(7) Kanban move: sem permissão = 403; CSRF inválido = 400; nada muda');
    [$st] = $mover($g0, $sa, $stageId('em-aprovacao'));
    $check($st === 404 && $etapaDe($sa) === $alvo, '(7) Kanban move: Gestor sem setores = 404');
    [$st] = $mover($vL, $sv, $alvo);
    $check($st === 200 && $etapaDe($sv) === $alvo, '(7) Kanban move: viewer legado com permissão segue movendo as próprias (regra antiga preservada)');
    // aprovação de líder: fechada ao Gestor
    [$st] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveLeader', ['decision' => 'aprovado'], [(string)$sb2]);
    [$st2] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', 'approveRh', ['decision' => 'aprovado'], [(string)$sa]);
    $check($st === 403 && $st2 === 403 && (string)$valor('SELECT status_fluxo FROM solicitacoes_vaga WHERE id = ?', [$sb2]) === 'pendente_lider', '(8) aprovação (líder/RH) NÃO é liberada ao Gestor (403, status inalterado) — decisão de política pendente');
    foreach (['updateRh', 'gerarVaga'] as $m) {
        [$st] = $requisitar($g1, 'gestor', false, 'AdminSolicitacoesVagaController', $m, ['x' => '1'], [(string)$sa]);
        $check($st === 403, "(8) {$m}: Admin/RH apenas (Gestor = 403)");
    }

    // ---- 4. criação: setor revalidado no backend -----------------------------------------------------------------------------------------------
    $pdo->prepare('UPDATE usuarios SET aprovador_usuario_id = ?, pode_solicitar_vaga = 1 WHERE id = ?')->execute([$rh, $g1]);
    $pdo->prepare('INSERT INTO usuario_setores (usuario_id, setor_id, principal) VALUES (?, ?, 1)')->execute([$g1, $sB]); // contexto aponta para um setor que ele NÃO gerencia
    $msgScope = 'Você só pode solicitar vagas para Setores que gerencia.';
    try {
        SolicitacaoVaga::create(['quantidade_vagas' => 1, 'tipo_vaga' => 'nova_posicao'], $g1, '127.0.0.1');
        $m1 = '';
    } catch (Throwable $e) {
        $m1 = $e->getMessage();
    }
    $check($m1 === $msgScope, '(9) criar com contexto de setor NÃO gerenciado: recusado no backend pela regra de escopo');
    $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([$g1]);
    $pdo->prepare('INSERT INTO usuario_setores (usuario_id, setor_id, principal) VALUES (?, ?, 1)')->execute([$g1, $sA]);
    try {
        SolicitacaoVaga::create(['quantidade_vagas' => 1, 'tipo_vaga' => 'nova_posicao', 'setor_id' => (string)$sB], $g1, '127.0.0.1');
        $m2 = '';
    } catch (Throwable $e) {
        $m2 = $e->getMessage();
    }
    $check($m2 !== $msgScope && $m2 !== '', '(9) setor_id forjado no POST é ignorado (usa o contexto do usuário): a recusa agora é de outra validação, não de escopo');
    $nSol = (int)$valor('SELECT COUNT(*) FROM solicitacoes_vaga WHERE solicitante_usuario_id = ?', [$g1]);
    $check($nSol === 1, '(9) nenhuma solicitação nova foi criada pelas tentativas recusadas');

    // ---- 5. histórico preservado ao perder o setor ------------------------------------------------------------------------------------------------
    $solicAntes = $pdo->query("SELECT solicitante_usuario_id, setor_id, status_fluxo FROM solicitacoes_vaga WHERE id = {$sa}")->fetch(PDO::FETCH_ASSOC);
    zzDeixarDeGerenciar($pdo, $g1, $sA);
    $solicDepois = $pdo->query("SELECT solicitante_usuario_id, setor_id, status_fluxo FROM solicitacoes_vaga WHERE id = {$sa}")->fetch(PDO::FETCH_ASSOC);
    $check(SolicitacaoVaga::findAccessible($sa, $g1, 'gestor', false) === null && $so($ids(SolicitacaoVaga::allForKanban($g1, 'gestor', false)), []) && $solicAntes === $solicDepois && SolicitacaoVaga::findAccessible($sa, $rh, 'rh', false) !== null, '(10) Gestor que perde o setor perde o acesso na hora; a solicitação e o histórico permanecem íntegros e visíveis ao RH');

    // ---- 6. Integração do Colaborador / Pipeline / Candidaturas ------------------------------------------------------------------------------------
    $rotasIntegracao = [['AdminPesquisaIntegracaoResultadosController', 'dashboard', []], ['AdminPesquisaIntegracaoResultadosController', 'resultados', [date('Y-m-d')]], ['AdminPesquisaIntegracaoQrController', 'index', []], ['AdminPesquisaReacaoIntegracaoController', 'index', []]];
    $furos = [];
    foreach ([['viewer legado', $vL, 'viewer'], ['gestor (permissão gravada)', $g1, 'gestor']] as [$rot, $u, $r]) {
        if ($r === 'gestor') {
            $pdo->prepare('INSERT IGNORE INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$u, $permId['integracao_colaborador.visualizar']]);
        }
        foreach ($rotasIntegracao as [$ctrl, $m, $args]) {
            [$st, $corpo] = $requisitar($u, $r, false, $ctrl, $m, [], $args);
            if ($st !== 403) {
                $furos[] = "{$rot}:{$ctrl}::{$m}={$st}";
            }
        }
        [$st] = $requisitar($u, $r, false, 'AdminPesquisaIntegracaoQrController', 'abrir', ['data_integracao' => date('Y-m-d')]);
        if ($st !== 403) {
            $furos[] = "{$rot}:QR abrir={$st}";
        }
    }
    $check($furos === [], '(11) Integração do Colaborador (dashboard, resultados, QR, central de pesquisas): 403 ao Viewer e ao Gestor, mesmo com as permissões GRAVADAS' . ($furos === [] ? '' : ' — FUROS: ' . implode(', ', $furos)));
    $check(!Authorization::usuarioTemPermissao($vL, 'integracao_colaborador.visualizar') && !Authorization::usuarioTemPermissao($vL, 'integracao_colaborador.editar') && PermissaoPerfilPolicy::classificar('viewer', 'integracao_colaborador.visualizar') === PermissaoPerfilPolicy::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'integracao_colaborador.visualizar') === PermissaoPerfilPolicy::INCOMPATIVEL, '(11) política: integracao_colaborador.* incompatível para Viewer e Gestor (permissão gravada não vale)');
    [$st] = $requisitar($rh, 'rh', false, 'AdminPesquisaIntegracaoResultadosController', 'dashboard');
    [$st2] = $requisitar($adm, 'admin', false, 'AdminPesquisaIntegracaoQrController', 'index');
    $check($st === 200 && $st2 === 200, '(11) Admin e RH mantêm o acesso à Integração');
    [, $htmlColab] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'updateIntegracao', ['x' => '1'], ['1']);
    [$stU] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'updateIntegracao', ['x' => '1'], ['1']);
    [$stG] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'gerarPesquisaIntegracao', ['x' => '1'], ['1']);
    $check($stU === 403 && $stG === 403, '(11) registro individual de Integração (editar/gerar pesquisa): 403 ao Gestor');
    foreach ([['AdminPipelineController', 'index'], ['AdminCandidaturasController', 'index'], ['AdminIndicacoesController', 'index']] as [$ctrl, $m]) {
        [$st] = $requisitar($g1, 'gestor', false, $ctrl, $m);
        $check($st === 403, "(12) {$ctrl}::{$m} segue 403 ao Gestor (Pipeline de Candidaturas restrito a Admin/RH)");
    }
    zzGerenciar($pdo, $g1, $sA); // restaura para a parte de Experiência

    // ---- 7. Experiência: avaliar ---------------------------------------------------------------------------------------------------------------------
    $mA = $mkContrato('A', $codA);
    $mB = $mkContrato('B', $codB);
    $exp = new AvaliacaoExperienciaService();
    $ag = new DateTimeImmutable('now');
    $a1 = $papel($g1);
    $check(Authorization::usuarioTemPermissao($g1, 'avaliacao_experiencia.avaliar') && PermissaoPerfilPolicy::classificar('gestor', 'avaliacao_experiencia.avaliar') === PermissaoPerfilPolicy::PERMITIDA, '(13) avaliacao_experiencia.avaliar liberada ao Gestor pela política');
    $rA = $exp->salvarRascunho($mA, '45', ['feedback_geral' => 'ZZS ok', 'gestor_usuario_id' => (string)$adm], $a1, $ag, '127.0.0.1');
    $rB = $exp->salvarRascunho($mB, '45', ['feedback_geral' => 'ZZS invasao'], $a1, $ag, '127.0.0.1');
    $check(($rA['ok'] ?? false) === true && ($rB['ok'] ?? true) === false && (int)$valor('SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ?', [$mB]) === 0, '(13) Gestor avalia colaborador do setor e é recusado em outro setor (nada gravado)');
    $respReg = (int)$valor('SELECT gestor_usuario_id FROM avaliacoes_experiencia WHERE id = ?', [$rA['id']]);
    $check($respReg !== $adm, '(13) responsável forjado no POST (gestor_usuario_id) é ignorado para quem não é Admin/RH');
    $rT = $exp->salvarRascunho($mA, 'xx', ['feedback_geral' => 'x'], $a1, $ag, null);
    $check(($rT['ok'] ?? true) === false, '(13) tipo de avaliação forjado é recusado');
    $conc = $exp->concluir((int)$rA['id'], ['parecer' => array_key_first(AvaliacaoExperienciaService::PARECER_OPCOES), 'parecer_justificativa' => 'ok'], $a1, $ag, null);
    $reab = $exp->reabrir((int)$rA['id'], 'x', $a1, $ag, null);
    $edit = $exp->salvarRascunho($mA, '45', ['feedback_geral' => 'EDICAO-APOS-CONCLUIR'], $a1, $ag, null);
    $check(($conc['ok'] ?? false) === true && ($reab['ok'] ?? true) === false && ($edit['ok'] ?? true) === false && (string)$valor('SELECT status FROM avaliacoes_experiencia WHERE id = ?', [$rA['id']]) === 'concluido', '(13) concluir ok; Gestor NÃO reabre nem edita avaliação concluída');
    $concB = $exp->concluir((int)$rA['id'] + 0, ['parecer' => 'x'], $papel($gM), $ag, null);
    $reabAdm = $exp->reabrir((int)$rA['id'], 'ajuste', $papel($adm, 'admin'), $ag, null);
    $check(($reabAdm['ok'] ?? false) === true, '(13) Admin continua reabrindo avaliação concluída');
    [$st] = $requisitar($g1, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'salvar', ['csrf' => 'forjado', 'feedback_geral' => 'HACK'], [(string)$mA, '90']);
    $check($st === 400 && (int)$valor("SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ? AND tipo = '90'", [$mA]) === 0, '(13) CSRF inválido = 400 e nada gravado');
    $requisitar($g1, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'salvar', ['feedback_geral' => 'HTTP-OK'], [(string)$mA, '90']);
    $requisitar($g1, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'salvar', ['feedback_geral' => 'HTTP-INVASAO'], [(string)$mB, '90']);
    $check((int)$valor("SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ? AND tipo = '90'", [$mA]) === 1 && (int)$valor("SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ?", [$mB]) === 0, '(13) HTTP com CSRF válido: grava no setor; em outro setor não grava');
    [$st] = $requisitar($gNP, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'index');
    $check($st === 403, '(13) sem a permissão individual: a lista de Experiência dá 403');

    // ---- 8. política e navegação -------------------------------------------------------------------------------------------------------------------------
    $P = 'PermissaoPerfilPolicy';
    $check(PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.visualizar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'kanban_vagas.movimentar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.editar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.cancelar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'kanban_vagas.editar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'candidaturas.visualizar') === $P::INCOMPATIVEL, '(14) política: visualizar/criar/mover liberados; editar/cancelar (sem operação protegida) e recrutamento seguem incompatíveis');
    $check(PermissaoPerfilPolicy::classificar('viewer', 'solicitacao_vaga.criar') === $P::TRANSITORIA_GESTOR, '(14) viewer: solicitação/Kanban seguem apenas como transição legada (sem novas permissões)');
    $pdo->prepare('INSERT IGNORE INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$g1, $permId['solicitacao_vaga.editar']]);
    $check(!Authorization::usuarioTemPermissao($g1, 'solicitacao_vaga.editar'), '(14) permissão incompatível gravada no Gestor não vale');
} finally {
    if ($c['metadados'] !== []) {
        $in = implode(',', array_map('intval', $c['metadados']));
        $avIds = $pdo->query("SELECT id FROM avaliacoes_experiencia WHERE metadados_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        if ($avIds !== []) {
            $x = implode(',', array_map('intval', $avIds));
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_experiencia_criterios WHERE avaliacao_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_experiencia WHERE id IN ({$x})");
        }
        $pdo->exec("DELETE FROM colaboradores_metadados WHERE id IN ({$in})");
    }
    if ($c['solicitacoes'] !== []) {
        $pdo->exec('DELETE FROM solicitacoes_vaga WHERE id IN (' . implode(',', array_map('intval', $c['solicitacoes'])) . ')');
    }
    if ($c['usuarios'] !== []) {
        $lu = implode(',', array_map('intval', $c['usuarios']));
        $pdo->exec("DELETE FROM solicitacao_vaga_kanban_historico WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM solicitacoes_vaga WHERE solicitante_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuario_setores WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE actor_usuario_id IN ({$lu}) OR target_usuario_id IN ({$lu})");
        $pdo->exec("UPDATE usuarios SET aprovador_usuario_id = NULL WHERE aprovador_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
    zzLimparSetores($pdo, $c['setores']);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — Solicitações de Vaga, Kanban, Integração e Experiência (Gestor).\n";
