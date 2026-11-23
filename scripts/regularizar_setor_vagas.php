<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Etapa 3.2A — diagnóstico e regularização HISTÓRICA do setor responsável das vagas (`vagas.setor_id`).
 *
 * Uso:
 *   php scripts/regularizar_setor_vagas.php                      → relatório (A/B/C/D) na tela; NÃO grava
 *   php scripts/regularizar_setor_vagas.php --csv=arquivo.csv    → também grava o relatório em CSV (sem CPF/currículo/dados pessoais)
 *   php scripts/regularizar_setor_vagas.php --aplicar            → aplica SOMENTE a classe B (vaga sem setor cuja SOLICITAÇÃO tem setor
 *                                                                  estruturado, oficial e ativo). Classes C e D nunca são aplicadas aqui.
 *
 * Modo padrão é SEMPRE simulação (mesmo padrão de aplicar_vinculos_colaboradores_metadados.php). Exige a migration
 * 2026-11-24-vagas-setor-responsavel.sql já executada. Não apaga nada, não presume setor por texto, não cria setor.
 * A regularização das classes C e D é manual: tela de edição da vaga (/admin/vagas/editar/{id}).
 */

$aplicar = in_array('--aplicar', $argv, true);
$csv = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csv = substr($arg, 6);
    }
}

if (!VagaSetorService::schemaSuportaSetor()) {
    fwrite(STDERR, "A coluna vagas.setor_id ainda não existe. Execute database/migrations/2026-11-24-vagas-setor-responsavel.sql primeiro.\n");
    exit(2);
}

$svc = new VagaSetorService();
$linhas = $svc->diagnosticoRegularizacao();
$resumo = $svc->resumoDiagnostico($linhas);

echo "=== Vagas × setor responsável ===\n";
echo "Total: {$resumo['total']} | A (setor estruturado): {$resumo['A']} | B (via solicitação): {$resumo['B']} | C (texto sugere, sem prova): {$resumo['C']} | D (sem informação): {$resumo['D']}\n\n";
foreach ($linhas as $l) {
    if ($l['classe'] === VagaSetorService::CLASSE_A && $l['observacao'] === '') {
        continue;
    }
    printf("[%s] vaga #%d \"%s\" área=\"%s\" solicitação=%s candidaturas=%d sugestão=%s %s\n", $l['classe'], $l['id'], mb_substr($l['titulo'], 0, 60), $l['area'], $l['solicitacao_vaga_id'] ?? '-', $l['candidaturas'], $l['setor_sugerido_nome'] ?? '-', $l['observacao']);
}

if ($csv !== null) {
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['vaga_id', 'titulo', 'area_textual', 'solicitacao_vaga_id', 'setor_atual_id', 'setor_atual', 'setor_sugerido_id', 'setor_sugerido', 'classe', 'candidaturas', 'observacao']);
    foreach ($linhas as $l) {
        fputcsv($fh, [$l['id'], $l['titulo'], $l['area'], $l['solicitacao_vaga_id'], $l['setor_atual'], $l['setor_atual_nome'], $l['setor_sugerido'], $l['setor_sugerido_nome'], $l['classe'], $l['candidaturas'], $l['observacao']]);
    }
    fclose($fh);
    echo "\nCSV gravado em {$csv}\n";
}

$plano = $svc->regularizarClasseB($aplicar);
echo "\nClasse B aplicável (setor da solicitação, oficial e ativo): " . count($plano['aplicaveis']) . " | não aplicáveis/pendentes: {$plano['ignoradas']}\n";
if ($aplicar) {
    echo "APLICADO: {$plano['aplicadas']} vaga(s) receberam setor.\n";
} else {
    echo "SIMULAÇÃO: nada foi gravado. Use --aplicar para aplicar a classe B.\n";
}
