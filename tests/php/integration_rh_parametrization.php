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

    $targetColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] !== (int)$gestorColaborador['id']) {
            $targetColaborador = $colaborador;
            break;
        }
    }
    $targetColaborador = $targetColaborador ?: $deps['colaboradores'][0];

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

    $createdEvaluation = AvaliacaoDesempenho::create([
        'colaborador_id' => (int)$targetColaborador['id'],
        'titulo' => 'Avaliação de Integração RH',
        'nota' => '8,7',
        'periodo_referencia' => '2026 - 1º semestre',
        'resumo' => 'Registro criado pelo teste automatizado para validar o CRUD administrativo de avaliações.',
    ]);
    if (!($createdEvaluation['ok'] ?? false)) {
        throw new RuntimeException('Falha ao criar avaliação de desempenho: ' . ($createdEvaluation['error'] ?? 'erro desconhecido'));
    }
    $createdEvaluationId = (int)$createdEvaluation['id'];

    $updatedEvaluation = AvaliacaoDesempenho::update($createdEvaluationId, [
        'colaborador_id' => (int)$targetColaborador['id'],
        'titulo' => 'Avaliação de Integração RH Ajustada',
        'nota' => '9,1',
        'periodo_referencia' => '2026 - ciclo final',
        'resumo' => 'Avaliação atualizada pelo teste para validar o fluxo completo de manutenção.',
    ]);
    if (!($updatedEvaluation['ok'] ?? false)) {
        throw new RuntimeException('Falha ao atualizar avaliação de desempenho: ' . ($updatedEvaluation['error'] ?? 'erro desconhecido'));
    }

    $evaluationRow = AvaliacaoDesempenho::find($createdEvaluationId);
    if (!$evaluationRow || ($evaluationRow['titulo'] ?? '') !== 'Avaliação de Integração RH Ajustada') {
        throw new RuntimeException('A avaliação criada não refletiu a atualização esperada.');
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

    $evaluationFoundInDeps = false;
    foreach (($dependencyColaborador['avaliacoes'] ?? []) as $avaliacao) {
        if ((int)($avaliacao['id'] ?? 0) === $createdEvaluationId) {
            $evaluationFoundInDeps = true;
            break;
        }
    }
    if (!$evaluationFoundInDeps) {
        throw new RuntimeException('A avaliação recém-criada não apareceu nas dependências do formulário de movimentação.');
    }

    $payload = [
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

    $draft = MovimentacaoPessoal::saveDraft($payload, (int)$actor['id'], null, '127.0.0.1');
    if (!($draft['ok'] ?? false)) {
        throw new RuntimeException('Falha ao salvar rascunho de movimentação com dados parametrizados: ' . ($draft['error'] ?? 'erro desconhecido'));
    }
    $createdMovimentacaoId = (int)$draft['id'];

    $manager = MovimentacaoPessoal::signManager(
        $payload,
        (int)$actor['id'],
        (int)($actor['is_supervisor'] ?? 0) === 1,
        $createdMovimentacaoId,
        '127.0.0.1'
    );
    if (!($manager['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do gestor durante a integração: ' . ($manager['error'] ?? 'erro desconhecido'));
    }

    $rh = MovimentacaoPessoal::signRh($createdMovimentacaoId, (int)$actor['id'], true, '127.0.0.1');
    if (!($rh['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do RH durante a integração: ' . ($rh['error'] ?? 'erro desconhecido'));
    }

    $record = MovimentacaoPessoal::findAccessible(
        $createdMovimentacaoId,
        (int)$actor['id'],
        (string)$actor['role'],
        (int)($actor['is_supervisor'] ?? 0) === 1
    );
    if (!$record) {
        throw new RuntimeException('A movimentação criada não foi encontrada após o fluxo de assinaturas.');
    }
    if (($record['matricula_snapshot'] ?? '') !== $newMatricula) {
        throw new RuntimeException('A movimentação não registrou a matrícula parametrizada do colaborador.');
    }
    if ((int)($record['avaliacao_desempenho_id'] ?? 0) !== $createdEvaluationId) {
        throw new RuntimeException('A movimentação não registrou a avaliação parametrizada selecionada.');
    }
    if (($record['status_fluxo'] ?? '') !== 'aprovada') {
        throw new RuntimeException('A movimentação integrada não chegou ao status final esperado.');
    }

    $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdMovimentacaoId]);
    $createdMovimentacaoId = null;

    if (!AvaliacaoDesempenho::delete($createdEvaluationId)) {
        throw new RuntimeException('Falha ao excluir a avaliação criada pelo teste.');
    }
    if (AvaliacaoDesempenho::find($createdEvaluationId)) {
        throw new RuntimeException('A avaliação deveria ter sido excluída ao final do teste.');
    }
    $createdEvaluationId = null;

    echo "RH_PARAMETRIZATION_OK\n";
} finally {
    if ($createdMovimentacaoId) {
        $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdMovimentacaoId]);
    }

    if ($createdEvaluationId) {
        $pdo->prepare('DELETE FROM colaborador_avaliacoes WHERE id = ?')->execute([$createdEvaluationId]);
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
