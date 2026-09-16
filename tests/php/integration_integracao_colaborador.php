<?php

/**
 * Integração — Sprint "Integração do Colaborador" (migration 2026-09-16-integracao-colaborador.sql
 * + Colaborador::updateIntegracao() + permissões integracao_colaborador.visualizar/editar).
 *
 * Prova que:
 *   - status padrão é Pendente;
 *   - Pendente aceita data/responsável vazios;
 *   - Realizada exige data E responsável;
 *   - responsável referencia `usuarios.id` (rejeita ID inexistente), nunca texto livre;
 *   - as permissões seguem o mesmo mecanismo central de `Authorization`;
 *   - NENHUMA escrita acontece no METADADOS nem em `colaboradores_metadados`.
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

$mk = 'ZZINT_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => [], 'colaboradores' => []];

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    // ---- fixture: colaborador local (mesmo padrão de integration_admin_colaboradores_metadados.php)
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($cargoId <= 0) {
        throw new RuntimeException('Nenhum cargo disponível para montar o cenário.');
    }
    $totalMetadadosAntes = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores_metadados')->fetchColumn();

    $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id, ativo) VALUES (?, ?, ?, 1)')
        ->execute(['COLAB ' . $mk, 'colab-' . strtolower($mk), $cargoId]);
    $colaboradorId = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $colaboradorId;

    $responsavelId = $mkUser('resp', 'rh');

    // ---- 1. status padrão Pendente -----------------------------------------------------------
    $inicial = Colaborador::find($colaboradorId);
    $check(strtolower((string)$inicial['integracao_status']) === 'pendente', 'status padrão de integração é Pendente');
    $check($inicial['integracao_data'] === null, 'data de integração começa vazia');
    $check($inicial['integracao_responsavel_usuario_id'] === null, 'responsável pela integração começa vazio');

    // ---- 2. Pendente aceita data vazia ---------------------------------------------------------
    $pendenteOk = Colaborador::updateIntegracao($colaboradorId, ['integracao_status' => 'pendente']);
    $check(($pendenteOk['ok'] ?? false) === true, 'salvar como Pendente sem data/responsável é aceito');

    // ---- 3. Realizada exige data ---------------------------------------------------------------
    $semData = Colaborador::updateIntegracao($colaboradorId, ['integracao_status' => 'realizada', 'integracao_responsavel_usuario_id' => (string)$responsavelId]);
    $check(($semData['ok'] ?? true) === false, 'Realizada SEM data é rejeitada');
    $check(strtolower((string)Colaborador::find($colaboradorId)['integracao_status']) === 'pendente', 'status continua Pendente após tentativa inválida (nada foi salvo)');

    // ---- 4. Realizada exige responsável ---------------------------------------------------------
    $semResponsavel = Colaborador::updateIntegracao($colaboradorId, ['integracao_status' => 'realizada', 'integracao_data' => '16/09/2026']);
    $check(($semResponsavel['ok'] ?? true) === false, 'Realizada SEM responsável é rejeitada');

    // ---- 5. responsável referencia usuário válido -----------------------------------------------
    $responsavelInvalido = Colaborador::updateIntegracao($colaboradorId, [
        'integracao_status' => 'realizada',
        'integracao_data' => '16/09/2026',
        'integracao_responsavel_usuario_id' => '999999999',
    ]);
    $check(($responsavelInvalido['ok'] ?? true) === false, 'responsável com ID de usuário inexistente é rejeitado');

    $completo = Colaborador::updateIntegracao($colaboradorId, [
        'integracao_status' => 'realizada',
        'integracao_data' => '16/09/2026',
        'integracao_responsavel_usuario_id' => (string)$responsavelId,
    ]);
    $check(($completo['ok'] ?? false) === true, 'Realizada COM data e responsável válidos é aceita');

    $final = Colaborador::find($colaboradorId);
    $check(strtolower((string)$final['integracao_status']) === 'realizada', 'status gravado como Realizada');
    $check($final['integracao_data'] === '2026-09-16', 'data da integração gravada corretamente (aceita a data informada, não presume hoje)');
    $check((int)$final['integracao_responsavel_usuario_id'] === $responsavelId, 'responsável referencia usuarios.id, não texto livre');
    $check($final['integracao_responsavel_nome'] === 'USR resp', 'a tela consegue mostrar o NOME do responsável (via JOIN com usuarios)');

    // ---- 6. permissões ---------------------------------------------------------------------------
    $adminId = $mkUser('admin', 'admin');
    $rhId = $mkUser('rh', 'rh');
    $gestorId = $mkUser('gestor', 'viewer');

    $check(Authorization::usuarioTemPermissao($adminId, 'integracao_colaborador.visualizar') === true, 'Admin visualiza integração pelo bypass central');
    $check(Authorization::usuarioTemPermissao($adminId, 'integracao_colaborador.editar') === true, 'Admin edita integração pelo bypass central');
    $check(Authorization::usuarioTemPermissao($rhId, 'integracao_colaborador.visualizar') === false, 'RH sem permissão individual NÃO visualiza integração só por role=rh');
    $check(Authorization::usuarioTemPermissao($rhId, 'integracao_colaborador.editar') === false, 'RH sem permissão individual NÃO edita integração só por role=rh');
    $check(Authorization::usuarioTemPermissao($gestorId, 'integracao_colaborador.visualizar') === false, 'usuário sem a permissão não visualiza integração');
    $check(Authorization::usuarioTemPermissao($gestorId, 'integracao_colaborador.editar') === false, 'usuário sem a permissão não edita integração');

    // Fonte real do gate no controller: updateIntegracao() exige a permissão de editar no backend.
    $reflexao = new ReflectionMethod(AdminColaboradoresController::class, 'updateIntegracao');
    $arquivo = new SplFileObject($reflexao->getFileName());
    $arquivo->seek($reflexao->getStartLine() - 1);
    $corpo = '';
    while ($arquivo->key() < $reflexao->getEndLine()) {
        $corpo .= $arquivo->current();
        $arquivo->next();
    }
    $check(str_contains($corpo, "Authorization::requirePermissao('integracao_colaborador.editar')"), 'AdminColaboradoresController::updateIntegracao() exige integracao_colaborador.editar no backend');

    // ---- 7/8. nenhuma escrita no METADADOS / colaboradores_metadados -----------------------------
    $totalMetadadosDepois = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores_metadados')->fetchColumn();
    $check($totalMetadadosAntes === $totalMetadadosDepois, 'nenhuma linha foi inserida/removida em colaboradores_metadados por causa da Integração');

    $corpoUpdateIntegracao = (string)file_get_contents((new ReflectionClass('Colaborador'))->getFileName());
    $check(!str_contains($corpoUpdateIntegracao, 'MetadadosDatabase'), 'Colaborador.php não referencia MetadadosDatabase (nenhuma conexão ao SQL Server do METADADOS)');
    $check(!preg_match('/updateIntegracao[\s\S]{0,600}colaboradores_metadados/', $corpoUpdateIntegracao), 'updateIntegracao() não escreve em colaboradores_metadados');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nINTEGRACAO_COLABORADOR_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['colaboradores'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
