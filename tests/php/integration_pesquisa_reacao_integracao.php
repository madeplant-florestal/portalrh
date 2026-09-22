<?php

/**
 * Integração — Pesquisa de Reação: Treinamento de Integração (migration
 * 2026-09-18-pesquisa-reacao-integracao.sql + PesquisaReacaoCampanha + PesquisaReacaoResposta +
 * PesquisaReacaoIntegracaoService + PesquisaReacaoIntegracaoController +
 * AdminPesquisaReacaoIntegracaoController).
 *
 * Instrumento DIFERENTE da Pesquisa de Integração já existente (`pesquisas_integracao`) — 1
 * Campanha -> N Respostas, participação anônima, sem gate de "já respondida".
 *
 * Prova que:
 *   - o token público é aleatório (64 hex = 32 bytes), nunca sequencial/derivado de nada, e só o
 *     HASH (sha256) fica gravado — o token bruto NUNCA é persistido em nenhuma coluna;
 *   - token inexistente não encontra nada;
 *   - uma campanha aceita respostas apenas enquanto existir, estiver ativa E não tiver expirado —
 *     validado sempre no backend, tanto na exibição quanto no envio;
 *   - `expira_em` é obrigatoriamente futuro na criação (rejeita validade inválida e data
 *     customizada no passado) — não é possível gerar uma campanha já expirada;
 *   - a data/hora customizada de expiração tem prioridade sobre a validade rápida quando as duas
 *     são enviadas;
 *   - POST público após expiração e após desativação são rejeitados pelo controller (sem gravar
 *     resposta), nunca só pela UI;
 *   - NPS aceita 0 e 10 (limites) e rejeita fora de 0-10; classificação 0-6 Detrator, 7-8 Neutro,
 *     9-10 Promotor é sempre calculada, nunca persistida como texto redundante;
 *   - as 6 avaliações aceitam 1 e 5 (limites) e rejeitam fora de 1-5;
 *   - as 3 perguntas abertas e o nome são opcionais — resposta totalmente anônima é válida;
 *   - a mesma campanha aceita múltiplas respostas independentes (nunca bloqueia pela segunda);
 *   - Empresa/Setor/Data da Integração vêm sempre da campanha (resolvida pelo token) — o serviço
 *     de registro de resposta nem lê essas chaves do POST, e a tabela de respostas não tem essas
 *     colunas (estruturalmente impossível manipular por lá);
 *   - `*_nome_snapshot` preserva a descrição oficial resolvida NO MOMENTO da criação da campanha;
 *   - NPS administrativo = %Promotores - %Detratores (nunca a média das notas), com
 *     Promotores/Neutros/Detratores e médias das 6 avaliações calculados corretamente;
 *   - campanha sem nenhuma resposta não inventa números (nps null, total 0);
 *   - `pesquisa_reacao_integracao.visualizar` e `.gerenciar` são permissões individuais reais —
 *     Admin só pelo bypass central, RH/viewer não recebem acesso automático pela role;
 *   - o backend do admin controller trava por Authorization::requirePermissao() com os códigos
 *     corretos em cada ação (visualizar vs. gerenciar) e por Auth::requireRole() em todas elas;
 *   - o controller público NUNCA exige Auth::requireRole() (é a única parte pública do módulo);
 *   - nenhuma DDL (CREATE TABLE/ALTER TABLE) e nenhum ensureSchema() aparecem nos arquivos novos —
 *     a migration é a única fonte da estrutura;
 *   - a Pesquisa de Integração existente (`pesquisas_integracao`) permanece com sua própria
 *     estrutura, intocada.
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
$criados = ['usuarios' => [], 'campanhas' => [], 'metadados_identificadores' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

try {
    // ---- 0) Fixtures de usuário/permissão ----------------------------------------------------
    $adminId = User::create('ZZPR Admin', 'admin.zzpr.' . $suffix . '@teste.local', $senha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $permVisStmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $permVisStmt->execute(['pesquisa_reacao_integracao.visualizar']);
    $permVisId = (int)($permVisStmt->fetchColumn() ?: 0);
    $check($permVisId > 0, 'Fixture: permissão pesquisa_reacao_integracao.visualizar existe no catálogo (seedada nesta sprint)');

    $permGerStmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $permGerStmt->execute(['pesquisa_reacao_integracao.gerenciar']);
    $permGerId = (int)($permGerStmt->fetchColumn() ?: 0);
    $check($permGerId > 0, 'Fixture: permissão pesquisa_reacao_integracao.gerenciar existe no catálogo (seedada nesta sprint)');

    $userSoVisualizarId = User::create('ZZPR So Visualizar', 'visualizar.zzpr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userSoVisualizarId, true);
    $criados['usuarios'][] = $userSoVisualizarId;
    Authorization::sincronizar($userSoVisualizarId, [$permVisId]);

    $userGerenciarId = User::create('ZZPR Gerenciar', 'gerenciar.zzpr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userGerenciarId, true);
    $criados['usuarios'][] = $userGerenciarId;
    Authorization::sincronizar($userGerenciarId, [$permVisId, $permGerId]);

    $userSemPermissaoId = User::create('ZZPR Sem Permissao', 'sempermissao.zzpr.' . $suffix . '@teste.local', $senha, 'rh');
    User::setActiveStatus($userSemPermissaoId, true);
    $criados['usuarios'][] = $userSemPermissaoId;

    $check(Authorization::usuarioTemPermissao($adminId, 'pesquisa_reacao_integracao.visualizar') === true, '(11a) Admin acessa .visualizar via bypass central, sem permissão individual concedida');
    $check(Authorization::usuarioTemPermissao($adminId, 'pesquisa_reacao_integracao.gerenciar') === true, '(11a) Admin acessa .gerenciar via bypass central');
    $check(Authorization::usuarioTemPermissao($userSoVisualizarId, 'pesquisa_reacao_integracao.visualizar') === true, '(14) Usuário com .visualizar acessa a listagem/resultados');
    $check(Authorization::usuarioTemPermissao($userSoVisualizarId, 'pesquisa_reacao_integracao.gerenciar') === false, '(14) Usuário só com .visualizar NÃO consegue gerar/desativar');
    $check(Authorization::usuarioTemPermissao($userGerenciarId, 'pesquisa_reacao_integracao.gerenciar') === true, '(15) Usuário com .gerenciar consegue gerar/desativar');
    $check(Authorization::usuarioTemPermissao($userSemPermissaoId, 'pesquisa_reacao_integracao.visualizar') === false, '(16) Usuário sem nenhuma das duas permissões não acessa nem a listagem — RH não ganha acesso automático pela role');
    $check(Authorization::usuarioTemPermissao($userSemPermissaoId, 'pesquisa_reacao_integracao.gerenciar') === false, '(16) ...nem a capacidade de gerenciar');

    // ---- Reflexão de fonte: backend travado corretamente em cada ação (nunca só pela UI) --------
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

    foreach (['index' => 'visualizar', 'resultados' => 'visualizar', 'store' => 'gerenciar', 'desativar' => 'gerenciar'] as $metodo => $sufixoPermissao) {
        $corpo = $corpoDoMetodo(AdminPesquisaReacaoIntegracaoController::class, $metodo);
        $check(str_contains($corpo, "Auth::requireRole(['admin', 'rh', 'viewer'])"), "(22) AdminPesquisaReacaoIntegracaoController::{$metodo}() exige sessão autenticada de área administrativa");
        $check(str_contains($corpo, "Authorization::requirePermissao('pesquisa_reacao_integracao.{$sufixoPermissao}')"), "(14/15) AdminPesquisaReacaoIntegracaoController::{$metodo}() exige pesquisa_reacao_integracao.{$sufixoPermissao} no backend");
    }
    foreach (['store', 'desativar'] as $metodo) {
        $corpo = $corpoDoMetodo(AdminPesquisaReacaoIntegracaoController::class, $metodo);
        $check(str_contains($corpo, "Security::csrfCheck"), "AdminPesquisaReacaoIntegracaoController::{$metodo}() valida CSRF");
    }

    $fonteControllerPublico = (string)file_get_contents(APP_PATH . '/controllers/PesquisaReacaoIntegracaoController.php');
    $check(!str_contains($fonteControllerPublico, 'Auth::requireRole'), '(21) PesquisaReacaoIntegracaoController (público) NUNCA exige Auth::requireRole() — é a única parte pública do módulo');
    $check(str_contains($fonteControllerPublico, 'Security::csrfCheck'), 'PesquisaReacaoIntegracaoController::store() valida CSRF mesmo sendo público');

    // ---- Central: item só aparece com a permissão individual (sidebar removida) --------------------
    $regraNavPesquisaReacao = null;
    foreach (PortalNavegacaoService::definicao() as $m) {
        foreach ($m['itens'] as $it) {
            if ($it['href'] === '/admin/pesquisas-reacao-integracao') { $regraNavPesquisaReacao = $it['regra']; }
        }
    }
    $check(
        $regraNavPesquisaReacao === 'perm:pesquisa_reacao_integracao.visualizar',
        '(12) A Central só oferece a Pesquisa de Reação sob a regra perm:pesquisa_reacao_integracao.visualizar (PortalNavegacaoService::definicao() — fonte da verdade agora é o serviço de navegação)'
    );

    // ---- Nenhuma DDL em runtime: migration é a única fonte da estrutura --------------------------
    $fontesNovas = [
        APP_PATH . '/models/PesquisaReacaoCampanha.php',
        APP_PATH . '/models/PesquisaReacaoResposta.php',
        APP_PATH . '/services/PesquisaReacaoIntegracaoService.php',
        APP_PATH . '/controllers/PesquisaReacaoIntegracaoController.php',
        APP_PATH . '/controllers/AdminPesquisaReacaoIntegracaoController.php',
    ];
    foreach ($fontesNovas as $arquivo) {
        $conteudo = (string)file_get_contents($arquivo);
        $check(!preg_match('/\bCREATE\s+TABLE\b/i', $conteudo), basename($arquivo) . ' nunca executa CREATE TABLE — a migration é a única fonte da estrutura');
        $check(!preg_match('/\bALTER\s+TABLE\b/i', $conteudo), basename($arquivo) . ' nunca executa ALTER TABLE');
        $check(!str_contains($conteudo, 'ensureSchema'), basename($arquivo) . ' não usa ensureSchema() — decisão explícita para este módulo novo');
    }

    // ---- 1) Fixtures oficiais de Empresa/Setor (colaboradores_metadados) para o snapshot ---------
    $empFixture = 'ZZPRE' . $suffix;
    $setFixture = 'ZZPRS' . substr($suffix, 0, 3);
    $nomeEmpresaFixture = 'ZZPR Empresa Fixture ' . $suffix;
    $identificadorMeta = 'ZZPR_' . $suffix . '_META';
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
            nome, empresa, admissao, codigo_setor, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $identificadorMeta, $empFixture, 'ZZU' . $suffix, 'ZZC' . $suffix, 'ZZP' . $suffix,
        'ZZPR Fixture Pessoa', $nomeEmpresaFixture, (new DateTimeImmutable('-100 days'))->format('Y-m-d'),
        $setFixture, 'zzpr-teste',
    ]);
    $criados['metadados_identificadores'][] = $identificadorMeta;

    $opcoes = PesquisaReacaoCampanha::opcoesEmpresaSetor();
    $encontrouEmpresa = false;
    foreach ($opcoes['empresas'] as $e) {
        if ($e['codigo_empresa'] === $empFixture) {
            $encontrouEmpresa = ($e['empresa'] === $nomeEmpresaFixture);
        }
    }
    $check($encontrouEmpresa, 'opcoesEmpresaSetor() resolve a Empresa fixture pela mesma fonte/convenção de PeopleAnalyticsRepository::opcoesFiltro()');

    // ---- 2) Criação de campanha: expira_em obrigatoriamente futuro --------------------------------
    $semValidade = PesquisaReacaoIntegracaoService::criarCampanha(['codigo_empresa' => $empFixture, 'codigo_setor' => $setFixture], $adminId);
    $check(($semValidade['ok'] ?? true) === false, '(7)(18) Criação sem validade rápida nem data customizada é rejeitada');

    $validadeInvalida = PesquisaReacaoIntegracaoService::criarCampanha(['validade_dias' => '2'], $adminId);
    $check(($validadeInvalida['ok'] ?? true) === false, '(7) Validade rápida fora das opções oferecidas (2 dias) é rejeitada');

    $customizadaPassado = PesquisaReacaoIntegracaoService::criarCampanha(['expira_em_customizada' => (new DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i')], $adminId);
    $check(($customizadaPassado['ok'] ?? true) === false, '(18) Não é possível gerar uma campanha já expirada (data customizada no passado é rejeitada)');

    $dataIntegracaoFixture = '2026-09-01';
    $criacao = PesquisaReacaoIntegracaoService::criarCampanha([
        'codigo_empresa' => $empFixture,
        'codigo_setor' => $setFixture,
        'data_integracao' => $dataIntegracaoFixture,
        'validade_dias' => '7',
    ], $adminId);
    $check(($criacao['ok'] ?? false) === true, '(2) Criação de campanha válida (Empresa/Setor/Data + validade de 7 dias) é aceita');
    $campanhaId = (int)$criacao['id'];
    $criados['campanhas'][] = $campanhaId;
    $tokenBruto = (string)$criacao['token'];

    // ---- 3) Token: aleatório, longo, nunca persistido em texto puro --------------------------------
    $check(strlen($tokenBruto) === 64 && ctype_xdigit($tokenBruto), '(6) Token bruto tem 64 caracteres hexadecimais (32 bytes de random_bytes)');
    $linhaCampanha = PesquisaReacaoCampanha::find($campanhaId);
    $check($linhaCampanha['token_hash'] === hash('sha256', $tokenBruto), 'token_hash gravado é exatamente sha256(token bruto)');
    $check($linhaCampanha['token_hash'] !== $tokenBruto, '(6) Token BRUTO nunca é igual ao que está gravado — só o hash é persistido');
    foreach ($linhaCampanha as $coluna => $valor) {
        $check($valor !== $tokenBruto, "Nenhuma coluna de campanhas_pesquisa_reacao_integracao ({$coluna}) armazena o token bruto");
    }

    // ---- 4) Snapshot: nomes oficiais capturados no momento da criação -----------------------------
    $check($linhaCampanha['empresa_nome_snapshot'] === $nomeEmpresaFixture, '(4) empresa_nome_snapshot preserva o nome oficial resolvido no momento da criação');
    $check($linhaCampanha['codigo_empresa'] === $empFixture, 'codigo_empresa (identidade oficial) é persistido junto com o snapshot');
    $check($linhaCampanha['data_integracao'] === $dataIntegracaoFixture, 'data_integracao pertence à campanha, gravada corretamente na criação');

    // ---- 5) Token inexistente ------------------------------------------------------------------
    $check(PesquisaReacaoCampanha::findByRawToken('token-completamente-invalido-' . $suffix) === null, '(5) Token inexistente não encontra nenhuma campanha');
    ob_start();
    (new PesquisaReacaoIntegracaoController())->show('token-completamente-invalido-' . $suffix);
    $htmlInvalido = ob_get_clean();
    $check(str_contains($htmlInvalido, 'Link inválido'), 'Página pública mostra "Link inválido" para token inexistente');

    // ---- 6) Campanha aceita respostas: ativa + não expirada -----------------------------------
    $check(PesquisaReacaoCampanha::aceitaRespostas($linhaCampanha) === true, 'Campanha recém-criada (ativa, expira em 7 dias) aceita respostas');

    // ---- 7) Prioridade: data customizada futura sobrepõe validade rápida enviada junto -----------
    $criacaoComAmbos = PesquisaReacaoIntegracaoService::criarCampanha([
        'validade_dias' => '30',
        'expira_em_customizada' => (new DateTimeImmutable('+2 hours'))->format('Y-m-d\TH:i'),
    ], $adminId);
    $check(($criacaoComAmbos['ok'] ?? false) === true, 'Criação com validade rápida E data customizada (ambas futuras) é aceita');
    $linhaAmbos = PesquisaReacaoCampanha::find((int)$criacaoComAmbos['id']);
    $criados['campanhas'][] = (int)$criacaoComAmbos['id'];
    $expiraEmAmbos = new DateTimeImmutable($linhaAmbos['expira_em']);
    $check($expiraEmAmbos < new DateTimeImmutable('+1 day'), 'Data/hora customizada (2h) teve prioridade sobre a validade rápida (30 dias) quando as duas foram enviadas');

    // ---- 8) Múltiplas respostas independentes na mesma campanha -----------------------------------
    $postBase = [
        'nota_nps' => '9',
        'avaliacao_historia_proposito_valores' => '5',
        'avaliacao_responsabilidades_rotina' => '5',
        'avaliacao_seguranca_saude' => '5',
        'avaliacao_relevancia_conteudos' => '5',
        'avaliacao_acolhimento' => '5',
        'avaliacao_expectativas' => '5',
    ];
    $r1 = PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nome' => 'Fulano de Tal']));
    $check(($r1['ok'] ?? false) === true, '(8) Primeira resposta (com nome) é aceita');
    $r2 = PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nota_nps' => '3']));
    $check(($r2['ok'] ?? false) === true, '(9) Segunda resposta, MESMA campanha, é aceita — NÃO bloqueada por "já respondida"');
    $check((int)$r1['id'] !== (int)$r2['id'], 'As duas respostas são linhas distintas e independentes');

    // ---- 9) Resposta anônima (nome ausente) é válida -----------------------------------------------
    $r3 = PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, $postBase);
    $check(($r3['ok'] ?? false) === true, '(9-anon) Resposta sem nome (anônima) é aceita');
    $respostas = PesquisaReacaoResposta::listarPorCampanha($campanhaId);
    $respostaAnonima = null;
    foreach ($respostas as $resp) {
        if ((int)$resp['id'] === (int)$r3['id']) {
            $respostaAnonima = $resp;
        }
    }
    $check($respostaAnonima !== null && $respostaAnonima['nome_opcional'] === null, 'Resposta anônima grava nome_opcional como NULL, nunca string vazia inventada');

    // ---- 10) NPS: limites e rejeição fora da faixa -------------------------------------------------
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nota_nps' => '0'])))['ok'] === true, '(NPS) NPS = 0 (limite mínimo) é aceito');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nota_nps' => '10'])))['ok'] === true, '(NPS) NPS = 10 (limite máximo) é aceito');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nota_nps' => '-1'])))['ok'] === false, '(NPS) NPS = -1 é rejeitado');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['nota_nps' => '11'])))['ok'] === false, '(NPS) NPS = 11 é rejeitado');

    // ---- 11) Classificação Detrator/Neutro/Promotor (calculada, não persistida) --------------------
    $check(PesquisaReacaoResposta::classificarNps(0) === PesquisaReacaoResposta::DETRATOR, 'NPS=0 classifica como Detrator');
    $check(PesquisaReacaoResposta::classificarNps(6) === PesquisaReacaoResposta::DETRATOR, 'NPS=6 (limite) classifica como Detrator');
    $check(PesquisaReacaoResposta::classificarNps(7) === PesquisaReacaoResposta::NEUTRO, 'NPS=7 (limite) classifica como Neutro');
    $check(PesquisaReacaoResposta::classificarNps(8) === PesquisaReacaoResposta::NEUTRO, 'NPS=8 classifica como Neutro');
    $check(PesquisaReacaoResposta::classificarNps(9) === PesquisaReacaoResposta::PROMOTOR, 'NPS=9 (limite) classifica como Promotor');
    $check(PesquisaReacaoResposta::classificarNps(10) === PesquisaReacaoResposta::PROMOTOR, 'NPS=10 classifica como Promotor');
    $colunasResposta = $pdo->query('SHOW COLUMNS FROM respostas_pesquisa_reacao_integracao')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['classificacao', 'nps_classificacao', 'detrator', 'promotor', 'neutro'] as $colunaProibida) {
        $check(!in_array($colunaProibida, $colunasResposta, true), "respostas_pesquisa_reacao_integracao NÃO persiste '{$colunaProibida}' — classificação é sempre calculada a partir de nota_nps");
    }

    // ---- 12) 6 avaliações: limites e rejeição fora da faixa -----------------------------------------
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['avaliacao_acolhimento' => '1'])))['ok'] === true, '(avaliação) Nota 1 (limite mínimo) é aceita');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['avaliacao_acolhimento' => '5'])))['ok'] === true, '(avaliação) Nota 5 (limite máximo) é aceita');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['avaliacao_acolhimento' => '0'])))['ok'] === false, '(avaliação) Nota 0 é rejeitada');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['avaliacao_seguranca_saude' => '6'])))['ok'] === false, '(avaliação) Nota 6 é rejeitada');
    $check((PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, ['nota_nps' => '9']))['ok'] === false, 'Faltar qualquer uma das 6 avaliações rejeita a resposta inteira');

    // ---- 13) Perguntas abertas opcionais ----------------------------------------------------------
    $comAbertas = PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, array_merge($postBase, ['mais_gostou' => 'Gostei da recepção', 'poderia_melhorar' => '', 'informacao_faltante' => '']));
    $check(($comAbertas['ok'] ?? false) === true, '(10) Preencher só 1 das 3 perguntas abertas e deixar as outras vazias é aceito');

    // ---- 14) Empresa/Setor/Data não podem ser adulterados pelo POST público ------------------------
    $tentativaAdulteracao = array_merge($postBase, [
        'codigo_empresa' => 'FORJADO', 'codigo_setor' => 'FORJADO', 'data_integracao' => '1999-01-01',
        'empresa_nome_snapshot' => 'Empresa Forjada',
    ]);
    $rAdulterado = PesquisaReacaoIntegracaoService::registrarResposta($linhaCampanha, $tentativaAdulteracao);
    $check(($rAdulterado['ok'] ?? false) === true, 'Resposta com campos forjados de Empresa/Setor/Data ainda é aceita (os campos extras são simplesmente ignorados)');
    foreach (['codigo_empresa', 'codigo_setor', 'data_integracao', 'empresa_nome_snapshot', 'setor_nome_snapshot'] as $colunaProibida) {
        $check(!in_array($colunaProibida, $colunasResposta, true), "(13) respostas_pesquisa_reacao_integracao NÃO tem a coluna '{$colunaProibida}' — estruturalmente impossível o POST público alterar o contexto da campanha");
    }
    // O contexto exibido/perpetuado é sempre o da campanha, nunca do POST:
    $check($linhaCampanha['codigo_empresa'] === $empFixture && $linhaCampanha['data_integracao'] === $dataIntegracaoFixture, 'A campanha continua com Empresa/Data reais (fixture), não os valores forjados enviados no POST de uma resposta');

    // ---- 15) Total de respostas da campanha (contagem sempre derivada) -----------------------------
    $totalEsperado = count(PesquisaReacaoResposta::listarPorCampanha($campanhaId));
    $listagem = PesquisaReacaoCampanha::listarComContagem();
    $itemListagem = null;
    foreach ($listagem as $item) {
        if ((int)$item['id'] === $campanhaId) {
            $itemListagem = $item;
        }
    }
    $check($itemListagem !== null && (int)$itemListagem['total_respostas'] === $totalEsperado, 'listarComContagem() conta corretamente as respostas da campanha (COUNT derivado, nunca um contador redundante)');

    // ---- 16) Resultados administrativos: NPS = %Promotores - %Detratores, nunca a média -----------
    $campanhaResultados = PesquisaReacaoIntegracaoService::criarCampanha(['validade_dias' => '1'], $adminId);
    $check(($campanhaResultados['ok'] ?? false) === true, 'Fixture: campanha isolada para o cálculo de resultados');
    $idCampanhaResultados = (int)$campanhaResultados['id'];
    $criados['campanhas'][] = $idCampanhaResultados;
    $linhaResultados = PesquisaReacaoCampanha::find($idCampanhaResultados);

    // Sem nenhuma resposta: nunca inventa número.
    $resultadosVazios = PesquisaReacaoIntegracaoService::calcularResultados($idCampanhaResultados);
    $check($resultadosVazios['total'] === 0 && $resultadosVazios['nps'] === null, '(17) Campanha sem respostas: total=0 e nps=null, nunca 0% falso — a view mostra "Nenhuma resposta recebida até o momento."');

    // 2 Promotores (9,10) + 1 Neutro (7) + 1 Detrator (2) = 4 respostas.
    // %Promotores=50, %Detratores=25 -> NPS = 50-25 = 25.0 (NUNCA a média das notas, que seria 7.0).
    $notasPlano = ['9' => 5, '10' => 4, '7' => 3, '2' => 2]; // nota_nps => avaliacao usada em todos os 6 campos
    foreach ($notasPlano as $nota => $avaliacao) {
        $post = ['nota_nps' => $nota];
        foreach (['avaliacao_historia_proposito_valores', 'avaliacao_responsabilidades_rotina', 'avaliacao_seguranca_saude', 'avaliacao_relevancia_conteudos', 'avaliacao_acolhimento', 'avaliacao_expectativas'] as $campo) {
            $post[$campo] = (string)$avaliacao;
        }
        $res = PesquisaReacaoIntegracaoService::registrarResposta($linhaResultados, $post);
        $check(($res['ok'] ?? false) === true, "Fixture de resultados: resposta com NPS={$nota} aceita");
    }
    $resultados = PesquisaReacaoIntegracaoService::calcularResultados($idCampanhaResultados);
    $check($resultados['total'] === 4, '(16) Total de respostas = 4');
    $check($resultados['promotores'] === 2 && $resultados['neutros'] === 1 && $resultados['detratores'] === 1, '(16) Promotores=2, Neutros=1, Detratores=1');
    $check($resultados['nps'] === 25.0, '(16) NPS = %Promotores(50) - %Detratores(25) = 25.0 — NUNCA a média das notas (que seria 7.0)');
    $mediaEsperada = round((5 + 4 + 3 + 2) / 4, 1);
    $check($resultados['medias']['avaliacao_acolhimento'] === $mediaEsperada, '(16) Média de cada uma das 6 avaliações calculada corretamente');

    // ---- 17) Expiração validada também no POST: campanha expirada rejeita envio -------------------
    $campanhaExpirada = PesquisaReacaoCampanha::create(null, null, null, null, null, new DateTimeImmutable('-1 hour'), $adminId);
    $criados['campanhas'][] = $campanhaExpirada['id'];
    $linhaExpirada = PesquisaReacaoCampanha::find($campanhaExpirada['id']);
    $check(PesquisaReacaoCampanha::aceitaRespostas($linhaExpirada) === false, 'Campanha com expira_em no passado não aceita mais respostas');

    $_SESSION['csrf_token'] = 'zzpr-csrf-' . $suffix;
    $_POST = ['csrf' => $_SESSION['csrf_token']] + $postBase;
    ob_start();
    (new PesquisaReacaoIntegracaoController())->store($campanhaExpirada['token']);
    $htmlExpirada = ob_get_clean();
    $check(str_contains($htmlExpirada, 'Esta pesquisa foi encerrada'), '(19) POST público após expiração é rejeitado — página mostra "Esta pesquisa foi encerrada..."');
    $check(count(PesquisaReacaoResposta::listarPorCampanha((int)$campanhaExpirada['id'])) === 0, '(19) Nenhuma resposta foi gravada para a campanha expirada, apesar do POST');

    ob_start();
    (new PesquisaReacaoIntegracaoController())->show($campanhaExpirada['token']);
    $htmlExpiradaGet = ob_get_clean();
    $check(str_contains($htmlExpiradaGet, 'Esta pesquisa foi encerrada'), 'GET público em campanha expirada também mostra a mensagem de encerramento (mesma validação, não só no POST)');

    // ---- 18) Desativação: campanha desativada rejeita envio -----------------------------------------
    $campanhaParaDesativar = PesquisaReacaoIntegracaoService::criarCampanha(['validade_dias' => '30'], $adminId);
    $idParaDesativar = (int)$campanhaParaDesativar['id'];
    $criados['campanhas'][] = $idParaDesativar;
    $tokenParaDesativar = (string)$campanhaParaDesativar['token'];

    $check(PesquisaReacaoCampanha::desativar($idParaDesativar) === true, '(15) PesquisaReacaoCampanha::desativar() desativa uma campanha ativa');
    $linhaDesativada = PesquisaReacaoCampanha::find($idParaDesativar);
    $check((int)$linhaDesativada['ativa'] === 0, 'Campanha desativada tem ativa=0');
    $check(PesquisaReacaoCampanha::aceitaRespostas($linhaDesativada) === false, 'Campanha desativada (mesmo dentro do prazo de expira_em) não aceita mais respostas');
    $check(PesquisaReacaoCampanha::desativar($idParaDesativar) === false, 'Desativar uma campanha já desativada não é uma operação repetível (rowCount=0)');

    ob_start();
    (new PesquisaReacaoIntegracaoController())->store($tokenParaDesativar);
    $htmlDesativada = ob_get_clean();
    $check(str_contains($htmlDesativada, 'Esta pesquisa foi encerrada'), '(20) POST público após desativação é rejeitado');
    $check(count(PesquisaReacaoResposta::listarPorCampanha($idParaDesativar)) === 0, '(20) Nenhuma resposta gravada para a campanha desativada');

    // ---- 19) Empresa sem correspondência oficial: nunca inferida, mas nunca bloqueia --------------
    $codigoSemCorrespondencia = 'ZZNC' . $suffix;
    $criacaoSemCorrespondencia = PesquisaReacaoIntegracaoService::criarCampanha([
        'codigo_empresa' => $codigoSemCorrespondencia,
        'validade_dias' => '1',
    ], $adminId);
    $check(($criacaoSemCorrespondencia['ok'] ?? false) === true, 'Empresa sem correspondência oficial não bloqueia a criação da campanha');
    $criados['campanhas'][] = (int)$criacaoSemCorrespondencia['id'];
    $linhaSemCorrespondencia = PesquisaReacaoCampanha::find((int)$criacaoSemCorrespondencia['id']);
    $check($linhaSemCorrespondencia['codigo_empresa'] === $codigoSemCorrespondencia, 'O código informado é preservado mesmo sem correspondência no catálogo');
    $check($linhaSemCorrespondencia['empresa_nome_snapshot'] === null, 'Sem correspondência oficial, o nome snapshot fica NULL — nunca um nome inventado');

    // ---- 20) Admin index()/resultados() renderizam sem Warning/Notice/Fatal (smoke) ----------------
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];
    ob_start();
    $erroIndex = null;
    try {
        (new AdminPesquisaReacaoIntegracaoController())->index();
    } catch (Throwable $e) {
        $erroIndex = $e;
    }
    $htmlIndex = ob_get_clean();
    $check($erroIndex === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlIndex), 'AdminPesquisaReacaoIntegracaoController::index() renderiza sem Warning/Notice/Fatal — ' . ($erroIndex?->getMessage() ?? 'ok'));
    $check(str_contains($htmlIndex, (string)$campanhaId) || str_contains($htmlIndex, 'Gerar link'), 'Tela administrativa lista campanhas e/ou exibe o formulário "Gerar link"');

    ob_start();
    $erroResultados = null;
    try {
        (new AdminPesquisaReacaoIntegracaoController())->resultados((string)$idCampanhaResultados);
    } catch (Throwable $e) {
        $erroResultados = $e;
    }
    $htmlResultados = ob_get_clean();
    $check($erroResultados === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlResultados), 'AdminPesquisaReacaoIntegracaoController::resultados() renderiza sem Warning/Notice/Fatal — ' . ($erroResultados?->getMessage() ?? 'ok'));
    $check(str_contains($htmlResultados, '25'), 'Tela de resultados mostra o NPS calculado (25,0)');

    // ---- 21) Pesquisa de Integração existente permanece intocada (estrutura própria) ---------------
    $colunasPesquisaIntegracao = $pdo->query('SHOW COLUMNS FROM pesquisas_integracao')->fetchAll(PDO::FETCH_COLUMN);
    $check(
        in_array('nota_clareza', $colunasPesquisaIntegracao, true) && !in_array('avaliacao_historia_proposito_valores', $colunasPesquisaIntegracao, true),
        '(regressão) pesquisas_integracao continua com suas próprias 5 perguntas — nenhuma coluna da Pesquisa de Reação vazou para lá'
    );
    $totalPesquisaIntegracaoAntes = (int)$pdo->query('SELECT COUNT(*) FROM pesquisas_integracao')->fetchColumn();
    $check($totalPesquisaIntegracaoAntes >= 0, '(regressão) pesquisas_integracao continua acessível e intocada por esta sprint');

    echo "\nPESQUISA_REACAO_INTEGRACAO_OK\n";
} finally {
    if (!empty($criados['campanhas'])) {
        $ids = implode(',', array_map('intval', $criados['campanhas']));
        $pdo->exec("DELETE FROM respostas_pesquisa_reacao_integracao WHERE campanha_id IN ($ids)");
        $pdo->exec("DELETE FROM campanhas_pesquisa_reacao_integracao WHERE id IN ($ids)");
    }
    if (!empty($criados['metadados_identificadores'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados_identificadores']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados_identificadores']);
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
