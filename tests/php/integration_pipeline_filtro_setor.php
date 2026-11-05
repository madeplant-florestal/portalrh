<?php

/**
 * Integração — filtro por Setor no Kanban de Recrutamento (correção de 2026-11, pedido do RH),
 * combinável com o filtro por Vaga já existente. Setor da vaga vem de
 * `vagas.solicitacao_vaga_id -> solicitacoes_vaga.setor_id -> setores` — vagas criadas direto
 * pelo admin (sem Solicitação) não têm setor; nunca tratadas como "sem setor = excluir" (regra já
 * rejeitada no projeto para outros módulos) — ficam disponíveis só na opção explícita "Setor não
 * informado" (Candidatura::SETOR_NAO_INFORMADO), nunca escondidas.
 *
 * Fixtures ZZKFS-* (setor, solicitação, vagas, candidaturas), com limpeza em `finally`. Usa IDs de
 * cargo/colaborador/usuário/centro de custo já existentes só como referência de FK válida (nunca
 * altera esses registros, nunca presume qual é o "certo" para a regra de negócio em si).
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
$criados = ['candidaturas' => [], 'vagas' => [], 'solicitacoes' => [], 'setores' => []];

try {
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
    $colaboradorId = (int)$pdo->query('SELECT id FROM colaboradores ORDER BY id LIMIT 1')->fetchColumn();
    $usuarioId = (int)$pdo->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
    $centroCustoId = (int)$pdo->query('SELECT id FROM centros_custo ORDER BY id LIMIT 1')->fetchColumn();
    $check($cargoId > 0 && $colaboradorId > 0 && $usuarioId > 0 && $centroCustoId > 0, '(pré-condição) Existem registros locais suficientes para referenciar como FK válida (cargo/colaborador/usuário/centro de custo)');

    $pdo->prepare('INSERT INTO setores (nome, slug, ativo) VALUES (?, ?, 1)')
        ->execute(["ZZKFS Setor {$suffix}", "zzkfs-setor-{$suffix}"]);
    $setorId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorId;

    $pdo->prepare(
        'INSERT INTO solicitacoes_vaga (
            setor_id, quantidade_vagas, cargo_id, gestor_solicitante_colaborador_id, solicitante_usuario_id,
            tipo_vaga, tipo_contratacao, salario_previsto, centro_custo_id, previsto_orcamento, jornada_trabalho,
            escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio,
            urgencia, status_fluxo
        ) VALUES (?, 1, ?, ?, ?, \'nova_posicao\', \'clt\', 2000, ?, 1, \'44h semanais\', \'medio\', \'teste\', \'operacional\', CURDATE(), \'media\', \'aprovada\')'
    )->execute([$setorId, $cargoId, $colaboradorId, $usuarioId, $centroCustoId]);
    $solicitacaoId = (int)$pdo->lastInsertId();
    $criados['solicitacoes'][] = $solicitacaoId;

    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo, solicitacao_vaga_id) VALUES (?, 'desc', 'req', 1, ?)")
        ->execute(["ZZKFS Vaga Com Setor {$suffix}", $solicitacaoId]);
    $vagaComSetor = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaComSetor;

    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")
        ->execute(["ZZKFS Vaga Sem Setor {$suffix}"]);
    $vagaSemSetor = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaSemSetor;

    $mkCandidatura = static function (int $vagaId, string $nome) use ($pdo, &$criados, $suffix): int {
        static $seq = 0;
        $seq++;
        $pdo->prepare(
            "INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status)
             VALUES (?, ?, ?, '11999999999', ?, 'Cargo X', 'exp', 'x.pdf', 'novo')"
        )->execute([$vagaId, "ZZKFS {$nome} {$suffix}", "zzkfs{$seq}{$suffix}@teste.local", '1234567890' . $seq]);
        $id = (int)$pdo->lastInsertId();
        $criados['candidaturas'][] = $id;
        return $id;
    };

    $candComSetor = $mkCandidatura($vagaComSetor, 'ComSetor');
    $candSemSetor = $mkCandidatura($vagaSemSetor, 'SemSetor');

    // ---- filtro por setor específico ------------------------------------------------------------
    $porSetor = Candidatura::all(['setor_id' => $setorId]);
    $idsPorSetor = array_column($porSetor, 'id');
    $check(in_array($candComSetor, $idsPorSetor, true), '(filtro setor) Candidatura da vaga com Solicitação neste setor aparece');
    $check(!in_array($candSemSetor, $idsPorSetor, true), '(filtro setor) Candidatura da vaga sem Solicitação (sem setor) não aparece quando um setor específico é escolhido');

    // ---- "Setor não informado" — nunca escondido, nunca vira regra de exclusão geral -----------
    $naoInformado = Candidatura::all(['setor_id' => Candidatura::SETOR_NAO_INFORMADO]);
    $idsNaoInformado = array_column($naoInformado, 'id');
    $check(in_array($candSemSetor, $idsNaoInformado, true), '(setor não informado) Candidatura de vaga sem Solicitação aparece na opção explícita "Setor não informado"');
    $check(!in_array($candComSetor, $idsNaoInformado, true), '(setor não informado) Candidatura da vaga com setor não aparece aqui');

    // ---- sem filtro de setor: ambas aparecem (nada escondido por padrão) -----------------------
    $semFiltro = Candidatura::all(['vaga_id' => $vagaComSetor]);
    $check(in_array($candComSetor, array_column($semFiltro, 'id'), true), '(sem filtro de setor) Candidatura aparece normalmente quando só o filtro de vaga é usado');

    // ---- combinação AND com o filtro de vaga já existente ---------------------------------------
    $combinado = Candidatura::all(['setor_id' => $setorId, 'vaga_id' => $vagaSemSetor]);
    $check(array_column($combinado, 'id') === [], '(combinação) Setor certo + vaga errada não traz nada — os dois filtros aplicam em AND, não em OR');
    $combinadoCerto = Candidatura::all(['setor_id' => $setorId, 'vaga_id' => $vagaComSetor]);
    $check(in_array($candComSetor, array_column($combinadoCerto, 'id'), true), '(combinação) Setor certo + vaga certa traz a candidatura');

    // ---- metadados de setor expostos para a tela ------------------------------------------------
    $linhaComSetor = current(array_filter($porSetor, static fn(array $r): bool => (int)$r['id'] === $candComSetor));
    $check($linhaComSetor !== false && (int)$linhaComSetor['vaga_setor_id'] === $setorId, '(dados) Candidatura::all() expõe vaga_setor_id para a vaga vinda de Solicitação');

    echo "\nPIPELINE_FILTRO_SETOR_OK\n";
} finally {
    if (!empty($criados['candidaturas'])) {
        $ph = implode(',', array_fill(0, count($criados['candidaturas']), '?'));
        $pdo->prepare("DELETE FROM candidaturas WHERE id IN ($ph)")->execute($criados['candidaturas']);
    }
    if (!empty($criados['vagas'])) {
        $ph = implode(',', array_fill(0, count($criados['vagas']), '?'));
        $pdo->prepare("DELETE FROM vagas WHERE id IN ($ph)")->execute($criados['vagas']);
    }
    if (!empty($criados['solicitacoes'])) {
        $ph = implode(',', array_fill(0, count($criados['solicitacoes']), '?'));
        $pdo->prepare("DELETE FROM solicitacoes_vaga WHERE id IN ($ph)")->execute($criados['solicitacoes']);
    }
    if (!empty($criados['setores'])) {
        $ph = implode(',', array_fill(0, count($criados['setores']), '?'));
        $pdo->prepare("DELETE FROM setores WHERE id IN ($ph)")->execute($criados['setores']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nPIPELINE_FILTRO_SETOR_OK\n";
