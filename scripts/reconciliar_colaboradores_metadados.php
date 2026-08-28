<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Reconciliação somente-leitura entre colaboradores (local) e colaboradores_metadados (espelho
 * oficial do METADADOS). Não escreve metadados_id em nenhuma hipótese nesta fase — sem flag para
 * aplicar vínculos, de propósito, para não permitir uso acidental.
 *
 * Gera: resumo por contagem no stdout (JSON) e um relatório detalhado em CSV, com CPF mascarado
 * e sem salário/dados bancários/contato, em storage/reconciliation/ (fora do Git).
 */
function maskCpf(?string $cpf): ?string
{
    if ($cpf === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $cpf);
    if (strlen($digits) < 4) {
        return '***';
    }
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function boolToCsv(?bool $value): string
{
    if ($value === null) {
        return '';
    }
    return $value ? '1' : '0';
}

try {
    $service = new ColaboradorMetadadosReconciliationService();
    $results = $service->run();

    $counts = [
        'total_local' => count($results),
        'ja_vinculado' => 0,
        'correspondencia_segura' => 0,
        'correspondencia_provavel' => 0,
        'ambigua' => 0,
        'sem_correspondencia' => 0,
        'conflito' => 0,
    ];
    $classificacaoParaChave = [
        ColaboradorMetadadosReconciliationService::JA_VINCULADO => 'ja_vinculado',
        ColaboradorMetadadosReconciliationService::SEGURA => 'correspondencia_segura',
        ColaboradorMetadadosReconciliationService::PROVAVEL => 'correspondencia_provavel',
        ColaboradorMetadadosReconciliationService::AMBIGUA => 'ambigua',
        ColaboradorMetadadosReconciliationService::SEM_CORRESPONDENCIA => 'sem_correspondencia',
        ColaboradorMetadadosReconciliationService::CONFLITO => 'conflito',
    ];
    foreach ($results as $r) {
        $chave = $classificacaoParaChave[$r['classificacao']] ?? null;
        if ($chave !== null) {
            $counts[$chave]++;
        }
    }

    $reportDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'reconciliation';
    @mkdir($reportDir, 0775, true);
    $reportPath = $reportDir . DIRECTORY_SEPARATOR . 'colaboradores-reconciliacao-' . date('Ymd-His') . '.csv';

    $fh = fopen($reportPath, 'w');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível criar o arquivo de relatório em ' . $reportPath);
    }
    fputcsv($fh, [
        'colaborador_id', 'classificacao', 'metadados_id_candidato', 'quantidade_candidatos',
        'cpf_mascarado', 'data_admissao_local', 'data_admissao_metadados',
        'data_demissao_local', 'data_demissao_metadados',
        'nome_compativel', 'nascimento_compativel', 'situacao_compativel', 'motivo_classificacao',
    ]);
    foreach ($results as $r) {
        fputcsv($fh, [
            $r['colaborador_id'],
            $r['classificacao'],
            $r['metadados_id_candidato'],
            $r['quantidade_candidatos'],
            maskCpf($r['cpf'] ?? null),
            $r['data_admissao_local'],
            $r['data_admissao_metadados'],
            $r['data_demissao_local'],
            $r['data_demissao_metadados'],
            boolToCsv($r['nome_compativel']),
            boolToCsv($r['nascimento_compativel']),
            boolToCsv($r['situacao_compativel']),
            $r['motivo_classificacao'],
        ]);
    }
    fclose($fh);

    $summary = [
        'ok' => true,
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'counts' => $counts,
        'report_path' => $reportPath,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'reconciliar_colaboradores_metadados.php']);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
