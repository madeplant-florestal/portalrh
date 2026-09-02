<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_metadados_sync_execucoes (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$uuid = static function (): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
};

$pdo = Database::conn();
$tabela = static function (string $nome) use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$nome]);
    return (int)$stmt->fetchColumn() > 0;
};
if (!$tabela('metadados_sync_execucoes') || !$tabela('colaboradores_metadados')) {
    echo "SKIP integration_metadados_sync_execucoes (migrations metadados nao aplicadas)\n";
    exit(0);
}

$segredo = 'segredo-teste-' . bin2hex(random_bytes(4));
$config = ['shared_secret' => $segredo, 'replay_window_seconds' => 300, 'max_batch_size' => 2000];
$sufixo = (string)time() . (string)random_int(100, 999);
$empresa = 'EXE' . $sufixo;
$unidade = 'UNI' . $sufixo;

$correlacao1 = $uuid();
$correlacao2 = $uuid();
$idsCriados = [];

$registro = static function (array $overrides = []) use ($empresa, $unidade, $sufixo): array {
    return array_merge([
        'identificador' => "$empresa-$unidade-001",
        'codigo_empresa' => $empresa,
        'codigo_unidade' => $unidade,
        'numero_contrato' => '001',
        'codigo_pessoa' => 'PES' . $sufixo,
        'cpf' => '11122233344',
        'nome' => 'Colaborador Execucao',
        'empresa' => 'Empresa Teste',
        'nascimento' => '1990-05-10',
        'admissao' => '2023-01-01',
        'cargo' => 'Analista',
        'demissao' => null,
        'motivo_rescisao_codigo' => null,
        'motivo_rescisao_descricao' => null,
        'unidade' => 'Unidade Teste',
        'setor' => 'Setor Teste',
        'centro_custo' => 'CC1',
        'ativo' => 1,
        'salario_atual' => '3000.00',
        'data_inicio_cargo' => '2023-01-01',
        'atualizado_em_origem' => null,
    ], $overrides);
};

$assinar = static function (array $payload) use ($segredo): array {
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string)time();
    return [
        'corpo' => $corpo,
        'headers' => [
            MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp,
            MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar($timestamp, $corpo, $segredo),
        ],
    ];
};

$falha = null;
try {
    $repo = new MetadadosSyncExecucaoRepository($pdo);
    $service = new MetadadosSyncIngestService(null, $repo);

    // --- sanitizarMensagem --------------------------------------------------------------------
    $assert(MetadadosSyncExecucaoRepository::sanitizarMensagem(null) === null, 'sanitizar(null) deveria ser null.');
    $assert(MetadadosSyncExecucaoRepository::sanitizarMensagem('   ') === null, 'sanitizar(espacos) deveria ser null.');
    $comHex = (string)MetadadosSyncExecucaoRepository::sanitizarMensagem('segredo ' . str_repeat('a1b2', 10) . ' fim');
    $assert(strpos($comHex, '[omitido]') !== false, 'sanitizar deveria mascarar sequencia hexadecimal longa.');
    $assert(strpos($comHex, str_repeat('a1b2', 10)) === false, 'sanitizar nao deveria manter o hex longo original.');
    $assert(mb_strlen((string)MetadadosSyncExecucaoRepository::sanitizarMensagem(str_repeat('x', 900))) === 500, 'sanitizar deveria truncar em 500.');

    // --- 1) Solicitação manual cria linha aberta --------------------------------------------
    $idSolicitacao = $repo->criarSolicitacao($correlacao1, null, 'manual_dashboard');
    $idsCriados[] = $idSolicitacao;
    $assert($idSolicitacao > 0, 'Caso 1: criarSolicitacao deveria devolver o id.');
    $aberta = $repo->buscarPorCorrelacao($correlacao1);
    $assert($aberta['status'] === 'solicitada' && $aberta['gatilho'] === 'manual_dashboard', 'Caso 1: linha deveria nascer como solicitada/manual_dashboard.');
    $assert($repo->existeEmAndamento(900) === true, 'Caso 1: existeEmAndamento deveria detectar a solicitação recém-criada.');

    // --- 2) Ingest bem-sucedido com o mesmo correlacao_id FECHA a linha aberta --------------
    $payload = [
        'versao' => '1',
        'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 1,
        'registros' => [$registro()],
        'correlacao_id' => $correlacao1,
    ];
    $a2 = $assinar($payload);
    $r2 = $service->receberLote($a2['corpo'], $a2['headers'], $config);
    $assert($r2['http_status'] === 200 && $r2['body']['inseridos'] === 1, 'Caso 2: ingest deveria inserir 1 registro.');

    $qtd = (int)$pdo->query("SELECT COUNT(*) FROM metadados_sync_execucoes WHERE correlacao_id = " . $pdo->quote($correlacao1))->fetchColumn();
    $assert($qtd === 1, 'Caso 2: deveria continuar existindo UMA linha para o correlacao_id (a aberta foi atualizada).');
    $fechada = $repo->buscarPorCorrelacao($correlacao1);
    $assert((int)$fechada['id'] === (int)$aberta['id'], 'Caso 2: a mesma linha deveria ter sido atualizada.');
    $assert($fechada['status'] === 'sucesso', 'Caso 2: status deveria ser sucesso.');
    $assert((int)$fechada['registros_recebidos'] === 1 && (int)$fechada['inseridos'] === 1 && (int)$fechada['erros'] === 0, 'Caso 2: contadores deveriam ter sido gravados.');
    $assert($fechada['origem'] === 'RHMADEPLANT', 'Caso 2: origem deveria ter sido gravada.');
    $assert(!empty($fechada['concluido_em']), 'Caso 2: concluido_em deveria ter sido preenchido.');
    $assert(strlen((string)$fechada['hash_lote']) === 64, 'Caso 2: hash_lote deveria ser um sha256.');

    // --- 3) Nenhum dado sensível na linha nem na tabela ------------------------------------
    $linhaJson = json_encode($fechada);
    foreach (['11122233344', 'Colaborador Execucao', '3000.00', $segredo] as $sensivel) {
        $assert(strpos($linhaJson, (string)$sensivel) === false, "Caso 3: a linha do histórico nunca deveria conter '{$sensivel}'.");
    }
    $colunas = array_map(static fn($c) => strtolower((string)$c['Field']), $pdo->query("SHOW COLUMNS FROM metadados_sync_execucoes")->fetchAll(PDO::FETCH_ASSOC));
    foreach (['cpf', 'nome', 'salario', 'salario_atual', 'shared_secret', 'payload'] as $proibida) {
        $assert(!in_array($proibida, $colunas, true), "Caso 3: a tabela nunca deveria ter a coluna '{$proibida}'.");
    }

    // --- 4) ultimaSincronizacaoValida devolve a execução concluída -----------------------
    $ultima = $repo->ultimaSincronizacaoValida();
    $assert($ultima !== null && (int)$ultima['id'] === (int)$fechada['id'], 'Caso 4: ultimaSincronizacaoValida deveria devolver a execução de sucesso.');

    // --- 5) Falha após autenticação (conflito de origem) vira falha sanitizada -----------
    $payloadConflito = $payload;
    $payloadConflito['origem_metadados'] = 'RHTESTE';
    $payloadConflito['correlacao_id'] = $correlacao2;
    $payloadConflito['registros'][0]['numero_contrato'] = '777';
    $payloadConflito['registros'][0]['identificador'] = "$empresa-$unidade-777";
    $a5 = $assinar($payloadConflito);
    $r5 = $service->receberLote($a5['corpo'], $a5['headers'], $config);
    $assert($r5['http_status'] === 409, 'Caso 5: origem incompatível deveria retornar 409.');
    $exec5 = $repo->buscarPorCorrelacao($correlacao2);
    $assert($exec5 !== null, 'Caso 5: deveria ter registrado uma execução.');
    $idsCriados[] = (int)$exec5['id'];
    $assert($exec5['status'] === 'falha', 'Caso 5: status deveria ser falha.');
    $assert(!empty($exec5['mensagem_tecnica']), 'Caso 5: mensagem_tecnica deveria estar preenchida.');
    $assert(strpos((string)$exec5['mensagem_tecnica'], $segredo) === false, 'Caso 5: mensagem_tecnica nunca deveria conter o segredo.');
    $escritos777 = (int)$pdo->query("SELECT COUNT(*) FROM colaboradores_metadados WHERE codigo_empresa = " . $pdo->quote($empresa) . " AND numero_contrato = '777'")->fetchColumn();
    $assert($escritos777 === 0, 'Caso 5: nada deveria ter sido escrito no espelho.');

    // --- 6) Ingest sem correlacao_id insere linha nova (gatilho desconhecido) ------------
    $payloadSemCorrelacao = $payload;
    unset($payloadSemCorrelacao['correlacao_id']);
    $payloadSemCorrelacao['registros'][0]['cargo'] = 'Analista Pleno';
    $a6 = $assinar($payloadSemCorrelacao);
    $r6 = $service->receberLote($a6['corpo'], $a6['headers'], $config);
    $assert($r6['http_status'] === 200 && $r6['body']['atualizados'] === 1, 'Caso 6: ingest deveria atualizar 1 registro.');
    $nova = $pdo->query("SELECT * FROM metadados_sync_execucoes WHERE correlacao_id IS NULL AND origem = 'RHMADEPLANT' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert($nova !== false && $nova['gatilho'] === 'desconhecido' && $nova['status'] === 'sucesso' && (int)$nova['atualizados'] === 1, 'Caso 6: deveria existir linha nova com gatilho desconhecido e 1 atualização.');
    $idsCriados[] = (int)$nova['id'];

    // --- 7) Janela de "em andamento" e expiração ----------------------------------------
    $correlacao3 = $uuid();
    $idPendente = $repo->criarSolicitacao($correlacao3, null, 'manual_dashboard');
    $idsCriados[] = $idPendente;
    // envelhece a solicitação para 20 min atrás
    $pdo->prepare("UPDATE metadados_sync_execucoes SET solicitado_em = (NOW() - INTERVAL 1200 SECOND), created_at = (NOW() - INTERVAL 1200 SECOND) WHERE id = ?")->execute([$idPendente]);
    $assert($repo->existeEmAndamento(900) === false, 'Caso 7: solicitação de 20 min atrás não deveria mais contar como "em andamento" (janela 900s).');
    $afetadas = $repo->marcarExpiradas(900);
    $assert($afetadas >= 1, 'Caso 7: marcarExpiradas deveria fechar a solicitação abandonada.');
    $expirada = $repo->buscarPorCorrelacao($correlacao3);
    $assert($expirada['status'] === 'expirada' && !empty($expirada['concluido_em']), 'Caso 7: a solicitação deveria ter virado expirada.');
    $pdo->prepare('DELETE FROM metadados_sync_execucoes WHERE correlacao_id = ?')->execute([$correlacao3]);

    echo "OK integration_metadados_sync_execucoes\n";
} catch (Throwable $e) {
    $falha = $e->getMessage();
} finally {
    $pdo->prepare('DELETE FROM colaboradores_metadados WHERE codigo_empresa = ? AND codigo_unidade = ?')->execute([$empresa, $unidade]);
    $pdo->prepare('DELETE FROM metadados_sync_execucoes WHERE correlacao_id IN (?, ?)')->execute([$correlacao1, $correlacao2]);
    $idsCriados = array_values(array_unique(array_filter($idsCriados)));
    if ($idsCriados !== []) {
        $ph = implode(',', array_fill(0, count($idsCriados), '?'));
        $pdo->prepare("DELETE FROM metadados_sync_execucoes WHERE id IN ($ph)")->execute($idsCriados);
    }
}

if ($falha !== null) {
    fwrite(STDERR, $falha . PHP_EOL);
    exit(1);
}
