<?php

/**
 * Integração — Etapa 2C.1: estrutura do perfil GESTOR (role `gestor`) — reconhecimento, autenticação, sessão, Autoridade, política
 * de permissões, resolvedor central de escopo setorial (EscopoSetorial), rotas e navegação.
 * Requer o ENUM migrado no banco de DESENVOLVIMENTO (database/migrations/2026-11-22-usuarios-role-gestor.sql); a compatibilidade com
 * o ENUM ANTIGO é provada com `Autoridade::forcarSchemaGestorParaTeste(false)` (e a migration em schema isolado:
 * integration_migration_role_gestor.php). Requisições HTTP em processos próprios (fixture_requisicao_http.php).
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
if (!Autoridade::schemaSuportaGestor()) {
    fwrite(STDERR, "O banco de desenvolvimento não tem a migration 2026-11-22-usuarios-role-gestor.sql aplicada.\n");
    exit(1);
}

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$senhaClara = 'SenhaForte#2026x';
$senha = password_hash($senhaClara, PASSWORD_BCRYPT);
$c = ['usuarios' => [], 'setores' => [], 'metadados' => []];
$permId = [];
foreach ($pdo->query('SELECT id, codigo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $permId[$r['codigo']] = (int)$r['id'];
}

$novo = static function (string $apelido, string $role, int $flag = 0, array $perms = []) use ($pdo, $senha, $suffix, &$c, $permId): int {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(['ZZG ' . $apelido, strtolower($apelido) . '.zzg.' . $suffix . '@teste.local', $senha, $role, $flag]);
    $id = (int)$pdo->lastInsertId();
    $c['usuarios'][] = $id;
    foreach ($perms as $p) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$id, $permId[$p]]);
    }
    return $id;
};
$mkSetor = static function (string $codigo) use ($pdo, &$c): int {
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados) VALUES (?, ?, ?, ?, 1, 'A', 'zzg-teste')")
        ->execute([$codigo, 'ZZG Setor ' . $codigo, 'ZZG Setor ' . $codigo, 'zzg-' . strtolower($codigo) . '-' . uniqid()]);
    $id = (int)$pdo->lastInsertId();
    $c['setores'][] = $id;
    return $id;
};
$mkContrato = static function (string $codigoSetor, string $nome, float $salario) use ($pdo, &$c, $suffix): int {
    static $i = 0;
    $i++;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa, codigo_setor, setor, cargo, codigo_cargo, salario_atual, admissao, ativo, origem_metadados)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute(["ZZG{$suffix}-{$i}", 'ZZGEMP', 'UNI', "ZZGC{$suffix}{$i}", "ZZGP{$suffix}{$i}", $nome, 'ZZG Empresa', $codigoSetor, 'ZZG Setor ' . $codigoSetor, 'ZZG Cargo', 'ZZGCG', $salario, date('Y-m-d', strtotime('-40 days')), 'zzg-teste']);
    $id = (int)$pdo->lastInsertId();
    $c['metadados'][] = $id;
    return $id;
};
$requisitar = static function (int $userId, string $role, bool $sess, string $controller, string $metodo, array $post = [], array $args = [], array $get = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sess ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)],
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
$linha = static fn(int $id): array => $pdo->query('SELECT role, is_supervisor, email_verified_at FROM usuarios WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
$gravadas = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT p.codigo FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = ?');
    $st->execute([$id]);
    $r = $st->fetchAll(PDO::FETCH_COLUMN);
    sort($r);
    return $r;
};

try {
    // ---- fixtures ----------------------------------------------------------------------------------------------------------
    $sA = $mkSetor('ZA' . substr($suffix, -4));
    $sB = $mkSetor('ZB' . substr($suffix, -4));
    $cA = 'ZA' . substr($suffix, -4);
    $cB = 'ZB' . substr($suffix, -4);
    $pessoaA = $mkContrato($cA, 'ZZG PESSOA SETOR A', 5111.11);
    $pessoaB = $mkContrato($cB, 'ZZG PESSOA SETOR B', 7222.22);
    $svc = new UsuarioSetoresGerenciadosService();

    $permsGestor = ['colaboradores.visualizar', 'avaliacao_experiencia.visualizar'];
    $sg = $novo('sg', 'admin', 1);
    $adm = $novo('adm', 'admin');
    $rh = $novo('rh', 'rh');
    $g1 = $novo('g1', 'gestor', 0, $permsGestor);                  // 1 setor
    $g2 = $novo('g2', 'gestor', 0, $permsGestor);                  // 2 setores
    $g0 = $novo('g0', 'gestor', 0, $permsGestor);                  // sem setores
    $gFlag = $novo('gflag', 'gestor', 1, $permsGestor);            // gestor com is_supervisor=1
    $gTudo = $novo('gtudo', 'gestor', 0, array_merge($permsGestor, ['avaliacao_experiencia.avaliar', 'pdi.visualizar', 'pdi.gerenciar', 'feedback.avaliar', 'avaliacao_desempenho.avaliar', 'solicitacao_vaga.criar', 'kanban_vagas.movimentar', 'integracao_colaborador.visualizar', 'candidaturas.visualizar', 'pipeline.visualizar', 'indicacoes.visualizar', 'entrevista_desligamento.visualizar', 'pesquisa_reacao_integracao.visualizar', 'mensagens.visualizar', 'recruitment_webhooks.visualizar', 'empresas.visualizar', 'dashboard.visualizar']));
    $gSem = $novo('gsem', 'gestor', 0, []);                        // sem permissões
    $vLegado = $novo('vleg', 'viewer', 0, ['colaboradores.visualizar']);
    $vAlvo = $novo('valvo', 'viewer');
    $rhAlvo = $novo('rhalvo', 'rh');
    $svc->definirSetoresGerenciados($g1, [$sA]);
    $svc->definirSetoresGerenciados($g2, [$sA, $sB]);
    $svc->definirSetoresGerenciados($gFlag, [$sA]);
    $svc->definirSetoresGerenciados($gTudo, [$sA]);
    $svc->definirSetoresGerenciados($vLegado, [$sA]);
    // distratores: gestor_usuario_id e usuario_setores (setor de atuação) NÃO são fonte de escopo
    $pdo->prepare('UPDATE usuarios SET gestor_usuario_id = ? WHERE id = ?')->execute([$g2, $g0]);
    $pdo->prepare("INSERT INTO usuario_setores (usuario_id, setor_id, principal, origem) VALUES (?, ?, 1, 'MANUAL')")->execute([$g0, $sB]);

    // ---- 1. reconhecimento da role / rejeição de desconhecida -----------------------------------------------------------
    $check(Autoridade::roleConhecida('gestor') && Autoridade::roleConhecida('GESTOR ') && !Autoridade::roleConhecida('superadmin') && !Autoridade::roleConhecida('candidate'), '(1) gestor é perfil conhecido; valor desconhecido não é');
    $check(Autoridade::nivel(User::findById($g1)) === Autoridade::GESTOR && Autoridade::nivel(User::findById($gFlag)) === Autoridade::GESTOR, '(1) nível Gestor (com ou sem is_supervisor)');
    $check(in_array('gestor', Autoridade::rolesAtribuiveis(), true) && Auth::roleAceitaNoLogin('gestor') && !Auth::roleAceitaNoLogin('superadmin') && Auth::roleAceitaNoLogin(''), '(2) perfil desconhecido é recusado no login; vazio (legado) mantém o tratamento antigo; gestor é aceito');
    $check(Autoridade::privilegioInconsistente(User::findById($gFlag)) && !Autoridade::ehSupervisorGeral(User::findById($gFlag)), '(13) gestor com is_supervisor=1 é configuração inconsistente e NÃO é Supervisor Geral');

    // ---- 19. compatibilidade com o ENUM antigo (schema sem gestor) --------------------------------------------------------
    Autoridade::forcarSchemaGestorParaTeste(false);
    $check(!Autoridade::gestorDisponivel() && Autoridade::rolesAtribuiveis() === ['admin', 'rh', 'viewer'] && !PermissaoPerfilPolicy::gestorAtivo(), '(19) schema antigo: gestor indisponível, perfis atribuíveis = admin/rh/viewer');
    $check(!Autoridade::podeAtribuirRole(User::findById($sg), User::findById($vAlvo), 'gestor') && !User::attemptRoleUpdate($vAlvo, 'gestor', User::findById($sg), '127.0.0.1') && $linha($vAlvo)['role'] === 'viewer', '(19) schema antigo: nem Autoridade nem User::attemptRoleUpdate gravam gestor (nada é tentado no ENUM antigo)');
    $lancou = false;
    try {
        User::create('X', 'x.' . $suffix . '@t.local', $senha, 'gestor');
    } catch (InvalidArgumentException) {
        $lancou = true;
    }
    $check($lancou, '(19) schema antigo: User::create recusa gestor antes de tocar no banco');
    $check(PermissaoPerfilPolicy::classificar('gestor', 'colaboradores.visualizar') === PermissaoPerfilPolicy::INCOMPATIVEL && PermissaoPerfilPolicy::permissoesPermitidas('gestor') === [], '(19) schema antigo: política nega tudo ao gestor');
    Autoridade::forcarSchemaGestorParaTeste(null);
    $check(Autoridade::gestorDisponivel() && PermissaoPerfilPolicy::gestorAtivo(), '(19) schema migrado: gestor disponível');

    // ---- 3. autenticação e sessão ---------------------------------------------------------------------------------------
    $_SESSION = [];
    $login = Auth::attemptLogin(User::findById($g1)->email, $senhaClara);
    $check(($login['ok'] ?? false) === true && ($_SESSION['user_role'] ?? '') === 'gestor' && empty($_SESSION['user_is_supervisor']), '(3) autenticação do Gestor: sessão com role gestor (não vira viewer), sem supervisor');
    $check(Auth::canAccessAdmin() && Authorization::primeiraRotaAcessivel() === '/admin', '(3) Gestor acessa a área logada; destino inicial = Central');
    $_SESSION = [];
    $login = Auth::attemptLogin(User::findById($gFlag)->email, $senhaClara);
    $check(($login['ok'] ?? false) === true && ($_SESSION['user_role'] ?? '') === 'gestor' && empty($_SESSION['user_is_supervisor']), '(13) gestor com is_supervisor=1: a sessão NÃO recebe privilégio de supervisor');
    $check(!Authorization::temAcessoPorRoleOuPermissao(['admin', 'rh'], 'qualquer.codigo'), '(13) o flag não dá acesso a gates de admin/RH');
    $_SESSION = [];

    // ---- 7. resolvedor central de escopo ----------------------------------------------------------------------------------
    $E = 'EscopoSetorial';
    $eg1 = EscopoSetorial::resolver(['id' => $g1, 'role' => 'gestor']);
    $eg2 = EscopoSetorial::resolver($g2);
    $eg0 = EscopoSetorial::resolver(['id' => $g0, 'role' => 'gestor']);
    $check($eg1->ehRestrito() && $eg1->codigos() === [$cA] && $eg1->permiteSetor($cA) && !$eg1->permiteSetor($cB), '(7) Gestor com 1 setor: exatamente esse setor');
    $check($eg2->ehRestrito() && count($eg2->codigos()) === 2 && $eg2->permiteSetor($cA) && $eg2->permiteSetor($cB), '(8) Gestor com 2 setores: os dois');
    $check($eg0->ehRestrito() && !$eg0->ehGlobal() && !$eg0->enxergaAlgo() && $eg0->codigos() === [] && $eg0->codigosOuNull() === [] && !$eg0->permiteSetor($cA) && !$eg0->permiteSetor(null), '(9) Gestor SEM setores: conjunto vazio — não enxerga nada e NUNCA é "sem restrição"');
    $params = [];
    $check($eg0->sql('m.codigo_setor', $params) === '1 = 0' && $params === [], '(9) SQL do escopo vazio = 1 = 0 (jamais sem filtro)');
    $params = [];
    $check($eg2->sql('m.codigo_setor', $params) === 'm.codigo_setor IN (?, ?)' && count($params) === 2, '(8) SQL parametrizado para múltiplos setores');
    $check($eg0->tipo !== $E::GLOBAL && EscopoSetorial::nenhum()->semAutorizacao() && !EscopoSetorial::nenhum()->enxergaAlgo(), '(7) escopo vazio, nenhum e global são situações distintas');
    $check(EscopoSetorial::resolver(['id' => $sg, 'role' => 'admin'])->ehGlobal() && EscopoSetorial::resolver(['id' => $adm, 'role' => 'admin'])->ehGlobal() && EscopoSetorial::resolver(['id' => $rh, 'role' => 'rh'])->ehGlobal(), '(16/17) Supervisor Geral, Administrador e RH: escopo global');
    $paramsG = [];
    $check(EscopoSetorial::resolver($rh)->sql('x', $paramsG) === '1 = 1' && EscopoSetorial::resolver($rh)->codigosOuNull() === null, '(16) global: sem restrição (null)');
    $check(EscopoSetorial::resolver(['id' => $gFlag, 'role' => 'gestor'])->codigos() === [$cA], '(13) is_supervisor não altera o escopo do gestor');
    $check($eg0->codigos() === [] && EscopoSetorial::resolver(['id' => $g0, 'role' => 'gestor'])->enxergaAlgo() === false, '(9) gestor_usuario_id e usuario_setores (setor de atuação) NÃO são fonte de escopo');
    $check(EscopoSetorial::resolver(['id' => $vLegado, 'role' => 'viewer'])->codigos() === [$cA] && EscopoSetorial::resolver(['id' => $vLegado, 'role' => 'viewer'], false)->semAutorizacao(), '(18) viewer: restrito aos setores só na transição; na arquitetura definitiva = sem autorização');
    $check(EscopoSetorial::resolver(['id' => 0, 'role' => 'gestor'])->semAutorizacao() && EscopoSetorial::resolver(['id' => $g1, 'role' => 'inventada'])->semAutorizacao() && EscopoSetorial::resolver(null)->semAutorizacao() && EscopoSetorial::resolver(['id' => $g1, 'role' => ''])->semAutorizacao(), '(7) ator inexistente, role desconhecida ou vazia: sem autorização');

    // ---- 8. política de permissões do Gestor ----------------------------------------------------------------------------
    $check(PermissaoPerfilPolicy::permissoesPermitidas('gestor') === ['colaboradores.visualizar', 'avaliacao_experiencia.visualizar', 'avaliacao_desempenho.visualizar', 'avaliacao_desempenho.avaliar', 'feedback.visualizar', 'feedback.avaliar', 'pdi.visualizar', 'pdi.gerenciar', 'pdi.acompanhar', 'avaliacao_experiencia.avaliar', 'solicitacao_vaga.visualizar', 'solicitacao_vaga.criar', 'kanban_vagas.visualizar', 'kanban_vagas.detalhes', 'kanban_vagas.movimentar', 'solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar', 'vagas.visualizar', 'candidaturas.visualizar'] && PermissaoPerfilPolicy::modeloRecomendado('gestor') === array_values(array_diff(PermissaoPerfilPolicy::permissoesPermitidas('gestor'), ['solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar', 'vagas.visualizar', 'candidaturas.visualizar'])), '(política) Gestor (2C.2C/Etapa 3): Colaboradores, Experiência, Desempenho, Feedback, PDI, Solicitação de Vaga e Kanban — escopo setorial comprovado; modelo = operacional, sem aprovação de líder e sem dashboard agregado');
    $tem = static fn(int $u, string $p): bool => Authorization::usuarioTemPermissao($u, $p);
    $check($tem($gTudo, 'colaboradores.visualizar') && $tem($gTudo, 'avaliacao_experiencia.visualizar'), '(política) permissões autorizadas valem para o Gestor');
    $bloqueadas = ['integracao_colaborador.visualizar', 'candidaturas.editar', 'pipeline.visualizar', 'indicacoes.visualizar', 'entrevista_desligamento.visualizar', 'pesquisa_reacao_integracao.visualizar', 'mensagens.visualizar', 'recruitment_webhooks.visualizar', 'empresas.visualizar', 'dashboard.visualizar'];
    $check(array_reduce($bloqueadas, static fn(bool $ok, string $p) => $ok && !$tem($gTudo, $p), true), '(6) todas as permissões de módulos não preparados/administrativos, mesmo GRAVADAS no Gestor, não concedem acesso');
    $check(!$tem($gSem, 'colaboradores.visualizar'), '(política) gestor sem permissão não tem nada');
    $check(count($gravadas($gTudo)) === 19, '(legado) as permissões gravadas continuam armazenadas (nada apagado)');
    $v = PermissaoPerfilPolicy::validarSelecao('gestor', [$permId['colaboradores.visualizar'], $permId['integracao_colaborador.visualizar']], []);
    $check(!$v['ok'] && $v['incompativeis'] === ['integracao_colaborador.visualizar'], '(política) seleção de permissões do Gestor com módulo fechado (Integração do Colaborador) é recusada');
    $liberadas2c2a = ['pdi.visualizar', 'pdi.gerenciar', 'feedback.avaliar', 'avaliacao_desempenho.avaliar']; // as que o gTudo tem gravadas
    $check(array_reduce($liberadas2c2a, static fn(bool $ok, string $p) => $ok && $tem($gTudo, $p), true), '(2C.2A) PDI, Feedback e Desempenho valem para o Gestor (o escopo por LINHA é provado em integration_gestor_escopo_desempenho_feedback_pdi)');

    // ---- 4/5/6. rotas e navegação ----------------------------------------------------------------------------------------
    [$st] = $requisitar($g1, 'gestor', false, 'AdminCentralController', 'index');
    $check($st === 200, '(5) Gestor abre a Central');
    [$st, $html] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'index');
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR A') && !str_contains($html, 'ZZG PESSOA SETOR B'), '(7) Gestor com 1 setor: vê a pessoa do próprio setor e NÃO a de outro');
    $check(!str_contains($html, '5.111,11') && !str_contains($html, '5111') && !str_contains($html, '7222'), '(7) sem salário na listagem do Gestor');
    [$st, $html] = $requisitar($g2, 'gestor', false, 'AdminColaboradoresController', 'index');
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR A') && str_contains($html, 'ZZG PESSOA SETOR B'), '(8) Gestor com 2 setores: vê os dois');
    [$st, $html] = $requisitar($g0, 'gestor', false, 'AdminColaboradoresController', 'index');
    $check($st === 200 && !str_contains($html, 'ZZG PESSOA SETOR'), '(9) Gestor sem setores: nenhum dado setorial é retornado');
    [$st, $html] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'index', [], [], ['setor' => $cB, 'q' => 'PESSOA SETOR B']);
    $check(!str_contains($html, 'ZZG PESSOA SETOR B'), '(10) filtro/busca por URL apontando para outro setor não revela o registro');
    [$st, $html] = $requisitar($g1, 'gestor', false, 'AdminColaboradoresController', 'index', [], [], ['setor' => 'ZZG Setor ' . $cB, 'empresa' => 'ZZG Empresa', 'situacao' => '', 'per_page' => '100']);
    $check(!str_contains($html, 'ZZG PESSOA SETOR B'), '(10) filtro pelo NOME do setor alheio também não revela o registro');
    [$st, $html] = $requisitar($adm, 'admin', false, 'AdminColaboradoresController', 'index', [], [], ['q' => 'ZZG PESSOA']);
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR A') && str_contains($html, 'ZZG PESSOA SETOR B'), '(16) Administrador mantém o acesso global');
    [$st, $html] = $requisitar($rh, 'rh', false, 'AdminColaboradoresController', 'index', [], [], ['q' => 'ZZG PESSOA']);
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR A') && str_contains($html, 'ZZG PESSOA SETOR B'), '(16) RH mantém o acesso global');
    [$st, $html] = $requisitar($sg, 'admin', true, 'AdminColaboradoresController', 'index', [], [], ['q' => 'ZZG PESSOA']);
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR B'), '(17) Supervisor Geral mantém o acesso global');
    [$st, $html] = $requisitar($vLegado, 'viewer', false, 'AdminColaboradoresController', 'index', [], [], ['q' => 'ZZG PESSOA']);
    $check($st === 200 && str_contains($html, 'ZZG PESSOA SETOR A') && !str_contains($html, 'ZZG PESSOA SETOR B'), '(18) viewer com a permissão e setor (transição) mantém o que já tinha — e sem outros setores');
    [$st] = $requisitar($gSem, 'gestor', false, 'AdminColaboradoresController', 'index');
    $check($st === 403, '(5) Gestor sem a permissão colaboradores.visualizar: 403');

    // Avaliação de Experiência
    [$st] = $requisitar($g1, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'index');
    $check($st === 200, '(5) Gestor com avaliacao_experiencia.visualizar abre Avaliações de Experiência');
    [$st] = $requisitar($gSem, 'gestor', false, 'AdminAvaliacoesExperienciaController', 'index');
    $check($st === 403, '(5) Gestor sem a permissão: 403');
    $ator1 = ['id' => $g1, 'role' => 'gestor'];
    $sx = new AvaliacaoExperienciaService();
    $ctxA = $sx->contratoParaAvaliar($pessoaA, '45', $ator1);
    $ctxB = $sx->contratoParaAvaliar($pessoaB, '45', $ator1);
    $check(($ctxA['ok'] ?? false) === true, '(5) Gestor consulta o contrato do próprio setor');
    $check(($ctxB['ok'] ?? true) === false && str_contains((string)($ctxB['error'] ?? ''), 'acesso'), '(10) acesso direto por ID a contrato de OUTRO setor: negado');
    $rB = $sx->salvarRascunho($pessoaB, '45', ['x' => 1], $ator1, new DateTimeImmutable('now'), '127.0.0.1');
    $rA = $sx->salvarRascunho($pessoaA, '45', ['x' => 1], $ator1, new DateTimeImmutable('now'), '127.0.0.1');
    $check(($rB['ok'] ?? true) === false && ($rA['ok'] ?? true) === false, '(12) Gestor não grava avaliação (permissão avaliar indisponível) — nem fora nem dentro do escopo');
    $check(EscopoSetorial::resolver($ator1)->codigosOuNull() === AvaliacaoExperienciaService::escopoSetores($ator1) && AvaliacaoExperienciaService::escopoTotal(['id' => $rh, 'role' => 'rh']) && !AvaliacaoExperienciaService::escopoTotal($ator1), '(escopo) Avaliação de Experiência usa o resolvedor central');
    $lista = $sx->listarPendencias([], ['id' => $g0, 'role' => 'gestor'], new DateTimeImmutable('now'));
    $check(json_encode($lista) !== false && !str_contains((string)json_encode($lista), 'ZZG PESSOA'), '(9) pendências de Avaliação de Experiência do Gestor sem setores: nada');
    $lista1 = $sx->listarPendencias([], $ator1, new DateTimeImmutable('now'));
    $json1 = (string)json_encode($lista1);
    $check(!str_contains($json1, 'ZZG PESSOA SETOR B'), '(7) pendências do Gestor com 1 setor nunca trazem o outro setor');

    // 6. módulos não preparados: 403 por requisição direta, mesmo com permissões gravadas
    $bloqueio = [
        ['AdminRhIndicadoresController', 'index'], ['AdminController', 'index'], ['AdminController', 'dadosDashboard'], ['AdminController', 'exportarColaboradores'],
        ['AdminDashboardTurnoverController', 'index'], ['AdminDashboardRecrutamentoController', 'index'], ['AdminDashboardEntrevistaDesligamentoController', 'index'],
        ['AdminPipelineController', 'index'], ['AdminIndicacoesController', 'index'], ['AdminIndicacoesController', 'contasReceberApi'],
        ['AdminEntrevistaDesligamentoController', 'index'], ['AdminPesquisaReacaoIntegracaoController', 'index'], ['AdminMensagensController', 'index'],
        ['AdminPesquisaIntegracaoQrController', 'index'],
        ['AdminMovimentacoesPessoalController', 'index'], ['AdminVagasController', 'create'], ['AdminSetoresController', 'index'], ['AdminBeneficiosController', 'index'],
        ['AdminAvaliacoesController', 'index'], ['AdminManualController', 'index'], ['AdminIntegracoesController', 'index'],
    ];
    $furos = [];
    foreach ($bloqueio as [$ctrl, $m]) {
        [$st, $corpo] = $requisitar($gTudo, 'gestor', false, $ctrl, $m);
        if ($st !== 403 || str_contains($corpo, 'ZZG PESSOA')) {
            $furos[] = "{$ctrl}::{$m}={$st}";
        }
    }
    $check($furos === [], '(6) Gestor (mesmo com 19 permissões legadas gravadas) recebe 403 em ' . count($bloqueio) . ' rotas de módulos não preparados/administrativos' . ($furos === [] ? '' : ' — FUROS: ' . implode(', ', $furos)));
    $furos = [];
    foreach ($bloqueio as [$ctrl, $m]) {
        [$st] = $requisitar($gFlag, 'gestor', true, $ctrl, $m);
        if ($st !== 403) {
            $furos[] = "{$ctrl}::{$m}={$st}";
        }
    }
    $check($furos === [], '(13) gestor com is_supervisor=1 (inclusive na sessão): as mesmas rotas seguem 403' . ($furos === [] ? '' : ' — FUROS: ' . implode(', ', $furos)));
    [$st] = $requisitar($g1, 'gestor', false, 'AdminController', 'exportarColaboradores', [], [], ['empresa' => 'ZZG Empresa']);
    $check($st === 403, '(11) exportação nominal (CSV) negada ao Gestor — nenhum dado de outro setor sai');

    // hub Avaliações e Desenvolvimento: landing aberta por desenho, mas só mostra a seção cujas permissões o Gestor tem (escopo setorial no serviço)
    [$st, $hub] = $requisitar($gTudo, 'gestor', false, 'AdminAvaliacoesDesenvolvimentoController', 'index');
    $check($st === 200 && !str_contains($hub, 'ZZG PESSOA SETOR B'), '(6) hub Avaliações e Desenvolvimento: 200 e sem nenhum dado do setor que o Gestor não gerencia (Feedback/PDI/Desempenho entram com escopo — 2C.2A)');
    // navegação
    $_SESSION = ['user' => true, 'user_id' => $g1, 'user_role' => 'gestor', 'user_is_supervisor' => false];
    $nav = new PortalNavegacaoService();
    $modulos = array_column($nav->modulos(), 'chave');
    sort($modulos);
    $check($modulos === ['avaliacoes-desenvolvimento', 'colaboradores'], '(4) navegação do Gestor: só Colaboradores e Avaliações e Desenvolvimento (' . implode(',', $modulos) . ')');
    $check(array_column($nav->abas('pessoas'), 'chave') === ['colaboradores'] && array_column($nav->abas('avaliacoes-desenvolvimento'), 'chave') === ['resumo', 'avaliacao-experiencia'], '(4) abas do Gestor: Colaboradores, o Resumo (só a seção de Experiência) e Avaliação de Experiência — nenhuma leva a 403');
    foreach (['cadastros', 'recrutamento', 'indicadores', 'configuracoes', 'integracao', 'desligamento'] as $m) {
        $check($nav->abas($m) === [], "(4) o Gestor não vê abas de {$m}");
    }
    $_SESSION = ['user' => true, 'user_id' => $gTudo, 'user_role' => 'gestor', 'user_is_supervisor' => false];
    $navT = new PortalNavegacaoService();
    $mt = array_column($navT->modulos(), 'chave');
    sort($mt);
    $check($mt === ['avaliacoes-desenvolvimento', 'colaboradores', 'recrutamento', 'solicitacoes-vaga'] && array_column($navT->abas('recrutamento'), 'chave') === ['solicitacoes', 'candidaturas'], '(4) a navegação não mostra módulos bloqueados (Integração, Cadastros, Configurações) nem para o Gestor com permissões legadas gravadas; Recrutamento (Etapa 3.2C.1) só com Solicitações de Vaga e Candidaturas (este Gestor tem candidaturas.visualizar gravada, mas não vagas.visualizar), sem Vagas/Pipeline/Indicações [módulos: ' . implode(',', $mt) . ' | abas: ' . implode(',', array_column($navT->abas('recrutamento'), 'chave')) . ']');
    $_SESSION = [];

    // ---- 14/15. Autoridade: o Gestor não administra ---------------------------------------------------------------------
    foreach ([[$g1, false, 'gestor'], [$gFlag, true, 'gestor com is_supervisor=1']] as [$ator, $sess, $rot]) {
        $antesAlvo = $linha($vAlvo);
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'updateRole', ['role' => 'admin'], [(string)$vAlvo]);
        $check($st === 403 && $linha($vAlvo) === $antesAlvo, "(14) {$rot}: tentar alterar perfil de outro usuário é negado");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'updateRole', ['role' => 'admin'], [(string)$ator]);
        $check($st === 403 && $linha($ator)['role'] === 'gestor', "(14) {$rot}: tentar elevar o próprio perfil é negado");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'index');
        $check($st === 403, "(15) {$rot}: não acessa a administração de usuários");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'show', [], [(string)$vAlvo]);
        $check($st === 403, "(15) {$rot}: não vê o detalhe de usuário");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => [$permId['colaboradores.visualizar']]], [(string)$vAlvo]);
        $check($st === 403 && $gravadas($vAlvo) === [], "(15) {$rot}: não concede permissões");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'alterarSupervisorGeral', ['acao' => 'conceder'], [(string)$adm]);
        $check($st === 403 && (int)$linha($adm)['is_supervisor'] === 0, "(14) {$rot}: não altera is_supervisor");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'updateStatus', ['active' => '0'], [(string)$vAlvo]);
        $check($st === 403 && $linha($vAlvo)['email_verified_at'] !== null, "(15) {$rot}: não desativa contas");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'delete', [], [(string)$vAlvo]);
        $check($st === 403 && $pdo->query("SELECT 1 FROM usuarios WHERE id = {$vAlvo}")->fetchColumn() !== false, "(15) {$rot}: não exclui contas");
        [$st] = $requisitar($ator, 'gestor', $sess, 'AdminUsuariosController', 'adminChangePasswordApi', ['new_password' => 'Qw7!zK9#mPx2'], [(string)$vAlvo]);
        $check($st === 403, "(15) {$rot}: não redefine senha");
    }
    $check(!Autoridade::podeGerenciar(User::findById($g1), User::findById($vAlvo)) && !Autoridade::podeGerenciar(User::findById($g1), User::findById($g2)) && !Autoridade::podeAtribuirRole(User::findById($g1), User::findById($vAlvo), 'viewer') && !Autoridade::podeAlterarSupervisorGeral(User::findById($g1)), '(14) Autoridade: Gestor não gerencia, não atribui perfis e não altera o flag');

    // RH, Admin e Supervisor Geral atribuem o novo perfil conforme a política
    $r = static fn(int $ator, int $alvo, string $role): bool => User::attemptRoleUpdate($alvo, $role, User::findById($ator), '127.0.0.1');
    $check($r($rh, $vAlvo, 'gestor') && $linha($vAlvo)['role'] === 'gestor' && $r($rh, $vAlvo, 'viewer') && $r($rh, $rhAlvo, 'gestor') && $r($rh, $rhAlvo, 'rh'), '(RH) atribui rh/gestor/viewer a contas não administrativas');
    $check(!$r($rh, $vAlvo, 'admin') && $linha($vAlvo)['role'] === 'viewer' && !$r($rh, $adm, 'gestor') && $linha($adm)['role'] === 'admin' && !$r($rh, $sg, 'gestor'), '(RH) não atribui admin, não rebaixa administrador/Supervisor Geral para gestor');
    $check($r($adm, $vAlvo, 'gestor') && $r($sg, $vAlvo, 'viewer') && !$r($sg, $sg, 'gestor') && !$r($adm, $sg, 'gestor'), '(Admin/Supervisor) atribuem gestor; ninguém altera o próprio perfil; Admin não toca o Supervisor Geral');
    $check((int)$pdo->query("SELECT COUNT(*) FROM auditoria_usuarios WHERE action = 'role_change' AND target_usuario_id = {$vAlvo}")->fetchColumn() >= 3, '(auditoria) as alterações de perfil para/de gestor são auditadas');
    [$st] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'updateRole', ['role' => 'gestor'], [(string)$vAlvo]);
    $check($linha($vAlvo)['role'] === 'gestor', '(HTTP) Administrador atribui gestor pela rota de edição');
    [$st] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'updateRole', ['role' => 'superadmin'], [(string)$vAlvo]);
    $check($st === 400 && $linha($vAlvo)['role'] === 'gestor', '(2) perfil inventado pela rota: 400, nada muda');
    // permissões do gestor pela rota de edição (política central)
    [$st] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => [$permId['colaboradores.visualizar'], $permId['integracao_colaborador.visualizar']]], [(string)$vAlvo]);
    $check($gravadas($vAlvo) === [], '(8) Administrador: seleção do Gestor com Integração do Colaborador (módulo fechado) recusada, nada gravado');
    [$st] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => [$permId['colaboradores.visualizar'], $permId['avaliacao_experiencia.visualizar']]], [(string)$vAlvo]);
    $check($gravadas($vAlvo) === ['avaliacao_experiencia.visualizar', 'colaboradores.visualizar'], '(8) Administrador: seleção compatível do Gestor é gravada');
    [$st, $htmlShow] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'show', [], [(string)$vAlvo]);
    $check($st === 200 && str_contains($htmlShow, 'value="gestor"'), '(cadastro) a tela de edição oferece o perfil Gestor');
    [$st, $htmlNovo] = $requisitar($adm, 'admin', false, 'AdminUsuariosController', 'create');
    $check($st === 200 && str_contains($htmlNovo, 'value="gestor"'), '(cadastro) a tela de cadastro oferece o perfil Gestor');

    // ---- 18. viewer mantém o que é compatível ---------------------------------------------------------------------------
    $vDash = $novo('vdash', 'viewer', 0, ['dashboard_recrutamento.visualizar', 'pdi.visualizar', 'candidaturas.visualizar']);
    $check($tem($vDash, 'dashboard_recrutamento.visualizar') && $tem($vDash, 'pdi.visualizar') && !$tem($vDash, 'candidaturas.visualizar'), '(18) viewer: dashboard e transitória seguem efetivos; nominal incompatível não');
    $check(!Autoridade::podeAtribuirRole(User::findById($g1), User::findById($vDash), 'gestor'), '(18) viewer não é promovido por ninguém sem autoridade');
    $check($linha($vLegado)['role'] === 'viewer' && $linha($g1)['role'] === 'gestor', '(dados) nenhuma conta existente foi reclassificada automaticamente');
} finally {
    Autoridade::forcarSchemaGestorParaTeste(null);
    if ($c['usuarios'] !== []) {
        $pdo->exec('DELETE FROM usuario_setores WHERE usuario_id IN (' . implode(',', array_map('intval', $c['usuarios'])) . ')');
        $pdo->exec('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $c['usuarios'])) . ')');
    }
    if ($c['metadados'] !== []) {
        $pdo->exec('DELETE FROM colaboradores_metadados WHERE id IN (' . implode(',', array_map('intval', $c['metadados'])) . ')');
    }
    if ($c['setores'] !== []) {
        $pdo->exec('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $c['setores'])) . ')');
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — estrutura do perfil Gestor.\n";
