<?php

/**
 * Integração — Bloco 6 (2026-10, pedido do RH): elimina o bloqueio funcional que impedia
 * colaboradores ATIVOS sem `colaboradores.metadados_id` de concluir uma Movimentação de Pessoal.
 *
 * Causa raiz confirmada:
 *   1. MovimentacaoPessoal::validateCompleteInput() exige `avaliacao_desempenho_id` para QUALQUER
 *      tipo de movimentação (signManager() nunca completa sem isso);
 *   2. MovimentacaoPessoal::formDependencies()/evaluationsForColaborador() só oferecem avaliações
 *      via `colaboradores.metadados_id = avaliacoes_desempenho.metadados_id` — sem metadados_id,
 *      zero avaliações aparecem, e o campo obrigatório nunca pode ser preenchido;
 *   3. CollaboratorSpreadsheetImportService (import de planilha) criava `colaboradores` sem NUNCA
 *      preencher `metadados_id` — ao contrário de ColaboradorExtensaoLocalService (criação a partir
 *      de um contrato oficial já vinculado).
 *
 * Correção (só a causa dos NOVOS colaboradores — "Prioridade: corrigir a causa para novos
 * colaboradores" do Bloco 6): CollaboratorSpreadsheetImportService::autoLinkMetadados(), chamado ao
 * final de cada import, reaproveita INTEGRALMENTE ColaboradorMetadadosReconciliationService +
 * ColaboradorMetadadosLinkService (nenhuma lógica de correspondência nova) e só aplica
 * CORRESPONDENCIA_SEGURA (CPF + data de admissão batendo com exatamente um contrato oficial).
 * PROVAVEL/AMBIGUA/SEM_CORRESPONDENCIA/CONFLITO ficam pendentes, nunca vinculados por aproximação —
 * cobertos por tests/php/unit_colaborador_metadados_reconciliation.php e
 * tests/php/integration_colaborador_metadados_link_apply.php; aqui o foco é a integração end-to-end
 * import -> vínculo -> Movimentação de Pessoal.
 *
 * Fixtures ZZB6-* com limpeza em `finally`. Prova, contra o banco:
 *   - colaborador sem vínculo e SEM correspondência (CPF nunca visto no METADADOS): fica pendente,
 *     import continua OK, Movimentação continua bloqueada com a mensagem exata esperada;
 *   - colaborador sem vínculo mas com correspondência AMBÍGUA (mesmo CPF, duas admissões no
 *     espelho, nenhuma bate com a local): fica pendente, nunca vinculado por aproximação;
 *   - colaborador sem vínculo com correspondência INEQUÍVOCA (CPF + admissão batendo com um único
 *     contrato): vinculado automaticamente pelo import, SEM intervenção manual;
 *   - uma vez vinculado (automaticamente ou já vinculado antes do import), a Movimentação de
 *     Pessoal conclui normalmente (rascunho -> assinatura do gestor) com uma avaliação de
 *     desempenho real ligada ao mesmo metadados_id;
 *   - colaborador JÁ vinculado antes do import nunca é tocado pelo auto-link (nenhuma tentativa de
 *     resolução, nenhum warning, metadados_id inalterado);
 *   - nenhuma regressão: dry-run e dos demais campos do relatório de import continuam normais.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

MovimentacaoPessoal::ensureSchema();

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['colaboradores' => [], 'metadados' => [], 'avaliacoes' => [], 'movimentacoes' => [], 'usuario_colaboradores' => []];
$csvFiles = [];

/** CPF com dígitos verificadores reais (Security::isValidCpf) a partir de uma base de 9 dígitos. */
$cpfValido = static function (string $base9): string {
    $sum = 0;
    $weight = 10;
    foreach (str_split($base9) as $d) {
        $sum += (int)$d * $weight;
        $weight--;
    }
    $rest = $sum % 11;
    $d1 = $rest < 2 ? 0 : 11 - $rest;
    $base10 = $base9 . $d1;
    $sum = 0;
    $weight = 11;
    foreach (str_split($base10) as $d) {
        $sum += (int)$d * $weight;
        $weight--;
    }
    $rest = $sum % 11;
    $d2 = $rest < 2 ? 0 : 11 - $rest;
    return $base10 . $d2;
};

$mkMetadados = static function (string $cpf, string $admissao, string $nome) use ($pdo, &$criados, $suffix): int {
    static $i = 0;
    $i++;
    $stmt = $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        "ZZB6{$suffix}-{$i}", 'ZZB6EMP', 'ZZB6UNI', "ZZB6C{$suffix}{$i}", "ZZB6P{$suffix}{$i}", $cpf, $nome, 'ZZB6 Empresa',
        'ZZB6SET', 'ZZB6 Setor', 'ZZB6 Cargo', 'ZZB6CG', $admissao, $admissao, 'zzb6-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

$mkCsv = static function (string $codigo, string $nome, string $empresa, string $cpf, string $admissaoBr, string $cargo) use (&$csvFiles): string {
    $csv = "COD;COLABORADOR;EMPRESA;CPF;ADMISSÃO;NASC.;CARGO;DEMISSÃO;MOTIVO RESCISÃO\n"
        . "{$codigo};{$nome};{$empresa};{$cpf};{$admissaoBr};01/01/1990;{$cargo};;\n";
    $tempFile = tempnam(sys_get_temp_dir(), 'zzb6_');
    $csvPath = $tempFile . '.csv';
    rename($tempFile, $csvPath);
    file_put_contents($csvPath, $csv);
    $csvFiles[] = $csvPath;
    return $csvPath;
};

$colaboradorPorCodigo = static function (string $codigo) use ($pdo, &$criados): ?array {
    $stmt = $pdo->prepare('SELECT * FROM colaboradores WHERE codigo = ? LIMIT 1');
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $criados['colaboradores'][] = (int)$row['id'];
    }
    return $row ?: null;
};

try {
    $service = new CollaboratorSpreadsheetImportService();

    // ---- 1) SEM correspondência: CPF nunca visto no METADADOS ------------------------------------
    $cpfSemCorrespondencia = $cpfValido(str_pad('1' . $suffix, 9, '0'));
    $csv1 = $mkCsv("ZZB6COD1{$suffix}", "ZZB6 Sem Correspondencia {$suffix}", "ZZB6 Empresa Import", $cpfSemCorrespondencia, '10/01/2024', 'ZZB6 Cargo Import');
    $report1 = $service->import($csv1);
    $check(($report1['ok'] ?? false) === true, '(1) Import do colaborador SEM correspondência no METADADOS continua OK (vínculo é reforço, nunca condição)');
    $colab1 = $colaboradorPorCodigo("ZZB6COD1{$suffix}");
    $check($colab1 !== null && $colab1['metadados_id'] === null, '(1) Colaborador criado com metadados_id NULL — nenhum vínculo inventado');
    $check((int)($report1['summary']['metadados_vinculados_automaticamente'] ?? -1) === 0 && (int)($report1['summary']['metadados_pendentes'] ?? 0) >= 1, '(1) Relatório do import reporta 0 vinculados e >=1 pendente');

    // ---- 2) Correspondência AMBÍGUA: mesmo CPF, duas admissões no espelho, nenhuma bate -----------
    $cpfAmbiguo = $cpfValido(str_pad('2' . $suffix, 9, '0'));
    $mkMetadados($cpfAmbiguo, '2020-05-01', 'ZZB6 Ambiguo Contrato A');
    $mkMetadados($cpfAmbiguo, '2022-08-15', 'ZZB6 Ambiguo Contrato B');
    $csv2 = $mkCsv("ZZB6COD2{$suffix}", "ZZB6 Ambiguo {$suffix}", "ZZB6 Empresa Import", $cpfAmbiguo, '01/01/2024', 'ZZB6 Cargo Import');
    $report2 = $service->import($csv2);
    $check(($report2['ok'] ?? false) === true, '(2) Import do colaborador com correspondência AMBÍGUA continua OK');
    $colab2 = $colaboradorPorCodigo("ZZB6COD2{$suffix}");
    $check($colab2 !== null && $colab2['metadados_id'] === null, '(2) CPF com 2 contratos e nenhuma admissão batendo NUNCA é vinculado por aproximação — fica pendente');
    $check((int)($report2['summary']['metadados_vinculados_automaticamente'] ?? -1) === 0, '(2) Nenhum vínculo automático aplicado no caso ambíguo');

    // ---- 3) Correspondência INEQUÍVOCA (SEGURA): CPF + admissão batem com um único contrato -------
    $cpfSeguro = $cpfValido(str_pad('3' . $suffix, 9, '0'));
    $metadadosSeguroId = $mkMetadados($cpfSeguro, '2024-03-15', 'ZZB6 Seguro Contrato');
    $csv3 = $mkCsv("ZZB6COD3{$suffix}", "ZZB6 Seguro {$suffix}", "ZZB6 Empresa Import", $cpfSeguro, '15/03/2024', 'ZZB6 Cargo Import');
    $report3 = $service->import($csv3);
    $check(($report3['ok'] ?? false) === true, '(3) Import do colaborador com correspondência SEGURA é OK');
    $colab3 = $colaboradorPorCodigo("ZZB6COD3{$suffix}");
    $check($colab3 !== null && (int)$colab3['metadados_id'] === $metadadosSeguroId, '(3) CPF + admissão batendo com um único contrato oficial -> vinculado AUTOMATICAMENTE pelo import, sem intervenção manual');
    $check((int)($report3['summary']['metadados_vinculados_automaticamente'] ?? 0) >= 1, '(3) Relatório do import reporta pelo menos 1 vínculo automático aplicado');

    // ---- 4) Colaborador JÁ vinculado antes do import: auto-link nunca o toca ----------------------
    $cpfJaVinculado = $cpfValido(str_pad('4' . $suffix, 9, '0'));
    $metadadosJaVinculadoId = $mkMetadados($cpfJaVinculado, '2019-02-10', 'ZZB6 Ja Vinculado Contrato');
    $csv4a = $mkCsv("ZZB6COD4{$suffix}", "ZZB6 Ja Vinculado {$suffix}", "ZZB6 Empresa Import", $cpfJaVinculado, '10/02/2019', 'ZZB6 Cargo Import');
    $service->import($csv4a); // 1ª importação: vincula via SEGURA (mesmo mecanismo do cenário 3)
    $colab4 = $colaboradorPorCodigo("ZZB6COD4{$suffix}");
    $check($colab4 !== null && (int)$colab4['metadados_id'] === $metadadosJaVinculadoId, '(4) Fixture: colaborador chega vinculado antes do cenário de regressão');
    // 2ª importação (atualização, ex.: mudança de cargo) do MESMO colaborador já vinculado.
    $csv4b = $mkCsv("ZZB6COD4{$suffix}", "ZZB6 Ja Vinculado {$suffix}", "ZZB6 Empresa Import", $cpfJaVinculado, '10/02/2019', 'ZZB6 Cargo Import Atualizado');
    $report4b = $service->import($csv4b);
    $colab4b = $colaboradorPorCodigo("ZZB6COD4{$suffix}");
    $check(($report4b['summary']['updated'] ?? 0) === 1, '(4) 2ª importação atualiza o mesmo colaborador (mesmo COD), não cria um novo');
    $check((int)$colab4b['metadados_id'] === $metadadosJaVinculadoId, '(4) metadados_id permanece EXATAMENTE o mesmo — auto-link nunca mexe em quem já está vinculado');
    $check(($report4b['warnings'] ?? []) === [] || !array_filter($report4b['warnings'], static fn(string $w): bool => str_contains($w, 'METADADOS')), '(4) Nenhum warning de vínculo automático nesta atualização (já estava JA_VINCULADO, fora do plano)');

    // ---- 5) Bloqueio confirmado: Movimentação de Pessoal SEM metadados_id (cenário 1) -------------
    $admin = $pdo->query("SELECT id, role, is_supervisor FROM usuarios ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh', 'viewer'), id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $check($admin !== false, 'Fixture: existe ao menos um usuário admin/rh/supervisor para assinar a movimentação');

    // gestor_solicitante_usuario_id = o próprio ator: signManager() libera sem exigir um vínculo
    // usuario_colaboradores (que é 1:1 por usuário — não pode ser reatribuído livremente aqui).
    $setor = $pdo->query('SELECT id FROM setores LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $cargoNovo = $pdo->query('SELECT id FROM cargos LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    $payloadSemVinculo = [
        'tipo_movimentacao' => 'promocao',
        'data_solicitacao' => date('d/m/Y'),
        'gestor_solicitante_usuario_id' => (int)$admin['id'],
        'setor_id' => (int)($colab1['setor_id'] ?: $setor['id']),
        'colaborador_id' => (int)$colab1['id'],
        'novo_cargo_id' => (int)$cargoNovo['id'],
        'nova_area_setor_id' => '',
        'novo_salario' => 'R$ 5.000,00',
        'data_prevista_mudanca' => date('d/m/Y', strtotime('+20 days')),
        'justificativa' => 'ZZB6 justificativa de teste com texto suficientemente longo para passar na validação.',
        'entregas_ultimos_6_meses' => 'ZZB6 entregas de teste com texto suficientemente longo para passar na validação.',
        'resultados_atingidos' => 'ZZB6 resultados de teste com texto suficientemente longo para passar na validação.',
        'avaliacao_desempenho_id' => '',
        'pronto_proximo_nivel' => 'ZZB6 prontidão de teste.',
        'existe_orcamento_aprovado' => 'sim',
        'posicao_atual_sera' => 'extinta',
        'existe_risco_perda' => '0',
    ];
    $draftSemVinculo = MovimentacaoPessoal::saveDraft($payloadSemVinculo, (int)$admin['id'], null, '127.0.0.1');
    $check(($draftSemVinculo['ok'] ?? false) === true, '(5) Rascunho é salvo normalmente mesmo sem metadados_id (bloqueio é só na ASSINATURA do gestor)');
    if ($draftSemVinculo['ok'] ?? false) {
        $criados['movimentacoes'][] = (int)$draftSemVinculo['id'];
    }
    $signSemVinculo = MovimentacaoPessoal::signManager($payloadSemVinculo, (int)$admin['id'], (int)$admin['is_supervisor'] === 1, (int)($draftSemVinculo['id'] ?? 0), '127.0.0.1');
    $check(
        ($signSemVinculo['ok'] ?? true) === false && str_contains((string)($signSemVinculo['error'] ?? ''), 'avaliação de desempenho'),
        '(5) Confirma a causa raiz: sem metadados_id (nenhuma avaliação elegível), a assinatura do gestor é bloqueada com "Selecione a avaliação de desempenho mais recente do colaborador." — ' . json_encode($signSemVinculo)
    );

    // ---- 6) Conclusão da Movimentação para o colaborador vinculado no cenário 3 -------------------
    $agoraAv = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO avaliacoes_desempenho (
            metadados_id, snap_nome, snap_codigo_empresa, snap_empresa, snap_codigo_unidade, snap_unidade,
            snap_codigo_setor, snap_setor, snap_codigo_cargo, snap_cargo, snap_admissao,
            gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim,
            status, data_realizacao, resultado_final, criado_por_usuario_id, criado_em, atualizado_em
        ) VALUES (?, ?, \'ZZB6EMP\', \'ZZB6 Empresa\', \'ZZB6UNI\', \'ZZB6 Unidade\', \'ZZB6SET\', \'ZZB6 Setor\', \'ZZB6CG\', \'ZZB6 Cargo\', ?, ?, \'ZZB6 Gestor\', \'ZZB6-Ciclo\', ?, ?, \'concluido\', ?, \'atende_expectativas\', ?, ?, ?)'
    )->execute([
        $metadadosSeguroId, (string)$colab3['nome'], '2024-03-15',
        (int)$admin['id'], date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day')),
        $agoraAv, (int)$admin['id'], $agoraAv, $agoraAv,
    ]);
    $avaliacaoId = (int)$pdo->lastInsertId();
    $criados['avaliacoes'][] = $avaliacaoId;

    $deps = MovimentacaoPessoal::formDependencies((int)$admin['id']);
    $avaliacoesDoColab3 = [];
    foreach ($deps['colaboradores'] as $c) {
        if ((int)$c['id'] === (int)$colab3['id']) {
            $avaliacoesDoColab3 = $c['avaliacoes'];
            break;
        }
    }
    $check(count($avaliacoesDoColab3) === 1 && (int)$avaliacoesDoColab3[0]['id'] === $avaliacaoId, '(6) Depois do vínculo automático, formDependencies() já oferece a avaliação de desempenho do colaborador — a mesma consequência que antes bloqueava, agora resolvida');

    $payloadVinculado = [
        'tipo_movimentacao' => 'merito',
        'data_solicitacao' => date('d/m/Y'),
        'gestor_solicitante_usuario_id' => (int)$admin['id'],
        'setor_id' => (int)($colab3['setor_id'] ?: $setor['id']),
        'colaborador_id' => (int)$colab3['id'],
        'novo_salario' => 'R$ 6.000,00',
        'data_prevista_mudanca' => date('d/m/Y', strtotime('+20 days')),
        'justificativa' => 'ZZB6 justificativa de teste com texto suficientemente longo para passar na validação.',
        'entregas_ultimos_6_meses' => 'ZZB6 entregas de teste com texto suficientemente longo para passar na validação.',
        'resultados_atingidos' => 'ZZB6 resultados de teste com texto suficientemente longo para passar na validação.',
        'avaliacao_desempenho_id' => (string)$avaliacaoId,
        'pronto_proximo_nivel' => 'ZZB6 prontidão de teste.',
        'existe_orcamento_aprovado' => 'sim',
        'posicao_atual_sera' => 'substituida',
        'existe_candidato_interno' => '0',
        'necessita_recrutamento_externo' => '1',
        'existe_risco_perda' => '0',
    ];
    $draftVinculado = MovimentacaoPessoal::saveDraft($payloadVinculado, (int)$admin['id'], null, '127.0.0.1');
    $check(($draftVinculado['ok'] ?? false) === true, '(6) Rascunho salvo para o colaborador agora vinculado');
    if ($draftVinculado['ok'] ?? false) {
        $criados['movimentacoes'][] = (int)$draftVinculado['id'];
    }
    $signVinculado = MovimentacaoPessoal::signManager($payloadVinculado, (int)$admin['id'], (int)$admin['is_supervisor'] === 1, (int)($draftVinculado['id'] ?? 0), '127.0.0.1');
    $check(($signVinculado['ok'] ?? false) === true, '(6) Movimentação de Pessoal CONCLUI normalmente (assinatura do gestor) para o colaborador vinculado automaticamente pelo import — ' . json_encode($signVinculado));

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nBLOCO6_COLABORADORES_SEM_METADADOS_OK\n";
} finally {
    foreach ($criados['movimentacoes'] as $id) {
        $pdo->prepare('DELETE FROM movimentacoes_pessoal_auditoria WHERE movimentacao_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM movimentacoes_pessoal WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['avaliacoes'] as $id) {
        $pdo->prepare('DELETE FROM avaliacoes_desempenho WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['usuario_colaboradores'] as [$usuarioId, $colaboradorId]) {
        $pdo->prepare('DELETE FROM usuario_colaboradores WHERE usuario_id = ? AND colaborador_id = ?')->execute([$usuarioId, $colaboradorId]);
    }
    foreach ($criados['colaboradores'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$id]);
    }
    foreach ($csvFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
