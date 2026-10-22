<?php

/**
 * Integração — Configurações → Integrações: Regras de comunicação (Etapa 3, 2026-10 — migration
 * 2026-10-22-integracao-regras-comunicacao.sql). Associa evento/etapa a um modelo JÁ existente em
 * `mensagens` (nunca duplica texto/variáveis/canal de template — isso continua só em Mensagem).
 * Fixtures ZZREG-* com limpeza em `finally`. Prova, contra o banco:
 *   - a nova seção aparece na página de Integrações, em modo leitura/operação conforme o perfil
 *     (mesmo gate `recruitment_webhooks.visualizar`/admin-rh da página, nenhuma permissão nova);
 *   - criar/editar/(in)ativar uma regra funciona via `IntegracaoRegraComunicacaoService::salvar()`
 *     (a mesma camada usada pelo controller — os testes NÃO chamam
 *     `criarRegraComunicacao()`/`atualizarRegraComunicacao()` diretamente porque esses métodos
 *     terminam em `redirect()`, que encerra o processo PHP — mesmo padrão já usado para
 *     Mensagem::create()/update() em `integration_mensagens.php`);
 *   - validações: evento inválido, etapa inexistente, mensagem inexistente/inativa, canal
 *     inválido, duplicidade de evento+etapa+canal (criação E edição);
 *   - só role admin/rh grava uma regra (viewer com `recruitment_webhooks.visualizar` só visualiza);
 *   - o `<select>` de mensagem do formulário só lista mensagens ATIVAS;
 *   - a regra persiste após reload (nova instância do repository);
 *   - a FK RESTRICT bloqueia excluir uma mensagem/etapa referenciada por uma regra;
 *   - a página de Integrações (conexão/fila/histórico) continua funcionando sem regressão.
 *
 * Itens 15/16/17 do pedido da Etapa 3 (webhook/payload/Kanban inalterados) são cobertos pelos
 * testes já existentes e NÃO tocados nesta etapa — `integration_recruitment_webhooks.php` e
 * `integration_portal_configuracoes_integracoes.php` — reexecutados junto com este.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

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
$sessaoOriginal = $_SESSION;
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'mensagens' => [], 'regras' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$corpoDe = static function (string $classe, string $metodo): string {
    $r = new ReflectionMethod($classe, $metodo);
    $linhas = file($r->getFileName());
    return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};

$permId = [];
$stmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
foreach (['recruitment_webhooks.visualizar'] as $c) {
    $stmt->execute([$c]);
    $permId[$c] = (int)$stmt->fetchColumn();
}
$novoUsuario = static function (string $rotulo, string $role, array $permissoes = []) use ($pdo, &$criados, $senha, $suffix, $permId): array {
    $id = User::create('ZZREG ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzreg.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    if ($permissoes !== []) {
        Authorization::sincronizar($id, array_map(static fn(string $p): int => $permId[$p], $permissoes));
    }
    return ['id' => $id, 'role' => $role];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZREG Teste';
    $_SESSION['user_is_supervisor'] = 0;
};

$regraRepository = new IntegracaoRegraComunicacaoRepository($pdo);
$regraService = new IntegracaoRegraComunicacaoService($regraRepository);
$eventos = IntegracaoRegraComunicacaoService::eventosDisponiveis();

try {
    $admin = $novoUsuario('Admin', 'admin');
    $rh = $novoUsuario('RH', 'rh');
    $soWeb = $novoUsuario('So Integracoes', 'viewer', ['recruitment_webhooks.visualizar']);

    // ---- fixtures: mensagens de teste (ativa e inativa) --------------------------------------------
    $resAtiva = Mensagem::create([
        'codigo' => 'zzreg_ativa_' . $suffix,
        'titulo' => 'ZZREG Convocação de teste',
        'conteudo' => 'Olá, isto é um teste.',
        'ativo' => 1,
    ]);
    $check($resAtiva['ok'] ?? false, 'fixture: mensagem ativa criada');
    $mensagemAtivaId = (int)($resAtiva['id'] ?? 0);
    $criados['mensagens'][] = $mensagemAtivaId;

    $resInativa = Mensagem::create([
        'codigo' => 'zzreg_inativa_' . $suffix,
        'titulo' => 'ZZREG Mensagem inativa',
        'conteudo' => 'Rascunho.',
        'ativo' => 0,
    ]);
    $check($resInativa['ok'] ?? false, 'fixture: mensagem inativa criada');
    $mensagemInativaId = (int)($resInativa['id'] ?? 0);
    $criados['mensagens'][] = $mensagemInativaId;

    // ---- (2) etapas reais do pipeline — nunca hardcoded sem confirmar no banco ----------------------
    $etapas = PipelineStage::all();
    $entrevistaRh = null;
    foreach ($etapas as $etapa) {
        if (($etapa['slug'] ?? '') === 'entrevista-rh') {
            $entrevistaRh = $etapa;
            break;
        }
    }
    $check($entrevistaRh !== null, '(2) Etapa "entrevista-rh" existe no banco (PipelineStage::all())');
    $entrevistaRhId = (int)$entrevistaRh['id'];

    $eventoComEtapa = RecruitmentEventDispatcher::EVENT_CANDIDATE_STAGE_CHANGED;
    $eventoSemEtapa = RecruitmentEventDispatcher::EVENT_CANDIDATURA_CRIADA;
    $check(isset($eventos[$eventoComEtapa]) && $eventos[$eventoComEtapa]['exige_etapa'] === true, '(3) Catálogo: evento de movimentação de etapa exige etapa');
    $check(isset($eventos[$eventoSemEtapa]) && $eventos[$eventoSemEtapa]['exige_etapa'] === false, '(11) Catálogo: candidatura criada nunca exige etapa');

    // ---- (3) criação de regra (via service — mesma camada do controller) ---------------------------
    $resultadoCriar = $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check($resultadoCriar['ok'] ?? false, '(3) Criação de regra válida (etapa_alterada + entrevista-rh + mensagem ativa) é aceita');
    $regraId = (int)($resultadoCriar['id'] ?? 0);
    if ($regraId > 0) {
        $criados['regras'][] = $regraId;
    }

    $linha = $regraRepository->find($regraId);
    $check(
        $linha !== null
        && $linha['event_type'] === $eventoComEtapa
        && (int)$linha['pipeline_stage_id'] === $entrevistaRhId
        && (int)$linha['mensagem_id'] === $mensagemAtivaId
        && $linha['canal'] === 'whatsapp'
        && (int)$linha['ativo'] === 1,
        '(3) Linha gravada com os campos corretos (nenhum texto/variável de mensagem duplicado na tabela)'
    );

    // ---- (6) validação: mensagem inexistente -------------------------------------------------------
    $resMensagemInexistente = $regraService->salvar([
        'event_type' => $eventoSemEtapa,
        'mensagem_id' => '999999999',
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check(!($resMensagemInexistente['ok'] ?? true) && ($resMensagemInexistente['error'] ?? '') !== '', '(6) Mensagem inexistente é rejeitada com erro');

    // ---- validação: mensagem inativa ----------------------------------------------------------------
    $resMensagemInativa = $regraService->salvar([
        'event_type' => $eventoSemEtapa,
        'mensagem_id' => (string)$mensagemInativaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check(!($resMensagemInativa['ok'] ?? true), '(6) Mensagem inativa é rejeitada (não pode ser vinculada a uma regra)');

    // ---- (7) validação: etapa inexistente ------------------------------------------------------------
    $resEtapaInexistente = $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => '999999999',
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check(!($resEtapaInexistente['ok'] ?? true), '(7) Etapa inexistente é rejeitada com erro');

    // ---- evento inválido / canal inválido / etapa exigida ausente ------------------------------------
    $check(!($regraService->salvar(['event_type' => 'evento.inventado.zz', 'mensagem_id' => (string)$mensagemAtivaId, 'canal' => 'whatsapp'])['ok'] ?? true), 'Evento fora do catálogo é rejeitado');
    $check(!($regraService->salvar(['event_type' => $eventoComEtapa, 'mensagem_id' => (string)$mensagemAtivaId, 'canal' => 'whatsapp'])['ok'] ?? true), 'Evento que exige etapa sem etapa informada é rejeitado');
    $check(!($regraService->salvar(['event_type' => $eventoSemEtapa, 'mensagem_id' => (string)$mensagemAtivaId, 'canal' => 'sms'])['ok'] ?? true), 'Canal fora do conjunto controlado é rejeitado');

    // ---- (8) duplicidade: mesma combinação evento+etapa+canal ---------------------------------------
    $resDuplicada = $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check(!($resDuplicada['ok'] ?? true), '(8) Segunda regra para a MESMA combinação evento+etapa+canal é rejeitada (duplicidade)');
    $check(!$regraRepository->existeConflito($eventoComEtapa, $entrevistaRhId, 'whatsapp', $regraId), '(8) Sem conflito quando o próprio id é excluído da checagem (permite editar a si mesma)');

    // ---- (4) edição: altera para o evento sem etapa, outra mensagem ---------------------------------
    $resultadoEditar = $regraService->salvar([
        'event_type' => $eventoSemEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId, // deve ser ignorado: evento sem etapa nunca persiste etapa
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ], $regraId);
    $check($resultadoEditar['ok'] ?? false, '(4) Edição de regra existente é aceita');
    $linhaEditada = $regraRepository->find($regraId);
    $check($linhaEditada['event_type'] === $eventoSemEtapa && $linhaEditada['pipeline_stage_id'] === null, '(11) Evento sem etapa nunca persiste uma etapa artificial, mesmo que o formulário envie uma');

    // ---- (8) duplicidade também na edição: criar uma 2ª regra e tentar colidir com a 1ª -------------
    $resSegunda = $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ]);
    $check($resSegunda['ok'] ?? false, 'fixture: segunda regra (combinação diferente) criada para o teste de colisão na edição');
    $regraId2 = (int)($resSegunda['id'] ?? 0);
    if ($regraId2 > 0) {
        $criados['regras'][] = $regraId2;
    }
    $resColisaoEdicao = $regraService->salvar([
        'event_type' => $eventoSemEtapa,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ], $regraId2);
    $check(!($resColisaoEdicao['ok'] ?? true), '(8) Editar a 2ª regra para colidir com a 1ª (agora em evento sem etapa) é rejeitado');

    // ---- (5) ativação/inativação -------------------------------------------------------------------
    $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        // 'ativo' ausente = desmarcado no formulário
    ], $regraId2);
    $check((int)$regraRepository->find($regraId2)['ativo'] === 0, '(5) Edição sem marcar "ativo" inativa a regra (mesma linha, sem duplicar)');
    $regraService->salvar([
        'event_type' => $eventoComEtapa,
        'pipeline_stage_id' => (string)$entrevistaRhId,
        'mensagem_id' => (string)$mensagemAtivaId,
        'canal' => 'whatsapp',
        'ativo' => '1',
    ], $regraId2);
    $check((int)$regraRepository->find($regraId2)['ativo'] === 1, '(5) Reativar a mesma regra funciona');

    // ---- (12) a regra permanece após reload (nova instância do repository) -------------------------
    $repositoryNovo = new IntegracaoRegraComunicacaoRepository($pdo);
    $linhaReload = $repositoryNovo->find($regraId2);
    $check($linhaReload !== null && (int)$linhaReload['mensagem_id'] === $mensagemAtivaId, '(12) A regra persiste após reload (nova instância do repository, nova consulta)');

    // ---- (9)/(10) somente admin/rh grava; viewer com permissão de visualizar só visualiza -----------
    foreach (['novaRegraComunicacao', 'criarRegraComunicacao', 'editarRegraComunicacao', 'atualizarRegraComunicacao'] as $metodo) {
        $check(
            str_contains($corpoDe(AdminIntegracoesController::class, $metodo), "Auth::requireRole(['admin', 'rh'])"),
            "(9)/(10) {$metodo}() exige role admin/rh — viewer com recruitment_webhooks.visualizar (sem a role) é bloqueado; admin/rh continuam operando"
        );
    }
    foreach (['criarRegraComunicacao', 'atualizarRegraComunicacao'] as $metodoPost) {
        $check(str_contains($corpoDe(AdminIntegracoesController::class, $metodoPost), 'Security::csrfCheck'), "(18) {$metodoPost}() segue o padrão de CSRF do Portal");
    }

    // ---- (1) acesso à nova seção + (2) listagem + modo leitura vs. operação ------------------------
    $comoUsuario($rh);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlRh = $renderizar(static fn() => (new AdminIntegracoesController())->index());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlRh)
        && str_contains($htmlRh, 'Regras de comunicação')
        && str_contains($htmlRh, 'regras/nova')
        && str_contains($htmlRh, 'ZZREG Convocação de teste')
        && str_contains($htmlRh, 'Entrevista RH')
        && str_contains($htmlRh, 'regras/' . $regraId2 . '/editar'),
        '(1)/(2) RH: seção "Regras de comunicação" aparece, com botão "Nova regra", listando a regra criada (mensagem + etapa) e link de editar'
    );

    $comoUsuario($soWeb);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlLeitura = $renderizar(static fn() => (new AdminIntegracoesController())->index());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlLeitura)
        && str_contains($htmlLeitura, 'Regras de comunicação')
        && str_contains($htmlLeitura, 'ZZREG Convocação de teste')
        && !str_contains($htmlLeitura, 'regras/nova')
        && !str_contains($htmlLeitura, '/editar"'),
        '(1) viewer com recruitment_webhooks.visualizar: vê a seção e a listagem, mas sem "Nova regra" nem link de editar (somente leitura)'
    );

    // ---- (11) select de mensagem só lista ativas -----------------------------------------------------
    $comoUsuario($rh);
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlForm = $renderizar(static fn() => (new AdminIntegracoesController())->novaRegraComunicacao());
    $check(
        !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlForm)
        && str_contains($htmlForm, 'ZZREG Convocação de teste')
        && !str_contains($htmlForm, 'ZZREG Mensagem inativa'),
        '(9)/(11) Formulário de nova regra: <select> de mensagem só lista a mensagem ATIVA, nunca a inativa'
    );

    // ---- (14) página de Integrações (conexão/fila/histórico) continua funcionando ------------------
    $check(
        str_contains($htmlRh, 'n8n / Webhooks')
        && str_contains($htmlRh, 'Situação da fila')
        && str_contains($htmlRh, 'Histórico recente')
        && str_contains($htmlRh, 'data-app-shell-v2="1"'),
        '(14) A página de Integrações continua mostrando Conexão, Regras de comunicação e Fila/Histórico, sem regressão'
    );
    $_GET = [];

    // ---- (13) FK/integridade referencial: RESTRICT bloqueia excluir mensagem/etapa referenciadas ----
    $bloqueouMensagem = false;
    try {
        $pdo->prepare('DELETE FROM mensagens WHERE id = ?')->execute([$mensagemAtivaId]);
    } catch (PDOException $e) {
        $bloqueouMensagem = true;
    }
    $check($bloqueouMensagem && Mensagem::find($mensagemAtivaId) !== null, '(13) FK RESTRICT impede excluir uma mensagem referenciada por uma regra ativa');

    $bloqueouEtapa = false;
    try {
        $pdo->prepare('DELETE FROM pipeline_stages WHERE id = ?')->execute([$entrevistaRhId]);
    } catch (PDOException $e) {
        $bloqueouEtapa = true;
    }
    $check($bloqueouEtapa && PipelineStage::find($entrevistaRhId) !== null, '(13) FK RESTRICT impede excluir uma etapa referenciada por uma regra');

    echo "\nINTEGRATION_INTEGRACAO_REGRAS_COMUNICACAO_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    foreach ($criados['regras'] as $rid) {
        $pdo->prepare('DELETE FROM integracao_regras_comunicacao WHERE id = ?')->execute([$rid]);
    }
    foreach ($criados['mensagens'] as $mid) {
        $pdo->prepare('DELETE FROM mensagens WHERE id = ?')->execute([$mid]);
    }
    if ($criados['usuarios'] !== []) {
        $lista = implode(',', array_map('intval', $criados['usuarios']));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($lista) OR actor_usuario_id IN ($lista)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lista)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nINTEGRATION_INTEGRACAO_REGRAS_COMUNICACAO_OK\n";
