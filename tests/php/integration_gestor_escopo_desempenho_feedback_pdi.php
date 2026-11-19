<?php

/**
 * Integração — Etapa 2C.2A: escopo setorial do perfil Gestor em Avaliação de Desempenho, Feedback e PDI.
 *
 * Regra provada: o acesso por LINHA é o setor ATUAL do contrato (`colaboradores_metadados.codigo_setor`) ∈ Setores Gerenciados do ator
 * (`usuario_setores_gerenciados`, via EscopoSetorial). `gestor_usuario_id` (responsável registrado) NÃO é fonte de acesso. Cobre listagem,
 * detalhe por ID, busca de contrato para criação, criação com colaborador forjado no POST, escrita fora do escopo, setor perdido,
 * colaborador transferido/sem setor/desligado/com múltiplos contratos, Admin/RH/Supervisor Geral (global), viewer legado, Gestor com
 * is_supervisor=1, permissão ausente (403) e CSRF inválido (400, nada gravado) — pelos services e por requisições HTTP em processo próprio.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/fixture_setor_gerenciado.php';

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

$suf = substr((string)time(), -5) . random_int(10, 99);
$c = ['usuarios' => [], 'setores' => [], 'metadados' => []];
$agora = new DateTimeImmutable('now');
$hoje = DateHelper::hojeOperacional();
$ip = '127.0.0.1';
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$permId = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $permId[$r['codigo']] = (int)$r['id'];
}
$perms9 = ['colaboradores.visualizar', 'avaliacao_experiencia.visualizar', 'avaliacao_desempenho.visualizar', 'avaliacao_desempenho.avaliar', 'feedback.visualizar', 'feedback.avaliar', 'pdi.visualizar', 'pdi.gerenciar', 'pdi.acompanhar'];

$novo = static function (string $apelido, string $role, int $flag, array $perms) use ($pdo, $senha, $suf, &$c, $permId): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZE ' . $apelido, strtolower($apelido) . '.zze.' . $suf . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $c['usuarios'][] = $id;
    foreach ($perms as $p) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $permId[$p]]);
    }
    return $id;
};
$mkSetor = static function (string $cod) use ($pdo, &$c): int {
    $id = zzSetorTeste($pdo, $cod, 'ZZE');
    $c['setores'][] = $id;
    return $id;
};
$mkContrato = static function (string $rotulo, ?string $codSetor, string $pessoa) use ($pdo, $suf, &$c): int {
    static $n = 0;
    $n++;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa, unidade, codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute(["ZZE{$suf}-{$n}", 'ZZEEMP', 'UNI', "ZZEC{$suf}{$n}", "ZZEP{$suf}{$pessoa}", "ZZE2C {$rotulo}", 'ZZE Empresa', 'ZZE Unidade', $codSetor, $codSetor === null ? null : 'ZZE Setor', 'ZZE Cargo', 'ZZECG', date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('-200 days')), 'zze-teste']);
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
$valores = static function (): array {
    $d = [];
    foreach (array_keys(FeedbackService::VALORES_CULTURAIS) as $v) {
        $d['valor_' . $v] = 'atende';
        $d['comentario_' . $v] = '';
    }
    return $d;
};
$coluna = static fn(string $sql, array $p = []): array => (function () use ($pdo, $sql, $p): array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
})();
$valor = static function (string $sql, array $p = []) use ($pdo): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
};

try {
    // ---- setores, contratos, usuários ---------------------------------------------------------------------------------------
    $codA = 'EA' . substr($suf, -5);
    $codB = 'EB' . substr($suf, -5);
    $codC = 'EC' . substr($suf, -5);
    $sA = $mkSetor($codA);
    $sB = $mkSetor($codB);
    $sC = $mkSetor($codC);
    $mA = $mkContrato('A', $codA, 'a');
    $mB = $mkContrato('B', $codB, 'b');
    $mC = $mkContrato('C', $codC, 'c');
    $mN = $mkContrato('SEM SETOR', null, 'n');
    $mD = $mkContrato('DESLIGADO', $codA, 'd');
    $mT = $mkContrato('TRANSFERIDO', $codA, 't');
    $mM1 = $mkContrato('MULTI 1', $codA, 'm');
    $mM2 = $mkContrato('MULTI 2', $codB, 'm'); // mesma pessoa, 2º contrato em outro setor

    $g1 = $novo('g1', 'gestor', 0, $perms9);
    $gM = $novo('gmulti', 'gestor', 0, $perms9);
    $g0 = $novo('gzero', 'gestor', 0, $perms9);
    $gNP = $novo('gnp', 'gestor', 0, []);
    $gP = $novo('gperde', 'gestor', 0, $perms9);
    $gF = $novo('gflag', 'gestor', 1, $perms9); // is_supervisor=1 NÃO é Supervisor Geral
    $gC = $novo('gc', 'gestor', 0, $perms9);
    $vL = $novo('vlegado', 'viewer', 0, $perms9);
    $rh = $novo('rh', 'rh', 0, []);
    $adm = $novo('adm', 'admin', 0, []);
    $sg = $novo('sg', 'admin', 1, []);
    foreach ([$g1, $gP, $gF, $vL] as $u) {
        zzGerenciar($pdo, $u, $sA);
    }
    zzGerenciar($pdo, $gNP, $sA);
    zzGerenciar($pdo, $gM, $sA);
    zzGerenciar($pdo, $gM, $sB);
    zzGerenciar($pdo, $gC, $sC);

    $atRh = ['id' => $rh, 'role' => 'rh'];
    $ator = static fn(int $id, string $role = 'gestor'): array => ['id' => $id, 'role' => $role];
    $fbSvc = new FeedbackService();
    $adSvc = new AvaliacaoDesempenhoService();
    $pdiSvc = new PdiService();

    // registros criados pelo RH (autor/responsável = RH; o gestor NÃO é o responsável registrado de nenhum)
    $mapa = ['A' => $mA, 'B' => $mB, 'C' => $mC, 'N' => $mN, 'D' => $mD, 'T' => $mT, 'M1' => $mM1, 'M2' => $mM2];
    $fb = $ad = $pd = [];
    foreach ($mapa as $k => $mid) {
        $r = $fbSvc->criar(array_merge(['metadados_id' => (string)$mid, 'gestor_usuario_id' => (string)$rh, 'tipo' => 'reconhecimento', 'data_feedback' => $hoje->format('Y-m-d'), 'pontos_fortes' => "ZZE fb {$k}"], $valores()), $atRh, $agora, $ip);
        $fb[$k] = (int)($r['id'] ?? 0);
        $r = $adSvc->criar(['metadados_id' => (string)$mid, 'gestor_usuario_id' => (string)$rh, 'ciclo' => "ZZE {$k}", 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30', 'pontos_fortes' => "ZZE ad {$k}"], $atRh, $agora, $ip);
        $ad[$k] = (int)($r['id'] ?? 0);
        $r = $pdiSvc->criar(['metadados_id' => (string)$mid, 'gestor_usuario_id' => (string)$rh, 'origem_tipo' => 'desenvolvimento_carreira', 'data_abertura' => $hoje->format('Y-m-d'), 'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d'), 'pontos_fortes' => "ZZE pdi {$k}", 'oportunidades_desenvolvimento' => '', 'objetivo_esperado' => '', 'competencias' => '', 'acoes' => []], $atRh, $agora, $ip);
        $pd[$k] = (int)($r['id'] ?? 0);
    }
    $check(!in_array(0, array_merge($fb, $ad, $pd), true), '(fixture) 8 contratos × 3 módulos criados pelo RH (responsável registrado = RH, nunca o gestor testado)');
    // contrato desligado: o histórico existe; a demissão só entra depois da criação
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-10 days')), $mD]);

    $listaFb = static fn(array $a): array => array_map('intval', array_column($fbSvc->listar([], $a), 'id'));
    $listaAd = static fn(array $a): array => array_map('intval', array_column($adSvc->listar([], $a), 'id'));
    $listaPd = static fn(array $a): array => array_map('intval', array_column($pdiSvc->listar([], $a, $hoje)['itens'] ?? [], 'id'));
    $so = static function (array $lista, array $esperadoChaves, array $mapaIds): bool {
        $esperado = array_map(static fn(string $k): int => $mapaIds[$k], $esperadoChaves);
        $meus = array_values(array_intersect($lista, array_values($mapaIds)));
        sort($esperado);
        sort($meus);
        return $meus === $esperado;
    };

    // ---- 1. Gestor de UM setor ----------------------------------------------------------------------------------------------
    $a1 = $ator($g1);
    $visA = ['A', 'D', 'T', 'M1'];
    $check($so($listaFb($a1), $visA, $fb) && $so($listaAd($a1), $visA, $ad) && $so($listaPd($a1), $visA, $pd), '(1) Gestor de 1 setor: as três listagens trazem SÓ colaboradores do setor (A, desligado D, T, M1) — nunca B, C, sem-setor nem o 2º contrato');
    $check($fbSvc->detalhe($fb['A'], $a1) !== null && $adSvc->detalhe($ad['A'], $a1) !== null && $pdiSvc->detalhe($pd['A'], $a1, $hoje) !== null, '(1) detalhe por ID do setor é acessível');
    // ---- 2. múltiplos setores ----------------------------------------------------------------------------------------------
    $aM = $ator($gM);
    $visM = ['A', 'B', 'D', 'T', 'M1', 'M2'];
    $check($so($listaFb($aM), $visM, $fb) && $so($listaAd($aM), $visM, $ad) && $so($listaPd($aM), $visM, $pd), '(2) Gestor de 2 setores: vê os dois (inclusive os dois contratos da mesma pessoa) — nunca C nem sem-setor');
    // ---- 3. sem setores ----------------------------------------------------------------------------------------------------
    $a0 = $ator($g0);
    $check($listaFb($a0) === [] && $listaAd($a0) === [] && $listaPd($a0) === [] && $fbSvc->detalhe($fb['A'], $a0) === null && $adSvc->detalhe($ad['A'], $a0) === null && $pdiSvc->detalhe($pd['A'], $a0, $hoje) === null, '(3) Gestor SEM setores: conjunto vazio (nenhuma listagem, nenhum detalhe) — jamais acesso global');
    // ---- 4. acesso direto por ID de outro setor ---------------------------------------------------------------------------------
    $check($fbSvc->detalhe($fb['B'], $a1) === null && $adSvc->detalhe($ad['B'], $a1) === null && $pdiSvc->detalhe($pd['B'], $a1, $hoje) === null && $fbSvc->detalhe($fb['N'], $a1) === null && $adSvc->detalhe($ad['N'], $a1) === null && $pdiSvc->detalhe($pd['N'], $a1, $hoje) === null, '(4) ID de outro setor, ou de colaborador SEM setor: não encontrado nos três módulos');

    // ---- 5. escrita fora do escopo ------------------------------------------------------------------------------------------
    $antesFb = $valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['B']]);
    $antesAd = $valor('SELECT pontos_fortes FROM avaliacoes_desempenho WHERE id = ?', [$ad['B']]);
    $antesPd = $valor('SELECT pontos_fortes FROM pdis WHERE id = ?', [$pd['B']]);
    $r1 = $fbSvc->atualizar($fb['B'], ['pontos_fortes' => 'INVASAO'] + $valores(), $a1, $agora, $ip);
    $r2 = $fbSvc->concluir($fb['B'], ['pontos_fortes' => 'INVASAO', 'resultado_geral' => 'atende_expectativas', 'observacoes_finais' => 'x'] + $valores(), $a1, $agora, $ip);
    $r3 = $fbSvc->registrarEspacoColaborador($fb['B'], 'INVASAO', $a1, $agora);
    $r4 = $adSvc->atualizar($ad['B'], ['pontos_fortes' => 'INVASAO'], $a1, $agora, $ip);
    $r5 = $adSvc->cancelar($ad['B'], 'INVASAO', $a1, $agora, $ip);
    $r6 = $adSvc->registrarCienciaColaborador($ad['B'], 'x', $a1, $agora);
    $r7 = $pdiSvc->atualizarEstrutura($pd['B'], ['pontos_fortes' => 'INVASAO', 'origem_tipo' => 'desenvolvimento_carreira', 'data_abertura' => $hoje->format('Y-m-d'), 'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d')], $a1, $agora, $ip);
    $r8 = $pdiSvc->adicionarAcompanhamento($pd['B'], 'INVASAO', false, $a1, $agora, $ip);
    $r9 = $pdiSvc->liberar($pd['B'], $a1, $agora, $ip);
    $r10 = $pdiSvc->cancelar($pd['B'], 'INVASAO', $a1, $agora, $ip);
    $check(array_reduce([$r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8, $r9, $r10], static fn(bool $ok, array $r) => $ok && ($r['ok'] ?? true) === false, true), '(5) Gestor não edita, conclui, cancela, dá ciência, comenta nem muda status de registro de OUTRO setor (10 operações recusadas)');
    $check($valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['B']]) === $antesFb && $valor('SELECT pontos_fortes FROM avaliacoes_desempenho WHERE id = ?', [$ad['B']]) === $antesAd && $valor('SELECT pontos_fortes FROM pdis WHERE id = ?', [$pd['B']]) === $antesPd && (string)$valor('SELECT status FROM pdis WHERE id = ?', [$pd['B']]) === 'rascunho' && (string)$valor('SELECT status FROM avaliacoes_desempenho WHERE id = ?', [$ad['B']]) === 'rascunho', '(5) nada foi gravado nos registros alheios');
    $check($fbSvc->atualizar($fb['A'], ['pontos_fortes' => 'OK GESTOR'] + $valores(), $a1, $agora, $ip)['ok'] === true && $adSvc->atualizar($ad['A'], ['pontos_fortes' => 'OK GESTOR'], $a1, $agora, $ip)['ok'] === true && $pdiSvc->adicionarAcompanhamento($pd['A'], 'x', false, $a1, $agora, $ip) !== null, '(5) no próprio setor o Gestor edita normalmente (Feedback, Desempenho)');

    // ---- 6. POST forjado: colaborador/gestor/setor ---------------------------------------------------------------------------------
    $nFb = (int)$valor('SELECT COUNT(*) FROM feedbacks'); $nAd = (int)$valor('SELECT COUNT(*) FROM avaliacoes_desempenho'); $nPd = (int)$valor('SELECT COUNT(*) FROM pdis');
    $f1 = $fbSvc->criar(array_merge(['metadados_id' => (string)$mB, 'gestor_usuario_id' => (string)$g1, 'tipo' => 'reconhecimento', 'data_feedback' => $hoje->format('Y-m-d')], $valores()), $a1, $agora, $ip);
    $f2 = $adSvc->criar(['metadados_id' => (string)$mB, 'gestor_usuario_id' => (string)$g1, 'ciclo' => 'forjado', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30'], $a1, $agora, $ip);
    $f3 = $pdiSvc->criar(['metadados_id' => (string)$mB, 'gestor_usuario_id' => (string)$g1, 'origem_tipo' => 'desenvolvimento_carreira', 'data_abertura' => $hoje->format('Y-m-d'), 'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d'), 'competencias' => '', 'acoes' => []], $a1, $agora, $ip);
    $f4 = $fbSvc->criar(array_merge(['metadados_id' => (string)$mN, 'gestor_usuario_id' => (string)$g1, 'tipo' => 'reconhecimento', 'data_feedback' => $hoje->format('Y-m-d')], $valores()), $a1, $agora, $ip);
    $check(($f1['ok'] ?? true) === false && ($f2['ok'] ?? true) === false && ($f3['ok'] ?? true) === false && ($f4['ok'] ?? true) === false, '(6) criar com colaborador FORJADO no POST (outro setor / sem setor) é recusado nos três módulos');
    $check((int)$valor('SELECT COUNT(*) FROM feedbacks') === $nFb && (int)$valor('SELECT COUNT(*) FROM avaliacoes_desempenho') === $nAd && (int)$valor('SELECT COUNT(*) FROM pdis') === $nPd, '(6) nenhum registro foi criado');
    $ok1 = $fbSvc->criar(array_merge(['metadados_id' => (string)$mA, 'gestor_usuario_id' => (string)$rh, 'tipo' => 'reconhecimento', 'data_feedback' => $hoje->format('Y-m-d')], $valores()), $a1, $agora, $ip);
    $ok3 = $pdiSvc->criar(['metadados_id' => (string)$mA, 'gestor_usuario_id' => (string)$rh, 'origem_tipo' => 'desenvolvimento_carreira', 'data_abertura' => $hoje->format('Y-m-d'), 'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d'), 'competencias' => '', 'acoes' => []], $a1, $agora, $ip);
    $check(($ok1['ok'] ?? false) === true && ($ok3['ok'] ?? false) === true && (int)$valor('SELECT gestor_usuario_id FROM feedbacks WHERE id = ?', [$ok1['id']]) === $g1 && (int)$valor('SELECT gestor_usuario_id FROM pdis WHERE id = ?', [$ok3['id']]) === $g1, '(6) criar para colaborador do PRÓPRIO setor funciona e o gestor_usuario_id forjado é ignorado (responsável = o próprio Gestor)');
    // busca/seleção de contrato para criação
    $busca = static fn(array $lista): array => array_map('intval', array_column($lista, 'metadados_id'));
    $bF = $busca($fbSvc->buscarContratos('ZZE2C', $a1)); $bA = $busca($adSvc->buscarContratos('ZZE2C', $a1)); $bP = $busca($pdiSvc->buscarContratos('ZZE2C', $a1, $hoje));
    $so2 = static fn(array $ids): bool => in_array($mA, $ids, true) && in_array($mM1, $ids, true) && !in_array($mB, $ids, true) && !in_array($mC, $ids, true) && !in_array($mN, $ids, true) && !in_array($mM2, $ids, true);
    $check($so2($bF) && $so2($bA) && $so2($bP), '(6) busca de colaborador para criar traz só contratos do setor do Gestor');
    $check(($fbSvc->contratoParaCriacao($mB, $a1)['ok'] ?? true) === false && ($adSvc->novoParaContrato($mB, $a1)['ok'] ?? true) === false && $pdiSvc->contratoParaCriacao($mB, $a1, $hoje) === null && ($fbSvc->contratoParaCriacao($mA, $a1)['ok'] ?? false) === true, '(6) abrir o formulário de criação para contrato de outro setor: negado (e dados/avaliações anteriores não vazam); do próprio: ok');
    $check($so($listaPd($a1), $visA, $pd) && $pdiSvc->pdisDoContrato($mB, $a1) === [] && count($pdiSvc->pdisDoContrato($mA, $a1)) >= 1 && $pdiSvc->contextoOrigem('feedback', $fb['B'], $a1) === null, '(6) PDI: "PDIs do contrato" e origem documental respeitam o escopo');

    // ---- 7. colaborador transferido ------------------------------------------------------------------------------------------------
    $antesT = $pdo->query("SELECT gestor_usuario_id, snap_codigo_setor FROM feedbacks WHERE id = {$fb['T']}")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE colaboradores_metadados SET codigo_setor = ? WHERE id = ?')->execute([$codC, $mT]);
    $aC = $ator($gC);
    $check(!in_array($fb['T'], $listaFb($a1), true) && !in_array($ad['T'], $listaAd($a1), true) && !in_array($pd['T'], $listaPd($a1), true) && $fbSvc->detalhe($fb['T'], $a1) === null && in_array($fb['T'], $listaFb($aC), true) && in_array($pd['T'], $listaPd($aC), true), '(7) Colaborador TRANSFERIDO: o acesso acompanha o setor ATUAL — o gestor antigo perde, o do setor novo ganha (snapshot não decide)');
    $depoisT = $pdo->query("SELECT gestor_usuario_id, snap_codigo_setor FROM feedbacks WHERE id = {$fb['T']}")->fetch(PDO::FETCH_ASSOC);
    $check($depoisT === $antesT && (int)$valor('SELECT COUNT(*) FROM feedbacks WHERE id = ?', [$fb['T']]) === 1, '(7) o registro histórico permanece íntegro (autor/responsável/snapshot intactos)');

    // ---- 8. gestor que PERDEU um setor ----------------------------------------------------------------------------------------------
    $aP = $ator($gP);
    $antes = in_array($fb['A'], $listaFb($aP), true) && $fbSvc->detalhe($fb['A'], $aP) !== null;
    $respAntes = (int)$valor('SELECT gestor_usuario_id FROM feedbacks WHERE id = ?', [$fb['A']]);
    zzDeixarDeGerenciar($pdo, $gP, $sA);
    $check($antes && $listaFb($aP) === [] && $listaAd($aP) === [] && $listaPd($aP) === [] && $fbSvc->detalhe($fb['A'], $aP) === null && $adSvc->atualizar($ad['A'], ['pontos_fortes' => 'x'], $aP, $agora, $ip)['ok'] === false, '(8) Gestor que perdeu o setor deixa de acessar (lista, detalhe e escrita) imediatamente');
    $check((int)$valor('SELECT gestor_usuario_id FROM feedbacks WHERE id = ?', [$fb['A']]) === $respAntes && in_array($fb['A'], $listaFb($ator($rh, 'rh')), true), '(8) o histórico permanece preservado e visível ao RH; nada foi reatribuído');

    // ---- 9. desligado / sem setor / múltiplos contratos ------------------------------------------------------------------------------
    $check($fbSvc->detalhe($fb['D'], $a1) !== null && $adSvc->detalhe($ad['D'], $a1) !== null && $pdiSvc->detalhe($pd['D'], $a1, $hoje) !== null, '(9) Colaborador DESLIGADO: o histórico segue o setor registrado no contrato (consulta permitida ao gestor do setor)');
    $novoDeslig = $pdiSvc->criar(['metadados_id' => (string)$mD, 'gestor_usuario_id' => (string)$g1, 'origem_tipo' => 'desenvolvimento_carreira', 'data_abertura' => $hoje->format('Y-m-d'), 'data_prevista_conclusao' => $hoje->modify('+90 days')->format('Y-m-d'), 'competencias' => '', 'acoes' => []], $a1, $agora, $ip);
    $check(($novoDeslig['ok'] ?? true) === false, '(9) as regras existentes de elegibilidade continuam valendo: não abre PDI novo para contrato desligado');
    $check($fbSvc->detalhe($fb['N'], $aM) === null && $fbSvc->detalhe($fb['N'], $a1) === null && $adSvc->detalhe($ad['N'], $aM) === null && $pdiSvc->detalhe($pd['N'], $aM, $hoje) === null, '(9) Colaborador SEM setor: invisível a qualquer Gestor (só o escopo global enxerga)');
    $check($fbSvc->detalhe($fb['M1'], $a1) !== null && $fbSvc->detalhe($fb['M2'], $a1) === null && $fbSvc->detalhe($fb['M2'], $aM) !== null, '(9) Múltiplos contratos: cada contrato é avaliado pelo SEU setor');

    // ---- 10. global: Admin / RH / Supervisor Geral ---------------------------------------------------------------------------------------
    $todos = array_values($mapa);
    $tudoOk = true;
    foreach ([$ator($adm, 'admin'), $ator($rh, 'rh'), $ator($sg, 'admin')] as $glob) {
        foreach (['A', 'B', 'C', 'N', 'M2'] as $k) {
            $tudoOk = $tudoOk && $fbSvc->detalhe($fb[$k], $glob) !== null && $adSvc->detalhe($ad[$k], $glob) !== null && $pdiSvc->detalhe($pd[$k], $glob, $hoje) !== null;
        }
        $tudoOk = $tudoOk && count(array_intersect($listaFb($glob), $fb)) === count($fb) && count(array_intersect($listaAd($glob), $ad)) === count($ad) && count(array_intersect($listaPd($glob), $pd)) === count($pd);
    }
    $check($tudoOk, '(10) Admin, RH e Supervisor Geral: acesso global (todos os setores e inclusive sem setor) nos três módulos');

    // ---- 11. viewer legado e Gestor com is_supervisor=1 ------------------------------------------------------------------------------------
    $check($so($listaFb($ator($vL, 'viewer')), ['A', 'D', 'M1'], $fb) && $fbSvc->detalhe($fb['B'], $ator($vL, 'viewer')) === null, '(11) Viewer com permissões transitórias legadas: segue só o setor que gerencia (sem novo acesso)');
    $check($so($listaFb($ator($gF)), ['A', 'D', 'M1'], $fb) && $fbSvc->detalhe($fb['B'], $ator($gF)) === null && !EscopoSetorial::resolver($ator($gF))->ehGlobal(), '(11) Gestor com is_supervisor=1: o flag não amplia o escopo');

    // ---- 12. HTTP: permissão ausente, leitura por ID, CSRF ----------------------------------------------------------------------------------
    foreach ([['AdminFeedbacksController', 'index'], ['AdminAvaliacoesDesempenhoController', 'index'], ['AdminPdisController', 'index']] as [$ctrl, $m]) {
        [$st] = $requisitar($gNP, 'gestor', false, $ctrl, $m);
        [$stOk, $corpo] = $requisitar($g1, 'gestor', false, $ctrl, $m);
        $check($st === 403 && $stOk === 200 && !str_contains($corpo, 'ZZE2C B') && !str_contains($corpo, 'ZZE2C C'), "(12) {$ctrl}: sem permissão = 403; com permissão = 200 só com colaboradores do setor");
        [, $c0] = $requisitar($g0, 'gestor', false, $ctrl, $m);
        $check(!str_contains($c0, 'ZZE2C'), "(12) {$ctrl}: Gestor sem setores não recebe nenhum colaborador");
    }
    [$st, $corpo] = $requisitar($g1, 'gestor', false, 'AdminPdisController', 'show', [], [(string)$pd['B']]);
    $check($st === 404 && !str_contains($corpo, 'ZZE2C B'), '(12) PDI de outro setor por URL direta: 404 sem revelar o registro');
    [, $corpo] = $requisitar($g1, 'gestor', false, 'AdminFeedbacksController', 'editar', [], [(string)$fb['B']]);
    [, $corpo2] = $requisitar($g1, 'gestor', false, 'AdminAvaliacoesDesempenhoController', 'editar', [], [(string)$ad['B']]);
    $check(!str_contains($corpo, 'ZZE2C B') && !str_contains($corpo2, 'ZZE2C B') && !str_contains($corpo, 'ZZE fb B') && !str_contains($corpo2, 'ZZE ad B'), '(12) Feedback/Desempenho de outro setor por URL direta: nada do registro é exibido');
    [, $corpo] = $requisitar($g1, 'gestor', false, 'AdminFeedbacksController', 'novo', [], [], ['metadados_id' => (string)$mB]);
    $check(!str_contains($corpo, 'ZZE2C B'), '(12) formulário de novo Feedback com metadados_id de outro setor na URL: sem dados do colaborador');

    // CSRF inválido: 400 e nada gravado (Admin, que TEM escopo — prova que foi o CSRF que barrou)
    $antesFbA = $valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['C']]);
    [$st] = $requisitar($adm, 'admin', false, 'AdminFeedbacksController', 'atualizar', ['csrf' => 'forjado', 'pontos_fortes' => 'HACK-CSRF'] + $valores(), [(string)$fb['C']]);
    $check($st === 400 && $valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['C']]) === $antesFbA, '(13) Feedback: POST com CSRF inválido = 400 e nada é alterado');
    [$st] = $requisitar($adm, 'admin', false, 'AdminAvaliacoesDesempenhoController', 'cancelar', ['csrf' => 'forjado', 'motivo' => 'HACK-CSRF'], [(string)$ad['C']]);
    $check($st === 400 && (string)$valor('SELECT status FROM avaliacoes_desempenho WHERE id = ?', [$ad['C']]) === 'rascunho', '(13) Desempenho: POST com CSRF inválido = 400 e o status não muda');
    [$st] = $requisitar($adm, 'admin', false, 'AdminAvaliacoesDesempenhoController', 'store', ['csrf' => '', 'metadados_id' => (string)$mC, 'ciclo' => 'HACK', 'periodo_inicio' => '2026-01-01', 'periodo_fim' => '2026-06-30']);
    $check($st === 400 && (int)$valor("SELECT COUNT(*) FROM avaliacoes_desempenho WHERE ciclo = 'HACK'") === 0, '(13) Desempenho: criação com CSRF vazio = 400 e nenhum registro');
    [$st] = $requisitar($adm, 'admin', false, 'AdminPdisController', 'cancelar', ['csrf' => 'forjado', 'motivo' => 'HACK'], [(string)$pd['C']]);
    $check($st === 400 && (string)$valor('SELECT status FROM pdis WHERE id = ?', [$pd['C']]) === 'rascunho', '(13) PDI: POST com CSRF inválido = 400 e o status não muda');
    [$st] = $requisitar($adm, 'admin', false, 'AdminAvaliacoesExperienciaController', 'salvar', ['csrf' => 'forjado', 'comentario_geral' => 'HACK'], [(string)$mC, '45']);
    $check($st === 400 && (int)$valor('SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ?', [$mC]) === 0, '(13) Experiência: o mesmo defeito de CSRF foi corrigido (400, nada gravado)');
    // CSRF válido + dentro do escopo: a escrita funciona; fora do escopo: não
    [$st] = $requisitar($g1, 'gestor', false, 'AdminFeedbacksController', 'atualizar', ['pontos_fortes' => 'HTTP-OK'] + $valores(), [(string)$fb['A']]);
    $check($valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['A']]) === 'HTTP-OK', '(13) Gestor com CSRF válido grava no próprio setor (HTTP)');
    $depois = $valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['B']]);
    $requisitar($g1, 'gestor', false, 'AdminFeedbacksController', 'atualizar', ['pontos_fortes' => 'HTTP-INVASAO'] + $valores(), [(string)$fb['B']]);
    $check($valor('SELECT pontos_fortes FROM feedbacks WHERE id = ?', [$fb['B']]) === $depois, '(13) Gestor com CSRF válido NÃO grava em outro setor (HTTP)');

    // ---- 14. Gestor não ganha privilégios globais por esses módulos ----------------------------------------------------------------------------
    $check(!PdiService::escopoTotal($a1) && !AvaliacaoDesempenhoService::escopoTotal($a1) && !FeedbackService::escopoTotal($a1) && $pdiSvc->reabrir($pd['A'], 'x', $a1, $agora, $ip)['ok'] === false && $fbSvc->reabrir($fb['A'], 'x', $a1, $agora, $ip)['ok'] === false && $adSvc->reabrir($ad['A'], 'x', $a1, $agora, $ip)['ok'] === false, '(14) reabrir segue só Admin/RH; o Gestor não tem escopo total em nenhum dos módulos');
    $check(EscopoSetorial::resolver($a1)->permiteColaborador(0) === false && EscopoSetorial::resolver($a1)->permiteColaborador(2147480000) === false && EscopoSetorial::resolver($ator($adm, 'admin'))->permiteColaborador($mN) === true, '(14) resolvedor: contrato inexistente nunca é permitido; global permite inclusive sem setor');
    // SQL do resolvedor: restrito sem setores = 1 = 0
    $p = [];
    $check(EscopoSetorial::restrito([])->sqlColaborador('x.id', $p) === '1 = 0' && $p === [] && EscopoSetorial::global()->sqlColaborador('x.id', $p) === '1 = 1' && EscopoSetorial::nenhum()->sqlColaborador('x.id', $p) === '1 = 0', '(14) sqlColaborador: vazio/nenhum = 1 = 0; global = 1 = 1 (nunca "sem filtro" por engano)');
} finally {
    if ($c['metadados'] !== []) {
        $in = implode(',', array_map('intval', $c['metadados']));
        $fbIds = $pdo->query("SELECT id FROM feedbacks WHERE metadados_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        $adIds = $pdo->query("SELECT id FROM avaliacoes_desempenho WHERE metadados_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        $pdIds = $pdo->query("SELECT id FROM pdis WHERE metadados_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        $lst = static fn(array $a): string => implode(',', array_map('intval', $a));
        if ($fbIds !== []) {
            $x = $lst($fbIds);
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'feedback' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'feedback' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM feedback_valores WHERE feedback_id IN ({$x})");
            $pdo->exec("DELETE FROM feedbacks WHERE id IN ({$x})");
        }
        if ($adIds !== []) {
            $x = $lst($adIds);
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_desempenho' AND documento_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_desempenho_criterios WHERE avaliacao_id IN ({$x})");
            $pdo->exec("DELETE FROM avaliacoes_desempenho WHERE id IN ({$x})");
        }
        if ($pdIds !== []) {
            $x = $lst($pdIds);
            foreach (['pdi_eventos', 'pdi_origens', 'pdi_acoes', 'pdi_competencias', 'pdi_acompanhamentos'] as $t) {
                $pdo->exec("DELETE FROM {$t} WHERE pdi_id IN ({$x})");
            }
            $pdo->exec("DELETE FROM pdis WHERE id IN ({$x})");
        }
        $pdo->exec("DELETE FROM avaliacoes_experiencia WHERE metadados_id IN ({$in})");
        $pdo->exec("DELETE FROM colaboradores_metadados WHERE id IN ({$in})");
    }
    if ($c['usuarios'] !== []) {
        $lu = implode(',', array_map('intval', $c['usuarios']));
        $pdo->exec("DELETE FROM pdi_eventos WHERE ator_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE autor_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM pdis WHERE gestor_usuario_id IN ({$lu}) OR criado_por_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM feedbacks WHERE gestor_usuario_id IN ({$lu}) OR criado_por_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM avaliacoes_desempenho WHERE gestor_usuario_id IN ({$lu}) OR criado_por_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE actor_usuario_id IN ({$lu}) OR target_usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
    zzLimparSetores($pdo, $c['setores']);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — escopo setorial do Gestor em Desempenho, Feedback e PDI.\n";
