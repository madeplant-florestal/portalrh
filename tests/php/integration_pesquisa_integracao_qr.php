<?php

/**
 * Integração — Pesquisa de Integração via QR Code coletivo (migration
 * 2026-09-19-pesquisa-integracao-qr.sql + SessaoIntegracao + PesquisaIntegracaoQr +
 * PesquisaIntegracaoQrService + PesquisaIntegracaoQrController + AdminPesquisaIntegracaoQrController).
 *
 * Prova que:
 *   - a rota /integracao é pública (sem Auth::requireRole) e não colide com /integracao/{token},
 *     que segue registrada e funcional (fluxo individual antigo preservado);
 *   - sem sessão de integração aberta não há pesquisa disponível; no máximo UMA sessão fica aberta
 *     (garantido pelo UNIQUE do banco), e encerrar fecha o recebimento;
 *   - a identificação exige CPF válido + nascimento válido; qualquer falha de localização (CPF
 *     inexistente, nascimento incorreto, contrato inativo) devolve a MESMA mensagem genérica;
 *   - só contrato ATIVO do espelho oficial é localizado; Nome/Cargo/Empresa vêm do espelho;
 *   - vários contratos ativos: o backend só aceita seleção dentro do conjunto validado;
 *   - CPF/nascimento nunca entram em sessão, banco, URL ou HTML de volta; o contexto guarda só ids;
 *   - `integracao_data_relacionada` = data da SESSÃO; `respondida_em` = instante real (independentes);
 *   - resposta anônima (sem login) grava metadados_id direto, sem colaborador_id/token/CPF/nascimento;
 *   - nenhuma escrita em `colaboradores` nem no METADADOS;
 *   - duplicidade (contrato + integração) bloqueada — inclusive entre fluxo individual e QR — sem
 *     CPF; mesma pessoa em OUTRA integração é permitida;
 *   - rate limit por falhas, folgado para uma turma, sem reset por acerto;
 *   - CSRF nas três ações POST públicas; contexto limpo após o envio;
 *   - o QR codifica só a URL pública, gerado localmente (sem serviço externo);
 *   - administração exige permissão individual (visualizar vs editar);
 *   - People Analytics continua lendo as novas respostas (NPS) sem alteração.
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
$criados = ['usuarios' => [], 'sessoes' => [], 'metadados' => [], 'colaboradores' => [], 'pesquisas' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$ipFalso = '203.0.113.' . random_int(1, 254);
$_SERVER['HTTP_X_FORWARDED_FOR'] = $ipFalso;

// Sessão aberta preexistente (dev) é suspensa durante o teste e restaurada no fim.
$abertaOriginal = SessaoIntegracao::abertaAtual();
if ($abertaOriginal !== null) {
    $pdo->prepare('UPDATE sessoes_integracao SET aberta = NULL WHERE id = ?')->execute([(int)$abertaOriginal['id']]);
}

$gerarCpf = static function () use ($pdo): string {
    do {
        $d = [];
        for ($i = 0; $i < 9; $i++) {
            $d[] = random_int(0, 9);
        }
        $soma = 0;
        for ($i = 0, $peso = 10; $i < 9; $i++, $peso--) {
            $soma += $d[$i] * $peso;
        }
        $r = $soma % 11;
        $d[] = $r < 2 ? 0 : 11 - $r;
        $soma = 0;
        for ($i = 0, $peso = 11; $i < 10; $i++, $peso--) {
            $soma += $d[$i] * $peso;
        }
        $r = $soma % 11;
        $d[] = $r < 2 ? 0 : 11 - $r;
        $cpf = implode('', $d);
        $existe = $pdo->prepare('SELECT COUNT(*) FROM colaboradores_metadados WHERE cpf = ?');
        $existe->execute([$cpf]);
    } while (count(array_unique(str_split($cpf))) === 1 || (int)$existe->fetchColumn() > 0 || !Security::isValidCpf($cpf));
    return $cpf;
};

$mkContrato = static function (string $cpf, ?string $nascimento, bool $ativo, string $nome, string $empresa, string $cargo, ?string $codigoCargo = null) use ($pdo, &$criados, $suffix): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZQR_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf,
            nome, empresa, cargo, codigo_cargo, nascimento, admissao, demissao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, 'ZQ' . $suffix, 'ZQU' . $suffix, 'ZQC' . $suffix . $seq, 'ZQP' . $suffix . $seq, $cpf,
        $nome, $empresa, $cargo, $codigoCargo, $nascimento,
        (new DateTimeImmutable('-400 days'))->format('Y-m-d'),
        $ativo ? null : (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
        $ativo ? 1 : 0, 'zzqr-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

$contarColaboradores = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM colaboradores')->fetchColumn();
$contarMetadados = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM colaboradores_metadados')->fetchColumn();

$notasValidas = [
    'nota_nps' => '9', 'nota_clareza' => '5', 'nota_acolhimento' => '4', 'nota_normas' => '5',
    'nota_utilidade' => '4', 'nota_satisfacao_geral' => '5', 'comentarios' => 'Ótima integração',
];

try {
    // ---- 0) Usuários para a parte administrativa -------------------------------------------------
    $adminId = User::create('ZZQR Admin', 'admin.zzqr.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'integracao_colaborador.visualizar'")->fetchColumn();
    $permEdit = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'integracao_colaborador.editar'")->fetchColumn();
    $check($permVis > 0 && $permEdit > 0, 'Fixture: permissões integracao_colaborador.visualizar/.editar já existem (reaproveitadas, sem seed novo)');

    $userVisId = User::create('ZZQR So Visualizar', 'vis.zzqr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userVisId, true);
    $criados['usuarios'][] = $userVisId;
    Authorization::sincronizar($userVisId, [$permVis]);
    $userEditId = User::create('ZZQR Editar', 'edit.zzqr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userEditId, true);
    $criados['usuarios'][] = $userEditId;
    Authorization::sincronizar($userEditId, [$permVis, $permEdit]);
    $userNadaId = User::create('ZZQR Sem Permissao', 'nada.zzqr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userNadaId, true);
    $criados['usuarios'][] = $userNadaId;

    $check(Authorization::usuarioTemPermissao($userVisId, 'integracao_colaborador.visualizar') && !Authorization::usuarioTemPermissao($userVisId, 'integracao_colaborador.editar'), '(admin) Usuário só com .visualizar vê o QR mas NÃO abre/encerra integração');
    $check(Authorization::usuarioTemPermissao($userEditId, 'integracao_colaborador.editar'), '(admin) Usuário com .editar abre/encerra integração');
    $check(!Authorization::usuarioTemPermissao($userNadaId, 'integracao_colaborador.visualizar'), '(admin) Usuário sem permissão não acessa a área (RH não ganha acesso só pela role)');

    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $reflexao = new ReflectionMethod($classe, $metodo);
        $arquivo = new SplFileObject($reflexao->getFileName());
        $arquivo->seek($reflexao->getStartLine() - 1);
        $corpo = '';
        while ($arquivo->key() < $reflexao->getEndLine()) {
            $corpo .= $arquivo->current();
            $arquivo->next();
        }
        return $corpo;
    };
    foreach (['index' => 'visualizar', 'abrir' => 'editar', 'encerrar' => 'editar'] as $metodo => $perm) {
        $corpo = $corpoDoMetodo(AdminPesquisaIntegracaoQrController::class, $metodo);
        $check(str_contains($corpo, "Auth::requireRole(['admin', 'rh', 'viewer'])"), "(admin) {$metodo}() exige autenticação");
        $check(str_contains($corpo, "Authorization::requirePermissao('integracao_colaborador.{$perm}')"), "(admin) {$metodo}() exige integracao_colaborador.{$perm} no backend");
    }
    foreach (['abrir', 'encerrar'] as $metodo) {
        $check(str_contains($corpoDoMetodo(AdminPesquisaIntegracaoQrController::class, $metodo), 'Security::csrfCheck'), "(admin) {$metodo}() valida CSRF");
    }

    // ---- 1) Rota pública e coexistência com o fluxo individual -------------------------------------
    $fonteControllerPublico = (string)file_get_contents(APP_PATH . '/controllers/PesquisaIntegracaoQrController.php');
    $check(!str_contains($fonteControllerPublico, 'Auth::requireRole'), '(1) Controller do QR é público — nunca exige Auth::requireRole()');
    $fonteIndex = (string)file_get_contents(APP_PATH . '/../index.php');
    $check(str_contains($fonteIndex, "\$router->get('/integracao', [PesquisaIntegracaoQrController::class, 'inicio'])"), '(1) GET /integracao (URL do QR) está registrada');
    $check(str_contains($fonteIndex, "\$router->get('/integracao/{token}', [PesquisaIntegracaoController::class, 'show'])") && str_contains($fonteIndex, "\$router->post('/integracao/{token}', [PesquisaIntegracaoController::class, 'store'])"), '(1) Rotas do fluxo individual /integracao/{token} continuam registradas');
    $check(str_contains($fonteIndex, "\$router->get('/admin/pesquisa-integracao-qr'"), '(1) Rota administrativa registrada sob /admin (autenticação global do index.php)');
    $regraNavQr = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/pesquisa-integracao-qr') { $regraNavQr = $it['regra']; }
        }
    }
    $check($regraNavQr === 'perm:integracao_colaborador.visualizar', '(admin) A Central só oferece o QR da Integração sob a regra perm:integracao_colaborador.visualizar (PortalNavegacaoService::definicao() — sidebar removida, fonte da verdade agora é o serviço de navegação)');

    // ---- 2) Sem sessão aberta -----------------------------------------------------------------------
    $_SESSION = [];
    $_POST = [];
    $check(SessaoIntegracao::abertaAtual() === null, 'Fixture: nenhuma sessão de integração aberta');
    ob_start();
    (new PesquisaIntegracaoQrController())->inicio();
    $htmlSemSessao = ob_get_clean();
    $check(str_contains($htmlSemSessao, 'Não existe pesquisa de integração disponível'), '(3) Sem integração aberta, /integracao mostra mensagem amigável de indisponibilidade');
    $check(!str_contains($htmlSemSessao, 'name="cpf"'), '(3) Sem integração aberta o formulário de identificação NÃO é exibido');

    $_SESSION['csrf_token'] = 'zzqr-csrf-' . $suffix;
    $_POST = ['csrf' => $_SESSION['csrf_token'], 'cpf' => '111.444.777-35', 'nascimento' => '1990-01-01'];
    ob_start();
    (new PesquisaIntegracaoQrController())->identificar();
    $htmlPostSemSessao = ob_get_clean();
    $check(str_contains($htmlPostSemSessao, 'Não existe pesquisa de integração disponível'), '(3) POST de identificação sem integração aberta também é recusado no backend');

    // ---- 3) Sessão de integração: abrir/única/encerrar ------------------------------------------------
    $dataSessao = (new DateTimeImmutable('-10 days'))->format('Y-m-d');
    $abrir = SessaoIntegracao::abrir($dataSessao, $adminId);
    $check(($abrir['ok'] ?? false) === true, '(sessão) Abrir uma integração informando a data');
    $sessaoId = (int)$abrir['id'];
    $criados['sessoes'][] = $sessaoId;
    $segunda = SessaoIntegracao::abrir((new DateTimeImmutable('-3 days'))->format('Y-m-d'), $adminId);
    $check(($segunda['ok'] ?? true) === false, '(sessão) Segunda sessão aberta ao mesmo tempo é barrada (UNIQUE no banco)');
    $check((int)SessaoIntegracao::abertaAtual()['id'] === $sessaoId, '(sessão) A sessão ativa é a única aberta');
    $check(SessaoIntegracao::abertaAtual()['data_integracao'] === $dataSessao, '(sessão) A sessão guarda a data da integração');

    // ---- 4) Fixtures oficiais ------------------------------------------------------------------------
    $cpfA = $gerarCpf();
    $nascA = '1990-05-17';
    $idA = $mkContrato($cpfA, $nascA, true, 'ZZQR Maria da Silva', 'ZZQR Empresa Alfa', 'ZZQR Operador');
    $cpfInativo = $gerarCpf();
    $idInativo = $mkContrato($cpfInativo, '1985-03-02', false, 'ZZQR Desligado', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    $cpfMulti = $gerarCpf();
    $nascMulti = '1979-11-30';
    $idMulti1 = $mkContrato($cpfMulti, $nascMulti, true, 'ZZQR Multi Pessoa', 'ZZQR Empresa Alfa', 'ZZQR Cargo Um');
    $idMulti2 = $mkContrato($cpfMulti, $nascMulti, true, 'ZZQR Multi Pessoa', 'ZZQR Empresa Beta', 'ZZQR Cargo Dois');
    $cpfOutro = $gerarCpf();
    $idOutro = $mkContrato($cpfOutro, '1992-07-07', true, 'ZZQR Outra Pessoa', 'ZZQR Empresa Gama', 'ZZQR Cargo Tres');

    // ---- 5) Identificação -----------------------------------------------------------------------------
    $formatoCpf = PesquisaIntegracaoQrService::identificar('123.456.789-00', $nascA);
    $check(($formatoCpf['ok'] ?? true) === false && $formatoCpf['motivo'] === 'formato', '(4) CPF com dígitos verificadores inválidos é rejeitado antes de consultar o banco');
    $check((PesquisaIntegracaoQrService::identificar('', $nascA)['motivo'] ?? '') === 'formato', '(4) CPF vazio é rejeitado');
    $check((PesquisaIntegracaoQrService::identificar($cpfA, '')['motivo'] ?? '') === 'formato', '(4) Nascimento é obrigatório');
    $check((PesquisaIntegracaoQrService::identificar($cpfA, '31/02/1990')['motivo'] ?? '') === 'formato', '(4) Nascimento inexistente (31/02) é rejeitado');
    $check((PesquisaIntegracaoQrService::identificar($cpfA, (new DateTimeImmutable('+2 days'))->format('Y-m-d'))['motivo'] ?? '') === 'formato', '(4) Nascimento no futuro é rejeitado');

    $inexistente = PesquisaIntegracaoQrService::identificar($gerarCpf(), $nascA);
    $nascErrado = PesquisaIntegracaoQrService::identificar($cpfA, '1990-05-18');
    $inativo = PesquisaIntegracaoQrService::identificar($cpfInativo, '1985-03-02');
    $check(($inexistente['motivo'] ?? '') === 'nao_encontrado', '(5) CPF válido inexistente no espelho -> não encontrado');
    $check(($nascErrado['motivo'] ?? '') === 'nao_encontrado', '(5) CPF correto + nascimento incorreto -> não encontrado (exige os DOIS simultaneamente)');
    $check(($inativo['motivo'] ?? '') === 'nao_encontrado', '(5) Contrato INATIVO (mesmo com CPF e nascimento corretos) -> não encontrado');
    $check($inexistente === $nascErrado && $nascErrado === $inativo, '(6) As três falhas produzem resultado idêntico — nunca revelam qual campo falhou nem se o CPF existe/está desligado');

    $ok = PesquisaIntegracaoQrService::identificar($cpfA, $nascA);
    $check(($ok['ok'] ?? false) === true && count($ok['contratos']) === 1, '(7) CPF + nascimento corretos localizam exatamente 1 contrato ativo');
    $contratoA = $ok['contratos'][0];
    $check((int)$contratoA['id'] === $idA, '(7) Contrato identificado é o oficial do espelho (metadados_id)');
    $check($contratoA['nome'] === 'ZZQR Maria da Silva', '(7) Nome oficial vem do espelho');
    $check($contratoA['cargo'] === 'ZZQR Operador', '(7) Cargo oficial (texto do espelho quando não há codigo_cargo)');
    $check($contratoA['empresa'] === 'ZZQR Empresa Alfa', '(7) Empresa oficial vem do espelho');
    $check(!array_key_exists('cpf', $contratoA) && !array_key_exists('nascimento', $contratoA), '(7) O resultado da identificação NÃO carrega CPF nem nascimento de volta');
    $check((PesquisaIntegracaoQrService::identificar('  ' . substr($cpfA, 0, 3) . '.' . substr($cpfA, 3, 3) . '.' . substr($cpfA, 6, 3) . '-' . substr($cpfA, 9), '17/05/1990')['ok'] ?? false) === true, 'CPF mascarado e nascimento dd/mm/aaaa são normalizados no backend');

    $cargoCatalogo = $pdo->query("SELECT codigo_cargo, COALESCE(descricao_oficial, nome) AS nome_oficial FROM cargos WHERE codigo_cargo IS NOT NULL AND codigo_cargo <> '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cargoCatalogo) {
        $cpfCat = $gerarCpf();
        $idCat = $mkContrato($cpfCat, '1988-08-08', true, 'ZZQR Cargo Catalogo', 'ZZQR Empresa Alfa', 'texto-do-espelho-nao-deve-prevalecer', $cargoCatalogo['codigo_cargo']);
        $rCat = PesquisaIntegracaoQrService::identificar($cpfCat, '1988-08-08');
        $check(($rCat['contratos'][0]['cargo'] ?? null) === $cargoCatalogo['nome_oficial'], '(7) Cargo é resolvido pelo catálogo oficial quando o contrato tem codigo_cargo');
    }

    $multi = PesquisaIntegracaoQrService::identificar($cpfMulti, $nascMulti);
    $check(($multi['ok'] ?? false) === true && count($multi['contratos']) === 2, '(8) Mais de um contrato ativo: os dois são devolvidos, nenhum escolhido arbitrariamente');

    // ---- 6) Controller: falhas de identificação (nunca terminam em redirect) -----------------------
    $mensagemGenerica = PesquisaIntegracaoQrService::MSG_FALHA_IDENTIFICACAO;
    $htmlFalhas = [];
    foreach ([
        'inexistente' => [$gerarCpf(), '1990-01-01'],
        'nascimento incorreto' => [$cpfA, '1990-05-18'],
        'contrato inativo' => [$cpfInativo, '1985-03-02'],
    ] as $rotulo => [$cpfTeste, $nascTeste]) {
        $_SESSION = ['csrf_token' => 'zzqr-csrf-' . $suffix];
        $_POST = ['csrf' => $_SESSION['csrf_token'], 'cpf' => $cpfTeste, 'nascimento' => $nascTeste];
        ob_start();
        (new PesquisaIntegracaoQrController())->identificar();
        $htmlFalhas[$rotulo] = ob_get_clean();
        $check(str_contains($htmlFalhas[$rotulo], $mensagemGenerica), "(6) Falha '{$rotulo}': mensagem genérica \"Não foi possível localizar um vínculo ativo...\"");
        $check(!str_contains($htmlFalhas[$rotulo], $cpfTeste) && !str_contains($htmlFalhas[$rotulo], $nascTeste), "(12) Falha '{$rotulo}': CPF e nascimento NÃO são ecoados de volta na página");
        $check(!str_contains($htmlFalhas[$rotulo], 'ZZQR'), "(6) Falha '{$rotulo}': nenhum dado do colaborador é revelado");
        $check(!isset($_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]), "(9) Falha '{$rotulo}': nenhum contexto de identificação é criado");
    }
    PesquisaIntegracaoQrService::limparRateLimit();

    // CSRF nas ações POST públicas
    foreach (['identificar' => 'identificar', 'confirmarSelecao' => 'confirmar', 'enviar' => 'responder'] as $metodo => $rotulo) {
        $_SESSION = ['csrf_token' => 'zzqr-csrf-' . $suffix];
        $_POST = ['csrf' => 'token-invalido'];
        ob_start();
        (new PesquisaIntegracaoQrController())->{$metodo}();
        $htmlCsrf = ob_get_clean();
        $check(str_contains($htmlCsrf, 'CSRF'), "(CSRF) POST /integracao/{$rotulo} sem token válido é rejeitado");
    }

    // ---- 7) Contexto temporário e seleção segura ---------------------------------------------------
    $_SESSION = ['csrf_token' => 'zzqr-csrf-' . $suffix];
    $antesColaboradores = $contarColaboradores();
    $antesMetadados = $contarMetadados();

    PesquisaIntegracaoQrService::iniciarContexto($multi['contratos'], $sessaoId);
    $serializado = json_encode($_SESSION);
    $check(!str_contains($serializado, $cpfMulti) && !str_contains($serializado, $nascMulti) && !str_contains($serializado, '1979'), '(9) Sessão anônima NÃO contém CPF nem nascimento — só ids oficiais');
    $ctx = PesquisaIntegracaoQrService::contextoAtivo();
    $check($ctx !== null && $ctx['selecionado'] === null && count($ctx['candidatos']) === 2, '(8) Com 2 contratos, nenhum vínculo vem pré-selecionado');
    $check(PesquisaIntegracaoQrService::selecionarContrato($idOutro) === false, '(8) Contrato de OUTRA pessoa não pode ser selecionado (fora do conjunto validado)');
    $check(PesquisaIntegracaoQrService::selecionarContrato(999999999) === false, '(8) Id arbitrário forjado no navegador é rejeitado');
    $check(PesquisaIntegracaoQrService::registrarResposta($notasValidas)['codigo'] === 'sem_contexto', '(8) Sem seleção de vínculo confirmada, não é possível enviar a pesquisa');
    // registrarResposta() em contexto sem seleção NÃO limpa o contexto — reinicia para o passo seguinte
    PesquisaIntegracaoQrService::iniciarContexto($multi['contratos'], $sessaoId);
    $check(PesquisaIntegracaoQrService::selecionarContrato($idMulti2) === true, '(8) Contrato do conjunto validado é aceito');
    $check((int)$_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]['selecionado'] === $idMulti2, '(8) Seleção registrada no contexto');

    // Contexto expirado
    $_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]['expira'] = time() - 1;
    $check(PesquisaIntegracaoQrService::contextoAtivo() === null && !isset($_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]), '(9) Contexto expirado é descartado e limpo');

    // ---- 8) Envio: contrato único, data da sessão, sem login ---------------------------------------
    unset($_SESSION['user_id'], $_SESSION['user_role']);
    PesquisaIntegracaoQrService::iniciarContexto($ok['contratos'], $sessaoId);
    $invalida = PesquisaIntegracaoQrService::registrarResposta(array_merge($notasValidas, ['nota_nps' => '11']));
    $check(($invalida['codigo'] ?? '') === 'invalido', '(validação) NPS fora de 0-10 é rejeitado');
    $check((PesquisaIntegracaoQrService::registrarResposta(array_merge($notasValidas, ['nota_clareza' => '0']))['codigo'] ?? '') === 'invalido', '(validação) Nota de satisfação fora de 1-5 é rejeitada');
    $check((PesquisaIntegracaoQrService::registrarResposta(array_merge($notasValidas, ['nota_normas' => '']))['codigo'] ?? '') === 'invalido', '(validação) Pergunta obrigatória vazia é rejeitada');
    $check((PesquisaIntegracaoQrService::registrarResposta(array_merge($notasValidas, ['comentarios' => str_repeat('x', 2001)]))['codigo'] ?? '') === 'invalido', '(validação) Comentário acima de 2000 caracteres é rejeitado');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE metadados_id = {$idA}")->fetchColumn() === 0, '(validação) Nenhuma linha foi gravada pelas tentativas inválidas');
    $check(PesquisaIntegracaoQrService::contextoAtivo() !== null, '(validação) Erro de validação preserva o contexto para o colaborador corrigir e reenviar');

    $envio = PesquisaIntegracaoQrService::registrarResposta($notasValidas);
    $check(($envio['ok'] ?? false) === true, '(8) Envio anônimo (sem login) com contrato único selecionado automaticamente');
    $linha = $pdo->query("SELECT * FROM pesquisas_integracao WHERE metadados_id = {$idA}")->fetch(PDO::FETCH_ASSOC);
    $check($linha !== false, '(8) Resposta gravada com o metadados_id oficial');
    $criados['pesquisas'][] = (int)$linha['id'];
    $check($linha['colaborador_id'] === null && $linha['token_hash'] === null, '(10) Linha nova: sem colaborador_id e sem token individual');
    $check($linha['integracao_data_relacionada'] === $dataSessao, '(12) integracao_data_relacionada = data da SESSÃO de integração (10 dias atrás), não a data da resposta');
    $check(substr((string)$linha['respondida_em'], 0, 10) === date('Y-m-d') && $linha['respondida_em'] !== null, '(12) respondida_em = instante real do envio, independente da data da integração');
    $check($linha['integracao_data_relacionada'] !== substr((string)$linha['respondida_em'], 0, 10), '(12) As duas datas NÃO se confundem');
    $check((int)$linha['nota_nps'] === 9 && (int)$linha['nota_clareza'] === 5 && $linha['comentarios'] === 'Ótima integração', 'Notas e comentário do instrumento atual persistidos');
    $colunasPesquisa = $pdo->query('SHOW COLUMNS FROM pesquisas_integracao')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['cpf', 'nascimento', 'data_nascimento'] as $coluna) {
        $check(!in_array($coluna, $colunasPesquisa, true), "(12) pesquisas_integracao NÃO tem a coluna '{$coluna}' — CPF/nascimento nunca são persistidos");
    }
    $check(!str_contains(json_encode($linha), $cpfA), '(12) Nenhum valor da linha gravada contém o CPF');
    $check(!isset($_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]), '(9) Contexto temporário limpo após o envio');
    $check($contarColaboradores() === $antesColaboradores, '(13) Nenhuma escrita/materialização em colaboradores');
    $check($contarMetadados() === $antesMetadados, '(13) Nenhuma escrita em colaboradores_metadados (espelho do METADADOS)');

    // ---- 9) Duplicidade -----------------------------------------------------------------------------
    PesquisaIntegracaoQrService::iniciarContexto($ok['contratos'], $sessaoId);
    $dup = PesquisaIntegracaoQrService::registrarResposta($notasValidas);
    $check(($dup['codigo'] ?? '') === 'duplicada', '(14) Mesmo contrato + mesma integração não responde duas vezes (sem usar CPF)');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE metadados_id = {$idA}")->fetchColumn() === 1, '(14) Continua existindo uma única linha do contrato nesta integração');
    $check(!isset($_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]), '(9) Contexto também é limpo na duplicidade');

    try {
        $pdo->prepare('INSERT INTO pesquisas_integracao (metadados_id, integracao_data_relacionada, nota_nps) VALUES (?, ?, 5)')->execute([$idA, $dataSessao]);
        $check(false, '(14) UNIQUE (metadados_id, integracao_data_relacionada) barra a duplicata direto no banco');
    } catch (PDOException $e) {
        $check((int)($e->errorInfo[1] ?? 0) === 1062, '(14) UNIQUE (metadados_id, integracao_data_relacionada) barra a duplicata direto no banco');
    }

    // Outra integração (outra sessão, outra data) do MESMO contrato: permitido
    SessaoIntegracao::encerrar($sessaoId);
    $check(SessaoIntegracao::abertaAtual() === null, '(sessão) Encerrar a integração fecha o recebimento');
    $dataSessao2 = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
    $abrir2 = SessaoIntegracao::abrir($dataSessao2, $adminId);
    $check(($abrir2['ok'] ?? false) === true, '(sessão) Após encerrar, outra integração pode ser aberta');
    $sessaoId2 = (int)$abrir2['id'];
    $criados['sessoes'][] = $sessaoId2;
    // contexto criado na sessão antiga NÃO vale para a nova (id diferente)
    $_SESSION[PesquisaIntegracaoQrService::SESSION_KEY] = ['candidatos' => [$idA], 'selecionado' => $idA, 'sessao_id' => $sessaoId, 'expira' => time() + 600];
    $check(PesquisaIntegracaoQrService::contextoAtivo() === null, '(sessão) Contexto de uma sessão encerrada não é aceito em outra');
    PesquisaIntegracaoQrService::iniciarContexto($ok['contratos'], $sessaoId2);
    $novaIntegracao = PesquisaIntegracaoQrService::registrarResposta($notasValidas);
    $check(($novaIntegracao['ok'] ?? false) === true, '(14) Mesmo contrato em OUTRA integração (data diferente) é permitido');
    $linha2 = $pdo->query("SELECT * FROM pesquisas_integracao WHERE metadados_id = {$idA} AND integracao_data_relacionada = '{$dataSessao2}'")->fetch(PDO::FETCH_ASSOC);
    $criados['pesquisas'][] = (int)$linha2['id'];
    $check($linha2['integracao_data_relacionada'] === $dataSessao2, '(12) A segunda resposta usa a data da segunda sessão');

    // Sessão encerrada no meio do preenchimento
    PesquisaIntegracaoQrService::iniciarContexto($multi['contratos'], $sessaoId2);
    PesquisaIntegracaoQrService::selecionarContrato($idMulti1);
    SessaoIntegracao::encerrar($sessaoId2);
    $check((PesquisaIntegracaoQrService::registrarResposta($notasValidas)['codigo'] ?? '') === 'sem_contexto', '(sessão) Integração encerrada durante o preenchimento: envio recusado no backend');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE metadados_id = {$idMulti1}")->fetchColumn() === 0, '(sessão) Nada foi gravado após o encerramento');

    // Contrato desligado entre a identificação e o envio
    $abrir3 = SessaoIntegracao::abrir($dataSessao, $adminId);
    $sessaoId3 = (int)$abrir3['id'];
    $criados['sessoes'][] = $sessaoId3;
    $cpfTemp = $gerarCpf();
    $idTemp = $mkContrato($cpfTemp, '1991-01-01', true, 'ZZQR Temp', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    PesquisaIntegracaoQrService::iniciarContexto(PesquisaIntegracaoQrService::identificar($cpfTemp, '1991-01-01')['contratos'], $sessaoId3);
    $pdo->prepare('UPDATE colaboradores_metadados SET ativo = 0, demissao = CURDATE() WHERE id = ?')->execute([$idTemp]);
    $check((PesquisaIntegracaoQrService::registrarResposta($notasValidas)['codigo'] ?? '') === 'sem_contexto', '(6) Contrato que deixou de estar ativo não consegue mais enviar (revalidado no envio)');

    // ---- 10) Duplicidade entre fluxo individual (legado) e QR -----------------------------------------
    $cargoLocal = (int)$pdo->query('SELECT id FROM cargos ORDER BY id ASC LIMIT 1')->fetchColumn();
    $mkColaboradorLegado = static function (int $metadadosId, string $dataIntegracao) use ($pdo, &$criados, $suffix, $cargoLocal): int {
        static $seq = 0;
        $seq++;
        $pdo->prepare(
            "INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, integracao_status, integracao_data, ativo)
             VALUES (?, ?, ?, ?, 'realizada', ?, 1)"
        )->execute(['ZZQR Legado ' . $seq, 'zzqr-legado-' . $suffix . '-' . $seq, $cargoLocal, $metadadosId, $dataIntegracao]);
        $id = (int)$pdo->lastInsertId();
        $criados['colaboradores'][] = $id;
        return $id;
    };

    // (a) pesquisa individual JÁ RESPONDIDA para o mesmo contrato+integração -> QR é duplicada
    $cpfLegA = $gerarCpf();
    $idLegA = $mkContrato($cpfLegA, '1980-02-02', true, 'ZZQR Legado Respondido', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    $colLegA = $mkColaboradorLegado($idLegA, $dataSessao);
    $resLegA = PesquisaIntegracaoService::criarParaIntegracao($colLegA);
    $check(($resLegA['ok'] ?? false) === true && !empty($resLegA['token']), '(15) Fluxo individual antigo continua gerando a pesquisa com token');
    $idPesqLegA = (int)$resLegA['pesquisa']['id'];
    $criados['pesquisas'][] = $idPesqLegA;
    $check((int)PesquisaIntegracao::find($idPesqLegA)['metadados_id'] === $idLegA, '(15) Pesquisa individual de colaborador com contrato oficial já registra o metadados_id (proteção também no banco)');
    $check(PesquisaIntegracao::findByRawToken($resLegA['token']) !== null, '(15) Token individual antigo continua encontrando a pesquisa');
    $rLeg = PesquisaIntegracao::responder($idPesqLegA, 8, 4, 4, 4, 4, 4, null);
    $check(($rLeg['ok'] ?? false) === true, '(15) Fluxo individual antigo continua registrando resposta');
    PesquisaIntegracaoQrService::iniciarContexto(PesquisaIntegracaoQrService::identificar($cpfLegA, '1980-02-02')['contratos'], $sessaoId3);
    $dupLegado = PesquisaIntegracaoQrService::registrarResposta($notasValidas);
    $check(($dupLegado['codigo'] ?? '') === 'duplicada', '(16) Contrato+integração já respondidos pelo fluxo individual NÃO respondem de novo via QR');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE integracao_data_relacionada = '{$dataSessao}' AND (metadados_id = {$idLegA} OR colaborador_id = {$colLegA})")->fetchColumn() === 1, '(16) Continua uma única linha para o evento');

    // (b) linha ANTIGA sem metadados_id (histórica): equivalência via colaboradores.metadados_id
    $cpfLegB = $gerarCpf();
    $idLegB = $mkContrato($cpfLegB, '1981-03-03', true, 'ZZQR Legado Historico', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    $colLegB = $mkColaboradorLegado($idLegB, $dataSessao);
    $pdo->prepare('INSERT INTO pesquisas_integracao (colaborador_id, integracao_data_relacionada, token_hash, nota_nps, respondida_em) VALUES (?, ?, ?, 7, NOW())')
        ->execute([$colLegB, $dataSessao, hash('sha256', 'zzqr-historico-' . $suffix)]);
    $criados['pesquisas'][] = (int)$pdo->lastInsertId();
    PesquisaIntegracaoQrService::iniciarContexto(PesquisaIntegracaoQrService::identificar($cpfLegB, '1981-03-03')['contratos'], $sessaoId3);
    $check((PesquisaIntegracaoQrService::registrarResposta($notasValidas)['codigo'] ?? '') === 'duplicada', '(16) Linha histórica (só colaborador_id) é reconhecida pelo contrato oficial em colaboradores.metadados_id — sem CPF');

    // (c) pesquisa individual PENDENTE para o mesmo evento: o QR completa ESSA linha (sem segunda linha)
    $cpfLegC = $gerarCpf();
    $idLegC = $mkContrato($cpfLegC, '1982-04-04', true, 'ZZQR Legado Pendente', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    $colLegC = $mkColaboradorLegado($idLegC, $dataSessao);
    $resLegC = PesquisaIntegracaoService::criarParaIntegracao($colLegC);
    $idPesqLegC = (int)$resLegC['pesquisa']['id'];
    $criados['pesquisas'][] = $idPesqLegC;
    PesquisaIntegracaoQrService::iniciarContexto(PesquisaIntegracaoQrService::identificar($cpfLegC, '1982-04-04')['contratos'], $sessaoId3);
    $completa = PesquisaIntegracaoQrService::registrarResposta($notasValidas);
    $check(($completa['ok'] ?? false) === true, '(16) Pesquisa individual pendente do mesmo evento é completada via QR');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE integracao_data_relacionada = '{$dataSessao}' AND (metadados_id = {$idLegC} OR colaborador_id = {$colLegC})")->fetchColumn() === 1, '(16) Nenhuma segunda linha foi criada — o link individual e o QR são o mesmo evento');
    $check(($resposta = PesquisaIntegracao::responder($idPesqLegC, 3, 1, 1, 1, 1, 1, null)) && ($resposta['ok'] ?? true) === false, '(16) O link individual antigo NÃO aceita mais resposta depois que o QR completou o evento');

    // (d) QR primeiro, depois o RH tenta gerar a pesquisa individual do mesmo evento
    $cpfLegD = $gerarCpf();
    $idLegD = $mkContrato($cpfLegD, '1983-05-05', true, 'ZZQR Legado Depois', 'ZZQR Empresa Alfa', 'ZZQR Cargo');
    PesquisaIntegracaoQrService::iniciarContexto(PesquisaIntegracaoQrService::identificar($cpfLegD, '1983-05-05')['contratos'], $sessaoId3);
    $check((PesquisaIntegracaoQrService::registrarResposta($notasValidas)['ok'] ?? false) === true, 'Fixture: contrato respondeu primeiro pelo QR');
    $colLegD = $mkColaboradorLegado($idLegD, $dataSessao);
    $resLegD = PesquisaIntegracaoService::criarParaIntegracao($colLegD);
    $check(($resLegD['ok'] ?? false) === true && ($resLegD['ja_existia'] ?? false) === true && $resLegD['token'] === null, '(16) Gerar pesquisa individual para evento já respondido via QR não cria outra pesquisa nem novo token');
    $check((int)$pdo->query("SELECT COUNT(*) FROM pesquisas_integracao WHERE integracao_data_relacionada = '{$dataSessao}' AND (metadados_id = {$idLegD} OR colaborador_id = {$colLegD})")->fetchColumn() === 1, '(16) Continua uma única linha para o evento');
    foreach ($pdo->query("SELECT id FROM pesquisas_integracao WHERE metadados_id IN ({$idLegA}, {$idLegC}, {$idLegD})")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $criados['pesquisas'][] = (int)$pid;
    }

    // ---- 11) Rate limit --------------------------------------------------------------------------------
    PesquisaIntegracaoQrService::limparRateLimit();
    $check(PesquisaIntegracaoQrService::segundosBloqueado() === 0, '(rate) Sem falhas registradas, o acesso está liberado');
    for ($i = 0; $i < 30; $i++) {
        PesquisaIntegracaoQrService::registrarFalhaIdentificacao();
    }
    $check(PesquisaIntegracaoQrService::segundosBloqueado() === 0, '(rate) 30 falhas do mesmo IP (turma inteira no mesmo Wi-Fi) NÃO bloqueiam — limite folgado');
    for ($i = 0; $i < 10; $i++) {
        PesquisaIntegracaoQrService::registrarFalhaIdentificacao();
    }
    $check(PesquisaIntegracaoQrService::segundosBloqueado() > 0, '(rate) Ao atingir 40 falhas em 10 minutos o IP é bloqueado (dificulta enumeração automatizada)');
    $_SESSION = ['csrf_token' => 'zzqr-csrf-' . $suffix];
    $_POST = ['csrf' => $_SESSION['csrf_token'], 'cpf' => $cpfA, 'nascimento' => $nascA];
    ob_start();
    (new PesquisaIntegracaoQrController())->identificar();
    $htmlBloqueado = ob_get_clean();
    $check(str_contains($htmlBloqueado, 'Muitas tentativas') && !isset($_SESSION[PesquisaIntegracaoQrService::SESSION_KEY]), '(rate) Bloqueado, nem credenciais corretas identificam — e nenhum contexto é criado');
    $globalCheck = Security::rateLimitCheck('integracao_qr_ident_global', 'global', 300, 600, 600);
    $check($globalCheck['attempts_count'] >= 40, '(rate) O contador GLOBAL também acumula as falhas (freio contra IP forjado/enumeração distribuída)');
    PesquisaIntegracaoQrService::limparRateLimit();
    $check(PesquisaIntegracaoQrService::segundosBloqueado() === 0, 'Fixture: rate limit do teste limpo');

    // ---- 12) QR Code: só a URL pública, gerado localmente ---------------------------------------------------
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];
    ob_start();
    $erroAdmin = null;
    try {
        (new AdminPesquisaIntegracaoQrController())->index();
    } catch (Throwable $e) {
        $erroAdmin = $e;
    }
    $htmlAdmin = ob_get_clean();
    $check($erroAdmin === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlAdmin), '(QR) Tela administrativa renderiza sem Warning/Notice/Fatal — ' . ($erroAdmin?->getMessage() ?? 'ok'));
    $check((bool)preg_match('/data-qr-url="([^"]+)"/', $htmlAdmin, $mUrl), '(QR) A tela expõe a URL codificada no QR');
    $urlQr = html_entity_decode($mUrl[1] ?? '');
    $check(str_ends_with($urlQr, '/integracao') && !str_contains($urlQr, '?') && !str_contains($urlQr, '#'), '(QR) O QR codifica SOMENTE a URL pública estável /integracao — sem query, sem token, sem dado pessoal');
    $check(!preg_match('/\d{6,}/', $urlQr), '(QR) A URL do QR não contém sequência numérica (CPF/id/matrícula/data)');
    $check(str_contains($htmlAdmin, 'Escaneie o QR Code com a câmera do seu celular para responder à pesquisa.'), '(QR) Área de impressão traz o texto orientativo');
    $check(str_contains($htmlAdmin, '@media print'), '(QR) Impressão isolada por CSS @media print (sem PDF)');
    $check(str_contains($htmlAdmin, 'Copiar link') && str_contains($htmlAdmin, 'Imprimir QR Code'), '(QR) Ações copiar link e imprimir disponíveis');
    $areaImpressao = substr($htmlAdmin, (int)strpos($htmlAdmin, 'id="qr-print-area"'), 1400);
    $check(!str_contains($areaImpressao, 'ZZQR') && !str_contains($areaImpressao, $cpfA), '(QR) A área impressa não contém dados de colaborador');
    $check(is_file(BASE_PATH . '/public/assets/qrcode.js') && is_file(BASE_PATH . '/public/assets/qrcode.LICENSE.txt'), '(QR) qrcode.js e a licença MIT estão versionados localmente em public/assets');
    $check(str_contains((string)file_get_contents(BASE_PATH . '/public/assets/qrcode.LICENSE.txt'), 'MIT'), '(QR) Licença MIT registrada');
    foreach ([BASE_PATH . '/public/assets/qrcode.js', BASE_PATH . '/public/assets/integracao-qr.js'] as $arquivoJs) {
        $js = (string)file_get_contents($arquivoJs);
        $check(!preg_match('/XMLHttpRequest|\bfetch\s*\(|sendBeacon|WebSocket|new Image\s*\(|importScripts/', $js), '(QR) ' . basename($arquivoJs) . ' não faz nenhuma chamada de rede');
    }
    $viewAdminFonte = (string)file_get_contents(APP_PATH . '/views/admin/pesquisa_integracao_qr/index.php');
    $check(!preg_match('#https?://#i', $viewAdminFonte), '(QR) A view administrativa não referencia nenhum serviço/CDN externo');
    $check(str_contains($viewAdminFonte, 'assets/qrcode.js'), '(QR) A biblioteca é carregada de /assets local');
    $check(!preg_match('/chart\.googleapis|qrserver|api\.qrcode|cdn\./i', $viewAdminFonte . (string)file_get_contents(BASE_PATH . '/public/assets/integracao-qr.js')), '(QR) Nenhuma API pública de QR Code (Google Charts etc.)');

    // ---- 13) Regressões / compatibilidades -------------------------------------------------------------------
    foreach ([
        APP_PATH . '/models/SessaoIntegracao.php', APP_PATH . '/models/PesquisaIntegracaoQr.php',
        APP_PATH . '/services/PesquisaIntegracaoQrService.php', APP_PATH . '/controllers/PesquisaIntegracaoQrController.php',
        APP_PATH . '/controllers/AdminPesquisaIntegracaoQrController.php',
    ] as $arquivo) {
        $conteudo = (string)file_get_contents($arquivo);
        $check(!preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+colaboradores\b(?!_)/i', $conteudo), basename($arquivo) . ' nunca escreve em colaboradores (nenhuma materialização)');
        $check(!preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+colaboradores_metadados\b/i', $conteudo), basename($arquivo) . ' nunca escreve no METADADOS');
        $check(!str_contains($conteudo, 'ColaboradorExtensaoLocalService') && !preg_match('/sqlsrv|pdo_sqlsrv/i', $conteudo), basename($arquivo) . ' não usa ColaboradorExtensaoLocalService nem SQL Server');
        $check(!preg_match('/\bCREATE\s+TABLE\b|\bALTER\s+TABLE\b|ensureSchema/i', $conteudo), basename($arquivo) . ' não executa DDL em runtime');
        $check(!preg_match('/error_log|Logger::|file_put_contents\s*\(.*(cpf|nascimento)/i', $conteudo), basename($arquivo) . ' não registra CPF/nascimento em logs');
    }

    // People Analytics continua lendo as novas respostas (NPS) sem alteração
    $notasPeriodo = (new PeopleAnalyticsRepository())->buscarNotasNpsIntegracao(new DateTimeImmutable('today'), new DateTimeImmutable('today'));
    $check(count($notasPeriodo) >= 1, '(People Analytics) buscarNotasNpsIntegracao() inclui as respostas do fluxo QR (colaborador_id NULL) — nenhuma alteração necessária');

    // Fluxo individual antigo: página pública continua respondendo
    $_SESSION = ['csrf_token' => 'zzqr-csrf-' . $suffix];
    ob_start();
    (new PesquisaIntegracaoController())->show('token-inexistente-' . $suffix);
    $htmlLegado = ob_get_clean();
    $check(str_contains($htmlLegado, 'Link inválido'), '(15) /integracao/{token} inválido continua mostrando "Link inválido" (fluxo individual preservado)');

    echo "\nPESQUISA_INTEGRACAO_QR_OK\n";
} finally {
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    PesquisaIntegracaoQrService::limparRateLimit();

    $todosMetadados = array_map('intval', $criados['metadados']);
    if ($todosMetadados !== []) {
        $ids = implode(',', $todosMetadados);
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE metadados_id IN ($ids)");
    }
    if (!empty($criados['colaboradores'])) {
        $ids = implode(',', array_map('intval', $criados['colaboradores']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE colaborador_id IN ($ids)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($ids)");
    }
    if (!empty($criados['pesquisas'])) {
        $ids = implode(',', array_map('intval', $criados['pesquisas']));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE id IN ($ids)");
    }
    if ($todosMetadados !== []) {
        $pdo->exec('DELETE FROM colaboradores_metadados WHERE id IN (' . implode(',', $todosMetadados) . ')');
    }
    if (!empty($criados['sessoes'])) {
        $pdo->exec('DELETE FROM sessoes_integracao WHERE id IN (' . implode(',', array_map('intval', $criados['sessoes'])) . ')');
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
    if ($abertaOriginal !== null) {
        $pdo->prepare('UPDATE sessoes_integracao SET aberta = 1 WHERE id = ?')->execute([(int)$abertaOriginal['id']]);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
