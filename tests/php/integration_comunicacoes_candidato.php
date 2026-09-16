<?php

/**
 * Integração — Sprint "Histórico de Comunicação" (migration 2026-09-16-comunicacoes-candidato.sql
 * + Comunicacao + ComunicacaoService + permissão comunicacoes.visualizar).
 *
 * Prova que:
 *   - comunicação pertence à PARTICIPAÇÃO certa (`candidatura_id`), não a um cadastro global;
 *   - o conteúdo é um SNAPSHOT imutável — editar o template depois não muda o histórico já gravado;
 *   - origem MANUAL exige usuário responsável; AUTOMATICA nunca usa usuário fake;
 *   - "enviada" não implica "entregue"; "entregue" não implica "visualizada" (timestamps
 *     complementares, nunca marcados por presunção);
 *   - multiline e emoji são preservados no snapshot;
 *   - `comunicacoes.visualizar` segue o mesmo mecanismo central de `Authorization`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

Comunicacao::ensureSchema();
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

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$mk = 'ZZCOM_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => [], 'comunicacoes' => [], 'candidaturas' => [], 'vagas' => []];

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    // ---- fixtures: Vaga + Candidatura (mesmo padrão de fixture_pipeline_kanban_candidate.php) --
    $cpf = str_pad((string)random_int(10000000000, 99999999999), 11, '0', STR_PAD_LEFT);
    $cpf2 = str_pad((string)random_int(10000000000, 99999999999), 11, '0', STR_PAD_LEFT);
    $vagaId = Vaga::create([
        'titulo' => 'Vaga Comunicacao ' . $suffix,
        'descricao' => 'Fixture de teste de comunicações.',
        'requisitos' => 'Requisito de fixture.',
        'area' => 'RH',
        'local' => 'Remoto',
        'ativo' => 1,
    ]);
    $criados['vagas'][] = $vagaId;

    $candidaturaId = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Candidato Comunicacao ' . $suffix,
        'email' => 'com_' . str_replace('-', '_', $suffix) . '@rhmadeplant.local',
        'telefone' => '67999999999',
        'cpf' => $cpf,
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Fixture de comunicações.',
        'pdf_path' => 'fixture-comunicacao.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $criados['candidaturas'][] = $candidaturaId;

    // Segunda candidatura -> comunicação não pode vazar entre participações diferentes.
    $candidaturaId2 = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Outro Candidato ' . $suffix,
        'email' => 'com2_' . str_replace('-', '_', $suffix) . '@rhmadeplant.local',
        'telefone' => '67988888888',
        'cpf' => $cpf2,
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Fixture de comunicações 2.',
        'pdf_path' => 'fixture-comunicacao-2.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $criados['candidaturas'][] = $candidaturaId2;

    $rhUsuarioId = $mkUser('rh', 'rh');

    // ---- 1/2. comunicação pertence à candidatura (participação) certa --------------------------
    $criacao = Comunicacao::create([
        'candidatura_id' => $candidaturaId,
        'conteudo' => "Olá, Maria.\n📅 Data: 18/09/2026\n🕒 Horário: 14:00",
        'origem' => 'MANUAL',
        'usuario_id' => $rhUsuarioId,
    ]);
    $check(($criacao['ok'] ?? false) === true, 'Comunicacao::create() aceita comunicação manual com usuário responsável');
    $comId = (int)($criacao['id'] ?? 0);
    $criados['comunicacoes'][] = $comId;

    $doCandidato1 = Comunicacao::allByCandidatura($candidaturaId);
    $doCandidato2 = Comunicacao::allByCandidatura($candidaturaId2);
    $check(count($doCandidato1) === 1 && (int)$doCandidato1[0]['id'] === $comId, 'comunicação aparece no histórico da candidatura correta');
    $check(count($doCandidato2) === 0, 'comunicação NÃO aparece no histórico de outra participação/candidatura');

    // ---- 3. snapshot imutável: editar o template depois não muda o histórico -------------------
    $codigoTemplateTeste = strtolower($mk) . '_template';
    $criadoTemplate = Mensagem::create([
        'codigo' => $codigoTemplateTeste,
        'titulo' => 'Template de teste de snapshot',
        'conteudo' => 'Olá, [Nome]. Texto original.',
        'ativo' => 1,
    ]);
    $check(($criadoTemplate['ok'] ?? false) === true, 'template de teste criado para o cenário de snapshot');
    $templateId = (int)($criadoTemplate['id'] ?? 0);

    $prepared = ComunicacaoService::prepararDeTemplate($codigoTemplateTeste, $candidaturaId, ['Nome' => 'Maria'], 'MANUAL', $rhUsuarioId);
    $check(($prepared['ok'] ?? false) === true, 'ComunicacaoService::prepararDeTemplate() renderiza e grava o snapshot');
    $comSnapshotId = (int)($prepared['id'] ?? 0);
    $criados['comunicacoes'][] = $comSnapshotId;

    $antesDaEdicao = Comunicacao::find($comSnapshotId);
    $check($antesDaEdicao['conteudo'] === 'Olá, Maria. Texto original.', 'snapshot gravado com o texto renderizado no momento da preparação');

    // Edita o TEMPLATE depois — o histórico já gravado não pode mudar.
    Mensagem::update($templateId, ['titulo' => 'x', 'conteudo' => 'Texto COMPLETAMENTE diferente [Nome].', 'ativo' => 1]);
    $depoisDaEdicao = Comunicacao::find($comSnapshotId);
    $check($depoisDaEdicao['conteudo'] === 'Olá, Maria. Texto original.', 'editar o template DEPOIS não altera o snapshot já gravado no histórico');
    $check($depoisDaEdicao['conteudo'] === $antesDaEdicao['conteudo'], 'conteúdo do histórico é idêntico antes/depois da edição do template');

    // ---- 4/5. responsável humano preservado / origem automática sem usuário fake ---------------
    $manualSemUsuario = Comunicacao::create(['candidatura_id' => $candidaturaId, 'conteudo' => 'x', 'origem' => 'MANUAL']);
    $check(($manualSemUsuario['ok'] ?? true) === false, 'origem MANUAL sem usuário responsável é rejeitada');

    $autoComUsuario = Comunicacao::create(['candidatura_id' => $candidaturaId, 'conteudo' => 'x', 'origem' => 'AUTOMATICA', 'usuario_id' => $rhUsuarioId]);
    $check(($autoComUsuario['ok'] ?? true) === false, 'origem AUTOMATICA com usuário associado é rejeitada (nunca finge que foi um humano)');

    $autoOk = Comunicacao::create(['candidatura_id' => $candidaturaId, 'conteudo' => 'Mensagem automática de teste.', 'origem' => 'AUTOMATICA']);
    $check(($autoOk['ok'] ?? false) === true, 'origem AUTOMATICA sem usuário é aceita — nenhum usuário "Sistema" fake é exigido');
    if ($autoOk['ok'] ?? false) {
        $criados['comunicacoes'][] = (int)$autoOk['id'];
        $rowAuto = Comunicacao::find((int)$autoOk['id']);
        $check($rowAuto['usuario_id'] === null, 'comunicação automática realmente fica com usuario_id NULL (não aponta pra ninguém)');
    }

    // ---- 6/7. enviada não implica entregue; entregue não implica visualizada -------------------
    $novaId = (int)(Comunicacao::create(['candidatura_id' => $candidaturaId, 'conteudo' => 'Teste de estados.', 'origem' => 'MANUAL', 'usuario_id' => $rhUsuarioId])['id'] ?? 0);
    $criados['comunicacoes'][] = $novaId;
    Comunicacao::marcarEnviada($novaId, 'ext-123');
    $rowEnviada = Comunicacao::find($novaId);
    $check($rowEnviada['situacao'] === 'enviada' && $rowEnviada['entregue_em'] === null, "'enviada' não seta entregue_em automaticamente — nenhuma presunção de entrega");
    $check($rowEnviada['identificador_externo'] === 'ext-123', 'identificador externo do provedor é preparado para uso futuro');

    Comunicacao::marcarEntregue($novaId);
    $rowEntregue = Comunicacao::find($novaId);
    $check($rowEntregue['entregue_em'] !== null, 'marcarEntregue() (só chamado com confirmação real) grava entregue_em');
    $check($rowEntregue['visualizada_em'] === null, "'entregue' não implica 'visualizada' — timestamp continua NULL até confirmação própria");

    Comunicacao::marcarVisualizada($novaId);
    $rowVisualizada = Comunicacao::find($novaId);
    $check($rowVisualizada['visualizada_em'] !== null, 'marcarVisualizada() grava visualizada_em separadamente');

    // ---- 8/9/10. timestamps, multiline e emoji preservados no snapshot -------------------------
    $check(!empty($rowVisualizada['created_at']), 'created_at é preservado');
    $multilineId = (int)(Comunicacao::create([
        'candidatura_id' => $candidaturaId,
        'conteudo' => "Olá, Maria.\n\n📅 Data: 18/09/2026\n🕒 Horário: 14:00\nAtenciosamente,\nEquipe de RH",
        'origem' => 'MANUAL',
        'usuario_id' => $rhUsuarioId,
    ])['id'] ?? 0);
    $criados['comunicacoes'][] = $multilineId;
    $rowMultiline = Comunicacao::find($multilineId);
    $check(str_contains($rowMultiline['conteudo'], "Maria.\n\n📅"), 'quebra de linha dupla e emoji preservados literalmente no snapshot gravado');

    // ---- 11. usuário sem comunicacoes.visualizar não acessa o histórico protegido ---------------
    $adminId = $mkUser('admin', 'admin');
    $gestorId = $mkUser('gestor', 'viewer');
    $check(Authorization::usuarioTemPermissao($adminId, 'comunicacoes.visualizar') === true, 'Admin acessa o histórico de comunicação pelo bypass central');
    $check(Authorization::usuarioTemPermissao($rhUsuarioId, 'comunicacoes.visualizar') === false, 'RH sem permissão individual NÃO acessa o histórico só por role=rh');
    $check(Authorization::usuarioTemPermissao($gestorId, 'comunicacoes.visualizar') === false, 'usuário sem a permissão não acessa o histórico de comunicação');

    $reflexao = new ReflectionMethod(AdminCandidaturasController::class, 'show');
    $arquivo = new SplFileObject($reflexao->getFileName());
    $arquivo->seek($reflexao->getStartLine() - 1);
    $corpo = '';
    while ($arquivo->key() < $reflexao->getEndLine()) {
        $corpo .= $arquivo->current();
        $arquivo->next();
    }
    $check(str_contains($corpo, "Authorization::temPermissao('comunicacoes.visualizar')"), 'AdminCandidaturasController::show() checa comunicacoes.visualizar no backend antes de buscar os dados (não só a view esconde)');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nCOMUNICACOES_CANDIDATO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['comunicacoes'] as $id) {
        $pdo->prepare('DELETE FROM comunicacoes WHERE id = ?')->execute([(int)$id]);
    }
    if (isset($templateId)) {
        $pdo->prepare('DELETE FROM mensagens WHERE id = ?')->execute([(int)$templateId]);
    }
    foreach ($criados['candidaturas'] as $id) {
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['vagas'] as $id) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
