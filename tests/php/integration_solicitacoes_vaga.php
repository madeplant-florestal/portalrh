<?php
require __DIR__ . '/../../app/core/bootstrap.php';

SolicitacaoVaga::ensureSchema();

$pdo = Database::conn();
$createdId = null;
$adminUser = null;
$actorVagaAccessTouched = false;
$originalActorVagaAccess = null;
$usuarioSetorConcedido = null;
$cleanup = [
    'vagas' => [],
    'centros' => [],
    'colaboradores' => [],
    'cargos' => [],
    'setores' => [],
    'usuarios_extra' => [],
];

try {
    $adminStmt = $pdo->query("SELECT id, role, is_supervisor FROM usuarios ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh', 'viewer'), id ASC LIMIT 1");
    $adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);
    if (!$adminUser) {
        throw new RuntimeException('Nenhum usuário disponível para executar o teste.');
    }

    // Sprint 2026-09-09: autorização e hierarquia de aprovação vivem em `usuarios`. Para exercitar
    // o fluxo completo de 2 etapas (líder -> RH), o usuário-ator precisa ter um aprovador.
    $approverId = (int)$pdo->query(
        "SELECT id FROM usuarios WHERE email_verified_at IS NOT NULL AND id <> " . (int)$adminUser['id'] . " ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if ($approverId <= 0) {
        $approverId = User::create('Aprovador Teste Integracao', 'aprovador.teste.integracao.' . uniqid() . '@example.com', password_hash('x', PASSWORD_BCRYPT), 'rh');
        User::setActiveStatus($approverId, true);
        $cleanup['usuarios_extra'][] = $approverId;
    }
    $originalActorVagaAccess = $pdo->query(
        "SELECT pode_solicitar_vaga, aprovador_usuario_id FROM usuarios WHERE id = " . (int)$adminUser['id']
    )->fetch(PDO::FETCH_ASSOC) ?: null;
    $pdo->prepare("UPDATE usuarios SET pode_solicitar_vaga = 1, aprovador_usuario_id = ? WHERE id = ?")
        ->execute([$approverId, (int)$adminUser['id']]);
    $actorVagaAccessTouched = true;

    // ---- Sprint Solicitação de Vaga Etapa 2 (Contexto Organizacional) ------
    // Cargo oficial e Setor oficial são independentes; o Setor da vaga vem do contexto do
    // SOLICITANTE (usuario_setores), não de `usuario_colaboradores`/gestor. Cria fixtures oficiais
    // dedicadas e concede o Setor ao ator via INSERT direto (não via
    // UsuarioContextoOrganizacionalService::definirContextoManual, que substituiria as linhas
    // MANUAL já existentes do usuário real usado como ator neste teste).
    $sufixo = substr(md5(uniqid('', true)), 0, 5);
    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZI' . $sufixo, 'SETOR TESTE INTEGRACAO ' . $sufixo, 'setor-teste-integracao-' . $sufixo, 'RHMADEPLANT']);
    $setorId = (int)$pdo->lastInsertId();
    $cleanup['setores'][] = $setorId;

    $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZI' . $sufixo, 'CARGO TESTE INTEGRACAO ' . $sufixo, 'cargo-teste-integracao-' . $sufixo, 'RHMADEPLANT']);
    $cargoId = (int)$pdo->lastInsertId();
    $cleanup['cargos'][] = $cargoId;

    $pdo->prepare('INSERT INTO centros_custo (setor_id, codigo, nome, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$setorId, 'CCTI' . $sufixo, 'Centro de custo teste ' . $sufixo]);
    $centroId = (int)$pdo->lastInsertId();
    $cleanup['centros'][] = $centroId;

    $pdo->prepare("INSERT IGNORE INTO usuario_setores (usuario_id, setor_id, principal, origem) VALUES (?, ?, 0, 'MANUAL')")
        ->execute([(int)$adminUser['id'], $setorId]);
    $usuarioSetorConcedido = ['usuario_id' => (int)$adminUser['id'], 'setor_id' => $setorId];

    $deps = SolicitacaoVaga::formDependencies((int)$adminUser['id'], (int)$adminUser['id']);
    $cargo = null;
    foreach ($deps['cargos_oficiais'] as $item) {
        if ((int)$item['id'] === $cargoId) {
            $cargo = $item;
            break;
        }
    }
    if (!$cargo) {
        throw new RuntimeException('Cargo oficial de teste não apareceu em cargos_oficiais().');
    }

    $colaboradorContratado = $pdo->query('SELECT id, nome FROM colaboradores ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$colaboradorContratado) {
        $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id) VALUES (?, ?, ?)')
            ->execute(['COLABORADOR TESTE INTEGRACAO ' . $sufixo, 'colaborador-teste-integracao-' . $sufixo, $cargoId]);
        $novoColaboradorId = (int)$pdo->lastInsertId();
        $cleanup['colaboradores'][] = $novoColaboradorId;
        $colaboradorContratado = ['id' => $novoColaboradorId, 'nome' => 'COLABORADOR TESTE INTEGRACAO ' . $sufixo];
    }

    $beneficios = $deps['beneficios_by_cargo'][$cargoId] ?? [];
    $competenciasTecnicas = $deps['competencias']['tecnica'] ?? [];
    $competenciasComportamentais = $deps['competencias']['comportamental'] ?? [];

    $payload = [
        'setor_id' => $setorId,
        'quantidade_vagas' => 1,
        'cargo_id' => $cargoId,
        'maquina_operada' => !empty($cargo['requires_machine_description']) ? 'Harvester' : '',
        'tipo_vaga' => 'nova_posicao',
        'tipo_contratacao' => 'clt',
        'salario_previsto' => 'R$ ' . number_format((float)$cargo['salario_min'], 2, ',', '.'),
        'beneficio_ids' => array_slice(array_map(static fn(array $row): int => (int)$row['id'], $beneficios), 0, 2),
        'centro_custo_id' => $centroId,
        'previsto_orcamento' => '1',
        'jornada_trabalho' => '44h semanais',
        'escala' => '5x2',
        'turno' => 'diurno',
        'escolaridade_minima' => 'medio',
        'formacao_academica' => 'Ensino médio completo',
        'experiencia_necessaria' => 'Experiência prévia em processos de rotina da área solicitante.',
        'entregas_esperadas' => str_repeat('Resultado esperado com aderência aos indicadores da área e conformidade com as políticas internas. ', 2),
        'competencia_tecnica_ids' => array_slice(array_map(static fn(array $row): int => (int)$row['id'], $competenciasTecnicas), 0, 2),
        'competencia_comportamental_ids' => array_slice(array_map(static fn(array $row): int => (int)$row['id'], $competenciasComportamentais), 0, 2),
        'nivel_responsabilidade' => 'operacional',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+20 days')),
        'urgencia' => 'alta',
        'data_limite_fechamento' => date('d/m/Y', strtotime('+35 days')),
    ];

    $createdId = SolicitacaoVaga::create($payload, (int)$adminUser['id'], '127.0.0.1');
    if ($createdId <= 0) {
        throw new RuntimeException('A criação da solicitação não retornou um ID válido.');
    }

    // Segurança: o solicitante persistido é SEMPRE o usuário autenticado (parâmetro do backend),
    // nunca um id vindo do formulário — a menos que o ator seja Admin/RH/supervisor escolhendo
    // OUTRO usuário elegível (Etapa 2); aqui o ator não pediu outro solicitante, então é ele mesmo.
    $solicitanteRow = (int)$pdo->query('SELECT solicitante_usuario_id FROM solicitacoes_vaga WHERE id = ' . (int)$createdId)->fetchColumn();
    if ($solicitanteRow !== (int)$adminUser['id']) {
        throw new RuntimeException('O solicitante deveria ser o usuário autenticado.');
    }
    // Adulteração: um id de usuário inexistente enviado no POST é rejeitado (Etapa 2 valida o
    // usuário-alvo mesmo quando o ator tem permissão para agir em nome de outro).
    $payloadAdulterado = array_merge($payload, ['solicitante_usuario_id' => 999999]);
    $adulteracaoRejeitada = false;
    try {
        SolicitacaoVaga::create($payloadAdulterado, (int)$adminUser['id'], '127.0.0.1');
    } catch (\InvalidArgumentException $e) {
        $adulteracaoRejeitada = stripos($e->getMessage(), 'não foi encontrado') !== false || stripos($e->getMessage(), 'não tem permissão') !== false;
    }
    if (!$adulteracaoRejeitada) {
        throw new RuntimeException('Tentativa de adulterar o solicitante com um id inexistente deveria ser rejeitada.');
    }

    // Autorização canônica (migration 2026-09-09): `usuarios.pode_solicitar_vaga`. Um usuário
    // comum sem a flag é rejeitado no backend, independente da tela e sem depender de
    // `usuario_colaboradores`.
    $comumSemPermissaoId = User::create('Comum Sem Permissao', 'comum.sem.permissao.' . uniqid() . '@example.com', password_hash('x', PASSWORD_BCRYPT), 'viewer');
    User::setActiveStatus($comumSemPermissaoId, true);
    $cleanup['usuarios_extra'][] = $comumSemPermissaoId;
    $rejeitou = false;
    try {
        SolicitacaoVaga::create($payload, $comumSemPermissaoId, '127.0.0.1');
    } catch (\InvalidArgumentException $e) {
        $rejeitou = stripos($e->getMessage(), 'autoriz') !== false;
    }
    if (!$rejeitou) {
        throw new RuntimeException('create() deveria rejeitar usuário comum sem usuarios.pode_solicitar_vaga.');
    }

    $record = SolicitacaoVaga::findAccessible($createdId, (int)$adminUser['id'], (string)$adminUser['role'], (int)$adminUser['is_supervisor'] === 1);
    if (!$record) {
        throw new RuntimeException('A solicitação criada não pôde ser recuperada.');
    }

    $leaderApproval = null;
    foreach (($record['aprovacoes'] ?? []) as $approval) {
        if (($approval['etapa'] ?? '') === 'lider_imediato') {
            $leaderApproval = $approval;
            break;
        }
    }
    $leaderActorId = (int)($leaderApproval['destinatario_usuario_id'] ?? $adminUser['id']);

    $leaderResult = SolicitacaoVaga::approve($createdId, 'lider_imediato', $leaderActorId, true, true, 'aprovado', 'Aprovado no teste de integração.', '127.0.0.1');
    if (!($leaderResult['ok'] ?? false)) {
        throw new RuntimeException('Falha na aprovação do líder imediato: ' . ($leaderResult['error'] ?? 'erro desconhecido'));
    }

    $rhResult = SolicitacaoVaga::approve($createdId, 'rh', (int)$adminUser['id'], true, true, 'aprovado', 'Aprovado pelo RH no teste de integração.', '127.0.0.1');
    if (!($rhResult['ok'] ?? false)) {
        throw new RuntimeException('Falha na aprovação do RH: ' . ($rhResult['error'] ?? 'erro desconhecido'));
    }

    // Sprint Solicitação/Publicação de Vagas: a aprovação do RH gera automaticamente o RASCUNHO
    // da vaga pública (ativo=0, invisível), vinculado à solicitação.
    $vagaGerada = $pdo->prepare('SELECT * FROM vagas WHERE solicitacao_vaga_id = ? LIMIT 1');
    $vagaGerada->execute([$createdId]);
    $vagaRow = $vagaGerada->fetch(PDO::FETCH_ASSOC);
    if (!$vagaRow) {
        throw new RuntimeException('A aprovação do RH deveria ter gerado o rascunho da vaga.');
    }
    $cleanup['vagas'][] = (int)$vagaRow['id'];
    if ((int)$vagaRow['ativo'] !== 0 || $vagaRow['publicada_em'] !== null) {
        throw new RuntimeException('A vaga gerada deveria nascer em rascunho (ativo=0, publicada_em NULL).');
    }
    if ((int)($rhResult['vaga_id'] ?? 0) !== (int)$vagaRow['id']) {
        throw new RuntimeException('approve() deveria retornar o vaga_id gerado.');
    }
    // Idempotência: gerar de novo não cria uma segunda vaga.
    $segundaTentativa = (new SolicitacaoVagaPublicacaoService())->gerarRascunho($createdId, (int)$adminUser['id'], '127.0.0.1');
    if (($segundaTentativa['vaga_id'] ?? 0) !== (int)$vagaRow['id'] || ($segundaTentativa['ja_existia'] ?? false) !== true) {
        throw new RuntimeException('gerarRascunho() deveria ser idempotente.');
    }
    $qtdVagas = (int)$pdo->query('SELECT COUNT(*) FROM vagas WHERE solicitacao_vaga_id = ' . (int)$createdId)->fetchColumn();
    if ($qtdVagas !== 1) {
        throw new RuntimeException('Nunca pode existir mais de uma vaga por solicitação.');
    }
    // Enquanto rascunho, NÃO aparece na consulta pública.
    $idsPublicas = array_map(static fn(array $r): int => (int)$r['id'], Vaga::allActive());
    if (in_array((int)$vagaRow['id'], $idsPublicas, true)) {
        throw new RuntimeException('A vaga em rascunho não pode aparecer na página pública.');
    }
    // Publicação pelo RH -> vai ao ar.
    $pub = (new SolicitacaoVagaPublicacaoService())->publicar((int)$vagaRow['id'], (int)$adminUser['id'], '127.0.0.1');
    if (!($pub['ok'] ?? false)) {
        throw new RuntimeException('Falha ao publicar a vaga: ' . ($pub['error'] ?? '?'));
    }
    $vagaPub = Vaga::find((int)$vagaRow['id']);
    if ((int)$vagaPub['ativo'] !== 1 || empty($vagaPub['publicada_em'])) {
        throw new RuntimeException('Após publicar, a vaga deveria estar ativa e com publicada_em carimbado.');
    }
    $idsPublicasDepois = array_map(static fn(array $r): int => (int)$r['id'], Vaga::allActive());
    if (!in_array((int)$vagaRow['id'], $idsPublicasDepois, true)) {
        throw new RuntimeException('Após publicar, a vaga deveria aparecer na consulta pública.');
    }
    // Rastreabilidade: auditoria da solicitação registra geração e publicação.
    $eventos = $pdo->prepare("SELECT event_type FROM solicitacao_vaga_auditoria WHERE solicitacao_id = ? AND event_type IN ('vaga_rascunho_gerada','vaga_publicada')");
    $eventos->execute([$createdId]);
    $tiposEvento = $eventos->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vaga_rascunho_gerada', $tiposEvento, true) || !in_array('vaga_publicada', $tiposEvento, true)) {
        throw new RuntimeException('A auditoria deveria registrar a geração e a publicação da vaga.');
    }

    $rhControl = SolicitacaoVaga::saveRhControl($createdId, [
        'nome_contratado_colaborador_id' => (int)$colaboradorContratado['id'],
        'data_admissao' => date('d/m/Y', strtotime('+40 days')),
        'avaliacao_90_dias' => 'atendeu_plenamente',
        'observacoes_rh' => 'Registro validado automaticamente pelo teste de integração.',
    ], (int)$adminUser['id'], '127.0.0.1');
    if (!($rhControl['ok'] ?? false)) {
        throw new RuntimeException('Falha no controle interno de RH: ' . ($rhControl['error'] ?? 'erro desconhecido'));
    }

    $finalRecord = SolicitacaoVaga::findAccessible($createdId, (int)$adminUser['id'], (string)$adminUser['role'], (int)$adminUser['is_supervisor'] === 1);
    if (($finalRecord['status_fluxo'] ?? '') !== 'concluida') {
        throw new RuntimeException('O status final esperado era "concluida".');
    }
    if (empty($finalRecord['tempo_fechamento_dias'])) {
        throw new RuntimeException('O campo tempo_fechamento_dias não foi calculado.');
    }

    echo "SOLICITACAO_FLOW_OK\n";
} finally {
    foreach ($cleanup['vagas'] as $vagaId) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([(int)$vagaId]);
    }
    if ($createdId) {
        $stmt = $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?');
        $stmt->execute([$createdId]);
    }
    if ($usuarioSetorConcedido !== null) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ? AND setor_id = ?')
            ->execute([$usuarioSetorConcedido['usuario_id'], $usuarioSetorConcedido['setor_id']]);
    }
    foreach ($cleanup['centros'] as $centroId) {
        $pdo->prepare('DELETE FROM centros_custo WHERE id = ?')->execute([(int)$centroId]);
    }
    foreach ($cleanup['colaboradores'] as $colaboradorId) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([(int)$colaboradorId]);
    }
    foreach ($cleanup['cargos'] as $cargoId) {
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$cargoId]);
    }
    foreach ($cleanup['setores'] as $setorId) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$setorId]);
    }
    if ($actorVagaAccessTouched && $adminUser) {
        $pdo->prepare('UPDATE usuarios SET pode_solicitar_vaga = ?, aprovador_usuario_id = ? WHERE id = ?')->execute([
            (int)($originalActorVagaAccess['pode_solicitar_vaga'] ?? 0),
            isset($originalActorVagaAccess['aprovador_usuario_id']) && $originalActorVagaAccess['aprovador_usuario_id'] !== null
                ? (int)$originalActorVagaAccess['aprovador_usuario_id'] : null,
            (int)$adminUser['id'],
        ]);
    }
    foreach ($cleanup['usuarios_extra'] as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$uid]);
    }
}
