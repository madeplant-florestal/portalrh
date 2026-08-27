<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/bootstrap.php';

$mode = $argv[1] ?? 'create';
$pdo = Database::conn();

if ($mode === 'cleanup') {
    $solicitacaoId = (int)($argv[2] ?? 0);
    if ($solicitacaoId > 0) {
        $pdo->prepare('DELETE FROM solicitacao_vaga_kanban_historico WHERE solicitacao_id = ?')->execute([$solicitacaoId]);
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$solicitacaoId]);
    }
    echo json_encode(['ok' => true]);
    exit(0);
}

SolicitacaoVaga::ensureSchema();
SolicitacaoVagaStage::all();

$centro = $pdo->query('SELECT id, setor_id FROM centros_custo LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$cargo = $pdo->query('SELECT id FROM cargos LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$colaborador = $pdo->query('SELECT id FROM colaboradores LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$usuario = $pdo->query('SELECT id FROM usuarios LIMIT 1')->fetch(PDO::FETCH_ASSOC);

if (!$centro || !$cargo || !$colaborador || !$usuario) {
    fwrite(STDERR, "Falha: massa de dados minima ausente (centro_custo/cargo/colaborador/usuario).\n");
    exit(1);
}

$emAprovacao = SolicitacaoVagaStage::findBySlug('em-aprovacao');
$emRecrutamento = SolicitacaoVagaStage::findBySlug('em-recrutamento');
$fechada = SolicitacaoVagaStage::findBySlug('fechada');
$cancelada = SolicitacaoVagaStage::findBySlug('cancelada');

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$stmt = $pdo->prepare(
    "INSERT INTO solicitacoes_vaga (
        setor_id, quantidade_vagas, cargo_id, gestor_solicitante_colaborador_id,
        solicitante_usuario_id, tipo_vaga, tipo_contratacao, salario_previsto, centro_custo_id,
        previsto_orcamento, jornada_trabalho, escolaridade_minima, entregas_esperadas_encrypted,
        nivel_responsabilidade, data_prevista_inicio, urgencia, status_fluxo, situacao_kanban_id
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$stmt->execute([
    (int)$centro['setor_id'], 1, (int)$cargo['id'], (int)$colaborador['id'],
    (int)$usuario['id'], 'nova_posicao', 'clt', 5000.00, (int)$centro['id'],
    1, '44h semanais', 'medio', 'Fixture E2E Kanban de Solicitações — ' . $suffix,
    'operacional', date('Y-m-d', strtotime('+30 days')), 'media', 'pendente_lider', (int)$emAprovacao['id'],
]);
$solicitacaoId = (int)$pdo->lastInsertId();

echo json_encode([
    'ok' => true,
    'solicitacao_id' => $solicitacaoId,
    'em_aprovacao_id' => (int)$emAprovacao['id'],
    'em_recrutamento_id' => (int)$emRecrutamento['id'],
    'fechada_id' => (int)$fechada['id'],
    'cancelada_id' => (int)$cancelada['id'],
]);
