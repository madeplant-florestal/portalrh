<?php

/**
 * Integração — Etapa 2C.2C: dashboard AGREGADO de Integração para o Gestor (permissão dashboard_integracao_agregado.visualizar).
 * Escopo = Setores Gerenciados; mínimo de 5 respondentes DISTINTOS após os filtros; supressão complementar; recusa de combinações de filtros que
 * permitam deduzir poucas pessoas por diferença; nenhum comentário/resposta individual/identificador; Viewer bloqueado; Admin/RH preservados.
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
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$permId = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $permId[$r['codigo']] = (int)$r['id'];
}
$PERM = 'dashboard_integracao_agregado.visualizar';
$novo = static function (string $apelido, string $role, array $perms) use ($pdo, $senha, $suf, &$c, $permId): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, 0, NOW())')
        ->execute(['ZZI ' . $apelido, strtolower($apelido) . '.zzi.' . $suf . '@teste.local', $senha, $role]);
    $id = (int)$pdo->lastInsertId();
    $c['usuarios'][] = $id;
    foreach ($perms as $p) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $permId[$p]]);
    }
    return $id;
};
$mkContrato = static function (string $pessoa, string $codSetor, string $empresa, string $unidade) use ($pdo, $suf, &$c): int {
    static $n = 0;
    $n++;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa, unidade, codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute(["ZZI{$suf}-{$n}", $empresa, $unidade, "ZZIC{$suf}{$n}", "ZZIP{$suf}{$pessoa}", 'ZZI NOME-SECRETO ' . $pessoa, 'ZZI Empresa ' . $empresa, 'ZZI Unidade ' . $unidade, $codSetor, 'ZZI Setor ' . $codSetor, 'ZZI CARGO-SECRETO', 'ZZICG', '2026-01-01', '2026-01-01', 'zzi-teste']);
    $id = (int)$pdo->lastInsertId();
    $c['metadados'][] = $id;
    return $id;
};
$resp = static function (int $contrato, string $data, int $nps = 9): void {
    PesquisaIntegracaoQr::inserirResposta($contrato, $data, ['nota_nps' => $nps, 'nota_clareza' => 4, 'nota_acolhimento' => 5, 'nota_normas' => 3, 'nota_utilidade' => 4, 'nota_satisfacao_geral' => 4], 'ZZI-COMENTARIO-LIVRE-SECRETO');
};
$requisitar = static function (int $userId, string $role, string $controller, string $metodo, array $get = [], array $args = []): array {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, '0', $controller, $metodo, json_encode($get), json_encode($args), ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
    return [$status, preg_replace('/\n?@@STATUS=\d+@@\s*$/', '', $saida)];
};
$hoje = new DateTimeImmutable('2026-12-31');
$svc = new DashboardIntegracaoAgregadoService();
$painel = static fn(array $get, EscopoSetorial $e) => $svc->montar($get, $hoje, $e);
$k = 'DashboardIntegracaoAgregadoService';

try {
    // ---- 1. supressão complementar (função pura) --------------------------------------------------------------------------------------------------------------
    $g = static fn(array $ns): array => array_map(static fn(int $n): array => ['n' => $n], $ns);
    $vis = static fn(array $ns): array => array_keys($k::suprimirGrupos($g($ns))['visiveis']);
    $check($vis([6, 5, 9]) === [0, 1, 2] && $k::suprimirGrupos($g([6, 5, 9]))['suprimidos'] === 0, '(1) todos com >= 5: nada é suprimido');
    $check($vis([6, 4]) === [] && $k::suprimirGrupos($g([6, 4]))['suprimidos'] === 2, '(1) um grupo de 4 entre dois: o outro também é suprimido (senão total − 6 revelaria o 4)');
    $check($vis([7, 4, 9]) === [2] && $k::suprimirGrupos($g([7, 4, 9]))['suprimidos'] === 2, '(1) [7,4,9]: suprime o 4 e o menor exibido (7) — o resíduo passa a 11 (>= 5)');
    $check($vis([4, 4, 10]) === [2], '(1) [4,4,10]: dois pequenos somam 8 (>= 5): só eles são suprimidos');
    $check($vis([3, 2]) === [] && $vis([1]) === [], '(1) tudo pequeno: nada é exibido');
    $check($vis([5]) === [0] && $vis([4]) === [], '(1) limiar exato: 5 aparece, 4 não');

    // ---- 2. fixtures ----------------------------------------------------------------------------------------------------------------------------------------
    $codA = 'IA' . substr($suf, -5);
    $codB = 'IB' . substr($suf, -5);
    $codC = 'IC' . substr($suf, -5);
    $codD = 'ID' . substr($suf, -5);
    $sA = zzSetorTeste($pdo, $codA, 'ZZI');
    $sB = zzSetorTeste($pdo, $codB, 'ZZI');
    $sC = zzSetorTeste($pdo, $codC, 'ZZI');
    $sD = zzSetorTeste($pdo, $codD, 'ZZI');
    $c['setores'] = [$sA, $sB, $sC, $sD];
    // A: 6 em março + 4 em abril (10) · B: 5 em março · C: 4 em março · D: 6 PESSOAS distintas em maio (7 contratos: uma pessoa tem 2)
    for ($i = 1; $i <= 6; $i++) {
        $resp($mkContrato("a{$i}", $codA, 'ZEA', 'U1'), '2026-03-10', $i % 2 ? 10 : 8);
    }
    for ($i = 7; $i <= 10; $i++) {
        $resp($mkContrato("a{$i}", $codA, 'ZEA', 'U1'), '2026-04-10');
    }
    for ($i = 1; $i <= 5; $i++) {
        $resp($mkContrato("b{$i}", $codB, 'ZEA', 'U2'), '2026-03-12');
    }
    for ($i = 1; $i <= 4; $i++) {
        $resp($mkContrato("c{$i}", $codC, 'ZEB', 'U3'), '2026-03-15');
    }
    for ($i = 1; $i <= 6; $i++) {
        $resp($mkContrato("d{$i}", $codD, 'ZEA', 'U4'), '2026-05-10');
    }
    $resp($mkContrato('d1', $codD, 'ZEA', 'U4'), '2026-05-11'); // 2º contrato da MESMA pessoa d1

    $g1 = $novo('g1', 'gestor', [$PERM]);       // setor A (10)
    $gM = $novo('gmulti', 'gestor', [$PERM]);   // A + B (15)
    $gB = $novo('gsb', 'gestor', [$PERM]);      // só B (5)
    $gC = $novo('gsc', 'gestor', [$PERM]);      // só C (4)
    $gD = $novo('gsd', 'gestor', [$PERM]);      // só D (6 pessoas / 7 contratos)
    $g0 = $novo('gzero', 'gestor', [$PERM]);    // nenhum setor
    $gNP = $novo('gnp', 'gestor', []);          // sem permissão
    $vL = $novo('viewer', 'viewer', [$PERM, 'integracao_colaborador.visualizar']);
    $rh = $novo('rh', 'rh', []);
    $adm = $novo('adm', 'admin', []);
    zzGerenciar($pdo, $g1, $sA);
    zzGerenciar($pdo, $gM, $sA);
    zzGerenciar($pdo, $gM, $sB);
    zzGerenciar($pdo, $gB, $sB);
    zzGerenciar($pdo, $gC, $sC);
    zzGerenciar($pdo, $gD, $sD);
    $esc = static fn(int $u): EscopoSetorial => EscopoSetorial::resolver($u);

    // ---- 3. escopo e limiares ---------------------------------------------------------------------------------------------------------------------------------
    $p1 = $painel([], $esc($g1));
    $check($p1['suficiente'] === true && $p1['respondentes'] === 10, '(3) Gestor de 1 setor (A): 10 respondentes — só o do setor (B, C e D não entram)');
    $pM = $painel([], $esc($gM));
    $check($pM['suficiente'] === true && $pM['respondentes'] === 15, '(3) Gestor de 2 setores (A+B): 15 respondentes — nunca C/D');
    $p0 = $painel([], $esc($g0));
    $check($p0['suficiente'] === false && ($p0['motivo'] ?? '') === 'sem_escopo' && !isset($p0['respondentes']) && !isset($p0['nps']), '(3) Gestor SEM setores: nenhum dado (nunca acesso global)');
    $pC = $painel([], $esc($gC));
    $check($pC['suficiente'] === false && ($pC['motivo'] ?? '') === 'poucos_respondentes' && !isset($pC['respondentes']), '(3) grupo com 4 respondentes: "dados insuficientes" — nem o total é informado');
    $pB = $painel([], $esc($gB));
    $check($pB['suficiente'] === true && $pB['respondentes'] === 5, '(3) grupo com EXATAMENTE 5: exibido');
    $pD = $painel([], $esc($gD));
    $check($pD['suficiente'] === true && $pD['respondentes'] === 6, '(3) grupo com 6 PESSOAS distintas (7 respostas: uma pessoa com 2 contratos conta UMA vez): 6 respondentes');

    // ---- 4. filtros: empresa, unidade, setor, período ------------------------------------------------------------------------------------------------------
    $pFE = $painel(['empresa' => 'ZEA'], $esc($gM));
    $check($pFE['suficiente'] === true && $pFE['respondentes'] === 15 && $pFE['filtros']['empresa'] === 'ZEA', '(4) filtro por empresa (ZEA) dentro do escopo');
    $pFU = $painel(['unidade' => 'ZEA|U2'], $esc($gM));
    $check($pFU['suficiente'] === true && $pFU['respondentes'] === 5, '(4) filtro por unidade (U2 = setor B): 5 respondentes');
    $pFS = $painel(['setor' => $codB], $esc($gM));
    $check($pFS['suficiente'] === true && $pFS['respondentes'] === 5, '(4) filtro por setor (B): 5 respondentes');
    $check($painel(['setor' => $codC], $esc($g1))['respondentes'] === 10 && $painel(['empresa' => 'ZEB'], $esc($g1))['respondentes'] === 10 && $painel(['unidade' => 'ZEB|U3'], $esc($g1))['respondentes'] === 10, '(4) filtros FORA do escopo (setor C, empresa ZEB, unidade U3) são descartados — nunca ampliam nem trocam o escopo');
    $check($painel(['setor' => $codB], $esc($g1))['respondentes'] === 10, '(4) filtro de setor que o Gestor não gerencia (B) é ignorado');
    $check($painel(['gestor' => '1'], $esc($g1))['respondentes'] === 10, '(4) o filtro "gestor" do painel operacional não existe aqui (ignorado)');

    // ---- 5. reconstrução por diferença -------------------------------------------------------------------------------------------------------------------------
    $pAno = $painel(['ano' => '2026'], $esc($g1));
    $mensal = array_column($pAno['mensal'], null, 'rotulo');
    $check($pAno['suficiente'] === true && $pAno['respondentes'] === 10 && $mensal['2026-03']['suprimido'] === true && $mensal['2026-04']['suprimido'] === true, '(5) A em 2026: total 10; abril (4) é suprimido e, por COMPLEMENTAR, março (6) também — total − março não isola os 4 de abril');
    $check(!isset($mensal['2026-03']['nps']) && !isset($mensal['2026-03']['respondentes']), '(5) mês suprimido não traz nenhum número');
    $pMarco = $painel(['ano' => '2026', 'mes' => '3'], $esc($g1));
    $check($pMarco['suficiente'] === false && ($pMarco['motivo'] ?? '') === 'combinacao_de_filtros', '(5) março sozinho (6) é recusado: o ano (10) menos março isolaria 4 pessoas');
    $pAbril = $painel(['ano' => '2026', 'mes' => '4'], $esc($g1));
    $check($pAbril['suficiente'] === false && ($pAbril['motivo'] ?? '') === 'poucos_respondentes', '(5) abril sozinho (4): dados insuficientes');
    $pMSA = $painel(['ano' => '2026', 'mes' => '3', 'setor' => $codA], $esc($gM));
    $check($pMSA['suficiente'] === false, '(5) A+março (6): recusado — a visão sem o filtro de mês (A = 10) isolaria 4');
    $pSB = $painel(['ano' => '2026', 'mes' => '3', 'setor' => $codB], $esc($gM));
    $check($pSB['suficiente'] === true && $pSB['respondentes'] === 5, '(5) B+março (5): permitido — as visões mais amplas (B no ano = 5; março A+B = 11) não isolam 1–4 pessoas');
    $pSetorA = $painel(['setor' => $codA], $esc($gM));
    $check($pSetorA['suficiente'] === true && $pSetorA['respondentes'] === 10, '(5) setor A (10) sem período: permitido (a visão sem o filtro de setor = 15 → resto 5, >= 5)');
    $check($painel(['ano' => '2026', 'mes' => '5'], $esc($gD))['suficiente'] === true, '(5) D em maio (6; o ano e o histórico também têm os mesmos 6): permitido');
    $grupos = array_column($pM['por_setor']['grupos'], null, 'rotulo');
    $check(count($pM['por_setor']['grupos']) === 2 && $pM['por_setor']['suprimidos'] === 0, '(5) A+B: os dois setores (10 e 5) são exibidos — nenhum suprimido');
    $pMAno = $painel(['ano' => '2026'], $esc($gM));
    $mm = array_column($pMAno['mensal'], null, 'rotulo');
    $check($pMAno['respondentes'] === 15 && $mm['2026-03']['suprimido'] === true && $mm['2026-04']['suprimido'] === true, '(5) A+B em 2026 (15): abril (4) suprimido → março (11) também (resíduo 4 < 5)');

    // ---- 6. nada individual na saída -------------------------------------------------------------------------------------------------------------------------------------
    $proibidos = ['comentario', 'comentarios', 'nome', 'cargo', 'metadados_id', 'colaborador_id', 'token_hash', 'respondida_em', 'codigo_pessoa', 'cpf', 'nascimento'];
    $achados = [];
    $varre = static function (mixed $no, string $caminho) use (&$varre, &$achados, $proibidos): void {
        if (is_array($no)) {
            foreach ($no as $kk => $vv) {
                if ($kk === 'opcoes') {
                    continue; // rótulos de empresa/unidade/setor (catálogo), não dado de respondente
                }
                if (is_string($kk) && in_array($kk, $proibidos, true)) {
                    $achados[] = $caminho . '.' . $kk;
                }
                $varre($vv, $caminho . '.' . $kk);
            }
        }
    };
    $varre($p1, 'p1');
    $varre($pM, 'pM');
    $json = json_encode([$p1, $pM, $pAno], JSON_UNESCAPED_UNICODE);
    $check($achados === [] && !str_contains($json, 'ZZI-COMENTARIO') && !str_contains($json, 'NOME-SECRETO') && !str_contains($json, 'CARGO-SECRETO') && !str_contains($json, 'ZZIP'), '(6) a estrutura devolvida não tem comentários, nomes, cargos, ids nem datas de resposta' . ($achados === [] ? '' : ': ' . implode(', ', $achados)));

    // ---- 7. HTTP: acesso, API, bloqueios ------------------------------------------------------------------------------------------------------------------------------------
    [$st, $html] = $requisitar($g1, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'index');
    $check($st === 200 && str_contains($html, 'Respondentes') && !str_contains($html, 'ZZI-COMENTARIO') && !str_contains($html, 'NOME-SECRETO') && !str_contains($html, 'CARGO-SECRETO') && !str_contains($html, 'ZZI Setor ' . $codB) && !str_contains($html, 'ZZI Setor ' . $codC), '(7) tela do Gestor: 200, só o próprio setor, sem comentário/nome/cargo');
    [$st, $corpo] = $requisitar($g1, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'dados', ['ano' => '2026']);
    $j = json_decode($corpo, true);
    $check($st === 200 && is_array($j) && ($j['respondentes'] ?? null) === 10 && !isset($j['opcoes']) && !str_contains($corpo, 'ZZI-COMENTARIO') && !str_contains($corpo, 'NOME-SECRETO'), '(7) API JSON: mesma estrutura fechada, sem comentários nem dados individuais');
    [$st, $corpo] = $requisitar($g1, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'dados', ['ano' => '2026', 'mes' => '3']);
    $j = json_decode($corpo, true);
    $check($st === 200 && ($j['suficiente'] ?? true) === false && !isset($j['respondentes']) && !isset($j['nps']), '(7) API: combinação recusada devolve só a indicação de dados insuficientes');
    [$st, $corpo] = $requisitar($g1, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'dados', ['setor' => $codC, 'empresa' => 'ZEB']);
    $j = json_decode($corpo, true);
    $check($st === 200 && ($j['respondentes'] ?? null) === 10, '(7) API: setor/empresa de fora do escopo na URL não mudam o resultado');
    [$st] = $requisitar($gNP, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'index');
    [$st2] = $requisitar($gNP, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'dados');
    $check($st === 403 && $st2 === 403, '(7) Gestor sem a permissão: 403 na tela e na API');
    [$st] = $requisitar($vL, 'viewer', 'AdminDashboardIntegracaoAgregadoController', 'index');
    [$st2] = $requisitar($vL, 'viewer', 'AdminDashboardIntegracaoAgregadoController', 'dados');
    $check($st === 403 && $st2 === 403 && !Authorization::usuarioTemPermissao($vL, $PERM), '(7) Viewer bloqueado (mesmo com a permissão gravada): 403 na tela e na API');
    [$st, $corpo] = $requisitar($g0, 'gestor', 'AdminDashboardIntegracaoAgregadoController', 'dados');
    $check($st === 200 && !isset(json_decode($corpo, true)['respondentes']), '(7) Gestor sem setores: 200 sem nenhum dado');
    // o Gestor não acessa o operacional (QR, campanhas, resultados, dashboard operacional, registro individual)
    $fechadas = [['AdminPesquisaIntegracaoResultadosController', 'dashboard', []], ['AdminPesquisaIntegracaoResultadosController', 'resultados', ['2026-03-10']], ['AdminPesquisaIntegracaoQrController', 'index', []], ['AdminPesquisaIntegracaoQrController', 'abrir', []], ['AdminPesquisaReacaoIntegracaoController', 'index', []]];
    $furos = [];
    foreach ($fechadas as [$ctrl, $m, $args]) {
        [$st] = $requisitar($g1, 'gestor', $ctrl, $m, [], $args);
        if ($st !== 403) {
            $furos[] = "{$ctrl}::{$m}={$st}";
        }
    }
    $check($furos === [], '(7) o Gestor com a permissão do agregado NÃO acessa o dashboard operacional, resultados, QR nem campanhas (403)' . ($furos === [] ? '' : ' — ' . implode(', ', $furos)));
    // Admin/RH preservados
    [$stRh] = $requisitar($rh, 'rh', 'AdminPesquisaIntegracaoResultadosController', 'dashboard');
    [$stAd] = $requisitar($adm, 'admin', 'AdminPesquisaIntegracaoQrController', 'index');
    [$stRhA] = $requisitar($rh, 'rh', 'AdminDashboardIntegracaoAgregadoController', 'dados');
    $check($stRh === 200 && $stAd === 200 && $stRhA === 200, '(7) Admin e RH preservam o painel operacional e o QR; também consultam a visão agregada (com as mesmas regras de privacidade)');
    $global = $painel([], EscopoSetorial::global());
    $check($global['suficiente'] === true && $global['respondentes'] >= 30, '(7) escopo global (Admin/RH) enxerga todos os setores');

    // ---- 8. política e navegação --------------------------------------------------------------------------------------------------------------------------------------------------
    $P = 'PermissaoPerfilPolicy';
    $check(PermissaoPerfilPolicy::classificar('gestor', $PERM) === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('viewer', $PERM) === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'integracao_colaborador.visualizar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'integracao_colaborador.editar') === $P::INCOMPATIVEL, '(8) política: agregado liberado ao Gestor; o dashboard operacional/QR/gestão (integracao_colaborador.*) seguem incompatíveis');
    $_SESSION = ['user' => true, 'user_id' => $g1, 'user_role' => 'gestor', 'user_is_supervisor' => false];
    $nav = new PortalNavegacaoService();
    $abas = array_column($nav->abas('integracao'), 'chave');
    $_SESSION = ['user' => true, 'user_id' => $gNP, 'user_role' => 'gestor', 'user_is_supervisor' => false];
    $abasSem = array_column((new PortalNavegacaoService())->abas('integracao'), 'chave');
    $_SESSION = [];
    $check($abas === ['agregado'] && $abasSem === [], '(8) navegação: o Gestor com a permissão vê só a aba "Dashboard agregado"; sem a permissão, nada');
} finally {
    if ($c['metadados'] !== []) {
        $in = implode(',', array_map('intval', $c['metadados']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE metadados_id IN ({$in})");
        $pdo->exec("DELETE FROM colaboradores_metadados WHERE id IN ({$in})");
    }
    if ($c['usuarios'] !== []) {
        $lu = implode(',', array_map('intval', $c['usuarios']));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ({$lu})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$lu})");
    }
    zzLimparSetores($pdo, $c['setores']);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — dashboard agregado de Integração (Gestor).\n";
