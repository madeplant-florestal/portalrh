<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/bootstrap.php';

$mode = $argv[1] ?? 'create';
$pdo = Database::conn();

if ($mode === 'cleanup') {
    $candidaturaId = (int)($argv[2] ?? 0);
    $vagaId = (int)($argv[3] ?? 0);

    if ($candidaturaId > 0) {
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candidaturaId]);
    }
    if ($vagaId > 0) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);
    }

    echo json_encode(['ok' => true]);
    exit(0);
}

PipelineStage::ensureRecruitmentLifecycle();
$stages = PipelineStage::all();
$novaInscricaoId = 0;
foreach ($stages as $stage) {
    $normalized = PipelineStage::normalizeName((string)($stage['nome'] ?? ''));
    if ($normalized === 'nova inscricao') {
        $novaInscricaoId = (int)($stage['id'] ?? 0);
        break;
    }
}
if ($novaInscricaoId <= 0) {
    fwrite(STDERR, "Falha: estágio 'Nova Inscrição' não encontrado.\n");
    exit(1);
}

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$cpf = substr(preg_replace('/\D+/', '', $suffix . '12345678901') ?: '12345678901', 0, 11);
$vagaId = Vaga::create([
    'titulo' => 'Vaga Kanban ' . $suffix,
    'descricao' => 'Fixture de teste do Kanban de recrutamento.',
    'requisitos' => 'Requisito de fixture.',
    'area' => 'RH',
    'local' => 'Remoto',
    'ativo' => 1,
]);

$candidaturaId = Candidatura::create([
    'vaga_id' => $vagaId,
    'nome' => 'Candidato Kanban ' . $suffix,
    'email' => 'kanban_' . str_replace('-', '_', $suffix) . '@rhmadeplant.local',
    'telefone' => '67999999999',
    'cpf' => str_pad($cpf, 11, '0', STR_PAD_RIGHT),
    'cargo_pretendido' => 'Analista de RH',
    'experiencia' => 'Fixture de movimentação entre colunas do Kanban.',
    'pdf_path' => 'fixture-kanban.pdf',
    'status' => 'novo',
    'indicacao_colaborador' => 0,
]);

$stmt = $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?');
$stmt->execute([$novaInscricaoId, $candidaturaId]);

echo json_encode([
    'ok' => true,
    'vaga_id' => $vagaId,
    'candidatura_id' => $candidaturaId,
    'stage_id' => $novaInscricaoId,
]);
