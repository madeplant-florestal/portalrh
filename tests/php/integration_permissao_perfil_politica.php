<?php

/**
 * Integração — Etapa 2B (2026-11): política central de permissões por perfil (PermissaoPerfilPolicy) integrada a
 * Authorization (acesso efetivo) e a AdminUsuariosController::updatePermissoes (validação da seleção final).
 *
 * Prova que: a política classifica por código real; o viewer só tem efeito nas permissões compatíveis (dashboards, transição e
 * pendências já gravadas); permissão legada incompatível continua gravada mas NÃO concede acesso (nem por URL direta); o
 * salvamento de seleção inválida (inexistente, inativa, incompatível) é recusado por inteiro, preservando o que estava gravado;
 * Admin/RH preservam o acesso global; Autoridade continua prevalecendo (Administrador não altera o Supervisor Geral).
 * Requisições em processos próprios (fixture_requisicao_http.php).
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
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$criados = [];
$permTemp = [];

$novo = static function (string $apelido, string $role, int $flag = 0) use ($pdo, $senha, $suffix, &$criados): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZ2B ' . $apelido, strtolower($apelido) . '.zz2b.' . $suffix . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $criados[] = $id;
    return $id;
};
$ids = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ids[$r['codigo']] = (int)$r['id'];
}
$conceder = static function (int $usuario, array $codigos) use ($pdo, $ids): void {
    $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$usuario]);
    foreach ($codigos as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$usuario, $ids[$c]]);
    }
};
$gravadas = static function (int $usuario) use ($pdo): array {
    $st = $pdo->prepare('SELECT p.codigo FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = ? ORDER BY p.codigo');
    $st->execute([$usuario]);
    $r = $st->fetchAll(PDO::FETCH_COLUMN);
    sort($r);
    return $r;
};
$requisitar = static function (int $userId, string $role, bool $sessaoSup, string $controller, string $metodo, array $post = [], array $args = [], array $get = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sessaoSup ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/@@STATUS=(\d+)@@\s*$/', $saida, $m)) {
        $status = (int)$m[1];
        $saida = substr($saida, 0, -strlen($m[0]));
    }
    return [$status, $saida];
};

$dashboards = ['dashboard.visualizar', 'indicadores_rh.visualizar', 'dashboard_turnover.visualizar', 'dashboard_recrutamento.visualizar', 'dashboard_entrevista_desligamento.visualizar'];

try {
    // ---- 1. política pura -------------------------------------------------------------------------------------------------
    $P = 'PermissaoPerfilPolicy';
    $check(PermissaoPerfilPolicy::classificar('viewer', 'dashboard.visualizar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('viewer', 'candidaturas.visualizar') === $P::INCOMPATIVEL, '(política) viewer: dashboard permitido; candidaturas incompatível — por CÓDIGO, não por sufixo');
    $check(PermissaoPerfilPolicy::classificar('viewer', 'pipeline.visualizar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('viewer', 'indicacoes.gerenciar_pagamento') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('viewer', 'vagas.publicar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('viewer', 'recruitment_webhooks.gerenciar') === $P::INCOMPATIVEL, '(política) viewer: pipeline, financeira, gerenciamento e publicação são incompatíveis');
    $check(PermissaoPerfilPolicy::classificar('viewer', 'pdi.visualizar') === $P::TRANSITORIA_GESTOR && PermissaoPerfilPolicy::classificar('viewer', 'entrevista_desligamento.resultados') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('viewer', 'mensagens.criar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('viewer', 'pesquisa_reacao_integracao.gerenciar') === $P::INCOMPATIVEL, '(política) viewer: Entrevista de Desligamento, Pesquisa de Reação e Mensagens são INCOMPATÍVEIS (decididos; não há mais pendentes)');
    $check(PermissaoPerfilPolicy::classificar('rh', 'indicacoes.gerenciar_pagamento') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('admin', 'qualquer.coisa') === $P::PERMITIDA, '(política) admin e RH: acesso global (qualquer permissão é permitida)');
    $check(PermissaoPerfilPolicy::classificar('gestor', 'integracao_colaborador.visualizar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'candidaturas.visualizar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'avaliacao_experiencia.avaliar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.criar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'solicitacao_vaga.editar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('gestor', 'pdi.visualizar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'feedback.avaliar') === $P::PERMITIDA && PermissaoPerfilPolicy::classificar('gestor', 'colaboradores.visualizar') === $P::PERMITIDA, '(política) Gestor (Etapa 2C.2B): Colaboradores, Experiência, Desempenho, Feedback, PDI, Solicitação de Vaga e Kanban (só as operações protegidas); Integração, recrutamento e admin incompatíveis');
    $check(PermissaoPerfilPolicy::classificar('candidate', 'dashboard.visualizar') === $P::INCOMPATIVEL && PermissaoPerfilPolicy::classificar('', 'dashboard.visualizar') === $P::INCOMPATIVEL, '(política) perfil desconhecido: negação por padrão');
    $check(PermissaoPerfilPolicy::modeloRecomendado('viewer') === ['dashboard_recrutamento.visualizar'] && PermissaoPerfilPolicy::modeloRecomendado('admin') === [] && PermissaoPerfilPolicy::modeloRecomendado('rh') === [] && PermissaoPerfilPolicy::modeloRecomendado('gestor') === array_values(array_diff(PermissaoPerfilPolicy::permissoesPermitidas('gestor'), ['solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar'])), '(modelo) viewer recomenda só o dashboard sem risco de reidentificação; admin/RH não exigem linhas; Gestor (Etapa 3) recebe o operacional setorial, sem aprovação de líder nem dashboard agregado');
    $aud = PermissaoPerfilPolicy::auditoriaDashboards();
    $check((function () use ($aud, $dashboards) { $k = array_keys($aud); sort($k); $d = $dashboards; sort($d); return $k === $d; })() && count(array_filter($aud, static fn(array $a): bool => $a['recomendado'])) === 1 && $aud['dashboard_recrutamento.visualizar']['recomendado'] && $aud['dashboard.visualizar']['reidentificacao'] === 'alto', '(auditoria) os 5 dashboards estão classificados e só o de baixo risco é recomendado');
    $check(PermissaoPerfilPolicy::podeReceberPermissao('viewer', 'dashboard_turnover.visualizar') && PermissaoPerfilPolicy::podeReceberPermissao('viewer', 'pdi.gerenciar') && !PermissaoPerfilPolicy::podeReceberPermissao('viewer', 'candidaturas.editar') && !PermissaoPerfilPolicy::podeReceberPermissao('viewer', 'entrevista_desligamento.visualizar') && !PermissaoPerfilPolicy::podeReceberPermissao('viewer', 'entrevista_desligamento.visualizar', true), '(receber) incompatível nunca — nem como atribuição já gravada');
    $check(PermissaoPerfilPolicy::incompativeis('viewer', ['dashboard.visualizar', 'pipeline.movimentar', 'candidaturas.visualizar']) === ['pipeline.movimentar', 'candidaturas.visualizar'] && PermissaoPerfilPolicy::incompativeis('rh', ['pipeline.movimentar', 'candidaturas.visualizar']) === [], '(incompatíveis) lista exatamente o que o perfil não pode ter');
    $check(PermissaoPerfilPolicy::permissoesPermitidas('viewer') !== [] && !in_array('candidaturas.visualizar', PermissaoPerfilPolicy::permissoesPermitidas('viewer'), true) && PermissaoPerfilPolicy::permissoesPermitidas('candidate') === [], '(permitidas) viewer não inclui nominais; perfil desconhecido não recebe nada');

    // ---- 2. usuários ------------------------------------------------------------------------------------------------------
    $sg = $novo('sg', 'admin', 1);
    $adm = $novo('adm', 'admin');
    $rh = $novo('rh', 'rh');
    $vSem = $novo('vsem', 'viewer');
    $vDash = $novo('vdash', 'viewer');
    $vNom = $novo('vnom', 'viewer');
    $vFin = $novo('vfin', 'viewer');
    $vGer = $novo('vger', 'viewer');
    $vTrans = $novo('vtrans', 'viewer');
    $vFlag = $novo('vflag', 'viewer', 1);
    $conceder($vDash, $dashboards);
    $conceder($vNom, ['candidaturas.visualizar', 'candidaturas.editar', 'comunicacoes.visualizar', 'dashboard.visualizar']);
    $conceder($vFin, ['indicacoes.visualizar', 'indicacoes.gerenciar_pagamento']);
    $conceder($vGer, ['recruitment_webhooks.gerenciar', 'recruitment_webhooks.visualizar', 'empresas.editar', 'vagas.publicar', 'pipeline.movimentar']);
    $conceder($vTrans, ['pdi.visualizar', 'mensagens.criar', 'entrevista_desligamento.visualizar']);
    $conceder($vFlag, ['candidaturas.visualizar', 'dashboard.visualizar', 'pipeline.visualizar']);
    $conceder($rh, ['dashboard.visualizar']); // RH com linha irrelevante: o acesso vem do perfil
    $tem = static fn(int $u, string $c): bool => Authorization::usuarioTemPermissao($u, $c);

    // ---- 3. acesso efetivo ------------------------------------------------------------------------------------------------
    $check(array_reduce($dashboards, static fn(bool $ok, string $c) => $ok && $tem($vDash, $c), true), '(efetivo) viewer com os dashboards autorizados os tem');
    $check(!$tem($vSem, 'dashboard.visualizar') && !$tem($vSem, 'candidaturas.visualizar'), '(efetivo) viewer sem permissões não tem nada');
    $check(!$tem($vNom, 'candidaturas.visualizar') && !$tem($vNom, 'candidaturas.editar') && !$tem($vNom, 'comunicacoes.visualizar') && $tem($vNom, 'dashboard.visualizar'), '(efetivo) viewer com permissão NOMINAL legada: a nominal não concede nada; o dashboard gravado segue valendo');
    $check(!$tem($vFin, 'indicacoes.visualizar') && !$tem($vFin, 'indicacoes.gerenciar_pagamento'), '(efetivo) viewer com permissão FINANCEIRA legada: não concede nada');
    $check(array_reduce(['recruitment_webhooks.gerenciar', 'recruitment_webhooks.visualizar', 'empresas.editar', 'vagas.publicar', 'pipeline.movimentar'], static fn(bool $ok, string $c) => $ok && !$tem($vGer, $c), true), '(efetivo) viewer com permissões de GERENCIAMENTO/escrita legadas: nenhuma concede acesso');
    $check($tem($vTrans, 'pdi.visualizar') && !$tem($vTrans, 'mensagens.criar') && !$tem($vTrans, 'entrevista_desligamento.visualizar'), '(efetivo) transitória (pdi) segue efetiva; Mensagens e Entrevista de Desligamento legadas não concedem mais acesso');
    $check(!$tem($vFlag, 'candidaturas.visualizar') && !$tem($vFlag, 'pipeline.visualizar') && $tem($vFlag, 'dashboard.visualizar'), '(efetivo) viewer com is_supervisor=1: o flag não muda nada — só valem as permissões compatíveis');
    $check($tem($sg, 'candidaturas.visualizar') && $tem($adm, 'pipeline.movimentar') && $tem($rh, 'indicacoes.gerenciar_pagamento') && $tem($rh, 'recruitment_webhooks.gerenciar'), '(efetivo) Supervisor Geral, Administrador e RH mantêm o acesso global sem linhas em usuario_permissoes');
    $check(count($gravadas($vNom)) === 4 && count($gravadas($vGer)) === 5, '(legado) as permissões incompatíveis continuam GRAVADAS (nada foi apagado)');
    $efVNom = PermissaoPerfilPolicy::permissoesEfetivas(User::findById($vNom));
    $check($efVNom === ['dashboard.visualizar'], '(permissoesEfetivas) viewer: só o que é compatível e gravado');
    $check(in_array('candidaturas.visualizar', PermissaoPerfilPolicy::permissoesEfetivas(User::findById($rh)), true) && count(PermissaoPerfilPolicy::permissoesEfetivas(User::findById($adm))) === (int)$pdo->query('SELECT COUNT(*) FROM permissoes WHERE ativo = 1')->fetchColumn(), '(permissoesEfetivas) admin/RH: todo o catálogo ativo');
    $dg = PermissaoPerfilPolicy::diagnosticoLegado('viewer', $gravadas($vNom));
    $check($dg['incompativeis'] === ['candidaturas.editar', 'candidaturas.visualizar', 'comunicacoes.visualizar'] || count($dg['incompativeis']) === 3, '(diagnóstico) o legado incompatível é identificado para informar o administrador');

    // ---- 4. validação da seleção -----------------------------------------------------------------------------------------
    $pdo->prepare("INSERT INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES ('zz2b.inativa', 'zz2b', 'Inativa 2B', '', 9999, 0)")->execute();
    $idInativa = (int)$pdo->lastInsertId();
    $permTemp[] = $idInativa;
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['dashboard.visualizar'], 99999999], []);
    $check(!$v['ok'] && $v['inexistentes'] === [99999999] && str_contains($v['mensagem'], 'inexistente'), '(validação) permissão INEXISTENTE é recusada com mensagem');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['dashboard.visualizar'], $idInativa], []);
    $check(!$v['ok'] && $v['inativas'] === ['zz2b.inativa'] && str_contains($v['mensagem'], 'inativa'), '(validação) permissão INATIVA é recusada com mensagem');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['dashboard.visualizar'], $ids['candidaturas.visualizar']], []);
    $check(!$v['ok'] && $v['incompativeis'] === ['candidaturas.visualizar'] && str_contains($v['mensagem'], 'Incompatível com o perfil VIEWER'), '(validação) seleção INCOMPATÍVEL é recusada identificando o código');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['dashboard_recrutamento.visualizar'], $ids['pdi.visualizar']], []);
    $check($v['ok'] && $v['mensagem'] === '', '(validação) seleção compatível passa');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['entrevista_desligamento.visualizar']], []);
    $check(!$v['ok'], '(validação) NOVA atribuição de permissão pendente de decisão é recusada');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [$ids['entrevista_desligamento.visualizar']], [$ids['entrevista_desligamento.visualizar']]);
    $check(!$v['ok'] && $v['incompativeis'] === ['entrevista_desligamento.visualizar'], '(validação) mesmo já gravada, a permissão de Entrevista de Desligamento é incompatível (bloqueia a seleção final)');
    $v = PermissaoPerfilPolicy::validarSelecao('rh', [$ids['pipeline.movimentar'], $ids['candidaturas.editar']], []);
    $check($v['ok'], '(validação) perfil global aceita qualquer permissão existente e ativa');
    $v = PermissaoPerfilPolicy::validarSelecao('viewer', [], [$ids['candidaturas.visualizar']]);
    $check($v['ok'], '(validação) seleção vazia é válida (o administrador pode remover tudo)');

    // ---- 5. updatePermissoes por HTTP ------------------------------------------------------------------------------------
    $salvar = static fn(int $ator, string $role, bool $sess, int $alvo, array $idsPerm): array => $requisitar($ator, $role, $sess, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => $idsPerm, 'x' => '1'], [(string)$alvo]);
    $conceder($vNom, ['dashboard.visualizar', 'pdi.visualizar']);
    $antes = $gravadas($vNom);
    $salvar($adm, 'admin', false, $vNom, [$ids['dashboard.visualizar'], $ids['candidaturas.visualizar']]);
    $check($gravadas($vNom) === $antes, '(HTTP salvar) seleção incompatível: recusada, o que estava gravado fica intacto (sem alteração parcial)');
    $check((int)$pdo->query("SELECT COUNT(*) FROM auditoria_usuarios WHERE action = 'blocked_permissoes_update' AND target_usuario_id = {$vNom}")->fetchColumn() === 1, '(HTTP salvar) a recusa é auditada');
    $salvar($adm, 'admin', false, $vNom, [$ids['dashboard.visualizar'], 99999999]);
    $check($gravadas($vNom) === $antes, '(HTTP salvar) id inexistente: recusado, nada gravado');
    $salvar($adm, 'admin', false, $vNom, [$ids['dashboard.visualizar'], $idInativa]);
    $check($gravadas($vNom) === $antes, '(HTTP salvar) permissão inativa: recusada, nada gravado');
    $salvar($adm, 'admin', false, $vNom, [$ids['dashboard.visualizar'], $ids['dashboard_turnover.visualizar']]);
    $check($gravadas($vNom) === ['dashboard.visualizar', 'dashboard_turnover.visualizar'], '(HTTP salvar) seleção compatível: gravada');
    $conceder($vGer, ['candidaturas.visualizar', 'dashboard.visualizar']);
    $salvar($adm, 'admin', false, $vGer, [$ids['dashboard.visualizar'], $ids['dashboard_recrutamento.visualizar']]);
    $check($gravadas($vGer) === ['dashboard.visualizar', 'dashboard_recrutamento.visualizar'], '(HTTP salvar) o administrador regulariza o legado desmarcando a incompatível (nada é descartado em silêncio: ele fez a escolha)');
    $conceder($vGer, ['candidaturas.visualizar', 'dashboard.visualizar']);
    $salvar($adm, 'admin', false, $vGer, [$ids['dashboard.visualizar'], $ids['candidaturas.visualizar']]);
    $check($gravadas($vGer) === ['candidaturas.visualizar', 'dashboard.visualizar'], '(HTTP salvar) mesmo mantendo a legada intocada, a seleção final incompatível é recusada — e o legado preservado');
    $salvar($adm, 'admin', false, $rh, [$ids['pipeline.movimentar'], $ids['candidaturas.editar']]);
    $check($gravadas($rh) === ['candidaturas.editar', 'pipeline.movimentar'], '(HTTP salvar) RH (perfil global) aceita qualquer permissão ativa');
    $antesSg = $gravadas($sg);
    $salvar($adm, 'admin', false, $sg, [$ids['dashboard.visualizar']]);
    $check($gravadas($sg) === $antesSg, '(HTTP salvar) Autoridade prevalece: Administrador não altera permissões do Supervisor Geral');
    $antesRh = $gravadas($vNom);
    $salvar($rh, 'rh', false, $vNom, [$ids['dashboard_recrutamento.visualizar']]);
    $salvar($vFlag, 'viewer', true, $vNom, [$ids['dashboard_recrutamento.visualizar']]);
    $check($gravadas($vNom) === $antesRh, '(HTTP salvar) RH e viewer com is_supervisor=1 não salvam permissões (rota é de admin)');
    $conceder($vTrans, ['pdi.visualizar', 'entrevista_desligamento.visualizar']);
    $salvar($adm, 'admin', false, $vTrans, [$ids['pdi.visualizar'], $ids['entrevista_desligamento.visualizar'], $ids['dashboard.visualizar']]);
    $check($gravadas($vTrans) === ['entrevista_desligamento.visualizar', 'pdi.visualizar'], '(HTTP salvar) manter a legada incompatível na seleção final: recusado, gravado intacto');
    $salvar($adm, 'admin', false, $vTrans, [$ids['pdi.visualizar'], $ids['dashboard.visualizar']]);
    $check($gravadas($vTrans) === ['dashboard.visualizar', 'pdi.visualizar'], '(HTTP salvar) o administrador regulariza removendo a legada: gravado');

    // ---- 6. acesso direto por endpoints ----------------------------------------------------------------------------------
    $conceder($vGer, ['recruitment_webhooks.visualizar', 'recruitment_webhooks.gerenciar']);
    [$st, $corpo] = $requisitar($vGer, 'viewer', false, 'AdminIntegracoesController', 'index');
    $check($st === 403 && trim($corpo) === 'Acesso negado', '(endpoint) viewer com recruitment_webhooks.* legada: Integrações dá 403 (antes abria)');
    [$st] = $requisitar($rh, 'rh', false, 'AdminIntegracoesController', 'index');
    $check($st !== 403, '(endpoint) RH mantém o acesso a Integrações');
    $conceder($vDash, $dashboards);
    foreach ([['AdminController', 'index'], ['AdminRhIndicadoresController', 'index'], ['AdminDashboardTurnoverController', 'index'], ['AdminDashboardRecrutamentoController', 'index'], ['AdminDashboardEntrevistaDesligamentoController', 'index']] as [$ctrl, $m]) {
        [$st] = $requisitar($vDash, 'viewer', false, $ctrl, $m);
        $check($st === 200, "(endpoint) viewer com os dashboards autorizados abre {$ctrl}::{$m}");
        [$st, $corpo] = $requisitar($vSem, 'viewer', false, $ctrl, $m);
        $check($st === 403 && trim($corpo) === 'Acesso negado', "(endpoint) viewer sem permissão: {$ctrl}::{$m} dá 403");
        if ($ctrl !== 'AdminController') { // o viewer-flag do teste TEM dashboard.visualizar (legítima); as demais ele não tem
            [$st] = $requisitar($vFlag, 'viewer', true, $ctrl, $m);
            $check($st === 403, "(endpoint) viewer com is_supervisor=1 sem a permissão: {$ctrl}::{$m} dá 403");
        }
    }
    $conceder($vTrans, ['pdi.visualizar']);
    [$st] = $requisitar($vTrans, 'viewer', false, 'AdminPdisController', 'index');
    $check($st !== 403, '(dependência) viewer com pdi.visualizar (transição, escopo setorial) continua abrindo PDI — nada operacional foi retirado');
    $conceder($vNom, ['candidaturas.visualizar', 'comunicacoes.visualizar']);
    [$st] = $requisitar($vNom, 'viewer', false, 'AdminCandidaturasController', 'index');
    $check($st === 403, '(endpoint) viewer com candidaturas.visualizar legada: Candidaturas segue 403 (Etapa 2A + 2B)');

    // ---- 7. regressão: ENUM de roles e catálogo intactos -----------------------------------------------------------------
    $col = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'role'")->fetchColumn();
    $check(str_contains((string)$col, "'admin'") && str_contains((string)$col, "'rh'") && str_contains((string)$col, "'viewer'") && (str_contains((string)$col, 'gestor') === Autoridade::schemaSuportaGestor()), '(estrutura) os perfis admin/rh/viewer seguem no ENUM; gestor só onde a migration 2026-11-22 foi aplicada');
} finally {
    if ($permTemp !== []) {
        $pdo->exec('DELETE FROM permissoes WHERE id IN (' . implode(',', array_map('intval', $permTemp)) . ')');
    }
    if ($criados !== []) {
        $pdo->exec('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados)) . ')');
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — política central de permissões por perfil.\n";
