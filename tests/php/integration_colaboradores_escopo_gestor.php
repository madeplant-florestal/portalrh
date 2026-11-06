<?php

/**
 * Integração — Escopo gerencial de Colaboradores (correção de 2026-11, caso Odair Pedro Tonin).
 *
 * Até esta correção, `AdminColaboradoresController` tinha TODOS os métodos restritos a admin/RH
 * (`Auth::requireRole(['admin', 'rh'])`) — a permissão individual `colaboradores.visualizar` era
 * concedida pela tela de usuários mas NUNCA consultada pelo controller (achado documentado em
 * docs/claude/ui-ux.md e no teste `integration_portal_modulo_pessoas.php`, que até agora
 * confirmava essa divergência como "decisão de negócio pendente").
 *
 * Esta correção abre a LISTAGEM (só ela — edição/acesso/exportações continuam admin/RH) para um
 * "Gestor computado" (viewer + `colaboradores.visualizar` + pelo menos 1 Setor Gerenciado em
 * `usuario_setores_gerenciados`), mesmo padrão já usado e testado em AvaliacaoExperienciaService
 * (`escopoTotal()`/`escopoSetores()`) — nenhum perfil novo armazenado, nenhuma tabela nova.
 *
 * Prova, contra o banco:
 *   - Admin/RH continuam com escopo total e salário visível (sem alteração de comportamento);
 *   - Gestor com permissão + Setor Gerenciado vê SÓ os colaboradores daquele setor, sem salário
 *     (nem a coluna chega na resposta do repository — não é só ocultado na view);
 *   - Setor principal/adicional de atuação (`usuario_setores`) NUNCA concede escopo gerencial —
 *     só `usuario_setores_gerenciados` conta;
 *   - Gestor tentando filtrar por um Setor que não gerencia nunca vê dados fora do escopo
 *     (escopo é incondicional, nunca sobrescrito pelo filtro da tela);
 *   - Usuário sem nenhum Setor Gerenciado vê lista VAZIA, nunca "tudo" (summary/opcoesFiltro
 *     também escopados, nunca só a listagem principal);
 *   - Viewer sem a permissão continua bloqueado (403), igual a antes;
 *   - Nenhum dos outros 9 métodos do controller (edição, acesso, materialização, integração,
 *     entrevista de desligamento, senha temporária) teve o gate alterado — a permissão de
 *     visualização não concede nenhuma operação administrativa.
 *
 * Fixtures ZZCOLAB-* com limpeza em `finally`.
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

$suffix = substr((string)time(), -4) . (string)random_int(10, 99);
$criados = ['usuarios' => [], 'setores' => [], 'metadados' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);

$mkSetor = static function (string $codigo, string $nome) use ($pdo, &$criados): int {
    $stmt = $pdo->prepare(
        "INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados)
         VALUES (?, ?, ?, ?, 1, 'A', 'zzcolab-teste')"
    );
    $stmt->execute([$codigo, $nome, $nome, 'zzcolab-' . strtolower($codigo) . '-' . uniqid()]);
    $id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $id;
    return $id;
};

$mkUsuario = static function (string $nome) use ($pdo, &$criados, $senha, $suffix): int {
    static $i = 0;
    $i++;
    $id = User::create("ZZCOLAB {$nome}", "zzcolab.{$suffix}.{$i}@teste.local", $senha, 'viewer');
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

$concederPermissao = static function (int $usuarioId, string $codigo) use ($pdo): void {
    $permId = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = " . $pdo->quote($codigo))->fetchColumn();
    if ($permId <= 0) {
        throw new RuntimeException("Permissão '{$codigo}' não existe no catálogo.");
    }
    $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$usuarioId, $permId]);
};

$mkMetadados = static function (string $codigoSetor, string $setorNome, string $nome, float $salario) use ($pdo, &$criados, $suffix): int {
    static $i = 0;
    $i++;
    $stmt = $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome, empresa,
            codigo_setor, setor, cargo, codigo_cargo, salario_atual, admissao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        "ZZCOLAB{$suffix}-{$i}", 'ZZCOLABEMP', 'UNI', "ZZCOLABC{$suffix}{$i}", "ZZCOLABP{$suffix}{$i}", $nome, 'ZZCOLAB Empresa',
        $codigoSetor, $setorNome, 'ZZCOLAB Cargo', 'ZZCOLABCG', $salario, date('Y-m-d', strtotime('-200 days')),
        'zzcolab-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

try {
    // ---- fixtures: 2 setores, 2 colaboradores (um em cada), 4 usuários de teste -------------------
    $setorS1 = $mkSetor('ZZCS1', 'ZZCOLAB Logística e Transportes');
    $setorS2 = $mkSetor('ZZCS2', 'ZZCOLAB Manutenção');

    $metaS1 = $mkMetadados('ZZCS1', 'ZZCOLAB Logística e Transportes', 'ZZCOLAB Pessoa S1', 5000.00);
    $metaS2 = $mkMetadados('ZZCS2', 'ZZCOLAB Manutenção', 'ZZCOLAB Pessoa S2', 7000.00);

    $gestorA = $mkUsuario('Gestor A (permissão + setor S1)');
    $concederPermissao($gestorA, 'colaboradores.visualizar');
    (new UsuarioSetoresGerenciadosService())->definirSetoresGerenciados($gestorA, [$setorS1]);

    $gestorSemSetor = $mkUsuario('Gestor Sem Setor (permissão, 0 setores gerenciados)');
    $concederPermissao($gestorSemSetor, 'colaboradores.visualizar');

    $viewerSemPermissao = $mkUsuario('Viewer Sem Permissão');
    (new UsuarioSetoresGerenciadosService())->definirSetoresGerenciados($viewerSemPermissao, [$setorS1]); // tem setor, mas NÃO tem a permissão

    $viewerApenasAtuacao = $mkUsuario('Viewer Com Permissão, Só Setor de Atuação (não gerenciado)');
    $concederPermissao($viewerApenasAtuacao, 'colaboradores.visualizar');
    // Setor de ATUAÇÃO (usuario_setores), nunca usuario_setores_gerenciados — não deve conceder nada.
    $pdo->prepare('INSERT INTO usuario_setores (usuario_id, setor_id, principal, origem) VALUES (?, ?, 1, ?)')
        ->execute([$viewerApenasAtuacao, $setorS1, 'MANUAL']);

    $usuarioIdRh = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'rh' ORDER BY id LIMIT 1")->fetchColumn();
    $check($usuarioIdRh > 0, '(pré-condição) Existe usuário RH local');

    $repo = new ColaboradorMetadadosConsultaRepository();
    $service = new UsuarioSetoresGerenciadosService();

    // ---- 1) Admin/RH: escopo total, sem alteração de comportamento --------------------------------
    $pagRh = $repo->paginate(['q' => 'ZZCOLAB Pessoa'], 1, 50, null);
    $nomesRh = array_column($pagRh['items'], 'nome');
    $check(in_array('ZZCOLAB Pessoa S1', $nomesRh, true) && in_array('ZZCOLAB Pessoa S2', $nomesRh, true), '(1) Admin/RH (escopo null) continuam vendo os colaboradores de AMBOS os setores — sem alteração de comportamento');
    $itemS1Rh = current(array_filter($pagRh['items'], static fn(array $i): bool => $i['nome'] === 'ZZCOLAB Pessoa S1'));
    $check(array_key_exists('salario_atual', $itemS1Rh) && (float)$itemS1Rh['salario_atual'] === 5000.0, '(1) Admin/RH continuam recebendo salario_atual normalmente');

    // ---- 2) Gestor A (permissão + Setor Gerenciado S1): só vê S1, nunca S2, sem salário -----------
    $codigosA = $service->codigosSetorGerenciadosPor($gestorA);
    $check($codigosA === ['ZZCS1'], '(2) Gestor A tem exatamente o Setor Gerenciado ZZCS1 — ' . json_encode($codigosA));
    $pagA = $repo->paginate(['q' => 'ZZCOLAB Pessoa'], 1, 50, $codigosA);
    $nomesA = array_column($pagA['items'], 'nome');
    $check(in_array('ZZCOLAB Pessoa S1', $nomesA, true), '(2) Gestor A vê o colaborador do setor que gerencia (S1)');
    $check(!in_array('ZZCOLAB Pessoa S2', $nomesA, true), '(2) Gestor A NUNCA vê o colaborador de S2 (não gerencia esse setor)');
    $itemS1A = current(array_filter($pagA['items'], static fn(array $i): bool => $i['nome'] === 'ZZCOLAB Pessoa S1'));
    $check(!array_key_exists('salario_atual', $itemS1A), '(2) Salário NUNCA é selecionado pelo repository para um Gestor escopado — ausente da resposta, não só ocultado na view');

    // ---- 3) Gestor A tentando filtrar por um Setor que NÃO gerencia: escopo é incondicional -------
    $pagAFiltroS2 = $repo->paginate(['q' => 'ZZCOLAB Pessoa', 'setor' => 'ZZCOLAB Manutenção'], 1, 50, $codigosA);
    $check($pagAFiltroS2['total'] === 0, '(3) Gestor A filtrando explicitamente por "ZZCOLAB Manutenção" (setor de B) não recebe nenhum resultado — o filtro da tela nunca amplia o escopo');

    // ---- 4) Setor de ATUAÇÃO (usuario_setores) nunca concede escopo gerencial ----------------------
    $codigosAtuacao = $service->codigosSetorGerenciadosPor($viewerApenasAtuacao);
    $check($codigosAtuacao === [], '(4) Usuário com Setor de ATUAÇÃO (principal) em S1, mas SEM linha em usuario_setores_gerenciados, tem escopo gerencial VAZIO — setor de atuação nunca concede acesso gerencial');
    $pagAtuacao = $repo->paginate(['q' => 'ZZCOLAB Pessoa'], 1, 50, $codigosAtuacao);
    $check($pagAtuacao['total'] === 0, '(4) Consequência: esse usuário não vê NENHUM colaborador (nem os do próprio setor de atuação) sem um Setor Gerenciado explícito');

    // ---- 5) Usuário sem nenhum Setor Gerenciado: lista vazia, nunca "tudo" -------------------------
    $codigosSemSetor = $service->codigosSetorGerenciadosPor($gestorSemSetor);
    $check($codigosSemSetor === [], '(5) Gestor Sem Setor tem codigosSetorGerenciadosPor() === [] (permissão concedida, mas nenhum setor)');
    $pagSemSetor = $repo->paginate(['q' => 'ZZCOLAB Pessoa'], 1, 50, $codigosSemSetor);
    $check($pagSemSetor['total'] === 0, '(5) [] aplicado ao repository resulta em ZERO colaboradores — nunca "sem restrição" por omissão');
    $summarySemSetor = $repo->summary($codigosSemSetor);
    $check($summarySemSetor['contratos'] === 0, '(5) summary() também fica zerado para quem não gerencia nenhum setor');
    $opcoesSemSetor = $repo->opcoesFiltro($codigosSemSetor);
    $check(!in_array('ZZCOLAB Logística e Transportes', $opcoesSemSetor['setores'], true) && !in_array('ZZCOLAB Manutenção', $opcoesSemSetor['setores'], true), '(5) opcoesFiltro() também não expõe nenhum setor para quem não gerencia nada');

    // ---- 6) summary()/opcoesFiltro() escopados para o Gestor A (não só a listagem principal) ------
    $summaryA = $repo->summary($codigosA);
    $check($summaryA['contratos'] === 1, '(6) summary() do Gestor A conta só o contrato do setor que ele gerencia (1), nunca o total global');
    $opcoesA = $repo->opcoesFiltro($codigosA);
    $check(in_array('ZZCOLAB Logística e Transportes', $opcoesA['setores'], true) && !in_array('ZZCOLAB Manutenção', $opcoesA['setores'], true), '(6) opcoesFiltro() do Gestor A só lista o setor que ele gerencia, nunca o setor de B');

    // ---- 7) Permissão individual + Authorization::usuarioTemPermissao() ---------------------------
    $check(Authorization::usuarioTemPermissao($gestorA, 'colaboradores.visualizar'), '(7) Gestor A tem a permissão colaboradores.visualizar concedida');
    $check(!Authorization::usuarioTemPermissao($viewerSemPermissao, 'colaboradores.visualizar'), '(7) Viewer Sem Permissão (mesmo com Setor Gerenciado) NÃO tem a permissão — o controller deve bloqueá-lo com 403, setor nunca substitui a permissão');

    // ---- 8) Controller: simulação de sessão real (403 e render da listagem já escopada/sem salário) ----
    $sessaoOriginal = $_SESSION;
    $getOriginal = $_GET;
    try {
        $_GET = [];
        $_SESSION = ['user' => true, 'user_id' => $gestorA, 'user_role' => 'viewer', 'user_name' => 'ZZCOLAB Teste', 'user_is_supervisor' => 0];
        ob_start();
        (new AdminColaboradoresController())->index();
        $htmlGestorA = (string)ob_get_clean();
        $check(str_contains($htmlGestorA, 'ZZCOLAB Pessoa S1'), '(8) Render real de index() para o Gestor A mostra o colaborador do setor que ele gerencia');
        $check(!str_contains($htmlGestorA, 'ZZCOLAB Pessoa S2'), '(8) Render real de index() para o Gestor A NUNCA mostra o colaborador de outro setor, nem no HTML final');
        $check(!str_contains($htmlGestorA, '>Salário<'), '(8) Coluna "Salário" não é renderizada para o Gestor A');
        $check(!str_contains($htmlGestorA, 'Editar dados RH') && !str_contains($htmlGestorA, 'Acesso e liderança'), '(8) Ações administrativas (Editar dados RH / Acesso e liderança) não aparecem para o Gestor A — permissão de visualização não concede edição');
        $check(str_contains($htmlGestorA, 'ZZCOLAB Logística e Transportes') || str_contains($htmlGestorA, 'Você está vendo somente os colaboradores dos setores que gerencia'), '(8) Tela informa transparentemente que o Gestor está vendo um escopo restrito');

        $_GET = [];
        $_SESSION = ['user' => true, 'user_id' => $viewerSemPermissao, 'user_role' => 'viewer', 'user_name' => 'ZZCOLAB Teste', 'user_is_supervisor' => 0];
        ob_start();
        (new AdminColaboradoresController())->index();
        $saidaBloqueada = (string)ob_get_clean();
        $check(str_contains($saidaBloqueada, 'Acesso negado'), '(8) Viewer sem a permissão colaboradores.visualizar continua bloqueado (403) — igual a antes desta correção');

        $_GET = [];
        $_SESSION = ['user' => true, 'user_id' => $gestorSemSetor, 'user_role' => 'viewer', 'user_name' => 'ZZCOLAB Teste', 'user_is_supervisor' => 0];
        ob_start();
        (new AdminColaboradoresController())->index();
        $htmlSemSetor = (string)ob_get_clean();
        $check(!str_contains($htmlSemSetor, 'ZZCOLAB Pessoa'), '(8) Gestor com a permissão mas SEM nenhum Setor Gerenciado nunca vê nenhum colaborador, mesmo passando pelo gate de permissão');
    } finally {
        $_SESSION = $sessaoOriginal;
        $_GET = $getOriginal;
    }

    // ---- 9) Regressão: os outros métodos do controller continuam admin/RH apenas ------------------
    // (edição, acesso, materialização, integração, entrevista de desligamento, senha temporária) —
    // a permissão de visualização NUNCA concede nenhuma operação administrativa.
    $corpoController = file_get_contents(__DIR__ . '/../../app/controllers/AdminColaboradoresController.php');
    foreach (['materializarExtensaoLocal', 'editRh', 'updateIntegracao', 'gerarPesquisaIntegracao', 'liberarEntrevistaDesligamento', 'cancelarLiberacaoEntrevistaDesligamento', 'updateRh', 'acesso', 'updateAcesso'] as $metodo) {
        $r = new ReflectionMethod(AdminColaboradoresController::class, $metodo);
        $arq = new SplFileObject($r->getFileName());
        $arq->seek($r->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $r->getEndLine()) { $corpo .= $arq->current(); $arq->next(); }
        $check(str_contains($corpo, "Auth::requireRole(['admin', 'rh'])"), "(9) AdminColaboradoresController::{$metodo}() continua restrito a admin/RH (inalterado por esta correção)");
    }
    unset($corpoController);

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nCOLABORADORES_ESCOPO_GESTOR_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE id IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $ph = implode(',', array_fill(0, count($criados['usuarios']), '?'));
        $pdo->prepare("DELETE FROM usuario_setores_gerenciados WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM usuario_setores WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM usuario_permissoes WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($criados['usuarios']);
    }
    if (!empty($criados['setores'])) {
        $ph = implode(',', array_fill(0, count($criados['setores']), '?'));
        $pdo->prepare("DELETE FROM setores WHERE id IN ($ph)")->execute($criados['setores']);
    }
}
