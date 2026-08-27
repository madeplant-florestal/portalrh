<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_solicitacao_vaga_kanban (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
SolicitacaoVaga::ensureSchema();
SolicitacaoVagaStage::all();

$centro = $pdo->query('SELECT id, setor_id FROM centros_custo LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$cargo = $pdo->query('SELECT id FROM cargos LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$colaborador = $pdo->query('SELECT id FROM colaboradores LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$usuario = $pdo->query('SELECT id FROM usuarios LIMIT 1')->fetch(PDO::FETCH_ASSOC);

if (!$centro || !$cargo || !$colaborador || !$usuario) {
    echo "SKIP integration_solicitacao_vaga_kanban (massa de dados minima ausente: centro_custo/cargo/colaborador/usuario)\n";
    exit(0);
}

$emAprovacaoId = null;
$solicitacaoId = null;

try {
    $emAprovacaoId = (int)SolicitacaoVagaStage::findBySlug('em-aprovacao')['id'];
    $emRecrutamentoId = (int)SolicitacaoVagaStage::findBySlug('em-recrutamento')['id'];
    $canceladaId = (int)SolicitacaoVagaStage::findBySlug('cancelada')['id'];

    // Fixture mínima inserida diretamente (bypassa validateForSubmission, que já é responsabilidade
    // do fluxo de criação existente e não é o alvo deste teste — o alvo é SolicitacaoVagaPipelineService).
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
        1, '44h semanais', 'medio', '',
        'operacional', date('Y-m-d', strtotime('+30 days')), 'media', 'pendente_lider', $emAprovacaoId,
    ]);
    $solicitacaoId = (int)$pdo->lastInsertId();

    $service = new SolicitacaoVagaPipelineService();

    // 1) Movimentação simples, sem metadados obrigatórios.
    $result = $service->moveToStage($solicitacaoId, $emRecrutamentoId, (int)$usuario['id']);
    $assert($result['ok'] ?? false, 'Falha: mover para em-recrutamento deveria ter sucesso.');
    $row = $pdo->query("SELECT situacao_kanban_id FROM solicitacoes_vaga WHERE id = {$solicitacaoId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['situacao_kanban_id'] === $emRecrutamentoId, 'Falha: situacao_kanban_id deveria refletir em-recrutamento.');

    // 2) Cancelamento sem motivo deve ser rejeitado.
    $result = $service->moveToStage($solicitacaoId, $canceladaId, (int)$usuario['id'], []);
    $assert(($result['ok'] ?? true) === false, 'Falha: cancelamento sem motivo deveria falhar.');
    $assert(($result['error'] ?? '') === 'validation', 'Falha: erro deveria ser de validação.');
    $assert(in_array('motivo_cancelamento', $result['missing_fields'] ?? [], true), 'Falha: motivo_cancelamento deveria estar em missing_fields.');

    // 3) Concorrência: expected_current_stage_id divergente deve ser rejeitado.
    $result = $service->moveToStage($solicitacaoId, $canceladaId, (int)$usuario['id'], ['motivo_cancelamento' => 'Teste'], true, 9999999);
    $assert(($result['ok'] ?? true) === false, 'Falha: expected_current_stage_id divergente deveria ser rejeitado.');
    $assert(($result['error'] ?? '') === 'conflict', 'Falha: erro deveria ser de conflito de concorrência.');

    // 4) Cancelamento com motivo deve funcionar e persistir motivo/data/responsável.
    $result = $service->moveToStage($solicitacaoId, $canceladaId, (int)$usuario['id'], ['motivo_cancelamento' => 'Vaga não é mais necessária']);
    $assert($result['ok'] ?? false, 'Falha: cancelamento com motivo deveria ter sucesso.');
    $row = $pdo->query("SELECT situacao_kanban_id, motivo_cancelamento_encrypted, cancelada_em, cancelada_por_usuario_id FROM solicitacoes_vaga WHERE id = {$solicitacaoId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['situacao_kanban_id'] === $canceladaId, 'Falha: situacao_kanban_id deveria refletir cancelada.');
    $assert(Cipher::decrypt($row['motivo_cancelamento_encrypted']) === 'Vaga não é mais necessária', 'Falha: motivo do cancelamento não foi persistido corretamente.');
    $assert(!empty($row['cancelada_em']), 'Falha: cancelada_em deveria estar preenchido.');
    $assert((int)$row['cancelada_por_usuario_id'] === (int)$usuario['id'], 'Falha: cancelada_por_usuario_id deveria ser o ator da movimentação.');

    // 5) Anotação avulsa (sem mudança de etapa) deve gravar situacao_anterior == situacao_nova.
    $notaResult = $service->addNota($solicitacaoId, 'Observação de teste sobre a vaga.', (int)$usuario['id']);
    $assert($notaResult['ok'] ?? false, 'Falha: anotação avulsa deveria ter sucesso.');
    $historico = SolicitacaoVaga::kanbanHistorico($solicitacaoId);
    $nota = $historico[0] ?? null;
    $assert($nota !== null && $nota['observacao'] === 'Observação de teste sobre a vaga.', 'Falha: anotação avulsa não encontrada no histórico.');
    $assert(($nota['situacao_anterior'] ?? null) === ($nota['situacao_nova'] ?? null), 'Falha: anotação avulsa deveria ter situacao_anterior == situacao_nova.');

    // 6) Anotação vazia deve ser rejeitada.
    $vazioResult = $service->addNota($solicitacaoId, '   ', (int)$usuario['id']);
    $assert(($vazioResult['ok'] ?? true) === false, 'Falha: anotação vazia deveria ser rejeitada.');
} finally {
    if ($solicitacaoId !== null) {
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$solicitacaoId]);
    }
}

echo "OK integration_solicitacao_vaga_kanban\n";
