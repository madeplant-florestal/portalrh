<?php

/**
 * Integração — Etapa 3.2B.1: CONSULTA de Vagas e Candidaturas com escopo setorial (somente leitura).
 * Testa a CAMADA DE CONSULTA (Vaga::all/find com escopo; Candidatura::listarEscopo/contarEscopo/acharEscopo) e, separadamente, que os
 * endpoints continuam bloqueados para Gestor/Viewer (a liberação ponta a ponta é da Etapa 3.2C). Os isolamentos abaixo são provados
 * sobre os DADOS retornados (não por um 403 de outra barreira). Fixtures ZZVC*, limpas em `finally`.
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
$mk = 'zzvc' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$vagasCriadas = [];
$setoresCriados = [];
$usuariosCriados = [];

$mkUser = static function (string $suf, string $role) use (&$usuariosCriados, $mk, $senhaHash): int {
    $id = User::create('ZZVC ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    $usuariosCriados[] = $id;
    return $id;
};
$mkSetor = static function (string $suf, int $ativo = 1) use ($pdo, $mk, &$setoresCriados): array {
    $codigo = substr(strtoupper($suf[0]) . strtoupper(substr(md5($mk . $suf), 0, 5)), 0, 8);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, ?, 'zz-teste')")
        ->execute([$codigo, "ZZVC {$suf} {$mk}", "{$mk}-{$suf}", $ativo]);
    $id = (int)$pdo->lastInsertId();
    $setoresCriados[] = $id;
    return [$id, $codigo];
};
$gerencia = static function (int $uid, array $setorIds) use ($pdo): void {
    foreach ($setorIds as $s) {
        $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$uid, $s]);
    }
};
$mkVaga = static function (string $suf, ?int $setorId) use ($pdo, $mk, &$vagasCriadas): int {
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'Texto livre ZZVC', 'l', 1, ?)")
        ->execute(["ZZVC {$suf} {$mk}", $setorId]);
    return $vagasCriadas[] = (int)$pdo->lastInsertId();
};
$mkCand = static function (int $vagaId, string $nome, string $email, string $cpf) use ($pdo): int {
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path) VALUES (?, ?, ?, '11999990000', ?, 'ZZ', 'ZZ', 'zz.pdf')")
        ->execute([$vagaId, $nome, $email, $cpf]);
    return (int)$pdo->lastInsertId();
};
$http = static function (int $uid, string $role, string $controller, string $metodo, array $args = []): int {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, '0', $controller, $metodo, '[]', json_encode($args), ''],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    return preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
};
$ids = static fn(array $linhas): array => array_map('intval', array_column($linhas, 'id'));

try {
    $check(VagaSetorService::schemaSuportaSetor(), 'pré-requisito: migration 2026-11-24 aplicada no banco de teste');
    $gA = $mkUser('gestorA', 'gestor');
    $gB = $mkUser('gestorB', 'gestor');
    $gC = $mkUser('gestorC', 'gestor');
    $rh = $mkUser('rh', 'rh');
    $admin = $mkUser('admin', 'admin');
    $viewer = $mkUser('viewer', 'viewer');
    [$s1] = $mkSetor('S1');
    [$s2] = $mkSetor('S2');
    [$s3] = $mkSetor('S3');
    [$sInativo] = $mkSetor('Inativo', 0);
    $gerencia($gA, [$s1, $s2]);
    $gerencia($gB, [$s3]);
    // $gC: nenhum setor gerenciado

    $v1 = $mkVaga('V1', $s1);
    $v2 = $mkVaga('V2', $s2);
    $v3 = $mkVaga('V3', $s3);
    $vSem = $mkVaga('SemSetor', null);
    $vInat = $mkVaga('SetorInativo', $sInativo);

    // candidaturas.cpf é UNIQUE: a "mesma pessoa" em duas vagas se repete por nome/e-mail, não por CPF.
    $cpf = static fn(): string => (string)random_int(10000000000, 99999999999);
    $cpfComum = $cpf();
    $c1 = $mkCand($v1, "ZZVC Ana {$mk}", "{$mk}.ana@teste.local", $cpfComum);   // mesma pessoa (nome/e-mail) em dois setores
    $c3 = $mkCand($v3, "ZZVC Ana {$mk}", "{$mk}.ana@teste.local", $cpf());
    $c2 = $mkCand($v2, "ZZVC Bruno {$mk}", "{$mk}.bruno@teste.local", $cpf());
    $cSem = $mkCand($vSem, "ZZVC Carla {$mk}", "{$mk}.carla@teste.local", $cpf());
    $cInat = $mkCand($vInat, "ZZVC Dario {$mk}", "{$mk}.dario@teste.local", $cpf());

    $escA = EscopoSetorial::resolver(['id' => $gA, 'role' => 'gestor']);
    $escB = EscopoSetorial::resolver(['id' => $gB, 'role' => 'gestor']);
    $escC = EscopoSetorial::resolver(['id' => $gC, 'role' => 'gestor']);
    $escRh = EscopoSetorial::resolver(['id' => $rh, 'role' => 'rh']);
    $escAdmin = EscopoSetorial::resolver(['id' => $admin, 'role' => 'admin']);
    $meus = array_merge([$v1, $v2, $v3, $vSem, $vInat]);
    $soMinhas = static fn(array $lista) => array_values(array_intersect($lista, $meus));

    // ---- 1. Vagas: listagem ----
    $lA = $soMinhas($ids(Vaga::all($escA)));
    sort($lA);
    $check($lA === [$v1, $v2], 'Gestor A (setores 1 e 2) lista SOMENTE as vagas dos seus setores');
    $check($soMinhas($ids(Vaga::all($escB))) === [$v3], 'Gestor B (setor 3) lista somente a vaga do setor 3');
    $check($ids(Vaga::all($escC)) === [], 'Gestor C (sem setores) não lista nenhuma vaga (vazio, nunca "sem restrição")');
    $check(!in_array($vSem, $ids(Vaga::all($escA)), true) && !in_array($vInat, $ids(Vaga::all($escA)), true), 'vaga sem setor_id e vaga de setor fora do escopo nunca aparecem ao Gestor');
    $check(count($soMinhas($ids(Vaga::all($escRh)))) === 5 && count($soMinhas($ids(Vaga::all($escAdmin)))) === 5 && count($soMinhas($ids(Vaga::all()))) === 5, 'RH, Admin e chamada sem escopo (legado) continuam globais');
    $check($ids(Vaga::all(EscopoSetorial::nenhum())) === [], 'escopo NENHUM (sem autorização) não lista nada');

    // ---- 2. Vagas: por ID ----
    $check(Vaga::find($v1, $escA) !== null && Vaga::find($v2, $escA) !== null, 'Gestor A abre por ID as vagas dos seus setores');
    $check(Vaga::find($v3, $escA) === null && Vaga::find($vSem, $escA) === null && Vaga::find($vInat, $escA) === null, 'acesso por ID a vaga de outro setor / sem setor / setor inativo fora do escopo = null');
    $check(Vaga::find($v1, $escC) === null && Vaga::find($v1, $escB) === null, 'Gestor sem setores e Gestor de outro setor não abrem a vaga por ID');
    $check(Vaga::find($v3, $escRh) !== null && Vaga::find($vSem, $escAdmin) !== null && Vaga::find($vSem) !== null, 'Admin/RH/legado abrem qualquer vaga, inclusive sem setor');
    $check(Vaga::find(999999999, $escA) === null, 'ID inexistente = null');

    // ---- 3. Candidaturas: listagem ----
    $cand = static fn(EscopoSetorial $e, array $f = []): array => array_values(array_intersect($ids(Candidatura::listarEscopo($e, $f)), [$c1, $c2, $c3, $cSem, $cInat]));
    $cA = $cand($escA);
    sort($cA);
    $check($cA === [$c1, $c2], 'Gestor A vê as candidaturas das vagas dos seus setores (c1, c2) e NÃO a do setor 3 nem a de vaga sem setor');
    $check($cand($escB) === [$c3], 'Gestor B vê apenas a candidatura do setor 3');
    $check($cand($escC) === [] && Candidatura::contarEscopo($escC) === 0, 'Gestor C (sem setores) não vê nenhuma candidatura e o contador é 0');
    $check(count($cand($escRh)) === 5 && count($cand($escAdmin)) === 5, 'RH e Admin enxergam todas (global)');
    $check($cand(EscopoSetorial::nenhum()) === [], 'escopo NENHUM não vê nada');

    // ---- 4. Mesma pessoa em dois setores: só a candidatura autorizada ----
    $check(in_array($c1, $cand($escA), true) && !in_array($c3, $cand($escA), true), 'candidato com candidaturas em setores diferentes: Gestor A vê só a candidatura do seu setor (sem ficha global)');
    $check($cand($escB) === [$c3], 'Gestor B vê só a candidatura do setor 3 da mesma pessoa');
    $porNome = $cand($escA, ['busca' => 'ZZVC Ana ' . $mk]);
    $check($porNome === [$c1], 'busca por nome não traz a candidatura do mesmo nome em setor não autorizado');
    $check($cand($escB, ['busca' => "Bruno {$mk}"]) === [], 'busca pelo nome de candidato de outro setor não retorna nada');
    $check($cand($escA, ['busca' => '%']) === [], 'curingas LIKE da busca são escapados (% não vira "tudo")');

    // ---- 5. Filtros manipulados nunca ampliam ----
    $check($cand($escA, ['vaga_id' => $v3]) === [], 'filtro vaga_id de outro setor não amplia o escopo');
    $check($cand($escA, ['setor_id' => $s3]) === [], 'filtro setor_id de outro setor não amplia o escopo');
    $check($cand($escA, ['setor_id' => $s1]) === [$c1], 'filtro setor_id do próprio setor restringe (AND com o escopo)');
    $check($cand($escA, ['setor_id' => Candidatura::SETOR_NAO_INFORMADO]) === $cA || $cand($escA, ['setor_id' => Candidatura::SETOR_NAO_INFORMADO]) !== [$cSem], 'sentinela "sem setor" não revela candidaturas de vaga sem setor ao Gestor');
    $check($cand($escA, ['vaga_id' => $v1, 'stage_id' => 999999]) === [], 'filtros combinam (AND)');
    $check($cand($escRh, ['vaga_id' => $v3]) === [$c3], 'RH continua filtrando livremente');

    // ---- 6. Paginação e contadores ----
    $totalA = Candidatura::contarEscopo($escA);
    $todasA = Candidatura::listarEscopo($escA, [], 500, 0);
    $check($totalA === count($todasA), 'contador = total listado (mesmo filtro+escopo)');
    $pag1 = Candidatura::listarEscopo($escA, [], 1, 0);
    $pag2 = Candidatura::listarEscopo($escA, [], 1, 1);
    $check(count($pag1) === 1 && count($pag2) === 1 && $pag1[0]['id'] !== $pag2[0]['id'] || $totalA < 2, 'paginação percorre só registros do escopo');
    $paginas = [];
    for ($o = 0; $o < $totalA + 3; $o++) {
        foreach (Candidatura::listarEscopo($escA, [], 1, $o) as $r) { $paginas[] = (int)$r['id']; }
    }
    $check(!in_array($c3, $paginas, true) && !in_array($cSem, $paginas, true) && !in_array($cInat, $paginas, true), 'nenhuma página (inclusive além do fim) revela candidaturas de fora do escopo');
    $check(Candidatura::contarEscopo($escB, ['busca' => 'ZZVC Ana ' . $mk]) === 1, 'contador com busca respeita o escopo');

    // ---- 7. Por ID ----
    $check(Candidatura::acharEscopo($escA, $c1) !== null && Candidatura::acharEscopo($escA, $c2) !== null, 'Gestor A abre por ID candidaturas do seu escopo');
    $check(Candidatura::acharEscopo($escA, $c3) === null && Candidatura::acharEscopo($escA, $cSem) === null && Candidatura::acharEscopo($escA, $cInat) === null, 'candidatura de outro setor, de vaga sem setor ou de setor inativo fora do escopo = null por ID');
    $check(Candidatura::acharEscopo($escC, $c1) === null && Candidatura::acharEscopo($escB, $c1) === null, 'Gestor sem setores / de outro setor: null por ID');
    $check(Candidatura::acharEscopo($escRh, $cSem) !== null && Candidatura::acharEscopo($escAdmin, $c3) !== null, 'Admin/RH abrem qualquer candidatura por ID');
    $check(Candidatura::acharEscopo($escA, 0) === null && Candidatura::acharEscopo($escA, 999999999) === null, 'ID inválido/inexistente = null (indistinguível de "fora do escopo")');

    // ---- 8. Dados pessoais: projeção segura ----
    $linha = Candidatura::acharEscopo($escA, $c1) ?? [];
    $proibidos = ['cpf', 'email', 'telefone', 'pdf_path', 'experiencia', 'cargo_pretendido', 'indicacao_colaborador', 'indicacao_colaborador_nome', 'observacoes', 'interview_location', 'interview_link', 'admission_notes'];
    $vazados = array_values(array_filter($proibidos, static fn(string $k): bool => array_key_exists($k, $linha)));
    $check($linha !== [] && $vazados === [], 'projeção segura do Gestor não inclui CPF, e-mail, telefone, currículo, indicação nem observações' . ($vazados ? ' (vazou: ' . implode(',', $vazados) . ')' : ''));
    $check(isset($linha['nome'], $linha['vaga_titulo']) && array_key_exists('stage_nome', $linha) && (int)$linha['vaga_setor_id'] === $s1, 'projeção traz o necessário à listagem: nome, vaga, etapa e setor da vaga');
    $json = json_encode(Candidatura::listarEscopo($escA, []));
    $check(!str_contains((string)$json, $cpfComum) && !str_contains((string)$json, "{$mk}.ana@teste.local"), 'nenhum CPF/e-mail aparece no resultado serializado (ex.: endpoint JSON)');

    // ---- 9. Mudança de setor da vaga / de setores gerenciados reflete imediatamente (sem cache) ----
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s3, $v1]);
    $check(!in_array($c1, $cand($escA), true) && in_array($c1, $cand($escB), true) && Vaga::find($v1, $escA) === null, 'vaga que muda de setor deixa de aparecer ao Gestor anterior e passa ao novo (sem cache residual)');
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s1, $v1]);
    $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ? AND setor_id = ?')->execute([$gA, $s2]);
    $escA2 = EscopoSetorial::resolver(['id' => $gA, 'role' => 'gestor']);
    $check($cand($escA2) === [$c1] && Vaga::find($v2, $escA2) === null, 'remover um setor gerenciado retira imediatamente vagas e candidaturas daquele setor');
    $gerencia($gA, [$s2]);

    // ---- 10. Setor inativo/renomeado: vínculo por id ----
    $pdo->prepare('UPDATE setores SET ativo = 0 WHERE id = ?')->execute([$s2]);
    $pdo->prepare('UPDATE setores SET nome = ? WHERE id = ?')->execute(["ZZVC Renomeado {$mk}", $s1]);
    $check(in_array($c2, $cand($escA), true) && in_array($c1, $cand($escA), true), 'setor inativado/renomeado: o vínculo (por id/código) continua e o Gestor mantém o acesso do escopo');
    $pdo->prepare('UPDATE setores SET ativo = 1 WHERE id = ?')->execute([$s2]);

    // ---- 11. Endpoints seguem bloqueados (a liberação é da 3.2C) ----
    $bloqueios = [
        ['AdminVagasController', 'index', []], ['AdminVagasController', 'edit', [(string)$v1]],
        ['AdminCandidaturasController', 'index', []], ['AdminCandidaturasController', 'show', [(string)$c1]],
        ['AdminCandidaturasController', 'download', [(string)$c1]], ['AdminCandidaturasController', 'downloadDocumento', [(string)$c1, '1']],
        ['AdminPipelineController', 'index', []], ['AdminPipelineController', 'move', []],
    ];
    $todos403 = true;
    foreach ($bloqueios as [$ctl, $met, $args]) {
        $st = $http($gA, 'gestor', $ctl, $met, $args);
        if ($st !== 403) { $todos403 = false; fwrite(STDERR, "    {$ctl}::{$met} (gestor) => {$st}\n"); }
    }
    $check($todos403, 'Gestor com setores continua recebendo 403 em vagas/candidaturas/documentos/pipeline (nenhum acesso novo nesta etapa)');
    $viewer403 = true;
    foreach ([['AdminCandidaturasController', 'index', []], ['AdminCandidaturasController', 'show', [(string)$c1]], ['AdminPipelineController', 'index', []]] as [$ctl, $met, $args]) {
        if ($http($viewer, 'viewer', $ctl, $met, $args) !== 403) { $viewer403 = false; }
    }
    $check($viewer403, 'Viewer segue sem acesso nominal a candidaturas/pipeline');
    $check($http($rh, 'rh', 'AdminCandidaturasController', 'index') === 200 && $http($rh, 'rh', 'AdminVagasController', 'index') === 200 && $http($rh, 'rh', 'AdminPipelineController', 'index') === 200, 'RH segue com acesso normal às telas');

    // ---- 12. Sem ampliação de permissões / API pública ----
    foreach (['pipeline.visualizar', 'vagas.editar', 'candidaturas.editar', 'pipeline.movimentar'] as $perm) { // leitura (vagas/candidaturas.visualizar) passou a concedível na 3.2C.1
        $check(!in_array($perm, PermissaoPerfilPolicy::concediveis('gestor'), true) && !in_array($perm, PermissaoPerfilPolicy::modeloRecomendado('gestor'), true), "permissão {$perm} continua fora do concedível e do modelo do Gestor");
    }
    $ativas = Vaga::allActive();
    $check(!array_key_exists('setor_nome', $ativas[0] ?? []) , 'Vaga::allActive (base da divulgação pública) não ganhou campos de setor');
} finally {
    foreach ($vagasCriadas as $id) {
        $pdo->prepare('DELETE FROM candidaturas WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$id]);
    }
    foreach ($usuariosCriados as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($setoresCriados as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$id]);
    }
}

// Fora do try: exit dentro dele pularia o finally e deixaria dados de teste no banco.
if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — consulta setorial de Vagas e Candidaturas (Etapa 3.2B.1).\n";
