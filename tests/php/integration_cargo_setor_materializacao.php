<?php

/**
 * Integração — Correção definitiva: cargos do Setor MANUTENÇÃO (código oficial 12) na Solicitação
 * de Vaga (2026-10, problema estrutural recorrente). `RHQUADROLOTCARGO` não é fonte utilizável na
 * Madeplant; a matriz oficial `cargo_setores_metadados` pode ficar incompleta para setores cujo
 * histórico de RHCONTRATOS nunca foi materializado. Esta correção generaliza a carga inicial de
 * 2026-09-14 (seed estático de 53 combinações) em um mecanismo RE-EXECUTÁVEL:
 * `CargoSetorMetadadosRepository::materializarPorCodigos()` (consumido por
 * scripts/extrair_cargo_setor_rhcontratos.php + scripts/materializar_cargo_setor_metadados.php).
 *
 * Importante: depois de materializado, o Setor passa a cair no ramo NORMAL de
 * `SolicitacaoVaga::resolverCargoDaVaga()` — o mesmo que já atende qualquer usuário autorizado
 * (`pode_solicitar_vaga`), nunca exigindo Admin/RH/supervisor. Nenhuma regra de autorização nova
 * foi criada; só a fonte de dados da matriz passou a poder ser alimentada por RHCONTRATOS.
 *
 * Cobre os 10 cenários da correção:
 *   1  Setor com matriz já materializada -> usa a matriz normalmente;
 *   2  Setor sem matriz, mas com relação real em RHCONTRATOS (via materializarPorCodigos) ->
 *      passa a apresentar os cargos;
 *   3  Cargo sem relação comprovada para aquele Setor -> não aparece;
 *   4  Viewer autorizado (pode_solicitar_vaga) consegue solicitar normalmente, sem fallback admin;
 *   5  POST aceita somente o Cargo efetivamente retornado pela matriz materializada;
 *   6  POST com Cargo fora do conjunto é recusado;
 *   7  Setor sem NENHUM dado comprovável continua bloqueado — com a mensagem melhorada (§8);
 *   8  Nenhuma permissão/role extra é concedida ao Viewer;
 *   9  Nenhum Cargo é criado por nome/código desconhecido (materializarPorCodigos nunca insere em
 *      `cargos`, só lê o catálogo já sincronizado);
 *  10  materializarPorCodigos() é idempotente (reaplicar o mesmo lote não duplica nem falha).
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_cargo_setor_materializacao (MySQL indisponivel)\n";
    exit(0);
}

SolicitacaoVaga::ensureSchema();
$pdo = Database::conn();
$repo = new CargoSetorMetadadosRepository();

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$mk = 'ZZCS_' . substr(md5(uniqid('', true)), 0, 6);
$sfx = strtolower($mk);
$criados = ['solicitacoes' => [], 'usuarios' => [], 'setores' => [], 'cargos' => [], 'jornadas' => []];

$limparSolicitacao = static function (int $id) use ($pdo): void {
    foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$id]);
};

$exit = 0;
try {
    $novoSetor = static function (string $tag) use ($pdo, $sfx, &$criados): array {
        $codigo = 'Z' . strtoupper(substr(md5('setor-' . $tag . $sfx), 0, 6));
        $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, 'RHMADEPLANT']);
        $id = (int)$pdo->lastInsertId();
        $criados['setores'][] = $id;
        return ['id' => $id, 'codigo' => $codigo];
    };
    $novoCargo = static function (string $codigo, string $tag) use ($pdo, $sfx, &$criados): array {
        $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, 'RHMADEPLANT']);
        $id = (int)$pdo->lastInsertId();
        $criados['cargos'][] = $id;
        return ['id' => $id, 'codigo' => $codigo];
    };
    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $contextoService = new UsuarioContextoOrganizacionalService();
    $novoUsuario = static function (string $tag, string $role) use ($sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . strtoupper($sfx), "{$sfx}+{$tag}@teste.local", $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        return $id;
    };

    // ---- fixtures -------------------------------------------------------------------------------
    $setorManutencaoLike = $novoSetor('manutencao-like'); // simula o caso real: sem matriz hoje
    $cargoMecanico = $novoCargo('ZM' . substr($sfx, -6), 'mecanico-manutencao');
    $cargoSupervisor = $novoCargo('ZS' . substr($sfx, -6), 'supervisor-manutencao');
    $cargoDeOutroSetor = $novoCargo('ZO' . substr($sfx, -6), 'cargo-de-outro-setor'); // nunca materializado p/ este setor
    $setorSemDadoNenhum = $novoSetor('sem-dado-nenhum'); // nenhuma relação, nem matriz nem RHCONTRATOS

    $totalCargosAntes = (int)$pdo->query('SELECT COUNT(*) FROM cargos')->fetchColumn();

    // ---- 2/9/10: materializarPorCodigos() -------------------------------------------------------
    $lote = [
        ['codigo_cargo' => $cargoMecanico['codigo'], 'codigo_setor' => $setorManutencaoLike['codigo']],
        ['codigo_cargo' => $cargoSupervisor['codigo'], 'codigo_setor' => $setorManutencaoLike['codigo']],
        ['codigo_cargo' => $cargoMecanico['codigo'], 'codigo_setor' => $setorManutencaoLike['codigo']], // duplicado no mesmo lote
        ['codigo_cargo' => 'ZZINEXISTENTE' . $sfx, 'codigo_setor' => $setorManutencaoLike['codigo']], // código de cargo desconhecido
    ];
    $r1 = $repo->materializarPorCodigos($lote, 'RHCONTRATOS');
    $check($r1['inseridos'] === 2, '2 materializarPorCodigos() insere exatamente os 2 pares válidos e distintos (mecânico + supervisor)');
    $check($r1['sem_correspondencia_local'] === 1, '9 par com código de Cargo desconhecido não gera correspondência (nem erro, nem criação por nome)');
    $totalCargosDepois = (int)$pdo->query('SELECT COUNT(*) FROM cargos')->fetchColumn();
    $check($totalCargosDepois === $totalCargosAntes, '9 materializarPorCodigos() NUNCA cria linha em `cargos` — só lê o catálogo já sincronizado');

    // ---- 10: idempotência -------------------------------------------------------------------------
    $r2 = $repo->materializarPorCodigos($lote, 'RHCONTRATOS');
    $check($r2['inseridos'] === 0 && $r2['ja_existentes'] === 2, '10 reaplicar o MESMO lote é idempotente: 0 novos, os 2 já existentes reconhecidos, sem erro/duplicidade');

    // ---- 1/2/3: a matriz passa a responder normalmente -------------------------------------------
    $cargosDoSetor = $repo->cargosPorSetor($setorManutencaoLike['id']);
    $idsCargosDoSetor = array_column($cargosDoSetor, 'id');
    $check(in_array($cargoMecanico['id'], $idsCargosDoSetor, true), '1/2 Setor (antes sem matriz) passa a listar o Cargo Mecânico após a materialização via RHCONTRATOS');
    $check(in_array($cargoSupervisor['id'], $idsCargosDoSetor, true), '1/2 ...e também o Cargo Supervisor de Manutenção');
    $check(!in_array($cargoDeOutroSetor['id'], $idsCargosDoSetor, true), '3 Cargo sem relação comprovada para este Setor NÃO aparece (não materializado)');

    // Bloco 1 (2026-10): Jornada de Trabalho estruturada.
    $jornadaId = CadastroOrganizacional::create('jornadas_trabalho', ['nome' => 'JORNADA CARGO SETOR ' . $sfx, 'ativo' => 1]);
    $criados['jornadas'][] = $jornadaId;
    $payloadBase = [
        'quantidade_vagas' => 1,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'clt',
        'salario_previsto' => 'R$ 4.500,00',
        'previsto_orcamento' => '1', 'jornada_trabalho_id' => $jornadaId,
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    // ---- 4/5: Viewer autorizado consegue solicitar, só com o Cargo materializado -----------------
    $aprovador = $novoUsuario('aprovador-manutencao', 'rh');
    $viewer = $novoUsuario('viewer-manutencao', 'viewer');
    $papelAntes = (string)$pdo->query("SELECT role FROM usuarios WHERE id = {$viewer}")->fetchColumn();
    $supervisorAntes = (int)$pdo->query("SELECT is_supervisor FROM usuarios WHERE id = {$viewer}")->fetchColumn();
    User::setVagaAccess($viewer, true, $aprovador);
    $contextoService->definirContextoManual($viewer, null, $setorManutencaoLike['id'], []);

    $idViewer = SolicitacaoVaga::create(array_merge($payloadBase, [
        'setor_id' => $setorManutencaoLike['id'], 'cargo_id' => $cargoMecanico['id'],
    ]), $viewer, '127.0.0.1');
    $criados['solicitacoes'][] = $idViewer;
    $cargoGravado = (int)$pdo->query("SELECT cargo_id FROM solicitacoes_vaga WHERE id = {$idViewer}")->fetchColumn();
    $check($cargoGravado === $cargoMecanico['id'], '4/5 Viewer (pode_solicitar_vaga) consegue solicitar normalmente usando um Cargo da matriz materializada — sem precisar de fallback administrativo');

    // ---- 6: POST com Cargo fora do conjunto é recusado --------------------------------------------
    $postInvalidoRecusado = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, [
            'setor_id' => $setorManutencaoLike['id'], 'cargo_id' => $cargoDeOutroSetor['id'],
        ]), $viewer, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $postInvalidoRecusado = $e->getMessage() === 'Selecione um Cargo vinculado ao Setor selecionado.';
    }
    $check($postInvalidoRecusado, '6 POST com Cargo fora da matriz materializada (cargo_de_outro_setor) é recusado pelo backend, mesmo para o mesmo Viewer já autorizado');

    // ---- 7: Setor sem NENHUM dado comprovável continua bloqueado (mensagem melhorada) -----------
    $contextoService->definirContextoManual($viewer, null, $setorSemDadoNenhum['id'], []);
    $bloqueadoSemDado = false;
    try {
        SolicitacaoVaga::create(array_merge($payloadBase, [
            'setor_id' => $setorSemDadoNenhum['id'], 'cargo_id' => $cargoMecanico['id'],
        ]), $viewer, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueadoSemDado = $e->getMessage() === 'Não encontramos cargos vinculados oficialmente a este setor. O RH foi orientado a revisar a matriz Cargo × Setor.';
    }
    $check($bloqueadoSemDado, '7 Setor sem nenhuma relação comprovável (nem matriz, nem RHCONTRATOS materializado) continua bloqueado — agora com mensagem que não atribui a culpa automaticamente ao METADADOS');

    // ---- 8: nenhuma permissão/role extra concedida -------------------------------------------------
    $papelDepois = (string)$pdo->query("SELECT role FROM usuarios WHERE id = {$viewer}")->fetchColumn();
    $supervisorDepois = (int)$pdo->query("SELECT is_supervisor FROM usuarios WHERE id = {$viewer}")->fetchColumn();
    $qtdPermissoesExtra = (int)$pdo->query("SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id = {$viewer}")->fetchColumn();
    $check($papelAntes === 'viewer' && $papelDepois === 'viewer', '8 Viewer continua Viewer — nenhuma elevação de papel');
    $check($supervisorAntes === 0 && $supervisorDepois === 0, '8 Viewer continua sem is_supervisor');
    $check($qtdPermissoesExtra === 0, '8 Nenhuma permissão individual extra foi concedida ao Viewer — acesso veio só de pode_solicitar_vaga + dados materializados');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nCARGO_SETOR_MATERIALIZACAO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['solicitacoes'] as $id) {
        $limparSolicitacao((int)$id);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE setor_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['jornadas'] as $id) {
        $pdo->prepare('DELETE FROM jornadas_trabalho WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit);
