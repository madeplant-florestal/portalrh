<?php
require __DIR__ . '/../../app/core/bootstrap.php';

SolicitacaoVaga::ensureSchema();

$pdo = Database::conn();
$createdId = null;
$originalUserLink = null;
$userLinkTouched = false;
$adminUser = null;
$cleanup = [
    'centros' => [],
    'colaboradores' => [],
    'cargos' => [],
    'setores' => [],
];

try {
    $adminStmt = $pdo->query("SELECT id, role, is_supervisor FROM usuarios ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh', 'viewer'), id ASC LIMIT 1");
    $adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);
    if (!$adminUser) {
        throw new RuntimeException('Nenhum usuário disponível para executar o teste.');
    }

    $deps = SolicitacaoVaga::formDependencies((int)$adminUser['id']);
    if (empty($deps['setores'])) {
        $stmt = $pdo->prepare("INSERT INTO setores (nome, slug, ativo) VALUES (?, ?, 1)");
        $stmt->execute(['SETOR TESTE INTEGRACAO', 'setor-teste-integracao']);
        $cleanup['setores'][] = (int)$pdo->lastInsertId();
    }
    if (empty($deps['cargos'])) {
        $stmt = $pdo->prepare("INSERT INTO cargos (nome, slug, ativo) VALUES (?, ?, 1)");
        $stmt->execute(['CARGO TESTE INTEGRACAO', 'cargo-teste-integracao']);
        $cleanup['cargos'][] = (int)$pdo->lastInsertId();
    }
    $deps = SolicitacaoVaga::formDependencies((int)$adminUser['id']);

    $gestor = null;
    $cargo = null;
    $centro = null;
    $setorId = 0;
    foreach ($deps['gestores'] as $gestorCandidate) {
        $candidateSetorId = (int)$gestorCandidate['setor_id'];
        $candidateCargo = null;
        foreach ($deps['cargos'] as $item) {
            if (in_array($candidateSetorId, array_map('intval', $item['setor_ids'] ?? []), true)) {
                $candidateCargo = $item;
                break;
            }
        }
        if (!$candidateCargo) {
            continue;
        }

        $candidateCentro = null;
        foreach ($deps['centros_custo'] as $item) {
            if ((int)$item['setor_id'] === $candidateSetorId) {
                $candidateCentro = $item;
                break;
            }
        }
        if (!$candidateCentro) {
            continue;
        }

        $gestor = $gestorCandidate;
        $cargo = $candidateCargo;
        $centro = $candidateCentro;
        $setorId = $candidateSetorId;
        break;
    }
    if (!$gestor || !$cargo || !$centro || $setorId <= 0) {
        $linkStmt = $pdo->prepare("SELECT * FROM usuario_colaboradores WHERE usuario_id = ? LIMIT 1");
        $linkStmt->execute([(int)$adminUser['id']]);
        $originalUserLink = $linkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $candidateStmt = $pdo->query(
            "SELECT c.id, c.setor_id, c.cargo_id
             FROM colaboradores c
             LEFT JOIN usuario_colaboradores uc ON uc.colaborador_id = c.id
             WHERE c.ativo = 1 AND c.setor_id IS NOT NULL AND c.cargo_id IS NOT NULL
               AND (uc.id IS NULL OR uc.usuario_id = " . (int)$adminUser['id'] . ")
             ORDER BY c.id ASC
             LIMIT 1"
        );
        $candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$candidate) {
            $fallbackSetorId = !empty($deps['setores']) ? (int)$deps['setores'][0]['id'] : (($cleanup['setores'][0] ?? 0) ?: 0);
            $fallbackCargoId = !empty($deps['cargos']) ? (int)$deps['cargos'][0]['id'] : (($cleanup['cargos'][0] ?? 0) ?: 0);
            if ($fallbackSetorId <= 0 || $fallbackCargoId <= 0) {
                throw new RuntimeException('Nenhuma combinação válida de gestor, setor, cargo e centro de custo foi encontrada para o teste.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO colaboradores (nome, slug, cargo_id, empresa_id, setor_id, ativo)
                 VALUES (?, ?, ?, NULL, ?, 1)"
            );
            $stmt->execute([
                'COLABORADOR TESTE INTEGRACAO',
                'colaborador-teste-integracao-' . time(),
                $fallbackCargoId,
                $fallbackSetorId,
            ]);
            $newColaboradorId = (int)$pdo->lastInsertId();
            $cleanup['colaboradores'][] = $newColaboradorId;
            $candidate = [
                'id' => $newColaboradorId,
                'setor_id' => $fallbackSetorId,
                'cargo_id' => $fallbackCargoId,
            ];
        }

        $setorId = (int)$candidate['setor_id'];
        $pdo->prepare("INSERT IGNORE INTO cargo_setores (cargo_id, setor_id) VALUES (?, ?)")->execute([(int)$candidate['cargo_id'], $setorId]);
        $pdo->prepare(
            "INSERT IGNORE INTO centros_custo (setor_id, codigo, nome, ativo)
             VALUES (?, ?, ?, 1)"
        )->execute([$setorId, sprintf('CC-TST-%03d', $setorId), 'Centro de custo teste ' . $setorId]);
        $centroId = (int)$pdo->lastInsertId();
        if ($centroId > 0) {
            $cleanup['centros'][] = $centroId;
        }

        if ($originalUserLink) {
            $pdo->prepare(
                "UPDATE usuario_colaboradores
                 SET colaborador_id = ?, is_gestor = 1, is_rh = ?, ativo = 1
                 WHERE id = ?"
            )->execute([
                (int)$candidate['id'],
                strtolower((string)$adminUser['role']) === 'rh' ? 1 : 0,
                (int)$originalUserLink['id'],
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, lider_colaborador_id, ativo)
                 VALUES (?, ?, 1, ?, NULL, 1)"
            )->execute([
                (int)$adminUser['id'],
                (int)$candidate['id'],
                strtolower((string)$adminUser['role']) === 'rh' ? 1 : 0,
            ]);
        }
        $userLinkTouched = true;

        foreach ($deps['cargos'] as $item) {
            if ((int)$item['id'] === (int)$candidate['cargo_id']) {
                $cargo = $item;
                break;
            }
        }
        foreach ($deps['centros_custo'] as $item) {
            if ((int)$item['setor_id'] === $setorId) {
                $centro = $item;
                break;
            }
        }

        $gestor = [
            'colaborador_id' => (int)$candidate['id'],
            'setor_id' => $setorId,
        ];

        if (!$cargo || !$centro || $setorId <= 0) {
            throw new RuntimeException('Não foi possível preparar um vínculo mínimo de gestor para o teste.');
        }
    }

    $beneficios = $deps['beneficios_by_cargo'][(int)$cargo['id']] ?? [];
    $competenciasTecnicas = $deps['competencias']['tecnica'] ?? [];
    $competenciasComportamentais = $deps['competencias']['comportamental'] ?? [];
    $colaboradorContratado = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] !== (int)$gestor['colaborador_id']) {
            $colaboradorContratado = $colaborador;
            break;
        }
    }
    if (!$colaboradorContratado && !empty($deps['colaboradores'][0])) {
        $colaboradorContratado = $deps['colaboradores'][0];
    }
    if (!$colaboradorContratado) {
        throw new RuntimeException('Nenhum colaborador disponível para validar o controle interno RH.');
    }

    $payload = [
        'setor_id' => $setorId,
        'quantidade_vagas' => 1,
        'cargo_id' => (int)$cargo['id'],
        'maquina_operada' => !empty($cargo['requires_machine_description']) ? 'Harvester' : '',
        'gestor_solicitante_colaborador_id' => (int)$gestor['colaborador_id'],
        'tipo_vaga' => 'nova_posicao',
        'tipo_contratacao' => 'clt',
        'salario_previsto' => 'R$ ' . number_format((float)$cargo['salario_min'], 2, ',', '.'),
        'beneficio_ids' => array_slice(array_map(static fn(array $row): int => (int)$row['id'], $beneficios), 0, 2),
        'centro_custo_id' => (int)$centro['id'],
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
    if ($createdId) {
        $stmt = $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?');
        $stmt->execute([$createdId]);
    }
    if ($userLinkTouched) {
        if ($originalUserLink) {
            $pdo->prepare(
                "UPDATE usuario_colaboradores
                 SET colaborador_id = ?, is_gestor = ?, is_rh = ?, lider_colaborador_id = ?, ativo = ?
                 WHERE id = ?"
            )->execute([
                (int)$originalUserLink['colaborador_id'],
                (int)$originalUserLink['is_gestor'],
                (int)$originalUserLink['is_rh'],
                $originalUserLink['lider_colaborador_id'] !== null ? (int)$originalUserLink['lider_colaborador_id'] : null,
                (int)$originalUserLink['ativo'],
                (int)$originalUserLink['id'],
            ]);
        } else {
            $pdo->prepare('DELETE FROM usuario_colaboradores WHERE usuario_id = ?')->execute([(int)$adminUser['id']]);
        }
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
}
