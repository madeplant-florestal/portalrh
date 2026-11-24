<?php

/**
 * Integração — Etapa 3.2A: setor responsável ESTRUTURADO da vaga (`vagas.setor_id` → `setores.id`).
 * Cobre criação direta (RH), vaga gerada de solicitação, edição, regularização histórica, preservação de candidaturas/benefícios/API
 * pública, setor renomeado/inativado, POST forjado, acesso não autorizado e a NÃO ampliação de acesso do Gestor/Viewer.
 * Requisições reais de controller via fixture_requisicao_http.php. Fixtures ZZVS*, limpas em `finally`.
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
$mk = 'zzvs' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$vagasCriadas = [];
$setoresCriados = [];
$solicitacoesCriadas = [];
$usuariosCriados = [];

$mkUser = static function (string $suf, string $role) use (&$usuariosCriados, $mk, $senhaHash): int {
    $id = User::create('ZZVS ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    $usuariosCriados[] = $id;
    return $id;
};
$mkSetor = static function (string $suf, ?string $codigo, int $ativo = 1) use ($pdo, $mk, &$setoresCriados): int {
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, ?, 'zz-teste')")
        ->execute([$codigo, "ZZVS {$suf} {$mk}", "{$mk}-{$suf}", $ativo]);
    return $setoresCriados[] = (int)$pdo->lastInsertId();
};
$http = static function (int $uid, string $role, string $controller, string $metodo, array $post = [], array $args = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, '0', $controller, $metodo, '[]', json_encode($args), $post === [] ? '' : json_encode($post)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
    return [$status, preg_replace('/@@STATUS=\d+@@\s*$/', '', $saida)];
};
$vagaPorTitulo = static function (string $titulo) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM vagas WHERE titulo = ?');
    $st->execute([$titulo]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$vaga = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM vagas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$post = static fn(string $titulo, array $extra = []): array => $extra + ['titulo' => $titulo, 'descricao' => 'Descrição ZZVS', 'requisitos' => 'Requisitos ZZVS', 'area' => 'Área livre ZZVS', 'local' => 'Local ZZVS', 'ativo' => '1'];
$mkCandidatura = static function (int $vagaId) use ($pdo, $mk): int {
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path) VALUES (?, ?, ?, '000', '00000000000', 'ZZ', 'ZZ', 'zz.pdf')")
        ->execute([$vagaId, 'ZZVS Candidato', "{$mk}.c@teste.local"]);
    return (int)$pdo->lastInsertId();
};

try {
    $check(VagaSetorService::schemaSuportaSetor(), 'pré-requisito: a migration 2026-11-24 foi aplicada no banco de teste');
    $rh = $mkUser('rh', 'rh');
    $admin = $mkUser('admin', 'admin');
    $gestor = $mkUser('gestor', 'gestor');
    $viewer = $mkUser('viewer', 'viewer');
    $setorA = $mkSetor('A', substr('A' . strtoupper(substr(md5($mk . 'a'), 0, 5)), 0, 8));
    $setorB = $mkSetor('B', substr('B' . strtoupper(substr(md5($mk . 'b'), 0, 5)), 0, 8));
    $setorInativo = $mkSetor('Inativo', substr('I' . strtoupper(substr(md5($mk . 'i'), 0, 5)), 0, 8), 0);
    $setorLegado = $mkSetor('Legado', null);
    $svc = new VagaSetorService();

    // ---- 1. Criação direta pelo RH ----------------------------------------------------------------------------------
    $t1 = "ZZVS Vaga Valida {$mk}";
    $benef = (int)$pdo->query('SELECT id FROM beneficios WHERE ativo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'store', $post($t1, ['setor_id' => (string)$setorA, 'beneficio_ids' => $benef ? [$benef] : []]));
    $v1 = $vagaPorTitulo($t1);
    if ($v1) { $vagasCriadas[] = (int)$v1['id']; }
    $check($v1 !== null && (int)$v1['setor_id'] === $setorA && $v1['area'] === 'Área livre ZZVS' && $v1['solicitacao_vaga_id'] === null, 'RH cria vaga com setor válido: setor_id gravado; área textual segue livre e independente; sem solicitação');
    $check($v1 !== null && ($benef === 0 || Vaga::beneficioIdsSelecionados((int)$v1['id']) === [$benef]), 'benefícios por vaga preservados na criação');
    [$st] = $http($admin, 'admin', 'AdminVagasController', 'store', $post("ZZVS Vaga Admin {$mk}", ['setor_id' => (string)$setorB]));
    $va = $vagaPorTitulo("ZZVS Vaga Admin {$mk}");
    if ($va) { $vagasCriadas[] = (int)$va['id']; }
    $check($va !== null && (int)$va['setor_id'] === $setorB, 'Admin também cria com setor válido');

    // ---- 2. Recusas: sem setor, inexistente, inativo, fora do cadastro oficial, forjado ------------------------------
    $rejeita = function (string $suf, array $extra, string $descricao, string $mensagem) use ($http, $rh, $post, $vagaPorTitulo, $mk, $check, &$vagasCriadas): void {
        $t = "ZZVS Rejeitada {$suf} {$mk}";
        [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'store', $post($t, $extra));
        $v = $vagaPorTitulo($t);
        if ($v) { $vagasCriadas[] = (int)$v['id']; }
        $check($v === null && $st === 200 && str_contains($corpo, $mensagem), "{$descricao}: vaga NÃO criada e a mensagem de validação aparece (não um erro 500)");
    };
    $rejeita('vazio', [], 'sem setor', 'Informe o setor responsável');
    $rejeita('vazio2', ['setor_id' => ''], 'setor vazio', 'Informe o setor responsável');
    $rejeita('inexistente', ['setor_id' => '999999999'], 'setor inexistente', 'Setor responsável inválido');
    $rejeita('inativo', ['setor_id' => (string)$setorInativo], 'setor inativo (inelegível)', 'Setor responsável inválido');
    $rejeita('legado', ['setor_id' => (string)$setorLegado], 'setor fora do cadastro oficial (sem código)', 'Setor responsável inválido');
    $rejeita('forjado', ['setor_id' => '1 OR 1=1'], 'setor forjado (SQL)', 'Informe o setor responsável');
    $rejeita('array', ['setor_id' => [(string)$setorA]], 'setor enviado como array', 'Informe o setor responsável');
    $rejeita('negativo', ['setor_id' => '-5'], 'setor negativo', 'Informe o setor responsável');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'store', $post("ZZVS Rejeitada csrf {$mk}", ['setor_id' => (string)$setorA, 'csrf' => 'invalido']));
    $check($st === 400 && $vagaPorTitulo("ZZVS Rejeitada csrf {$mk}") === null, 'CSRF inválido: 400 e nada criado');

    // ---- 3. Acesso não autorizado (Gestor/Viewer) ------------------------------------------------------------------------
    foreach ([[$gestor, 'gestor'], [$viewer, 'viewer']] as [$uid, $role]) {
        [$st1] = $http($uid, $role, 'AdminVagasController', 'store', $post("ZZVS Nao Autorizada {$role} {$mk}", ['setor_id' => (string)$setorA]));
        [$st2] = $http($uid, $role, 'AdminVagasController', 'update', $post('ZZVS Hack', ['setor_id' => (string)$setorB]), [(string)$v1['id']]);
        [$st3] = $http($uid, $role, 'AdminVagasController', 'edit', [], [(string)$v1['id']]);
        $check($st1 === 403 && $st2 === 403 && $st3 === 403 && $vagaPorTitulo("ZZVS Nao Autorizada {$role} {$mk}") === null && (int)$vaga((int)$v1['id'])['setor_id'] === $setorA && $vaga((int)$v1['id'])['titulo'] === $t1, "{$role}: store/update/edit de vaga = 403 e nada muda");
    }

    // ---- 4. Edição: preserva, não remove, troca com/sem candidaturas ----------------------------------------------------
    $cand = $mkCandidatura((int)$v1['id']);
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => (string)$setorA, 'area' => 'Outra área']), [(string)$v1['id']]);
    $x = $vaga((int)$v1['id']);
    $check($x['titulo'] === $t1 . ' (editada)' && (int)$x['setor_id'] === $setorA && $x['area'] === 'Outra área', 'edição preserva o setor e altera só o que foi pedido');
    $check((int)$pdo->query("SELECT COUNT(*) FROM candidaturas WHERE vaga_id = {$v1['id']} AND id = {$cand}")->fetchColumn() === 1, 'a candidatura da vaga é preservada na edição');
    [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => '']), [(string)$v1['id']]);
    $check((int)$vaga((int)$v1['id'])['setor_id'] === $setorA && str_contains($corpo, 'obrigatório'), 'remover o setor de vaga que já o possui é recusado');
    [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => (string)$setorB]), [(string)$v1['id']]);
    $check((int)$vaga((int)$v1['id'])['setor_id'] === $setorA && str_contains($corpo, 'candidatura'), 'trocar o setor de vaga COM candidaturas exige confirmação: sem ela, recusado e nada muda');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => (string)$setorB, 'confirmar_mudanca_setor' => '1']), [(string)$v1['id']]);
    $check((int)$vaga((int)$v1['id'])['setor_id'] === $setorB && (int)$pdo->query("SELECT COUNT(*) FROM candidaturas WHERE vaga_id = {$v1['id']}")->fetchColumn() === 1, 'com a confirmação explícita a troca é aplicada e a candidatura continua ligada à vaga');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => (string)$setorInativo, 'confirmar_mudanca_setor' => '1']), [(string)$v1['id']]);
    $check((int)$vaga((int)$v1['id'])['setor_id'] === $setorB, 'trocar para setor inativo/inelegível é recusado mesmo com confirmação');
    // vaga sem candidaturas e sem solicitação: troca direta (sem confirmação)
    [$st] = $http($admin, 'admin', 'AdminVagasController', 'update', $post("ZZVS Vaga Admin {$mk}", ['setor_id' => (string)$setorA]), [(string)$va['id']]);
    $check((int)$vaga((int)$va['id'])['setor_id'] === $setorA, 'vaga sem dependências: troca de setor sem confirmação');
    // forjar campos que não pertencem ao POST
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS Vaga Admin {$mk}", ['setor_id' => (string)$setorA, 'solicitacao_vaga_id' => '1', 'id' => '999999', 'created_at' => '2000-01-01']), [(string)$va['id']]);
    $y = $vaga((int)$va['id']);
    $check($y['solicitacao_vaga_id'] === null && (int)$y['id'] === (int)$va['id'] && substr((string)$y['created_at'], 0, 4) !== '2000', 'campos forjados no POST (solicitação, id, data) são ignorados');

    // ---- 5. Vaga histórica sem setor: preserva, regulariza, depois exige ----------------------------------------------------
    $hist = Vaga::create(['titulo' => "ZZVS Historica {$mk}", 'descricao' => 'd', 'requisitos' => 'r', 'area' => 'RH', 'local' => 'l', 'empresa_id' => null, 'ativo' => 1]);
    $vagasCriadas[] = $hist;
    $check($vaga($hist)['setor_id'] === null, 'vaga histórica nasce sem setor (nada é presumido)');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS Historica {$mk}", ['area' => 'RH', 'setor_id' => '']), [(string)$hist]);
    $check($vaga($hist)['setor_id'] === null && (int)$vaga($hist)['ativo'] === 1 && $vaga($hist)['area'] === 'RH', 'editar vaga histórica SEM informar setor é aceito e não apaga nada');
    [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'edit', [], [(string)$hist]);
    $check(str_contains($corpo, 'Vaga histórica sem setor identificado') && str_contains($corpo, 'regularizar'), 'a tela de edição sinaliza a vaga histórica sem setor');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS Historica {$mk}", ['area' => 'RH', 'setor_id' => (string)$setorLegado]), [(string)$hist]);
    $check($vaga($hist)['setor_id'] === null, 'regularização com setor fora do cadastro oficial é recusada');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS Historica {$mk}", ['area' => 'RH', 'setor_id' => (string)$setorA]), [(string)$hist]);
    $check((int)$vaga($hist)['setor_id'] === $setorA, 'regularização manual da vaga histórica com setor oficial');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS Historica {$mk}", ['area' => 'RH', 'setor_id' => '']), [(string)$hist]);
    $check((int)$vaga($hist)['setor_id'] === $setorA, 'depois de regularizada, o setor passa a ser obrigatório');

    // ---- 6. Vaga originada de solicitação ---------------------------------------------------------------------------------
    $cargoId = (int)$pdo->query('SELECT MIN(id) FROM cargos')->fetchColumn();
    $mkSolic = static function (int $setorId) use ($pdo, $cargoId, $admin, &$solicitacoesCriadas): int {
        $pdo->prepare("INSERT INTO solicitacoes_vaga (setor_id, quantidade_vagas, cargo_id, solicitante_usuario_id, tipo_vaga, tipo_contratacao, salario_previsto, previsto_orcamento, jornada_trabalho, escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio, urgencia, status_fluxo)
                       VALUES (?, 1, ?, ?, 'nova_posicao', 'clt', 3000, 0, '8h/dia', 'medio', 'zzvs', 'operacional', CURDATE(), 'media', 'aprovada')")
            ->execute([$setorId, $cargoId, $admin]);
        return $solicitacoesCriadas[] = (int)$pdo->lastInsertId();
    };
    $pub = new SolicitacaoVagaPublicacaoService();
    $sOk = $mkSolic($setorA);
    $r = $pub->gerarRascunho($sOk, $admin, '127.0.0.1');
    $vSol = $r['ok'] ? $vaga((int)$r['vaga_id']) : [];
    if ($r['ok']) { $vagasCriadas[] = (int)$r['vaga_id']; }
    $check($r['ok'] && (int)$vSol['setor_id'] === $setorA && (int)$vSol['solicitacao_vaga_id'] === $sOk, 'vaga gerada de solicitação herda o setor ESTRUTURADO da solicitação (setor_id), não o texto');
    $r2 = $pub->gerarRascunho($sOk, $admin, '127.0.0.1');
    $check($r2['ok'] && ($r2['ja_existia'] ?? false) && (int)$r2['vaga_id'] === (int)$r['vaga_id'], 'geração continua idempotente');
    [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($vSol['titulo'], ['setor_id' => (string)$setorB]), [(string)$vSol['id']]);
    $check((int)$vaga((int)$vSol['id'])['setor_id'] === $setorA && str_contains($corpo, 'solicitação'), 'vaga de solicitação: forjar outro setor é recusado (sem divergência silenciosa)');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($vSol['titulo'] . ' ed', ['setor_id' => '']), [(string)$vSol['id']]);
    $check((int)$vaga((int)$vSol['id'])['setor_id'] === $setorA && $vaga((int)$vSol['id'])['titulo'] === $vSol['titulo'] . ' ed', 'vaga de solicitação: sem setor no POST, o da solicitação é mantido (não é apagado)');
    [$st, $corpo] = $http($rh, 'rh', 'AdminVagasController', 'edit', [], [(string)$vSol['id']]);
    $check(str_contains($corpo, 'originou de uma solicitação') && str_contains($corpo, 'disabled'), 'tela de edição: setor travado para vaga originada de solicitação');
    // solicitação com setor inelegível: bloqueia
    $sLegado = $mkSolic($setorLegado);
    $r3 = $pub->gerarRascunho($sLegado, $admin, '127.0.0.1');
    $check(!$r3['ok'] && str_contains((string)$r3['error'], 'oficial') && (int)$pdo->query("SELECT COUNT(*) FROM vagas WHERE solicitacao_vaga_id = {$sLegado}")->fetchColumn() === 0, 'solicitação com setor fora do cadastro oficial: geração BLOQUEADA com mensagem clara (nenhuma vaga criada)');
    $sInativo = $mkSolic($setorInativo);
    $r4 = $pub->gerarRascunho($sInativo, $admin, '127.0.0.1');
    $check(!$r4['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM vagas WHERE solicitacao_vaga_id = {$sInativo}")->fetchColumn() === 0, 'solicitação com setor inativo: geração bloqueada (não escolhe outro setor)');
    // vaga de solicitação sem setor (histórica): o formulário regulariza com o setor da solicitação
    $sH = $mkSolic($setorB);
    $pdo->prepare('INSERT INTO vagas (solicitacao_vaga_id, titulo, descricao, requisitos, area, local, ativo) VALUES (?, ?, ?, ?, ?, ?, 0)')->execute([$sH, "ZZVS HistSol {$mk}", 'd', 'r', 'ZZ', 'l']);
    $hSol = (int)$pdo->lastInsertId();
    $vagasCriadas[] = $hSol;
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post("ZZVS HistSol {$mk}", ['setor_id' => '']), [(string)$hSol]);
    $check((int)$vaga($hSol)['setor_id'] === $setorB, 'vaga de solicitação histórica sem setor é regularizada com o setor da solicitação');

    // ---- 7. Setor renomeado, inativado e reconciliado: o vínculo histórico não se perde -------------------------------------
    $idAntes = (int)$vaga((int)$v1['id'])['setor_id'];
    $pdo->prepare('UPDATE setores SET nome = ?, descricao_oficial = ? WHERE id = ?')->execute(["ZZVS Renomeado {$mk}", 'RENOMEADO', $idAntes]);
    $pdo->prepare('UPDATE setores SET ativo = 0 WHERE id = ?')->execute([$idAntes]);
    $check((int)$vaga((int)$v1['id'])['setor_id'] === $idAntes && Vaga::find((int)$v1['id'])['setor_nome'] === "ZZVS Renomeado {$mk}", 'setor renomeado e inativado: a vaga mantém o vínculo (id estável) e mostra o nome novo');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'update', $post($t1 . ' (editada)', ['setor_id' => (string)$idAntes, 'area' => 'x2']), [(string)$v1['id']]);
    $check($vaga((int)$v1['id'])['area'] === 'x2' && (int)$vaga((int)$v1['id'])['setor_id'] === $idAntes, 'editar vaga cujo setor foi inativado depois: o setor atual é mantido (não força troca)');
    [$st] = $http($rh, 'rh', 'AdminVagasController', 'store', $post("ZZVS Nova Setor Inativo {$mk}", ['setor_id' => (string)$idAntes]));
    $check($vagaPorTitulo("ZZVS Nova Setor Inativo {$mk}") === null, 'o setor inativado deixa de ser elegível para vagas NOVAS');
    $pdo->prepare('UPDATE setores SET ativo = 1 WHERE id = ?')->execute([$idAntes]);
    $apagou = true;
    try {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$idAntes]);
    } catch (PDOException) {
        $apagou = false;
    }
    $check(!$apagou, 'FK RESTRICT: setor com vagas não pode ser apagado');

    // ---- 8. Diagnóstico de regularização (A/B/C/D) e regra única de aplicação ------------------------------------------------
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo) VALUES (?, 'd', 'r', ?, 'l', 1)")->execute(["ZZVS Texto {$mk}", "ZZVS Renomeado {$mk}"]);
    $vTexto = (int)$pdo->lastInsertId();
    $vagasCriadas[] = $vTexto; // C: a área textual coincide com o nome (atual) de um setor oficial ativo — sugestão NÃO comprovada
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo) VALUES (?, 'd', 'r', 'Sem Correspondencia ZZ', 'l', 1)")->execute(["ZZVS Nada {$mk}"]);
    $vNada = (int)$pdo->lastInsertId();
    $vagasCriadas[] = $vNada; // D
    $sB2 = $mkSolic($setorA);
    $pdo->prepare('INSERT INTO vagas (solicitacao_vaga_id, titulo, descricao, requisitos, area, local, ativo) VALUES (?, ?, ?, ?, ?, ?, 0)')->execute([$sB2, "ZZVS ViaSol {$mk}", 'd', 'r', 'texto qualquer', 'l']);
    $vViaSol = (int)$pdo->lastInsertId();
    $vagasCriadas[] = $vViaSol; // B
    $diag = [];
    foreach ($svc->diagnosticoRegularizacao() as $l) {
        $diag[$l['id']] = $l;
    }
    $check($diag[(int)$v1['id']]['classe'] === 'A' && $diag[$vViaSol]['classe'] === 'B' && (int)$diag[$vViaSol]['setor_sugerido'] === $setorA && $diag[$vNada]['classe'] === 'D', 'diagnóstico: A (setor estruturado), B (via solicitação), D (sem informação)');
    $check($diag[$vTexto]['classe'] === 'C' && str_contains($diag[$vTexto]['observacao'], 'NÃO COMPROVADA') && $diag[$vTexto]['setor_atual'] === null, 'diagnóstico: C — o texto sugere, não comprova, e NADA é aplicado');
    $check(array_keys($diag[$vNada]) === array_keys($diag[$vTexto]) && !array_intersect(array_keys($diag[$vNada]), ['cpf', 'nome', 'email', 'telefone', 'pdf_path']), 'o relatório não carrega dados pessoais (só contagem de candidaturas)');
    $check($diag[(int)$v1['id']]['candidaturas'] === 1, 'o relatório informa a quantidade de candidaturas da vaga');
    $mine = [$vTexto, $vNada, $vViaSol];
    $sim = $svc->regularizarClasseB(false, $mine);
    $check(count($sim['aplicaveis']) === 1 && $sim['aplicadas'] === 0 && $vaga($vViaSol)['setor_id'] === null, 'regularização em simulação (padrão): lista a classe B e NÃO grava');
    $ap = $svc->regularizarClasseB(true, $mine);
    $check($ap['aplicadas'] === 1 && (int)$vaga($vViaSol)['setor_id'] === $setorA && $vaga($vTexto)['setor_id'] === null && $vaga($vNada)['setor_id'] === null, 'aplicar: SOMENTE a classe B recebe setor; C (texto) e D permanecem sem setor');
    $check($svc->regularizarClasseB(true, $mine)['aplicadas'] === 0, 'a aplicação é idempotente');

    // ---- 9. API pública, Carta Proposta e o fluxo público preservados -----------------------------------------------------------
    $payload = VagasPublicasApiService::montarPayload(Vaga::allActive(), 'https://exemplo.test');
    $item = $payload['vagas'][0] ?? [];
    $check($payload['ok'] === true && array_keys($item) === ['id', 'titulo', 'area', 'empresa', 'local', 'resumo', 'url'], 'API pública: o contrato do payload NÃO mudou e não expõe setor_id nem dado interno');
    $json = json_encode($payload);
    $check(!str_contains((string)$json, 'setor_id') && !str_contains((string)$json, 'setor_nome'), 'API pública não vaza setor_id/setor_nome');
    $check(isset(Vaga::find((int)$v1['id'])['area']) && Vaga::find((int)$v1['id'])['titulo'] === $t1 . ' (editada)', 'Vaga::find mantém os campos usados pela Carta Proposta (area, título) — o fluxo da Carta Proposta é coberto por integration_carta_proposta');

    // ---- 10. Sem migration (coluna ausente): comportamento anterior ----------------------------------------------------------
    VagaSetorService::forcarSchemaParaTeste(false);
    $semMigration = $svc->validarParaCriacao('');
    $edSem = $svc->validarParaEdicao(['id' => 1, 'setor_id' => null, 'solicitacao_vaga_id' => null], '', false);
    VagaSetorService::forcarSchemaParaTeste(null);
    $check($semMigration['ok'] && $edSem['ok'], 'coluna ausente (migration pendente): o fluxo anterior segue funcionando, sem exigir setor');

    // ---- 11. Nenhuma ampliação de acesso (Gestor/Viewer) -----------------------------------------------------------------------
    $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) SELECT ?, id FROM permissoes WHERE ativo = 1')->execute([$gestor]);
    $setoresGer = $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)');
    $setoresGer->execute([$gestor, $setorA]);
    $alvos = [['AdminVagasController', 'create'], ['AdminPipelineController', 'index']]; // as LISTAGENS passaram a abrir com permissão efetiva na Etapa 3.2C.1 (integration_recrutamento_leitura_setorial)
    $abertos = array_filter($alvos, fn(array $a): bool => $http($gestor, 'gestor', $a[0], $a[1])[0] !== 403);
    $check($abertos === [], 'Gestor com TODAS as permissões e o setor da vaga continua 403 na criação de vagas e no Pipeline (o setor_id não libera nada)');
    $check(PermissaoPerfilPolicy::classificar('gestor', 'vagas.criar') === PermissaoPerfilPolicy::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'candidaturas.editar') === PermissaoPerfilPolicy::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'pipeline.movimentar') === PermissaoPerfilPolicy::INCOMPATIVEL, 'a política segue bloqueando escrita de vagas/candidaturas e o Pipeline para o Gestor');
    $check(PermissaoPerfilPolicy::concediveis('gestor') === PermissaoPerfilPolicy::permissoesPermitidas('gestor') && count(PermissaoPerfilPolicy::concediveis('gestor')) === 19, 'conjunto concedível do Gestor: 17 anteriores + vagas.visualizar e candidaturas.visualizar (Etapa 3.2C.1)');
    $check($http($viewer, 'viewer', 'AdminVagasController', 'create')[0] === 403 && $http($viewer, 'viewer', 'AdminCandidaturasController', 'index')[0] === 403, 'Viewer: sem acesso à criação de vagas nem a Candidaturas');
} finally {
    foreach ($vagasCriadas as $id) {
        $pdo->prepare('DELETE FROM candidaturas WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vaga_beneficios WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$id]);
    }
    $pdo->prepare("DELETE FROM vagas WHERE titulo LIKE ?")->execute(["ZZVS %{$mk}%"]);
    foreach ($solicitacoesCriadas as $id) {
        $pdo->prepare('DELETE FROM solicitacao_vaga_auditoria WHERE solicitacao_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
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
echo "\nOK — setor responsável estruturado da vaga (Etapa 3.2A).\n";
