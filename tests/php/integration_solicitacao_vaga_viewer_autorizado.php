<?php

/**
 * Integração — Viewer autorizado a Solicitar Vaga (2026-10, caso real: Gustavo Guilherme dos
 * Santos Costa). Dois bugs relacionados, nesta ordem de descoberta:
 *
 *   1) "Acesso negado": a causa real não era `canCreate()` (que já liberava viewer+
 *      pode_solicitar_vaga corretamente) — era confusão entre DUAS permissões com nomes
 *      parecidos: `vagas.criar` (cadastro DIRETO de vaga publicada, AdminVagasController, sempre
 *      admin/rh) e `solicitacao_vaga.criar` (abrir uma Solicitação de Vaga). Gustavo tinha a
 *      primeira (errada para o que ele precisa) — ao tentar usá-la, caía em
 *      AdminVagasController::create(), que exige admin/rh e devolve "Acesso negado" (Auth::
 *      requireRole()) para um viewer.
 *   2) Mesmo com o backend (canCreate()) já correto, o CARD dedicado "Solicitações de Vaga" na
 *      Central do Portal E a aba homônima dentro de "Recrutamento e Seleção" usavam a regra
 *      `pedidos_vaga`, que NUNCA considerava `usuarios.pode_solicitar_vaga` — só `staff` ou as
 *      permissões individuais solicitacao_vaga.(visualizar|criar) ou kanban_vagas.visualizar. Um viewer autorizado só
 *      por `pode_solicitar_vaga` nunca via nenhum link para o fluxo, mesmo com o backend já aberto.
 *
 * Correção: `PortalNavegacaoService::visivel('pedidos_vaga')` passou a considerar também
 * `usuarios.pode_solicitar_vaga` (mesma fonte de AdminSolicitacoesVagaController::canCreate() —
 * reaproveitada via SolicitacaoVaga::userAccessProfilePublic(), nunca uma terceira regra).
 * `vagas.criar` continua INTOCADO (outro módulo, nunca entra nesta regra) e
 * AdminVagasController::create() continua exigindo admin/rh — Gustavo não ganhou nenhum acesso
 * novo a Vagas/Dashboard de Recrutamento/Pipeline/Candidaturas.
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

$mk = 'ZZVAU_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['solicitacoes' => [], 'usuarios' => [], 'cargos' => [], 'setores' => [], 'metadados' => [], 'jornadas' => []];

try {
    // ---- fixtures estruturais (mesmo padrão de integration_usuario_vaga_acesso.php) -----------
    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZZS' . substr($mk, -5), 'SETOR ' . $mk, 'setor-' . strtolower($mk), 'RHMADEPLANT']);
    $setorId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorId;

    $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute(['ZZC' . substr($mk, -5), 'CARGO ' . $mk, 'cargo-' . strtolower($mk), 'RHMADEPLANT']);
    $cargoId = (int)$pdo->lastInsertId();
    $criados['cargos'][] = $cargoId;

    $pdo->prepare('INSERT INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em) VALUES (?, ?, ?, NOW())')
        ->execute([$cargoId, $setorId, 'RHCONTRATOS']);

    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $contextoService = new UsuarioContextoOrganizacionalService();
    $mkUser = static function (string $sufixo, string $role) use ($pdo, $emailMk, $senha, $setorId, $contextoService, &$criados): int {
        $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
        User::setActiveStatus($id, true);
        $contextoService->definirContextoManual($id, null, $setorId, []);
        $criados['usuarios'][] = $id;
        return $id;
    };

    $jornadaId = CadastroOrganizacional::create('jornadas_trabalho', ['nome' => 'JORNADA ' . $mk, 'ativo' => 1]);
    $criados['jornadas'][] = $jornadaId;

    $payloadBase = [
        'setor_id' => $setorId, 'quantidade_vagas' => 1, 'cargo_id' => $cargoId,
        'tipo_vaga' => 'nova_posicao', 'tipo_contratacao' => 'clt',
        'salario_previsto' => 'R$ 5.000,00',
        'previsto_orcamento' => '1', 'jornada_trabalho_id' => $jornadaId,
        'escolaridade_minima' => 'medio', 'nivel_responsabilidade' => 'operacional', 'urgencia' => 'media',
        'data_prevista_inicio' => date('d/m/Y', strtotime('+30 days')),
        'entregas_esperadas' => str_repeat('Entrega detalhada da funcao com pelo menos cem caracteres para passar na validacao de conteudo. ', 2),
    ];

    $aprovadorId = $mkUser('aprov', 'rh');
    $gustavoId = $mkUser('gustavo', 'viewer');

    $ctrl = new AdminSolicitacoesVagaController();
    $rCanCreate = new ReflectionMethod($ctrl, 'canCreate');
    $rCanCreate->setAccessible(true);

    $permVagasCriarId = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'vagas.criar'")->fetchColumn();
    $check($permVagasCriarId > 0, 'Fixture: permissão vagas.criar existe no catálogo (não inventada para este teste)');

    // =============================================================================================
    // BLOCO 1 — backend (canCreate(), GET/POST, demais perfis, não-vazamento)
    // =============================================================================================

    // ---- (1) Viewer + pode_solicitar_vaga=0 (ainda não liberado) => negado --------------------
    $check($rCanCreate->invoke($ctrl, $gustavoId, 'viewer', false) === false, '(1) Viewer SEM pode_solicitar_vaga: canCreate() nega (mesmo gate de GET e POST — create()/store() chamam o mesmo método privado)');
    $bloqueouSemAcesso = false;
    try {
        SolicitacaoVaga::create($payloadBase, $gustavoId, '127.0.0.1');
    } catch (InvalidArgumentException $e) {
        $bloqueouSemAcesso = stripos($e->getMessage(), 'autoriz') !== false;
    }
    $check($bloqueouSemAcesso, '(1b) SolicitacaoVaga::create() também recusa diretamente, mesma regra central');

    // ---- (2)/(3) Viewer + pode_solicitar_vaga=1 + aprovador => GET e POST permitidos ----------
    User::setVagaAccess($gustavoId, true, $aprovadorId);
    $check($rCanCreate->invoke($ctrl, $gustavoId, 'viewer', false) === true, '(2) Viewer + pode_solicitar_vaga=1 + aprovador: canCreate() permite (GET)');

    $idCriado = SolicitacaoVaga::create($payloadBase, $gustavoId, '127.0.0.1');
    $criados['solicitacoes'][] = $idCriado;
    $registro = $pdo->query("SELECT solicitante_usuario_id, id FROM solicitacoes_vaga WHERE id = {$idCriado}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$registro['solicitante_usuario_id'] === $gustavoId, '(3) POST permitido de verdade: a solicitação criada mantém o PRÓPRIO Gustavo como solicitante');
    $aprovacaoLider = $pdo->prepare("SELECT destinatario_usuario_id FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = ? AND etapa = 'lider_imediato'");
    $aprovacaoLider->execute([$idCriado]);
    $check((int)$aprovacaoLider->fetchColumn() === $aprovadorId, '(Caso Gustavo) O aprovador/líder imediato configurado é utilizado normalmente — hierarquia de aprovação inalterada');

    // ---- (4) usuário inativo => negado ---------------------------------------------------------
    User::setActiveStatus($gustavoId, false);
    $check($rCanCreate->invoke($ctrl, $gustavoId, 'viewer', false) === false, '(4) Usuário inativo (email_verified_at NULL) => negado, mesmo com pode_solicitar_vaga=1');
    User::setActiveStatus($gustavoId, true);

    // ---- (5)/(6) Admin e RH continuam funcionando (via userCanEditRh — sem depender do flag) --
    $check($rCanCreate->invoke($ctrl, 999999, 'admin', false) === true, '(5) Admin continua funcionando (userCanEditRh, independe de pode_solicitar_vaga/userId real)');
    $check($rCanCreate->invoke($ctrl, 999999, 'rh', false) === true, '(6) RH continua funcionando');

    // ---- (7) Gestor (supervisor) continua conforme regra já existente -------------------------
    $check($rCanCreate->invoke($ctrl, 999999, 'viewer', true) === true, '(7) Supervisor (is_supervisor=1) continua liberado — regra preexistente, não alterada');

    // ---- (8) Permissão não vaza para outros módulos --------------------------------------------
    $check(Authorization::usuarioTemPermissao($gustavoId, 'dashboard_recrutamento.visualizar') === false, '(8a) Gustavo NÃO tem dashboard_recrutamento.visualizar — backend de AdminDashboardRecrutamentoController continua recusando');
    $check(Authorization::usuarioTemPermissao($gustavoId, 'pipeline.visualizar') === false, '(8b) Gustavo NÃO tem pipeline.visualizar — AdminPipelineController continua recusando (requireRoleOuPermissao admin/rh)');
    $check(Authorization::usuarioTemPermissao($gustavoId, 'kanban_vagas.visualizar') === false, '(8c) Gustavo NÃO tem kanban_vagas.visualizar');
    $corpoAvCreate = (function (): string {
        $r = new ReflectionMethod('AdminVagasController', 'create');
        $f = new SplFileObject($r->getFileName());
        $f->seek($r->getStartLine() - 1);
        $c = '';
        while ($f->key() < $r->getEndLine()) { $c .= $f->current(); $f->next(); }
        return $c;
    })();
    $check(str_contains($corpoAvCreate, "Auth::requireRole(['admin', 'rh'])"), '(8d) AdminVagasController::create() continua exigindo admin/rh — Gustavo (viewer) continua barrado de cadastrar/publicar Vaga diretamente, mesmo com vagas.criar');

    // =============================================================================================
    // BLOCO 2 — Central do Portal / menu / abas (o segundo sintoma relatado)
    // =============================================================================================
    $_SESSION['user_id'] = $gustavoId;
    $_SESSION['user_role'] = 'viewer';
    $_SESSION['user_is_supervisor'] = 0;

    // ---- (9)/(10) Sem nenhuma autorização: nem card, nem aba, nem backend ----------------------
    User::setVagaAccess($gustavoId, false, null);
    $navSemAcesso = new PortalNavegacaoService();
    $check($navSemAcesso->visivel('pedidos_vaga') === false, '(9) Viewer NÃO autorizado: a regra pedidos_vaga nega (nem card, nem aba aparecem)');
    $check($rCanCreate->invoke($ctrl, $gustavoId, 'viewer', false) === false, '(10) Acesso direto sem autorização continua negado no backend (consistente com o menu)');

    // Só vagas.criar (permissão do módulo ERRADO) — não deve destravar nada do fluxo de Solicitação.
    Authorization::sincronizar($gustavoId, [$permVagasCriarId]);
    $navSoVagasCriar = new PortalNavegacaoService();
    $check($navSoVagasCriar->visivel('pedidos_vaga') === false, '(L) vagas.criar sozinho NÃO libera o menu de Solicitação de Vaga — é permissão de outro módulo');
    Authorization::sincronizar($gustavoId, []);

    // ---- (1)/(I)/(J)/(K) Viewer autorizado: card dedicado + aba aparecem -----------------------
    User::setVagaAccess($gustavoId, true, $aprovadorId);
    $nav = new PortalNavegacaoService();
    $check($nav->visivel('pedidos_vaga') === true, '(Bloco2-1) Viewer autorizado (pode_solicitar_vaga=1): a regra pedidos_vaga libera');

    $cardSolicitacoes = array_values(array_filter(PortalNavegacaoService::definicao(), static fn(array $c): bool => $c['chave'] === 'solicitacoes-vaga'))[0];
    $cardVisivel = false;
    foreach ($cardSolicitacoes['itens'] as $item) {
        if ($nav->visivel($item['regra'])) {
            $cardVisivel = true;
        }
    }
    $check($cardVisivel, '(Bloco2-J) O card dedicado "Solicitações de Vaga" aparece na Central do Portal RH');

    $abasRecrutamento = array_column($nav->abas('recrutamento'), 'chave');
    $check(in_array('solicitacoes', $abasRecrutamento, true), '(Bloco2-K) Dentro de "Recrutamento e Seleção", a aba "Solicitações de Vaga" aparece');
    $check(!in_array('dashboard', $abasRecrutamento, true), '(3) Viewer autorizado NÃO vê a aba Dashboard de Recrutamento (sem dashboard_recrutamento.visualizar) — autorização não vazou');
    $check(!in_array('pipeline', $abasRecrutamento, true), '(4) Viewer autorizado NÃO vê a aba Pipeline (sem pipeline.visualizar) — autorização não vazou');

    // ---- (5, nota honesta) "Candidaturas" É visível — mas isso é PRÉ-EXISTENTE e INDEPENDENTE
    // desta correção: /admin/candidaturas já era `regra => 'aberto'` e AdminCandidaturasController::
    // index() já aceita ['admin','rh','viewer'] SEM gate adicional, para qualquer viewer autenticado
    // — nunca foi gateado por pode_solicitar_vaga nem por nenhuma permissão de recrutamento. Não é
    // vazamento desta correção; não alterado aqui (fora do escopo, exigiria aprovação separada).
    $check(in_array('candidaturas', $abasRecrutamento, true), '(5, documentado) "Candidaturas" aparece — comportamento PRÉ-EXISTENTE e INDEPENDENTE (regra "aberto" já antes desta correção), não uma permissão nova vazando');

    // ---- (7) Card/menu e backend usam a MESMA fonte (mesmo método estático) -------------------
    $check(
        SolicitacaoVaga::userAccessProfilePublic($gustavoId)['pode_solicitar_vaga'] === 1,
        '(Bloco2-7a) userAccessProfilePublic() (usado por canCreate()) confirma pode_solicitar_vaga=1'
    );
    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $r = new ReflectionMethod($classe, $metodo);
        $f = new SplFileObject($r->getFileName());
        $f->seek($r->getStartLine() - 1);
        $c = '';
        while ($f->key() < $r->getEndLine()) { $c .= $f->current(); $f->next(); }
        return $c;
    };
    $check(str_contains($corpoDoMetodo('PortalNavegacaoService', 'visivel'), 'podeSolicitarVaga'), '(Bloco2-7b) visivel() usa a propriedade podeSolicitarVaga na regra pedidos_vaga');
    $check(str_contains($corpoDoMetodo('PortalNavegacaoService', 'resolverPodeSolicitarVagaDaSessao'), 'SolicitacaoVaga::userAccessProfilePublic'), '(Bloco2-7c) ...que vem de SolicitacaoVaga::userAccessProfilePublic() — a MESMA fonte de dados do backend (canCreate()), nenhuma matriz paralela');

    echo "\nSOLICITACAO_VAGA_VIEWER_AUTORIZADO_OK\n";
} finally {
    if (!empty($criados['solicitacoes'])) {
        foreach ($criados['solicitacoes'] as $sid) {
            foreach (['solicitacao_vaga_aprovacoes', 'solicitacao_vaga_beneficios', 'solicitacao_vaga_competencias', 'solicitacao_vaga_auditoria'] as $t) {
                $pdo->prepare("DELETE FROM {$t} WHERE solicitacao_id = ?")->execute([$sid]);
            }
            $pdo->prepare('DELETE FROM vagas WHERE solicitacao_vaga_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM solicitacoes_vaga WHERE id = ?')->execute([$sid]);
        }
    }
    if (!empty($criados['usuarios'])) {
        foreach ($criados['usuarios'] as $uid) {
            Authorization::sincronizar($uid, []);
        }
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
    if (!empty($criados['jornadas'])) {
        $pdo->prepare('DELETE FROM jornadas_trabalho WHERE id IN (' . implode(',', array_map('intval', $criados['jornadas'])) . ')')->execute();
    }
    if (!empty($criados['cargos'])) {
        $pdo->prepare('DELETE FROM cargo_setores_metadados WHERE cargo_id IN (' . implode(',', array_map('intval', $criados['cargos'])) . ')')->execute();
        $pdo->prepare('DELETE FROM cargos WHERE id IN (' . implode(',', array_map('intval', $criados['cargos'])) . ')')->execute();
    }
    if (!empty($criados['setores'])) {
        $pdo->prepare('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $criados['setores'])) . ')')->execute();
    }
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_is_supervisor']);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
