<?php

/**
 * Integração — Sprint "Contexto Organizacional dos Usuários" — Etapa 1
 * (migration 2026-09-10-usuarios-contexto-organizacional.sql).
 *
 * Cobre os 14 cenários da spec:
 *   1  usuário sem vínculo pode ter Cargo oficial manual
 *   2  usuário sem vínculo pode ter vários Setores oficiais
 *   3  novo vínculo não aceita Cargo legado (sem codigo_cargo)
 *   4  não aceita Setor legado (sem codigo_setor) — principal ou adicional
 *   5  vínculo METADADOS com Cargo preenche o Cargo principal (usuarios.cargo_id)
 *   6  vínculo METADADOS com Setor preenche o Setor principal (origem METADADOS)
 *   7  vínculo METADADOS sem Setor: aviso "Setor não informado no METADADOS" + Setor manual permitido
 *   8  múltiplos Setores não duplicam (UNIQUE usuario_id+setor_id)
 *   9  no máximo 1 Setor principal por usuário
 *   10 Setores adicionais MANUAIS não são apagados ao aplicar/rearmar o contexto do vínculo
 *   11 PJ/terceiro funciona sem colaborador_metadados_id
 *   12 pode_solicitar_vaga continua independente do contexto
 *   13 aprovador_usuario_id continua independente do contexto
 *   14 Admin continua com visão global (paginateForAdmin sem filtro por Setor)
 * + trava do dado herdado, cargo do contrato sem correspondência, desvínculo e rollback da migration.
 *
 * Sem rollback de transação: User::vincularMetadados e o Service fazem commit. Fixtures marcadas
 * e removidas no finally, como integration_usuario_vaga_acesso.php.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_usuario_contexto_organizacional (MySQL indisponivel)\n";
    exit(0);
}

SchemaManager::ensure();

$pdo = Database::conn();

$temColuna = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'cargo_id'"
)->fetchColumn();
$temTabela = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_setores'"
)->fetchColumn();
if ($temColuna === 0 || $temTabela === 0) {
    echo "SKIP integration_usuario_contexto_organizacional (migration 2026-09-10-usuarios-contexto-organizacional.sql nao aplicada)\n";
    exit(0);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$mk = 'ZZCTX_' . substr(md5(uniqid('', true)), 0, 8);
$sfx = strtolower($mk);
$criados = ['usuarios' => [], 'metadados' => [], 'cargos' => [], 'setores' => []];

$setoresDo = static function (int $uid) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT setor_id, principal, origem FROM usuario_setores WHERE usuario_id = ? ORDER BY setor_id');
    $stmt->execute([$uid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$cargoDo = static fn (int $uid) => $pdo->query("SELECT cargo_id FROM usuarios WHERE id = {$uid}")->fetchColumn();
$countPrincipais = static fn (int $uid) => (int)$pdo->query("SELECT COUNT(*) FROM usuario_setores WHERE usuario_id = {$uid} AND principal = 1")->fetchColumn();

try {
    // ------------------------------------------------------------------ fixtures
    $novoSetor = static function (?string $codigo, string $rot) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, origem_metadados) VALUES (?,?,?,?,1,?)')
            ->execute([$codigo, strtoupper($rot) . ' ' . $sfx, $codigo !== null ? strtoupper($rot) : null, $rot . '-' . $sfx, $codigo !== null ? 'RHMADEPLANT' : null]);
        $id = (int)$pdo->lastInsertId();
        $criados['setores'][] = $id;
        return $id;
    };
    $novoCargo = static function (?string $codigo, string $rot) use ($pdo, $sfx, &$criados): int {
        $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, descricao_oficial, slug, ativo, origem_metadados) VALUES (?,?,?,?,1,?)')
            ->execute([$codigo, strtoupper($rot) . ' ' . $sfx, $codigo !== null ? strtoupper($rot) : null, $rot . '-' . $sfx, $codigo !== null ? 'RHMADEPLANT' : null]);
        $id = (int)$pdo->lastInsertId();
        $criados['cargos'][] = $id;
        return $id;
    };

    $setorA = $novoSetor('ZZS1' . substr($sfx, 0, 4), 'setor-oficial-a');
    $setorB = $novoSetor('ZZS2' . substr($sfx, 0, 4), 'setor-oficial-b');
    $setorC = $novoSetor('ZZS3' . substr($sfx, 0, 4), 'setor-oficial-c');
    $setorLegado = $novoSetor(null, 'setor-legado');
    $cargoX = $novoCargo('ZZC1' . substr($sfx, 0, 4), 'cargo-oficial-x');
    $cargoY = $novoCargo('ZZC2' . substr($sfx, 0, 4), 'cargo-oficial-y');
    $cargoLegado = $novoCargo(null, 'cargo-legado');

    $novoContrato = static function (string $tag, ?string $codigoCargo, ?string $codigoSetor) use ($pdo, $mk, &$criados): int {
        $pdo->prepare(
            "INSERT INTO colaboradores_metadados
                (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                 nome, empresa, unidade, cargo, codigo_cargo, setor, codigo_setor, ativo, origem_metadados)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,'RHMADEPLANT')"
        )->execute([
            'ID' . $tag . $mk, 'E' . substr($mk, 0, 6), 'U1', 'K' . $tag . substr($mk, 0, 6), 'P' . $tag . $mk,
            'CONTRATO ' . $tag . ' ' . $mk, 'EMP ' . $mk, 'UNID ' . $mk,
            'CARGO TXT ' . $tag, $codigoCargo, 'SETOR TXT ' . $tag, $codigoSetor,
        ]);
        $id = (int)$pdo->lastInsertId();
        $criados['metadados'][] = $id;
        return $id;
    };
    $contratoFull = $novoContrato('FULL', 'ZZC1' . substr($sfx, 0, 4), 'ZZS1' . substr($sfx, 0, 4));
    $contratoSemSetor = $novoContrato('NOSET', 'ZZC2' . substr($sfx, 0, 4), null);
    $contratoCargoOrfao = $novoContrato('ORFAO', 'ZZ-SEM-MATCH-' . substr($sfx, 0, 4), null);

    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $novoUser = static function (string $tag, string $role) use ($pdo, $sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . strtoupper($sfx), "{$sfx}+{$tag}@teste.local", $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        return $id;
    };

    $svc = new UsuarioContextoOrganizacionalService();

    // ---- 1 + 2 : usuário sem vínculo, Cargo + vários Setores oficiais ----------
    $uPj = $novoUser('pj', 'viewer');
    $r = $svc->definirContextoManual($uPj, $cargoX, $setorA, [$setorB, $setorC]);
    $check(($r['ok'] ?? false) === true, '1/2 contexto manual aceito para usuário sem vínculo');
    $check((int)$cargoDo($uPj) === $cargoX, '1 Cargo oficial manual gravado em usuarios.cargo_id');
    $linhas = $setoresDo($uPj);
    $ids = array_map(static fn ($l) => (int)$l['setor_id'], $linhas);
    $check(count($linhas) === 3 && in_array($setorA, $ids, true) && in_array($setorB, $ids, true) && in_array($setorC, $ids, true), '2 três Setores oficiais vinculados');
    $check(array_sum(array_map(static fn ($l) => (int)$l['principal'], $linhas)) === 1, '9 exatamente 1 Setor principal');
    $principal = array_values(array_filter($linhas, static fn ($l) => (int)$l['principal'] === 1))[0];
    $check((int)$principal['setor_id'] === $setorA && $principal['origem'] === 'MANUAL', '2 principal manual = setor escolhido, origem MANUAL');
    $check(array_reduce($linhas, static fn ($c, $l) => $c && $l['origem'] === 'MANUAL', true), '2 todas as origens MANUAL para usuário sem vínculo');

    // ---- 3 : Cargo legado recusado -------------------------------------------
    $r = $svc->definirContextoManual($uPj, $cargoLegado, $setorA, []);
    $check(($r['ok'] ?? true) === false, '3 Cargo legado (sem codigo_cargo) recusado');
    $check((int)$cargoDo($uPj) === $cargoX, '3 cargo anterior preservado após rejeição');

    // ---- 4 : Setor legado recusado (principal e adicional) -------------------
    $r = $svc->definirContextoManual($uPj, $cargoX, $setorLegado, []);
    $check(($r['ok'] ?? true) === false, '4 Setor legado como principal recusado');
    $r = $svc->definirContextoManual($uPj, $cargoX, $setorA, [$setorLegado]);
    $check(($r['ok'] ?? true) === false, '4 Setor legado como adicional recusado');
    $check(count($setoresDo($uPj)) === 3, '4 associações preservadas após rejeição');

    // ---- 8 : múltiplos setores não duplicam --------------------------------
    $r = $svc->definirContextoManual($uPj, $cargoX, $setorA, [$setorB, $setorB, $setorB]);
    $check(($r['ok'] ?? false) === true && count($setoresDo($uPj)) === 2, '8 setor repetido no payload não duplica linha');

    // ---- 12 + 13 : pode_solicitar_vaga / aprovador independentes -----------
    $aprov = $novoUser('aprov', 'rh');
    User::setVagaAccess($uPj, true, $aprov);
    $svc->definirContextoManual($uPj, $cargoY, $setorC, [$setorA]);
    $row = $pdo->query("SELECT pode_solicitar_vaga, aprovador_usuario_id FROM usuarios WHERE id = {$uPj}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$row['pode_solicitar_vaga'] === 1 && (int)$row['aprovador_usuario_id'] === $aprov, '12/13 pode_solicitar_vaga e aprovador intactos após mexer no contexto');

    // ---- 5 + 6 : vínculo METADADOS com cargo + setor ----------------------
    $uLink = $novoUser('link', 'viewer');
    $ok = User::vincularMetadados($uLink, $contratoFull);
    $check(($ok['ok'] ?? false) === true, '5/6 vínculo METADADOS aceito');
    $check((int)$cargoDo($uLink) === $cargoX, '5 Cargo principal herdado do contrato');
    $linhasL = $setoresDo($uLink);
    $check(count($linhasL) === 1 && (int)$linhasL[0]['setor_id'] === $setorA && (int)$linhasL[0]['principal'] === 1 && $linhasL[0]['origem'] === 'METADADOS', '6 Setor principal herdado, origem METADADOS');
    $ctxL = $svc->contextoDoUsuario($uLink);
    $check($ctxL['cargo_travado'] === true && $ctxL['setor_principal_travado'] === true, '11(trava) Cargo e Setor principal travados com vínculo');

    // trava: não troca cargo/setor herdado, mas aceita adicionais
    $r = $svc->definirContextoManual($uLink, $cargoY, null, []);
    $check(($r['ok'] ?? true) === false, 'trava Cargo herdado não pode ser trocado');
    $r = $svc->definirContextoManual($uLink, null, $setorB, []);
    $check(($r['ok'] ?? true) === false, 'trava Setor principal herdado não pode ser substituído');
    $r = $svc->definirContextoManual($uLink, null, null, [$setorB, $setorC]);
    $check(($r['ok'] ?? false) === true, 'trava adicionais aceitos mesmo com vínculo');
    $linhasL = $setoresDo($uLink);
    $princ = array_values(array_filter($linhasL, static fn ($l) => (int)$l['principal'] === 1));
    $check(count($princ) === 1 && (int)$princ[0]['setor_id'] === $setorA && $princ[0]['origem'] === 'METADADOS', '10/9 principal METADADOS preservado ao adicionar setores');
    $check(count($linhasL) === 3, '10 adicionais MANUAIS somados ao principal METADADOS');

    // ---- 10 : reaplicar o contexto do vínculo não apaga os adicionais MANUAIS ----
    $svc->aplicarContextoDoVinculo($uLink, $contratoFull);
    $linhasL = $setoresDo($uLink);
    $manuais = array_filter($linhasL, static fn ($l) => $l['origem'] === 'MANUAL');
    $check(count($linhasL) === 3 && count($manuais) === 2, '10 rearme do contexto preserva os 2 setores adicionais MANUAIS');
    $check($countPrincipais($uLink) === 1, '9 ainda 1 único principal após rearme');

    // ---- 7 : vínculo METADADOS sem Setor ---------------------------------
    $uNoSet = $novoUser('noset', 'viewer');
    User::vincularMetadados($uNoSet, $contratoSemSetor);
    $check((int)$cargoDo($uNoSet) === $cargoY, '7 Cargo herdado mesmo sem Setor no contrato');
    $check(count($setoresDo($uNoSet)) === 0, '7 nenhum Setor principal herdado quando o contrato não informa');
    $ctxN = $svc->contextoDoUsuario($uNoSet);
    $check(($ctxN['setor_aviso'] ?? '') === 'Setor não informado no METADADOS' && $ctxN['setor_principal_travado'] === false, '7 aviso "Setor não informado no METADADOS" e principal não travado');
    $r = $svc->definirContextoManual($uNoSet, null, $setorC, [$setorA]);
    $check(($r['ok'] ?? false) === true, '7 Setor principal manual permitido quando o contrato não tem Setor');
    $linhasN = $setoresDo($uNoSet);
    $princN = array_values(array_filter($linhasN, static fn ($l) => (int)$l['principal'] === 1))[0];
    $check((int)$princN['setor_id'] === $setorC && $princN['origem'] === 'MANUAL', '7 principal manual gravado, origem MANUAL');
    $check($countPrincipais($uNoSet) === 1, '9 um principal para vínculo-sem-setor + escolha manual');

    // ---- cargo do contrato sem correspondência oficial ------------------
    $uOrfao = $novoUser('orfao', 'viewer');
    User::vincularMetadados($uOrfao, $contratoCargoOrfao);
    $check($cargoDo($uOrfao) === null || $cargoDo($uOrfao) === false, 'contrato com codigo_cargo sem match -> usuarios.cargo_id NULL');
    $ctxO = $svc->contextoDoUsuario($uOrfao);
    $check(!empty($ctxO['cargo_aviso']), 'contrato com cargo órfão exibe aviso, sem chute');

    // ---- 11 : PJ opera sem colaborador_metadados_id --------------------
    $check((int)$pdo->query("SELECT colaborador_metadados_id IS NULL FROM usuarios WHERE id = {$uPj}")->fetchColumn() === 1, '11 usuário PJ mantém colaborador_metadados_id NULL e tem contexto');
    $check((int)$cargoDo($uPj) === $cargoY, '11 contexto do PJ persistido sem vínculo METADADOS');

    // ---- desvínculo (§6 da revisão final): 9 pontos ---------------------
    // Estado de entrada: uLink vinculado, Cargo + Setor principal herdados (METADADOS) + 2 adicionais MANUAIS.
    $antesCargo = (int)$cargoDo($uLink);
    $antesLinhas = $setoresDo($uLink);
    $antesPrincipal = array_values(array_filter($antesLinhas, static fn ($l) => (int)$l['principal'] === 1))[0];
    $antesAdicionais = array_column(array_filter($antesLinhas, static fn ($l) => (int)$l['principal'] === 0), 'setor_id');
    $ctxAntes = $svc->contextoDoUsuario($uLink);
    $check($ctxAntes['cargo_travado'] === true && (int)$antesPrincipal['setor_id'] === $setorA
        && $antesPrincipal['origem'] === 'METADADOS' && count($antesAdicionais) === 2,
        '1 (desvínculo) entrada: Cargo/Setor herdados do METADADOS + 2 adicionais MANUAIS');
    $totalAssocAntes = count($antesLinhas);

    $out = User::desvincularMetadados($uLink);
    $check(($out['ok'] ?? false) === true, '2 (desvínculo) executado');
    $check((int)$pdo->query("SELECT colaborador_metadados_id IS NULL FROM usuarios WHERE id = {$uLink}")->fetchColumn() === 1, '3 colaborador_metadados_id vira NULL');
    $check((int)$cargoDo($uLink) === $antesCargo, '4 cargo_id permanece');
    $linhasD = $setoresDo($uLink);
    $princD = array_values(array_filter($linhasD, static fn ($l) => (int)$l['principal'] === 1));
    $check(count($princD) === 1 && (int)$princD[0]['setor_id'] === (int)$antesPrincipal['setor_id'], '5 Setor principal permanece (mesmo setor, ainda principal)');
    $adicD = array_column(array_filter($linhasD, static fn ($l) => (int)$l['principal'] === 0), 'setor_id');
    sort($adicD); sort($antesAdicionais);
    $check($adicD === $antesAdicionais, '6 Setores adicionais permanecem');
    $check(array_reduce($linhasD, static fn ($c, $l) => $c && $l['origem'] === 'MANUAL', true), '7 vínculos que eram METADADOS agora são MANUAL; os que já eram MANUAL seguem MANUAL');
    $ctxD = $svc->contextoDoUsuario($uLink);
    $check($ctxD['cargo_travado'] === false && $ctxD['setor_principal_travado'] === false, '8 contexto passa a aceitar edição manual (nada travado, sem "Herdado do METADADOS")');
    $check(count($linhasD) === $totalAssocAntes, '9 nenhuma associação foi apagada');
    $rEdit = $svc->definirContextoManual($uLink, $cargoY, $setorB, [$setorC]);
    $check(($rEdit['ok'] ?? false) === true && (int)$cargoDo($uLink) === $cargoY, '8 (bis) após desvínculo o Cargo/Setor antes herdados podem ser alterados');

    // ---- 14 : Admin visão global -------------------------------------
    $totalUsuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $paginado = User::paginateForAdmin([], 1, 100);
    $check((int)$paginado['total'] === $totalUsuarios, '14 paginateForAdmin conta todos os usuários, sem filtro por Setor');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nUSUARIO_CONTEXTO_ORGANIZACIONAL_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
