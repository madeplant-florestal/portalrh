<?php
require __DIR__ . '/../../app/core/bootstrap.php';

MovimentacaoPessoal::ensureSchema();

$pdo = Database::conn();
$createdId = null;
$touchedUserLink = null;

try {
    $admin = $pdo->query("SELECT id, role, is_supervisor FROM usuarios ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh', 'viewer'), id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        throw new RuntimeException('Nenhum usuário disponível para o teste de movimentação de pessoal.');
    }

    $deps = MovimentacaoPessoal::formDependencies((int)$admin['id']);
    if (empty($deps['setores']) || empty($deps['cargos']) || empty($deps['colaboradores'])) {
        throw new RuntimeException('Dados insuficientes para testar a movimentação de pessoal.');
    }

    $gestor = $deps['gestores'][0] ?? null;
    if (!$gestor) {
        $colaborador = $deps['colaboradores'][0];
        $linkStmt = $pdo->prepare("SELECT * FROM usuario_colaboradores WHERE usuario_id = ? LIMIT 1");
        $linkStmt->execute([(int)$admin['id']]);
        $existing = $linkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare("UPDATE usuario_colaboradores SET colaborador_id = ?, is_gestor = 1, ativo = 1 WHERE id = ?")
                ->execute([(int)$colaborador['id'], (int)$existing['id']]);
            $touchedUserLink = ['mode' => 'update', 'row' => $existing];
        } else {
            $pdo->prepare("INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, lider_colaborador_id, ativo) VALUES (?, ?, 1, 0, NULL, 1)")
                ->execute([(int)$admin['id'], (int)$colaborador['id']]);
            $touchedUserLink = ['mode' => 'insert'];
        }
        $deps = MovimentacaoPessoal::formDependencies((int)$admin['id']);
        $gestor = $deps['gestores'][0] ?? null;
    }

    if (!$gestor) {
        throw new RuntimeException('Não foi possível preparar um gestor para o teste.');
    }

    $targetColaborador = null;
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] !== (int)$gestor['colaborador_id']) {
            $targetColaborador = $colaborador;
            break;
        }
    }
    if (!$targetColaborador) {
        $targetColaborador = $deps['colaboradores'][0];
    }

    // Avaliação de Desempenho NATIVA (Etapa 6): a lista `avaliacoes` de formDependencies() só é
    // populada via a coluna-ponte `colaboradores.metadados_id` (nunca mais colaborador_avaliacoes
    // legado). Cria-se aqui um fixture mínimo — colaboradores_metadados + avaliacoes_desempenho
    // concluída — ligado ao colaborador escolhido, sem depender de dados reais pré-existentes.
    $metadadosIdAlvo = (int)$pdo->query('SELECT metadados_id FROM colaboradores WHERE id = ' . (int)$targetColaborador['id'])->fetchColumn();
    $metadadosCriadoId = null;
    if ($metadadosIdAlvo <= 0) {
        $sufixoAv = (string)time() . (string)random_int(100, 999);
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            'ZZMP' . $sufixoAv, 'EMPA', 'UNI', 'ZC' . $sufixoAv, 'ZP' . $sufixoAv, null, 'ZZMP Fixture', 'ZZMP Empresa', 'ZZMP Unidade',
            'ST1', 'ZZMP Setor', 'ZZMP Cargo', 'CG1', date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('-400 days')), 'zzmp-teste',
        ]);
        $metadadosIdAlvo = (int)$pdo->lastInsertId();
        $metadadosCriadoId = $metadadosIdAlvo;
        $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ?')->execute([$metadadosIdAlvo, (int)$targetColaborador['id']]);
    }
    $agoraAv = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO avaliacoes_desempenho (
            metadados_id, snap_nome, snap_codigo_empresa, snap_empresa, snap_codigo_unidade, snap_unidade,
            snap_codigo_setor, snap_setor, snap_codigo_cargo, snap_cargo, snap_admissao,
            gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim,
            status, data_realizacao, resultado_final, criado_por_usuario_id, criado_em, atualizado_em
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'concluido\', ?, \'atende_expectativas\', ?, ?, ?)'
    )->execute([
        $metadadosIdAlvo, (string)$targetColaborador['nome'], 'EMPA', 'ZZMP Empresa', 'UNI', 'ZZMP Unidade',
        'ST1', 'ZZMP Setor', 'CG1', 'ZZMP Cargo', date('Y-m-d', strtotime('-400 days')),
        (int)$gestor['usuario_id'], 'ZZMP Gestor Fixture', 'ZZMP-Fixture', date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day')),
        $agoraAv, (int)$admin['id'], $agoraAv, $agoraAv,
    ]);
    $avaliacaoFixtureId = (int)$pdo->lastInsertId();

    // Releitura de formDependencies() para que `$targetColaborador['avaliacoes']` reflita o fixture recém-criado.
    $deps = MovimentacaoPessoal::formDependencies((int)$admin['id']);
    foreach ($deps['colaboradores'] as $colaborador) {
        if ((int)$colaborador['id'] === (int)$targetColaborador['id']) {
            $targetColaborador = $colaborador;
            break;
        }
    }

    $payload = [
        'tipo_movimentacao' => 'promocao',
        'data_solicitacao' => date('d/m/Y'),
        'gestor_solicitante_usuario_id' => (int)$gestor['usuario_id'],
        'setor_id' => (int)($targetColaborador['setor_id'] ?: $deps['setores'][0]['id']),
        'colaborador_id' => (int)$targetColaborador['id'],
        'novo_cargo_id' => (int)$deps['cargos'][0]['id'],
        'nova_area_setor_id' => '',
        'novo_salario' => 'R$ ' . number_format(max((float)$targetColaborador['salario_atual'] + 500, 3000), 2, ',', '.'),
        'data_prevista_mudanca' => date('d/m/Y', strtotime('+20 days')),
        'justificativa' => 'Solicitação baseada em evolução consistente de desempenho, ampliação de escopo, retenção do talento e aderência às necessidades do negócio.',
        'entregas_ultimos_6_meses' => 'Entregou projetos críticos, estruturou rotinas internas, apoiou o time e melhorou os indicadores operacionais da área.',
        'resultados_atingidos' => 'Metas superadas, melhoria de processos e redução de retrabalho com impacto direto na operação.',
        'avaliacao_desempenho_id' => (int)(($targetColaborador['avaliacoes'][0]['id'] ?? 0)),
        'pronto_proximo_nivel' => 'Sim, demonstra maturidade técnica, autonomia, visão sistêmica e capacidade de assumir responsabilidades maiores.',
        'competencias_tecnicas' => 'Gestão de processos, domínio operacional e leitura de indicadores.',
        'competencias_comportamentais' => 'Comunicação, colaboração e senso de dono.',
        'pontos_desenvolvimento' => 'Delegação estruturada e fortalecimento de visão estratégica.',
        'existe_orcamento_aprovado' => 'sim',
        'posicao_atual_sera' => 'extinta',
        'existe_risco_perda' => '1',
        'impacto_nao_aprovado' => 'Existe risco concreto de desengajamento e perda de continuidade das entregas.',
    ];

    $draft = MovimentacaoPessoal::saveDraft($payload, (int)$admin['id'], null, '127.0.0.1');
    if (!($draft['ok'] ?? false)) {
        throw new RuntimeException('Falha ao salvar rascunho: ' . ($draft['error'] ?? 'erro desconhecido'));
    }
    $createdId = (int)$draft['id'];

    $manager = MovimentacaoPessoal::signManager($payload, (int)$admin['id'], (int)$admin['is_supervisor'] === 1, $createdId, '127.0.0.1');
    if (!($manager['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do gestor: ' . ($manager['error'] ?? 'erro desconhecido'));
    }

    $rh = MovimentacaoPessoal::signRh($createdId, (int)$admin['id'], true, '127.0.0.1');
    if (!($rh['ok'] ?? false)) {
        throw new RuntimeException('Falha na assinatura do RH: ' . ($rh['error'] ?? 'erro desconhecido'));
    }

    $record = MovimentacaoPessoal::findAccessible($createdId, (int)$admin['id'], (string)$admin['role'], (int)$admin['is_supervisor'] === 1);
    if (!$record || ($record['status_fluxo'] ?? '') !== 'aprovada') {
        throw new RuntimeException('O status final esperado para a movimentação era "aprovada".');
    }
    if (empty($record['data_decisao'])) {
        throw new RuntimeException('A data da decisão não foi preenchida após a assinatura do RH.');
    }

    echo "MOVIMENTACAO_FLOW_OK\n";
} finally {
    if ($createdId) {
        $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$createdId]);
    }
    if (!empty($avaliacaoFixtureId)) {
        $pdo->prepare('DELETE FROM avaliacoes_desempenho WHERE id = ?')->execute([$avaliacaoFixtureId]);
    }
    if (!empty($metadadosCriadoId)) {
        $pdo->prepare('UPDATE colaboradores SET metadados_id = NULL WHERE metadados_id = ?')->execute([$metadadosCriadoId]);
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$metadadosCriadoId]);
    }
    if ($touchedUserLink) {
        if ($touchedUserLink['mode'] === 'update') {
            $row = $touchedUserLink['row'];
            $pdo->prepare("UPDATE usuario_colaboradores SET colaborador_id = ?, is_gestor = ?, is_rh = ?, lider_colaborador_id = ?, ativo = ? WHERE id = ?")
                ->execute([
                    (int)$row['colaborador_id'],
                    (int)$row['is_gestor'],
                    (int)$row['is_rh'],
                    $row['lider_colaborador_id'] !== null ? (int)$row['lider_colaborador_id'] : null,
                    (int)$row['ativo'],
                    (int)$row['id'],
                ]);
        } else {
            $pdo->prepare('DELETE FROM usuario_colaboradores WHERE usuario_id = ?')->execute([(int)$admin['id']]);
        }
    }
}
