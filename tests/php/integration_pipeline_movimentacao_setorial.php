<?php

/**
 * Integração — Etapa 3.2B.3: movimentação de candidatura com autorização setorial (PipelineMovimentacaoSetorialService).
 * Exercita a camada de autorização + gravação (não o 403 do controller). O verificador de permissão é injetado nos casos que isolam
 * escopo/transição da política que hoje bloqueia o Gestor; os casos com o verificador REAL provam que o bloqueio vigente se mantém.
 * Dados sintéticos ZZPM*, limpos em `finally`. Candidaturas.cpf é UNIQUE (CPFs distintos).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
PipelineStage::ensureRecruitmentLifecycle();
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
$mk = 'zzpm' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$vagas = []; $setores = []; $usuarios = []; $cands = [];

$mkUser = static function (string $suf, string $role) use (&$usuarios, $mk, $senhaHash): int {
    $id = User::create('ZZPM ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    return $usuarios[] = $id;
};
$mkSetor = static function (string $suf) use ($pdo, $mk, &$setores): int {
    $codigo = substr(strtoupper($suf[0]) . strtoupper(substr(md5($mk . $suf), 0, 5)), 0, 8);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, 'zz-teste')")->execute([$codigo, "ZZPM {$suf} {$mk}", "{$mk}-{$suf}"]);
    return $setores[] = (int)$pdo->lastInsertId();
};
$gerencia = static function (int $uid, array $ids) use ($pdo): void {
    foreach ($ids as $s) { $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$uid, $s]); }
};
$mkVaga = static function (string $suf, ?int $setor) use ($pdo, $mk, &$vagas): int {
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'ZZ', 'l', 1, ?)")->execute(["ZZPM {$suf} {$mk}", $setor]);
    return $vagas[] = (int)$pdo->lastInsertId();
};
$stage = static function (string $slug) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM pipeline_stages WHERE slug = ?');
    $st->execute([$slug]);
    return (int)$st->fetchColumn();
};
$mkCand = static function (int $vagaId, string $suf, string $slug) use ($pdo, $mk, &$cands, $stage): int {
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, stage_id) VALUES (?, ?, ?, '11999990000', ?, 'ZZ', 'ZZ', 'zz.pdf', ?)")
        ->execute([$vagaId, "ZZPM {$suf} {$mk}", "{$mk}.{$suf}@teste.local", (string)random_int(10000000000, 99999999999), $stage($slug)]);
    return $cands[] = (int)$pdo->lastInsertId();
};
$estado = static function (int $cid) use ($pdo): array {
    $c = $pdo->prepare('SELECT stage_id, observacoes FROM candidaturas WHERE id = ?'); $c->execute([$cid]); $r = $c->fetch(PDO::FETCH_ASSOC) ?: [];
    $h = $pdo->prepare('SELECT COUNT(*) FROM candidatura_historico WHERE candidatura_id = ?'); $h->execute([$cid]);
    $m = $pdo->prepare('SELECT COUNT(*) FROM pipeline_movements WHERE candidatura_id = ?'); $m->execute([$cid]);
    $s = $pdo->prepare('SELECT COUNT(*) FROM candidatura_stage_metadata WHERE candidatura_id = ?'); $s->execute([$cid]);
    return ['stage' => (int)$r['stage_id'], 'obs' => $r['observacoes'], 'hist' => (int)$h->fetchColumn(), 'mov' => (int)$m->fetchColumn(), 'meta' => (int)$s->fetchColumn()];
};
$permite = static fn(int $u, string $c): bool => true;
$nega = static fn(int $u, string $c): bool => false;
$soLeitura = static fn(int $u, string $c): bool => $c !== 'pipeline.movimentar';

try {
    $check(VagaSetorService::schemaSuportaSetor(), 'pré-requisito: migration 2026-11-24 aplicada');
    $gA = $mkUser('gA', 'gestor'); $gB = $mkUser('gB', 'gestor'); $gC = $mkUser('gC', 'gestor');
    $rh = $mkUser('rh', 'rh'); $admin = $mkUser('admin', 'admin'); $viewer = $mkUser('viewer', 'viewer');
    $s1 = $mkSetor('S1'); $s2 = $mkSetor('S2'); $s3 = $mkSetor('S3');
    $gerencia($gA, [$s1, $s2]); $gerencia($gB, [$s3]); $gerencia($viewer, [$s1]);
    $v1 = $mkVaga('V1', $s1); $v2 = $mkVaga('V2', $s2); $v3 = $mkVaga('V3', $s3); $vSem = $mkVaga('Sem', null);
    $eg = $stage('entrevista-gestor'); $te = $stage('testes'); $tri = $stage('triagem-rh'); $apr = $stage('aprovado'); $adm = $stage('admissao'); $rep = $stage('reprovado'); $ban = $stage('banco-de-talentos');
    $c1 = $mkCand($v1, 'c1', 'entrevista-gestor');
    $c2 = $mkCand($v2, 'c2', 'entrevista-gestor');
    $c3 = $mkCand($v3, 'c3', 'entrevista-gestor');
    $cs = $mkCand($vSem, 'cs', 'entrevista-gestor');
    $cTri = $mkCand($v1, 'ctri', 'triagem-rh');
    $metaTeste = ['test_name' => 'Teste ZZ', 'deadline' => date('Y-m-d', strtotime('+3 days'))];
    $svc = new PipelineMovimentacaoSetorialService($permite);

    // ---- 1. Caminho feliz do Gestor (escopo OK, transição operacional, permissão efetiva) ----
    $a0 = $estado($c1);
    $r = $svc->mover($gA, $c1, $te, $metaTeste, $eg);
    $a1 = $estado($c1);
    $check($r['ok'] === true && $a1['stage'] === $te && $a1['mov'] === $a0['mov'] + 1 && $a1['hist'] === $a0['hist'] + 1 && $a1['meta'] === 1, 'Gestor autorizado move candidatura do seu setor (etapa, movimento, histórico e metadados gravados)');
    $mv = $pdo->prepare('SELECT usuario_id, stage_anterior_id, stage_novo_id FROM pipeline_movements WHERE candidatura_id = ? ORDER BY id DESC LIMIT 1'); $mv->execute([$c1]); $mvr = $mv->fetch(PDO::FETCH_ASSOC);
    $check((int)$mvr['usuario_id'] === $gA && (int)$mvr['stage_anterior_id'] === $eg && (int)$mvr['stage_novo_id'] === $te, 'o movimento registra o usuário responsável, a etapa anterior e a nova');
    $r2 = $svc->mover($gA, $c2, $te, $metaTeste, $eg);
    $check($r2['ok'] === true, 'Gestor com vários setores move candidatura de outro dos seus setores');

    // ---- 2. Recusas: nada pode ser gravado ----
    $recusa = static function (string $desc, array $r, string $erro, int $cid) use ($check, $estado): void {};
    $casos = [
        ['candidatura de OUTRO setor', fn() => $svc->mover($gA, $c3, $te, $metaTeste, $eg), 'not_found', $c3],
        ['candidatura de vaga SEM setor', fn() => $svc->mover($gA, $cs, $te, $metaTeste, $eg), 'not_found', $cs],
        ['Gestor sem setores', fn() => $svc->mover($gC, $c3, $te, $metaTeste, $eg), 'not_found', $c3],
        ['ID inexistente', fn() => $svc->mover($gA, 999999999, $te, $metaTeste, $eg), 'not_found', $c3],
        ['ID forjado (0)', fn() => $svc->mover($gA, 0, $te, $metaTeste, $eg), 'forbidden', $c3],
        ['ID negativo', fn() => $svc->mover($gA, -4, $te, $metaTeste, $eg), 'forbidden', $c3],
        ['etapa de destino inexistente', fn() => $svc->mover($gB, $c3, 999999999, $metaTeste, $eg), 'not_found', $c3],
        ['usuário inexistente', fn() => $svc->mover(999999999, $c3, $te, $metaTeste, $eg), 'forbidden', $c3],
    ];
    foreach ($casos as [$desc, $fn, $erro, $cid]) {
        $antes = $estado($cid);
        $res = $fn();
        $check($res['ok'] === false && $res['error'] === $erro && $estado($cid) === $antes, "recusa ({$desc}): erro '{$erro}' e NENHUMA gravação (etapa, histórico, movimento, metadados)");
    }
    $antes = $estado($c3);
    $res = $svc->mover($gA, $c3, $te, $metaTeste, $eg);
    $check(!str_contains(json_encode($res), 'ZZPM') && !str_contains(json_encode($res), $mk), 'a resposta de recusa não revela dados do candidato de outro setor');

    // ---- 3. Permissão: leitura não autoriza mover; sem permissão; política real ----
    $antes = $estado($c1);
    $res = (new PipelineMovimentacaoSetorialService($soLeitura))->mover($gA, $c1, $eg, [], $te);
    $check($res['ok'] === false && $res['error'] === 'forbidden' && $estado($c1) === $antes, 'Gestor com pipeline.visualizar mas SEM pipeline.movimentar: negado, sem gravar');
    $res = (new PipelineMovimentacaoSetorialService($nega))->mover($gA, $c1, $eg, [], $te);
    $check($res['ok'] === false && $res['error'] === 'forbidden' && $estado($c1) === $antes, 'Gestor sem nenhuma permissão: negado');
    $idPerm = $pdo->query("SELECT id FROM permissoes WHERE codigo = 'pipeline.movimentar'")->fetchColumn();
    if ($idPerm) { $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$gB, $idPerm]); }
    $antesC3 = $estado($c3);
    $res = (new PipelineMovimentacaoSetorialService())->mover($gB, $c3, $te, $metaTeste, $eg);
    $check($res['ok'] === false && $res['error'] === 'forbidden' && $estado($c3) === $antesC3 && !Authorization::usuarioTemPermissao($gB, 'pipeline.movimentar'), 'com o verificador REAL: Gestor com a permissão GRAVADA em usuario_permissoes continua negado (a política não a torna efetiva) — nenhuma ampliação em produção');
    $check(!in_array('pipeline.movimentar', PermissaoPerfilPolicy::concediveis('gestor'), true) && !in_array('pipeline.movimentar', PermissaoPerfilPolicy::modeloRecomendado('gestor'), true), 'pipeline.movimentar segue fora do concedível e do modelo do Gestor');

    // ---- 4. Perfis: Viewer, RH, Admin ----
    $antes = $estado($cTri);
    $res = $svc->mover($viewer, $cTri, $eg, $metaTeste, $tri);
    $check($res['ok'] === false && $res['error'] === 'forbidden' && $estado($cTri) === $antes, 'Viewer (mesmo com setor gerenciado legado e permissão injetada) não move');
    $res = $svc->mover($rh, $cs, $te, $metaTeste, $eg);
    $check($res['ok'] === true && $estado($cs)['stage'] === $te, 'RH move qualquer candidatura, inclusive de vaga sem setor (fluxo global preservado)');
    $res = $svc->mover($admin, $c3, $apr, [], $eg);
    $check($res['ok'] === true && $estado($c3)['stage'] === $apr, 'Admin move para etapa privilegiada (Aprovado) sem restrição setorial');
    $check((new PipelineMovimentacaoSetorialService($nega))->mover($rh, $cTri, $te, $metaTeste, $tri)['ok'] === true, 'RH não depende de permissão individual (bypass por perfil já existente)');
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c3]);
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$tri, $cTri]);

    // ---- 5. Transições: operacionais x exclusivas do RH ----
    $privilegiadas = ['aprovado' => $apr, 'admissao' => $adm, 'reprovado' => $rep, 'banco-de-talentos' => $ban, 'triagem-rh' => $tri];
    foreach ($privilegiadas as $slug => $sid) {
        $antes = $estado($c2);
        $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c2]);
        $antes = $estado($c2);
        $res = $svc->mover($gA, $c2, $sid, ['observacoes' => 'x', 'confirm' => true, 'admission_date' => date('Y-m-d'), 'admission_notes' => 'x'], $eg);
        $check($res['ok'] === false && $res['error'] === 'transition' && $estado($c2) === $antes, "transição para '{$slug}' é exclusiva do RH: negada ao Gestor sem gravar nada");
    }
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$tri, $cTri]);
    $antes = $estado($cTri);
    $res = $svc->mover($gA, $cTri, $eg, ['interview_date' => date('Y-m-d'), 'interview_time' => '10:00', 'interview_location' => 'Sala'], $tri);
    $check($res['ok'] === false && $res['error'] === 'transition' && $estado($cTri) === $antes, 'o Gestor não retira candidato de etapa do RH (origem triagem-rh): negado');
    $check(PipelineMovimentacaoSetorialService::transicaoPermitidaAoGestor('entrevista-gestor', 'testes') && PipelineMovimentacaoSetorialService::transicaoPermitidaAoGestor('testes', 'entrevista-gestor') && !PipelineMovimentacaoSetorialService::transicaoPermitidaAoGestor(null, 'testes') && !PipelineMovimentacaoSetorialService::transicaoPermitidaAoGestor('testes', 'aprovado'), 'lista de transições operacionais do Gestor: só entre Entrevista Gestor e Testes; origem desconhecida = não');

    // ---- 6. Validação, metadados filtrados e efeitos indiretos ----
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c2]);
    $antes = $estado($c2);
    $res = $svc->mover($gA, $c2, $te, [], $eg);
    $check($res['ok'] === false && $res['error'] === 'validation' && $estado($c2) === $antes, 'transição válida sem os campos obrigatórios da etapa (Testes): rejeitada pela validação existente, sem gravar');
    $res = $svc->mover($gA, $c2, $te, $metaTeste + ['observacoes' => 'Texto interno ZZ', 'admission_notes' => 'adm ZZ', 'confirm' => true, 'interview_link' => ''], $eg);
    $obs = $pdo->prepare('SELECT observacoes FROM candidaturas WHERE id = ?'); $obs->execute([$c2]);
    $meta = $pdo->prepare('SELECT admission_notes FROM candidatura_stage_metadata WHERE candidatura_id = ?'); $meta->execute([$c2]);
    $check($res['ok'] === true && ($obs->fetchColumn() ?: '') === '' && ($meta->fetchColumn() ?: '') === '', 'metadados do Gestor filtrados: observações e dados de admissão enviados no POST NÃO são gravados (reservados ao RH)');

    // ---- 7. Concorrência / idempotência ----
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c1]);
    $antes = $estado($c1);
    $res = $svc->mover($gA, $c1, $te, $metaTeste, null);
    $check($res['ok'] === false && $res['error'] === 'validation' && $estado($c1) === $antes, 'etapa atual esperada é obrigatória para Gestor (sem ela, nada é gravado)');
    $ok1 = $svc->mover($gA, $c1, $te, $metaTeste, $eg);
    $apos = $estado($c1);
    $rep2 = $svc->mover($gA, $c1, $te, $metaTeste, $eg);
    $check($ok1['ok'] === true && $rep2['ok'] === false && $rep2['error'] === 'conflict' && $estado($c1) === $apos, 'requisição REPETIDA (mesma etapa esperada): conflito, sem segundo movimento/histórico');
    $outro = $svc->mover($gA, $c1, $eg, [], $eg);
    $check($outro['ok'] === false && $outro['error'] === 'conflict' && $estado($c1) === $apos, 'etapa alterada por outro usuário (esperada desatualizada): conflito sem gravar');

    // ---- 8. Alterações concorrentes de escopo entre a checagem e a gravação ----
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c1]);
    $antes = $estado($c1);
    $trocaSetor = new PipelineMovimentacaoSetorialService($permite, static function () use ($pdo, $v1, $s3): void { $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s3, $v1]); });
    $res = $trocaSetor->mover($gA, $c1, $te, $metaTeste, $eg);
    $check($res['ok'] === false && $res['error'] === 'not_found' && $estado($c1) === $antes, 'setor da vaga alterado APÓS a checagem inicial e ANTES da gravação: a reavaliação sob lock recusa e nada é gravado');
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s1, $v1]);
    $antes = $estado($c1);
    $perdeSetor = new PipelineMovimentacaoSetorialService($permite, static function () use ($pdo, $gA, $s1): void { $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ? AND setor_id = ?')->execute([$gA, $s1]); });
    $res = $perdeSetor->mover($gA, $c1, $te, $metaTeste, $eg);
    $check($res['ok'] === false && $res['error'] === 'not_found' && $estado($c1) === $antes, 'setor gerenciado REVOGADO durante a operação: recusado sob lock, nada gravado');
    $gerencia($gA, [$s1]);
    $semPerm = new PipelineMovimentacaoSetorialService(static fn(int $u, string $c): bool => false, null);
    $perdePerm = new PipelineMovimentacaoSetorialService(static function (int $u, string $c) use (&$chamadas): bool { $chamadas = ($chamadas ?? 0) + 1; return $chamadas === 1; });
    $antes = $estado($c1);
    $res = $perdePerm->mover($gA, $c1, $te, $metaTeste, $eg);
    $check($res['ok'] === false && $res['error'] === 'forbidden' && $estado($c1) === $antes, 'permissão revogada entre a checagem e a gravação: a reavaliação sob lock nega');

    // ---- 9. Lock: a vaga fica travada durante a gravação ----
    $lockProvado = null;
    $dispatcherEspiao = new class($lockProvado, $v1) extends RecruitmentEventDispatcher {
        public function __construct(private &$resultado, private int $vagaId) { parent::__construct(); }
        public function dispatchCandidateStageChanged(array $context, ?PDO $pdo = null, bool $deliverImmediately = true): ?int
        {
            $cfg = Config::app()['database'];
            try {
                $outra = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], $cfg['options']);
                $outra->exec('SET innodb_lock_wait_timeout = 1');
                $outra->prepare('UPDATE vagas SET setor_id = setor_id WHERE id = ?')->execute([$this->vagaId]);
                $this->resultado = false;
            } catch (\Throwable $e) {
                $this->resultado = str_contains($e->getMessage(), 'Lock wait timeout');
            }
            return parent::dispatchCandidateStageChanged($context, $pdo, $deliverImmediately);
        }
    };
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $c1]);
    $comLock = new PipelineMovimentacaoSetorialService($permite, null, new RecruitmentPipelineService($dispatcherEspiao));
    $res = $comLock->mover($gA, $c1, $te, $metaTeste, $eg);
    $check($res['ok'] === true && $lockProvado === true, 'durante a gravação a linha da vaga (e da candidatura) fica TRAVADA: outra conexão não consegue trocar o setor até o commit');

    // ---- 10. Admin/RH: fluxo inalterado (mesmo resultado de Candidatura::updateStage) ----
    $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?')->execute([$eg, $cTri]);
    $viaModel = Candidatura::updateStage($cTri, $te, $rh, $metaTeste, true, $eg);
    $check($viaModel['ok'] === true && $estado($cTri)['stage'] === $te, 'caminho legado Candidatura::updateStage (usado pelo controller atual) segue funcionando sem o guarda opcional');

    // ---- 11. Filtro do Kanban restrito ao escopo ----
    $escA = CandidaturaAcessoSetorialService::escopoNominal(['id' => $gA, 'role' => 'gestor']);
    $ids = array_map('intval', array_column(Candidatura::setoresDisponiveisEscopo($escA), 'id'));
    $check(in_array($s1, $ids, true) && in_array($s2, $ids, true) && !in_array($s3, $ids, true), 'filtro de setores do Gestor lista só os setores gerenciados');
    $check(Candidatura::setoresDisponiveisEscopo(EscopoSetorial::nenhum()) === [] && count(Candidatura::setoresDisponiveisEscopo(EscopoSetorial::global())) >= count(Candidatura::setoresDisponiveis()), 'sem escopo: lista vazia; global: igual ao filtro atual do RH');
} finally {
    $movIds = [];
    foreach ($cands as $cid) {
        $m = $pdo->prepare('SELECT id FROM pipeline_movements WHERE candidatura_id = ?'); $m->execute([$cid]);
        foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $mid) { $movIds[] = (int)$mid; }
    }
    foreach ($movIds as $mid) { $pdo->prepare('DELETE FROM webhook_events WHERE movement_id = ?')->execute([$mid]); }
    foreach ($vagas as $id) {
        $pdo->prepare('DELETE FROM candidaturas WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$id]);
    }
    foreach ($usuarios as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($setores as $id) { $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$id]); }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — movimentação setorial do Pipeline (Etapa 3.2B.3).\n";
