<?php

/**
 * Integração — Etapa 3.1: permissões INDIVIDUAIS do Gestor (modelo recomendado ≠ concedível ≠ atribuído ≠ efetivo).
 * Prova, permissão por permissão (nunca com um conjunto amplo que esconda a falta de verificação de outra):
 *   - o catálogo inteiro tem decisão explícita para o Gestor (concedível OU bloqueada com categoria e motivo) e o modelo ⊂ concedíveis;
 *   - cada permissão concedível, sozinha, abre exatamente o seu endpoint e nenhuma outra permissão do catálogo; sem ela, 403;
 *   - duas contas Gestor com combinações diferentes têm acessos diferentes; "visualizar" não abre as escritas;
 *   - permissão do catálogo bloqueada, mesmo gravada (e mesmo com TODAS as permissões e setores), não abre nenhum endpoint não escopado;
 *   - a matriz recusa forjar bloqueadas no POST (sem gravação parcial); Viewer não recebe novas permissões operacionais, mas o legado
 *     gravado é preservado; Admin/RH seguem globais; o escopo setorial (união de setores; sem setores = nada) não muda ao conceder.
 * Requisições reais de controller via fixture_requisicao_http.php. Fixtures ZZIND*, limpas em `finally`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
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
$P = PermissaoPerfilPolicy::class;
$mk = 'zzind' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$setoresCriados = [];

$catalogo = $pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_KEY_PAIR);
$idPorCodigo = array_flip($catalogo);
$codigos = array_values($catalogo);
$n = 0;
$mkUser = static function (string $role, array $perms = [], array $setorIds = []) use ($pdo, $mk, $senhaHash, $idPorCodigo, &$n): int {
    $id = User::create('ZZIND ' . ++$n, "{$mk}.{$n}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    foreach ($perms as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $idPorCodigo[$c]]);
    }
    foreach ($setorIds as $s) {
        $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$id, $s]);
    }
    return $id;
};
$http = static function (int $userId, string $role, string $controller, string $metodo, array $post = [], array $args = []): int {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, '0', $controller, $metodo, '[]', json_encode($args), $post === [] ? '' : json_encode($post)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    return preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m) ? (int)$m[1] : 0;
};
$gravadas = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT p.codigo FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = ?');
    $st->execute([$id]);
    $r = $st->fetchAll(PDO::FETCH_COLUMN);
    sort($r);
    return $r;
};

try {
    $gestorAtivo = $P::gestorAtivo();
    $check($gestorAtivo, 'pré-requisito: perfil Gestor ativo no banco de teste');
    if (!$gestorAtivo) {
        throw new RuntimeException('Gestor inativo');
    }

    // ---- 1. Separação: modelo ⊂ concedíveis; todo o resto decidido com motivo -----------------------------
    $concediveis = $P::concediveis('gestor');
    $modelo = $P::modeloRecomendado('gestor');
    $check(array_diff($modelo, $concediveis) === [] && count($concediveis) > count($modelo) && array_diff($concediveis, $modelo) !== [], 'o modelo recomendado é subconjunto ESTRITO das concedíveis (concedível ≠ recomendado)');
    $semDecisao = array_filter($codigos, static fn(string $c): bool => !$P::gestorTemDecisao($c));
    $check($semDecisao === [], 'todo código do catálogo tem decisão explícita para o Gestor' . ($semDecisao ? ': ' . implode(', ', $semDecisao) : ''));
    $bloqueadas = array_values(array_diff($codigos, $concediveis));
    $categorias = [$P::BLOQUEIO_SEM_CONSUMIDOR, $P::BLOQUEIO_REQUER_ESCOPO, $P::BLOQUEIO_ADMINISTRATIVA, $P::BLOQUEIO_GLOBAL_NOMINAL];
    $semMotivo = array_filter($bloqueadas, static fn(string $c): bool => trim((string)$P::motivoBloqueio('gestor', $c)) === '' || !in_array($P::categoriaBloqueioGestor($c), $categorias, true));
    $check($semMotivo === [] && count($bloqueadas) > 40, 'toda permissão bloqueada tem categoria e motivo legível (' . count($bloqueadas) . ' bloqueadas)');
    $check(array_filter($concediveis, static fn(string $c): bool => $P::motivoBloqueio('gestor', $c) !== null) === [], 'nenhuma concedível carrega motivo de bloqueio');
    $recrut = ['candidaturas.editar', 'pipeline.visualizar', 'pipeline.movimentar', 'vagas.criar', 'vagas.editar', 'vagas.excluir', 'vagas.publicar']; // Etapa 3.2C.1: vagas.visualizar e candidaturas.visualizar passaram a concedíveis (leitura setorial)
    $check(array_diff($recrut, $bloqueadas) === [] && $P::categoriaBloqueioGestor('candidaturas.editar') === $P::BLOQUEIO_REQUER_ESCOPO && in_array('vagas.visualizar', $concediveis, true) && in_array('candidaturas.visualizar', $concediveis, true) && $P::categoriaBloqueioGestor('recruitment_webhooks.gerenciar') === $P::BLOQUEIO_ADMINISTRATIVA && $P::categoriaBloqueioGestor('indicacoes.gerenciar_pagamento') === $P::BLOQUEIO_GLOBAL_NOMINAL && $P::categoriaBloqueioGestor('solicitacao_vaga.editar') === $P::BLOQUEIO_SEM_CONSUMIDOR, 'Recrutamento (candidaturas/pipeline/vagas) segue bloqueado por falta de escopo; webhooks administrativo; indicações nominal; editar sem consumidor');
    $m = PermissaoMatrizService::construir('gestor', [], false);
    $cel = static function (array $m, string $c): ?array {
        foreach ($m['areas'] as $a) {
            foreach ($a['linhas'] as $l) {
                if (isset($l['celulas'][explode('.', $c, 2)[1]]) && $l['celulas'][explode('.', $c, 2)[1]]['codigo'] === $c) {
                    return $l['celulas'][explode('.', $c, 2)[1]];
                }
            }
        }
        return null;
    };
    $c1 = $cel($m, 'candidaturas.editar');
    $c2 = $cel($m, 'solicitacao_vaga.aprovar_lider');
    $check($c1 !== null && !$c1['aplicavel'] && !$c1['marcada'] && str_contains((string)$c1['motivo'], 'Candidaturas') && $c2 !== null && $c2['aplicavel'] && !$c2['marcada'] && $c2['motivo'] === null, 'matriz do Gestor: bloqueada vem desabilitada COM motivo; concedível fora do modelo vem habilitada e desmarcada');
    $cv1 = $cel($m, 'vagas.visualizar'); $cv2 = $cel($m, 'candidaturas.visualizar');
    $check($cv1 !== null && $cv1['aplicavel'] && !$cv1['marcada'] && $cv2 !== null && $cv2['aplicavel'] && !$cv2['marcada'], 'matriz: vagas.visualizar e candidaturas.visualizar habilitadas porém DESMARCADAS (fora do modelo recomendado; concessão sempre individual)');
    $js = json_encode($m['perfis']['gestor']['motivos']);
    $check(count($m['perfis']['gestor']['motivos']) === count($bloqueadas) && !str_contains($js, 'null'), 'a matriz entrega ao JS o motivo de cada bloqueada (tooltip acompanha a troca de perfil)');

    // ---- 2. Independência: cada concedível, sozinha, é efetiva e só ela ------------------------------------
    $independente = true;
    $detalhe = '';
    foreach ($concediveis as $perm) {
        $g = $mkUser('gestor', [$perm]);
        foreach ($codigos as $outro) {
            if (Authorization::usuarioTemPermissao($g, $outro) !== ($outro === $perm)) {
                $independente = false;
                $detalhe = "{$perm} → {$outro}";
                break 2;
            }
        }
    }
    $check($independente, 'cada permissão concedível, SOZINHA, é efetiva e não implica nenhuma outra do catálogo' . ($detalhe ? " ({$detalhe})" : ''));
    $gAll = $mkUser('gestor', $bloqueadas);
    $efetivas = array_filter($codigos, static fn(string $c): bool => Authorization::usuarioTemPermissao($gAll, $c));
    $check($efetivas === [], 'Gestor com TODAS as bloqueadas gravadas: nenhuma é efetiva (a política nega mesmo gravada)');

    // ---- 3. Endpoints: cada permissão abre só o seu; combinações diferentes, acessos diferentes -----------------
    $cod = 'ZI' . substr(md5($mk), 0, 4);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, 'ZZIND Setor A', ?, 1, 'zz-teste')")->execute([$cod, strtolower($cod)]);
    $setorA = (int)$pdo->lastInsertId();
    $setoresCriados[] = $setorA;
    $pares = [
        ['colaboradores.visualizar', 'AdminColaboradoresController', 'index'],
        ['avaliacao_experiencia.visualizar', 'AdminAvaliacoesExperienciaController', 'index'],
        ['avaliacao_desempenho.visualizar', 'AdminAvaliacoesDesempenhoController', 'index'],
        ['avaliacao_desempenho.avaliar', 'AdminAvaliacoesDesempenhoController', 'novo'],
        ['feedback.visualizar', 'AdminFeedbacksController', 'index'],
        ['feedback.avaliar', 'AdminFeedbacksController', 'novo'],
        ['pdi.visualizar', 'AdminPdisController', 'index'],
        ['pdi.gerenciar', 'AdminPdisController', 'novo'],
        ['solicitacao_vaga.visualizar', 'AdminSolicitacoesVagaController', 'index'],
        ['solicitacao_vaga.criar', 'AdminSolicitacoesVagaController', 'create'],
        ['kanban_vagas.visualizar', 'AdminSolicitacoesVagaKanbanController', 'index'],
        ['dashboard_integracao_agregado.visualizar', 'AdminDashboardIntegracaoAgregadoController', 'index'],
    ];
    $semPerm = $mkUser('gestor', [], [$setorA]);
    foreach ($pares as [$perm, $ctrl, $metodo]) {
        $g = $mkUser('gestor', [$perm], [$setorA]);
        $check($http($semPerm, 'gestor', $ctrl, $metodo) === 403 && $http($g, 'gestor', $ctrl, $metodo) !== 403, "{$perm}: sem ela 403; só com ela {$ctrl}::{$metodo} abre");
    }
    $soLeitura = $mkUser('gestor', ['colaboradores.visualizar', 'avaliacao_desempenho.visualizar', 'feedback.visualizar', 'pdi.visualizar', 'solicitacao_vaga.visualizar', 'kanban_vagas.visualizar'], [$setorA]);
    $escritas = [['AdminAvaliacoesDesempenhoController', 'novo'], ['AdminFeedbacksController', 'novo'], ['AdminPdisController', 'novo'], ['AdminSolicitacoesVagaController', 'create']];
    $abriu = array_filter($escritas, fn(array $e): bool => $http($soLeitura, 'gestor', $e[0], $e[1]) !== 403);
    $check($abriu === [], 'Gestor que só visualiza (colaboradores, desempenho, feedback, PDI, solicitações, Kanban) NÃO abre nenhuma escrita (avaliar/gerenciar/criar são independentes)');
    $gA = $mkUser('gestor', ['colaboradores.visualizar'], [$setorA]);
    $gB = $mkUser('gestor', ['colaboradores.visualizar', 'avaliacao_experiencia.visualizar', 'avaliacao_desempenho.visualizar', 'avaliacao_desempenho.avaliar'], [$setorA]);
    $check($http($gA, 'gestor', 'AdminColaboradoresController', 'index') !== 403 && $http($gA, 'gestor', 'AdminAvaliacoesDesempenhoController', 'index') === 403 && $http($gB, 'gestor', 'AdminAvaliacoesDesempenhoController', 'novo') !== 403 && $http($gA, 'gestor', 'AdminAvaliacoesDesempenhoController', 'novo') === 403, 'dois Gestores, mesmo setor, combinações diferentes: A só consulta colaboradores; B também avalia');

    // ---- 4. Recrutamento e demais bloqueadas: nenhum endpoint não escopado abre (com TODAS as permissões e setores) ----
    $gTodas = $mkUser('gestor', $codigos, [$setorA]);
    $naoEscopados = [
        ['AdminPipelineController', 'index'], ['AdminVagasController', 'create'], ['AdminVagasController', 'edit', ['1']], ['AdminCandidaturasController', 'show', ['1']],
        ['AdminIndicacoesController', 'index'], ['AdminIntegracoesController', 'index'], ['AdminMovimentacoesPessoalController', 'index'],
        ['AdminDashboardRecrutamentoController', 'index'], ['AdminRhIndicadoresController', 'index'], ['AdminDashboardTurnoverController', 'index'],
        ['AdminEntrevistaDesligamentoController', 'index'], ['AdminMensagensController', 'index'], ['AdminBeneficiosController', 'index'],
        ['AdminSetoresController', 'index'], ['AdminEmpresasController', 'index'], ['AdminCargosController', 'index'], ['AdminAvaliacoesController', 'index'],
        ['AdminManualController', 'index'], ['AdminController', 'index'],
        // exportações e downloads nominais
        ['AdminController', 'exportarColaboradores'], ['AdminSetoresController', 'export'], ['AdminIndicacoesController', 'export'],
        ['AdminCandidaturasController', 'download', ['1']], ['AdminCandidaturasController', 'downloadDocumento', ['1', '1']],
    ];
    $abertos = array_filter($naoEscopados, fn(array $e): bool => $http($gTodas, 'gestor', $e[0], $e[1], [], $e[2] ?? []) !== 403);
    $check($abertos === [], 'endpoints sem escopo setorial (Candidaturas, Pipeline, Vagas, Indicações, Integrações, Movimentação, dashboards globais, cadastros…) seguem 403 ao Gestor com TODAS as permissões e setor: ' . ($abertos ? json_encode(array_values($abertos)) : 'nenhum aberto'));
    // Etapa 3.2C.1: as duas LISTAGENS integradas abrem ao Gestor com permissão efetiva (escopo/projeção em integration_recrutamento_leitura_setorial)
    $check($http($gTodas, 'gestor', 'AdminCandidaturasController', 'index') === 200 && $http($gTodas, 'gestor', 'AdminVagasController', 'index') === 200, 'listagens de Vagas e Candidaturas (integradas, somente leitura) abrem ao Gestor com permissão efetiva');
    $rh = $mkUser('rh');
    $check($http($rh, 'rh', 'AdminCandidaturasController', 'index') !== 403 && $http($rh, 'rh', 'AdminPipelineController', 'index') !== 403, 'RH mantém o acesso global por perfil (sem linhas em usuario_permissoes)');

    // ---- 5. Escopo setorial não muda ao conceder; sem setores não há dado nominal ------------------------------
    $semSetor = $mkUser('gestor', $concediveis);
    $esc0 = EscopoSetorial::resolver($semSetor);
    $check(!$esc0->ehGlobal() && !$esc0->enxergaAlgo() && $esc0->codigos() === [], 'Gestor sem setores gerenciados, mesmo com TODAS as concedíveis: escopo restrito e vazio (nunca global)');
    $cod2 = 'ZJ' . substr(md5($mk), 0, 4);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, 'ZZIND Setor B', ?, 1, 'zz-teste')")->execute([$cod2, strtolower($cod2)]);
    $setorB = (int)$pdo->lastInsertId();
    $setoresCriados[] = $setorB;
    $doisSetores = $mkUser('gestor', $concediveis, [$setorA, $setorB]);
    $umSetor = $mkUser('gestor', $concediveis, [$setorA]);
    $escA = EscopoSetorial::resolver($umSetor)->codigos();
    $escAB = EscopoSetorial::resolver($doisSetores)->codigos();
    sort($escAB);
    $check($escA === [$cod] && $escAB === [$cod, $cod2] && EscopoSetorial::resolver($doisSetores)->permiteSetor($cod2) && !EscopoSetorial::resolver($umSetor)->permiteSetor($cod2), 'um setor = só ele; múltiplos = a UNIÃO; o setor de outro não é permitido (o escopo vem de usuario_setores_gerenciados)');
    $admin = $mkUser('admin');
    $antesA = $gravadas($umSetor);
    $http($admin, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['role' => 'gestor', 'permissao_ids' => array_map(fn($c) => $idPorCodigo[$c], $modelo)], [(string)$umSetor]);
    $check(EscopoSetorial::resolver($umSetor)->codigos() === [$cod] && $gravadas($umSetor) !== $antesA, 'conceder/alterar permissões pela matriz não altera os setores gerenciados');

    // ---- 6. Matriz/POST: combinações individuais aceitas; bloqueadas forjadas recusadas sem gravação ------------------
    $alvo = $mkUser('gestor', ['colaboradores.visualizar'], [$setorA]);
    $combo = ['colaboradores.visualizar', 'solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar', 'pdi.acompanhar'];
    $http($admin, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => array_map(fn($c) => $idPorCodigo[$c], $combo)], [(string)$alvo]);
    $ord = static function (array $a): array {
        sort($a);
        return $a;
    };
    $check($gravadas($alvo) === $ord($combo), 'o administrador concede uma combinação individual que inclui permissões fora do modelo (aprovar líder, dashboard agregado)');
    $antes = $gravadas($alvo);
    $falhouAlgum = false;
    foreach (['candidaturas.editar', 'pipeline.movimentar', 'vagas.criar', 'colaboradores.editar', 'solicitacao_vaga.editar', 'kanban_vagas.editar', 'recruitment_webhooks.gerenciar', 'indicacoes.visualizar', 'dashboard.visualizar', 'empresas.criar'] as $forjada) {
        $http($admin, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => array_map(fn($c) => $idPorCodigo[$c], array_merge($combo, [$forjada]))], [(string)$alvo]);
        $falhouAlgum = $falhouAlgum || $gravadas($alvo) !== $antes;
    }
    $check(!$falhouAlgum, 'forjar no POST qualquer bloqueada (Recrutamento, cadastros, webhooks, sem consumidor…) recusa tudo: nenhuma gravação parcial');
    $validacao = $P::validarSelecao('gestor', [$idPorCodigo['candidaturas.editar']], []);
    $check(!$validacao['ok'] && str_contains($validacao['mensagem'], 'Incompatível com o perfil GESTOR'), 'validação central recusa bloqueada com mensagem');

    // ---- 7. Viewer: sem novas concessões operacionais; legado preservado -----------------------------------------
    $viewer = $mkUser('viewer', ['pdi.visualizar', 'solicitacao_vaga.criar']);
    $http($admin, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => array_map(fn($c) => $idPorCodigo[$c], ['pdi.visualizar', 'solicitacao_vaga.criar', 'pdi.gerenciar'])], [(string)$viewer]);
    $check($gravadas($viewer) === ['pdi.visualizar', 'solicitacao_vaga.criar'], 'Viewer: tentar NOVA permissão operacional (pdi.gerenciar) recusa o salvamento; o legado gravado fica intacto');
    $http($admin, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => array_map(fn($c) => $idPorCodigo[$c], ['pdi.visualizar', 'dashboard_turnover.visualizar'])], [(string)$viewer]);
    $check($gravadas($viewer) === ['dashboard_turnover.visualizar', 'pdi.visualizar'], 'Viewer: dashboards podem ser concedidos; regularizar (remover) legado é ação explícita e aceita');
    $vNovo = $mkUser('viewer');
    $check(!$P::podeReceberPermissao('viewer', 'pdi.gerenciar') && !Authorization::usuarioTemPermissao($vNovo, 'pdi.gerenciar') && $P::permissaoEfetiva('viewer', 'pdi.visualizar') && Authorization::usuarioTemPermissao($viewer, 'pdi.visualizar'), 'Viewer: transição não concede novo; a já gravada continua efetiva (direitos efetivos não ampliados nem reduzidos)');
    $viewerCand = $mkUser('viewer', ['candidaturas.visualizar']);
    $check($http($viewerCand, 'viewer', 'AdminCandidaturasController', 'index') === 403, 'Viewer com candidaturas.visualizar gravada segue sem acesso');
    $mV = PermissaoMatrizService::construir('viewer', Authorization::idsAtribuidos($viewer), true);
    $cv = $cel($mV, 'pdi.visualizar');
    $cn = $cel($mV, 'pdi.gerenciar');
    $check($cv['aplicavel'] && $cv['marcada'] && !$cn['aplicavel'] && str_contains((string)$cn['motivo'], 'transição'), 'matriz do Viewer: legado gravado aparece habilitado/marcado (para regularizar); a mesma permissão não gravada vem bloqueada com motivo');

    // ---- 8. Hierarquia e perfis globais -----------------------------------------------------------------------
    $sup = $mkUser('admin');
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$sup]);
    $adminB = $mkUser('admin');
    $antesSup = $gravadas($sup);
    $stSup = $http($adminB, 'admin', 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => [$idPorCodigo['pdi.visualizar']]], [(string)$sup]);
    $check($stSup === 403 && $gravadas($sup) === $antesSup, 'Admin comum não altera permissões do Supervisor Geral');
    $alvoRh = $mkUser('viewer');
    $stRh = $http($rh, 'rh', 'AdminUsuariosController', 'updatePermissoes', ['role' => 'admin', 'permissao_ids' => []], [(string)$alvoRh]);
    $check($stRh === 403 && User::findById($alvoRh)->role === 'viewer', 'RH não atribui Admin nem edita permissões pela matriz');
} finally {
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email LIKE ?');
    $st->execute([$mk . '.%@teste.local']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($setoresCriados as $setorId) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
    }
}

// Fora do try: exit dentro dele pularia o finally e deixaria dados de teste no banco.
if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — permissões individuais do Gestor (modelo recomendado ≠ concedível ≠ efetivo).\n";
