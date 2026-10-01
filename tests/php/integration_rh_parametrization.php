<?php
require __DIR__ . '/../../app/core/bootstrap.php';

MovimentacaoPessoal::ensureSchema();
AvaliacaoDesempenho::ensureSchema();

$pdo = Database::conn();
$createdEvaluationId = null;
$createdMovimentacaoId = null;
$restoredUserLink = null;
$originalColaborador = null;

try {
    $actor = $pdo->query(
        "SELECT id, nome, email, role, is_supervisor
         FROM usuarios
         WHERE is_supervisor = 1 OR role IN ('admin', 'rh')
         ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh'), id ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$actor) {
        throw new RuntimeException('Nenhum usuário administrador/RH disponível para o teste de parametrização de RH.');
    }

    $deps = MovimentacaoPessoal::formDependencies((int)$actor['id']);
    if (empty($deps['colaboradores']) || empty($deps['setores']) || empty($deps['cargos'])) {
        throw new RuntimeException('Dados mestres insuficientes para validar a parametrização de RH.');
    }

    $actorLinkStmt = $pdo->prepare("SELECT * FROM usuario_colaboradores WHERE usuario_id = ? ORDER BY id ASC LIMIT 1");
    $actorLinkStmt->execute([(int)$actor['id']]);
    $existingLink = $actorLinkStmt->fetch(PDO::FETCH_ASSOC);

    $gestorColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if (!empty($colaborador['setor_id'])) {
            $gestorColaborador = $colaborador;
            break;
        }
    }
    $gestorColaborador = $gestorColaborador ?: $deps['colaboradores'][0];

    if ($existingLink) {
        $pdo->prepare(
            "UPDATE usuario_colaboradores
             SET colaborador_id = ?, is_gestor = 1, ativo = 1
             WHERE id = ?"
        )->execute([
            (int)$gestorColaborador['id'],
            (int)$existingLink['id'],
        ]);
        $restoredUserLink = [
            'mode' => 'update',
            'row' => $existingLink,
        ];
    } else {
        $pdo->prepare(
            "INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, lider_colaborador_id, ativo)
             VALUES (?, ?, 1, ?, NULL, 1)"
        )->execute([
            (int)$actor['id'],
            (int)$gestorColaborador['id'],
            in_array(strtolower((string)$actor['role']), ['admin', 'rh'], true) ? 1 : 0,
        ]);
        $restoredUserLink = [
            'mode' => 'insert',
            'id' => (int)$pdo->lastInsertId(),
        ];
    }

    $deps = MovimentacaoPessoal::formDependencies((int)$actor['id']);

    // ============================================================================================
    // TRACK A — Parametrização manual de RH (matrícula/salário/datas)
    //
    // Colaborador::updateRhData() só permite editar estes campos manualmente quando o colaborador
    // NÃO tem metadados_id (tem_extensao_oficial=false — ver Colaborador::mesclarComEspelhoOficial());
    // com metadados_id, esses campos vêm do espelho oficial do METADADOS e são somente leitura.
    // ============================================================================================
    $semMetadadosRow = $pdo->prepare(
        "SELECT id FROM colaboradores
         WHERE ativo = 1 AND id != ? AND metadados_id IS NULL
         ORDER BY id ASC LIMIT 1"
    );
    $semMetadadosRow->execute([(int)$gestorColaborador['id']]);
    $semMetadadosRow = $semMetadadosRow->fetch(PDO::FETCH_ASSOC);
    if (!$semMetadadosRow) {
        throw new RuntimeException('Nenhum colaborador ativo sem metadados_id disponível para o teste de parametrização manual de RH.');
    }

    $targetColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] === (int)$semMetadadosRow['id']) {
            $targetColaborador = $colaborador;
            break;
        }
    }
    if (!$targetColaborador) {
        throw new RuntimeException('O colaborador sem metadados_id selecionado não apareceu nas dependências do formulário de movimentação.');
    }

    $originalColaborador = $pdo->prepare(
        "SELECT id, matricula, salario_atual, data_admissao, data_inicio_cargo
         FROM colaboradores
         WHERE id = ?
         LIMIT 1"
    );
    $originalColaborador->execute([(int)$targetColaborador['id']]);
    $originalColaborador = $originalColaborador->fetch(PDO::FETCH_ASSOC);

    if (!$originalColaborador) {
        throw new RuntimeException('Colaborador alvo não encontrado para o teste.');
    }

    $newMatricula = 'TST' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    $newSalary = 'R$ 4.321,98';
    $updateRh = Colaborador::updateRhData((int)$targetColaborador['id'], [
        'matricula' => $newMatricula,
        'salario_atual' => $newSalary,
        'data_admissao' => '15/01/2022',
        'data_inicio_cargo' => '01/03/2023',
    ]);

    if (!($updateRh['ok'] ?? false)) {
        throw new RuntimeException('Falha ao atualizar os dados RH do colaborador: ' . ($updateRh['error'] ?? 'erro desconhecido'));
    }

    $updatedColaborador = Colaborador::find((int)$targetColaborador['id']);
    if (!$updatedColaborador) {
        throw new RuntimeException('O colaborador não foi encontrado após a atualização RH.');
    }
    if (($updatedColaborador['matricula'] ?? '') !== $newMatricula) {
        throw new RuntimeException('A matrícula não foi persistida corretamente na parametrização RH.');
    }
    if ((float)($updatedColaborador['salario_atual'] ?? 0) !== 4321.98) {
        throw new RuntimeException('O salário atual não foi persistido corretamente na parametrização RH.');
    }
    if (($updatedColaborador['data_admissao'] ?? '') !== '2022-01-15') {
        throw new RuntimeException('A data de admissão não foi persistida corretamente.');
    }
    if (($updatedColaborador['data_inicio_cargo'] ?? '') !== '2023-03-01') {
        throw new RuntimeException('A data de início no cargo não foi persistida corretamente.');
    }

    $deps = MovimentacaoPessoal::formDependencies((int)$actor['id']);
    $dependencyColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] === (int)$targetColaborador['id']) {
            $dependencyColaborador = $colaborador;
            break;
        }
    }

    if (!$dependencyColaborador) {
        throw new RuntimeException('O colaborador atualizado não apareceu nas dependências do formulário de movimentação.');
    }
    if (($dependencyColaborador['matricula'] ?? '') !== $newMatricula) {
        throw new RuntimeException('A movimentação de pessoal não está consumindo a matrícula parametrizada manualmente.');
    }
    if ((float)($dependencyColaborador['salario_atual'] ?? 0) !== 4321.98) {
        throw new RuntimeException('A movimentação de pessoal não está consumindo o salário parametrizado manualmente.');
    }

    // Track A para no RASCUNHO (saveDraft) — NÃO tenta concluir via signManager()/signRh(). Achado
    // real desta rodada: MovimentacaoPessoal::validateCompleteInput() (chamada por signManager())
    // exige avaliacao_desempenho_id > 0 para QUALQUER tipo_movimentacao, e a avaliação nativa exige
    // metadados_id — ou seja, HOJE nenhum colaborador sem metadados_id consegue concluir NENHUMA
    // Movimentação de Pessoal (25/203 colaboradores ativos no ambiente de dev, ~12%). Decisão
    // explícita do Fabio: registrar no relatório, sem alterar produção nesta rodada — o teste só
    // cobre o que é de fato alcançável hoje para este tipo de colaborador.
    $payloadA = [
        'tipo_movimentacao' => 'promocao',
        'data_solicitacao' => date('d/m/Y'),
        'gestor_solicitante_usuario_id' => (int)$actor['id'],
        'setor_id' => (int)($dependencyColaborador['setor_id'] ?: $deps['setores'][0]['id']),
        'colaborador_id' => (int)$dependencyColaborador['id'],
        'novo_cargo_id' => (int)$deps['cargos'][0]['id'],
        'novo_salario' => 'R$ 4.950,00',
        'data_prevista_mudanca' => date('d/m/Y', strtotime('+25 days')),
        'justificativa' => 'Promoção recomendada com base em entregas consistentes, ampliação de responsabilidades e retenção do colaborador.',
        'entregas_ultimos_6_meses' => 'Consolidou rotinas internas, aumentou previsibilidade operacional e apoiou iniciativas críticas da área.',
        'resultados_atingidos' => 'Superou metas, reduziu retrabalho e melhorou indicadores de eficiência do processo.',
        'avaliacao_desempenho_id' => null,
        'pronto_proximo_nivel' => 'Sim, demonstra autonomia, qualidade técnica e capacidade de liderar entregas de maior complexidade.',
        'competencias_tecnicas' => 'Indicadores, processos, organização e domínio da rotina.',
        'competencias_comportamentais' => 'Comunicação, colaboração e adaptabilidade.',
        'pontos_desenvolvimento' => 'Aprofundar visão estratégica e ampliar atuação transversal.',
        'existe_orcamento_aprovado' => 'sim',
        'posicao_atual_sera' => 'substituida',
        'existe_candidato_interno' => '1',
        'necessita_recrutamento_externo' => '0',
        'existe_risco_perda' => '1',
        'impacto_nao_aprovado' => 'Há risco de perda do colaborador e de desaceleração nas entregas críticas da área.',
    ];

    $draftA = MovimentacaoPessoal::saveDraft($payloadA, (int)$actor['id'], null, '127.0.0.1');
    if (!($draftA['ok'] ?? false)) {
        throw new RuntimeException('Falha ao salvar rascunho de movimentação com dados parametrizados: ' . ($draftA['error'] ?? 'erro desconhecido'));
    }
    $createdMovimentacaoId = (int)$draftA['id'];

    $recordA = MovimentacaoPessoal::findAccessible(
        $createdMovimentacaoId,
        (int)$actor['id'],
        (string)$actor['role'],
        (int)($actor['is_supervisor'] ?? 0) === 1
    );
    if (!$recordA) {
        throw new RuntimeException('O rascunho de movimentação criado não foi encontrado (Track A).');
    }
    if (($recordA['matricula_snapshot'] ?? '') !== $newMatricula) {
        throw new RuntimeException('O rascunho não registrou a matrícula parametrizada do colaborador.');
    }
    if (($recordA['status_fluxo'] ?? '') !== 'rascunho') {
        throw new RuntimeException('O rascunho da Track A deveria permanecer em status "rascunho" (conclusão requer avaliação, indisponível para colaborador sem metadados_id).');
    }

    $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdMovimentacaoId]);
    $createdMovimentacaoId = null;

    // ============================================================================================
    // TRACK B — Avaliação de Desempenho NATIVA (avaliacoes_desempenho) vinculada à Movimentação
    //
    // Desde a migration 2026-09-30-movimentacoes-pessoal-fk-avaliacao-desempenho.sql,
    // `movimentacoes_pessoal.avaliacao_desempenho_id` tem FK real para avaliacoes_desempenho(id)
    // (aprovado explicitamente na própria migration, não mais o legado colaborador_avaliacoes), e
    // MovimentacaoPessoal::formDependencies() só lista avaliações concluídas dessa tabela, ligadas
    // via metadados_id. Por isso este track usa um colaborador DIFERENTE do Track A: a tabela exige
    // metadados_id NOT NULL, e um colaborador com metadados_id tem RH somente leitura (Track A não
    // se aplica a ele) — os dois cenários são mutuamente exclusivos no mesmo colaborador.
    // ============================================================================================
    $comMetadadosRow = $pdo->prepare(
        "SELECT id, metadados_id FROM colaboradores
         WHERE ativo = 1 AND id != ? AND id != ? AND metadados_id IS NOT NULL
         ORDER BY id ASC LIMIT 1"
    );
    $comMetadadosRow->execute([(int)$gestorColaborador['id'], (int)$targetColaborador['id']]);
    $comMetadadosRow = $comMetadadosRow->fetch(PDO::FETCH_ASSOC);
    if (!$comMetadadosRow) {
        throw new RuntimeException('Nenhum colaborador ativo com metadados_id vinculado disponível para o teste de avaliação de desempenho nativa.');
    }
    $avaliacaoMetadadosId = (int)$comMetadadosRow['metadados_id'];

    $deps = MovimentacaoPessoal::formDependencies((int)$actor['id']);
    $avaliacaoColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] === (int)$comMetadadosRow['id']) {
            $avaliacaoColaborador = $colaborador;
            break;
        }
    }
    if (!$avaliacaoColaborador) {
        throw new RuntimeException('O colaborador com metadados_id selecionado não apareceu nas dependências do formulário de movimentação.');
    }

    $agoraSql = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $avaliacaoStmt = $pdo->prepare(
        'INSERT INTO avaliacoes_desempenho (
            metadados_id, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_admissao,
            gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim,
            status, data_realizacao, resultado_final, criado_por_usuario_id, criado_em, atualizado_em
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'concluido\', ?, ?, ?, ?, ?)'
    );
    $avaliacaoStmt->execute([
        $avaliacaoMetadadosId, 'ZZ Parametrização RH', 'ZZRHP', 'ZZU', '2024-01-10',
        (int)$actor['id'], (string)$actor['nome'], 'Ciclo Teste Parametrização', '2026-01-01', '2026-06-30',
        date('Y-m-d'), 'atende_expectativas', (int)$actor['id'], $agoraSql, $agoraSql,
    ]);
    $createdEvaluationId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE avaliacoes_desempenho SET ciclo = ? WHERE id = ?')
        ->execute(['Ciclo Teste Parametrização Ajustado', $createdEvaluationId]);

    $evaluationRow = $pdo->prepare('SELECT ciclo FROM avaliacoes_desempenho WHERE id = ?');
    $evaluationRow->execute([$createdEvaluationId]);
    $evaluationRow = $evaluationRow->fetch(PDO::FETCH_ASSOC);
    if (!$evaluationRow || ($evaluationRow['ciclo'] ?? '') !== 'Ciclo Teste Parametrização Ajustado') {
        throw new RuntimeException('A avaliação criada não refletiu a atualização esperada.');
    }

    $deps = MovimentacaoPessoal::formDependencies((int)$actor['id']);
    $avaliacaoColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] === (int)$comMetadadosRow['id']) {
            $avaliacaoColaborador = $colaborador;
            break;
        }
    }
    if (!$avaliacaoColaborador) {
        throw new RuntimeException('O colaborador com metadados_id não apareceu nas dependências do formulário de movimentação após criar a avaliação.');
    }

    $evaluationFoundInDeps = false;
    foreach (($avaliacaoColaborador['avaliacoes'] ?? []) as $avaliacao) {
        if ((int)($avaliacao['id'] ?? 0) === $createdEvaluationId) {
            $evaluationFoundInDeps = true;
            break;
        }
    }
    if (!$evaluationFoundInDeps) {
        throw new RuntimeException('A avaliação recém-criada não apareceu nas dependências do formulário de movimentação.');
    }

    $payloadB = [
        'tipo_movimentacao' => 'promocao',
        'data_solicitacao' => date('d/m/Y'),
        'gestor_solicitante_usuario_id' => (int)$actor['id'],
        'setor_id' => (int)($avaliacaoColaborador['setor_id'] ?: $deps['setores'][0]['id']),
        'colaborador_id' => (int)$avaliacaoColaborador['id'],
        'novo_cargo_id' => (int)$deps['cargos'][0]['id'],
        'novo_salario' => 'R$ 4.950,00',
        'data_prevista_mudanca' => date('d/m/Y', strtotime('+25 days')),
        'justificativa' => 'Promoção recomendada com base em entregas consistentes, ampliação de responsabilidades e retenção do colaborador.',
        'entregas_ultimos_6_meses' => 'Consolidou rotinas internas, aumentou previsibilidade operacional e apoiou iniciativas críticas da área.',
        'resultados_atingidos' => 'Superou metas, reduziu retrabalho e melhorou indicadores de eficiência do processo.',
        'avaliacao_desempenho_id' => $createdEvaluationId,
        'pronto_proximo_nivel' => 'Sim, demonstra autonomia, qualidade técnica e capacidade de liderar entregas de maior complexidade.',
        'competencias_tecnicas' => 'Indicadores, processos, organização e domínio da rotina.',
        'competencias_comportamentais' => 'Comunicação, colaboração e adaptabilidade.',
        'pontos_desenvolvimento' => 'Aprofundar visão estratégica e ampliar atuação transversal.',
        'existe_orcamento_aprovado' => 'sim',
        'posicao_atual_sera' => 'substituida',
        'existe_candidato_interno' => '1',
        'necessita_recrutamento_externo' => '0',
        'existe_risco_perda' => '1',
        'impacto_nao_aprovado' => 'Há risco de perda do colaborador e de desaceleração nas entregas críticas da área.',
    ];

    $draftB = MovimentacaoPessoal::saveDraft($payloadB, (int)$actor['id'], null, '127.0.0.1');
    if (!($draftB['ok'] ?? false)) {
        throw new RuntimeException('Falha ao salvar rascunho de movimentação com avaliação parametrizada: ' . ($draftB['error'] ?? 'erro desconhecido'));
    }
    $createdMovimentacaoId = (int)$draftB['id'];

    $managerB = MovimentacaoPessoal::signManager(
        $payloadB,
        (int)$actor['id'],
        (int)($actor['is_supervisor'] ?? 0) === 1,
        $createdMovimentacaoId,
        '127.0.0.1'
    );
    if (!($managerB['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do gestor durante a integração (Track B): ' . ($managerB['error'] ?? 'erro desconhecido'));
    }

    $rhB = MovimentacaoPessoal::signRh($createdMovimentacaoId, (int)$actor['id'], true, '127.0.0.1');
    if (!($rhB['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do RH durante a integração (Track B): ' . ($rhB['error'] ?? 'erro desconhecido'));
    }

    $recordB = MovimentacaoPessoal::findAccessible(
        $createdMovimentacaoId,
        (int)$actor['id'],
        (string)$actor['role'],
        (int)($actor['is_supervisor'] ?? 0) === 1
    );
    if (!$recordB) {
        throw new RuntimeException('A movimentação criada não foi encontrada após o fluxo de assinaturas (Track B).');
    }
    if ((int)($recordB['avaliacao_desempenho_id'] ?? 0) !== $createdEvaluationId) {
        throw new RuntimeException('A movimentação não registrou a avaliação parametrizada selecionada.');
    }
    if (($recordB['status_fluxo'] ?? '') !== 'aprovada') {
        throw new RuntimeException('A movimentação integrada não chegou ao status final esperado (Track B).');
    }

    $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdMovimentacaoId]);
    $createdMovimentacaoId = null;

    $pdo->prepare('DELETE FROM avaliacoes_desempenho WHERE id = ?')->execute([$createdEvaluationId]);
    $aindaExiste = $pdo->prepare('SELECT COUNT(*) FROM avaliacoes_desempenho WHERE id = ?');
    $aindaExiste->execute([$createdEvaluationId]);
    if ((int)$aindaExiste->fetchColumn() !== 0) {
        throw new RuntimeException('A avaliação deveria ter sido excluída ao final do teste.');
    }
    $createdEvaluationId = null;

    echo "RH_PARAMETRIZATION_OK\n";
} finally {
    if ($createdMovimentacaoId) {
        $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdMovimentacaoId]);
    }

    if ($createdEvaluationId) {
        $pdo->prepare('DELETE FROM avaliacoes_desempenho WHERE id = ?')->execute([$createdEvaluationId]);
    }

    if ($originalColaborador && !empty($originalColaborador['id'])) {
        $pdo->prepare(
            "UPDATE colaboradores
             SET matricula = ?, salario_atual = ?, data_admissao = ?, data_inicio_cargo = ?
             WHERE id = ?"
        )->execute([
            $originalColaborador['matricula'],
            $originalColaborador['salario_atual'],
            $originalColaborador['data_admissao'],
            $originalColaborador['data_inicio_cargo'],
            (int)$originalColaborador['id'],
        ]);
    }

    if ($restoredUserLink) {
        if ($restoredUserLink['mode'] === 'update') {
            $row = $restoredUserLink['row'];
            $pdo->prepare(
                "UPDATE usuario_colaboradores
                 SET colaborador_id = ?, is_gestor = ?, is_rh = ?, lider_colaborador_id = ?, ativo = ?
                 WHERE id = ?"
            )->execute([
                (int)$row['colaborador_id'],
                (int)$row['is_gestor'],
                (int)$row['is_rh'],
                $row['lider_colaborador_id'] !== null ? (int)$row['lider_colaborador_id'] : null,
                (int)$row['ativo'],
                (int)$row['id'],
            ]);
        } elseif (!empty($restoredUserLink['id'])) {
            $pdo->prepare('DELETE FROM usuario_colaboradores WHERE id = ?')->execute([(int)$restoredUserLink['id']]);
        }
    }
}
