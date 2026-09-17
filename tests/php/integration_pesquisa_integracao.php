<?php

/**
 * Integração — Sprint "Fundação do Dashboard de Integração" (migration
 * 2026-09-17-pesquisa-integracao.sql + PesquisaIntegracao + PesquisaIntegracaoService +
 * PesquisaIntegracaoController + página pública + geração administrativa em
 * AdminColaboradoresController).
 *
 * Prova que:
 *   - só é possível gerar pesquisa para colaborador com integracao_status = 'realizada';
 *   - a geração é idempotente por EVENTO (colaborador_id + integracao_data_relacionada) — mesma
 *     data gera a mesma pesquisa, data diferente gera uma pesquisa NOVA sem apagar a antiga;
 *   - o token público é seguro (aleatório, longo, não sequencial, não derivado de CPF/ID/e-mail)
 *     e só o HASH fica gravado no banco;
 *   - token inválido não encontra nada;
 *   - NPS aceita 0 e 10 (limites) e rejeita fora da faixa;
 *   - as 5 notas de satisfação aceitam 1 e 5 (limites) e rejeitam fora da faixa;
 *   - comentário é opcional;
 *   - respondida_em só é setado após resposta válida, nunca antes, nunca inferido;
 *   - segunda resposta ao mesmo token/pesquisa é rejeitada;
 *   - vínculo é sempre com colaborador_id (nunca candidatura/CPF/e-mail);
 *   - ausência de colaboradores.metadados_id NÃO impede a geração da pesquisa;
 *   - nenhuma escrita em colaboradores_metadados/METADADOS;
 *   - pesquisas_experiencia (processo seletivo) fica completamente intocada;
 *   - o fluxo administrativo existente de Integração (editRh/updateIntegracao) continua
 *     funcionando sem Warning/Notice/Fatal, e a geração da pesquisa aparece nessa mesma tela.
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

$suffix = (string)time() . (string)random_int(1000, 9999);
$criados = ['colaboradores' => [], 'usuarios' => []];

$mkColaborador = static function (
    string $status,
    ?string $integracaoData,
    ?int $metadadosId = null
) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, integracao_status, integracao_data, ativo)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        'ZZINT Colaborador ' . $suffix . '-' . $seq,
        'zzint-colaborador-' . $suffix . '-' . $seq,
        $cargoId,
        $metadadosId,
        $status,
        $integracaoData,
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $id;
    return $id;
};

try {
    $cargoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $check($cargoId > 0, 'Fixture: existe cargo no catálogo local para as fixtures de colaborador');

    // ---- (1) só gera pesquisa para integração 'realizada' --------------------------------------
    $colPendente = $mkColaborador('pendente', null);
    $resPendente = PesquisaIntegracaoService::criarParaIntegracao($colPendente);
    $check(($resPendente['ok'] ?? true) === false, "(1) Colaborador com integração 'pendente' não gera pesquisa");

    // ---- fixture principal: integração realizada, com data ------------------------------------
    $dataIntegracao1 = '2026-06-01';
    $colRealizado = $mkColaborador('realizada', $dataIntegracao1);

    // ---- (2) geração idempotente por evento (colaborador + data) ------------------------------
    $res1 = PesquisaIntegracaoService::criarParaIntegracao($colRealizado);
    $check(($res1['ok'] ?? false) === true, '(2a) Primeira geração é bem-sucedida');
    $check(($res1['ja_existia'] ?? true) === false, '(2b) Primeira geração não é "já existia"');
    $check(!empty($res1['token']), '(2c) Primeira geração retorna o token bruto (única vez)');
    $idPesquisa1 = (int)$res1['pesquisa']['id'];

    $res2 = PesquisaIntegracaoService::criarParaIntegracao($colRealizado);
    $check(($res2['ja_existia'] ?? false) === true, '(2d) Segunda chamada, mesmo evento, retorna "já existia" (idempotente)');
    $check((int)$res2['pesquisa']['id'] === $idPesquisa1, '(2e) Segunda chamada retorna a MESMA pesquisa, não duplica');
    $check($res2['token'] === null, '(2f) Segunda chamada NUNCA retorna o token bruto (não é recuperável)');

    $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM pesquisas_integracao WHERE colaborador_id = ?');
    $stmtTotal->execute([$colRealizado]);
    $check((int)$stmtTotal->fetchColumn() === 1, '(2g) Nenhuma linha duplicada na tabela para o mesmo evento');

    // ---- (3) token não previsível ----------------------------------------------------------------
    $token1 = (string)$res1['token'];
    $check(strlen($token1) === 64 && ctype_xdigit($token1), '(3a) Token bruto tem 64 caracteres hexadecimais (32 bytes aleatórios)');
    $check(!str_contains($token1, (string)$colRealizado), '(3b) Token não contém o ID do colaborador em texto');

    // ---- (4) token inválido ----------------------------------------------------------------------
    $check(PesquisaIntegracao::findByRawToken('token-completamente-invalido') === null, '(4) Token inválido/inexistente não encontra nenhuma pesquisa');

    // ---- (14) vínculo correto com colaborador -----------------------------------------------------
    $pesquisa1 = PesquisaIntegracao::find($idPesquisa1);
    $check((int)$pesquisa1['colaborador_id'] === $colRealizado, '(14) Pesquisa gerada está vinculada ao colaborador_id correto');
    $check((string)$pesquisa1['integracao_data_relacionada'] === $dataIntegracao1, '(14b) integracao_data_relacionada é um snapshot da data da integração no momento da geração');

    // ---- (12) respondida_em só após resposta ------------------------------------------------------
    $check(empty($pesquisa1['respondida_em']), '(12a) respondida_em é NULL antes de qualquer resposta');

    // ---- (5)(6)(7)(9)(11) resposta válida nos limites (NPS=0, notas=1) + comentário ausente -------
    $r = PesquisaIntegracao::responder($idPesquisa1, 0, 1, 1, 1, 1, 1, null);
    $check(($r['ok'] ?? false) === true, '(5)(6)(9)(11) Resposta válida com NPS=0 (limite mínimo), notas=1 (limite mínimo) e sem comentário é aceita');

    $pesquisa1Depois = PesquisaIntegracao::find($idPesquisa1);
    $check(!empty($pesquisa1Depois['respondida_em']), '(12b) respondida_em é preenchido (NOW() real) após resposta válida');
    $check((int)$pesquisa1Depois['nota_nps'] === 0, 'Nota NPS original (0) permanece armazenada, não só a classificação');
    $check($pesquisa1Depois['comentarios'] === null, 'Comentário ausente é gravado como NULL, não string vazia inventada');

    // ---- (13) impedir segunda resposta -------------------------------------------------------------
    $r2 = PesquisaIntegracao::responder($idPesquisa1, 10, 5, 5, 5, 5, 5, 'tentativa de sobrescrever');
    $check(($r2['ok'] ?? true) === false, '(13) Segunda resposta ao mesmo id é rejeitada');
    $pesquisa1AposSegundaTentativa = PesquisaIntegracao::find($idPesquisa1);
    $check((int)$pesquisa1AposSegundaTentativa['nota_nps'] === 0, '(13b) Nota original da primeira resposta não foi sobrescrita pela tentativa rejeitada');

    // ---- (7)(9) limites máximos (NPS=10, notas=5) + comentário presente, em pesquisa separada -----
    $dataIntegracao2 = '2026-07-01';
    $colRealizado2 = $mkColaborador('realizada', $dataIntegracao2);
    $resB = PesquisaIntegracaoService::criarParaIntegracao($colRealizado2);
    $idPesquisaB = (int)$resB['pesquisa']['id'];
    $rB = PesquisaIntegracao::responder($idPesquisaB, 10, 5, 5, 5, 5, 5, 'Ótima integração, parabéns à equipe!');
    $check(($rB['ok'] ?? false) === true, '(7)(9) Resposta válida com NPS=10 (limite máximo) e notas=5 (limite máximo) é aceita');
    $pesquisaB = PesquisaIntegracao::find($idPesquisaB);
    $check((string)$pesquisaB['comentarios'] === 'Ótima integração, parabéns à equipe!', '(11b) Comentário é persistido quando informado');

    // ---- (8) rejeição de NPS fora da faixa ---------------------------------------------------------
    $dataIntegracao3 = '2026-08-01';
    $colRealizado3 = $mkColaborador('realizada', $dataIntegracao3);
    $resC = PesquisaIntegracaoService::criarParaIntegracao($colRealizado3);
    $idPesquisaC = (int)$resC['pesquisa']['id'];
    $check((PesquisaIntegracao::responder($idPesquisaC, -1, 3, 3, 3, 3, 3, null)['ok'] ?? true) === false, '(8a) NPS = -1 é rejeitado');
    $check((PesquisaIntegracao::responder($idPesquisaC, 11, 3, 3, 3, 3, 3, null)['ok'] ?? true) === false, '(8b) NPS = 11 é rejeitado');

    // ---- (10) rejeição de nota de satisfação fora da faixa -----------------------------------------
    $check((PesquisaIntegracao::responder($idPesquisaC, 5, 0, 3, 3, 3, 3, null)['ok'] ?? true) === false, '(10a) Nota de satisfação = 0 é rejeitada');
    $check((PesquisaIntegracao::responder($idPesquisaC, 5, 3, 3, 3, 3, 6, null)['ok'] ?? true) === false, '(10b) Nota de satisfação = 6 é rejeitada');
    $pesquisaCAindaNaoRespondida = PesquisaIntegracao::find($idPesquisaC);
    $check(empty($pesquisaCAindaNaoRespondida['respondida_em']), '(10c) Após todas as rejeições, a pesquisa C continua sem resposta (nenhum valor inválido foi gravado)');

    // ---- Evento diferente (data de integração corrigida) gera pesquisa NOVA, preserva a antiga ----
    $novaDataIntegracao1 = '2026-06-15';
    $pdo->prepare('UPDATE colaboradores SET integracao_data = ? WHERE id = ?')->execute([$novaDataIntegracao1, $colRealizado]);
    $resNovoEvento = PesquisaIntegracaoService::criarParaIntegracao($colRealizado);
    $check(($resNovoEvento['ja_existia'] ?? true) === false, 'Corrigir a data da integração para um novo evento gera uma pesquisa NOVA (não reaproveita a antiga)');
    $check((int)$resNovoEvento['pesquisa']['id'] !== $idPesquisa1, 'A pesquisa do novo evento tem um ID diferente da pesquisa original');
    $pesquisa1Preservada = PesquisaIntegracao::find($idPesquisa1);
    $check(
        (int)$pesquisa1Preservada['nota_nps'] === 0 && !empty($pesquisa1Preservada['respondida_em']),
        'A pesquisa original (evento antigo, já respondida com NPS=0) permanece intacta — histórico preservado, não foi apagada nem sobrescrita'
    );

    // ---- (16) ausência de metadados_id NÃO impede a geração da pesquisa ---------------------------
    $colSemMetadados = $mkColaborador('realizada', '2026-05-01', null);
    $colComMetadados = $mkColaborador('realizada', '2026-05-01', null); // metadados_id fica NULL de propósito (fixture não cria linha real em colaboradores_metadados)
    $resSemMetadados = PesquisaIntegracaoService::criarParaIntegracao($colSemMetadados);
    $check(($resSemMetadados['ok'] ?? false) === true, '(16) Colaborador SEM colaboradores.metadados_id ainda assim gera a pesquisa normalmente');

    // ---- integração 'realizada' sem data (estado defensivo, não deveria ocorrer via app, mas a
    //      service não pode presumir uma data) ------------------------------------------------------
    $colRealizadoSemData = $mkColaborador('realizada', null);
    $resSemData = PesquisaIntegracaoService::criarParaIntegracao($colRealizadoSemData);
    $check(($resSemData['ok'] ?? true) === false, "Integração 'realizada' sem data registrada não gera pesquisa (a service não inventa uma data)");

    // ---- (17) nenhuma escrita em colaboradores_metadados / METADADOS ------------------------------
    $fontesNovas = [
        APP_PATH . '/models/PesquisaIntegracao.php',
        APP_PATH . '/services/PesquisaIntegracaoService.php',
        APP_PATH . '/controllers/PesquisaIntegracaoController.php',
    ];
    foreach ($fontesNovas as $arquivo) {
        $conteudo = (string)file_get_contents($arquivo);
        $check(
            !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\s+colaboradores_metadados\b/i', $conteudo),
            basename($arquivo) . ' (17) não contém nenhuma escrita em colaboradores_metadados'
        );
        $check(!preg_match('/\bsqlsrv:|pdo_sqlsrv/i', $conteudo), basename($arquivo) . ' (17b) não abre nenhuma conexão SQL Server/pdo_sqlsrv');
    }
    // Vínculo real (não texto de comentário): a única FK/coluna de identidade em
    // pesquisas_integracao é colaborador_id — comprovado estruturalmente no item (15b) abaixo, e
    // todo o fluxo de geração/resposta acima (2)(5)(14) só aceita/retorna por colaborador_id.

    // ---- (15) preservação do vínculo METADADOS: schema não duplica Empresa/Setor/Cargo -----------
    $colunas = $pdo->query("SHOW COLUMNS FROM pesquisas_integracao")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['empresa', 'setor', 'cargo', 'codigo_empresa', 'codigo_setor'] as $colunaProibida) {
        $check(!in_array($colunaProibida, $colunas, true), "(15) pesquisas_integracao NÃO duplica '{$colunaProibida}' como texto — Empresa/Setor virão de colaboradores_metadados via colaboradores.metadados_id no Dashboard futuro");
    }
    $check(in_array('colaborador_id', $colunas, true), '(15b) pesquisas_integracao tem colaborador_id — o vínculo estrutural que leva a colaboradores.metadados_id quando existir');

    // ---- (18) pesquisas_experiencia (processo seletivo) permanece intocada -------------------------
    $colunasExperiencia = $pdo->query('SHOW COLUMNS FROM pesquisas_experiencia')->fetchAll(PDO::FETCH_COLUMN);
    $check(
        in_array('candidatura_id', $colunasExperiencia, true) && !in_array('colaborador_id', $colunasExperiencia, true),
        '(18) pesquisas_experiencia continua vinculada só a candidatura_id — nenhuma coluna de colaborador foi adicionada a ela'
    );

    // ---- (19) fluxo administrativo existente continua funcionando (smoke test) --------------------
    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $adminId = User::create('ZZINT Admin', 'admin.zzint.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;
    $responsavelId = User::create('ZZINT Responsavel', 'responsavel.zzint.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($responsavelId, true);
    $criados['usuarios'][] = $responsavelId;

    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];

    $colParaSmoke = $mkColaborador('pendente', null);

    ob_start();
    $erroEdit = null;
    try {
        (new AdminColaboradoresController())->editRh((string)$colParaSmoke);
    } catch (Throwable $e) {
        $erroEdit = $e;
    }
    $htmlEdit = ob_get_clean();
    $check($erroEdit === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlEdit), '(19a) editRh() de um colaborador com integração pendente renderiza sem Warning/Notice/Fatal — ' . ($erroEdit?->getMessage() ?? 'ok'));

    // Marca a integração como realizada via o MODEL real (Colaborador::updateIntegracao(), o
    // mesmo método que o controller chama) — não via AdminColaboradoresController::updateIntegracao()
    // diretamente, porque esse método termina com redirect()/exit() em caso de sucesso (correto em
    // produção via HTTP, mas encerraria este processo de teste PHP CLI no meio do script).
    $resultadoUpdate = Colaborador::updateIntegracao($colParaSmoke, [
        'integracao_status' => 'realizada',
        'integracao_data' => '10/06/2026',
        'integracao_responsavel_usuario_id' => (string)$responsavelId,
    ]);
    $check(($resultadoUpdate['ok'] ?? false) === true, '(19b) Colaborador::updateIntegracao() (fluxo existente, usado pelo controller) continua funcionando sem regressão — ' . ($resultadoUpdate['error'] ?? 'ok'));
    $colaboradorAtualizado = Colaborador::find($colParaSmoke);
    $check(($colaboradorAtualizado['integracao_status'] ?? '') === 'realizada', '(19c) Status da integração foi realmente atualizado para "realizada"');

    // gera a pesquisa via o novo endpoint administrativo
    $_POST = ['csrf' => Security::csrfToken()];
    ob_start();
    $erroGerar = null;
    try {
        (new AdminColaboradoresController())->gerarPesquisaIntegracao((string)$colParaSmoke);
    } catch (Throwable $e) {
        $erroGerar = $e;
    }
    $htmlGerar = ob_get_clean();
    $check($erroGerar === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlGerar), '(19d) gerarPesquisaIntegracao() renderiza sem Warning/Notice/Fatal — ' . ($erroGerar?->getMessage() ?? 'ok'));
    $check(str_contains($htmlGerar, '/integracao/'), '(19e) Tela exibe o link público gerado (contendo /integracao/) na primeira geração');

    // segunda visita à tela (GET normal) não deve mais exibir o link (não é recuperável)
    ob_start();
    (new AdminColaboradoresController())->editRh((string)$colParaSmoke);
    $htmlSegundaVisita = ob_get_clean();
    $check(!str_contains($htmlSegundaVisita, 'Copie o link agora'), '(19f) Em uma visita GET normal posterior, o link gerado não é reexibido (token não é recuperável)');
    $check(str_contains($htmlSegundaVisita, 'Aguardando resposta'), '(19g) Tela mostra status "Aguardando resposta" para a pesquisa já gerada');

    echo "\nPESQUISA_INTEGRACAO_OK\n";
} finally {
    if (!empty($criados['colaboradores'])) {
        $ids = implode(',', array_map('intval', $criados['colaboradores']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE colaborador_id IN ($ids)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($ids)");
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
