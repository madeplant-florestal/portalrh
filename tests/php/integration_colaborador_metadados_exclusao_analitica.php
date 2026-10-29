<?php

/**
 * Integração — Exclusão analítica de contratos específicos (2026-10, migration
 * 2026-10-29-colaboradores-metadados-exclusoes-analiticas.sql). Mecanismo central e reaproveitável
 * para "este contrato nunca deve entrar em nenhuma estatística/listagem analítica do Portal", sem
 * apagar o registro original e sem heurística genérica por "setor vazio" (rejeitada
 * explicitamente — há muitos contratos legítimos sem setor).
 *
 * Fixtures ZZEXC-* com limpeza em `finally`. NUNCA toca nos 3 registros reais já excluídos
 * manualmente (APARECIDO CORREA VIEIRA, PAULO DE SOUZA DANTAS FILHO, PHAENNA MARTINS MIRA MEYER) —
 * cria seus próprios contratos de teste, rastreia os ids que cria, remove só os próprios.
 *
 * Prova, contra o banco:
 *   - excluir()/incluir()/estaExcluido() funcionam e validam entrada;
 *   - um contrato excluído desaparece de: ColaboradorMetadadosConsultaRepository::paginate()/
 *     summary(), PeopleAnalyticsRepository::buscarContratos()/distribuicaoAtivosPorSetor(),
 *     RhIndicadoresRepository::buscarContratos(), TurnoverDashboardRepository::buscarContratos();
 *   - o registro original permanece intacto em colaboradores_metadados (nunca apagado);
 *   - um contrato "sem setor" NÃO excluído continua aparecendo normalmente em todas essas
 *     consultas (a regra rejeitada "sem setor = excluir" não ressurgiu escondida);
 *   - FK RESTRICT protege o registro excluído contra exclusão física enquanto a marca existir;
 *   - duplicidade, motivo vazio e contrato inexistente são rejeitados.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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
$criados = ['metadados' => []];

$mk = static function (string $nome, bool $semSetor) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZEXC_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
            nome, empresa, unidade, cargo, codigo_cargo, setor, codigo_setor, sexo,
            admissao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, 'ZE' . $suffix, 'ZU' . $suffix, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq,
        'ZZEXC ' . $nome . ' ' . $suffix, 'ZZEXC Empresa ' . $suffix, 'ZZEXC Unidade',
        'ZZEXC Cargo', 'ZZEXCCG' . $suffix,
        $semSetor ? null : 'ZZEXC Setor ' . $suffix, $semSetor ? null : 'ZZEXCST' . $suffix,
        'F', date('Y-m-d', strtotime('-400 days')), 1, 'zzexc-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

try {
    // Caso A: será excluído das estatísticas (com setor preenchido — a exclusão não depende de
    // "sem setor").
    $idExcluido = $mk('Excluido', false);
    // Caso B: contrato normal, COM setor — controle positivo.
    $idNormal = $mk('Normal', false);
    // Caso C: contrato legítimo SEM setor, NUNCA excluído — prova que "sem setor" continua
    // participando normalmente (a regra rejeitada não ressurgiu).
    $idSemSetorLegitimo = $mk('SemSetorLegitimo', true);

    // ---- validações de entrada ----------------------------------------------------------------
    $check(!(ColaboradorMetadadosElegibilidadeService::excluir($idExcluido, '', 1)['ok'] ?? true), 'Motivo vazio é rejeitado');
    $check(!(ColaboradorMetadadosElegibilidadeService::excluir(999999999, 'motivo', 1)['ok'] ?? true), 'Contrato inexistente é rejeitado');
    $check(!ColaboradorMetadadosElegibilidadeService::estaExcluido($idExcluido), 'Antes de excluir: estaExcluido() é falso');

    // ---- exclusão -------------------------------------------------------------------------------
    $resExcluir = ColaboradorMetadadosElegibilidadeService::excluir($idExcluido, 'Motivo de teste ZZEXC', 1);
    $check($resExcluir['ok'] ?? false, 'excluir() aceita contrato real + motivo não vazio');
    $check(ColaboradorMetadadosElegibilidadeService::estaExcluido($idExcluido), 'estaExcluido() passa a ser verdadeiro');
    $check(!(ColaboradorMetadadosElegibilidadeService::excluir($idExcluido, 'de novo', 1)['ok'] ?? true), 'Excluir o mesmo contrato duas vezes é rejeitado (duplicidade)');

    // ---- registro original permanece intacto ----------------------------------------------------
    $stmt = $pdo->prepare('SELECT nome, ativo FROM colaboradores_metadados WHERE id = ?');
    $stmt->execute([$idExcluido]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);
    $check($original !== false && str_contains((string)$original['nome'], 'ZZEXC Excluido'), 'O registro original permanece intacto em colaboradores_metadados (nunca apagado)');

    // ---- FK RESTRICT: não é possível excluir fisicamente um contrato marcado -------------------
    $bloqueouDelete = false;
    try {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([$idExcluido]);
    } catch (PDOException $e) {
        $bloqueouDelete = true;
    }
    $check($bloqueouDelete, 'FK RESTRICT impede excluir fisicamente um contrato com marca de exclusão analítica ativa');

    // ---- ColaboradorMetadadosConsultaRepository ---------------------------------------------------
    $consulta = new ColaboradorMetadadosConsultaRepository($pdo);
    $buscaExcluido = $consulta->paginate(['q' => 'ZZEXC Excluido ' . $suffix], 1, 20);
    $check($buscaExcluido['total'] === 0, '(listagem) Contrato excluído some de paginate()');
    $buscaNormal = $consulta->paginate(['q' => 'ZZEXC Normal ' . $suffix], 1, 20);
    $check($buscaNormal['total'] === 1, '(listagem) Contrato normal (controle) continua aparecendo em paginate()');
    $buscaSemSetor = $consulta->paginate(['q' => 'ZZEXC SemSetorLegitimo ' . $suffix], 1, 20);
    $check($buscaSemSetor['total'] === 1, '(regra rejeitada) Contrato legítimo SEM setor, nunca marcado, continua aparecendo normalmente — "sem setor" não é critério de exclusão');

    $antesSummary = $consulta->summary();
    ColaboradorMetadadosElegibilidadeService::incluir($idExcluido);
    $depoisSummaryTemp = $consulta->summary();
    ColaboradorMetadadosElegibilidadeService::excluir($idExcluido, 'Motivo de teste ZZEXC', 1); // restaura para o resto do teste
    $check($depoisSummaryTemp['contratos'] === $antesSummary['contratos'] + 1, '(summary) Reincluir temporariamente aumenta a contagem em 1 (prova que summary() aplica a exclusão)');

    // ---- PeopleAnalyticsRepository ----------------------------------------------------------------
    $pa = new PeopleAnalyticsRepository($pdo);
    $identificadorExcluido = 'ZZEXC_' . $suffix . '_1';
    $identificadorNormal = 'ZZEXC_' . $suffix . '_2';
    $idsPeopleAnalytics = array_column($pa->buscarContratos(), 'identificador');
    $check(!in_array($identificadorExcluido, $idsPeopleAnalytics, true), '(PeopleAnalytics/Headcount) Contrato excluído não aparece em buscarContratos()');
    $check(in_array($identificadorNormal, $idsPeopleAnalytics, true), '(PeopleAnalytics/Headcount) Contrato normal continua em buscarContratos()');

    $distSetor = $pa->distribuicaoAtivosPorSetor(['codigo_empresa' => 'ZE' . $suffix]);
    $totalDistSetor = array_sum(array_column($distSetor, 'contratos'));
    // Caso B (com setor) + Caso C (sem setor, legítimo, nunca excluído) = 2 grupos, 1 contrato
    // cada. Caso A (excluído) NÃO deve entrar em nenhum grupo — por isso o total esperado é 2,
    // não 3 (os três contratos criados nesta empresa de teste).
    $check($totalDistSetor === 2, '(PeopleAnalytics) distribuicaoAtivosPorSetor() conta só os 2 contratos não excluídos (normal + sem-setor-legítimo), nunca o excluído (esperado 2, veio ' . $totalDistSetor . ')');

    // ---- RhIndicadoresRepository -------------------------------------------------------------------
    $ri = new RhIndicadoresRepository($pdo);
    $idsRi = array_column($ri->buscarContratos(['codigo_empresa' => 'ZE' . $suffix]), 'id');
    $check(!in_array($idExcluido, $idsRi, true), '(RhIndicadores) Contrato excluído não aparece em buscarContratos()');
    $check(in_array($idNormal, $idsRi, true), '(RhIndicadores) Contrato normal continua em buscarContratos()');

    // ---- TurnoverDashboardRepository ---------------------------------------------------------------
    $td = new TurnoverDashboardRepository($pdo);
    $turnoverContratos = $td->buscarContratos(['codigo_empresa' => 'ZE' . $suffix]);
    $check(count($turnoverContratos) === 2, '(Turnover) buscarContratos() traz só os 2 contratos não excluídos desta empresa de teste (normal + sem-setor-legítimo), não os 3 criados');

    // ---- reversão (incluir) -------------------------------------------------------------------------
    ColaboradorMetadadosElegibilidadeService::incluir($idExcluido);
    $check(!ColaboradorMetadadosElegibilidadeService::estaExcluido($idExcluido), 'incluir() reverte a exclusão');
    $buscaReincluido = $consulta->paginate(['q' => 'ZZEXC Excluido ' . $suffix], 1, 20);
    $check($buscaReincluido['total'] === 1, '(reversão) Após incluir(), o contrato volta a aparecer na listagem');

    // ---- auditoria: listarExcluidos() não expõe os de teste depois da reversão --------------------
    $listaFinal = array_column(ColaboradorMetadadosElegibilidadeService::listarExcluidos(), 'metadados_id');
    $check(!in_array($idExcluido, $listaFinal, true), '(auditoria) listarExcluidos() não lista mais o contrato após incluir()');

    echo "\nCOLABORADOR_METADADOS_EXCLUSAO_ANALITICA_OK\n";
} finally {
    foreach ($criados['metadados'] as $mid) {
        $pdo->prepare('DELETE FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id = ?')->execute([$mid]);
    }
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE id IN ($placeholders)")->execute($criados['metadados']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nCOLABORADOR_METADADOS_EXCLUSAO_ANALITICA_OK\n";
