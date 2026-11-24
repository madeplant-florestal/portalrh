<?php

/**
 * Integração — Etapa 3.2C.1: listagens de Vagas e Candidaturas para o GESTOR, nos ENDPOINTS REAIS (controller + view), com permissão
 * individual gravada e efetiva, escopo por `usuario_setores_gerenciados` → `vagas.setor_id` e projeção sem dados pessoais.
 * Detalhe, documentos, Pipeline, escrita e a preservação de Admin/RH/Viewer também são exercitados. Dados sintéticos ZZLS*, limpos em `finally`.
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
$mk = 'zzls' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$vagas = []; $setores = []; $usuarios = [];
$idPerm = [];
foreach ($pdo->query("SELECT id, codigo FROM permissoes WHERE codigo IN ('vagas.visualizar','candidaturas.visualizar','pipeline.visualizar','pipeline.movimentar','candidaturas.editar','vagas.editar')") as $r) {
    $idPerm[$r['codigo']] = (int)$r['id'];
}

$mkUser = static function (string $suf, string $role, array $perms = [], array $setoresIds = []) use (&$usuarios, $mk, $senhaHash, $pdo, $idPerm): int {
    $id = User::create('ZZLS ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    $usuarios[] = $id;
    foreach ($perms as $p) { $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $idPerm[$p]]); }
    foreach ($setoresIds as $s) { $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$id, $s]); }
    return $id;
};
$mkSetor = static function (string $suf) use ($pdo, $mk, &$setores): int {
    $codigo = substr(strtoupper($suf[0]) . strtoupper(substr(md5($mk . $suf), 0, 5)), 0, 8);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, 'zz-teste')")->execute([$codigo, "ZZLS Setor{$suf} {$mk}", "{$mk}-{$suf}"]);
    return $setores[] = (int)$pdo->lastInsertId();
};
$mkVaga = static function (string $suf, ?int $setor, int $ativo = 1) use ($pdo, $mk, &$vagas): int {
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'ZZ', 'l', ?, ?)")->execute(["ZZLS Vaga{$suf} {$mk}", $ativo, $setor]);
    return $vagas[] = (int)$pdo->lastInsertId();
};
$stage = static function (string $slug) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM pipeline_stages WHERE slug = ?'); $st->execute([$slug]);
    return (int)$st->fetchColumn();
};
$mkCand = static function (int $vagaId, string $nome, string $slug = 'triagem-rh', ?string $criada = null) use ($pdo, $mk, $stage): array {
    $cpf = (string)random_int(10000000000, 99999999999);
    $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $nome)) . "@{$mk}.teste.local";
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, stage_id, observacoes, created_at) VALUES (?, ?, ?, '11987654321', ?, 'CargoSecretoZZ', 'ExperienciaSecretaZZ', 'zz.pdf', ?, 'ObsInternaZZ', ?)")
        ->execute([$vagaId, $nome, $email, $cpf, $stage($slug), $criada ?? date('Y-m-d H:i:s')]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'cpf' => $cpf];
};
$http = static function (int $uid, string $role, string $controller, string $metodo, array $get = [], array $args = [], array $post = []): array {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $st = preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
    return [$st, preg_replace('/@@STATUS=\d+@@\s*$/', '', $saida)];
};

try {
    $check(VagaSetorService::schemaSuportaSetor(), 'pré-requisito: migration 2026-11-24 aplicada');
    $s1 = $mkSetor('1'); $s2 = $mkSetor('2'); $s3 = $mkSetor('3');
    $leitura = ['vagas.visualizar', 'candidaturas.visualizar'];
    $gA = $mkUser('gA', 'gestor', $leitura, [$s1, $s2]);
    $gB = $mkUser('gB', 'gestor', $leitura, [$s3]);
    $gC = $mkUser('gC', 'gestor', $leitura, []);
    $gSemPerm = $mkUser('gSemPerm', 'gestor', [], [$s1]);
    $gSoVagas = $mkUser('gSoVagas', 'gestor', ['vagas.visualizar'], [$s1]);
    $gSoCand = $mkUser('gSoCand', 'gestor', ['candidaturas.visualizar'], [$s1]);
    $gPipe = $mkUser('gPipe', 'gestor', array_merge($leitura, ['pipeline.visualizar', 'pipeline.movimentar', 'candidaturas.editar', 'vagas.editar']), [$s1]);
    $rh = $mkUser('rh', 'rh'); $admin = $mkUser('admin', 'admin');
    $viewer = $mkUser('viewer', 'viewer', ['candidaturas.visualizar'], [$s1]); // permissão gravada e setor legado: nada muda
    $viewer2 = $mkUser('viewer2', 'viewer');

    $v1 = $mkVaga('1', $s1); $v2 = $mkVaga('2', $s2); $v3 = $mkVaga('3', $s3); $vSem = $mkVaga('Sem', null);
    $c1 = $mkCand($v1, 'Alice Alfa ZZLS'); $c1b = $mkCand($v1, 'Alicia Aldeia ZZLS', 'entrevista-gestor', '2026-01-15 10:00:00');
    $c2 = $mkCand($v2, 'Bruno Beta ZZLS');
    $c3 = $mkCand($v3, 'Carla Gama ZZLS'); $c3b = $mkCand($v3, 'Alice Alfa ZZLS'); // mesmo nome em OUTRO setor
    $cs = $mkCand($vSem, 'Dario Delta ZZLS');
    $paginacao = [];
    for ($i = 1; $i <= 22; $i++) { $paginacao[] = $mkCand($v1, sprintf('Pagina%02d ZZLS', $i), 'triagem-rh', '2026-02-' . sprintf('%02d', $i) . ' 09:00:00'); }
    $todosPessoais = [$c1, $c1b, $c2, $c3, $c3b, $cs];

    // ---- 1. Candidaturas: Gestor A (vários setores) ----
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'Alic']);
    [$st2, $h2] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'Bruno']);
    $check($st === 200 && $st2 === 200 && str_contains($h, 'Alice Alfa ZZLS') && str_contains($h, 'Alicia Aldeia ZZLS') && str_contains($h2, 'Bruno Beta ZZLS'), 'Gestor A (setores 1 e 2): vê candidaturas das vagas dos seus setores (vaga do setor 1 e do setor 2)');
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $check(!str_contains($h, 'Carla Gama ZZLS') && !str_contains($h, 'Dario Delta ZZLS') && str_contains($h, '25 candidatura(s) encontrada(s)'), 'Gestor A NÃO vê candidatura de outro setor nem de vaga sem setor; o contador soma só as 25 do escopo');
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'Alice Alfa']);
    $check(substr_count($h, 'Alice Alfa ZZLS') >= 1 && !str_contains($h, "ZZLS Setor3 {$mk}") && str_contains($h, '1 candidatura(s) encontrada(s)'), 'busca por nome: o mesmo nome em outro setor não aparece nem soma no contador (1 resultado)');

    // ---- 2. Dados pessoais ausentes (HTML inteiro) ----
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $vazou = [];
    foreach ([$c1, $c1b, $c2] as $c) {
        foreach (['email', 'cpf'] as $k) { if (str_contains($h, $c[$k])) { $vazou[] = $k; } }
    }
    foreach (['11987654321', '(11) 98765-4321', 'CargoSecretoZZ', 'ExperienciaSecretaZZ', 'ObsInternaZZ', 'zz.pdf', 'indicacao', 'Indicação'] as $txt) { if (str_contains($h, $txt)) { $vazou[] = $txt; } }
    $check($vazou === [], 'HTML do Gestor sem e-mail, CPF, telefone, cargo/experiência, observações, currículo nem indicação' . ($vazou ? ' (vazou: ' . implode(',', $vazou) . ')' : ''));
    $check(!preg_match('#/admin/candidaturas/\d+#', $h) && !str_contains($h, '/download') && !str_contains($h, '/documentos') && !str_contains($h, '/admin/pipeline"') && !str_contains($h, '<form action') && !str_contains($h, 'atualizar') && !str_contains($h, '/indicacao'), 'sem links/ações para detalhe, PDF, documentos, Kanban nem formulários de escrita');
    $check(str_contains($h, 'data-testid="filtros-candidaturas-setorial"') && str_contains($h, "ZZLS Setor1 {$mk}") && !str_contains($h, "ZZLS Setor3 {$mk}"), 'filtro de setores lista só os setores gerenciados; filtro de vagas só as vagas do escopo');
    $check(!str_contains($h, "ZZLS Vaga3 {$mk}") && !str_contains($h, "ZZLS VagaSem {$mk}") && str_contains($h, "ZZLS Vaga1 {$mk}"), 'opções do filtro de vaga não revelam vagas de outros setores');

    // ---- 3. Gestor B, C e filtros manipulados ----
    [$st, $h] = $http($gB, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $check($st === 200 && str_contains($h, 'Carla Gama ZZLS') && !str_contains($h, 'Bruno Beta ZZLS') && !str_contains($h, 'Alicia Aldeia ZZLS') && !str_contains($h, 'Pagina01'), 'Gestor B (setor 3): só o próprio setor');
    [$st, $h] = $http($gC, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $check($st === 200 && str_contains($h, 'data-testid="vazio-candidaturas"') && str_contains($h, '0 candidatura(s)') && !str_contains($h, 'Alice Alfa'), 'Gestor C (sem setores): lista vazia com estado vazio, nenhum nome');
    foreach ([['vaga_id' => $v3], ['setor_id' => $s3], ['vaga_id' => $vSem], ['vaga_id' => 0, 'setor_id' => '1 OR 1=1'], ['vaga_id' => '-1'], ['stage_id' => '999999'], ['busca' => "' OR '1'='1"], ['busca' => '%'], ['data_de' => '2000-01-01', 'vaga_id' => $v3], ['setor_id' => [$s3]], ['vaga_id' => [$v3]]] as $i => $get) {
        [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', $get);
        $check($st === 200 && !str_contains($h, 'Carla Gama ZZLS') && !str_contains($h, 'Dario Delta ZZLS'), 'filtro manipulado #' . ($i + 1) . ' (' . json_encode($get) . ') não amplia o escopo');
    }
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['vaga_id' => $v1, 'stage_id' => $stage('entrevista-gestor')]);
    $check($st === 200 && str_contains($h, 'Alicia Aldeia ZZLS') && !str_contains($h, 'Alice Alfa ZZLS') && !str_contains($h, 'Pagina01'), 'filtros por vaga e etapa combinam e funcionam dentro do escopo');
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['data_de' => '2026-01-01', 'data_ate' => '2026-01-31']);
    $check($st === 200 && str_contains($h, 'Alicia Aldeia ZZLS') && !str_contains($h, 'Pagina01'), 'filtro por período (data final inclusiva) dentro do escopo');

    // ---- 4. Paginação e contadores ----
    [$st, $p1] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['vaga_id' => $v1]);
    [$st2, $p2] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['vaga_id' => $v1, 'page' => 2]);
    $check(str_contains($p1, '24 candidatura(s) encontrada(s)') && str_contains($p1, 'Página 1 de 2') && str_contains($p2, 'Página 2 de 2') && $st === 200 && $st2 === 200, 'contador e paginação refletem só o escopo (24 candidaturas da vaga 1 em 2 páginas)');
    [$st, $pFim] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['page' => 99999, 'busca' => 'ZZLS']);
    $check($st === 200 && !str_contains($pFim, 'Carla Gama ZZLS') && !str_contains($pFim, 'Dario Delta ZZLS'), 'página muito além do fim não revela registros externos');
    $contaB = preg_match('/(\d+) candidatura\(s\) encontrada/', (string)$http($gB, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS'])[1], $mm) ? (int)$mm[1] : -1;
    $check($contaB === 2, 'contador do Gestor B soma só as candidaturas do setor 3');

    // ---- 5. Permissões: sem/parcial/Pipeline ----
    $c403 = static fn(array $r): bool => $r[0] === 403;
    $check($c403($http($gSemPerm, 'gestor', 'AdminCandidaturasController', 'index')) && $c403($http($gSemPerm, 'gestor', 'AdminVagasController', 'index')), 'Gestor SEM permissão: 403 em Vagas e Candidaturas');
    $check($http($gSoVagas, 'gestor', 'AdminVagasController', 'index')[0] === 200 && $c403($http($gSoVagas, 'gestor', 'AdminCandidaturasController', 'index')), 'só vagas.visualizar: abre Vagas, não Candidaturas');
    $check($http($gSoCand, 'gestor', 'AdminCandidaturasController', 'index')[0] === 200 && $c403($http($gSoCand, 'gestor', 'AdminVagasController', 'index')), 'só candidaturas.visualizar: abre Candidaturas, não Vagas (permissões independentes)');
    $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$gA]);
    $check($c403($http($gA, 'gestor', 'AdminCandidaturasController', 'index')) && $c403($http($gA, 'gestor', 'AdminVagasController', 'index')), 'permissão REVOGADA: acesso cai imediatamente (403)');
    foreach ($leitura as $p) { $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$gA, $idPerm[$p]]); }
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s3, $v2]);
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $check(!str_contains($h, 'Bruno Beta ZZLS'), 'vaga que muda de setor: o Gestor anterior deixa de ver as candidaturas dela');
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s2, $v2]);
    $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ? AND setor_id = ?')->execute([$gA, $s2]);
    [$st, $h] = $http($gA, 'gestor', 'AdminCandidaturasController', 'index', ['busca' => 'ZZLS']);
    $check(!str_contains($h, 'Bruno Beta ZZLS') && str_contains($h, 'Alice Alfa ZZLS'), 'setor gerenciado removido: some só o que era daquele setor');
    $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$gA, $s2]);

    // ---- 6. Vagas ----
    [$st, $h] = $http($gA, 'gestor', 'AdminVagasController', 'index');
    $check($st === 200 && str_contains($h, "ZZLS Vaga1 {$mk}") && str_contains($h, "ZZLS Vaga2 {$mk}") && !str_contains($h, "ZZLS Vaga3 {$mk}") && !str_contains($h, "ZZLS VagaSem {$mk}"), 'Vagas: Gestor A vê as dos seus setores; não vê a de outro setor nem a SEM setor');
    $check(!str_contains($h, '/admin/vagas/editar') && !str_contains($h, '/excluir') && !str_contains($h, '/publicar') && !str_contains($h, '/admin/vagas/novo') && !str_contains($h, '<form action') && !str_contains($h, '/admin/solicitacoes-vaga/'), 'Vagas (Gestor): sem Editar, Excluir, Publicar, Nova vaga nem links de solicitação');
    [$st, $h] = $http($gC, 'gestor', 'AdminVagasController', 'index');
    $check($st === 200 && !str_contains($h, "ZZLS Vaga1 {$mk}") && !str_contains($h, 'ZZLS Vaga'), 'Vagas: Gestor sem setores recebe lista vazia');
    [$st, $h] = $http($gB, 'gestor', 'AdminVagasController', 'index');
    $check(str_contains($h, "ZZLS Vaga3 {$mk}") && !str_contains($h, "ZZLS Vaga1 {$mk}"), 'Vagas: Gestor B só a do seu setor');

    // ---- 7. Rotas de detalhe, documentos, pipeline e escrita seguem bloqueadas ao Gestor (mesmo com permissões de escrita gravadas) ----
    $bloq = [
        ['AdminCandidaturasController', 'show', [(string)$c1['id']]], ['AdminCandidaturasController', 'download', [(string)$c1['id']]],
        ['AdminCandidaturasController', 'downloadDocumento', [(string)$c1['id'], '1']], ['AdminCandidaturasController', 'uploadDocumento', [(string)$c1['id']]],
        ['AdminCandidaturasController', 'update', [(string)$c1['id']]], ['AdminCandidaturasController', 'updateIndicacao', [(string)$c1['id']]],
        ['AdminPipelineController', 'index', []], ['AdminPipelineController', 'move', []],
        ['AdminVagasController', 'create', []], ['AdminVagasController', 'store', []], ['AdminVagasController', 'edit', [(string)$v1]], ['AdminVagasController', 'update', [(string)$v1]],
        ['AdminVagasController', 'delete', [(string)$v1]], ['AdminVagasController', 'publicar', [(string)$v1]],
    ];
    $abertos = [];
    foreach ($bloq as [$ctl, $met, $args]) {
        foreach ([$gA, $gPipe] as $u) {
            $r = $http($u, 'gestor', $ctl, $met, [], $args, in_array($met, ['store', 'update', 'delete', 'publicar', 'move', 'uploadDocumento', 'updateIndicacao'], true) ? ['x' => '1'] : []);
            if ($r[0] !== 403) { $abertos[] = "{$ctl}::{$met}=" . $r[0]; }
        }
    }
    $check($abertos === [], 'detalhe, download, documentos, upload, atualização, indicação, Pipeline e toda escrita de vagas: 403 ao Gestor, inclusive com pipeline.*/candidaturas.editar/vagas.editar gravadas' . ($abertos ? ' — ABERTOS: ' . implode(', ', array_unique($abertos)) : ''));
    $check(!Authorization::usuarioTemPermissao($gPipe, 'pipeline.visualizar') && !Authorization::usuarioTemPermissao($gPipe, 'pipeline.movimentar') && !Authorization::usuarioTemPermissao($gPipe, 'candidaturas.editar') && !Authorization::usuarioTemPermissao($gPipe, 'vagas.editar'), 'permissões de Pipeline, edição de candidaturas e edição de vagas gravadas NÃO são efetivas para o Gestor');

    // ---- 8. Viewer, RH, Admin ----
    $check($http($viewer, 'viewer', 'AdminCandidaturasController', 'index')[0] === 403 && $http($viewer2, 'viewer', 'AdminCandidaturasController', 'index')[0] === 403, 'Viewer: Candidaturas segue 403 (inclusive com candidaturas.visualizar gravada e setor gerenciado legado)');
    [$st, $h] = $http($viewer, 'viewer', 'AdminVagasController', 'index');
    $check($st === 200 && str_contains($h, "ZZLS Vaga3 {$mk}") && str_contains($h, "ZZLS VagaSem {$mk}") && str_contains($h, '/admin/vagas/editar'), 'Viewer: Vagas idêntico ao de antes (lista global, mesmos controles) — sem ampliar e sem reduzir');
    $check($http($viewer, 'viewer', 'AdminCandidaturasController', 'show', [], [(string)$c1['id']])[0] === 403 && $http($viewer, 'viewer', 'AdminVagasController', 'edit', [], [(string)$v1])[0] === 403, 'Viewer: detalhe de candidatura e edição de vaga seguem 403');
    [$st, $h] = $http($rh, 'rh', 'AdminCandidaturasController', 'index', ['vaga_id' => $v3]);
    $check($st === 200 && str_contains($h, 'Carla Gama ZZLS') && str_contains($h, $c3['email']) && str_contains($h, 'Detalhes') && str_contains($h, 'Ver Kanban'), 'RH: listagem completa e global preservada (e-mail, detalhes, Kanban)');
    [$st, $h] = $http($admin, 'admin', 'AdminVagasController', 'index');
    $check($st === 200 && str_contains($h, "ZZLS Vaga3 {$mk}") && str_contains($h, "ZZLS VagaSem {$mk}") && str_contains($h, '/admin/vagas/novo') && str_contains($h, '/admin/vagas/editar') && str_contains($h, '/excluir'), 'Admin: Vagas global com todas as ações');
    [$st, $h] = $http($admin, 'admin', 'AdminCandidaturasController', 'show', [], [(string)$c3['id']]);
    $check($st === 200 && str_contains($h, 'Carla Gama ZZLS'), 'Admin: detalhe completo preservado');
    $rSup = (function () use ($http, $v1) { $proc = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', '1', 'admin', '1', 'AdminVagasController', 'index', '[]', '[]', ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); $o = (string)stream_get_contents($pipes[1]); proc_close($proc); return $o; })();
    $check(str_contains($rSup, '@@STATUS=200@@') && str_contains($rSup, "ZZLS Vaga3 {$mk}"), 'Supervisor Geral: Vagas global (fluxo legado)');

    // ---- 9. Política, modelo e concessão automática ----
    $P = PermissaoPerfilPolicy::class;
    $check(count($P::concediveis('gestor')) === 19 && in_array('vagas.visualizar', $P::concediveis('gestor'), true) && in_array('candidaturas.visualizar', $P::concediveis('gestor'), true), 'concedíveis do Gestor: 19 (17 + vagas.visualizar + candidaturas.visualizar)');
    $check(count($P::modeloRecomendado('gestor')) === 15 && !in_array('vagas.visualizar', $P::modeloRecomendado('gestor'), true) && !in_array('candidaturas.visualizar', $P::modeloRecomendado('gestor'), true), 'modelo recomendado do Gestor INALTERADO (15; as novas permissões não nascem marcadas)');
    $check(!in_array('pipeline.visualizar', $P::concediveis('gestor'), true) && !in_array('pipeline.movimentar', $P::concediveis('gestor'), true) && !in_array('candidaturas.editar', $P::concediveis('gestor'), true) && !in_array('vagas.editar', $P::concediveis('gestor'), true) && !in_array('vagas.criar', $P::concediveis('gestor'), true) && !in_array('vagas.excluir', $P::concediveis('gestor'), true) && !in_array('vagas.publicar', $P::concediveis('gestor'), true), 'Pipeline, edição de candidaturas e escrita de vagas continuam fora do concedível');
    $novo = $mkUser('gNovo', 'gestor', [], [$s1]);
    $check(!Authorization::usuarioTemPermissao($novo, 'vagas.visualizar') && !Authorization::usuarioTemPermissao($novo, 'candidaturas.visualizar') && $http($novo, 'gestor', 'AdminVagasController', 'index')[0] === 403, 'Gestor novo (perfil + setor, sem concessão individual) não recebe nada automaticamente');
    $check(!$P::podeReceberPermissao('viewer', 'candidaturas.visualizar') && !$P::podeReceberPermissao('viewer', 'vagas.visualizar'), 'Viewer não pode receber as novas permissões (política do Viewer intacta)');

    // ---- 10. Serviço de decisão: falha nunca vira global ----
    $S = RecrutamentoLeituraSetorialService::class;
    $check($S::paraVagas(0, false, 'gestor')['modo'] === 'negado' && $S::paraCandidaturas(999999999, false, 'gestor')['modo'] === 'negado' && $S::paraCandidaturas($viewer, false, 'viewer')['modo'] === 'negado' && $S::paraVagas($gC, false, 'gestor')['escopo']->enxergaAlgo() === false, 'decisão: ator inválido/inexistente/viewer em candidaturas = negado; Gestor sem setores = escopo vazio (nunca global)');
    $check($S::paraVagas($gSemPerm, false, 'gestor')['modo'] === 'negado' && $S::paraCandidaturas($gSoVagas, false, 'gestor')['modo'] === 'negado' && $S::paraVagas($gSoCand, false, 'gestor')['modo'] === 'negado' && $S::paraCandidaturas($gSoCand, false, 'gestor')['modo'] === 'setorial' && $S::paraVagas($gPipe, false, 'gestor')['modo'] === 'setorial', 'decisão (serviço, independente do gate do controller): sem a permissão específica = negado; com ela = setorial; permissões de Pipeline/edição gravadas não somam nada')
    ;
    $check($S::paraVagas($gB, false, 'gestor')['escopo']->ehRestrito() && $S::paraVagas($gB, false, 'admin')['modo'] === 'legado', 'decisão: o Gestor usa escopo restrito; a role legada só vale para Admin/RH/Viewer (gate já existente)');
} finally {
    foreach ($vagas as $id) {
        $pdo->prepare('DELETE FROM candidaturas WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$id]);
    }
    foreach ($usuarios as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($setores as $id) { $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$id]); }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — leitura setorial de Vagas e Candidaturas nos endpoints reais (Etapa 3.2C.1).\n";
