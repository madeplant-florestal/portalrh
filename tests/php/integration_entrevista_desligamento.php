<?php

/**
 * Integração — Entrevista de Desligamento (repository + service + controllers público/admin + permissões
 * + rotas + menu + seed + migration). Fixtures ZZED-* no espelho `colaboradores_metadados`, com limpeza
 * em `finally`. Prova que:
 *   - elegibilidade em SQL e no service (Falecimento 020, futuro e ativo fora; recontratação = outro contrato);
 *   - uma entrevista por contrato (UNIQUE metadados_id), token só em hash, expiração de 30 dias;
 *   - regenerar invalida o token anterior e renova o prazo; respondida nunca é reaberta; cancelar só pendente;
 *   - token inválido / expirado / cancelado → telas neutras; envio válido grava respostas + fatores (tabela filha);
 *   - segunda submissão bloqueada (UPDATE condicional + rowCount), sem alterar a resposta original;
 *   - snapshot preservado após mudança no METADADOS, com sinalização de divergência;
 *   - permissões visualizar/gerenciar/resultados; Admin só por bypass; seed não concede a ninguém;
 *   - página pública sem CPF/identificadores internos/recursos externos; rate limit de token inexistente.
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
$agora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
$D = static fn(int $dias): string => (new DateTimeImmutable('today'))->modify(($dias >= 0 ? '-' : '+') . abs($dias) . ' days')->format('Y-m-d');
$T = static fn(DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s');
$BR = static fn(string $ymd): string => date('d/m/Y', strtotime($ymd));
$cpfSecreto = '98765432100';
$emp = 'ZE' . $suffix;
$busca = $suffix;
$comoUsuario = static function (int $id, string $role): void {
    $_SESSION['user_id'] = $id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_is_supervisor'] = 0;
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

$mk = static function (string $nome, string $adm, ?string $dem, ?string $motivo, ?string $pessoa = null) use ($pdo, &$criados, $suffix, $emp, $cpfSecreto): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZED_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, 'ZU' . $suffix, 'ZC' . $suffix . $seq, $pessoa ?? ('ZP' . $suffix . $seq), $cpfSecreto, $nome, 'ZZED Empresa ' . $suffix, 'ZZED Unidade',
        'ZZED Cargo Texto', 'ZZEDCG' . $suffix, $adm, $dem, $motivo, $motivo === null ? null : 'ZZED motivo ' . $motivo, $dem === null ? 1 : 0, 'zzed-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};
$estado = static fn(int $metadadosId): ?array => (function () use ($pdo, $metadadosId) {
    $s = $pdo->prepare('SELECT * FROM entrevistas_desligamento WHERE metadados_id = ?');
    $s->execute([$metadadosId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
})();

$postValido = static function (array $extra = []): array {
    $post = ['motivo_principal' => 'lideranca', 'experiencia_geral' => '7', 'enps' => '9', 'fatores' => ['remuneracao', 'clima'],
        'motivo_descricao' => 'Descrição do motivo',
        'aberta_continuar' => 'Continuar', 'aberta_melhorar' => 'Melhorar', 'aberta_mensagem' => 'Obrigado'];
    foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $secao) {
        foreach (array_keys($secao['itens']) as $campo) {
            $post[$campo] = '4';
        }
    }
    return $extra + $post;
};

try {
    // ---- permissões / usuários --------------------------------------------------------------------------------------
    $ids = [];
    foreach (['visualizar' => 620, 'gerenciar' => 630, 'resultados' => 640] as $acao => $ordem) {
        $p = $pdo->prepare('SELECT id, modulo, ordem, ativo FROM permissoes WHERE codigo = ?');
        $p->execute(['entrevista_desligamento.' . $acao]);
        $linha = $p->fetch(PDO::FETCH_ASSOC);
        $check($linha !== false && (int)$linha['ativo'] === 1 && $linha['modulo'] === 'entrevista_desligamento' && (int)$linha['ordem'] === $ordem, "(permissão) entrevista_desligamento.{$acao} no catálogo (ordem {$ordem})");
        $ids[$acao] = (int)($linha['id'] ?? 0);
    }
    $novoUsuario = static function (string $rotulo, string $role, array $permissoes) use (&$criados, $senha, $suffix, $ids): int {
        $id = User::create('ZZED ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzed.' . $suffix . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        if ($permissoes !== []) {
            Authorization::sincronizar($id, array_map(static fn(string $p): int => $ids[$p], $permissoes));
        }
        return $id;
    };
    $adminId = $novoUsuario('Admin', 'admin', []);
    $visId = $novoUsuario('So Visualiza', 'viewer', ['visualizar']);
    $gerId = $novoUsuario('Gerencia', 'viewer', ['visualizar', 'gerenciar']);
    $resId = $novoUsuario('Resultados', 'viewer', ['visualizar', 'resultados']);
    $rhSemId = $novoUsuario('RH Sem Permissao', 'rh', []);

    $tem = static fn(int $u, string $acao): bool => Authorization::usuarioTemPermissao($u, 'entrevista_desligamento.' . $acao);
    $check($tem($adminId, 'visualizar') && $tem($adminId, 'gerenciar') && $tem($adminId, 'resultados'), '(permissão) Admin acessa tudo pelo bypass central');
    $check($tem($visId, 'visualizar') && !$tem($visId, 'gerenciar') && !$tem($visId, 'resultados'), '(permissão) visualizar NÃO dá gerenciar nem resultados (não lê respostas)');
    $check($tem($gerId, 'gerenciar') && !$tem($gerId, 'resultados'), '(permissão) gerenciar NÃO dá resultados');
    $check($tem($resId, 'resultados') && !$tem($resId, 'gerenciar'), '(permissão) resultados NÃO dá gerenciar');
    $check(!$tem($rhSemId, 'visualizar') && !$tem($rhSemId, 'gerenciar') && !$tem($rhSemId, 'resultados'), '(permissão) RH pela role, sem permissão individual, não acessa nada');
    $concedidos = (int)$pdo->query(
        "SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id
         WHERE p.modulo = 'entrevista_desligamento' AND up.usuario_id NOT IN (" . implode(',', array_map('intval', $criados['usuarios'])) . ')'
    )->fetchColumn();
    $check($concedidos === 0, '(permissão) Ninguém além dos usuários de teste recebeu permissão — o seed não concede automaticamente');

    $exigencias = [
        [AdminEntrevistaDesligamentoController::class, 'index', 'entrevista_desligamento.visualizar'],
        [AdminEntrevistaDesligamentoController::class, 'gerar', 'entrevista_desligamento.gerenciar'],
        [AdminEntrevistaDesligamentoController::class, 'regenerar', 'entrevista_desligamento.gerenciar'],
        [AdminEntrevistaDesligamentoController::class, 'cancelar', 'entrevista_desligamento.gerenciar'],
        [AdminEntrevistaDesligamentoController::class, 'resultado', 'entrevista_desligamento.resultados'],
    ];
    foreach ($exigencias as [$classe, $metodo, $perm]) {
        $corpo = $corpoDe($classe, $metodo);
        $ok = str_contains($corpo, "Auth::requireRole(['admin', 'rh', 'viewer'])") && preg_match('/Authorization::requirePermissao\((self::PERM_[A-Z]+|\'' . preg_quote($perm, '/') . '\')\)/', $corpo) === 1;
        $constante = ['entrevista_desligamento.visualizar' => 'PERM_VISUALIZAR', 'entrevista_desligamento.gerenciar' => 'PERM_GERENCIAR', 'entrevista_desligamento.resultados' => 'PERM_RESULTADOS'][$perm];
        $check($ok && str_contains($corpo, 'self::' . $constante), "(backend) {$metodo}() exige sessão + {$perm} (403 real, não só ocultação de botão)");
        if (in_array($metodo, ['gerar', 'regenerar', 'cancelar'], true)) {
            $check(str_contains($corpo, 'Security::csrfCheck'), "(backend) {$metodo}() valida CSRF");
        }
    }
    $fonteIndex = (string)file_get_contents(BASE_PATH . '/index.php');
    foreach ([
        "\$router->get('/entrevista-desligamento/{token}', [EntrevistaDesligamentoController::class, 'show'])",
        "\$router->post('/entrevista-desligamento/{token}', [EntrevistaDesligamentoController::class, 'store'])",
        "\$router->get('/admin/entrevistas-desligamento', [AdminEntrevistaDesligamentoController::class, 'index'])",
        "\$router->post('/admin/entrevistas-desligamento/gerar', [AdminEntrevistaDesligamentoController::class, 'gerar'])",
        "\$router->post('/admin/entrevistas-desligamento/{id}/regenerar', [AdminEntrevistaDesligamentoController::class, 'regenerar'])",
        "\$router->post('/admin/entrevistas-desligamento/{id}/cancelar', [AdminEntrevistaDesligamentoController::class, 'cancelar'])",
        "\$router->get('/admin/entrevistas-desligamento/{id}/resultado', [AdminEntrevistaDesligamentoController::class, 'resultado'])",
    ] as $rota) {
        $check(str_contains($fonteIndex, $rota), '(rota) ' . substr($rota, 9, 70));
    }
    $check(!str_contains(substr($fonteIndex, 0, (int)strpos($fonteIndex, "'/admin/entrevistas-desligamento'")), "'/entrevista-desligamento/{token}', [Admin"), '(rota) A rota pública não é administrativa (não passa pelo bloqueio /admin) e as administrativas ficam sob /admin (login global)');
    $regraNavEntrevistas = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/entrevistas-desligamento') { $regraNavEntrevistas = $it['regra']; }
        }
    }
    $check($regraNavEntrevistas === 'perm:entrevista_desligamento.visualizar', '(menu) A Central só oferece Entrevistas de Desligamento sob a regra perm:entrevista_desligamento.visualizar (PortalNavegacaoService::definicao() — sidebar removida, fonte da verdade agora é o serviço de navegação)');

    // ---- fixtures ---------------------------------------------------------------------------------------------------------
    $adm1 = $D(1000);
    $dem1 = $D(9);
    $c1 = $mk("ZZED Fulano da Silva {$suffix}", $adm1, $dem1, '003');   // elegível (pedido)
    $c2 = $mk("ZZED Falecido {$suffix}", $D(2000), $D(9), '020');           // falecimento
    $c3 = $mk("ZZED Futuro {$suffix}", $D(2000), $D(-30), '002');             // desligamento futuro
    $c4 = $mk("ZZED Ativo {$suffix}", $D(2000), null, null);                       // ativo
    $c5 = $mk("ZZED Justa Causa {$suffix}", $D(2000), $D(14), '001');        // elegível (justa causa)
    $dem6 = $D(49);
    $c6 = $mk("ZZED Acordo {$suffix}", $D(2000), $dem6, '016');             // elegível (acordo)
    $c7 = $mk("ZZED Termino {$suffix}", $D(200), $D(18), '005');            // elegível (término)
    $dem8 = $D(3600);
    $c8 = $mk("ZZED Recontratado {$suffix}", $D(4000), $dem8, '003', 'ZPREC' . $suffix); // 1º contrato
    $dem9 = $D(11);
    $c9 = $mk("ZZED Recontratado {$suffix}", $D(800), $dem9, '002', 'ZPREC' . $suffix); // 2º contrato, MESMA pessoa

    $repo = new EntrevistaDesligamentoRepository();
    $service = new EntrevistaDesligamentoService($repo);
    $criterios = EntrevistaDesligamentoService::criteriosElegibilidade($agora);

    // ---- elegíveis (SQL) ---------------------------------------------------------------------------------------------------
    $elegiveis = $repo->listarElegiveis($criterios, ['busca' => $busca], 50);
    $idsElegiveis = array_map(static fn(array $r): int => (int)$r['metadados_id'], $elegiveis);
    sort($idsElegiveis);
    $check($idsElegiveis === [$c1, $c5, $c6, $c7, $c8, $c9], '(elegíveis) Só desligamentos efetivados sem Falecimento: exclui 020, futuro e ativo; inclui justa causa, acordo e término');
    $check($repo->contarElegiveis($criterios, ['busca' => $busca]) === 6, '(elegíveis) A contagem confere com a lista');
    $check(array_keys($elegiveis[0]) === ['metadados_id', 'nome', 'codigo_empresa', 'empresa', 'codigo_unidade', 'unidade', 'codigo_cargo', 'cargo', 'admissao', 'demissao', 'motivo_rescisao_codigo', 'motivo_rescisao_descricao'], '(elegíveis) Só colunas oficiais necessárias — sem CPF, nascimento ou salário');
    $check($elegiveis[0]['cargo'] === 'ZZED Cargo Texto', '(cargo) Sem catálogo para o código, o texto oficial do espelho é usado');
    $primeiroPorData = array_map(static fn(array $r): string => (string)$r['demissao'], $elegiveis);
    $ordenado = $primeiroPorData;
    rsort($ordenado);
    $check($primeiroPorData === $ordenado, '(elegíveis) Ordenados do desligamento mais recente para o mais antigo');
    $check(count($repo->listarElegiveis($criterios, ['busca' => $busca, 'dias' => 30], 50)) === 4, '(elegíveis) Filtro "últimos 30 dias": c1, c5, c7 e c9');
    $check(count($repo->listarElegiveis($criterios, ['busca' => $busca, 'codigo_empresa' => 'NAO-EXISTE'], 50)) === 0 && count($repo->listarElegiveis($criterios, ['busca' => $busca, 'codigo_empresa' => $emp], 50)) === 6, '(elegíveis) Filtro por código de empresa');
    $check(count($repo->listarElegiveis($criterios, ['busca' => "100%_' OR 1=1 --"], 50)) === 0, '(segurança) Busca com %, _ e aspas não vira curinga nem injeção');
    $check(count($repo->listarElegiveis(['hoje' => '2000-01-01', 'motivo_excluido' => '020'], ['busca' => $busca], 50)) === 0, '(elegíveis) A data de referência é parâmetro: em 2000 nada estaria desligado ainda');

    // ---- gerar --------------------------------------------------------------------------------------------------------------
    $g1 = $service->gerar($c1, $gerId, $agora);
    $check($g1['ok'] === true && preg_match('/^[0-9a-f]{64}$/', (string)$g1['token']) === 1, '(gerar) Contrato elegível gera link com token hex de 256 bits');
    $linha1 = $estado($c1);
    $check($linha1 !== null && $linha1['token_hash'] === hash('sha256', (string)$g1['token']) && (int)$linha1['metadados_id'] === $c1, '(token) Persistido só o SHA-256; vínculo por metadados_id');
    $tokenEmAlgumCampo = false;
    foreach ($linha1 as $valor) {
        if ($valor !== null && str_contains((string)$valor, (string)$g1['token'])) {
            $tokenEmAlgumCampo = true;
        }
    }
    $check(!$tokenEmAlgumCampo, '(token) O token bruto não aparece em nenhuma coluna da linha');
    $check($linha1['expira_em'] === $T($agora->modify('+30 days')) && $linha1['gerada_em'] === $T($agora) && (int)$linha1['gerada_por_usuario_id'] === $gerId && (int)$linha1['regeneracoes'] === 0, '(prazo) Expira exatamente 30 dias após a geração');
    $check($linha1['snap_nome'] === "ZZED Fulano da Silva {$suffix}" && $linha1['snap_demissao'] === $dem1 && $linha1['snap_admissao'] === $adm1 && $linha1['snap_motivo_codigo'] === '003' && $linha1['snap_motivo_descricao'] === 'ZZED motivo 003' && $linha1['snap_codigo_empresa'] === $emp && $linha1['snap_codigo_unidade'] === 'ZU' . $suffix && $linha1['snap_cargo'] === 'ZZED Cargo Texto' && $linha1['snap_codigo_cargo'] === 'ZZEDCG' . $suffix && $linha1['snap_empresa'] === 'ZZED Empresa ' . $suffix && $linha1['snap_unidade'] === 'ZZED Unidade', '(snapshot) Nome, empresa, unidade, cargo, admissão, demissão e motivo oficial gravados na geração');
    $colunas = array_column($pdo->query('SHOW COLUMNS FROM entrevistas_desligamento')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $check(!array_intersect(['cpf', 'nascimento', 'salario_atual', 'codigo_pessoa', 'numero_contrato'], $colunas), '(privacidade) A tabela não guarda CPF, nascimento, salário, codigo_pessoa nem número do contrato');
    $check(EntrevistaDesligamentoService::situacao($linha1, $agora) === 'pendente', '(situação) Recém-gerada = pendente');

    $dup = $service->gerar($c1, $gerId, $agora);
    $check($dup['ok'] === false && str_contains((string)$dup['error'], 'Já existe') && (int)$pdo->query("SELECT COUNT(*) FROM entrevistas_desligamento WHERE metadados_id = {$c1}")->fetchColumn() === 1, '(unicidade) Segunda geração para o mesmo contrato é recusada — uma entrevista por contrato');
    try {
        $repo->inserir($c1, hash('sha256', 'outro'), '2026-09-19 10:00:00', '2026-10-19 10:00:00', $gerId, ['snap_nome' => 'x', 'snap_codigo_empresa' => 'x', 'snap_codigo_unidade' => 'x', 'snap_demissao' => '2026-09-10']);
        $check(false, '(unicidade) O banco deve barrar a segunda entrevista do mesmo contrato');
    } catch (PDOException $e) {
        $check((int)($e->errorInfo[1] ?? 0) === 1062, '(unicidade) UNIQUE (metadados_id) barra a duplicidade no próprio banco');
    }
    try {
        $repo->inserir($c5, $linha1['token_hash'], '2026-09-19 10:00:00', '2026-10-19 10:00:00', $gerId, ['snap_nome' => 'x', 'snap_codigo_empresa' => 'x', 'snap_codigo_unidade' => 'x', 'snap_demissao' => '2026-09-10']);
        $check(false, '(token) O banco deve barrar token_hash repetido');
    } catch (PDOException $e) {
        $check((int)($e->errorInfo[1] ?? 0) === 1062, '(token) UNIQUE (token_hash) barra hash repetido');
    }
    try {
        $repo->inserir(2147483000, hash('sha256', 'fk'), '2026-09-19 10:00:00', '2026-10-19 10:00:00', $gerId, ['snap_nome' => 'x', 'snap_codigo_empresa' => 'x', 'snap_codigo_unidade' => 'x', 'snap_demissao' => '2026-09-10']);
        $check(false, '(vínculo) FK exige contrato existente no espelho');
    } catch (PDOException $e) {
        $check(true, '(vínculo) FK para colaboradores_metadados(id) barra contrato inexistente');
    }

    $gFalecido = $service->gerar($c2, $gerId, $agora);
    $gFuturo = $service->gerar($c3, $gerId, $agora);
    $gAtivo = $service->gerar($c4, $gerId, $agora);
    $gNada = $service->gerar(2147483000, $gerId, $agora);
    $check($gFalecido['ok'] === false && str_contains((string)$gFalecido['error'], 'Falecimento') && $estado($c2) === null, '(gerar) Falecimento (020) não gera entrevista');
    $check($gFuturo['ok'] === false && str_contains((string)$gFuturo['error'], 'futura') && $estado($c3) === null, '(gerar) Desligamento futuro não gera entrevista');
    $check($gAtivo['ok'] === false && $estado($c4) === null && $gNada['ok'] === false, '(gerar) Contrato ativo ou inexistente não gera entrevista');
    $g8 = $service->gerar($c8, $gerId, $agora);
    $g9 = $service->gerar($c9, $gerId, $agora);
    $check($g8['ok'] === true && $g9['ok'] === true && $g8['token'] !== $g9['token'], '(contrato) Recontratação: o 1º e o 2º contrato da mesma pessoa têm entrevistas próprias (codigo_pessoa não agrupa)');
    $check(count($repo->listarElegiveis($criterios, ['busca' => $busca], 50)) === 3, '(elegíveis) Quem já tem entrevista sai da lista de elegíveis');

    // ---- página pública: estados ---------------------------------------------------------------------------------------------
    $r = $service->resolverParaPagina((string)$g1['token'], $agora);
    $check($r['estado'] === 'formulario' && array_keys($r['contexto']) === ['id', 'primeiro_nome', 'cargo', 'admissao', 'demissao', 'tempo_empresa'] && $r['contexto']['primeiro_nome'] === 'Zzed' && $r['contexto']['tempo_empresa'] === EntrevistaDesligamentoService::tempoDeEmpresa($adm1, $dem1), '(público) Contexto mínimo do snapshot: primeiro nome, cargo, datas e tempo de empresa calculado');
    foreach (['', 'abc', str_repeat('a', 64), str_repeat('A', 64), str_repeat('g', 64), strtoupper((string)$g1['token']), (string)$g1['token'] . 'x', substr((string)$g1['token'], 1)] as $ruim) {
        $check($service->resolverParaPagina($ruim, $agora)['estado'] === 'invalido', '(público) Token inválido → "invalido": ' . substr($ruim, 0, 12) . '…');
    }
    $check($service->resolverParaPagina((string)$g1['token'], $agora->modify('+30 days -1 second'))['estado'] === 'formulario' && $service->resolverParaPagina((string)$g1['token'], $agora->modify('+30 days'))['estado'] === 'indisponivel', '(prazo) Vale até o segundo anterior aos 30 dias; no instante da expiração fica indisponível');

    // HTML da página pública (formulário)
    $_SESSION['csrf_token'] = bin2hex(random_bytes(8));
    EntrevistaDesligamentoController::limparLimite();
    $_POST = [];
    $html = $renderizar(static fn() => (new EntrevistaDesligamentoController())->show((string)$g1['token']));
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html), '(página) Renderiza sem Warning/Notice/Fatal');
    $check(str_contains($html, 'Olá, Zzed') && !str_contains($html, 'Fulano') && !str_contains($html, "ZZED Fulano da Silva {$suffix}"), '(privacidade) Mostra só o primeiro nome — nunca o nome completo');
    $check(!str_contains($html, $cpfSecreto) && !str_contains($html, '987.654.321-00') && !str_contains($html, 'metadados_id') && !str_contains($html, 'numero_contrato') && !str_contains($html, 'ZC' . $suffix) && !str_contains($html, $emp) && !str_contains($html, 'ZZEDCG' . $suffix) && !str_contains($html, (string)$linha1['token_hash']), '(privacidade) Sem CPF, códigos internos, número de contrato, hash nem metadados_id na página');
    $check(str_contains($html, 'ZZED Cargo Texto') && str_contains($html, $BR($adm1)) && str_contains($html, $BR($dem1)) && str_contains($html, (string)EntrevistaDesligamentoService::tempoDeEmpresa($adm1, $dem1)), '(página) Cargo, admissão, desligamento e tempo de empresa do snapshot');
    $check(!str_contains($html, 'Gestor') && !str_contains($html, 'Área') && !preg_match('/volunt[aá]ri|involunt[aá]ri/iu', $html), '(V1) Sem Gestor Imediato, sem Área e sem Voluntário/Involuntário');
    $check(str_contains($html, '<meta name="referrer" content="no-referrer">') && str_contains($html, 'noindex'), '(segurança) <meta referrer no-referrer> e noindex');
    $base = rtrim((string)(Config::app()['base_url'] ?? ''), '/');
    preg_match_all('/(?:href|src|action)="([^"]+)"/i', $html, $urls);
    $externas = array_filter($urls[1], static fn(string $u): bool => preg_match('#^https?://#i', $u) === 1 && ($base === '' || !str_starts_with($u, $base . '/')));
    $check($externas === [] && !str_contains($html, '<script') && !preg_match('/\son[a-z]+\s*=/i', $html) && !preg_match('/googleapis|gstatic|cdn\./i', $html), '(segurança) Nenhum recurso de terceiro (só assets locais), sem <script> nem handlers inline');
    $check(substr_count($html, 'name="csrf"') === 1 && str_contains($html, 'name="motivo_principal"') && substr_count($html, 'type="radio"') === (14 + 21 * 5 + 2 * 11) && substr_count($html, 'name="fatores[]"') === 11, '(página) CSRF; 14 motivos; 21 itens 1–5; 2 escalas 0–10; 11 fatores');
    $check(substr_count($html, 'Muito insatisfeito') === 1 && substr_count($html, EntrevistaDesligamentoService::LEGENDA_ESCALA_NEUTRA) === 3, '(escala) Legenda de satisfação só em Experiência; as outras três seções trazem a legenda neutra');
    $check(!str_contains($html, 'cultura_pratica_valores') && substr_count($html, '<textarea') === 4 && substr_count($html, EntrevistaDesligamentoService::PERGUNTA_CULTURA_PRATICA) === 1, '(cultura) A frase de valores é só contexto (sem campo próprio): 4 textareas = Descreva brevemente + 3 abertas');
    $check(!in_array('cultura_pratica_valores', array_column($pdo->query('SHOW COLUMNS FROM entrevistas_desligamento')->fetchAll(PDO::FETCH_ASSOC), 'Field'), true), '(cultura) Nenhuma coluna de resposta textual para a pergunta de valores');
    $check(str_contains($html, 'Descreva brevemente') && str_contains($html, 'O que a empresa faz muito bem') && str_contains($html, 'Na sua percepção, a empresa pratica seus valores') && str_contains($html, 'Em uma escala de 0 a 10, o quanto você recomendaria a Madeplant'), '(página) Perguntas abertas, pergunta contextual de valores e eNPS');

    // erro de validação: valores preservados
    $invalido = $service->registrarResposta((string)$g1['token'], $postValido(['enps' => '11', 'motivo_principal' => 'equipe', 'aberta_mensagem' => 'texto <b>preservado</b>', 'fatores' => ['clima']]), $agora);
    $check($invalido['ok'] === false && $invalido['estado'] === 'formulario' && $invalido['erros'] !== [] && $estado($c1)['respondida_em'] === null && $estado($c1)['enps'] === null, '(validação) Resposta inválida não grava nada e volta ao formulário');
    $_SESSION['csrf_token'] = 'x';
    $htmlErro = $renderizar(static fn() => (new View())->render('entrevista_desligamento/publica', ['estado' => 'formulario', 'contexto' => $r['contexto'], 'token' => (string)$g1['token'], 'erros' => $invalido['erros'], 'valores' => $invalido['valores'], 'csrf' => 'x'], 'layouts/publico-seguro'));
    $check(str_contains($htmlErro, 'role="alert"') && str_contains($htmlErro, 'nota de recomendação')
        && (bool)preg_match('/name="motivo_principal" value="equipe" required[^>]*checked/', $htmlErro)
        && (bool)preg_match('/name="fatores\[\]" value="clima"[^>]*checked/', $htmlErro)
        && str_contains($htmlErro, 'texto preservado') && (bool)preg_match('/name="lid_respeito" value="4" required[^>]*checked/', $htmlErro), '(UX) Erros listados e valores já preenchidos preservados (motivo, fatores, notas e textos)');
    $check(!str_contains($htmlErro, '<b>') && !str_contains($htmlErro, '<script'), '(XSS) Texto preservado sai escapado/sem tags');

    // ---- resposta válida ---------------------------------------------------------------------------------------------------------
    $ok = $service->registrarResposta((string)$g1['token'], $postValido(['fatores' => ['remuneracao', 'clima', 'clima']]), $agora->modify('+2 days'));
    $check($ok['ok'] === true && $ok['estado'] === 'concluida', '(resposta) Envio válido conclui a entrevista');
    $resp = $estado($c1);
    $check($resp['respondida_em'] === $T($agora->modify('+2 days')) && $resp['motivo_principal'] === 'lideranca' && (int)$resp['enps'] === 9 && (int)$resp['experiencia_geral'] === 7 && (int)$resp['exp_clima'] === 4 && (int)$resp['int_expectativa'] === 4 && $resp['motivo_descricao'] === 'Descrição do motivo' && $resp['aberta_mensagem'] === 'Obrigado', '(resposta) Notas, motivo declarado, textos e data gravados');
    $check($resp['snap_motivo_codigo'] === '003' && $resp['snap_motivo_descricao'] === 'ZZED motivo 003' && $resp['motivo_principal'] !== $resp['snap_motivo_codigo'], '(motivo) Motivo declarado e motivo OFICIAL (snapshot) são independentes — um não substitui o outro');
    $check($repo->fatoresDaEntrevista((int)$resp['id']) === ['clima', 'remuneracao'], '(fatores) Seleção múltipla em tabela filha, sem duplicidade');
    $check(!in_array('enps_classificacao', array_column($pdo->query('SHOW COLUMNS FROM entrevistas_desligamento')->fetchAll(PDO::FETCH_ASSOC), 'Field'), true), '(eNPS) Classificação não é coluna: é calculada pelo sistema');

    // segunda submissão / reabertura
    $segunda = $service->registrarResposta((string)$g1['token'], $postValido(['enps' => '0', 'motivo_principal' => 'outro', 'fatores' => ['equipe']]), $agora->modify('+3 days'));
    $depois = $estado($c1);
    $check($segunda['ok'] === false && $segunda['estado'] === 'concluida' && (int)$depois['enps'] === 9 && $depois['motivo_principal'] === 'lideranca' && $depois['respondida_em'] === $T($agora->modify('+2 days')) && $repo->fatoresDaEntrevista((int)$depois['id']) === ['clima', 'remuneracao'], '(reenvio) Segunda submissão bloqueada; respostas, data e fatores originais intactos');
    $check($service->resolverParaPagina((string)$g1['token'], $agora->modify('+3 days'))['estado'] === 'concluida', '(reenvio) Acessar de novo o link mostra "concluída"');
    $check($service->resolverParaPagina((string)$g1['token'], $agora->modify('+90 days'))['estado'] === 'concluida', '(reenvio) Mesmo após os 30 dias, respondida continua "concluída" (não vira "expirada")');
    $htmlConcluida = $renderizar(static fn() => (new EntrevistaDesligamentoController())->show((string)$g1['token']));
    $check(str_contains($htmlConcluida, 'Entrevista concluída') && !str_contains($htmlConcluida, '<form') && !str_contains($htmlConcluida, 'Zzed') && !str_contains($htmlConcluida, 'ZZED'), '(reenvio) Tela neutra: sem formulário e sem dado pessoal');
    $reabrir = $service->regenerar((int)$resp['id'], $gerId, $agora->modify('+4 days'));
    $depoisRegen = $estado($c1);
    $check($reabrir['ok'] === false && $depoisRegen['token_hash'] === $linha1['token_hash'] && $depoisRegen['respondida_em'] === $T($agora->modify('+2 days')) && (int)$depoisRegen['regeneracoes'] === 0, '(regenerar) Respondida NÃO é reaberta por regeneração; token e resposta intactos');
    $check($service->cancelar((int)$resp['id'], $gerId, $agora->modify('+4 days'))['ok'] === false && $estado($c1)['cancelada_em'] === null, '(cancelar) Respondida não pode ser cancelada');
    $check($repo->responder((int)$resp['id'], (string)$resp['token_hash'], $T($agora->modify('+5 days')), ['enps' => 1, 'motivo_principal' => 'outro'], ['equipe']) === false && (int)$estado($c1)['enps'] === 9 && $repo->fatoresDaEntrevista((int)$resp['id']) === ['clima', 'remuneracao'], '(concorrência) responder() em entrevista já concluída devolve false (rowCount 0), faz rollback e não duplica fatores');

    // concorrência: duas submissões com o mesmo token — só uma conclui
    $g5 = $service->gerar($c5, $gerId, $agora);
    $tokenHash5 = hash('sha256', (string)$g5['token']);
    $idE5 = (int)$estado($c5)['id'];
    $a = $repo->responder($idE5, $tokenHash5, $T($agora->modify('+5 minutes')), ['enps' => 10, 'motivo_principal' => 'remuneracao'], ['equipe']);
    $b = $repo->responder($idE5, $tokenHash5, $T($agora->modify('+6 minutes')), ['enps' => 2, 'motivo_principal' => 'outro'], ['jornada', 'clima']);
    $e5 = $estado($c5);
    $check($a === true && $b === false && (int)$e5['enps'] === 10 && $e5['respondida_em'] === $T($agora->modify('+5 minutes')) && $repo->fatoresDaEntrevista($idE5) === ['equipe'], '(concorrência) Duas submissões: somente a primeira conclui; a segunda é rejeitada sem gravar nada');

    // ---- cancelar / expirar / regenerar -----------------------------------------------------------------------------------------
    $g6 = $service->gerar($c6, $gerId, $agora);
    $idE6 = (int)$estado($c6)['id'];
    $check($service->resolverParaPagina((string)$g6['token'], $agora)['estado'] === 'formulario', '(estado) c6 pendente');
    $cancelou = $service->cancelar($idE6, $gerId, $agora->modify('+1 day'));
    $e6 = $estado($c6);
    $check($cancelou['ok'] === true && $e6['cancelada_em'] === $T($agora->modify('+1 day')) && (int)$e6['cancelada_por_usuario_id'] === $gerId && EntrevistaDesligamentoService::situacao($e6, $agora->modify('+1 day')) === 'cancelada', '(cancelar) Pendente pode ser cancelada (registra quem/quando)');
    $check($service->resolverParaPagina((string)$g6['token'], $agora->modify('+1 day'))['estado'] === 'indisponivel', '(cancelar) Link cancelado → tela neutra "indisponível"');
    $r6 = $service->registrarResposta((string)$g6['token'], $postValido(), $agora->modify('+1 day'));
    $check($r6['ok'] === false && $r6['estado'] === 'indisponivel' && $estado($c6)['respondida_em'] === null, '(cancelar) Não é possível responder um link cancelado');
    $check($service->cancelar($idE6, $gerId, $agora->modify('+1 day'))['ok'] === false, '(cancelar) Cancelar duas vezes é recusado');

    $reg6 = $service->regenerar($idE6, $resId, $agora->modify('+2 days'));
    $e6b = $estado($c6);
    $check($reg6['ok'] === true && $reg6['token'] !== $g6['token'] && $e6b['cancelada_em'] === null && (int)$e6b['regeneracoes'] === 1 && $e6b['expira_em'] === $T($agora->modify('+2 days')->modify('+30 days')) && (int)$e6b['gerada_por_usuario_id'] === $resId, '(regenerar) Cancelada pode ser regenerada: novo token, prazo renovado (30 dias), contador e autoria');
    $check($e6b['snap_demissao'] === $dem6 && $e6b['snap_nome'] === "ZZED Acordo {$suffix}", '(regenerar) O snapshot original é mantido');
    $check($service->resolverParaPagina((string)$g6['token'], $agora->modify('+2 days'))['estado'] === 'invalido' && $service->resolverParaPagina((string)$reg6['token'], $agora->modify('+2 days'))['estado'] === 'formulario', '(regenerar) O token anterior é invalidado imediatamente; o novo funciona');
    $check($estado($c6)['token_hash'] === hash('sha256', (string)$reg6['token']), '(regenerar) Persistido só o novo hash');

    $reg6b = $service->regenerar($idE6, $gerId, $agora->modify('+3 days'));
    $check($reg6b['ok'] === true && $service->resolverParaPagina((string)$reg6['token'], $agora->modify('+3 days'))['estado'] === 'invalido' && (int)$estado($c6)['regeneracoes'] === 2, '(regenerar) Pendente também pode ser regenerada (link perdido): o anterior deixa de valer');

    // expirada → regenerar
    $g7 = $service->gerar($c7, $gerId, $agora);
    $idE7 = (int)$estado($c7)['id'];
    $depoisDeExpirar = $agora->modify('+45 days');
    $check($service->resolverParaPagina((string)$g7['token'], $depoisDeExpirar)['estado'] === 'indisponivel', '(expirar) Após 30 dias o link fica indisponível');
    $r7 = $service->registrarResposta((string)$g7['token'], $postValido(), $depoisDeExpirar);
    $check($r7['ok'] === false && $r7['estado'] === 'indisponivel' && $estado($c7)['respondida_em'] === null, '(expirar) Não é possível responder após a expiração');
    $check($service->cancelar($idE7, $gerId, $depoisDeExpirar)['ok'] === false, '(expirar) Expirada não é "cancelável" — só regenerável');
    $reg7 = $service->regenerar($idE7, $gerId, $depoisDeExpirar);
    $check($reg7['ok'] === true && $reg7['expira_em'] === $T($depoisDeExpirar->modify('+30 days')) && $service->resolverParaPagina((string)$reg7['token'], $depoisDeExpirar)['estado'] === 'formulario', '(regenerar) Expirada pode ser regenerada com novo prazo de 30 dias');

    // ---- snapshot × METADADOS ------------------------------------------------------------------------------------------------------
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = ?, motivo_rescisao_codigo = ? WHERE id = ?')->execute([$D(8), '002', $c8]);
    $painelPend = $service->montarPainel(['visao' => 'pendente', 'busca' => $busca], $agora);
    $itensPend = [];
    foreach ($painelPend['itens'] as $item) {
        $itensPend[(int)$item['metadados_id']] = $item;
    }
    $check(count($itensPend[$c8]['divergencias']) === 2 && $itensPend[$c8]['snap_demissao'] === $dem8 && $itensPend[$c8]['snap_motivo_codigo'] === '003', '(snapshot) METADADOS alterou demissão e motivo: o snapshot foi preservado e a divergência sinalizada (2 avisos)');
    $check($itensPend[$c9]['divergencias'] === [], '(snapshot) Sem alteração: sem divergência');
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = NULL, ativo = 1 WHERE id = ?')->execute([$c9]);
    $itensPend2 = [];
    foreach ($service->montarPainel(['visao' => 'pendente', 'busca' => $busca], $agora)['itens'] as $item) {
        $itensPend2[(int)$item['metadados_id']] = $item;
    }
    $check(isset($itensPend2[$c9]) && $itensPend2[$c9]['snap_demissao'] === $dem9 && str_contains($itensPend2[$c9]['divergencias'][0], 'não consta mais') && $estado($c9) !== null, '(snapshot) Demissão que sumiu da origem: a entrevista NÃO é apagada e é sinalizada');
    $regInel = $service->regenerar((int)$estado($c9)['id'], $gerId, $agora);
    $check($regInel['ok'] === false && str_contains((string)$regInel['error'], 'Não é possível regenerar') && $estado($c9)['token_hash'] === hash('sha256', (string)$g9['token']), '(regenerar) Contrato que deixou de ser elegível não recebe novo link');
    $pdo->prepare('UPDATE colaboradores_metadados SET motivo_rescisao_codigo = ? WHERE id = ?')->execute(['020', $c8]);
    $check($service->regenerar((int)$estado($c8)['id'], $gerId, $agora)['ok'] === false, '(regenerar) Motivo oficial passou a Falecimento: não regenera');

    // ---- painel / indicadores ----------------------------------------------------------------------------------------------------------
    $painel = $service->montarPainel(['visao' => 'elegiveis', 'busca' => $busca, 'dias' => 0], $agora);
    $check($painel['visao'] === 'elegiveis' && $painel['itens'] === [] && $painel['total_visao'] === 0, '(painel) Todos os elegíveis de teste já têm entrevista: lista de elegíveis vazia');
    $painelRes = $service->montarPainel(['visao' => 'respondida', 'busca' => $busca], $agora);
    $checkRes = array_map(static fn(array $i): int => (int)$i['metadados_id'], $painelRes['itens']);
    sort($checkRes);
    $check($checkRes === [$c1, $c5] && !array_key_exists('enps', $painelRes['itens'][0]) && !array_key_exists('motivo_principal', $painelRes['itens'][0]) && !array_key_exists('aberta_mensagem', $painelRes['itens'][0]), '(painel) Respondidas listadas SEM nenhum campo de resposta (situação operacional apenas)');
    $check($service->montarPainel(['visao' => 'invalida'], $agora)['visao'] === 'elegiveis', '(painel) Visão desconhecida cai em "elegíveis"');
    $ind = $service->indicadores($agora, false);
    $check($ind['enps'] === null && $ind['enps_distribuicao'] === null && $ind['geradas'] >= 6, '(indicadores) Sem a permissão de resultados: nenhum dado derivado das respostas');
    $indRes = $service->indicadores($agora, true);
    $dist = $repo->distribuicaoEnps();
    $esperado = EntrevistaDesligamentoService::calcularEnps($dist);
    $check($indRes['enps'] === $esperado && $indRes['enps_distribuicao'] === $dist && $dist['promotores'] >= 2, '(indicadores) eNPS consolidado das respondidas (c1 = 9, c5 = 10 → promotores)');
    $check($indRes['taxa_resposta'] === round(($indRes['respondidas'] / $indRes['geradas']) * 100, 1) && $indRes['pendentes'] + $indRes['respondidas'] + $indRes['expiradas'] + $indRes['canceladas'] === $indRes['geradas'], '(indicadores) Taxa de resposta = respondidas ÷ geradas; situações somam o total');

    // ---- resultado individual --------------------------------------------------------------------------------------------------------------
    $ri = $service->resultadoIndividual((int)$estado($c1)['id'], $agora);
    $check($ri['situacao'] === 'respondida' && $ri['enps_classificacao'] === 'Promotor' && $ri['fatores'] === ['clima', 'remuneracao'] && $ri['tempo_empresa'] === EntrevistaDesligamentoService::tempoDeEmpresa($adm1, $dem1), '(resultado) Situação, eNPS classificado pelo sistema (9 = Promotor), fatores e tempo de empresa');
    $comoUsuario($resId, 'viewer');
    $htmlRes = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->resultado((string)$estado($c1)['id']));
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlRes) && str_contains($htmlRes, "ZZED Fulano da Silva {$suffix}") && str_contains($htmlRes, 'Motivo OFICIAL do desligamento') && str_contains($htmlRes, '003 — ZZED motivo 003') && str_contains($htmlRes, 'Motivo declarado pelo ex-colaborador') && str_contains($htmlRes, 'Relacionamento com liderança'), '(resultado) Mostra snapshot, motivo oficial e motivo declarado — separados');
    $check(substr_count($htmlRes, '— Satisfeito') === 7 && substr_count($htmlRes, '/ 5') === 14, '(escala) No resultado, o rótulo de satisfação só aparece em Experiência (7 itens); as demais seções mostram N / 5');
    $check(str_contains($htmlRes, 'Promotor') && str_contains($htmlRes, 'Descrição do motivo') && str_contains($htmlRes, 'Obrigado') && str_contains($htmlRes, 'Clima organizacional') && !str_contains($htmlRes, $cpfSecreto) && !str_contains($htmlRes, '987.654.321-00'), '(resultado) eNPS + classificação, fatores, textos abertos; sem CPF');
    $comoUsuario($resId, 'viewer');
    $htmlAusente = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->resultado('2147483000'));
    $check(str_contains($htmlAusente, 'não encontrada'), '(resultado) Entrevista inexistente → 404 neutro');

    // ---- administração (HTML) ---------------------------------------------------------------------------------------------------------------------
    $comoUsuario($visId, 'viewer');
    $_GET = ['visao' => 'pendente', 'busca' => $busca];
    $htmlVis = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlVis) && str_contains($htmlVis, 'Entrevistas de Desligamento') && str_contains($htmlVis, 'Pendente'), '(admin) Renderiza sem Warning/Notice/Fatal');
    $check(!str_contains($htmlVis, 'Regenerar link') && !str_contains($htmlVis, 'Confirmar cancelamento') && !str_contains($htmlVis, '/resultado') && !str_contains($htmlVis, 'eNPS'), '(admin) Só visualizar: sem botões de gerenciar, sem link de resultado e sem eNPS');
    $check(str_contains($htmlVis, 'Divergência com o METADADOS'), '(admin) Divergências com o METADADOS são sinalizadas na lista');
    $check(!preg_match('/\son[a-z]+\s*=/i', $htmlVis) && !str_contains($htmlVis, $cpfSecreto), '(admin) Sem handlers inline (CSP) e sem CPF');
    $_GET = ['visao' => 'respondida', 'busca' => $busca];
    $htmlVisResp = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(!str_contains($htmlVisResp, 'Ver resultado') && !str_contains($htmlVisResp, 'Descrição do motivo') && !str_contains($htmlVisResp, 'Obrigado'), '(admin) Respondidas sem a permissão de resultados: nem link nem conteúdo das respostas');

    $comoUsuario($resId, 'viewer');
    $htmlResLista = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(str_contains($htmlResLista, 'Ver resultado') && str_contains($htmlResLista, 'eNPS') && !str_contains($htmlResLista, 'Regenerar link') && !str_contains($htmlResLista, 'Descrição do motivo'), '(admin) Com resultados: link de resultado e eNPS; sem gerenciar e sem expor as respostas na lista');

    $comoUsuario($gerId, 'viewer');
    $_GET = ['visao' => 'pendente', 'busca' => $busca];
    $htmlGer = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(str_contains($htmlGer, 'Regenerar link') && str_contains($htmlGer, 'Confirmar cancelamento') && str_contains($htmlGer, 'name="csrf"') && !str_contains($htmlGer, 'eNPS') && !str_contains($htmlGer, 'Ver resultado'), '(admin) Gerenciar: regenerar e cancelar (com CSRF); ainda sem eNPS nem resultado');
    $check(!preg_match('#/entrevista-desligamento/[0-9a-f]{64}#', $htmlGer) && !str_contains($htmlGer, (string)$reg6b['token']) && !str_contains($htmlGer, (string)$reg7['token']), '(link) Nenhum link/token aparece numa listagem — só na resposta imediata da geração');

    // gerar via controller: link exibido uma única vez
    $cNovo = $mk("ZZED Novo Gerado {$suffix}", $D(600), $D(4), '003');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(8));
    $_POST = ['csrf' => $_SESSION['csrf_token'], 'metadados_id' => (string)$cNovo];
    $htmlGerou = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->gerar());
    $achou = preg_match('#/entrevista-desligamento/([0-9a-f]{64})#', $htmlGerou, $m);
    $check($achou === 1 && str_contains($htmlGerou, 'copie agora') && substr_count($htmlGerou, $m[1] ?? 'x') === 1 && str_contains($htmlGerou, "ZZED Novo Gerado {$suffix}"), '(link) O POST de gerar mostra o link completo UMA vez, com aviso, e o nome do ex-colaborador');
    $linhaNova = $estado($cNovo);
    $check($linhaNova !== null && $linhaNova['token_hash'] === hash('sha256', $m[1] ?? '') && (int)$linhaNova['gerada_por_usuario_id'] === $gerId, '(link) O hash confere com o link mostrado; autoria registrada');
    $_POST = [];
    $_GET = ['visao' => 'pendente', 'busca' => $busca];
    $htmlDepois = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(!str_contains($htmlDepois, $m[1] ?? 'x'), '(link) Recarregar a lista não mostra mais o link (não é recuperável)');

    $comoUsuario($gerId, 'viewer');
    $_GET = ['visao' => 'elegiveis', 'busca' => 'ZZED', 'dias' => '0'];
    $htmlEleg = $renderizar(static fn() => (new AdminEntrevistaDesligamentoController())->index());
    $check(!str_contains($htmlEleg, 'ZZED Falecido') && !str_contains($htmlEleg, 'ZZED Futuro') && !str_contains($htmlEleg, 'ZZED Ativo'), '(admin) A lista de elegíveis não mostra Falecimento, futuro nem ativos');
    $_GET = [];

    // ---- rate limit ------------------------------------------------------------------------------------------------------------------------------
    EntrevistaDesligamentoController::limparLimite();
    $check(EntrevistaDesligamentoController::RL_MAX === 10 && EntrevistaDesligamentoController::RL_JANELA === 600 && EntrevistaDesligamentoController::RL_BLOQUEIO === 900, '(rate limit) Valores: 10 tentativas / 10 min, bloqueio de 15 min');
    for ($i = 0; $i < EntrevistaDesligamentoController::RL_MAX - 1; $i++) {
        EntrevistaDesligamentoController::registrarTokenInvalido();
    }
    $check(EntrevistaDesligamentoController::limiteAtingido() === false, '(rate limit) Abaixo do limite: não bloqueia');
    $htmlInvalido = $renderizar(static fn() => (new EntrevistaDesligamentoController())->show(str_repeat('0', 64)));
    $check(str_contains($htmlInvalido, 'Link inválido') && EntrevistaDesligamentoController::limiteAtingido() === true, '(rate limit) A 10ª tentativa com token inexistente bloqueia o IP');
    $htmlBloqueado = $renderizar(static fn() => (new EntrevistaDesligamentoController())->show((string)$reg7['token']));
    $check(str_contains($htmlBloqueado, 'Muitas tentativas') && !str_contains($htmlBloqueado, '<form'), '(rate limit) Bloqueado: nem um token válido abre o formulário (tela neutra)');
    EntrevistaDesligamentoController::limparLimite();
    $check(EntrevistaDesligamentoController::limiteAtingido() === false, '(rate limit) Limite reiniciável');
    for ($i = 0; $i < 30; $i++) {
        $renderizar(static fn() => (new EntrevistaDesligamentoController())->show((string)$reg7['token']));
    }
    $check(EntrevistaDesligamentoController::limiteAtingido() === false, '(rate limit) Acessos com token VÁLIDO não consomem o limite (uso normal nunca bloqueia)');
    EntrevistaDesligamentoController::limparLimite();

    $htmlSemCsrf = (function () use ($renderizar, $g1) {
        $_SESSION['csrf_token'] = 'valor-da-sessao';
        $_POST = ['csrf' => 'outro'];
        return $renderizar(static fn() => (new EntrevistaDesligamentoController())->store((string)$g1['token']));
    })();
    $check(str_contains($htmlSemCsrf, 'Sessão expirada') && !str_contains($htmlSemCsrf, '<form'), '(CSRF) POST público sem token CSRF válido é recusado');
    $_POST = [];

    // ---- não regressão ------------------------------------------------------------------------------------------------------------------------------
    $check(count(get_class_methods(PesquisaReacaoIntegracaoController::class)) >= 2 && method_exists(PesquisaIntegracaoController::class, 'show') && method_exists(TurnoverDashboardService::class, 'montarPainel'), '(regressão) Pesquisas existentes e Dashboard de Turnover continuam presentes');

    echo "\nENTREVISTA_DESLIGAMENTO_OK\n";
} finally {
    EntrevistaDesligamentoController::limparLimite();
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
