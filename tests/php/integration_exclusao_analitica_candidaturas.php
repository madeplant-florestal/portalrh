<?php

/**
 * Integração — Candidatura::all() (lista de candidatos e Kanban de recrutamento) e a exclusão analítica.
 *
 * Decisão técnica documentada: o método lista CANDIDATOS (pessoas externas) e só toca em
 * `solicitacoes_vaga` para ler `sv.setor_id` (setor da vaga). Nenhuma coluna de colaborador/contrato é
 * lida. Uma solicitação de vaga ligada a colaborador de contrato excluído SAI das listas e estatísticas
 * de Solicitação de Vaga, mas os candidatos da vaga publicada NÃO podem sumir: são candidaturas
 * legítimas que apenas compartilham a vaga com um relacionamento administrativo que não os representa.
 *
 * Prova: (1) a candidatura continua listada, com setor da vaga, antes e depois da exclusão; (2) a mesma
 * solicitação desaparece de Solicitação de Vaga (assimetria intencional); (3) candidato cuja "indicação"
 * cita pelo NOME um colaborador excluído não é afetado (nada por nome); (4) nenhuma coluna de
 * colaborador/contrato é exposta pelo método; (5) filtros de vaga/setor seguem funcionando.
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

try {
    $_SESSION = ['user_role' => 'rh', 'user_id' => 1];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $S::reiniciarEstadoDaRequisicao();

    $idExc = $fx->contrato('1', 'PZV1', 'ZZEXF Vaga Excluido');
    $colExc = $fx->colaboradorLocal('ZZEXF Substituido Excluido', $idExc);
    $colOk = $fx->colaboradorLocal('ZZEXF Substituido Legitimo', null);
    $setorId = (int)$pdo->query('SELECT id FROM setores ORDER BY id LIMIT 1')->fetchColumn();
    $svExc = $fx->solicitacaoVaga(['setor_id' => $setorId, 'gestor_solicitante_colaborador_id' => $colOk, 'colaborador_substituido_id' => $colExc, 'status_fluxo' => 'aprovada']);
    $svOk = $fx->solicitacaoVaga(['setor_id' => $setorId, 'gestor_solicitante_colaborador_id' => $colOk, 'colaborador_substituido_id' => $colOk, 'status_fluxo' => 'aprovada']);
    $vagaExc = $fx->vaga('ZZEXF Vaga da solicitacao do excluido', $svExc);
    $vagaOk = $fx->vaga('ZZEXF Vaga da solicitacao legitima', $svOk);
    $vagaManual = $fx->vaga('ZZEXF Vaga manual sem solicitacao', null);
    $candExc = $fx->candidatura($vagaExc, 'ZZEXF Candidato da vaga do excluido');
    $candOk = $fx->candidatura($vagaOk, 'ZZEXF Candidato da vaga legitima');
    $candManual = $fx->candidatura($vagaManual, 'ZZEXF Candidato vaga manual');
    // candidato cuja indicação cita, por NOME, o colaborador excluído (texto livre — nunca critério)
    $candIndic = $fx->candidatura($vagaOk, 'ZZEXF Candidato Indicado');
    $pdo->prepare('UPDATE candidaturas SET indicacao_colaborador = 1, indicacao_colaborador_nome = ? WHERE id = ?')->execute(['ZZEXF Substituido Excluido', $candIndic]);

    $idsDe = static fn(array $filtros): array => array_map('intval', array_column(Candidatura::all($filtros), 'id'));
    $todos = [$candExc, $candOk, $candManual, $candIndic];
    $antes = $idsDe([]);
    $check(count(array_intersect($todos, $antes)) === 4, '(controle) Antes de excluir, os 4 candidatos de teste aparecem em Candidatura::all()');
    $listaSvAntes = array_map('intval', array_column(SolicitacaoVaga::allForUser(1, 'admin', true), 'id'));
    $check(in_array($svExc, $listaSvAntes, true), '(controle) Antes de excluir, a solicitação ligada ao colaborador aparece em Solicitação de Vaga');

    $S::excluirPorVinculo($fx->emp, $fx->uni, '1', 'ZZEXF teste candidaturas', 1);

    $depois = $idsDe([]);
    $check(count(array_intersect($todos, $depois)) === 4 && count(array_intersect($antes, $depois)) === count($antes),
        'Depois de excluir o contrato: NENHUM candidato some de Candidatura::all() (lista e Kanban de recrutamento)');
    $listaSvDepois = array_map('intval', array_column(SolicitacaoVaga::allForUser(1, 'admin', true), 'id'));
    $check(!in_array($svExc, $listaSvDepois, true) && in_array($svOk, $listaSvDepois, true),
        'Assimetria intencional: a SOLICITAÇÃO sai de Solicitação de Vaga, mas os candidatos da vaga publicada permanecem');

    $linha = null;
    foreach (Candidatura::all(['vaga_id' => $vagaExc]) as $l) {
        if ((int)$l['id'] === $candExc) {
            $linha = $l;
        }
    }
    $check($linha !== null && (int)$linha['vaga_setor_id'] === $setorId && ($linha['vaga_setor_nome'] ?? '') !== '',
        'O candidato da vaga do excluído continua com o setor da vaga resolvido (único dado lido de solicitacoes_vaga)');
    $chavesSuspeitas = array_filter(array_keys($linha ?? []), static fn(string $k): bool => str_contains($k, 'metadados') || str_contains($k, 'contrato') || preg_match('/^(gestor_solicitante|colaborador_substituido|lider_imediato|nome_contratado)/', $k) === 1);
    $check($chavesSuspeitas === [], 'Candidatura::all() não expõe nenhuma coluna de colaborador/contrato/solicitação (' . count($linha ?? []) . ' colunas conferidas)');
    $check(in_array($candIndic, $idsDe([]), true), 'Candidato indicado que cita, pelo NOME, um colaborador excluído não é afetado (nada por nome)');
    $check($idsDe(['vaga_id' => $vagaExc]) === [$candExc] && $idsDe(['setor_id' => $setorId]) !== [] && in_array($candManual, $idsDe(['setor_id' => Candidatura::SETOR_NAO_INFORMADO]), true),
        'Filtros de vaga e de setor (inclusive "setor não informado") seguem funcionando');

    // Individual: a candidatura continua acessível por id a quem já podia (não é registro de contrato)
    $_SESSION = [];
    $S::reiniciarEstadoDaRequisicao();
    $check(Candidatura::find($candExc) !== null, 'Candidatura::find() do candidato da vaga do excluído segue acessível (candidato legítimo)');

    echo "\nEXCLUSAO_ANALITICA_CANDIDATURAS_OK\n";
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
echo "\nEXCLUSAO_ANALITICA_CANDIDATURAS_OK\n";
