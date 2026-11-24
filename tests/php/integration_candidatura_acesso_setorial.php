<?php

/**
 * Integração — Etapa 3.2B.2: autorização POR CANDIDATURA e POR ARQUIVO pelo setor da vaga (CandidaturaAcessoSetorialService).
 * Prova o isolamento sobre os DADOS/arquivos resolvidos (não por 403 de outra barreira) e, em separado, que os endpoints seguem
 * bloqueados ao Gestor/Viewer (liberação = 3.2C). Arquivos físicos sintéticos ZZVD* em storage/, removidos em `finally`.
 * Respeita candidaturas.cpf UNIQUE (CPFs distintos). Não toca em dados nem arquivos reais.
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
$mk = 'zzvd' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$vagas = [];
$setores = [];
$usuarios = [];
$arquivos = [];
$cartas = [];

$mkUser = static function (string $suf, string $role) use (&$usuarios, $mk, $senhaHash): int {
    $id = User::create('ZZVD ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    $usuarios[] = $id;
    return $id;
};
$mkSetor = static function (string $suf) use ($pdo, $mk, &$setores): int {
    $codigo = substr(strtoupper($suf[0]) . strtoupper(substr(md5($mk . $suf), 0, 5)), 0, 8);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, 'zz-teste')")->execute([$codigo, "ZZVD {$suf} {$mk}", "{$mk}-{$suf}"]);
    return $setores[] = (int)$pdo->lastInsertId();
};
$gerencia = static function (int $uid, array $ids) use ($pdo): void {
    foreach ($ids as $s) {
        $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$uid, $s]);
    }
};
$mkVaga = static function (string $suf, ?int $setor) use ($pdo, $mk, &$vagas): int {
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'ZZ', 'l', 1, ?)")->execute(["ZZVD {$suf} {$mk}", $setor]);
    return $vagas[] = (int)$pdo->lastInsertId();
};
$arquivo = static function (string $subdir, string $nome, string $conteudo) use (&$arquivos): void {
    $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    file_put_contents($dir . DIRECTORY_SEPARATOR . $nome, $conteudo);
    $arquivos[] = $dir . DIRECTORY_SEPARATOR . $nome;
};
$mkCand = static function (int $vagaId, string $nome, string $email, string $pdf) use ($pdo): int {
    $cpf = (string)random_int(10000000000, 99999999999);
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, observacoes) VALUES (?, ?, ?, '11999990000', ?, 'Cargo Secreto ZZ', 'Experiencia Secreta ZZ', ?, 'Obs reservada RH ZZ')")
        ->execute([$vagaId, $nome, $email, $cpf, $pdf]);
    return (int)$pdo->lastInsertId();
};
$mkDoc = static function (int $candId, string $armazenado, string $mime) use ($pdo, &$usuarios): int {
    $pdo->prepare('INSERT INTO candidatura_documentos (candidatura_id, nome_original, nome_armazenado, mime, tamanho_bytes, enviado_por_usuario_id, criado_em) VALUES (?, ?, ?, ?, 3, ?, NOW())')->execute([$candId, 'Original Sensivel.pdf', $armazenado, $mime, $usuarios[0]]);
    return (int)$pdo->lastInsertId();
};
$http = static function (int $uid, string $role, string $controller, string $metodo, array $args = []): int {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$uid, $role, '0', $controller, $metodo, '[]', json_encode($args), ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    return preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
};

try {
    $check(VagaSetorService::schemaSuportaSetor(), 'pré-requisito: migration 2026-11-24 aplicada no banco de teste');
    $gA = $mkUser('gA', 'gestor');   // setores 1 e 2
    $gB = $mkUser('gB', 'gestor');   // setor 3
    $gC = $mkUser('gC', 'gestor');   // sem setores
    $rh = $mkUser('rh', 'rh');
    $admin = $mkUser('admin', 'admin');
    $viewer = $mkUser('viewer', 'viewer'); // com setor gerenciado legado
    $s1 = $mkSetor('S1'); $s2 = $mkSetor('S2'); $s3 = $mkSetor('S3');
    $gerencia($gA, [$s1, $s2]);
    $gerencia($gB, [$s3]);
    $gerencia($viewer, [$s1]);

    $v1 = $mkVaga('V1', $s1); $v2 = $mkVaga('V2', $s2); $v3 = $mkVaga('V3', $s3); $vSem = $mkVaga('Sem', null);
    $pdfs = [];
    foreach (['c1', 'c2', 'c3', 'cs'] as $k) {
        $pdfs[$k] = "{$mk}-{$k}.pdf";
        $arquivo('resumes', $pdfs[$k], "%PDF-{$k}");
    }
    $c1 = $mkCand($v1, "ZZVD Ana {$mk}", "{$mk}.ana@teste.local", $pdfs['c1']);
    $c2 = $mkCand($v2, "ZZVD Bia {$mk}", "{$mk}.bia@teste.local", $pdfs['c2']);
    $c3 = $mkCand($v3, "ZZVD Ana {$mk}", "{$mk}.ana@teste.local", $pdfs['c3']); // mesmo nome/e-mail, CPF distinto, outro setor
    $cs = $mkCand($vSem, "ZZVD Cia {$mk}", "{$mk}.cia@teste.local", $pdfs['cs']);
    $docs = [];
    foreach (['d1' => $c1, 'd3' => $c3, 'ds' => $cs] as $k => $cid) {
        $arq = "{$mk}-{$k}.pdf";
        $arquivo('candidatura_documentos', $arq, "DOC-{$k}");
        $docs[$k] = $mkDoc($cid, $arq, 'application/pdf');
    }
    // Carta Proposta (PDF) vinculada à candidatura 3 (outro setor) e à 1
    $obrig = ['nome_candidato' => 'ZZ', 'cargo' => 'ZZ', 'setor' => 'ZZ', 'empresa_contratante' => 'ZZ', 'salario_proposto' => '1', 'jornada_trabalho' => 'ZZ', 'escala_horario' => 'ZZ', 'beneficios_texto' => 'ZZ', 'local_trabalho' => 'ZZ', 'previsao_inicio' => date('Y-m-d'), 'validade_proposta' => date('Y-m-d')];
    $mkCarta = static function (int $candId, string $arq) use ($pdo, &$cartas, &$usuarios, $arquivo, $obrig): int {
        $arquivo('cartas_propostas', $arq, '%PDF-carta');
        $cols = array_merge(['candidatura_id' => $candId, 'versao' => 1, 'status' => 'gerada', 'pdf_nome_armazenado' => $arq, 'gerada_por_usuario_id' => $usuarios[0]], $obrig);
        $pdo->prepare('INSERT INTO cartas_propostas (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        return $cartas[] = (int)$pdo->lastInsertId();
    };
    $cartaOk = false;
    try {
        $cp1 = $mkCarta($c1, "{$mk}-cp1.pdf");
        $cp3 = $mkCarta($c3, "{$mk}-cp3.pdf");
        $cartaOk = true;
    } catch (\Throwable $e) {
        fwrite(STDERR, '  (fixture de carta indisponível: ' . $e->getMessage() . ")\n");
    }
    $check($cartaOk, 'fixtures de Carta Proposta criadas');

    $svc = new CandidaturaAcessoSetorialService();
    $esc = static fn(int $id, string $role): EscopoSetorial => CandidaturaAcessoSetorialService::escopoNominal(['id' => $id, 'role' => $role]);
    $eA = $esc($gA, 'gestor'); $eB = $esc($gB, 'gestor'); $eC = $esc($gC, 'gestor');
    $eRh = $esc($rh, 'rh'); $eAdmin = $esc($admin, 'admin'); $eViewer = $esc($viewer, 'viewer');

    // ---- 1. Autorização por candidatura ----
    $check($svc->autorizar($eA, $c1) !== null && $svc->autorizar($eA, $c2) !== null, 'Gestor com vários setores autoriza candidaturas das vagas dos seus setores');
    $check($svc->autorizar($eA, $c3) === null && $svc->autorizar($eA, $cs) === null, 'candidatura de outro setor e de vaga SEM setor: negadas (null)');
    $check($svc->autorizar($eB, $c3) !== null && $svc->autorizar($eB, $c1) === null, 'Gestor com um setor: só o seu');
    $check($svc->autorizar($eC, $c1) === null && $svc->autorizar($eC, $c3) === null, 'Gestor sem setores: nada');
    $check($svc->autorizar($eA, 999999999) === null && $svc->autorizar($eA, 0) === null && $svc->autorizar($eA, -3) === null, 'candidatura inexistente/ID forjado: null (indistinguível de fora do escopo)');
    $check($svc->autorizar($eRh, $cs) !== null && $svc->autorizar($eAdmin, $c3) !== null, 'Admin e RH: acesso global (inclusive vaga sem setor)');
    $check($eViewer->semAutorizacao() && $svc->autorizar($eViewer, $c1) === null, 'Viewer (mesmo com setor gerenciado legado) não tem acesso nominal');
    $check(CandidaturaAcessoSetorialService::escopoNominal(null)->semAutorizacao() && CandidaturaAcessoSetorialService::escopoNominal(['id' => 0, 'role' => 'gestor'])->semAutorizacao() && CandidaturaAcessoSetorialService::escopoNominal(['id' => 99999999, 'role' => 'gestor'])->enxergaAlgo() === false && CandidaturaAcessoSetorialService::escopoNominal(['id' => $gA, 'role' => ''])->semAutorizacao() && CandidaturaAcessoSetorialService::escopoNominal(999999999)->semAutorizacao(), 'falha/ausência de resolução do escopo NUNCA vira acesso global (ator nulo, id 0, inexistente, role vazia)');
    $check($svc->autorizar(EscopoSetorial::nenhum(), $c1) === null && $svc->autorizar(EscopoSetorial::restrito([]), $c1) === null, 'escopo nenhum/restrito vazio = nada');
    $check((new ReflectionMethod(CandidaturaAcessoSetorialService::class, 'autorizar'))->getParameters()[0]->allowsNull() === false && (new ReflectionMethod(CandidaturaAcessoSetorialService::class, 'arquivo'))->getParameters()[0]->allowsNull() === false, 'o tipo exige EscopoSetorial não nulo: não existe "sem escopo = global" nos caminhos novos');

    // ---- 2. Detalhe: projeção segura ----
    $pdo->prepare("INSERT INTO candidatura_historico (candidatura_id, status_anterior, status_novo, observacoes) VALUES (?, 'novo', 'triagem', 'Nota interna RH ZZ')")->execute([$c1]);
    $d = $svc->detalhe($eA, $c1) ?? [];
    $json = (string)json_encode($d);
    $proib = ['cpf', 'email', 'telefone', 'pdf_path', 'experiencia', 'cargo_pretendido', 'observacoes', 'indicacao_colaborador', 'interview_link', 'admission_notes'];
    $vaz = array_values(array_filter($proib, static fn(string $k): bool => array_key_exists($k, $d)));
    $check($d !== [] && $vaz === [], 'detalhe do Gestor não inclui CPF, e-mail, telefone, currículo, experiência, cargo pretendido nem observações' . ($vaz ? ' (vazou ' . implode(',', $vaz) . ')' : ''));
    $check(!str_contains($json, 'Cargo Secreto ZZ') && !str_contains($json, 'Experiencia Secreta') && !str_contains($json, 'Obs reservada') && !str_contains($json, 'Nota interna RH ZZ') && !str_contains($json, "{$mk}.ana@teste.local"), 'nenhum texto reservado (observações, histórico interno, e-mail) no detalhe serializado');
    $check(($d['nome'] ?? '') === "ZZVD Ana {$mk}" && count($d['historico_etapas'] ?? []) === 1 && array_keys($d['historico_etapas'][0]) === ['status_anterior', 'status_novo', 'created_at'], 'detalhe traz o necessário: nome, vaga, etapa e histórico de etapas (de → para, data), sem usuário nem observação');
    $check($svc->detalhe($eA, $c3) === null && $svc->detalhe($eC, $c1) === null && $svc->detalhe($eViewer, $c1) === null, 'detalhe de outro setor / sem setores / viewer = null');
    $dAna1 = $svc->detalhe($eA, $c1);
    $check(!str_contains((string)json_encode($dAna1), (string)$c3) || ($dAna1['id'] ?? 0) === $c1, 'mesmo nome/e-mail em outro setor: o detalhe não referencia a outra candidatura (sem ficha global)');
    $check($svc->detalhe($eRh, $cs) !== null, 'RH tem detalhe global');

    // ---- 3. Arquivos: currículo ----
    $cur = $svc->arquivo($eA, $c1, 'curriculo');
    $check($cur !== null && is_file($cur['caminho']) && file_get_contents($cur['caminho']) === '%PDF-c1' && $cur['mime'] === 'application/pdf' && !str_contains($cur['nome_download'], 'Ana'), 'currículo do próprio setor resolvido; nome de download sem dado pessoal');
    $check($svc->arquivo($eA, $c3, 'curriculo') === null && $svc->arquivo($eA, $cs, 'curriculo') === null && $svc->arquivo($eC, $c1, 'curriculo') === null && $svc->arquivo($eViewer, $c1, 'curriculo') === null, 'currículo de outro setor / vaga sem setor / gestor sem setores / viewer: null');
    $check($svc->arquivo($eRh, $cs, 'curriculo') !== null, 'RH resolve currículo global');

    // ---- 4. Arquivos: documentos ----
    $doc1 = $svc->arquivo($eA, $c1, 'documento', $docs['d1']);
    $check($doc1 !== null && file_get_contents($doc1['caminho']) === 'DOC-d1', 'documento da candidatura do escopo resolvido');
    $check($svc->arquivo($eA, $c1, 'documento', $docs['d3']) === null, 'ID de documento de OUTRA candidatura (de outro setor) usado na URL de candidatura autorizada: null');
    $check($svc->arquivo($eA, $c3, 'documento', $docs['d3']) === null && $svc->arquivo($eB, $c1, 'documento', $docs['d1']) === null, 'documento de candidatura fora do escopo: null mesmo com o id correto do documento');
    $check($svc->arquivo($eA, $c1, 'documento', 999999999) === null && $svc->arquivo($eA, $c1, 'documento', 0) === null && $svc->arquivo($eA, $c1, 'documento') === null, 'documento inexistente/forjado/sem id: null');
    $check($svc->arquivo($eRh, $cs, 'documento', $docs['ds']) !== null && $svc->arquivo($eAdmin, $c3, 'documento', $docs['d3']) !== null, 'Admin/RH resolvem documentos globalmente');
    $check($svc->arquivo($eA, $c1, 'tipo-invalido', 1) === null && $svc->arquivo($eA, $c1, '../../app/core/bootstrap.php', 1) === null, 'tipo de arquivo desconhecido/forjado: null');

    // ---- 5. Manipulação de caminho (registro adulterado no banco) ----
    $pdo->prepare('UPDATE candidatura_documentos SET nome_armazenado = ? WHERE id = ?')->execute(['../resumes/' . $pdfs['c2'], $docs['d1']]);
    $trav = $svc->arquivo($eA, $c1, 'documento', $docs['d1']);
    $check($trav === null, 'nome_armazenado com "../" não escapa do diretório de documentos (basename + realpath dentro da pasta) — currículo de outra candidatura não é entregue');
    $pdo->prepare('UPDATE candidaturas SET pdf_path = ? WHERE id = ?')->execute(['../candidatura_documentos/' . "{$mk}-d3.pdf", $c1]);
    $check($svc->arquivo($eA, $c1, 'curriculo') === null, 'pdf_path adulterado apontando para outra pasta: não entrega');
    $pdo->prepare('UPDATE candidaturas SET pdf_path = ? WHERE id = ?')->execute([$pdfs['c1'], $c1]);

    // ---- 6. Carta Proposta ----
    if ($cartaOk) {
        $check($svc->arquivo($eA, $c1, 'carta_pdf', $cp1) !== null, 'PDF da carta da candidatura do escopo resolvido');
        $check($svc->arquivo($eA, $c1, 'carta_pdf', $cp3) === null && $svc->arquivo($eA, $c3, 'carta_pdf', $cp3) === null && $svc->arquivo($eA, $c1, 'carta_comprovante', $cp1) === null, 'carta de outra candidatura/outro setor, e comprovante inexistente: null');
    }

    // ---- 7. Mudanças refletem imediatamente ----
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s3, $v1]);
    $check($svc->autorizar($eA, $c1) === null && $svc->arquivo($eA, $c1, 'curriculo') === null && $svc->detalhe($eA, $c1) === null && $svc->autorizar($eB, $c1) !== null, 'vaga que muda de setor: Gestor anterior perde detalhe/arquivos na hora; o novo passa a ter');
    $pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ?')->execute([$s1, $v1]);
    $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ? AND setor_id = ?')->execute([$gA, $s1]);
    $eA2 = $esc($gA, 'gestor');
    $check($svc->autorizar($eA2, $c1) === null && $svc->autorizar($eA2, $c2) !== null, 'perda de um setor gerenciado revoga na hora a autorização daquele setor (e só dele)');
    $pdo->prepare('UPDATE vagas SET setor_id = NULL WHERE id = ?')->execute([$v2]);
    $check($svc->autorizar($eA2, $c2) === null, 'vaga que fica sem setor_id deixa de ser acessível ao Gestor');

    // ---- 8. Endpoints seguem bloqueados (separado da autorização por registro; a liberação é da 3.2C) ----
    $bloq = [['AdminCandidaturasController', 'show', [(string)$c1]], ['AdminCandidaturasController', 'download', [(string)$c1]], ['AdminCandidaturasController', 'downloadDocumento', [(string)$c1, (string)$docs['d1']]], ['AdminCandidaturasController', 'uploadDocumento', [(string)$c1]], ['AdminCandidaturasController', 'update', [(string)$c1]], ['AdminPipelineController', 'move', []]];
    if ($cartaOk) { $bloq[] = ['AdminCartaPropostaController', 'pdf', [(string)$cp1]]; $bloq[] = ['AdminCartaPropostaController', 'comprovante', [(string)$cp1]]; }
    $ok403 = true;
    foreach ($bloq as [$ctl, $met, $args]) {
        foreach ([[$gA, 'gestor'], [$viewer, 'viewer'], [$gC, 'gestor']] as [$u, $r]) {
            $st = $http($u, $r, $ctl, $met, $args);
            if ($st !== 403) { $ok403 = false; fwrite(STDERR, "    {$ctl}::{$met} ({$r}) => {$st}\n"); }
        }
    }
    $check($ok403, 'detalhe, currículo, documentos, cartas, upload, update e move seguem 403 para Gestor (com e sem setor) e Viewer — nenhum acesso novo');
    $stRh = $http($rh, 'rh', 'AdminCandidaturasController', 'show', [(string)$c3]);
    $check($stRh === 200, 'RH segue abrindo o detalhe completo de qualquer candidatura (fluxo atual preservado)');

    // ---- 9. Sem ampliação de permissões ----
    foreach (['candidaturas.editar', 'pipeline.visualizar', 'pipeline.movimentar', 'comunicacoes.visualizar', 'pesquisa_experiencia.visualizar', 'vagas.editar'] as $p) {
        $check(!in_array($p, PermissaoPerfilPolicy::concediveis('gestor'), true) && !in_array($p, PermissaoPerfilPolicy::modeloRecomendado('gestor'), true), "{$p} continua fora do concedível e do modelo do Gestor");
    }
    $check(!array_key_exists('setor_nome', Vaga::allActive()[0] ?? []), 'Vaga::allActive (base da divulgação pública) não ganhou campos de setor; o contrato da API segue coberto por integration_vagas_setor_responsavel');
} finally {
    foreach ($cartas as $id) { $pdo->prepare('DELETE FROM cartas_propostas WHERE id = ?')->execute([$id]); }
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
    foreach ($arquivos as $f) { if (is_file($f)) { @unlink($f); } }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — autorização setorial por candidatura e arquivo (Etapa 3.2B.2).\n";
