<?php

/**
 * Integração — PDI V1 assistida (PdiRepository + PdiService + AdminPdisController + permissões + menu + rotas + seed +
 * migration). Fixtures ZZPDI-* com limpeza em `finally`. Prova, contra o banco:
 *   - criação em rascunho com vínculo `metadados_id` e SNAPSHOT; vários PDIs por contrato; gestor obrigatório e
 *     válido; origem/datas válidas; no máximo 3 ações (serviço + UNIQUE/CHECK no banco); sem Área;
 *   - fluxo rascunho → não iniciado → em andamento → concluído/cancelado, exigências (estrutura completa, ≥1 ação,
 *     avaliação final), data real, bloqueio de edição comum, reabertura só Admin/RH com auditoria;
 *   - acompanhamentos append-only e "em nome do colaborador"; eventos de auditoria transacionais;
 *   - desligamento/mudança de cargo ou unidade NÃO encerram nem alteram o PDI (só sinalizam);
 *   - permissões visualizar/gerenciar/acompanhar, Admin por bypass, sem permissão = sem acesso;
 *   - escopo por linha: gestor só acessa o próprio PDI; RH/Admin todos; sem rota/portal do colaborador.
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
$cpfSecreto = '52998224725';
$emp = 'ZPE' . $suffix;
$ip = '127.0.0.1';
$agora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
$hoje = new DateTimeImmutable('today');
$dia = static fn(int $n): string => (new DateTimeImmutable('today'))->modify(($n >= 0 ? '+' : '-') . abs($n) . ' days')->format('Y-m-d');
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
$pdiRow = static fn(int $id): ?array => $linha('SELECT * FROM pdis WHERE id = ?', [$id]);
$eventos = static fn(int $id): array => $todas('SELECT * FROM pdi_eventos WHERE pdi_id = ? ORDER BY id', [$id]);
$eventosDe = static function (int $id, string $tipo) use ($eventos): array {
    return array_values(array_filter($eventos($id), static fn(array $e): bool => $e['tipo_evento'] === $tipo));
};

$mk = static function (string $nome, string $unidade, string $cargo, ?string $dem = null) use ($pdo, &$criados, $suffix, $emp, $cpfSecreto, $dia): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZPDI_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, $unidade, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, $cpfSecreto, $nome, 'ZZPDI Empresa', 'ZZPDI Unidade ' . $unidade,
        'ZZPDI Cargo ' . $cargo, $cargo, $dia(-800), $dia(-200), $dem, $dem === null ? null : '003', $dem === null ? 1 : 0, 'zzpdi-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

/** Repository que falha ao gravar um tipo de evento — prova a atomicidade alteração + auditoria. */
class PdiRepositoryQueFalha extends PdiRepository
{
    public string $tipoQueFalha = '';
    public function inserirEvento(int $pdiId, string $tipo, ?string $campo, ?string $anterior, ?string $novo, int $atorUsuarioId, string $atorPapel, bool $emNomeDoColaborador, ?string $ip, string $agora): void
    {
        if ($tipo === $this->tipoQueFalha) {
            throw new RuntimeException('falha simulada ao gravar evento');
        }
        parent::inserirEvento($pdiId, $tipo, $campo, $anterior, $novo, $atorUsuarioId, $atorPapel, $emNomeDoColaborador, $ip, $agora);
    }
}

try {
    // ---- permissões e usuários ---------------------------------------------------------------------------------------------
    $permId = [];
    foreach (['visualizar' => 660, 'gerenciar' => 670, 'acompanhar' => 680] as $acao => $ordem) {
        $p = $linha('SELECT id, modulo, ordem, ativo FROM permissoes WHERE codigo = ?', ['pdi.' . $acao]);
        $check($p !== null && (int)$p['ativo'] === 1 && $p['modulo'] === 'pdi' && (int)$p['ordem'] === $ordem, "(permissão) pdi.{$acao} no catálogo (ordem {$ordem}) — seed aplicado localmente");
        $permId[$acao] = (int)($p['id'] ?? 0);
    }
    $novoUsuario = static function (string $rotulo, string $role, array $permissoes) use (&$criados, $senha, $suffix, $permId): int {
        $id = User::create('ZZPDI ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzpdi.' . $suffix . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        if ($permissoes !== []) {
            Authorization::sincronizar($id, array_map(static fn(string $p): int => $permId[$p], $permissoes));
        }
        return $id;
    };
    $todasPerm = ['visualizar', 'gerenciar', 'acompanhar'];
    $adminId = $novoUsuario('Admin', 'admin', []);
    $rhId = $novoUsuario('RH', 'rh', $todasPerm);
    $gestorAId = $novoUsuario('Gestor A', 'viewer', $todasPerm);
    $gestorBId = $novoUsuario('Gestor B', 'viewer', $todasPerm);
    $semPermId = $novoUsuario('Sem Permissao', 'rh', []);
    $soVisId = $novoUsuario('So Visualiza', 'viewer', ['visualizar']);
    $soGerId = $novoUsuario('So Gerencia', 'viewer', ['visualizar', 'gerenciar']);
    $soAcoId = $novoUsuario('So Acompanha', 'viewer', ['visualizar', 'acompanhar']);
    $inativoId = $novoUsuario('Inativo', 'viewer', []);
    User::setActiveStatus($inativoId, false);
    $ator = static fn(int $id, string $role): array => ['id' => $id, 'role' => $role];
    $adm = $ator($adminId, 'admin');
    $rh = $ator($rhId, 'rh');
    $gA = $ator($gestorAId, 'viewer');
    $gB = $ator($gestorBId, 'viewer');
    $semPerm = $ator($semPermId, 'rh');

    $tem = static fn(int $u, string $p): bool => Authorization::usuarioTemPermissao($u, 'pdi.' . $p);
    $check($tem($adminId, 'visualizar') && $tem($adminId, 'gerenciar') && $tem($adminId, 'acompanhar'), '(permissão) Admin acessa tudo pelo bypass central');
    $check($tem($soVisId, 'visualizar') && !$tem($soVisId, 'gerenciar') && !$tem($soVisId, 'acompanhar'), '(permissão) visualizar NÃO dá gerenciar nem acompanhar');
    $check($tem($soGerId, 'gerenciar') && !$tem($soGerId, 'acompanhar') && $tem($soAcoId, 'acompanhar') && !$tem($soAcoId, 'gerenciar'), '(permissão) gerenciar e acompanhar são independentes');
    $check(!$tem($semPermId, 'visualizar') && !$tem($semPermId, 'gerenciar') && !$tem($semPermId, 'acompanhar'), '(permissão) RH pela role, sem permissão individual, não acessa nada');
    $concedidos = (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE p.modulo = 'pdi' AND up.usuario_id NOT IN (" . implode(',', array_map('intval', $criados['usuarios'])) . ')')->fetchColumn();
    $check($concedidos === 0, '(permissão) Ninguém além dos usuários de teste recebeu permissão — o seed não concede automaticamente');

    // ---- fixtures de contratos ----------------------------------------------------------------------------------------------
    $cg1 = 'ZPG1' . $suffix;
    $c1 = $mk('ZZPDI Ana Colaboradora', 'ZPU1', $cg1);
    $c2 = $mk('ZZPDI Bruno Colaborador', 'ZPU1', $cg1);
    $c3 = $mk('ZZPDI Carla Desligada', 'ZPU1', $cg1, $dia(-5));
    $c4 = $mk('ZZPDI Diego Movimentado', 'ZPU1', $cg1);
    $c5 = $mk('ZZPDI Elisa Outra', 'ZPU1', $cg1);

    $repo = new PdiRepository();
    $svc = new PdiService($repo);
    $post = static function (int $contrato, int $gestor, array $extra = []) use ($dia): array {
        return $extra + [
            'metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestor, 'origem_tipo' => 'feedback',
            'data_abertura' => $dia(0), 'data_prevista_conclusao' => $dia(90),
            'pontos_fortes' => 'Comunicação clara com a equipe', 'oportunidades_desenvolvimento' => 'Delegar tarefas', 'objetivo_esperado' => 'Assumir a liderança de uma equipe',
            'competencias' => "Comunicação\nLiderança",
            'acoes' => [
                1 => ['descricao' => 'Curso de liderança', 'responsavel_tipo' => 'colaborador', 'prazo' => $dia(30)],
                2 => ['descricao' => 'Mentoria mensal', 'responsavel_tipo' => 'gestor', 'prazo' => $dia(60)],
            ],
        ];
    };
    $postEdicao = static function (int $id, array $o = []) use ($pdiRow, $todas): array {
        $r = $pdiRow($id);
        $acoes = [];
        foreach ($todas('SELECT * FROM pdi_acoes WHERE pdi_id = ? ORDER BY ordem', [$id]) as $a) {
            $acoes[(int)$a['ordem']] = [
                'descricao' => $a['descricao'], 'responsavel_tipo' => $a['responsavel_tipo'],
                'responsavel_usuario_id' => $a['responsavel_usuario_id'] !== null ? (string)$a['responsavel_usuario_id'] : '',
                'responsavel_nome' => ($a['responsavel_usuario_id'] === null && in_array($a['responsavel_tipo'], ['rh', 'outro'], true)) ? (string)$a['responsavel_nome_snapshot'] : '',
                'prazo' => $a['prazo'],
            ];
        }
        $competencias = implode("\n", array_column($todas('SELECT competencia_texto FROM pdi_competencias WHERE pdi_id = ? ORDER BY id', [$id]), 'competencia_texto'));
        return $o + [
            'gestor_usuario_id' => (string)$r['gestor_usuario_id'], 'origem_tipo' => $r['origem_tipo'], 'data_abertura' => $r['data_abertura'],
            'data_prevista_conclusao' => $r['data_prevista_conclusao'], 'pontos_fortes' => (string)$r['pontos_fortes'],
            'oportunidades_desenvolvimento' => (string)$r['oportunidades_desenvolvimento'], 'objetivo_esperado' => (string)$r['objetivo_esperado'],
            'competencias' => $competencias, 'acoes' => $acoes,
        ];
    };

    // ---- criação ----------------------------------------------------------------------------------------------------------------------
    $r1 = $svc->criar($post($c1, $gestorAId), $rh, $agora, $ip);
    $check($r1['ok'] === true && $r1['id'] > 0, '(criação) RH cria PDI para um contrato oficial');
    $id1 = (int)$r1['id'];
    $p1 = $pdiRow($id1);
    $check($p1['status'] === 'rascunho' && (int)$p1['metadados_id'] === $c1 && (int)$p1['gestor_usuario_id'] === $gestorAId && (int)$p1['criado_por_usuario_id'] === $rhId, '(criação) Nasce como rascunho, vinculado a metadados_id, com gestor e criador registrados');
    $check($p1['snap_nome'] === 'ZZPDI Ana Colaboradora' && $p1['snap_codigo_empresa'] === $emp && $p1['snap_empresa'] === 'ZZPDI Empresa' && $p1['snap_codigo_unidade'] === 'ZPU1' && $p1['snap_unidade'] === 'ZZPDI Unidade ZPU1' && $p1['snap_codigo_cargo'] === $cg1 && $p1['snap_cargo'] === 'ZZPDI Cargo ' . $cg1 && $p1['snap_admissao'] === $dia(-800) && $p1['snap_data_inicio_cargo'] === $dia(-200), '(snapshot) Nome, empresa, unidade, cargo (código e nome), admissão e data de início do cargo preservados na abertura');
    $check($p1['gestor_nome_snapshot'] === 'ZZPDI Gestor A' && $p1['origem_tipo'] === 'feedback' && $p1['origem_ref_tipo'] === null && $p1['data_real_conclusao'] === null && $p1['avaliacao_final'] === null, '(criação) Snapshot do nome do gestor; origem; sem referência de origem, data real nem avaliação');
    $cols = array_column($todas('SHOW COLUMNS FROM pdis'), 'Field');
    $check(!preg_grep('/area|setor|centro|cpf|codigo_pessoa|salario|nascimento/i', $cols), '(privacidade/escopo) A tabela não tem Área, Setor, CPF, codigo_pessoa, salário nem nascimento');
    $comps = $todas('SELECT * FROM pdi_competencias WHERE pdi_id = ? ORDER BY id', [$id1]);
    $check(array_column($comps, 'competencia_texto') === ['Comunicação', 'Liderança'] && $comps[0]['competencia_id'] === null, '(competências) Em texto na V1; `competencia_id` fica nulo');
    $acoes1 = $todas('SELECT * FROM pdi_acoes WHERE pdi_id = ? ORDER BY ordem', [$id1]);
    $check(count($acoes1) === 2 && $acoes1[0]['responsavel_tipo'] === 'colaborador' && $acoes1[0]['responsavel_usuario_id'] === null && $acoes1[0]['responsavel_nome_snapshot'] === 'ZZPDI Ana Colaboradora' && $acoes1[1]['responsavel_tipo'] === 'gestor' && (int)$acoes1[1]['responsavel_usuario_id'] === $gestorAId && $acoes1[1]['responsavel_nome_snapshot'] === 'ZZPDI Gestor A' && $acoes1[0]['status'] === 'nao_iniciada', '(ações) Responsável colaborador (sem usuário, nome do snapshot) e gestor (usuário do PDI); status inicial "não iniciada"');
    $ev = $eventos($id1);
    $check(count($ev) === 1 && $ev[0]['tipo_evento'] === 'criacao' && (int)$ev[0]['ator_usuario_id'] === $rhId && $ev[0]['ator_papel'] === 'rh' && $ev[0]['ip'] === $ip && $ev[0]['valor_novo'] === 'rascunho' && (int)$ev[0]['registrado_em_nome_do_colaborador'] === 0, '(auditoria) Evento de criação com ator, papel, IP e status inicial');

    $r1b = $svc->criar($post($c1, $gestorAId, ['origem_tipo' => 'desenvolvimento_carreira']), $rh, $agora, $ip);
    $check($r1b['ok'] === true && (int)$linha('SELECT COUNT(*) n FROM pdis WHERE metadados_id = ?', [$c1])['n'] === 2, '(múltiplos) Um contrato pode ter vários PDIs (sem UNIQUE por contrato)');
    $idxUnico = $todas("SHOW INDEX FROM pdis WHERE Non_unique = 0 AND Column_name = 'metadados_id'");
    $check($idxUnico === [], '(banco) Nenhum índice UNIQUE em metadados_id');

    // validações de criação
    $semGestor = $svc->criar($post($c2, 0), $rh, $agora, $ip);
    $check($semGestor['ok'] === false && str_contains(implode(' ', $semGestor['erros']), 'gestor'), '(gestor) Obrigatório: sem gestor a criação é recusada');
    $check($svc->criar($post($c2, 2147483000), $rh, $agora, $ip)['ok'] === false && $svc->criar($post($c2, $inativoId), $rh, $agora, $ip)['ok'] === false, '(gestor) Precisa ser usuário ATIVO existente do Portal');
    $check($svc->criar($post($c2, $gestorAId, ['origem_tipo' => 'nada']), $rh, $agora, $ip)['ok'] === false, '(origem) Fora da lista fechada é recusada');
    $check($svc->criar($post($c2, $gestorAId, ['data_prevista_conclusao' => $dia(-1)]), $rh, $agora, $ip)['ok'] === false, '(datas) Data prevista anterior à abertura é recusada');
    $quatro = $post($c2, $gestorAId);
    $quatro['acoes'][3] = ['descricao' => 'C', 'responsavel_tipo' => 'colaborador', 'prazo' => $dia(70)];
    $quatro['acoes'][4] = ['descricao' => 'D', 'responsavel_tipo' => 'colaborador', 'prazo' => $dia(80)];
    $r4 = $svc->criar($quatro, $rh, $agora, $ip);
    $check($r4['ok'] === false && str_contains(implode(' ', $r4['erros']), 'no máximo 3') && (int)$linha('SELECT COUNT(*) n FROM pdis WHERE metadados_id = ?', [$c2])['n'] === 0, '(ações) Uma 4ª ação é recusada pelo Service e nada é gravado');
    $check($svc->criar($post(2147483000, $gestorAId), $rh, $agora, $ip)['ok'] === false && $svc->criar($post($c3, $gestorAId), $rh, $agora, $ip)['ok'] === false, '(contrato) Contrato inexistente ou já desligado não recebe PDI');
    $comOrigemRef = $svc->criar($post($c2, $gestorAId, ['origem_tipo' => 'avaliacao_experiencia', 'origem_ref_tipo' => 'avaliacao_experiencia', 'origem_ref_id' => '42']), $rh, $agora, $ip);
    $idRef = (int)$comOrigemRef['id'];
    $rowRef = $pdiRow($idRef);
    $check($comOrigemRef['ok'] === true && $rowRef['origem_ref_tipo'] === 'avaliacao_experiencia' && (int)$rowRef['origem_ref_id'] === 42, '(origem) Referência opcional futura gravada, sem FK (módulo de origem ainda não existe)');

    // banco: máx. 3 ações e coerência de conclusão
    try {
        $pdo->prepare("INSERT INTO pdi_acoes (pdi_id, ordem, descricao, responsavel_tipo, prazo, criado_em, atualizado_em) VALUES (?, 4, 'x', 'gestor', ?, NOW(), NOW())")->execute([$id1, $dia(10)]);
        $check(false, '(banco) CHECK deve barrar ordem 4');
    } catch (PDOException $e) {
        $check(true, '(banco) CHECK (ordem BETWEEN 1 AND 3) barra uma 4ª ação direto no banco');
    }
    try {
        $pdo->prepare("INSERT INTO pdi_acoes (pdi_id, ordem, descricao, responsavel_tipo, prazo, criado_em, atualizado_em) VALUES (?, 1, 'x', 'gestor', ?, NOW(), NOW())")->execute([$id1, $dia(10)]);
        $check(false, '(banco) UNIQUE (pdi_id, ordem) deve barrar duplicidade');
    } catch (PDOException $e) {
        $check((int)($e->errorInfo[1] ?? 0) === 1062, '(banco) UNIQUE (pdi_id, ordem) barra ação duplicada no mesmo slot');
    }
    try {
        $pdo->prepare("UPDATE pdis SET status = 'concluido' WHERE id = ?")->execute([$id1]);
        $check(false, '(banco) CHECK deve barrar concluído sem avaliação/data real');
    } catch (PDOException $e) {
        $check(true, '(banco) CHECK barra "concluído" sem avaliação final e data real de conclusão');
    }

    // criação por gestor (escopo)
    $pGestor = $svc->criar($post($c5, $gestorBId), $gA, $agora, $ip);
    $idGA = (int)$pGestor['id'];
    $check($pGestor['ok'] === true && (int)$pdiRow($idGA)['gestor_usuario_id'] === $gestorAId, '(escopo) Gestor sem escopo total cria PDI sempre vinculado a SI PRÓPRIO (o gestor enviado é ignorado)');
    $check($svc->criar($post($c5, $gestorAId), $ator($soVisId, 'viewer'), $agora, $ip)['ok'] === false && $svc->criar($post($c5, $gestorAId), $semPerm, $agora, $ip)['ok'] === false && $svc->criar($post($c5, $gestorAId), $ator($soAcoId, 'viewer'), $agora, $ip)['ok'] === false, '(permissão) Só quem tem pdi.gerenciar cria: visualizar, acompanhar e sem permissão são recusados');

    // ---- rascunho → não iniciado → em andamento ----------------------------------------------------------------------------------------
    $vazio = $svc->criar($post($c2, $gestorAId, ['pontos_fortes' => '', 'oportunidades_desenvolvimento' => '', 'objetivo_esperado' => '', 'competencias' => '', 'acoes' => []]), $rh, $agora, $ip);
    $idV = (int)$vazio['id'];
    $check($vazio['ok'] === true && $pdiRow($idV)['status'] === 'rascunho', '(rascunho) Pode ser criado incompleto');
    $ed00 = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['objetivo_esperado' => 'Rascunho editado']), $rh, $agora, $ip);
    $check($ed00['ok'] === true && $pdiRow($idV)['status'] === 'rascunho', '(rascunho) Editável livremente por quem tem permissão');
    $ed01 = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['objetivo_esperado' => '']), $rh, $agora, $ip);
    $check($ed01['ok'] === true && $pdiRow($idV)['objetivo_esperado'] === null, '(opcionais) Os textos do desenvolvimento podem ficar em branco');
    $lib0 = $svc->liberar($idV, $rh, $agora, $ip);
    $check($lib0['ok'] === true && $pdiRow($idV)['status'] === 'nao_iniciado' && count($eventosDe($idV, 'liberacao')) === 1 && $pdiRow($idV)['pontos_fortes'] === null && (int)$linha('SELECT COUNT(*) n FROM pdi_competencias WHERE pdi_id = ?', [$idV])['n'] === 0, '(status) Sair de rascunho NÃO exige pontos fortes, oportunidades, objetivo nem competência: Rascunho → Não iniciado, com evento');
    $ed0 = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['pontos_fortes' => 'Ótimo', 'oportunidades_desenvolvimento' => 'Delegar', 'objetivo_esperado' => 'Liderar', 'competencias' => 'Comunicação']), $rh, $agora, $ip);
    $check($ed0['ok'] === true && $pdiRow($idV)['status'] === 'nao_iniciado', '(edição) PDI não iniciado continua editável (textos e competências preenchidos depois)');
    $pdSo = $svc->criar($post($c4, $gestorAId, ['pontos_fortes' => '', 'oportunidades_desenvolvimento' => '', 'objetivo_esperado' => '', 'competencias' => '']), $rh, $agora, $ip);
    $idSo = (int)$pdSo['id'];
    $iniSo = $svc->iniciar($idSo, $rh, $agora, $ip);
    $check($pdSo['ok'] === true && $iniSo['ok'] === true && $pdiRow($idSo)['status'] === 'em_andamento' && $pdiRow($idSo)['pontos_fortes'] === null, '(status) Iniciar exige apenas ao menos 1 ação: sem textos e sem competência o PDI inicia direto do rascunho');
    $semAcao = $svc->criar($post($c4, $gestorAId, ['acoes' => []]), $rh, $agora, $ip);
    $check($semAcao['ok'] === true && $svc->iniciar((int)$semAcao['id'], $rh, $agora, $ip)['ok'] === false && $pdiRow((int)$semAcao['id'])['status'] === 'rascunho', '(status) Sem nenhuma ação o PDI NÃO inicia, mesmo com todos os textos preenchidos');
    $svc->atualizarEstrutura($idSo, $postEdicao($idSo, ['acoes' => []]), $rh, $agora, $ip);
    $check((int)$linha('SELECT COUNT(*) n FROM pdi_acoes WHERE pdi_id = ?', [$idSo])['n'] === 0, '(ações) Nenhuma regra extra além da aprovada: a estrutura pode ser ajustada (a exigência de ≥1 ação é para INICIAR)');
    $ini0 = $svc->iniciar($idV, $rh, $agora, $ip);
    $check($ini0['ok'] === false && str_contains($ini0['error'], 'ao menos 1 ação') && $pdiRow($idV)['status'] === 'nao_iniciado', '(status) Iniciar exige ao menos 1 ação');
    $ed1 = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['acoes' => [1 => ['descricao' => 'Workshop de delegação', 'responsavel_tipo' => 'rh', 'responsavel_nome' => 'Fulano do RH', 'prazo' => $dia(40)]]]), $rh, $agora, $ip);
    $check($ed1['ok'] === true && (int)$linha('SELECT COUNT(*) n FROM pdi_acoes WHERE pdi_id = ?', [$idV])['n'] === 1, '(ações) Ação incluída em PDI não iniciado');
    $a1 = $linha('SELECT * FROM pdi_acoes WHERE pdi_id = ? AND ordem = 1', [$idV]);
    $check($a1['responsavel_tipo'] === 'rh' && $a1['responsavel_usuario_id'] === null && $a1['responsavel_nome_snapshot'] === 'Fulano do RH', '(ações) Responsável RH/Outro sem usuário usa o nome em texto (snapshot)');
    $ini1 = $svc->iniciar($idV, $rh, $agora, $ip);
    $check($ini1['ok'] === true && $pdiRow($idV)['status'] === 'em_andamento' && count($eventosDe($idV, 'inicio')) === 1, '(status) Não iniciado → Em andamento com ≥1 ação, com evento');
    $check(count($eventosDe($idV, 'acao_incluida')) === 1, '(auditoria) Inclusão de ação gera evento');

    // ---- alterações geram eventos ---------------------------------------------------------------------------------------------------------
    $novaPrevista = $dia(120);
    $edPrazo = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['data_prevista_conclusao' => $novaPrevista]), $rh, $agora, $ip);
    $ePrazo = $eventosDe($idV, 'alteracao_prazo');
    $check($edPrazo['ok'] === true && count($ePrazo) === 1 && $ePrazo[0]['campo'] === 'data_prevista_conclusao' && $ePrazo[0]['valor_anterior'] === $dia(90) && $ePrazo[0]['valor_novo'] === $novaPrevista && (int)$ePrazo[0]['ator_usuario_id'] === $rhId, '(auditoria) Mudança de prazo gera evento com valor anterior, novo e autor');
    $check($svc->atualizarEstrutura($idV, $postEdicao($idV, ['data_prevista_conclusao' => $dia(20)]), $rh, $agora, $ip)['ok'] === false, '(ações) Prazo do PDI menor que o prazo de uma ação existente é recusado');
    $edAcao = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['acoes' => [1 => ['descricao' => 'Workshop avançado', 'responsavel_tipo' => 'outro', 'responsavel_nome' => 'Consultoria Externa', 'prazo' => $dia(50)]]]), $rh, $agora, $ip);
    $check($edAcao['ok'] === true && count($eventosDe($idV, 'acao_alterada')) === 2 && count($eventosDe($idV, 'mudanca_responsavel_acao')) === 1, '(auditoria) Alterar descrição e prazo de uma ação gera eventos; mudar o responsável gera evento próprio');
    $eResp = $eventosDe($idV, 'mudanca_responsavel_acao')[0];
    $check($eResp['campo'] === 'acao_1.responsavel' && str_contains((string)$eResp['valor_anterior'], 'Fulano do RH') && str_contains((string)$eResp['valor_novo'], 'Consultoria Externa'), '(auditoria) Evento de responsável guarda quem era e quem passou a ser');
    $incAntes = count($eventosDe($idV, 'competencia_incluida'));
    $edComp = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['competencias' => "Comunicação\nGestão do tempo"]), $rh, $agora, $ip);
    $eInc = $eventosDe($idV, 'competencia_incluida');
    $check($edComp['ok'] === true && count($eInc) === $incAntes + 1 && end($eInc)['valor_novo'] === 'Gestão do tempo' && count($eventosDe($idV, 'competencia_removida')) === 0, '(auditoria) Competência incluída gera evento');
    $edComp2 = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['competencias' => 'Comunicação']), $rh, $agora, $ip);
    $check($edComp2['ok'] === true && count($eventosDe($idV, 'competencia_removida')) === 1, '(auditoria) Competência removida gera evento');
    $objAntes = count($eventosDe($idV, 'alteracao_objetivo'));
    $desAntes = count($eventosDe($idV, 'alteracao_desenvolvimento'));
    $edObj = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['objetivo_esperado' => 'Liderar equipe de 5 pessoas', 'pontos_fortes' => 'Ótimo e proativo']), $rh, $agora, $ip);
    $check($edObj['ok'] === true && count($eventosDe($idV, 'alteracao_objetivo')) === $objAntes + 1 && count($eventosDe($idV, 'alteracao_desenvolvimento')) === $desAntes + 1, '(auditoria) Objetivo e desenvolvimento profissional alterados geram eventos');
    $edGestor = $svc->atualizarEstrutura($idV, $postEdicao($idV, ['gestor_usuario_id' => (string)$gestorBId]), $rh, $agora, $ip);
    $eG = $eventosDe($idV, 'alteracao_gestor');
    $check($edGestor['ok'] === true && (int)$pdiRow($idV)['gestor_usuario_id'] === $gestorBId && $pdiRow($idV)['gestor_nome_snapshot'] === 'ZZPDI Gestor B' && count($eG) === 1 && $eG[0]['valor_anterior'] === 'ZZPDI Gestor A' && $eG[0]['valor_novo'] === 'ZZPDI Gestor B', '(auditoria) Troca de gestor (RH) atualiza o snapshot e gera evento');
    $check($svc->atualizarEstrutura($idV, $postEdicao($idV, ['gestor_usuario_id' => (string)$gestorAId]), $gB, $agora, $ip)['ok'] === true && (int)$pdiRow($idV)['gestor_usuario_id'] === $gestorBId && count($eventosDe($idV, 'alteracao_gestor')) === 1, '(escopo) Gestor sem escopo total não reatribui o gestor: o valor enviado é ignorado');
    $eNada = count($eventos($idV));
    $svc->atualizarEstrutura($idV, $postEdicao($idV), $rh, $agora, $ip);
    $check(count($eventos($idV)) === $eNada, '(auditoria) Salvar sem mudanças não gera eventos');

    // atomicidade
    $repoFalha = new PdiRepositoryQueFalha();
    $repoFalha->tipoQueFalha = 'alteracao_prazo';
    $antes = $pdiRow($idV)['data_prevista_conclusao'];
    $eventosAntes = count($eventos($idV));
    try {
        (new PdiService($repoFalha))->atualizarEstrutura($idV, $postEdicao($idV, ['data_prevista_conclusao' => $dia(200)]), $rh, $agora, $ip);
        $check(false, '(atomicidade) A falha do evento deveria propagar');
    } catch (RuntimeException $e) {
        $check($pdiRow($idV)['data_prevista_conclusao'] === $antes && count($eventos($idV)) === $eventosAntes, '(atomicidade) Falha ao gravar o evento desfaz a alteração (mesma transação): PDI e trilha ficam consistentes');
    }

    // ---- ação: status ------------------------------------------------------------------------------------------------------------------------
    $stA = $svc->alterarStatusAcao($idV, 1, 'em_andamento', $gB, $agora, $ip);
    $eSt = $eventosDe($idV, 'acao_status');
    $check($stA['ok'] === true && count($eSt) === 1 && $eSt[0]['campo'] === 'acao_1.status' && $eSt[0]['valor_anterior'] === 'nao_iniciada' && $eSt[0]['valor_novo'] === 'em_andamento' && $eSt[0]['ator_papel'] === 'gestor', '(ação) Status da ação muda (não iniciada → em andamento) e gera evento (papel: gestor)');
    $check($svc->alterarStatusAcao($idV, 1, 'inventado', $gB, $agora, $ip)['ok'] === false && $svc->alterarStatusAcao($idV, 3, 'concluida', $gB, $agora, $ip)['ok'] === false, '(ação) Status inválido ou ação inexistente é recusado');
    $check($svc->alterarStatusAcao($idV, 1, 'em_andamento', $gB, $agora, $ip)['ok'] === true && count($eventosDe($idV, 'acao_status')) === 1, '(ação) Repetir o mesmo status não gera evento');
    $check($svc->alterarStatusAcao($id1, 1, 'concluida', $rh, $agora, $ip)['ok'] === false, '(ação) O status da ação só muda com o PDI em andamento (este está em rascunho)');

    // ---- acompanhamentos (append-only) ----------------------------------------------------------------------------------------------
    $ac1 = $svc->adicionarAcompanhamento($idV, 'Primeira conversa realizada', false, $gB, $agora, $ip);
    $ac2 = $svc->adicionarAcompanhamento($idV, 'Colaborador relatou dificuldade em delegar', true, $gB, $agora->modify('+1 minute'), $ip);
    $lista = $repo->acompanhamentos($idV);
    $check($ac1['ok'] === true && $ac2['ok'] === true && count($lista) === 2 && $lista[0]['comentario'] === 'Colaborador relatou dificuldade em delegar' && $lista[1]['autor_papel'] === 'gestor' && (int)$lista[1]['registrado_em_nome_do_colaborador'] === 0, '(acompanhamento) Comentários acumulados em ordem cronológica reversa (append-only)');
    $check($lista[0]['autor_papel'] === 'colaborador_assistido' && (int)$lista[0]['registrado_em_nome_do_colaborador'] === 1 && (int)$lista[0]['autor_usuario_id'] === $gestorBId, '(assistido) Relato em nome do colaborador: papel "colaborador_assistido", flag e QUEM registrou');
    $eAc = $eventosDe($idV, 'acompanhamento');
    $check(count($eAc) === 2 && (int)$eAc[1]['registrado_em_nome_do_colaborador'] === 1 && $eAc[1]['ator_papel'] === 'gestor' && (int)$eAc[0]['registrado_em_nome_do_colaborador'] === 0, '(assistido) O evento também marca "registrado em nome do colaborador" e mantém o papel real do ator');
    $check($svc->adicionarAcompanhamento($idV, '   ', false, $gB, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($idV, str_repeat('a', 4001), false, $gB, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($id1, 'x', false, $rh, $agora, $ip)['ok'] === false, '(acompanhamento) Vazio, acima do limite ou em PDI em rascunho é recusado');
    $metodosRepo = array_map(static fn(ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(PdiRepository::class))->getMethods());
    $check(!preg_grep('/^(atualizar|editar|remover|excluir|apagar)(Acompanhamento|Evento)/i', $metodosRepo), '(append-only) O repository não oferece nenhuma operação de alterar/apagar acompanhamento ou evento');

    // espaço do colaborador
    $esp = $svc->registrarEspacoColaborador($idV, 'Me sinto motivado', 'Quero aprender a delegar', $gB, $agora, $ip);
    $eEsp = $eventosDe($idV, 'espaco_colaborador');
    $check($esp['ok'] === true && $pdiRow($idV)['momento_profissional'] === 'Me sinto motivado' && $pdiRow($idV)['pontos_desenvolver_colaborador'] === 'Quero aprender a delegar' && count($eEsp) === 2 && (int)$eEsp[0]['registrado_em_nome_do_colaborador'] === 1 && (int)$eEsp[1]['registrado_em_nome_do_colaborador'] === 1 && (int)$eEsp[0]['ator_usuario_id'] === $gestorBId, '(assistido) Espaço do colaborador registrado por gestor/RH: cada campo gera evento "em nome do colaborador" com o autor real');
    $svc->registrarEspacoColaborador($idV, 'Me sinto motivado', 'Quero aprender a delegar', $gB, $agora, $ip);
    $check(count($eventosDe($idV, 'espaco_colaborador')) === 2, '(assistido) Reenviar o mesmo conteúdo não gera evento novo');

    // evidências
    $evd = $svc->registrarEvidencias($idV, 'Liderou a reunião semanal com autonomia', $gB, $agora, $ip);
    $check($evd['ok'] === true && $pdiRow($idV)['evidencias_evolucao'] === 'Liderou a reunião semanal com autonomia' && count($eventosDe($idV, 'evidencias')) === 1 && $svc->registrarEvidencias($id1, 'x', $rh, $agora, $ip)['ok'] === false, '(evidências) Somente texto, com evento; recusadas em rascunho');

    // ---- conclusão ---------------------------------------------------------------------------------------------------------------------------
    $c0 = $svc->concluir($idV, '', 'ok', $gB, $agora, $ip);
    $check($c0['ok'] === false && $svc->concluir($idV, 'inventada', '', $gB, $agora, $ip)['ok'] === false && $pdiRow($idV)['status'] === 'em_andamento', '(conclusão) Exige avaliação final válida (lista fechada)');
    $check($svc->concluir($idGA, 'objetivo_atingido', '', $rh, $agora, $ip)['ok'] === false, '(conclusão) Só conclui a partir de "em andamento"');
    $cc = $svc->concluir($idV, 'objetivo_atingido', 'Plano cumprido com sucesso', $gB, $agora, $ip);
    $pc = $pdiRow($idV);
    $check($cc['ok'] === true && $pc['status'] === 'concluido' && $pc['avaliacao_final'] === 'objetivo_atingido' && $pc['comentarios_finais'] === 'Plano cumprido com sucesso' && $pc['data_real_conclusao'] === $hoje->format('Y-m-d'), '(conclusão) Status concluído + avaliação final + comentários + data real de conclusão (hoje)');
    $check(count($eventosDe($idV, 'conclusao')) === 1 && count($eventosDe($idV, 'avaliacao_final')) === 1 && $eventosDe($idV, 'avaliacao_final')[0]['valor_novo'] === 'objetivo_atingido', '(auditoria) Conclusão e avaliação final geram eventos');
    // bloqueio de edição comum
    $bloq = [
        'estrutura' => $svc->atualizarEstrutura($idV, $postEdicao($idV, ['pontos_fortes' => 'x']), $rh, $agora, $ip),
        'espaco' => $svc->registrarEspacoColaborador($idV, 'x', 'y', $rh, $agora, $ip),
        'acompanhamento' => $svc->adicionarAcompanhamento($idV, 'x', false, $rh, $agora, $ip),
        'evidencias' => $svc->registrarEvidencias($idV, 'x', $rh, $agora, $ip),
        'status_acao' => $svc->alterarStatusAcao($idV, 1, 'concluida', $rh, $agora, $ip),
        'cancelar' => $svc->cancelar($idV, 'x', $rh, $agora, $ip),
        'iniciar' => $svc->iniciar($idV, $rh, $agora, $ip),
        'liberar' => $svc->liberar($idV, $rh, $agora, $ip),
    ];
    $check(!in_array(true, array_column($bloq, 'ok'), true) && $pdiRow($idV)['pontos_fortes'] === 'Ótimo e proativo', '(bloqueio) Concluído: estrutura, espaço, acompanhamento, evidências, status de ação, cancelar, iniciar e liberar ficam bloqueados');
    // reabertura
    $r0 = $svc->reabrir($idV, 'Novos objetivos', $gB, $agora, $ip);
    $check($r0['ok'] === false && str_contains($r0['error'], 'Admin/RH') && $pdiRow($idV)['status'] === 'concluido', '(reabertura) Gestor sem escopo total NÃO reabre');
    $check($svc->reabrir($idV, '', $rh, $agora, $ip)['ok'] === false && $svc->reabrir($idV, '   ', $adm, $agora, $ip)['ok'] === false, '(reabertura) Exige motivo');
    $rb = $svc->reabrir($idV, 'Ajuste de metas do ciclo', $rh, $agora, $ip);
    $pr = $pdiRow($idV);
    $check($rb['ok'] === true && $pr['status'] === 'em_andamento' && $pr['data_real_conclusao'] === null && $pr['avaliacao_final'] === null && $pr['comentarios_finais'] === null, '(reabertura) RH reabre: volta a em andamento e limpa avaliação final, comentários e data real');
    $eRe = $eventosDe($idV, 'reabertura');
    $eAv = $eventosDe($idV, 'avaliacao_final');
    $check(count($eRe) === 1 && $eRe[0]['valor_anterior'] === 'concluido' && $eRe[0]['valor_novo'] === 'em_andamento' && count($eventosDe($idV, 'reabertura_motivo')) === 1 && $eventosDe($idV, 'reabertura_motivo')[0]['valor_novo'] === 'Ajuste de metas do ciclo' && count($eAv) === 2 && $eAv[1]['valor_anterior'] === 'objetivo_atingido' && $eAv[1]['valor_novo'] === null, '(reabertura) Auditoria obrigatória: evento de reabertura, motivo e avaliação anterior preservada no histórico');
    $check($svc->reabrir($idV, 'de novo', $rh, $agora, $ip)['ok'] === false, '(reabertura) Só reabre PDI concluído');
    $check($svc->concluir($idV, 'superou_expectativa', '', $adm, $agora, $ip)['ok'] === true && $pdiRow($idV)['avaliacao_final'] === 'superou_expectativa' && count($eventosDe($idV, 'conclusao')) === 2, '(conclusão) Pode concluir de novo depois de reaberto (Admin) com nova avaliação');

    // ---- cancelamento --------------------------------------------------------------------------------------------------------------------------
    $canc = $svc->cancelar($idGA, '', $gA, $agora, $ip);
    $check($canc['ok'] === false, '(cancelamento) Exige motivo');
    $canc2 = $svc->cancelar($idGA, 'Colaborador transferido de projeto', $gA, $agora, $ip);
    $check($canc2['ok'] === true && $pdiRow($idGA)['status'] === 'cancelado' && count($eventosDe($idGA, 'cancelamento')) === 1 && $eventosDe($idGA, 'cancelamento_motivo')[0]['valor_novo'] === 'Colaborador transferido de projeto', '(cancelamento) Cancela com motivo e evento');
    $check($svc->iniciar($idGA, $rh, $agora, $ip)['ok'] === false && $svc->reabrir($idGA, 'x', $rh, $agora, $ip)['ok'] === false && $svc->atualizarEstrutura($idGA, $postEdicao($idGA, ['pontos_fortes' => 'x']), $rh, $agora, $ip)['ok'] === false, '(cancelamento) Cancelado é final: sem iniciar, reabrir nem editar');

    // ---- desligamento durante o PDI --------------------------------------------------------------------------------------------------------------
    $pdD1 = (int)$svc->criar($post($c2, $gestorAId), $rh, $agora, $ip)['id'];
    $svc->liberar($pdD1, $rh, $agora, $ip);
    $svc->iniciar($pdD1, $rh, $agora, $ip);
    $pdD2 = (int)$svc->criar($post($c5, $gestorAId), $rh, $agora, $ip)['id'];
    $svc->liberar($pdD2, $rh, $agora, $ip);
    $svc->iniciar($pdD2, $rh, $agora, $ip);
    $check($svc->manterAposDesligamento($pdD1, 'x', $rh, $agora, $ip)['ok'] === false, '(desligamento) "Manter" só se aplica a contrato desligado');
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = ?, ativo = 0 WHERE id IN (?, ?)')->execute([$dia(-1), $c2, $c5]);
    $dD = $svc->detalhe($pdD1, $rh, $hoje);
    $check($dD['pdi']['status'] === 'em_andamento' && $dD['divergencias']['desligado'] === true && $dD['divergencias']['desligamento'] === $dia(-1), '(desligamento) O contrato foi desligado: o PDI NÃO é encerrado nem alterado sozinho — só sinalizado');
    $check($svc->manterAposDesligamento($pdD1, '', $rh, $agora, $ip)['ok'] === false, '(desligamento) Manter exige justificativa');
    $mant = $svc->manterAposDesligamento($pdD1, 'Concluir o ciclo antes do desligamento efetivo', $rh, $agora, $ip);
    $eM = $eventosDe($pdD1, 'decisao_contrato_desligado');
    $check($mant['ok'] === true && $pdiRow($pdD1)['status'] === 'em_andamento' && count($eM) === 1 && $eM[0]['valor_anterior'] === $dia(-1) && str_starts_with((string)$eM[0]['valor_novo'], 'mantido') && (int)$eM[0]['ator_usuario_id'] === $rhId, '(desligamento) Decisão de MANTER registrada no histórico, com quem decidiu; status intacto');
    $svc->concluir($pdD2, 'evoluiu_parcialmente', '', $rh, $agora, $ip);
    $eC = $eventosDe($pdD2, 'decisao_contrato_desligado');
    $check($pdiRow($pdD2)['status'] === 'concluido' && count($eC) === 1 && $eC[0]['valor_novo'] === 'concluido', '(desligamento) Concluir um PDI de contrato desligado registra a decisão no histórico');
    $check($svc->criar($post($c2, $gestorAId), $rh, $agora, $ip)['ok'] === false, '(desligamento) Contrato desligado não recebe NOVO PDI');
    $l0 = $svc->listar(['busca' => 'Bruno'], $rh, $hoje);
    $check(count($l0['itens']) >= 1 && in_array('em_andamento', array_column($l0['itens'], 'status'), true), '(desligamento) A lista continua exibindo o PDI (nada foi apagado)');

    // ---- mudança de cargo / unidade --------------------------------------------------------------------------------------------------------------
    $pdC = (int)$svc->criar($post($c4, $gestorAId), $rh, $agora, $ip)['id'];
    $svc->liberar($pdC, $rh, $agora, $ip);
    $pdo->prepare("UPDATE colaboradores_metadados SET codigo_cargo = 'OUTRO', cargo = 'Outro Cargo', codigo_unidade = 'ZPU9', unidade = 'Outra Unidade', data_inicio_cargo = ? WHERE id = ?")->execute([$dia(-1), $c4]);
    $dC = $svc->detalhe($pdC, $rh, $hoje);
    $tipos = array_column($dC['divergencias']['itens'], 'tipo');
    $rC = $pdiRow($pdC);
    $check($rC['status'] === 'nao_iniciado' && $rC['snap_codigo_cargo'] === $cg1 && $rC['snap_cargo'] === 'ZZPDI Cargo ' . $cg1 && $rC['snap_codigo_unidade'] === 'ZPU1' && $rC['snap_data_inicio_cargo'] === $dia(-200), '(snapshot) Mudança de cargo/unidade no METADADOS: o snapshot da abertura permanece e o PDI não muda de status');
    $check(in_array('cargo', $tipos, true) && in_array('unidade', $tipos, true) && !$dC['divergencias']['desligado'], '(snapshot) A divergência de cargo e de unidade é sinalizada');

    // ---- atraso (só sinalização) -------------------------------------------------------------------------------------------------------------------
    $pdAt = $svc->criar($post($c1, $gestorAId, ['data_abertura' => $dia(-60), 'data_prevista_conclusao' => $dia(-10), 'acoes' => [1 => ['descricao' => 'Ação atrasada', 'responsavel_tipo' => 'colaborador', 'prazo' => $dia(-20)]]]), $rh, $agora, $ip);
    $idAt = (int)$pdAt['id'];
    $check($pdAt['ok'] === true && $svc->detalhe($idAt, $rh, $hoje)['prazo']['atrasado'] === false, '(atraso) Rascunho com prazo vencido não é sinalizado como atrasado');
    $svc->liberar($idAt, $rh, $agora, $ip);
    $dAt = $svc->detalhe($idAt, $rh, $hoje);
    $check($dAt['prazo'] === ['atrasado' => true, 'dias_atraso' => 10] && $dAt['pdi']['status'] === 'nao_iniciado' && $dAt['acoes'][0]['atrasada'] === true, '(atraso) Passou da data prevista: sinalizado como atrasado (10 dias) e a ação também — o status NÃO muda sozinho');
    $lAt = $svc->listar(['prazo' => 'atrasado'], $rh, $hoje);
    $check(in_array($idAt, array_map('intval', array_column($lAt['itens'], 'id')), true) && !in_array($idV, array_map('intval', array_column($lAt['itens'], 'id')), true), '(lista) Filtro "atrasado" traz os PDIs vencidos e não os concluídos');
    $lAt2 = $svc->listar(['prazo' => 'atrasado'], $rh, $hoje);
    $linhaAt = array_values(array_filter($lAt2['itens'], static fn(array $i): bool => (int)$i['id'] === $idAt))[0];
    $check($linhaAt['prazo']['atrasado'] === true && $linhaAt['acoes_atrasadas'] === 1 && $linhaAt['progresso'] === ['total' => 1, 'concluidas' => 0, 'percentual' => 0], '(lista) Atraso do PDI, ações atrasadas e progresso das ações (0/1) calculados na própria consulta');

    // ---- permissões (matriz) ------------------------------------------------------------------------------------------------------------------
    $cP = $mk('ZZPDI Contrato Permissoes', 'ZPU1', $cg1);
    $pVis = (int)$svc->criar($post($cP, $soVisId), $rh, $agora, $ip)['id'];
    $pGer = (int)$svc->criar($post($cP, $soGerId), $rh, $agora, $ip)['id'];
    $pAco = (int)$svc->criar($post($cP, $soAcoId), $rh, $agora, $ip)['id'];
    $aVis = $ator($soVisId, 'viewer');
    $aGer = $ator($soGerId, 'viewer');
    $aAco = $ator($soAcoId, 'viewer');
    $check($svc->detalhe($pVis, $aVis, $hoje) !== null && count($svc->listar([], $aVis, $hoje)['itens']) === 1, '(visualizar) Vê a lista e o detalhe do PDI em que é gestor');
    $check($svc->atualizarEstrutura($pVis, $postEdicao($pVis, ['pontos_fortes' => 'x']), $aVis, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($pVis, 'x', false, $aVis, $agora, $ip)['ok'] === false && $svc->liberar($pVis, $aVis, $agora, $ip)['ok'] === false && $svc->registrarEspacoColaborador($pVis, 'x', 'y', $aVis, $agora, $ip)['ok'] === false, '(visualizar) Não edita, não acompanha e não muda status');
    $check($svc->atualizarEstrutura($pGer, $postEdicao($pGer, ['pontos_fortes' => 'Novo texto']), $aGer, $agora, $ip)['ok'] === true && $svc->liberar($pGer, $aGer, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($pGer, 'x', false, $aGer, $agora, $ip)['ok'] === false && $svc->registrarEspacoColaborador($pGer, 'Momento', '', $aGer, $agora, $ip)['ok'] === true, '(gerenciar) Edita a estrutura e registra o espaço do colaborador; NÃO altera status nem acompanha');
    $check($svc->atualizarEstrutura($pAco, $postEdicao($pAco, ['pontos_fortes' => 'x']), $aAco, $agora, $ip)['ok'] === false && $svc->liberar($pAco, $aAco, $agora, $ip)['ok'] === true && $svc->iniciar($pAco, $aAco, $agora, $ip)['ok'] === true && $svc->adicionarAcompanhamento($pAco, 'Ok', false, $aAco, $agora, $ip)['ok'] === true && $svc->registrarEvidencias($pAco, 'Evid', $aAco, $agora, $ip)['ok'] === true, '(acompanhar) Muda status, acompanha e registra evidências; NÃO edita a estrutura');
    $check($svc->listar([], $semPerm, $hoje)['ok'] === false && $svc->detalhe($pAco, $semPerm, $hoje) === null && $svc->atualizarEstrutura($pAco, $postEdicao($pAco), $semPerm, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($pAco, 'x', false, $semPerm, $agora, $ip)['ok'] === false, '(sem permissão) RH pela role, sem permissão individual, não lista, não vê e não altera');
    $check($svc->detalhe($pAco, $adm, $hoje) !== null && $svc->detalhe($pAco, $rh, $hoje) !== null, '(Admin/RH) Admin (bypass) e RH (permissão) veem qualquer PDI');

    // ---- escopo por linha ---------------------------------------------------------------------------------------------------------------------------
    $idsA = array_map('intval', array_column($svc->listar([], $gA, $hoje)['itens'], 'id'));
    $idsB = array_map('intval', array_column($svc->listar([], $gB, $hoje)['itens'], 'id'));
    $idsRh = array_map('intval', array_column($svc->listar([], $rh, $hoje)['itens'], 'id'));
    $idsAdm = array_map('intval', array_column($svc->listar([], $adm, $hoje)['itens'], 'id'));
    $donoA = array_map('intval', array_column($todas('SELECT id FROM pdis WHERE gestor_usuario_id = ?', [$gestorAId]), 'id'));
    $donoB = array_map('intval', array_column($todas('SELECT id FROM pdis WHERE gestor_usuario_id = ?', [$gestorBId]), 'id'));
    sort($idsA);
    sort($donoA);
    $check($idsA === $donoA && $idsA !== [] && array_diff($idsA, $donoB) === $idsA, '(escopo) A lista do gestor A traz SÓ os PDIs em que ele é o responsável');
    $check(count(array_intersect($idsB, $donoA)) === 0 && $idsB !== [], '(escopo) A lista do gestor B não traz PDIs do gestor A');
    $check(!array_diff(array_merge($donoA, $donoB), $idsRh) && !array_diff(array_merge($donoA, $donoB), $idsAdm), '(escopo) RH e Admin veem os PDIs de todos os gestores');
    $alheio = $donoB[0];
    $check($svc->detalhe($alheio, $gA, $hoje) === null && $svc->detalhe($alheio, $rh, $hoje) !== null, '(escopo) Detalhe de PDI alheio: o gestor recebe "não encontrado"; RH acessa');
    $check($svc->atualizarEstrutura($alheio, $postEdicao($alheio, ['pontos_fortes' => 'invasão']), $gA, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($alheio, 'invasão', false, $gA, $agora, $ip)['ok'] === false && $svc->registrarEvidencias($alheio, 'invasão', $gA, $agora, $ip)['ok'] === false && $svc->liberar($alheio, $gA, $agora, $ip)['ok'] === false && $svc->cancelar($alheio, 'invasão', $gA, $agora, $ip)['ok'] === false, '(escopo) O gestor A não altera, comenta, avalia, muda status nem cancela PDI do gestor B (mesmo com as três permissões)');
    $rowAlheio = $pdiRow($alheio);
    $check($rowAlheio['pontos_fortes'] !== 'invasão' && $rowAlheio['evidencias_evolucao'] !== 'invasão', '(escopo) Nada foi gravado no PDI alheio');
    // supervisor: a sinalização NÃO dá escopo global no PDI (só permissão individual + escopo por linha)
    $supId = $novoUsuario('Supervisor', 'viewer', $todasPerm);
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supId]);
    $supAtor = ['id' => $supId, 'role' => 'viewer', 'supervisor' => true];
    $pSup = (int)$svc->criar($post($cP, $supId), $rh, $agora, $ip)['id'];
    $check($svc->detalhe($alheio, $supAtor, $hoje) === null && $svc->atualizarEstrutura($alheio, $postEdicao($alheio, ['pontos_fortes' => 'invasão do supervisor']), $supAtor, $agora, $ip)['ok'] === false && $svc->adicionarAcompanhamento($alheio, 'invasão', false, $supAtor, $agora, $ip)['ok'] === false && $svc->liberar($alheio, $supAtor, $agora, $ip)['ok'] === false && $pdiRow($alheio)['pontos_fortes'] !== 'invasão do supervisor', '(escopo) Supervisor com as três permissões, mas que não é Admin/RH, NÃO abre nem altera PDI de outro gestor');
    $check($svc->detalhe($pSup, $supAtor, $hoje) !== null && array_map('intval', array_column($svc->listar([], $supAtor, $hoje)['itens'], 'id')) === [$pSup] && $svc->adicionarAcompanhamento($pSup, 'x', false, $supAtor, $agora, $ip)['ok'] === false /* rascunho */ && $svc->registrarEspacoColaborador($pSup, 'Momento', '', $supAtor, $agora, $ip)['ok'] === true, '(escopo) Supervisor acessa o PDI em que ele próprio é o gestor, e a lista traz só esse');
    $comoUsuario($supId, 'viewer');
    $_SESSION['user_is_supervisor'] = 1;
    $_GET = [];
    $htmlSupAlheio = $renderizar(static fn() => (new AdminPdisController())->show((string)$alheio));
    $htmlSupProprio = $renderizar(static fn() => (new AdminPdisController())->show((string)$pSup));
    $htmlSupLista = $renderizar(static fn() => (new AdminPdisController())->index());
    $_SESSION['user_is_supervisor'] = 0;
    $check(str_contains($htmlSupAlheio, 'PDI não encontrado') && !str_contains($htmlSupAlheio, 'ZZPDI Colaboradora') && str_contains($htmlSupProprio, 'Identificação') && str_contains($htmlSupLista, '/admin/pdis/' . $pSup . '"') && !str_contains($htmlSupLista, '/admin/pdis/' . $alheio . '"'), '(escopo/backend) Por URL direta: supervisor recebe "não encontrado" no PDI alheio, abre o próprio e a lista traz só o próprio');
    $rhSemPermLista = $svc->listar([], $semPerm, $hoje);
    $check($rhSemPermLista['ok'] === false && $svc->detalhe($alheio, $semPerm, $hoje) === null && $svc->detalhe($alheio, $rh, $hoje) !== null && $svc->detalhe($alheio, $adm, $hoje) !== null, '(escopo) Matriz: RH sem permissão individual NÃO acessa; RH com permissão e Admin (bypass central) acessam qualquer PDI');
    $filtroGestor = $svc->listar(['gestor' => (string)$gestorBId], $gA, $hoje);
    $check(count(array_intersect(array_map('intval', array_column($filtroGestor['itens'], 'id')), $donoB)) === 0, '(escopo) Filtrar por outro gestor não amplia o escopo do gestor A');
    $lf = $svc->listar(['status' => 'em_andamento', 'origem' => 'feedback', 'unidade' => $emp . '|ZPU1', 'cargo' => $cg1, 'empresa' => $emp, 'busca' => 'Ana'], $rh, $hoje);
    $check($lf['ok'] === true && array_diff(array_unique(array_column($lf['itens'], 'status')), ['em_andamento']) === [] && array_diff(array_unique(array_column($lf['itens'], 'snap_nome')), ['ZZPDI Ana Colaboradora']) === [], '(lista) Filtros de status, origem, unidade, cargo, empresa e colaborador combinam');
    $check($svc->listar(['status' => "x'; DROP TABLE pdis;--", 'busca' => "%_' OR 1=1 --", 'unidade' => "a|b' OR '1'='1"], $rh, $hoje)['ok'] === true && (int)$pdo->query('SELECT COUNT(*) FROM pdis')->fetchColumn() > 0, '(segurança) Filtros maliciosos são ignorados/parametrizados');

    // ---- controllers / páginas ---------------------------------------------------------------------------------------------------------------------------
    $exig = [['index', 'pdi.visualizar'], ['novo', 'pdi.gerenciar'], ['store', 'pdi.gerenciar'], ['show', 'pdi.visualizar'], ['editar', 'pdi.gerenciar'], ['atualizar', 'pdi.gerenciar'], ['acompanhar', 'pdi.acompanhar'], ['evidencias', 'pdi.acompanhar'], ['statusAcao', 'pdi.acompanhar'], ['liberar', 'pdi.acompanhar'], ['iniciar', 'pdi.acompanhar'], ['concluir', 'pdi.acompanhar'], ['reabrir', 'pdi.acompanhar'], ['cancelar', 'pdi.acompanhar'], ['manterDesligado', 'pdi.acompanhar']];
    $todosOk = true;
    foreach ($exig as [$metodo, $permissao]) {
        $corpo = $corpoDe(AdminPdisController::class, $metodo);
        $ehPost = !in_array($metodo, ['index', 'novo', 'show', 'editar'], true);
        $ok = str_contains($corpo, "'{$permissao}'") && ($ehPost ? (str_contains($corpo, 'entrarPost') || str_contains($corpo, 'csrfCheck')) : str_contains($corpo, "Authorization::requirePermissao('{$permissao}')"));
        if (!$ok) {
            fwrite(STDERR, "    controller sem exigência: {$metodo}\n");
        }
        $todosOk = $todosOk && $ok;
    }
    $check($todosOk, '(backend) Cada ação do controller exige a permissão individual e, nos POSTs, CSRF (entrarPost)');
    $corpoEntrar = $corpoDe(AdminPdisController::class, 'entrarPost');
    $check(str_contains($corpoEntrar, 'csrfCheck') && str_contains($corpoEntrar, 'exigirLogin') && !str_contains($corpoEntrar, 'requireRole') && !str_contains((string)file_get_contents(APP_PATH . '/controllers/AdminPdisController.php'), 'Auth::requireRole([') && str_contains($corpoDe(AdminPdisController::class, 'espacoColaborador'), 'entrarPost(null)'), '(backend) entrarPost valida sessão + permissão + CSRF sem Auth::requireRole (que liberaria supervisor); o espaço do colaborador aceita gerenciar OU acompanhar');
    $publicos = array_map(static fn(ReflectionMethod $m): string => $m->getName(), array_filter((new ReflectionClass(AdminPdisController::class))->getMethods(ReflectionMethod::IS_PUBLIC), static fn(ReflectionMethod $m): bool => $m->class === AdminPdisController::class));
    $check(!preg_grep('/meu|colaborador(?!.*espaco)|portal|autoatend/i', array_diff($publicos, ['espacoColaborador'])) && !preg_grep('/colaborador/i', array_map(static fn(string $r): string => $r, array_filter(file(BASE_PATH . '/index.php'), static fn(string $l): bool => str_contains($l, '/pdi') && !str_contains($l, '/admin/pdis')))), '(colaborador) Não existe rota, método ou portal do colaborador — o espaço dele é registrado por RH/Gestor');
    $fonteIndex = (string)file_get_contents(BASE_PATH . '/index.php');
    $regraNavPdi = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/pdis') { $regraNavPdi = $it['regra']; }
        }
    }
    $check($regraNavPdi === 'perm:pdi.visualizar' && str_contains($fonteIndex, "\$router->get('/admin/pdis/novo', [AdminPdisController::class, 'novo'])") && strpos($fonteIndex, "'/admin/pdis/novo'") < strpos($fonteIndex, "'/admin/pdis/{id}'"), '(rota/menu) A Central só oferece PDI sob a regra perm:pdi.visualizar (sidebar removida); /novo registrada antes de /{id}');

    $comoUsuario($gestorAId, 'viewer');
    $_GET = [];
    $htmlIdx = $renderizar(static fn() => (new AdminPdisController())->index());
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlIdx) && str_contains($htmlIdx, 'PDI — Plano de Desenvolvimento Individual') && str_contains($htmlIdx, 'Novo PDI') && str_contains($htmlIdx, 'ZZPDI Ana Colaboradora') && str_contains($htmlIdx, 'ações'), '(página) Lista renderiza com filtros, "Novo PDI" (tem pdi.gerenciar), colaborador e progresso das ações');
    $check(str_contains($htmlIdx, 'name="status"') && str_contains($htmlIdx, 'name="prazo"') && str_contains($htmlIdx, 'name="origem"') && str_contains($htmlIdx, 'name="unidade"') && str_contains($htmlIdx, 'name="cargo"') && str_contains($htmlIdx, 'name="gestor"') && !preg_match('/name="(area|setor)"/i', $htmlIdx), '(página) Filtros: status, prazo, colaborador, empresa, unidade, cargo, gestor, origem — sem Área');
    $_GET = ['busca' => 'Contrato Permissoes'];
    $htmlEsc = $renderizar(static fn() => (new AdminPdisController())->index());
    $check(!str_contains($htmlEsc, '/admin/pdis/' . $pAco . '"') && !str_contains($htmlEsc, '/admin/pdis/' . $pGer . '"'), '(página) O gestor A não vê, na lista, PDIs de outros gestores (nem filtrando por nome)');
    $_GET = ['busca' => 'ZZPDI Ana', 'contrato' => (string)$c1];
    $htmlNovo1 = $renderizar(static fn() => (new AdminPdisController())->novo());
    $check(str_contains($htmlNovo1, 'Novo PDI — Ana') || str_contains($htmlNovo1, 'ZZPDI Ana Colaboradora'), '(página) Novo PDI abre o formulário do contrato escolhido');
    $_GET = ['busca' => 'ZZPDI Ana'];
    $htmlNovo0 = $renderizar(static fn() => (new AdminPdisController())->novo());
    $check(str_contains($htmlNovo0, 'Selecionar') && str_contains($htmlNovo0, 'ZZPDI Ana Colaboradora') && !str_contains($htmlNovo0, $cpfSecreto) && !str_contains($htmlNovo0, 'Carla Desligada'), '(página) Busca de colaborador lista só contratos ativos, sem CPF');
    $_GET = [];
    $htmlShow = $renderizar(static fn() => (new AdminPdisController())->show((string)$idGA));
    $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlShow), '(página) Detalhe renderiza sem Warning/Notice/Fatal');
    $htmlShowOk = $renderizar(static fn() => (new AdminPdisController())->show((string)$pdD1));
    $check(str_contains($htmlShowOk, 'ZZPDI Bruno Colaborador') && str_contains($htmlShowOk, 'Contrato desligado'), '(página) O PDI do gestor A (contrato desligado) mostra o aviso e a decisão do RH');
    foreach (['Identificação', 'Desenvolvimento profissional', 'Competências a desenvolver', 'Plano de ação', 'Espaço do colaborador', 'Acompanhamentos', 'Evidências de evolução', 'Avaliação final', 'Histórico'] as $secao) {
        $check(str_contains($htmlShowOk, $secao), "(página) Detalhe exibe a seção \"{$secao}\"");
    }
    $check(str_contains($htmlShowOk, 'registrado por RH/Gestor em nome do colaborador') && str_contains($htmlShowOk, 'Manter o PDI'), '(página) O espaço do colaborador informa que é registrado por RH/Gestor em nome dele');
    $htmlAlheio = $renderizar(static fn() => (new AdminPdisController())->show((string)$alheio));
    $check(str_contains($htmlAlheio, 'PDI não encontrado') && !str_contains($htmlAlheio, 'ZZPDI Colaborador'), '(página) PDI de outro gestor: "não encontrado" (não vaza a existência)');
    $todosHtml = $htmlIdx . $htmlNovo0 . $htmlNovo1 . $htmlShowOk;
    $check(!str_contains($todosHtml, $cpfSecreto) && !str_contains($todosHtml, '529.982.247-25') && !preg_match('/codigo_pessoa|ZP' . $suffix . '/', $todosHtml) && !preg_match('/\son(click|submit|change|load)\s*=/i', $todosHtml), '(privacidade/CSP) Sem CPF, sem codigo_pessoa e sem handlers inline nas páginas');
    $comoUsuario($rhId, 'rh');
    $htmlEd = $renderizar(static fn() => (new AdminPdisController())->editar((string)$pdC));
    $check(str_contains($htmlEd, 'Editar plano') && str_contains($htmlEd, 'name="acoes[3][descricao]"') && !str_contains($htmlEd, 'acoes[4]') && str_contains($htmlEd, 'name="gestor_usuario_id"') && str_contains($htmlEd, 'Atenção') === false, '(página) Formulário do plano: 3 slots de ação (nunca 4), gestor explícito, origem e datas');
    $htmlDet = $renderizar(static fn() => (new AdminPdisController())->show((string)$pdC));
    $check(str_contains($htmlDet, 'Atenção (METADADOS)') && str_contains($htmlDet, 'cargo oficial atual') && str_contains($htmlDet, 'O registro da abertura foi preservado'), '(página) Divergência de cargo/unidade sinalizada com o snapshot preservado');

    echo "\nPDI_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM pdis WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador IN ($ph))");
        $ids->execute($criados['metadados']);
        $lista = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($lista !== []) {
            $in = implode(',', $lista);
            $pdo->exec("DELETE FROM pdi_eventos WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_acoes WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_competencias WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdis WHERE id IN ($in)");
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $lu = implode(',', array_map('intval', $criados['usuarios']));
        $pdo->exec("DELETE FROM pdi_eventos WHERE ator_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE autor_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM pdis WHERE gestor_usuario_id IN ($lu) OR criado_por_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
