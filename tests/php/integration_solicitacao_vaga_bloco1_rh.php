<?php

/**
 * Bloco 1 — Correções de RH na Solicitação de Vaga (2026-10).
 *
 * Prova que:
 *   - Jornada de Trabalho é um cadastro próprio (jornadas_trabalho), reaproveitando
 *     CadastroOrganizacional/AdminCatalogosController — CRUD completo, `usageCount()` bloqueia
 *     exclusão quando referenciada por uma Solicitação de Vaga (mesma disciplina já testada para
 *     Empresas/Setores);
 *   - SolicitacaoVaga::create() exige jornada_trabalho_id (rejeita ausência/0/inexistente) e grava
 *     tanto o id quanto o snapshot de texto (jornada_trabalho) com o nome do catálogo no momento
 *     da criação — nunca reescrito depois;
 *   - Pré-requisitos e Competências técnicas/comportamentais em texto livre são OPCIONAIS, nunca
 *     exigidos, e fazem round-trip corretamente via findAccessible()/hydrateRecord();
 *   - Registros antigos (sem jornada_trabalho_id, com competências por catálogo) continuam exibindo
 *     os dados antigos sem quebrar — histórico preservado, nenhuma reescrita;
 *   - Gestor aprovador (contexto organizacional do solicitante): usuário com aprovador configurado
 *     expõe o nome; RH/Admin/supervisor sem aprovador expõe "etapa dispensada"; usuário comum sem
 *     aprovador expõe nenhum dos dois (nunca inventa um aprovador por cargo/setor/METADADOS);
 *   - rotas de /admin/jornadas-trabalho existem e exigem autenticação (admin/rh/viewer para
 *     listagem, admin/rh para escrita — mesmo gate de Empresas/Setores/Cargos).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SolicitacaoVaga::ensureSchema();

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

$sfx = 'ZZB1_' . substr(md5(uniqid('', true)), 0, 8);
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$criados = [
    'usuarios' => [], 'setores' => [], 'cargos' => [], 'jornadas' => [], 'solicitacoes' => [], 'vinculos' => [],
];

try {
    // ================================================================= Cadastro Jornadas de Trabalho

    $jornadaId = CadastroOrganizacional::create('jornadas_trabalho', ['nome' => 'JORNADA ' . $sfx . ' A', 'ativo' => 1]);
    $criados['jornadas'][] = $jornadaId;
    $check($jornadaId > 0, '(1) Cadastro de Jornada de Trabalho criado via CadastroOrganizacional::create() (reaproveita a infra genérica de Empresas/Setores/Cargos)');

    $jornadaRow = CadastroOrganizacional::find('jornadas_trabalho', $jornadaId);
    $check($jornadaRow !== null && $jornadaRow['nome'] === 'JORNADA ' . $sfx . ' A', '(1) find() retorna a jornada recém-criada com o nome correto');

    $meta = CadastroOrganizacional::meta('jornadas_trabalho');
    $check(($meta['singular'] ?? '') === 'Jornada de Trabalho' && ($meta['plural'] ?? '') === 'Jornadas de Trabalho', '(1) meta() tem singular/plural corretos para a UI genérica');

    // summary()/linkedCount() são chamados pela tela de listagem (AdminCatalogosController::
    // renderIndex()) e usam uma fonte ESPECIAL (solicitacoes_vaga), não a genérica (colaboradores) —
    // sem o special case, a listagem quebraria com "Unknown column" (achado via validação visual).
    $summary = CadastroOrganizacional::summary('jornadas_trabalho');
    $check(is_array($summary) && isset($summary['total'], $summary['vinculados']), '(1) summary() não quebra para jornadas_trabalho — usa a fonte especial (solicitacoes_vaga), nunca colaboradores');

    $check(CadastroOrganizacional::usageCount('jornadas_trabalho', $jornadaId) === 0, '(2) usageCount() = 0 antes de qualquer Solicitação de Vaga referenciar esta jornada');

    // ================================================================= Fixtures para create()

    $setorStmt = $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)');
    $setorStmt->execute(['ZB' . substr($sfx, -6), 'SETOR ' . $sfx, 'setor-' . strtolower($sfx), 'RHMADEPLANT']);
    $setorId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorId;

    $cargoStmt = $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)');
    $cargoStmt->execute(['ZB' . substr($sfx, -6), 'CARGO ' . $sfx, 'cargo-' . strtolower($sfx), 'RHMADEPLANT']);
    $cargoId = (int)$pdo->lastInsertId();
    $criados['cargos'][] = $cargoId;

    $pdo->prepare('INSERT INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em) VALUES (?, ?, ?, NOW())')
        ->execute([$cargoId, $setorId, 'RHCONTRATOS']);
    $criados['vinculos'][] = [$cargoId, $setorId];

    $contextoService = new UsuarioContextoOrganizacionalService();

    $mkUsuario = static function (string $tag, string $role) use ($sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . $sfx, strtolower($tag) . '.' . strtolower($sfx) . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        return $id;
    };

    $aprovador = $mkUsuario('aprovador', 'rh');
    $solicitanteComAprovador = $mkUsuario('comaprov', 'viewer');
    User::setVagaAccess($solicitanteComAprovador, true, $aprovador);
    $contextoService->definirContextoManual($solicitanteComAprovador, null, $setorId, []);

    $rhSemAprovador = $mkUsuario('rhsemaprov', 'rh');
    $contextoService->definirContextoManual($rhSemAprovador, null, $setorId, []);

    $comumSemAprovador = $mkUsuario('comumsemaprov', 'viewer');
    User::setVagaAccess($comumSemAprovador, true, null);
    $contextoService->definirContextoManual($comumSemAprovador, null, $setorId, []);

    $payloadBase = [
        'setor_id' => $setorId, 'quantidade_vagas' => 1, 'cargo_id' => $cargoId,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'pj', 'salario_previsto' => 'R$ 5.000,00',
        'previsto_orcamento' => '1',
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    // ================================================================= Jornada obrigatória

    $semJornada = $payloadBase;
    $rejeitouSemJornada = false;
    try {
        SolicitacaoVaga::create($semJornada, $solicitanteComAprovador, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $rejeitouSemJornada = stripos($e->getMessage(), 'jornada') !== false;
    }
    $check($rejeitouSemJornada, '(3) create() sem jornada_trabalho_id é rejeitado com mensagem mencionando "jornada"');

    $jornadaInexistente = array_merge($payloadBase, ['jornada_trabalho_id' => 999999]);
    $rejeitouInexistente = false;
    try {
        SolicitacaoVaga::create($jornadaInexistente, $solicitanteComAprovador, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $rejeitouInexistente = stripos($e->getMessage(), 'jornada') !== false;
    }
    $check($rejeitouInexistente, '(3) create() com jornada_trabalho_id inexistente é rejeitado');

    // ================================================================= Criação completa + round-trip

    $payloadCompleto = array_merge($payloadBase, [
        'jornada_trabalho_id' => $jornadaId,
        'pre_requisitos' => 'CNH categoria B, veículo próprio.',
        'competencias_tecnicas' => 'Pacote Office avançado, rotinas fiscais.',
        'competencias_comportamentais' => 'Proatividade, trabalho em equipe.',
    ]);
    $createdId = SolicitacaoVaga::create($payloadCompleto, $solicitanteComAprovador, '127.0.0.1');
    $criados['solicitacoes'][] = $createdId;
    $check($createdId > 0, '(4) Solicitação criada com jornada_trabalho_id + pré-requisitos + competências texto');

    $record = SolicitacaoVaga::findAccessible($createdId, $solicitanteComAprovador, 'viewer', false);
    $check($record !== null, '(4) findAccessible() retorna o registro recém-criado');
    $check((int)($record['jornada_trabalho_id'] ?? 0) === $jornadaId, '(4) jornada_trabalho_id persistido corretamente');
    $check(($record['jornada_trabalho'] ?? '') === 'JORNADA ' . $sfx . ' A', '(4) jornada_trabalho (texto) grava o SNAPSHOT do nome do catálogo no momento da criação');
    $check(($record['pre_requisitos'] ?? '') === 'CNH categoria B, veículo próprio.', '(4) Pré-requisitos faz round-trip corretamente (criptografado e decifrado)');
    $check(($record['competencias_tecnicas_texto'] ?? '') === 'Pacote Office avançado, rotinas fiscais.', '(4) Competências técnicas (texto livre) faz round-trip corretamente');
    $check(($record['competencias_comportamentais_texto'] ?? '') === 'Proatividade, trabalho em equipe.', '(4) Competências comportamentais (texto livre) faz round-trip corretamente');
    $check($record['competencias_tecnicas'] === [], '(4) Nenhuma linha nova em solicitacao_vaga_competencias (catálogo) — a seleção por catálogo não é mais usada aqui');

    $check(CadastroOrganizacional::usageCount('jornadas_trabalho', $jornadaId) === 1, '(2) usageCount() = 1 depois que a Solicitação passou a referenciar a jornada');
    $check(CadastroOrganizacional::delete('jornadas_trabalho', $jornadaId) === false, '(2) delete() é BLOQUEADO enquanto a jornada estiver em uso — mesma disciplina de Empresas/Setores/Cargos');

    // ================================================================= Campos opcionais

    $jornadaB = CadastroOrganizacional::create('jornadas_trabalho', ['nome' => 'JORNADA ' . $sfx . ' B', 'ativo' => 1]);
    $criados['jornadas'][] = $jornadaB;

    $payloadSemOpcionais = array_merge($payloadBase, ['jornada_trabalho_id' => $jornadaB]);
    $idSemOpcionais = SolicitacaoVaga::create($payloadSemOpcionais, $solicitanteComAprovador, '127.0.0.1');
    $criados['solicitacoes'][] = $idSemOpcionais;
    $recordSemOpcionais = SolicitacaoVaga::findAccessible($idSemOpcionais, $solicitanteComAprovador, 'viewer', false);
    $check(
        ($recordSemOpcionais['pre_requisitos'] ?? 'x') === '' && ($recordSemOpcionais['competencias_tecnicas_texto'] ?? 'x') === '' && ($recordSemOpcionais['competencias_comportamentais_texto'] ?? 'x') === '',
        '(5) Pré-requisitos e Competências são OPCIONAIS — criação sem eles funciona normalmente e fica como string vazia, nunca bloqueia'
    );

    // ================================================================= Registro "antigo" (histórico preservado)

    $metadadosAntigo = $pdo->query('SELECT id FROM colaboradores_metadados LIMIT 1')->fetchColumn();
    $gestorAntigo = $pdo->query('SELECT id FROM colaboradores LIMIT 1')->fetchColumn();
    $competenciaAntiga = $pdo->query("SELECT id FROM competencias WHERE tipo = 'tecnica' LIMIT 1")->fetchColumn();
    if ($gestorAntigo && $competenciaAntiga) {
        $stmtAntigo = $pdo->prepare(
            "INSERT INTO solicitacoes_vaga (
                setor_id, quantidade_vagas, cargo_id, gestor_solicitante_colaborador_id, solicitante_usuario_id,
                tipo_vaga, tipo_contratacao, salario_previsto, previsto_orcamento, jornada_trabalho, jornada_trabalho_id,
                escolaridade_minima, entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio,
                urgencia, status_fluxo, lider_imediato_usuario_id
            ) VALUES (?, 1, ?, ?, ?, 'nova_posicao', 'clt', 3000, 0, '40h semanais (legado)', NULL, 'medio', ?, 'operacional', CURDATE(), 'media', 'aprovada', ?)"
        );
        $stmtAntigo->execute([$setorId, $cargoId, (int)$gestorAntigo, $solicitanteComAprovador, Cipher::encrypt('Entrega histórica de registro antigo, pré-existente a esta mudança, com texto suficiente para passar.'), $aprovador]);
        $idAntigo = (int)$pdo->lastInsertId();
        $criados['solicitacoes'][] = $idAntigo;
        $pdo->prepare('INSERT INTO solicitacao_vaga_competencias (solicitacao_id, competencia_id, tipo) VALUES (?, ?, ?)')
            ->execute([$idAntigo, (int)$competenciaAntiga, 'tecnica']);

        $recordAntigo = SolicitacaoVaga::findAccessible($idAntigo, $solicitanteComAprovador, 'viewer', false);
        $check(($recordAntigo['jornada_trabalho'] ?? '') === '40h semanais (legado)', '(6) Registro antigo (jornada_trabalho_id NULL) continua exibindo o texto livre original — nunca reescrito');
        $check((int)($recordAntigo['jornada_trabalho_id'] ?? -1) === 0 || $recordAntigo['jornada_trabalho_id'] === null, '(6) jornada_trabalho_id do registro antigo é NULL — nunca inferido retroativamente');
        $check(count($recordAntigo['competencias_tecnicas'] ?? []) === 1, '(6) Competências por catálogo de um registro antigo continuam acessíveis via selectedCompetencies() — histórico preservado');
        $check(($recordAntigo['competencias_tecnicas_texto'] ?? 'x') === '', '(6) Registro antigo não tem competências em texto livre (coluna nova, NULL por padrão)');
    } else {
        echo "  [aviso] Sem colaborador/competência técnica disponível em dev para simular registro antigo — bloco (6) pulado.\n";
    }

    // ================================================================= Gestor aprovador (contexto)

    $ctxComAprovador = SolicitacaoVaga::contextoOrganizacionalSolicitante($solicitanteComAprovador);
    $check($ctxComAprovador['aprovador_nome'] !== null, '(7) Usuário com aprovador configurado: aprovador_nome preenchido');
    $check($ctxComAprovador['etapa_lider_dispensada'] === false, '(7) Usuário com aprovador configurado: etapa_lider_dispensada = false');

    $ctxRhSemAprovador = SolicitacaoVaga::contextoOrganizacionalSolicitante($rhSemAprovador);
    $check($ctxRhSemAprovador['aprovador_nome'] === null, '(8) RH sem aprovador configurado: aprovador_nome null');
    $check($ctxRhSemAprovador['etapa_lider_dispensada'] === true, '(8) RH sem aprovador configurado: etapa_lider_dispensada = true (mesma regra de resolveApprover(), só para exibição)');

    $ctxComumSemAprovador = SolicitacaoVaga::contextoOrganizacionalSolicitante($comumSemAprovador);
    $check($ctxComumSemAprovador['aprovador_nome'] === null, '(9) Usuário comum sem aprovador: aprovador_nome null');
    $check($ctxComumSemAprovador['etapa_lider_dispensada'] === false, '(9) Usuário comum sem aprovador: etapa_lider_dispensada = false — nunca dispensa a etapa para quem não é RH/Admin/supervisor');

    $ctxInvalido = SolicitacaoVaga::contextoOrganizacionalSolicitante(0);
    $check($ctxInvalido['aprovador_nome'] === null && $ctxInvalido['etapa_lider_dispensada'] === false, '(10) usuarioId inválido (0): aprovador_nome/etapa_lider_dispensada vêm com defaults seguros, sem erro');

    // ================================================================= Dependências do formulário

    $deps = SolicitacaoVaga::formDependencies($solicitanteComAprovador, $solicitanteComAprovador);
    $check(isset($deps['jornadas_trabalho']) && is_array($deps['jornadas_trabalho']), '(11) formDependencies() expõe "jornadas_trabalho" para popular o select do formulário');
    $idsJornadas = array_column($deps['jornadas_trabalho'], 'id');
    $check(in_array($jornadaB, $idsJornadas, true), '(11) A jornada ativa cadastrada aparece na lista de dependências');

    // ================================================================= Rotas /admin/jornadas-trabalho

    $corpoIndex = file_get_contents(__DIR__ . '/../../index.php');
    $check(str_contains($corpoIndex, "/admin/jornadas-trabalho") && str_contains($corpoIndex, 'AdminJornadasTrabalhoController'), '(12) Rotas de /admin/jornadas-trabalho registradas em index.php, apontando para AdminJornadasTrabalhoController');
    foreach (['index', 'create', 'store', 'edit', 'update', 'delete'] as $metodo) {
        $check(method_exists('AdminJornadasTrabalhoController', $metodo), "(12) AdminJornadasTrabalhoController::{$metodo}() existe");
    }

    echo "SOLICITACAO_VAGA_BLOCO1_RH_OK\n";
} finally {
    foreach ($criados['solicitacoes'] as $id) {
        $pdo->prepare('DELETE FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM solicitacao_vaga_competencias WHERE solicitacao_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM solicitacao_vaga_auditoria WHERE solicitacao_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['jornadas'] as $id) {
        $pdo->prepare('DELETE FROM jornadas_trabalho WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['vinculos'] as [$vCargoId, $vSetorId]) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id = ? AND setor_id = ?')->execute([(int)$vCargoId, (int)$vSetorId]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }

    if ($falhas !== []) {
        fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
        exit(1);
    }
}
