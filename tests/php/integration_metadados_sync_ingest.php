<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_metadados_sync_ingest (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::conn();
$tableExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados'"
)->fetchColumn();
if ($tableExists === 0) {
    echo "SKIP integration_metadados_sync_ingest (migration colaboradores-metadados.sql nao aplicada)\n";
    exit(0);
}

// O receiver agora registra cada sincronização válida em metadados_sync_execucoes (observabilidade).
// Marca o ponto de partida para limpar só as linhas criadas por este teste no finally.
$execTableExists = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metadados_sync_execucoes'"
)->fetchColumn() > 0;
$execMaxIdInicial = $execTableExists
    ? (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM metadados_sync_execucoes")->fetchColumn()
    : 0;

// Segredo/dados fictícios — nunca reais.
$segredo = 'segredo-teste-' . bin2hex(random_bytes(4));
$config = ['shared_secret' => $segredo, 'replay_window_seconds' => 300, 'max_batch_size' => 2000];
$sufixo = (string)time() . (string)random_int(100, 999);
$empresa = 'ING' . $sufixo;
$unidade = 'UNI' . $sufixo;

function registroIngest(string $empresa, string $unidade, string $sufixo, array $overrides = []): array
{
    return array_merge([
        'identificador' => "$empresa-$unidade-001",
        'codigo_empresa' => $empresa,
        'codigo_unidade' => $unidade,
        'numero_contrato' => '001',
        'codigo_pessoa' => 'PES' . $sufixo,
        'cpf' => '11122233344',
        'nome' => 'Colaborador Teste Ingest',
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
}

function assinarLote(array $payload, string $segredo, ?string $timestamp = null): array
{
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = $timestamp ?? (string)time();
    $assinatura = MetadadosSyncSignature::assinar($timestamp, $corpo, $segredo);
    return [
        'corpo' => $corpo,
        'headers' => [
            MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp,
            MetadadosSyncSignature::HEADER_SIGNATURE => $assinatura,
        ],
    ];
}

$falha = null;
try {
    $service = new MetadadosSyncIngestService();

    $registro1 = registroIngest($empresa, $unidade, $sufixo);
    $registro2 = registroIngest($empresa, $unidade, $sufixo, ['identificador' => "$empresa-$unidade-002", 'numero_contrato' => '002']);
    $payloadValido = [
        'versao' => '1',
        'origem_metadados' => 'RHMADEPLANT',
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => 2,
        'registros' => [$registro1, $registro2],
    ];

    // 1) Primeira importação: assinatura válida, payload válido -> 2 inseridos.
    $assinado1 = assinarLote($payloadValido, $segredo);
    $r1 = $service->receberLote($assinado1['corpo'], $assinado1['headers'], $config);
    $assert($r1['http_status'] === 200, 'Caso 1: primeira importação válida deveria retornar 200.');
    $assert($r1['body']['ok'] === true, 'Caso 1: primeira importação válida deveria retornar ok=true.');
    $assert($r1['body']['inseridos'] === 2, 'Caso 1: deveria inserir os 2 registros.');
    $assert($r1['body']['origem'] === 'RHMADEPLANT', 'Caso 1: resposta deveria reportar a origem aplicada.');
    foreach ($r1['body'] as $chave => $valor) {
        $assert(!in_array($chave, ['cpf', 'nome', 'salario_atual', 'nascimento'], true), "Caso 1: resposta nunca deveria conter o campo '{$chave}' (PII).");
    }
    $assert(strpos(json_encode($r1['body']), '11122233344') === false, 'Caso 1: resposta não deveria conter CPF em nenhum lugar.');

    // 2) Segunda importação idêntica -> idempotente, 0 inseridos/atualizados, 2 inalterados.
    $assinado2 = assinarLote($payloadValido, $segredo);
    $r2 = $service->receberLote($assinado2['corpo'], $assinado2['headers'], $config);
    $assert($r2['body']['inseridos'] === 0 && $r2['body']['atualizados'] === 0, 'Caso 2: reenviar o mesmo lote não deveria inserir/atualizar nada.');
    $assert($r2['body']['inalterados'] === 2, 'Caso 2: os 2 registros deveriam estar inalterados.');

    // 3) Atualização: mesmo lote com um campo mudado -> 1 atualizado.
    $payloadAtualizado = $payloadValido;
    $payloadAtualizado['registros'][0]['cargo'] = 'Analista Sênior';
    $assinado3 = assinarLote($payloadAtualizado, $segredo);
    $r3 = $service->receberLote($assinado3['corpo'], $assinado3['headers'], $config);
    $assert($r3['body']['atualizados'] === 1, 'Caso 3: mudança de cargo em um registro deveria gerar 1 atualização.');
    $assert($r3['body']['inalterados'] === 1, 'Caso 3: o outro registro deveria continuar inalterado.');

    // 4) Origem incompatível bloqueia (409) e não escreve nada.
    $payloadOutraOrigem = $payloadValido;
    $payloadOutraOrigem['origem_metadados'] = 'RHTESTE';
    $payloadOutraOrigem['registros'][0]['numero_contrato'] = '999'; // registro novo, para provar que nem inserção ocorre
    $payloadOutraOrigem['registros'][0]['identificador'] = "$empresa-$unidade-999";
    unset($payloadOutraOrigem['registros'][1]);
    $payloadOutraOrigem['total'] = 1;
    $assinado4 = assinarLote($payloadOutraOrigem, $segredo);
    $r4 = $service->receberLote($assinado4['corpo'], $assinado4['headers'], $config);
    $assert($r4['http_status'] === 409, 'Caso 4: origem incompatível com a já predominante deveria retornar 409.');
    $assert($r4['body']['ok'] === false, 'Caso 4: origem incompatível deveria retornar ok=false.');
    $repo = new ColaboradorMetadadosRepository($pdo);
    $assert($repo->findByVinculo($empresa, $unidade, '999') === null, 'Caso 4: nenhuma linha deveria ter sido escrita quando a origem é bloqueada.');

    // 5) Assinatura inválida (segredo errado) -> 401, nada muda.
    $corpoValido5 = json_encode($payloadValido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp5 = (string)time();
    $assinaturaErrada = MetadadosSyncSignature::assinar($timestamp5, $corpoValido5, 'segredo-errado');
    $r5 = $service->receberLote($corpoValido5, [
        MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp5,
        MetadadosSyncSignature::HEADER_SIGNATURE => $assinaturaErrada,
    ], $config);
    $assert($r5['http_status'] === 401, 'Caso 5: assinatura com segredo errado deveria retornar 401.');

    // 6) Timestamp expirado -> 401.
    $assinado6 = assinarLote($payloadValido, $segredo, (string)(time() - 3600));
    $r6 = $service->receberLote($assinado6['corpo'], $assinado6['headers'], $config);
    $assert($r6['http_status'] === 401, 'Caso 6: timestamp expirado (1h atrás, janela de 300s) deveria retornar 401.');

    // 7) Corpo alterado depois de assinado -> 401 (a assinatura cobre o corpo inteiro).
    $assinado7 = assinarLote($payloadValido, $segredo);
    $r7 = $service->receberLote($assinado7['corpo'] . ' ', $assinado7['headers'], $config); // 1 byte a mais
    $assert($r7['http_status'] === 401, 'Caso 7: corpo alterado depois da assinatura deveria invalidar a verificação.');

    // 8) JSON inválido -> 400.
    $assinado8 = assinarLote($payloadValido, $segredo); // assina o payload válido, mas envia lixo
    $r8 = $service->receberLote('{isto nao e json valido', [
        MetadadosSyncSignature::HEADER_TIMESTAMP => (string)time(),
        MetadadosSyncSignature::HEADER_SIGNATURE => MetadadosSyncSignature::assinar((string)time(), '{isto nao e json valido', $segredo),
    ], $config);
    $assert($r8['http_status'] === 400, 'Caso 8: JSON malformado deveria retornar 400.');

    // 9) Campo obrigatório ausente -> 400, nada escrito.
    $payloadSemCampo = $payloadValido;
    unset($payloadSemCampo['registros'][0]['codigo_empresa']);
    $payloadSemCampo['registros'][0]['identificador'] = "$empresa-$unidade-888";
    $payloadSemCampo['registros'][0]['numero_contrato'] = '888';
    $assinado9 = assinarLote($payloadSemCampo, $segredo);
    $r9 = $service->receberLote($assinado9['corpo'], $assinado9['headers'], $config);
    $assert($r9['http_status'] === 400, 'Caso 9: registro sem codigo_empresa deveria retornar 400.');
    $assert($repo->findByVinculo($empresa, $unidade, '888') === null, 'Caso 9: nenhuma linha deveria ter sido escrita quando o payload é estruturalmente inválido.');

    // 10) Quantidade declarada divergente -> 400.
    $payloadTotalErrado = $payloadValido;
    $payloadTotalErrado['total'] = 99;
    $assinado10 = assinarLote($payloadTotalErrado, $segredo);
    $r10 = $service->receberLote($assinado10['corpo'], $assinado10['headers'], $config);
    $assert($r10['http_status'] === 400, 'Caso 10: total declarado divergente da quantidade real deveria retornar 400.');

    // 11) Chave lógica duplicada dentro do lote -> 400, nada escrito.
    $payloadDuplicado = $payloadValido;
    $registroDuplicado = registroIngest($empresa, $unidade, $sufixo, ['identificador' => "$empresa-$unidade-777", 'numero_contrato' => '777']);
    $payloadDuplicado['registros'] = [$registroDuplicado, $registroDuplicado];
    $payloadDuplicado['total'] = 2;
    $assinado11 = assinarLote($payloadDuplicado, $segredo);
    $r11 = $service->receberLote($assinado11['corpo'], $assinado11['headers'], $config);
    $assert($r11['http_status'] === 400, 'Caso 11: chave lógica duplicada no lote deveria retornar 400.');
    $assert($repo->findByVinculo($empresa, $unidade, '777') === null, 'Caso 11: nenhuma linha deveria ter sido escrita quando há chave duplicada no lote.');

    // 12) String "perigosa" em campo de texto é armazenada literalmente (prepared statements) —
    // nunca interpretada como SQL.
    $payloadTextoMalicioso = $payloadValido;
    $payloadTextoMalicioso['registros'] = [registroIngest($empresa, $unidade, $sufixo, [
        'identificador' => "$empresa-$unidade-666",
        'numero_contrato' => '666',
        'cargo' => "Analista'; DROP TABLE colaboradores_metadados; --",
    ])];
    $payloadTextoMalicioso['total'] = 1;
    $assinado12 = assinarLote($payloadTextoMalicioso, $segredo);
    $r12 = $service->receberLote($assinado12['corpo'], $assinado12['headers'], $config);
    $assert($r12['http_status'] === 200 && $r12['body']['inseridos'] === 1, 'Caso 12: string com sintaxe SQL em campo de texto deveria ser aceita como dado comum.');
    $linha666 = $repo->findByVinculo($empresa, $unidade, '666');
    $assert($linha666['cargo'] === "Analista'; DROP TABLE colaboradores_metadados; --", 'Caso 12: o texto deveria ter sido persistido literalmente, sem execução.');
    $totalAindaExiste = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados'")->fetchColumn();
    $assert($totalAindaExiste === 1, 'Caso 12: a tabela colaboradores_metadados ainda deveria existir (nenhum SQL foi executado).');

    echo "OK integration_metadados_sync_ingest\n";
} catch (Throwable $e) {
    // Não usar exit() aqui — isso pularia o finally abaixo e deixaria as fixtures órfãs. Guarda o
    // erro e só encerra o processo depois que a limpeza já rodou.
    $falha = $e->getMessage();
} finally {
    $pdo->prepare('DELETE FROM colaboradores_metadados WHERE codigo_empresa = ? AND codigo_unidade = ?')->execute([$empresa, $unidade]);
    if ($execTableExists) {
        $pdo->prepare('DELETE FROM metadados_sync_execucoes WHERE id > ?')->execute([$execMaxIdInicial]);
    }
}

if ($falha !== null) {
    fwrite(STDERR, $falha . PHP_EOL);
    exit(1);
}
