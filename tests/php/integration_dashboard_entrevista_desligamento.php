<?php

/**
 * Integração — Dashboard da Entrevista de Desligamento (Repository + Service + Controller + permissão + menu + seed +
 * página). Fixtures ZZDE-* (empresa/unidade/cargo próprios) com datas FIXAS no passado (2025) e limpeza em `finally`.
 * Prova, contra o banco:
 *   - período (inclusivo), Unidade (empresa+unidade) e Cargo (código) como filtros de todos os indicadores;
 *   - Total de desligamentos por CONTRATO; geradas x respondidas; taxa (0,0% real x "sem base");
 *   - competência = data de desligamento: entrevista respondida em mês posterior fica no mês da demissão;
 *   - satisfação, eNPS, liderança, cultura, integração, Top 5 motivos e fatores (só respondidas);
 *   - permanência (datas inválidas excluídas); Falecimento e ativos fora; contrato futuro/antes do período fora;
 *   - resumo por Unidade/Cargo; número constante de consultas (sem N+1);
 *   - permissão própria (individual; Admin por bypass; `resultados` NÃO basta), rota, menu e página sem PII.
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
$cpfSecreto = '11144477735';
$nomeSecreto = 'ZZDE Pessoa Secreta';
$E1 = 'ZDA' . $suffix;
$E2 = 'ZDB' . $suffix;
$U1 = 'ZU1';
$U2 = 'ZU2';
$CG1 = 'ZDC1' . $suffix;
$CG2 = 'ZDC2' . $suffix;
$agoraGeracao = new DateTimeImmutable('2025-08-01 10:00:00');
$agoraResposta = '2025-08-02 10:00:00';
$periodo = ['inicio' => '2025-01-01', 'fim' => '2025-06-30'];

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
$dias = static fn(string $a, string $b): int => (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days;

$mk = static function (string $emp, string $uni, string $cargo, ?string $adm, ?string $dem, ?string $motivo) use ($pdo, &$criados, $suffix, $cpfSecreto, $nomeSecreto): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZDE_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, $uni, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, $cpfSecreto, $nomeSecreto . ' ' . $seq, 'ZZDE Empresa ' . substr($emp, 0, 3), 'ZZDE Unidade ' . $uni,
        'ZZDE ' . $cargo, $cargo, $adm, $dem, $motivo, $motivo === null ? null : 'ZZDE motivo ' . $motivo, $dem === null ? 1 : 0, 'zzde-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$respostas = static function (string $motivo, int $sat, int $enps, int $lid, int $cul, int $int, int $exp = 4): array {
    $d = ['motivo_principal' => $motivo, 'experiencia_geral' => $sat, 'enps' => $enps];
    foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $chave => $secao) {
        foreach (array_keys($secao['itens']) as $campo) {
            $d[$campo] = match ($chave) { 'lideranca' => $lid, 'cultura' => $cul, 'integracao' => $int, default => $exp };
        }
    }
    return $d;
};

try {
    // ---- permissões / usuários -----------------------------------------------------------------------------------------
    $perm = $pdo->query("SELECT id, modulo, ordem, ativo FROM permissoes WHERE codigo = 'dashboard_entrevista_desligamento.visualizar'")->fetch(PDO::FETCH_ASSOC);
    $check($perm !== false && (int)$perm['ativo'] === 1 && $perm['modulo'] === 'dashboard_entrevista_desligamento' && (int)$perm['ordem'] === 650, '(permissão) dashboard_entrevista_desligamento.visualizar no catálogo (650) — seed aplicado localmente');
    $permId = (int)($perm['id'] ?? 0);
    $resultadosId = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'entrevista_desligamento.resultados'")->fetchColumn();

    $novoUsuario = static function (string $rotulo, string $role, array $permIds) use (&$criados, $senha, $suffix): int {
        $id = User::create('ZZDE ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzde.' . $suffix . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        if ($permIds !== []) {
            Authorization::sincronizar($id, $permIds);
        }
        return $id;
    };
    $adminId = $novoUsuario('Admin', 'admin', []);
    $comPermId = $novoUsuario('Com Permissao', 'viewer', [$permId]);
    $rhSemId = $novoUsuario('RH Sem Permissao', 'rh', []);
    $soResultadosId = $novoUsuario('So Resultados', 'viewer', [$resultadosId]);

    $tem = static fn(int $u): bool => Authorization::usuarioTemPermissao($u, 'dashboard_entrevista_desligamento.visualizar');
    $check($tem($adminId) === true, '(permissão) Admin acessa pelo bypass central');
    $check($tem($comPermId) === true, '(permissão) Usuário com a permissão individual acessa');
    $check($tem($rhSemId) === false, '(permissão) RH SEM a permissão individual NÃO acessa — role sozinha não basta');
    $check($tem($soResultadosId) === false && Authorization::usuarioTemPermissao($soResultadosId, 'entrevista_desligamento.resultados') === true, '(permissão) `entrevista_desligamento.resultados` NÃO substitui a permissão do dashboard (recurso próprio)');
    $concedidosFora = (int)$pdo->query(
        "SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id
         WHERE p.codigo = 'dashboard_entrevista_desligamento.visualizar' AND up.usuario_id NOT IN (" . implode(',', array_map('intval', $criados['usuarios'])) . ')'
    )->fetchColumn();
    $check($concedidosFora === 0, '(permissão) Ninguém além do usuário de teste recebeu a permissão — o seed não concede automaticamente');
    $corpoIndex = $corpoDe(AdminDashboardEntrevistaDesligamentoController::class, 'index');
    $check(str_contains($corpoIndex, "Auth::requireRole(['admin', 'rh', 'viewer'])") && str_contains($corpoIndex, "Authorization::requirePermissao('dashboard_entrevista_desligamento.visualizar')"), '(backend) index() exige sessão + a permissão (403 real, não só ocultação de menu)');
    $fonteIndexPhp = (string)file_get_contents(BASE_PATH . '/index.php');
    $check(str_contains($fonteIndexPhp, "\$router->get('/admin/dashboard-entrevista-desligamento', [AdminDashboardEntrevistaDesligamentoController::class, 'index'])"), '(rota) /admin/dashboard-entrevista-desligamento registrada sob /admin (login global do index.php)');
    $regraNavDashboardEntrevista = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/dashboard-entrevista-desligamento') { $regraNavDashboardEntrevista = $it['regra']; }
        }
    }
    $check($regraNavDashboardEntrevista === 'perm:dashboard_entrevista_desligamento.visualizar', '(menu) A Central só oferece o Dashboard da Entrevista sob a regra perm:dashboard_entrevista_desligamento.visualizar (PortalNavegacaoService::definicao() — sidebar removida, fonte da verdade agora é o serviço de navegação)');

    // ---- fixtures ------------------------------------------------------------------------------------------------------------
    $c1 = $mk($E1, $U1, $CG1, '2024-01-10', '2025-01-10', '003');  // Jan | respondida em MARÇO | enps 10
    $c2 = $mk($E1, $U1, $CG2, '2024-07-20', '2025-01-20', '002');  // Jan | respondida | enps 6
    $c3 = $mk($E1, $U1, $CG1, '2023-02-05', '2025-02-05', '016');  // Fev | pendente
    $c4 = $mk($E1, $U2, $CG2, null, '2025-02-15', '001'); // Fev | SEM entrevista | sem admissão
    $c5 = $mk($E1, $U2, $CG1, '2025-03-01', '2025-02-20', '005');  // Fev | respondida | admissão POSTERIOR à demissão (inválida) | enps 8
    $c6 = $mk($E1, $U1, $CG1, '2020-03-10', '2025-03-10', '020');  // Mar | Falecimento (fora)
    $c7 = $mk($E2, $U1, $CG1, '2024-05-01', '2025-05-01', '003');  // Mai | OUTRA empresa, MESMO código de unidade
    $c8 = $mk($E1, $U1, $CG1, '2020-01-01', null, null);            // ativo (não conta)
    $c9 = $mk($E1, $U1, $CG1, '2020-01-01', '2024-12-31', '003');  // ANTES do período | respondida
    $c10 = $mk($E1, $U1, $CG1, '2020-01-01', '2025-07-01', '003'); // DEPOIS do período | respondida

    $repo = new EntrevistaDesligamentoRepository();
    $servicoEntrevista = new EntrevistaDesligamentoService($repo);
    $gerarEResponder = static function (int $contratoId, ?array $dados, ?string $respondidaEm = null) use ($servicoEntrevista, $repo, $agoraGeracao, $agoraResposta, $adminId, $pdo): ?int {
        $g = $servicoEntrevista->gerar($contratoId, $adminId, $agoraGeracao);
        if (!$g['ok']) {
            throw new RuntimeException('Falha ao gerar fixture: ' . ($g['error'] ?? ''));
        }
        if ($dados !== null) {
            $ok = $repo->responder((int)$g['id'], hash('sha256', (string)$g['token']), $agoraResposta, $dados, ($dados['_fatores'] ?? []));
            if (!$ok) {
                throw new RuntimeException('Falha ao responder fixture');
            }
            if ($respondidaEm !== null) {
                $pdo->prepare('UPDATE entrevistas_desligamento SET respondida_em = ? WHERE id = ?')->execute([$respondidaEm, (int)$g['id']]);
            }
        }
        return (int)$g['id'];
    };
    $r1 = $respostas('nova_oportunidade', 8, 10, 5, 4, 3) + ['_fatores' => ['remuneracao', 'lideranca']];
    $r2 = $respostas('remuneracao', 4, 6, 3, 2, 1) + ['_fatores' => ['remuneracao']];
    $r5 = $respostas('nova_oportunidade', 6, 8, 4, 4, 4) + ['_fatores' => ['clima']];
    $gerarEResponder($c1, $r1, '2025-03-05 09:00:00');          // respondida em MARÇO; competência = JANEIRO
    $gerarEResponder($c2, $r2);
    $gerarEResponder($c3, null);                                  // pendente
    $gerarEResponder($c5, $r5);
    $gerarEResponder($c7, null);
    $gerarEResponder($c9, $respostas('equipe', 10, 10, 5, 5, 5) + ['_fatores' => ['equipe']]);
    $gerarEResponder($c10, $respostas('equipe', 10, 10, 5, 5, 5) + ['_fatores' => ['equipe']]);
    $semEntrevista = (int)$pdo->query("SELECT COUNT(*) FROM entrevistas_desligamento WHERE metadados_id IN ($c4, $c6, $c8)")->fetchColumn();
    $check($semEntrevista === 0, '(fixture) c4 (elegível), c6 (Falecimento) e c8 (ativo) ficaram sem entrevista');

    $spy = new class extends DashboardEntrevistaDesligamentoRepository {
        public int $chamadas = 0;
        public function desligamentosPorMes(array $f, string $m): array { $this->chamadas++; return parent::desligamentosPorMes($f, $m); }
        public function entrevistasPorMes(array $f): array { $this->chamadas++; return parent::entrevistasPorMes($f); }
        public function motivosDeclarados(array $f): array { $this->chamadas++; return parent::motivosDeclarados($f); }
        public function fatoresContribuintes(array $f): array { $this->chamadas++; return parent::fatoresContribuintes($f); }
        public function desligamentosPorUnidade(array $f): array { $this->chamadas++; return parent::desligamentosPorUnidade($f); }
        public function entrevistasPorUnidade(array $f): array { $this->chamadas++; return parent::entrevistasPorUnidade($f); }
        public function desligamentosPorCargo(array $f): array { $this->chamadas++; return parent::desligamentosPorCargo($f); }
        public function entrevistasPorCargo(array $f): array { $this->chamadas++; return parent::entrevistasPorCargo($f); }
    };
    $service = new DashboardEntrevistaDesligamentoService($spy);
    $hoje = new DateTimeImmutable('2026-09-19');
    $opcoes = $service->opcoesFiltro();
    $painel = static function (array $get) use ($service, $hoje, $opcoes, $periodo): array {
        $filtros = DashboardEntrevistaDesligamentoService::normalizarFiltros($get + $periodo, $hoje, $opcoes);
        return $service->montarPainel($filtros);
    };
    $chaveU1E1 = $E1 . '|' . $U1;

    // ---- opções dos filtros ---------------------------------------------------------------------------------------------------
    $chaves = array_column($opcoes['unidades'], 'nome', 'chave');
    $check(isset($chaves[$chaveU1E1]) && isset($chaves[$E2 . '|' . $U1]) && isset($chaves[$E1 . '|' . $U2]), '(filtros) Unidades pela identidade oficial empresa+unidade: o mesmo código ZU1 aparece uma vez por empresa');
    $check($chaves[$chaveU1E1] === 'ZZDE Unidade ZU1 — ZZDE Empresa ZDA' && !str_contains($chaves[$chaveU1E1], $cpfSecreto), '(filtros) Nome da unidade acompanha a empresa (sem confundir os dois conceitos)');
    $cargosOpc = array_column($opcoes['cargos'], 'nome', 'codigo');
    $check(($cargosOpc[$CG1] ?? null) === 'ZZDE ' . $CG1 && isset($cargosOpc[$CG2]), '(filtros) Cargos por codigo_cargo (sem catálogo, o texto oficial do espelho)');

    // ---- Unidade 1 da empresa 1 ---------------------------------------------------------------------------------------------
    $spy->chamadas = 0;
    $p = $painel(['unidade' => $chaveU1E1]);
    $check($spy->chamadas === 8, '(performance) 8 consultas agregadas, independente de quantos meses, unidades ou cargos (sem N+1)');
    $x = $p['executivo'];
    $check($x['desligamentos'] === 4, '(desligamentos) Unidade E1/U1 no período: c1, c2, c3 e c6 = 4 CONTRATOS (ativo, antes e depois do período fora)');
    $check($x['geradas'] === 3 && $x['respondidas'] === 2 && $x['taxa_resposta'] === 66.7, '(entrevistas) Geradas 3 (c1, c2, c3), respondidas 2 → taxa 66,7%');
    $check($x['cobertura'] === ['elegiveis' => 3, 'nao_elegiveis' => 1, 'sem_entrevista' => 0, 'com_entrevista' => 3, 'percentual' => 100.0], '(cobertura) Falecimento (c6) fora: 3 elegíveis, todos com entrevista gerada = 100%');
    $check($x['satisfacao'] === ['media' => 6.0, 'n' => 2], '(satisfação) (8 + 4) ÷ 2 = 6,0 sobre 2 respostas (a pendente não entra)');
    $check($x['enps']['valor'] === 0.0 && $x['enps']['n'] === 2 && $x['enps']['promotores'] === 1 && $x['enps']['detratores'] === 1 && $x['enps']['neutros'] === 0, '(eNPS) Notas 10 e 6: 1 promotor, 1 detrator = 0,0 (zero REAL) sobre 2 respostas');
    $check($p['blocos']['lideranca']['media'] === 4.0 && $p['blocos']['lideranca']['n'] === 2 && $p['blocos']['cultura']['media'] === 3.0 && $p['blocos']['integracao']['media'] === 2.0, '(blocos) Liderança (5 e 3) = 4,0; Cultura (4 e 2) = 3,0; Integração (3 e 1) = 2,0 — 2 respostas cada');
    $check($p['blocos']['lideranca']['dimensoes'][0]['media'] === 4.0 && count($p['blocos']['cultura']['dimensoes']) === 6 && count($p['blocos']['integracao']['dimensoes']) === 3, '(blocos) Média por dimensão: 5 de Liderança, 6 de Cultura, 3 de Integração');
    $expectPerm = round(($dias('2024-01-10', '2025-01-10') + $dias('2024-07-20', '2025-01-20') + $dias('2023-02-05', '2025-02-05') + $dias('2020-03-10', '2025-03-10')) / 4 / 30.4375, 1);
    $check($x['permanencia']['meses'] === $expectPerm && $x['permanencia']['n'] === 4 && $x['permanencia']['invalidos'] === 0, "(permanência) Média de dias ÷ 30,4375 = {$expectPerm} meses sobre os 4 contratos (população oficial, inclui Falecimento)");

    $meses = array_column($p['mensal'], null, 'mes');
    $check(array_keys($meses) === ['2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06'], '(mensal) Seis meses do período');
    $check($meses['2025-01']['desligamentos'] === 2 && $meses['2025-01']['respondidas'] === 2 && $meses['2025-01']['geradas'] === 2 && $meses['2025-01']['taxa_resposta'] === 100.0, '(competência) c1 foi RESPONDIDA em março, mas conta em JANEIRO (mês da demissão): janeiro tem 2 respondidas');
    $check($meses['2025-03']['respondidas'] === 0 && $meses['2025-03']['desligamentos'] === 1 && $meses['2025-03']['geradas'] === 0 && $meses['2025-03']['taxa_resposta'] === null && $meses['2025-03']['enps'] === null, '(competência) Março (Falecimento, sem entrevista): 0 respondidas apesar da resposta de c1 em março; taxa e eNPS = null (sem base)');
    $check($meses['2025-02']['geradas'] === 1 && $meses['2025-02']['respondidas'] === 0 && $meses['2025-02']['taxa_resposta'] === 0.0 && $meses['2025-02']['satisfacao'] === null && $meses['2025-02']['lideranca'] === null, '(zero real × sem base) Fevereiro: 1 gerada pendente → taxa 0,0% (real); satisfação e liderança = null');
    $check($meses['2025-01']['enps'] === 0.0 && $meses['2025-01']['satisfacao'] === 6.0 && $meses['2025-01']['lideranca'] === 4.0, '(mensal) Janeiro: eNPS 0,0, satisfação 6,0, liderança 4,0');
    $check($meses['2025-04']['desligamentos'] === 0 && $meses['2025-04']['geradas'] === 0 && $meses['2025-04']['taxa_resposta'] === null && $meses['2025-06']['enps'] === null, '(sem base) Meses sem nada: contagens 0, indicadores null');

    $check($p['motivos']['total'] === 2 && array_column($p['motivos']['itens'], 'rotulo') === ['Nova oportunidade profissional', 'Remuneração'] && array_column($p['motivos']['itens'], 'percentual') === [50.0, 50.0], '(motivos) Declarados nas entrevistas respondidas (c1 e c2), empate por rótulo; nunca o motivo oficial');
    $fat = array_column($p['fatores']['itens'], 'quantidade', 'codigo');
    $check($fat === ['remuneracao' => 2, 'lideranca' => 1] && $p['fatores']['base'] === 2, '(fatores) Seleção múltipla: remuneração 2 marcações (c1, c2), liderança 1 — base = 2 entrevistas respondidas');
    $pct = array_column($p['fatores']['itens'], 'percentual', 'codigo');
    $check($pct['remuneracao'] === 100.0 && $pct['lideranca'] === 50.0, '(fatores) Percentual sobre as entrevistas respondidas');

    $check(count($p['unidades']) === 1 && $p['unidades'][0]['nome'] === 'ZZDE Unidade ZU1 — ZZDE Empresa ZDA' && $p['unidades'][0]['desligamentos'] === 4 && $p['unidades'][0]['respondidas'] === 2, '(unidade) Resumo por unidade (filtrado): desligamentos 4, respondidas 2');
    $cargos = array_column($p['cargos']['itens'], null, 'nome');
    $check($cargos['ZZDE ' . $CG1]['desligamentos'] === 3 && $cargos['ZZDE ' . $CG1]['geradas'] === 2 && $cargos['ZZDE ' . $CG1]['respondidas'] === 1 && $cargos['ZZDE ' . $CG1]['satisfacao'] === 8.0 && $cargos['ZZDE ' . $CG1]['enps'] === 100.0, '(cargo) CG1 na unidade: c1, c3, c6 → 3 desligamentos, 2 geradas, 1 respondida, satisfação 8,0, eNPS +100,0');
    $check($cargos['ZZDE ' . $CG2]['desligamentos'] === 1 && $cargos['ZZDE ' . $CG2]['respondidas'] === 1 && $cargos['ZZDE ' . $CG2]['enps'] === -100.0, '(cargo) CG2 (c2, nota 6 = detrator): eNPS −100,0');

    // ---- identidade da unidade: mesmo código em outra empresa ------------------------------------------------------------
    $pOutra = $painel(['unidade' => $E2 . '|' . $U1]);
    $check($pOutra['executivo']['desligamentos'] === 1 && $pOutra['executivo']['geradas'] === 1 && $pOutra['executivo']['respondidas'] === 0 && $pOutra['executivo']['taxa_resposta'] === 0.0 && $pOutra['executivo']['enps']['valor'] === null && $pOutra['executivo']['satisfacao']['media'] === null, '(unidade) Empresa 2 / ZU1: só c7 (1 desligamento, 1 gerada pendente) — não se mistura com a Empresa 1');
    $check($x['desligamentos'] === 4, '(unidade) E1/U1 continua com 4: o contrato c7 (mesmo código de unidade, outra empresa) não entra');

    // ---- Unidade 2 (contrato sem entrevista, admissão inválida) ------------------------------------------------------------
    $pU2 = $painel(['unidade' => $E1 . '|' . $U2]);
    $x2 = $pU2['executivo'];
    $check($x2['desligamentos'] === 2 && $x2['geradas'] === 1 && $x2['respondidas'] === 1 && $x2['taxa_resposta'] === 100.0, '(unidade) E1/U2: c4 e c5 (2 desligamentos); só c5 tem entrevista, respondida');
    $check($x2['cobertura']['elegiveis'] === 2 && $x2['cobertura']['sem_entrevista'] === 1 && $x2['cobertura']['com_entrevista'] === 1 && $x2['cobertura']['percentual'] === 50.0, '(cobertura) 1 desligamento elegível SEM entrevista gerada (c4): cobertura 50,0%');
    $check($x2['permanencia'] === ['meses' => null, 'n' => 0, 'invalidos' => 2], '(permanência) c4 (sem admissão) e c5 (admissão posterior à demissão) são excluídos: sem base, 2 inválidos contados à parte');
    $check($x2['enps']['valor'] === 0.0 && $x2['enps']['neutros'] === 1 && $x2['enps']['n'] === 1 && $x2['satisfacao'] === ['media' => 6.0, 'n' => 1], '(eNPS) Nota 8 = neutro: eNPS 0,0 sobre 1 resposta; satisfação 6,0');

    // ---- Cargo -------------------------------------------------------------------------------------------------------------------
    $pCg1 = $painel(['cargo' => $CG1]);
    $check($pCg1['executivo']['desligamentos'] === 5 && $pCg1['executivo']['geradas'] === 4 && $pCg1['executivo']['respondidas'] === 2, '(cargo) CG1: 5 desligamentos, 4 geradas (c1, c3, c5, c7), 2 respondidas (c1, c5)');
    $check($pCg1['executivo']['enps']['promotores'] === 1 && $pCg1['executivo']['enps']['neutros'] === 1 && $pCg1['executivo']['enps']['detratores'] === 0 && $pCg1['executivo']['enps']['valor'] === 50.0, '(cargo) CG1: eNPS = (1 promotor − 0 detratores) ÷ 2 = +50,0');
    $check(count($pCg1['unidades']) === 3, '(cargo) O filtro de Cargo também vale para o resumo por unidade (3 unidades têm CG1)');
    $pCombo = $painel(['unidade' => $chaveU1E1, 'cargo' => $CG2]);
    $check($pCombo['executivo']['desligamentos'] === 1 && $pCombo['executivo']['respondidas'] === 1 && $pCombo['executivo']['enps']['valor'] === -100.0, '(filtros) Unidade + Cargo combinados: só c2');

    // ---- período -----------------------------------------------------------------------------------------------------------------
    $pDia = $painel(['unidade' => $chaveU1E1, 'inicio' => '2025-01-10', 'fim' => '2025-01-10']);
    $check($pDia['executivo']['desligamentos'] === 1 && $pDia['executivo']['respondidas'] === 1 && count($pDia['mensal']) === 1, '(período) Datas inclusivas: só o dia 10/01 → c1');
    $pMarco = $painel(['unidade' => $chaveU1E1, 'inicio' => '2025-03-01', 'fim' => '2025-03-31']);
    $check($pMarco['executivo']['desligamentos'] === 1 && $pMarco['executivo']['respondidas'] === 0 && $pMarco['executivo']['enps']['valor'] === null && $pMarco['motivos']['itens'] === [], '(competência) Filtrar MARÇO (mês em que c1 respondeu) não traz a resposta: a competência é a data de desligamento');
    $pAntes = $painel(['unidade' => $chaveU1E1, 'inicio' => '2024-12-01', 'fim' => '2024-12-31']);
    $check($pAntes['executivo']['desligamentos'] === 1 && $pAntes['executivo']['geradas'] === 1 && $pAntes['executivo']['respondidas'] === 1, '(período) Dezembro/2024: apenas c9 (fora do período principal)');
    $pFuturo = $painel(['unidade' => $chaveU1E1, 'inicio' => '2026-01-01', 'fim' => '2099-12-31']);
    $check($pFuturo['executivo']['desligamentos'] === 0 && $pFuturo['executivo']['taxa_resposta'] === null && $pFuturo['executivo']['enps']['valor'] === null && $pFuturo['executivo']['permanencia']['meses'] === null && $pFuturo['motivos']['itens'] === [], '(sem base) Período sem nada: contagens 0; taxa, eNPS e permanência = null');

    // ---- sem filtro de unidade/cargo (população real do banco de teste) ------------------------------------------------------------
    $todos = $service->montarPainel(DashboardEntrevistaDesligamentoService::normalizarFiltros($periodo, $hoje, $opcoes));
    $check($todos['executivo']['desligamentos'] >= 7 && $todos['executivo']['geradas'] >= 5, '(sem filtro) Considera todos os contratos/entrevistas do período, inclusive os de teste');

    // ---- privacidade do payload -------------------------------------------------------------------------------------------------------
    $json = json_encode([$p, $pU2, $pCg1, $pOutra], JSON_UNESCAPED_UNICODE);
    $tokenHashes = $pdo->query("SELECT token_hash FROM entrevistas_desligamento WHERE metadados_id = $c1")->fetchColumn();
    $check(!str_contains($json, $cpfSecreto) && !str_contains($json, $nomeSecreto) && !str_contains($json, (string)$tokenHashes) && !str_contains($json, 'metadados_id') && !str_contains($json, 'Descrição') && !preg_match('/"(nome_colaborador|cpf|token|token_hash|snap_nome|aberta_[a-z]+|motivo_descricao)"/', $json), '(privacidade) O payload é só agregado: sem nome, CPF, token, metadados_id nem texto livre');
    $chavesPainel = array_keys($p);
    $check($chavesPainel === ['periodo', 'executivo', 'motivos', 'fatores', 'blocos', 'mensal', 'unidades', 'cargos'], '(escopo) Payload sem Área, Gestor ou tipo de desligamento');

    // ---- página ----------------------------------------------------------------------------------------------------------------------------
    $comoUsuario($comPermId, 'viewer');
    $_GET = ['inicio' => '2025-01-01', 'fim' => '2025-06-30', 'unidade' => $chaveU1E1];
    $html = $renderizar(static fn() => (new AdminDashboardEntrevistaDesligamentoController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html), '(página) Renderiza sem Warning/Notice/Fatal');
    foreach (['Dashboard da Entrevista de Desligamento', 'Cobertura da pesquisa', 'Top 5 motivos principais', 'declarados nas entrevistas', 'Fatores contribuintes mais recorrentes', 'Evolução mensal', 'Liderança', 'Cultura e valores', 'Integração e desenvolvimento', 'Resumo por Unidade', 'Resumo por Cargo', 'Permanência média'] as $titulo) {
        $check(str_contains($html, $titulo), "(página) Exibe \"{$titulo}\"");
    }
    $check(substr_count($html, '<svg viewBox="0 0 720 280"') === 5, '(página) Cinco gráficos mensais em SVG próprio (colunas, taxa, eNPS, satisfação, liderança)');
    $check(str_contains($html, 'name="inicio"') && str_contains($html, 'name="fim"') && str_contains($html, 'name="unidade"') && str_contains($html, 'name="cargo"') && str_contains($html, 'value="2025-01-01"') && str_contains($html, '<option value="' . $chaveU1E1 . '" selected'), '(filtros) Período, Unidade e Cargo na página; seleção preservada');
    $check(!str_contains($html, 'name="area"') && !str_contains($html, 'name="gestor"') && !str_contains($html, 'name="tipo"'), '(escopo) Sem filtros de Área, Gestor ou Tipo de desligamento');
    $check(str_contains($html, 'Sem base') && str_contains($html, '2 respostas') && str_contains($html, '66,7%'), '(página) "Sem base" onde não há dados; quantidade de respostas junto do indicador; taxa 66,7%');
    $check(!str_contains($html, $cpfSecreto) && !str_contains($html, '111.444.777-35') && !str_contains($html, $nomeSecreto) && !str_contains($html, (string)$tokenHashes) && !str_contains($html, 'metadados_id') && !str_contains($html, 'Descrição do motivo'), '(privacidade) A página não exibe nome, CPF, token, metadados_id nem comentários');
    $base = rtrim((string)(Config::app()['base_url'] ?? ''), '/');
    preg_match_all('/(?:href|src|action)="([^"]+)"/i', $html, $urls);
    $externas = array_filter($urls[1], static fn(string $u): bool => preg_match('#^https?://#i', $u) === 1 && ($base === '' || !str_starts_with($u, $base . '/')) && !str_contains($u, 'fonts.googleapis.com'));
    $check($externas === [] && !str_contains($html, '<script src="https://'), '(visual) Sem biblioteca externa de gráficos');
    $check(substr_count($html, 'Ver valores em tabela') >= 6 && str_contains($html, '<caption class="sr-only">'), '(acessibilidade) Valores também em tabelas acessíveis');
    $check(str_contains($html, '/admin/dashboard-entrevista-desligamento'), '(menu) Usuário COM a permissão vê o item no menu');
    // Avaliação média da liderança: altura compacta com dados; estado compacto (sem plano cartesiano vazio) sem base.
    $check(str_contains($html, 'max-w-[780px]') && !str_contains($html, 'Sem base no período selecionado'), '(liderança) Com dados no período: gráfico renderizado em contêiner compacto (~300px no desktop), sem o estado vazio');
    $_GET = ['inicio' => '2099-01-01', 'fim' => '2099-12-31', 'unidade' => $chaveU1E1];
    $htmlVazio = $renderizar(static fn() => (new AdminDashboardEntrevistaDesligamentoController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlVazio) && str_contains($htmlVazio, 'Sem base no período selecionado') && str_contains($htmlVazio, 'entrevistas respondidas suficientes para calcular a avaliação média da liderança'), '(liderança) Sem base em todos os meses: estado compacto "Sem base no período selecionado" com texto auxiliar');
    $check(substr_count($htmlVazio, '<svg viewBox="0 0 720 280"') === 4 && str_contains($htmlVazio, 'Avaliação média da liderança (1 a 5)') && str_contains($htmlVazio, 'Liderança (1–5)'), '(liderança) Sem base: não renderiza o gráfico vazio, mantém o título e a tabela acessível "Ver valores em tabela"');
    $_GET = ['inicio' => '2025-01-01', 'fim' => '2025-06-30', 'unidade' => $chaveU1E1];
    $comoUsuario($soResultadosId, 'viewer');
    $htmlSem = $renderizar(static function () use ($service) {
        // Sem a permissão do dashboard o menu não mostra o item (o backend responde 403 antes de renderizar).
        $_GET = [];
        echo Authorization::temPermissao('dashboard_entrevista_desligamento.visualizar') ? 'TEM' : 'NAO-TEM';
    });
    $check($htmlSem === 'NAO-TEM', '(permissão) Usuário só com `resultados` não tem a permissão do dashboard (menu oculto e backend 403)');
    $comoUsuario($adminId, 'admin');
    $_GET = ['unidade' => "x' OR 1=1 --", 'cargo' => "y' OR 1=1 --", 'inicio' => "2025-01-01'; DROP TABLE usuarios; --", 'fim' => 'lixo'];
    $htmlInj = $renderizar(static fn() => (new AdminDashboardEntrevistaDesligamentoController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlInj) && str_contains($htmlInj, 'Dashboard da Entrevista de Desligamento') && (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0, '(segurança) Filtros maliciosos são ignorados: página normal (Admin por bypass), nada executado');
    $_GET = [];

    echo "\nDASHBOARD_ENTREVISTA_DESLIGAMENTO_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM entrevistas_desligamento WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador IN ($ph))")->execute($criados['metadados']);
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $lista = implode(',', array_map('intval', $criados['usuarios']));
        $pdo->exec("DELETE FROM entrevistas_desligamento WHERE gerada_por_usuario_id IN ($lista) OR cancelada_por_usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lista)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
