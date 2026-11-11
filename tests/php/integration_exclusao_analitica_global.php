<?php

/**
 * Integração — exclusão analítica GLOBAL e RETROATIVA.
 *
 * Um contrato marcado em colaboradores_metadados_exclusoes_analiticas (pela chave natural) deve se
 * comportar, em TODO módulo, como se nunca tivesse integrado a base válida: sem listagem, sem
 * headcount/admissão/desligamento/turnover, sem People Analytics, sem avaliações, entrevistas,
 * pesquisas, feedback ou PDI, sem opção de filtro nem export — e para qualquer período.
 *
 * Método: fixtures ZZGLOB (contratos + registros de cada módulo ligados a eles) → observa cada
 * módulo ANTES de excluir (o contrato aparece: prova que a fixture é visível) e DEPOIS (some; os
 * demais ficam idênticos). Comparação por diferença (depois − antes), então dados já existentes no
 * banco não interferem. Limpeza em `finally`; nunca toca nos contratos/exclusões reais.
 *
 * Requer a migration 2026-11-11 (chave natural) aplicada no banco de teste.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$S = ColaboradorMetadadosElegibilidadeService::class;
if ($S::modoSchema() !== $S::MODO_NATURAL) {
    echo "PULADO: migration 2026-11-11 (chave natural) não aplicada neste banco.\n";
    exit(0);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suf = substr((string)time(), -5) . random_int(10, 99);
$EMP = 'ZG' . $suf;      // empresa só destes fixtures
$UNI = 'ZU' . $suf;
$suf4 = substr($suf, -4);     // códigos curtos (setores.codigo_setor tem tamanho pequeno)
$SETOR_EXC = 'ZGE' . $suf4;   // setor que só existe por causa do contrato excluído
$SETOR_COM = 'ZGC' . $suf4;   // setor compartilhado (permanece)
$CARGO_EXC = 'ZGE' . $suf4;
$CARGO_OK = 'ZGO' . $suf4;
$criados = ['cm' => [], 'avexp' => [], 'avdes' => [], 'fb' => [], 'pdi' => [], 'ent' => [], 'pesq' => [], 'setor' => []];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); // fixtures de módulo referenciam usuário 1 / contratos; limpeza abaixo

$cm = static function (string $ctr, string $pessoa, string $nome, array $o) use ($pdo, $EMP, $UNI, &$criados): int {
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf,
            nome, empresa, unidade, cargo, codigo_cargo, setor, codigo_setor, centro_custo, sexo, nascimento, admissao, demissao,
            motivo_rescisao_codigo, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        "{$EMP}-{$UNI}-{$ctr}", $EMP, $UNI, $ctr, $pessoa, $o['cpf'] ?? null,
        $nome, "ZZGLOB Empresa {$EMP}", "ZZGLOB Unidade {$UNI}", $o['cargo'], $o['codigo_cargo'], $o['setor'], $o['codigo_setor'],
        $o['centro_custo'] ?? null, 'F', $o['nascimento'] ?? null, $o['admissao'], $o['demissao'] ?? null,
        $o['motivo'] ?? null, $o['ativo'], 'zzglob-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['cm'][] = $id;
    return $id;
};
$ins = static function (string $sql, array $params, string $grupo) use ($pdo, &$criados): int {
    $pdo->prepare($sql)->execute($params);
    $id = (int)$pdo->lastInsertId();
    $criados[$grupo][] = $id;
    return $id;
};

try {
    // ---------------------------------------------------------------- contratos
    $cpfExc = '9' . substr($suf, 0, 10);
    $idExcAtivo = $cm('1', 'PGX1', 'ZZGLOB Excluido Ativo', ['cargo' => 'ZZGLOB CARGO SO EXCLUIDO', 'codigo_cargo' => $CARGO_EXC,
        'setor' => 'ZZGLOB SETOR SO EXCLUIDO', 'codigo_setor' => $SETOR_EXC, 'centro_custo' => 'ZZGLOB CC SO EXCLUIDO',
        'admissao' => '2024-03-10', 'ativo' => 1, 'cpf' => $cpfExc, 'nascimento' => '1990-01-01']);
    $idExcDesl = $cm('2', 'PGX2', 'ZZGLOB Excluido Desligado', ['cargo' => 'ZZGLOB CARGO OK', 'codigo_cargo' => $CARGO_OK,
        'setor' => 'ZZGLOB SETOR COMUM', 'codigo_setor' => $SETOR_COM, 'admissao' => '2023-01-05', 'demissao' => '2025-02-20',
        'motivo' => '01', 'ativo' => 0]);
    $idOkAtivo = $cm('3', 'PGX3', 'ZZGLOB Normal Ativo', ['cargo' => 'ZZGLOB CARGO OK', 'codigo_cargo' => $CARGO_OK,
        'setor' => 'ZZGLOB SETOR COMUM', 'codigo_setor' => $SETOR_COM, 'admissao' => '2024-05-05', 'ativo' => 1]);
    $idOkDesl = $cm('4', 'PGX4', 'ZZGLOB Normal Desligado', ['cargo' => 'ZZGLOB CARGO OK', 'codigo_cargo' => $CARGO_OK,
        'setor' => 'ZZGLOB SETOR COMUM', 'codigo_setor' => $SETOR_COM, 'admissao' => '2023-02-01', 'demissao' => '2025-03-15',
        'motivo' => '01', 'ativo' => 0]);
    // Mesma PESSOA do excluído, OUTRO contrato (legítimo): nunca é excluído por tabela.
    $idMesmaPessoa = $cm('5', 'PGX1', 'ZZGLOB Mesma Pessoa Outro Contrato', ['cargo' => 'ZZGLOB CARGO OK', 'codigo_cargo' => $CARGO_OK,
        'setor' => 'ZZGLOB SETOR COMUM', 'codigo_setor' => $SETOR_COM, 'admissao' => '2025-07-07', 'ativo' => 1]);

    // ---------------------------------------------------------------- registros de módulo (excluído + controle)
    $snap = static fn(string $nome): array => [$nome, $EMP, $UNI];
    foreach ([[$idExcAtivo, 'ZZGLOB Excluido Ativo'], [$idOkAtivo, 'ZZGLOB Normal Ativo']] as [$mid, $nome]) {
        $ins('INSERT INTO avaliacoes_experiencia (metadados_id, tipo, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                snap_admissao, gestor_usuario_id, gestor_nome_snapshot, data_prevista, data_realizacao, parecer, parecer_justificativa, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'45\', \'concluido\', ?, ?, ?, ?, \'2024-03-10\', 1, \'Gestor\', \'2025-06-01\', \'2025-06-01\', \'efetivacao_acompanhamento\', \'ZZGLOB justificativa\', 1, NOW(), NOW())',
            [$mid, $nome, $EMP, $UNI, $SETOR_COM], 'avexp');
        $ins('INSERT INTO avaliacoes_desempenho (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                snap_admissao, gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim, data_realizacao, resultado_final, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'concluido\', ?, ?, ?, ?, \'2024-03-10\', 1, \'Gestor\', \'ZZGLOB 2025\', \'2025-01-01\', \'2025-06-30\', \'2025-06-30\', \'atende_expectativas\', 1, NOW(), NOW())',
            [$mid, $nome, $EMP, $UNI, $SETOR_COM], 'avdes');
        $ins('INSERT INTO feedbacks (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor, gestor_usuario_id,
                gestor_nome_snapshot, tipo, data_feedback, resultado_geral, concluido_em, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'concluido\', ?, ?, ?, ?, 1, \'Gestor\', \'desenvolvimento\', \'2025-06-10\', \'em_desenvolvimento\', \'2025-06-10 10:00:00\', 1, NOW(), NOW())',
            [$mid, $nome, $EMP, $UNI, $SETOR_COM], 'fb');
        $ins('INSERT INTO pdis (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, gestor_usuario_id,
                gestor_nome_snapshot, origem_tipo, data_abertura, data_prevista_conclusao, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'em_andamento\', ?, ?, ?, 1, \'Gestor\', \'desenvolvimento_carreira\', \'2025-06-01\', \'2027-06-01\', 1, NOW(), NOW())',
            [$mid, $nome, $EMP, $UNI], 'pdi');
        $ins('INSERT INTO pesquisas_integracao (colaborador_id, metadados_id, integracao_data_relacionada, token_hash, nota_nps, nota_clareza,
                nota_acolhimento, nota_normas, nota_utilidade, nota_satisfacao_geral, respondida_em)
              VALUES (NULL, ?, \'2025-06-01\', NULL, 9, 5, 5, 5, 5, 5, \'2025-06-02 10:00:00\')',
            [$mid], 'pesq');
    }
    foreach ([[$idExcAtivo, 'ZZGLOB Excluido Ativo', '2025-02-20'], [$idOkDesl, 'ZZGLOB Normal Desligado', '2025-03-15']] as $i => [$mid, $nome, $dem]) {
        $ins('INSERT INTO entrevistas_desligamento (metadados_id, token_hash, gerada_em, gerada_por_usuario_id, expira_em, respondida_em, enps,
                snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_demissao, snap_codigo_cargo)
              VALUES (?, ?, \'2025-03-01 10:00:00\', 1, \'2027-03-01 10:00:00\', \'2025-03-02 10:00:00\', 10, ?, ?, ?, ?, ?)',
            [$mid, str_pad(dechex($mid) . 'zzglob' . $suf . $i, 64, 'a'), $nome, $EMP, $UNI, $dem, $CARGO_OK], 'ent');
    }
    $pdo->exec("INSERT INTO setores (nome, slug, codigo_setor) VALUES ('ZZGLOB SETOR COMUM {$suf}', 'zzglob-setor-comum-{$suf}', '{$SETOR_COM}')");
    $criados['setor'][] = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO setores (nome, slug, codigo_setor) VALUES ('ZZGLOB SETOR SO EXCLUIDO {$suf}', 'zzglob-setor-exc-{$suf}', '{$SETOR_EXC}')");
    $criados['setor'][] = (int)$pdo->lastInsertId();

    // ---------------------------------------------------------------- repositories / observações
    $consulta = new ColaboradorMetadadosConsultaRepository($pdo);
    $pa = new PeopleAnalyticsRepository($pdo);
    $ri = new RhIndicadoresRepository($pdo);
    $td = new TurnoverDashboardRepository($pdo);
    $avExp = new AvaliacaoExperienciaRepository($pdo);
    $avDes = new AvaliacaoDesempenhoRepository($pdo);
    $fb = new FeedbackRepository($pdo);
    $pdiRepo = new PdiRepository($pdo);
    $entRepo = new EntrevistaDesligamentoRepository($pdo);
    $dashEnt = new DashboardEntrevistaDesligamentoRepository($pdo);
    $dashInt = new DashboardIntegracaoRepository($pdo);
    $paAval = new PeopleAnalyticsAvaliacoesRepository($pdo);
    $setorRepo = new SetorRepository($pdo);
    $paService = new PeopleAnalyticsService();

    $ini = new DateTimeImmutable('2024-01-01');
    $fim = new DateTimeImmutable('2025-12-31');
    $hoje = new DateTimeImmutable('2026-11-11');
    $contem = static fn(array $lista, string $needle): bool => in_array($needle, $lista, true);

    $observar = static function () use (
        $consulta, $pa, $ri, $td, $avExp, $avDes, $fb, $pdiRepo, $entRepo, $dashEnt, $dashInt, $paAval, $setorRepo, $paService,
        $EMP, $UNI, $SETOR_EXC, $CARGO_EXC, $ini, $fim, $hoje, $cpfExc, $pdo
    ): array {
        $o = [];
        // listagem + exportação (mesma população)
        $o['listagem'] = array_column($consulta->paginate(['q' => 'ZZGLOB'], 1, 50)['items'], 'nome');
        $o['listagem_total'] = $consulta->paginate(['q' => 'ZZGLOB'], 1, 50)['total'];
        $o['export_csv'] = array_column($paService->exportarColaboradoresCsv(['codigo_empresa' => $EMP], $ini, $fim), 'nome');
        // headcount / admissões / desligamentos / turnover / people analytics (retroativo: 2024-2025)
        $o['pa_contratos'] = array_column(array_filter($pa->buscarContratos(), static fn($c) => str_starts_with((string)$c['identificador'], "{$EMP}-")), 'identificador');
        $o['pa_movimentacao'] = array_column(array_filter($pa->buscarContratosParaMovimentacao(), static fn($c) => str_starts_with((string)$c['identificador'], "{$EMP}-")), 'identificador');
        $o['ri_contratos'] = count($ri->buscarContratos(['codigo_empresa' => $EMP]));
        $o['td_contratos'] = count($td->buscarContratos(['codigo_empresa' => $EMP]));
        $o['pa_dist_setor'] = array_sum(array_column($pa->distribuicaoAtivosPorSetor(['codigo_empresa' => $EMP]), 'contratos'));
        // filtros / agrupamentos (opções)
        $o['op_consulta_cargos'] = $consulta->opcoesFiltro()['cargos'];
        $o['op_consulta_setores'] = $consulta->opcoesFiltro()['setores'];
        $op = $ri->opcoesFiltro();
        $o['op_ri_cargos'] = $op['cargos'];
        $o['op_ri_setores'] = $op['setores'];
        $o['op_ri_cc'] = $op['centrosCusto'];
        $o['op_td_cargos'] = array_column($td->opcoesFiltro()['cargos'], 'codigo_cargo');
        $o['op_pa_setores'] = array_column($pa->opcoesFiltro()['setores'], 'codigo_setor');
        $o['op_avexp_setores'] = array_column($avExp->opcoesFiltro()['setores'], 'codigo_setor');
        $o['op_reacao_setores'] = array_column(PesquisaReacaoCampanha::opcoesEmpresaSetor()['setores'], 'codigo_setor');
        // avaliações
        $o['avexp_pendencia'] = count($avExp->candidatosPendencia('45', '2000-01-01', ['codigo_empresa' => $EMP], null));
        $o['avexp_realizadas'] = $paAval->experienciaRealizadasPorTipo($ini, $fim, $EMP, null)['45'] ?? 0;
        $o['avexp_pareceres'] = array_sum($paAval->experienciaPareceres($ini, $fim, $EMP, null));
        $o['avexp_cobertura'] = $paAval->coberturaExperiencia($ini, $fim, $EMP, null)['elegiveis'];
        $o['avdes_lista'] = count($avDes->listar(['busca' => 'ZZGLOB'], null, 100));
        $o['avdes_resumo'] = $paAval->desempenhoResumo($ini, $fim, $EMP, null)['concluidas'];
        $o['avdes_buscar'] = array_column($avDes->buscarContratos('ZZGLOB', $hoje->format('Y-m-d'), 50), 'nome');
        // feedback / PDI
        $o['fb_lista'] = count($fb->listar(['busca' => 'ZZGLOB'], null, 100));
        $o['fb_resumo'] = array_sum($paAval->feedbackResumo($ini, $fim, $EMP, null)['por_tipo']);
        $o['fb_buscar'] = array_column($fb->buscarContratos('ZZGLOB', $hoje->format('Y-m-d'), 50), 'nome');
        $o['pdi_lista'] = count($pdiRepo->listar(['busca' => 'ZZGLOB'], null, $hoje->format('Y-m-d'), 100));
        $o['pdi_ativos'] = $paAval->pdiSituacaoAtual($EMP, null)['ativos'];
        $o['pdi_origens_manual'] = $paAval->pdiVinculosOrigem($EMP, null)['manual'];
        $o['pdi_buscar'] = array_column($pdiRepo->buscarContratos('ZZGLOB', $hoje->format('Y-m-d'), 50), 'nome');
        $o['pdi_opcoes_empresas'] = array_column($pdiRepo->opcoesFiltro(null)['empresas'], 'codigo');
        // entrevistas de desligamento
        $crit = ['hoje' => $hoje->format('Y-m-d'), 'motivo_excluido' => '99'];
        $o['ent_elegiveis'] = count($entRepo->listarElegiveis($crit, ['codigo_empresa' => $EMP], 50));
        $o['ent_elegiveis_total'] = $entRepo->contarElegiveis($crit, ['codigo_empresa' => $EMP]);
        $o['ent_respondidas'] = count($entRepo->listarEntrevistas('respondida', $hoje->format('Y-m-d H:i:s'), ['codigo_empresa' => $EMP], 50));
        $o['ent_geradas'] = $entRepo->contagemPorSituacao($hoje->format('Y-m-d H:i:s'))['geradas'];
        $o['ent_enps_total'] = $entRepo->distribuicaoEnps()['total'];
        $f = ['inicio' => '2025-01-01', 'fim' => '2025-12-31', 'unidade' => ['codigo_empresa' => $EMP, 'codigo_unidade' => $UNI]];
        $o['dash_desligamentos'] = array_sum(array_column($dashEnt->desligamentosPorMes($f, '99'), 'desligamentos'));
        $o['dash_entrevistas'] = array_sum(array_column($dashEnt->entrevistasPorMes($f), 'geradas'));
        $o['dash_unidades'] = array_sum(array_column($dashEnt->desligamentosPorUnidade($f), 'desligamentos'));
        // pesquisas de integração / reação
        $fi = ['inicio' => '2025-01-01', 'fim' => '2025-12-31', 'codigo_empresa' => $EMP, 'codigo_unidade' => '', 'codigo_setor' => ''];
        $o['pesq_respostas'] = count($dashInt->respostas($fi));
        $o['pesq_qr_resumo'] = count(array_filter(PesquisaIntegracaoQr::respostasQrParaResumo(), static fn($r) => $r['integracao_data_relacionada'] === '2025-06-01'));
        $o['pesq_qr_cpf'] = count(PesquisaIntegracaoQr::contratosAtivosPorCpfENascimento($cpfExc, '1990-01-01'));
        $o['pa_nps'] = count($pa->buscarNotasNpsIntegracao(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30')));
        // setores (tela e exportação)
        $setoresExport = $setorRepo->exportDataset(['q' => 'ZZGLOB SETOR']);
        $o['setor_export_vinculados'] = array_sum(array_column($setoresExport, 'colaboradores_vinculados'));
        return $o;
    };

    // ---------------------------------------------------------------- ANTES (nada excluído)
    $antes = $observar();
    $check(in_array('ZZGLOB Excluido Ativo', $antes['listagem'], true) && count($antes['listagem']) === 5, '(controle) Antes de excluir, os 5 contratos de teste aparecem na listagem');
    $check($contem($antes['op_consulta_cargos'], 'ZZGLOB CARGO SO EXCLUIDO') && $contem($antes['op_ri_cargos'], 'ZZGLOB CARGO SO EXCLUIDO'), '(controle) Antes de excluir, o cargo exclusivo do contrato aparece nas opções de filtro');
    $check($antes['avexp_realizadas'] === 2 && $antes['avdes_resumo'] === 2 && $antes['fb_lista'] === 2 && $antes['pdi_lista'] === 2, '(controle) Antes de excluir, os registros dos módulos (2 por módulo) são visíveis');

    // ---------------------------------------------------------------- exclusão (pela chave natural, "hoje" — retroatividade vem do filtro, não de created_at)
    $check(($S::excluirPorVinculo($EMP, $UNI, '1', 'ZZGLOB teste global', 1)['ok'] ?? false), 'Exclusão do contrato ATIVO pela chave natural');
    $check(($S::excluirPorVinculo($EMP, $UNI, '2', 'ZZGLOB teste global', 1)['ok'] ?? false), 'Exclusão do contrato DESLIGADO pela chave natural');

    $depois = $observar();

    // 1 listagem
    $check(!in_array('ZZGLOB Excluido Ativo', $depois['listagem'], true) && !in_array('ZZGLOB Excluido Desligado', $depois['listagem'], true), '1. Contratos excluídos não aparecem na listagem');
    $check($depois['listagem_total'] === $antes['listagem_total'] - 2, '1. Total da listagem cai exatamente pelos excluídos (−2)');
    $check(in_array('ZZGLOB Mesma Pessoa Outro Contrato', $depois['listagem'], true), '13. Outro contrato da MESMA pessoa continua na listagem (exclusão é por vínculo)');
    // 15 exportação
    $check(!in_array('ZZGLOB Excluido Ativo', $depois['export_csv'], true) && in_array('ZZGLOB Normal Ativo', $depois['export_csv'], true), '15. Exportação CSV de People Analytics respeita a mesma exclusão da interface');
    $check($depois['setor_export_vinculados'] === $antes['setor_export_vinculados'] - 1, '15. Exportação de Setores: colaboradores vinculados cai pelo excluído ativo (−1)');
    // 2,3,4,5,6,11 headcount / admissão / desligamento / turnover / PA / retroativo
    $check(!$contem($depois['pa_contratos'], "{$EMP}-{$UNI}-1") && !$contem($depois['pa_contratos'], "{$EMP}-{$UNI}-2") && $contem($depois['pa_contratos'], "{$EMP}-{$UNI}-3"), '2/4/5/6/11. Headcount, admissões e desligamentos (People Analytics) — excluídos fora, controle dentro, períodos de 2023-2025');
    $check(count($depois['pa_contratos']) === count($antes['pa_contratos']) - 2, '6. PeopleAnalytics: base de contratos cai exatamente −2');
    $check($depois['pa_movimentacao'] === array_values(array_diff($antes['pa_movimentacao'], ["{$EMP}-{$UNI}-1", "{$EMP}-{$UNI}-2"])), '6. Base de transferências (movimentação) também exclui os contratos');
    $check($depois['ri_contratos'] === $antes['ri_contratos'] - 2 && $depois['td_contratos'] === $antes['td_contratos'] - 2, '3/4/5. RhIndicadores e Turnover: base −2 (admissão 2024 e desligamento 2025 do excluído não contam)');
    $check($depois['pa_dist_setor'] === $antes['pa_dist_setor'] - 1, '2/6. Distribuição de ativos por setor cai −1 (só o excluído ATIVO)');
    // 14 filtros / agrupamentos
    foreach (['op_consulta_cargos' => 'ZZGLOB CARGO SO EXCLUIDO', 'op_consulta_setores' => 'ZZGLOB SETOR SO EXCLUIDO', 'op_ri_cargos' => 'ZZGLOB CARGO SO EXCLUIDO',
              'op_ri_setores' => 'ZZGLOB SETOR SO EXCLUIDO', 'op_ri_cc' => 'ZZGLOB CC SO EXCLUIDO'] as $k => $valor) {
        $check(!$contem($depois[$k], $valor), "14. Opção de filtro exclusiva do contrato excluído desaparece ({$k})");
    }
    $check(!$contem($depois['op_td_cargos'], $CARGO_EXC) && !$contem($depois['op_pa_setores'], $SETOR_EXC) && !$contem($depois['op_avexp_setores'], $SETOR_EXC) && !$contem($depois['op_reacao_setores'], $SETOR_EXC),
        '14. Opções de cargo/setor de Turnover, People Analytics, Avaliação de Experiência e Pesquisa de Reação também não vêm do contrato excluído');
    $check($contem($depois['op_ri_cargos'], 'ZZGLOB CARGO OK') && $contem($depois['op_pa_setores'], $SETOR_COM), '13/14. Opções legítimas permanecem');
    // 7 avaliações
    $check($depois['avexp_pendencia'] === $antes['avexp_pendencia'] - 2 && $depois['avexp_realizadas'] === $antes['avexp_realizadas'] - 1
        && $depois['avexp_pareceres'] === $antes['avexp_pareceres'] - 1 && $depois['avexp_cobertura'] === $antes['avexp_cobertura'] - 1,
        '7. Avaliação de Experiência: pendências (−2 contratos), realizadas, pareceres e cobertura excluem o contrato excluído');
    $check($depois['avdes_lista'] === $antes['avdes_lista'] - 1 && $depois['avdes_resumo'] === $antes['avdes_resumo'] - 1 && !in_array('ZZGLOB Excluido Ativo', $depois['avdes_buscar'], true),
        '7. Avaliação de Desempenho: lista, resumo e busca de colaborador excluem o contrato excluído');
    // 8 entrevistas
    $check($depois['ent_elegiveis'] === $antes['ent_elegiveis'] - 1 && $depois['ent_elegiveis_total'] === $antes['ent_elegiveis_total'] - 1, '8. Entrevista de Desligamento: o excluído desligado não é elegível');
    $check($depois['ent_respondidas'] === $antes['ent_respondidas'] - 1 && $depois['ent_geradas'] === $antes['ent_geradas'] - 1 && $depois['ent_enps_total'] === $antes['ent_enps_total'] - 1,
        '8. Entrevistas já respondidas do excluído não entram na lista, na contagem nem no eNPS');
    $check($depois['dash_desligamentos'] === $antes['dash_desligamentos'] - 1 && $depois['dash_entrevistas'] === $antes['dash_entrevistas'] - 1 && $depois['dash_unidades'] === $antes['dash_unidades'] - 1,
        '5/8/11. Dashboard de Entrevista de Desligamento: desligamento de 2025 do excluído some das agregações (período anterior à exclusão)');
    // 9 pesquisas
    $check($depois['pesq_respostas'] === $antes['pesq_respostas'] - 1 && $depois['pesq_qr_resumo'] === $antes['pesq_qr_resumo'] - 1 && $depois['pa_nps'] === $antes['pa_nps'] - 1,
        '9. Pesquisas de Integração (dashboard, QR e NPS do People Analytics) excluem as respostas do contrato excluído');
    $check($antes['pesq_qr_cpf'] === 1 && $depois['pesq_qr_cpf'] === 0, '9. Excluído não consegue ser identificado no fluxo público da pesquisa QR (CPF + nascimento)');
    // 10 feedback / PDI
    $check($depois['fb_lista'] === $antes['fb_lista'] - 1 && $depois['fb_resumo'] === $antes['fb_resumo'] - 1 && !in_array('ZZGLOB Excluido Ativo', $depois['fb_buscar'], true), '10. Feedback: lista, resumo e busca excluem o contrato excluído');
    $check($depois['pdi_lista'] === $antes['pdi_lista'] - 1 && $depois['pdi_ativos'] === $antes['pdi_ativos'] - 1 && $depois['pdi_origens_manual'] === $antes['pdi_origens_manual'] - 1 && !in_array('ZZGLOB Excluido Ativo', $depois['pdi_buscar'], true),
        '10. PDI: lista, ativos, origens e busca excluem o contrato excluído');

    // 11 retroatividade: o created_at da exclusão (hoje) não limita a vigência — períodos de 2023/2024/2025 já foram checados acima.
    $linhaExcl = $pdo->prepare('SELECT created_at FROM colaboradores_metadados_exclusoes_analiticas WHERE codigo_empresa = ? AND codigo_unidade = ? AND numero_contrato = ?');
    $linhaExcl->execute([$EMP, $UNI, '1']);
    $check(substr((string)$linhaExcl->fetchColumn(), 0, 4) >= '2026', '11. A exclusão foi criada em 2026 e vale para admissão de 2024 / desligamento de 2025 (nada usa created_at como vigência)');

    // 12 reimportação
    $repoCm = new ColaboradorMetadadosRepository($pdo);
    $linhaSync = [
        'identificador' => "{$EMP}-{$UNI}-1", 'codigo_empresa' => $EMP, 'codigo_unidade' => $UNI, 'numero_contrato' => '1', 'codigo_pessoa' => 'PGX1',
        'nome' => 'ZZGLOB Excluido Ativo', 'empresa' => "ZZGLOB Empresa {$EMP}", 'unidade' => "ZZGLOB Unidade {$UNI}", 'cargo' => 'CARGO ALTERADO NA REIMPORTACAO',
        'codigo_cargo' => $CARGO_EXC, 'setor' => 'ZZGLOB SETOR SO EXCLUIDO', 'codigo_setor' => $SETOR_EXC, 'admissao' => '2024-03-10', 'ativo' => 1,
    ];
    $check($repoCm->upsert($linhaSync, 'zzglob-teste') === 'updated', '12. Reimportação atualiza o dado bruto do contrato excluído');
    $apos = $observar();
    $check($apos['listagem'] === $depois['listagem'] && $apos['pa_contratos'] === $depois['pa_contratos'] && $apos['avexp_realizadas'] === $depois['avexp_realizadas'],
        '12. Reimportação não reativa o contrato: listagem, People Analytics e avaliações seguem idênticos');
    $check($S::estaExcluidoPorVinculo($EMP, $UNI, '1'), '12. A marca de exclusão sobrevive à reimportação');

    // 13 contratos legítimos inalterados (controle positivo em vários módulos)
    $check($contem($depois['listagem'], 'ZZGLOB Normal Ativo') && $contem($depois['listagem'], 'ZZGLOB Normal Desligado'), '13. Contratos legítimos permanecem visíveis');
    $check($depois['avexp_realizadas'] === 1 && $depois['avdes_resumo'] === 1 && $depois['fb_lista'] === 1 && $depois['pdi_lista'] === 1 && $depois['dash_desligamentos'] >= 1,
        '13. Registros dos contratos legítimos continuam contando (1 por módulo)');

    // dados brutos e registros relacionados preservados (nada apagado)
    $check((int)$pdo->query('SELECT COUNT(*) FROM colaboradores_metadados WHERE id IN (' . implode(',', [$idExcAtivo, $idExcDesl]) . ')')->fetchColumn() === 2, 'Dados brutos dos contratos excluídos preservados em colaboradores_metadados');
    $check((int)$pdo->query('SELECT COUNT(*) FROM avaliacoes_experiencia WHERE metadados_id = ' . $idExcAtivo)->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM entrevistas_desligamento WHERE metadados_id = ' . $idExcAtivo)->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM pdis WHERE metadados_id = ' . $idExcAtivo)->fetchColumn() === 1,
        'Registros de avaliação/entrevista/PDI do contrato excluído continuam fisicamente preservados (rastreabilidade)');

    // reversão: tudo volta ao estado "antes"
    $S::incluirPorVinculo($EMP, $UNI, '1');
    $S::incluirPorVinculo($EMP, $UNI, '2');
    $revertido = $observar();
    $check($revertido['listagem'] === $antes['listagem'] || (count($revertido['listagem']) === 5), 'Reversão administrativa: contratos e registros voltam a contar normalmente');
    $check($revertido['avexp_realizadas'] === $antes['avexp_realizadas'] && $revertido['pdi_lista'] === $antes['pdi_lista'] && $revertido['ent_respondidas'] === $antes['ent_respondidas'],
        'Reversão administrativa: módulos voltam exatamente ao estado anterior');

    echo "\nEXCLUSAO_ANALITICA_GLOBAL_OK\n";
} finally {
    $in = static fn(array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    try {
        foreach ($criados['cm'] as $mid) {
            $pdo->prepare('DELETE FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id = ?
                           OR (codigo_empresa, codigo_unidade, numero_contrato) IN (SELECT codigo_empresa, codigo_unidade, numero_contrato FROM colaboradores_metadados WHERE id = ?)')->execute([$mid, $mid]);
        }
        $pdo->exec('DELETE FROM avaliacoes_experiencia WHERE id IN (' . $in($criados['avexp']) . ')');
        $pdo->exec('DELETE FROM avaliacoes_desempenho WHERE id IN (' . $in($criados['avdes']) . ')');
        $pdo->exec('DELETE FROM feedbacks WHERE id IN (' . $in($criados['fb']) . ')');
        $pdo->exec('DELETE FROM pdis WHERE id IN (' . $in($criados['pdi']) . ')');
        $pdo->exec('DELETE FROM entrevistas_desligamento WHERE id IN (' . $in($criados['ent']) . ')');
        $pdo->exec('DELETE FROM pesquisas_integracao WHERE id IN (' . $in($criados['pesq']) . ')');
        $pdo->exec('DELETE FROM setores WHERE id IN (' . $in($criados['setor']) . ')');
        $pdo->exec('DELETE FROM colaboradores_metadados WHERE id IN (' . $in($criados['cm']) . ')');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_GLOBAL_OK\n";
