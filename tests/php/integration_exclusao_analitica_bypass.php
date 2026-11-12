<?php

/**
 * Integração — NÃO HÁ CONTORNO da exclusão analítica pelos serviços (rotas alternativas, tokens públicos,
 * endpoints auxiliares). Para um contrato excluído, executa TODA operação por id e por token dos módulos
 * (PDI, Avaliação de Experiência/Desempenho, Feedback, Entrevista de Desligamento, Pesquisa de Integração)
 * como Admin e como RH, em requisição de ESCRITA (POST), e exige:
 *   (1) a barreira de acesso individual FOI acionada (ocultadoNaRequisicao) — a operação não passou "por fora";
 *   (2) a operação não teve sucesso (null / ok=false / exceção);
 *   (3) o banco não mudou (nenhuma linha alterada ou criada nas tabelas do contrato).
 * Os mesmos serviços, para o contrato legítimo, CHEGAM ao carregador (controle: a barreira não dispara).
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

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

$fx = new ExclusaoFixtures($pdo);
$sessaoOriginal = $_SESSION ?? [];
$metodoOriginal = $_SERVER['REQUEST_METHOD'] ?? null;
$agora = new DateTimeImmutable('2026-11-12 10:00:00');
$hoje = new DateTimeImmutable('2026-11-12');

$como = static function (?string $role, string $metodo) use ($S): void {
    $_SESSION = $role === null ? [] : ['user_role' => $role, 'user_id' => 1, 'user_name' => 'Teste'];
    $_SERVER['REQUEST_METHOD'] = $metodo;
    $S::reiniciarEstadoDaRequisicao();
};

/** Impressão digital de TODAS as linhas dos módulos ligadas a um contrato (+ contagem), para provar que nada mudou. */
$fingerprint = static function (int $mid, int $colLocal) use ($pdo): string {
    $partes = [];
    foreach (['avaliacoes_experiencia', 'avaliacoes_desempenho', 'feedbacks', 'pdis', 'entrevistas_desligamento', 'entrevista_desligamento_liberacoes', 'pesquisas_integracao'] as $t) {
        $linhas = $pdo->query("SELECT * FROM `{$t}` WHERE metadados_id = {$mid} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $partes[] = $t . ':' . count($linhas) . ':' . md5(json_encode($linhas));
    }
    $partes[] = 'pesq_col:' . $pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE colaborador_id = {$colLocal}")->fetchColumn();
    foreach (['pdi_acoes', 'pdi_eventos', 'pdi_origens'] as $t) {
        try {
            $partes[] = $t . ':' . $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        } catch (Throwable $e) {
            // tabela auxiliar ausente neste banco: ignora
        }
    }
    return implode('|', $partes);
};

try {
    $como('admin', 'GET');
    $idExc = $fx->contrato('1', 'PZB1', 'ZZEXF Bypass Excluido', ['admissao' => '2024-03-10']);
    $idOk = $fx->contrato('2', 'PZB2', 'ZZEXF Bypass Legitimo', ['admissao' => '2024-03-10']);
    $tokEntExc = bin2hex(random_bytes(32));
    $tokEntOk = bin2hex(random_bytes(32));
    $reg = [];
    foreach (['exc' => [$idExc, $tokEntExc], 'ok' => [$idOk, $tokEntOk]] as $k => [$mid, $tok]) {
        $reg[$k] = [
            'mid' => $mid,
            'avexp' => $fx->avaliacaoExperiencia($mid, "ZZEXF {$k}"),
            'avdes' => $fx->avaliacaoDesempenho($mid, "ZZEXF {$k}"),
            'fb' => $fx->feedback($mid, "ZZEXF {$k}"),
            'pdi' => $fx->pdi($mid, "ZZEXF {$k}"),
            'ent' => $fx->entrevista($mid, "ZZEXF {$k}", $tok),
            'col' => $fx->colaboradorLocal("ZZEXF Local {$k}", $mid),
        ];
    }
    $fx->excluir('1');

    $pdi = new PdiService();
    $avExp = new AvaliacaoExperienciaService();
    $avDes = new AvaliacaoDesempenhoService();
    $fbk = new FeedbackService();
    $ent = new EntrevistaDesligamentoService();

    /** Operações por id/token: nome => closure(registro, ator). */
    $operacoes = static function (array $r, array $a, string $token) use ($pdi, $avExp, $avDes, $fbk, $ent, $agora, $hoje): array {
        $mid = $r['mid'];
        return [
            'PDI detalhe' => fn() => $pdi->detalhe($r['pdi'], $a, $hoje),
            'PDI atualizarEstrutura' => fn() => $pdi->atualizarEstrutura($r['pdi'], [], $a, $agora, null),
            'PDI adicionarAcompanhamento' => fn() => $pdi->adicionarAcompanhamento($r['pdi'], 'x', false, $a, $agora, null),
            'PDI registrarEspacoColaborador' => fn() => $pdi->registrarEspacoColaborador($r['pdi'], 'x', 'x', $a, $agora, null),
            'PDI registrarEvidencias' => fn() => $pdi->registrarEvidencias($r['pdi'], 'x', $a, $agora, null),
            'PDI alterarStatusAcao' => fn() => $pdi->alterarStatusAcao($r['pdi'], 1, 'concluida', $a, $agora, null),
            'PDI liberar' => fn() => $pdi->liberar($r['pdi'], $a, $agora, null),
            'PDI iniciar' => fn() => $pdi->iniciar($r['pdi'], $a, $agora, null),
            'PDI cancelar' => fn() => $pdi->cancelar($r['pdi'], 'motivo', $a, $agora, null),
            'PDI concluir' => fn() => $pdi->concluir($r['pdi'], 'objetivo_atingido', 'x', $a, $agora, null),
            'PDI reabrir' => fn() => $pdi->reabrir($r['pdi'], 'justificativa', $a, $agora, null),
            'PDI manterAposDesligamento' => fn() => $pdi->manterAposDesligamento($r['pdi'], 'justificativa', $a, $agora, null),
            'PDI pdisDoContrato' => fn() => $pdi->pdisDoContrato($mid, $a),
            'PDI contratoParaCriacao' => fn() => $pdi->contratoParaCriacao($mid, $a, $hoje),
            'PDI criarDeOrigem (avaliação de experiência)' => fn() => $pdi->criarDeOrigem('avaliacao_experiencia', $r['avexp'], [], $a, $agora, null),
            'PDI contextoOrigem (feedback)' => fn() => $pdi->contextoOrigem('feedback', $r['fb'], $a),
            'AvExp contratoParaAvaliar' => fn() => $avExp->contratoParaAvaliar($mid, '45', $a),
            'AvExp salvarRascunho' => fn() => $avExp->salvarRascunho($mid, '45', [], $a, $agora, null),
            'AvExp concluir' => fn() => $avExp->concluir($r['avexp'], [], $a, $agora, null),
            'AvExp registrarCienciaColaborador' => fn() => $avExp->registrarCienciaColaborador($r['avexp'], 'Fulano', $a, $agora),
            'AvExp reabrir' => fn() => $avExp->reabrir($r['avexp'], 'justificativa', $a, $agora, null),
            'AvExp detalhe' => fn() => $avExp->detalhe($r['avexp'], $a),
            'AvExp candidatosParaPdi' => fn() => $avExp->candidatosParaPdi($r['avexp'], $a),
            'AvDes novoParaContrato' => fn() => $avDes->novoParaContrato($mid, $a),
            'AvDes atualizar' => fn() => $avDes->atualizar($r['avdes'], [], $a, $agora, null),
            'AvDes concluir' => fn() => $avDes->concluir($r['avdes'], [], $a, $agora, null),
            'AvDes cancelar' => fn() => $avDes->cancelar($r['avdes'], 'motivo', $a, $agora, null),
            'AvDes registrarCienciaColaborador' => fn() => $avDes->registrarCienciaColaborador($r['avdes'], 'Fulano', $a, $agora),
            'AvDes reabrir' => fn() => $avDes->reabrir($r['avdes'], 'justificativa', $a, $agora, null),
            'AvDes detalhe' => fn() => $avDes->detalhe($r['avdes'], $a),
            'AvDes candidatosParaPdi' => fn() => $avDes->candidatosParaPdi($r['avdes'], $a),
            'AvDes criar (por contrato)' => fn() => $avDes->criar(['metadados_id' => $mid], $a, $agora, null),
            'Feedback contratoParaCriacao' => fn() => $fbk->contratoParaCriacao($mid, $a),
            // payload VÁLIDO até o carregador (tipo e data vêm validados antes de buscar o contrato)
            'Feedback criar (por contrato)' => fn() => $fbk->criar(['metadados_id' => $mid, 'tipo' => 'desenvolvimento', 'data_feedback' => '2026-11-12'], $a, $agora, null),
            'Feedback atualizar' => fn() => $fbk->atualizar($r['fb'], [], $a, $agora, null),
            'Feedback concluir' => fn() => $fbk->concluir($r['fb'], [], $a, $agora, null),
            'Feedback registrarEspacoColaborador' => fn() => $fbk->registrarEspacoColaborador($r['fb'], 'x', $a, $agora),
            'Feedback registrarCienciaColaborador' => fn() => $fbk->registrarCienciaColaborador($r['fb'], 'Fulano', $a, $agora),
            'Feedback reabrir' => fn() => $fbk->reabrir($r['fb'], 'justificativa', $a, $agora, null),
            'Feedback detalhe' => fn() => $fbk->detalhe($r['fb'], $a),
            'Feedback candidatosParaPdi' => fn() => $fbk->candidatosParaPdi($r['fb'], $a),
            'Entrevista resultadoIndividual' => fn() => $ent->resultadoIndividual($r['ent'], $agora),
            'Entrevista gerar (por contrato)' => fn() => $ent->gerar($mid, 1, $agora),
            'Entrevista regenerar' => fn() => $ent->regenerar($r['ent'], 1, $agora),
            'Entrevista cancelar' => fn() => $ent->cancelar($r['ent'], 1, $agora),
            'Entrevista liberarManualmente' => fn() => $ent->liberarManualmente($mid, 1, $agora),
            'Entrevista cancelarLiberacaoManual' => fn() => $ent->cancelarLiberacaoManual($mid, 1, $agora),
            'Entrevista PÚBLICA resolverParaPagina (token)' => fn() => $ent->resolverParaPagina($token, $agora),
            'Entrevista PÚBLICA registrarResposta (token)' => fn() => $ent->registrarResposta($token, [], $agora),
            'Pesquisa de Integração criarParaIntegracao (colaborador local)' => fn() => (new PesquisaIntegracaoService())->criarParaIntegracao($r['col']),
        ];
    };

    /** O resultado representa "sem sucesso"? */
    $semSucesso = static fn($v): bool => $v === null || $v === [] || $v === false || (is_array($v) && array_key_exists('ok', $v) && $v['ok'] === false)
        || (is_array($v) && isset($v['estado']) && $v['estado'] !== 'formulario' && !($v['ok'] ?? false));

    $fpAntes = $fingerprint($idExc, $reg['exc']['col']);
    $total = 0;
    foreach (['admin', 'rh'] as $role) {
        foreach ($operacoes($reg['exc'], ['id' => 1, 'role' => $role], $tokEntExc) as $nome => $op) {
            $como($role, 'POST');
            $resultado = null;
            $excecao = null;
            try {
                $resultado = $op();
            } catch (Throwable $e) {
                $excecao = $e;
            }
            $total++;
            $check($S::ocultadoNaRequisicao(), "[{$role}] {$nome}: a barreira de acesso individual foi acionada (não passou por fora)");
            $check($excecao !== null || $semSucesso($resultado), "[{$role}] {$nome}: operação sem sucesso sobre contrato excluído");
        }
    }
    $check($fingerprint($idExc, $reg['exc']['col']) === $fpAntes, "Nenhuma das {$total} operações alterou ou criou linhas nos módulos do contrato excluído (impressão digital idêntica)");

    // ---- controle: com o contrato legítimo, as mesmas operações CHEGAM ao carregador (a barreira não dispara)
    $alcancou = 0;
    $naoAlcancou = [];
    foreach ($operacoes($reg['ok'], ['id' => 1, 'role' => 'admin'], $tokEntOk) as $nome => $op) {
        if (!str_contains($nome, 'detalhe') && !str_contains($nome, 'resultadoIndividual') && !str_contains($nome, 'resolverParaPagina') && !str_contains($nome, 'pdisDoContrato')) {
            continue; // controle só em operações de leitura (as demais mudariam dados do legítimo)
        }
        $como('admin', 'GET');
        try {
            $r = $op();
        } catch (Throwable $e) {
            $r = null;
        }
        if (!$S::ocultadoNaRequisicao()) {
            $alcancou++;
        } else {
            $naoAlcancou[] = $nome;
        }
    }
    $check($naoAlcancou === [] && $alcancou >= 6, "Controle: as operações de leitura do contrato LEGÍTIMO não acionam a barreira ({$alcancou} verificadas)");
    $como('admin', 'GET');
    $check($pdi->detalhe($reg['ok']['pdi'], ['id' => 1, 'role' => 'admin'], $hoje) !== null && $ent->resolverParaPagina($tokEntOk, $agora)['estado'] !== 'invalido',
        'Controle: PDI e página pública de entrevista do contrato legítimo continuam acessíveis');
    $como(null, 'GET');
    $check($ent->resolverParaPagina($tokEntExc, $agora)['estado'] === 'invalido' && $S::ocultadoNaRequisicao(), 'Token público da entrevista do contrato EXCLUÍDO é tratado como inválido (visitante)');

    echo "\nEXCLUSAO_ANALITICA_BYPASS_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    if ($metodoOriginal === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $metodoOriginal;
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_BYPASS_OK\n";
