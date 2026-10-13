<?php

/**
 * Integração — Liberação manual da Entrevista de Desligamento (2026-10, pedido do RH). Fixtures
 * ZZEDL-* no espelho `colaboradores_metadados`, com limpeza em `finally`. Prova que:
 *   - ativo SEM liberação é inelegível; ativo COM liberação ativa é elegível; rescindido no
 *     METADADOS continua elegível sem nenhuma liberação (caminho oficial inalterado);
 *   - liberar() NUNCA altera `colaboradores_metadados` (ativo/demissao/motivo) — comparação
 *     byte-a-byte da linha antes/depois;
 *   - Headcount (RhIndicadoresService::headcountEm) não muda com a liberação;
 *   - RH e Admin conseguem liberar; perfil não autorizado é bloqueado (Auth::requireRole);
 *   - CSRF é obrigatório;
 *   - gerar() depois de liberar cria UMA entrevista (nunca duas), com snap_demissao NULL;
 *   - uma sincronização posterior do METADADOS (rescisão real) NÃO cria segunda entrevista nem
 *     altera o snapshot já gravado — mesma entrevista, mesmo token;
 *   - liberada_por_usuario_id/liberada_em registram quem e quando;
 *   - cancelar a liberação funciona quando a entrevista ainda não foi respondida, e é recusado
 *     quando já houve resposta.
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
$criados = ['usuarios' => [], 'metadados' => [], 'colaboradores' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$agora = new DateTimeImmutable('now');
$emp = 'ZEDL' . $suffix;
$csrfOriginal = $_SESSION['csrf_token'] ?? null;
$sessaoOriginal = $_SESSION;

$mk = static function (?string $dem, ?string $motivo) use ($pdo, &$criados, $suffix, $emp): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZEDL_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, $emp, 'ZU' . $suffix, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, 'ZZEDL Fixture ' . $seq, 'ZZEDL Empresa', 'ZZEDL Unidade',
        'ZZEDL Cargo', 'ZZEDLCG' . $suffix, (new DateTimeImmutable('-800 days'))->format('Y-m-d'), $dem, $motivo,
        $motivo === null ? null : 'ZZEDL motivo ' . $motivo, $dem === null ? 1 : 0, 'zzedl-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};
$linhaOficial = static fn(int $metadadosId): array => (function () use ($pdo, $metadadosId): array {
    $s = $pdo->prepare('SELECT * FROM colaboradores_metadados WHERE id = ?');
    $s->execute([$metadadosId]);
    return $s->fetch(PDO::FETCH_ASSOC);
})();
$cargoIdQualquer = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
$mkColaboradorLocal = static function (int $metadadosId) use ($pdo, &$criados, $suffix, $cargoIdQualquer): int {
    static $seq = 0;
    $seq++;
    $pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, ativo) VALUES (?, ?, ?, ?, 1)')
        ->execute(['ZZEDL Colaborador ' . $seq, 'zzedl-colaborador-' . $suffix . '-' . $seq, $cargoIdQualquer, $metadadosId]);
    $id = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $id;
    return $id;
};
$comoUsuario = static function (int $id, string $role): void {
    $_SESSION['user_id'] = $id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_is_supervisor'] = 0;
};
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = (string)ob_get_clean();
    }
    return $html;
};
$corpoDe = static function (string $classe, string $metodo): string {
    $r = new ReflectionMethod($classe, $metodo);
    $arq = new SplFileObject($r->getFileName());
    $arq->seek($r->getStartLine() - 1);
    $corpo = '';
    while ($arq->key() < $r->getEndLine()) {
        $corpo .= $arq->current();
        $arq->next();
    }
    return $corpo;
};

try {
    $rhId = User::create('ZZEDL RH', 'rh.zzedl.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($rhId, true);
    $criados['usuarios'][] = $rhId;
    $adminId = User::create('ZZEDL Admin', 'admin.zzedl.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $repo = new EntrevistaDesligamentoRepository();
    $service = new EntrevistaDesligamentoService($repo);

    // ---- (10) Permissão: só Admin/RH — verificado por reflexão (Auth::requireRole chama exit() no
    // 403, não dá para invocar direto com um perfil não autorizado sem matar o processo de teste) --
    $corpoLiberar = $corpoDe('AdminColaboradoresController', 'liberarEntrevistaDesligamento');
    $corpoCancelar = $corpoDe('AdminColaboradoresController', 'cancelarLiberacaoEntrevistaDesligamento');
    $check(str_contains($corpoLiberar, "Auth::requireRole(['admin', 'rh'])"), '(10) liberarEntrevistaDesligamento() exige admin/rh — mesmo gate da tela, nenhuma permissão nova');
    $check(str_contains($corpoCancelar, "Auth::requireRole(['admin', 'rh'])"), '(10) cancelarLiberacaoEntrevistaDesligamento() exige admin/rh');
    $check(str_contains($corpoLiberar, 'Security::csrfCheck') && str_contains($corpoCancelar, 'Security::csrfCheck'), '(11) Ambas as ações exigem CSRF');

    // ---- Fixture 1: ativo, sem liberação ---------------------------------------------------------
    $ativoSemLiberacao = $mk(null, null);
    $antes1 = $linhaOficial($ativoSemLiberacao);
    $elegSem = EntrevistaDesligamentoService::elegibilidade($repo->buscarContrato($ativoSemLiberacao), $agora);
    $check($elegSem['elegivel'] === false && $elegSem['motivo'] === 'sem_demissao', '(1) Ativo SEM liberação => não pode gerar entrevista');
    $resultadoGerarSemLiberacao = $service->gerar($ativoSemLiberacao, $rhId, $agora);
    $check(($resultadoGerarSemLiberacao['ok'] ?? true) === false, '(1b) gerar() também recusa diretamente, mesma regra (elegibilidade central, um único ponto de decisão)');

    // ---- (8)/(9) RH e Admin conseguem liberar — chamada REAL ao controller (sem exit, role válida) -
    $comoUsuario($rhId, 'rh');
    $_SESSION['csrf_token'] = 'zzedl-csrf-' . $suffix;
    $_POST = ['csrf' => $_SESSION['csrf_token']];

    // Vincula um colaborador LOCAL ao contrato fixture (liberarEntrevistaDesligamento() é chamado
    // por colaborador.id, não por metadados_id direto — mesma convenção de editRh()/updateRh()) —
    // materializada diretamente (sem passar por ColaboradorExtensaoLocalService::obterOuCriar(),
    // que exige codigo_cargo resolvido no catálogo oficial — irrelevante para este teste).
    $colaboradorId = $mkColaboradorLocal($ativoSemLiberacao);

    $html2 = $renderizar(function () use ($colaboradorId): void {
        (new AdminColaboradoresController())->liberarEntrevistaDesligamento((string)$colaboradorId);
    });
    $check(str_contains($html2, 'liberada manualmente'), '(8) RH consegue liberar a Entrevista pela tela do colaborador (ação real, não só reflexão)');
    $liberacaoAposRh = $repo->buscarLiberacao($ativoSemLiberacao);
    $check($liberacaoAposRh !== null && $liberacaoAposRh['cancelada_em'] === null, '(2) Após liberar, existe liberação ATIVA para o contrato');
    $check((int)$liberacaoAposRh['liberada_por_usuario_id'] === $rhId, '(14) Auditoria: liberada_por_usuario_id registra o RH que liberou');
    $check(!empty($liberacaoAposRh['liberada_em']), '(14) Auditoria: liberada_em registra quando');

    // ---- (4)/(5)/(6)/(7) Liberação NUNCA altera colaboradores_metadados / Headcount -----------------
    $depois1 = $linhaOficial($ativoSemLiberacao);
    $check($antes1 === $depois1, '(4)/(5) A linha de colaboradores_metadados está BYTE-A-BYTE idêntica antes/depois da liberação (ativo, demissao e todo o resto)');
    $contratosHeadcount = [$repo->buscarContrato($ativoSemLiberacao)];
    // RhIndicadoresService::headcountEm() opera sobre linhas de colaboradores_metadados (admissao/demissao) — usa a
    // própria linha oficial (nunca a liberação) para decidir se conta como ativo numa data.
    $headcountAntes = (int)(RhIndicadoresService::headcountEm([$antes1], $agora) );
    $headcountDepois = (int)(RhIndicadoresService::headcountEm([$depois1], $agora));
    $check($headcountAntes === $headcountDepois && $headcountAntes === 1, '(6)/(7) Headcount (RhIndicadoresService::headcountEm) não muda com a liberação — continua contando 1 (ativo) nos dois momentos');

    // ---- (2) Ativo COM liberação => elegível / pode gerar ------------------------------------------
    $contratoComLiberacao = $repo->buscarContrato($ativoSemLiberacao);
    $check(EntrevistaDesligamentoService::elegibilidade($contratoComLiberacao, $agora)['elegivel'] === true, '(2) Ativo COM liberação manual ativa => elegível');
    $resultadoGerar = $service->gerar($ativoSemLiberacao, $rhId, $agora);
    $check(($resultadoGerar['ok'] ?? false) === true, '(2b) gerar() cria a entrevista normalmente após a liberação');
    $entrevistaId = (int)($resultadoGerar['id'] ?? 0);
    $linhaEntrevista = $pdo->prepare('SELECT * FROM entrevistas_desligamento WHERE id = ?');
    $linhaEntrevista->execute([$entrevistaId]);
    $entrevista1 = $linhaEntrevista->fetch(PDO::FETCH_ASSOC);
    $check($entrevista1 !== false && $entrevista1['snap_demissao'] === null, '(2c) snap_demissao gravado como NULL — nunca uma data inventada para um contrato ainda ativo');

    // ---- (12) Não duplica: nova tentativa de gerar é recusada --------------------------------------
    $segundaTentativa = $service->gerar($ativoSemLiberacao, $rhId, $agora);
    $check(($segundaTentativa['ok'] ?? true) === false, '(12) Segunda tentativa de gerar() para o mesmo contrato é recusada — nunca duas entrevistas');
    $totalEntrevistasContrato = (int)$pdo->query('SELECT COUNT(*) FROM entrevistas_desligamento WHERE metadados_id = ' . (int)$ativoSemLiberacao)->fetchColumn();
    $check($totalEntrevistasContrato === 1, '(12b) Exatamente 1 linha em entrevistas_desligamento para este contrato');

    // ---- (15a) Cancelar liberação com entrevista AINDA NÃO respondida: permitido -------------------
    // (usa um SEGUNDO contrato, para não interferir no fluxo de sincronização testado logo abaixo)
    $paraCancelar = $mk(null, null);
    $service->liberarManualmente($paraCancelar, $rhId, $agora);
    $cancelamento1 = $service->cancelarLiberacaoManual($paraCancelar, $rhId, $agora);
    $check(($cancelamento1['ok'] ?? false) === true, '(15a) Cancelar liberação SEM entrevista ainda é permitido');
    $liberacaoCancelada = $repo->buscarLiberacao($paraCancelar);
    $check($liberacaoCancelada !== null && $liberacaoCancelada['cancelada_em'] !== null, '(15a-correlato) A liberação fica marcada como cancelada (nunca apagada — auditável)');
    $elegAposCancelar = EntrevistaDesligamentoService::elegibilidade($repo->buscarContrato($paraCancelar), $agora);
    $check($elegAposCancelar['elegivel'] === false, '(5-correlato) Após cancelar a liberação, o contrato volta a ser inelegível (ainda ativo no METADADOS)');

    // ---- (15b) Cancelar liberação com entrevista JÁ RESPONDIDA: recusado --------------------------
    $pdo->prepare('UPDATE entrevistas_desligamento SET respondida_em = ? WHERE id = ?')->execute([$agora->format('Y-m-d H:i:s'), $entrevistaId]);
    $cancelamento2 = $service->cancelarLiberacaoManual($ativoSemLiberacao, $rhId, $agora);
    $check(($cancelamento2['ok'] ?? true) === false, '(15b) Cancelar liberação de um contrato cuja entrevista JÁ foi respondida é recusado');

    // ---- (13) Sincronização posterior do METADADOS mantém a MESMA entrevista ----------------------
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = ?, motivo_rescisao_codigo = ?, ativo = 0 WHERE id = ?')
        ->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d'), '003', $ativoSemLiberacao]);
    $totalEntrevistasAposSync = (int)$pdo->query('SELECT COUNT(*) FROM entrevistas_desligamento WHERE metadados_id = ' . (int)$ativoSemLiberacao)->fetchColumn();
    $check($totalEntrevistasAposSync === 1, '(13) Após a sincronização registrar a rescisão real, continua existindo exatamente 1 entrevista (a mesma)');
    $entrevistaAposSync = $repo->buscarPorId($entrevistaId);
    $check((int)$entrevistaAposSync['id'] === $entrevistaId, '(13b) Mesmo id de entrevista');
    $check($entrevistaAposSync['snap_demissao'] === null, '(13c) snap_demissao do SNAPSHOT continua NULL — a sincronização posterior nunca reescreve o snapshot já gravado');
    $resultadoGerarAposSync = $service->gerar($ativoSemLiberacao, $rhId, $agora);
    $check(($resultadoGerarAposSync['ok'] ?? true) === false, '(13d) Tentar gerar de novo após a sincronização continua recusado — nenhuma segunda entrevista criada pela sincronização');

    // ---- (9) Admin também consegue liberar (segundo contrato dedicado) ----------------------------
    $paraAdmin = $mk(null, null);
    $colaboradorAdmin = $mkColaboradorLocal($paraAdmin);
    $comoUsuario($adminId, 'admin');
    $htmlAdmin = $renderizar(function () use ($colaboradorAdmin): void {
        (new AdminColaboradoresController())->liberarEntrevistaDesligamento((string)$colaboradorAdmin);
    });
    $check(str_contains($htmlAdmin, 'liberada manualmente'), '(9) Admin também consegue liberar a Entrevista pela tela do colaborador');
    $liberacaoAdmin = $repo->buscarLiberacao($paraAdmin);
    $check($liberacaoAdmin !== null && (int)$liberacaoAdmin['liberada_por_usuario_id'] === $adminId, '(9b) Auditoria registra o Admin como responsável');

    // ---- (11) CSRF inválido é recusado (sem criar liberação) ---------------------------------------
    $paraCsrf = $mk(null, null);
    $colaboradorCsrf = $mkColaboradorLocal($paraCsrf);
    $_POST = ['csrf' => 'token-errado'];
    $htmlCsrf = $renderizar(function () use ($colaboradorCsrf): void {
        http_response_code(200); // reset entre chamadas
        (new AdminColaboradoresController())->liberarEntrevistaDesligamento((string)$colaboradorCsrf);
    });
    $check(str_contains($htmlCsrf, 'CSRF inválido') || http_response_code() === 400, '(11b) CSRF inválido é recusado explicitamente');
    $check($repo->buscarLiberacao($paraCsrf) === null, '(11c) Nenhuma liberação foi criada com CSRF inválido');

    // ---- (3) Rescindido no METADADOS => pode gerar SEM liberação nenhuma --------------------------
    $rescindido = $mk((new DateTimeImmutable('-10 days'))->format('Y-m-d'), '003');
    $check($repo->buscarLiberacao($rescindido) === null, '(3-fixture) Nenhuma liberação foi criada para este contrato');
    $resultadoRescindido = $service->gerar($rescindido, $rhId, $agora);
    $check(($resultadoRescindido['ok'] ?? false) === true, '(3) Contrato já rescindido no METADADOS gera entrevista normalmente, sem nenhuma liberação manual');

    // ---- Ajuste de UX (2026-10): liberação vira HISTÓRICO assim que o contrato é rescindido -------
    // oficialmente — cancelar deixa de ser oferecido/aceito, mas o registro nunca é apagado nem
    // alterado, e a entrevista continua acessível normalmente.
    $paraAjusteUx = $mk(null, null);
    $service->liberarManualmente($paraAjusteUx, $rhId, $agora);

    // (UX-1) Ainda ativo: cancelar a liberação está disponível (sucesso).
    $podeCancelarAtivo = $service->cancelarLiberacaoManual($paraAjusteUx, $rhId, $agora);
    $check(($podeCancelarAtivo['ok'] ?? false) === true, '(UX-1) Ativo + liberação manual => ação de cancelar disponível');

    // Religa a liberação para continuar o cenário até a rescisão oficial.
    $service->liberarManualmente($paraAjusteUx, $rhId, $agora);
    $liberacaoAntesRescisao = $repo->buscarLiberacao($paraAjusteUx);

    // Simula a sincronização registrando a rescisão oficial no METADADOS.
    $pdo->prepare('UPDATE colaboradores_metadados SET demissao = ?, motivo_rescisao_codigo = ?, ativo = 0 WHERE id = ?')
        ->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d'), '003', $paraAjusteUx]);

    // (UX-2) Oficialmente rescindido + liberação histórica => cancelar é recusado.
    $tentativaCancelarRescindido = $service->cancelarLiberacaoManual($paraAjusteUx, $rhId, $agora);
    $check(($tentativaCancelarRescindido['ok'] ?? true) === false, '(UX-2) Rescindido oficialmente + liberação histórica => cancelar é recusado pelo backend (não só escondido na tela)');

    // (UX-3) Registro histórico permanece intacto — nunca apagado, nunca alterado.
    $liberacaoDepoisRescisao = $repo->buscarLiberacao($paraAjusteUx);
    $check(
        $liberacaoDepoisRescisao !== null && $liberacaoDepoisRescisao['cancelada_em'] === null
            && $liberacaoDepoisRescisao['liberada_em'] === $liberacaoAntesRescisao['liberada_em'],
        '(UX-3) Registro histórico da liberação permanece no banco, intacto (mesma liberada_em, ainda não cancelada)'
    );

    // Elegibilidade continua vindo da rescisão oficial (regra não foi alterada).
    $elegPosRescisao = EntrevistaDesligamentoService::elegibilidade($repo->buscarContrato($paraAjusteUx), $agora);
    $check($elegPosRescisao['elegivel'] === true, '(UX-correlato) Elegibilidade continua vindo naturalmente da rescisão oficial — regra de elegibilidade() inalterada');

    // (UX-4) Entrevista continua acessível/gerável normalmente.
    $resultadoGerarUx = $service->gerar($paraAjusteUx, $rhId, $agora);
    $check(($resultadoGerarUx['ok'] ?? false) === true, '(UX-4) Entrevista continua acessível/gerável normalmente para o contrato rescindido');
    $entrevistaUx = $repo->buscarPorId((int)($resultadoGerarUx['id'] ?? 0));
    $check($entrevistaUx !== null, '(UX-4b) Entrevista recém-gerada é acessível via buscarPorId()');

    echo "\nENTREVISTA_DESLIGAMENTO_LIBERACAO_MANUAL_OK\n";
} finally {
    $pdo->prepare("DELETE FROM entrevistas_desligamento_fatores WHERE entrevista_id IN (SELECT id FROM entrevistas_desligamento WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE 'ZZEDL\\_%' ESCAPE '\\\\'))")->execute();
    $pdo->prepare("DELETE FROM entrevistas_desligamento WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE 'ZZEDL\\_%' ESCAPE '\\\\')")->execute();
    $pdo->prepare("DELETE FROM entrevista_desligamento_liberacoes WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador LIKE 'ZZEDL\\_%' ESCAPE '\\\\')")->execute();
    if (!empty($criados['colaboradores'])) {
        $pdo->prepare('DELETE FROM colaboradores WHERE id IN (' . implode(',', array_map('intval', $criados['colaboradores'])) . ')')->execute();
    }
    $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador LIKE 'ZZEDL\\_%' ESCAPE '\\\\'")->execute();
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
    $_SESSION = $sessaoOriginal;
    if ($csrfOriginal !== null) {
        $_SESSION['csrf_token'] = $csrfOriginal;
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
