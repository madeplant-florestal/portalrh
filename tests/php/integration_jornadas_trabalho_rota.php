<?php
declare(strict_types=1);

/**
 * Correção urgente (2026-10): /admin/jornadas_trabalho/novo (underscore) dava 404. Causa raiz:
 * AdminCatalogosController montava toda URL com '/admin/' . $table — para jornadas_trabalho, o
 * NOME DA TABELA (underscore) diverge da URL registrada no router (hífen, jornadas-trabalho), a
 * única convenção usada por setores/cargos/empresas, pela navegação (PortalNavegacaoService) e
 * pelo link da Solicitação de Vaga. Para os outros 3 catálogos tabela == segmento de URL, por
 * isso o bug nunca apareceu antes de Jornadas (primeiro catálogo com nome composto).
 *
 * Corrigido com CadastroOrganizacional::routeSegment() (fallback para o nome da tabela — não muda
 * nada para empresas/setores/cargos — só jornadas_trabalho tem 'route' explícito). Este teste
 * prova que TODAS as URLs que o módulo de Jornadas gera (listagem, form de cadastro, form de
 * edição) usam o segmento canônico com hífen, e que o fluxo ponta a ponta (cadastrar -> aparecer
 * na Solicitação de Vaga) funciona.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$check = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
};

$sessaoOriginal = $_SESSION;
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
    $linhas = file((string)$r->getFileName());
    return implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};

$sufixo = substr((string)time(), -5) . (string)random_int(10, 99);
$nomeJornada = 'ZZJT Teste ' . $sufixo;
$idCriado = null;
$snapshotAtivos = null;

try {
    // --- Caso 1: routeSegment() — a correção em si, isolada ---------------------------------
    $check(CadastroOrganizacional::routeSegment('jornadas_trabalho') === 'jornadas-trabalho', 'Caso 1: jornadas_trabalho deveria mapear para o segmento com hífen.');
    foreach (['empresas', 'setores', 'cargos'] as $t) {
        $check(CadastroOrganizacional::routeSegment($t) === $t, "Caso 1: {$t} nao deveria mudar (tabela == segmento de URL).");
    }

    // --- Caso 2: rota /admin/jornadas-trabalho/novo está registrada no router --------------
    $rotas = (string)file_get_contents(BASE_PATH . '/index.php');
    $check(
        (bool)preg_match('#\$router->get\(\'/admin/jornadas-trabalho/novo\',\s*\[AdminJornadasTrabalhoController::class,\s*\'create\'\]\)#', $rotas),
        'Caso 2: a rota GET /admin/jornadas-trabalho/novo deveria estar registrada apontando para AdminJornadasTrabalhoController::create.'
    );
    $check(
        strpos($rotas, "'/admin/jornadas_trabalho") === false,
        'Caso 2: nenhuma rota deveria usar o segmento com underscore (jornadas_trabalho).'
    );

    // --- Caso 3: listagem renderiza o botão "Novo" com o link correto (hífen) --------------
    $_SESSION = ['user' => true, 'user_id' => 999001, 'user_role' => 'rh', 'user_name' => 'ZZJT RH', 'user_is_supervisor' => 0];
    $_GET = [];
    $htmlIndex = $renderizar(static function (): void {
        (new AdminJornadasTrabalhoController())->index();
    });
    $check(strpos($htmlIndex, '/admin/jornadas-trabalho/novo') !== false, 'Caso 3: a listagem deveria linkar "Novo" para /admin/jornadas-trabalho/novo.');
    $check(strpos($htmlIndex, 'jornadas_trabalho/novo') === false, 'Caso 3: a listagem NUNCA deveria linkar para a URL com underscore (a causa raiz do 404).');

    // --- Caso 4: tela de nova Jornada (GET) renderiza form action correto (hífen) ----------
    $htmlCreate = $renderizar(static function (): void {
        (new AdminJornadasTrabalhoController())->create();
    });
    $check((bool)preg_match('#<form[^>]+action="[^"]*/admin/jornadas-trabalho/novo"#', $htmlCreate), 'Caso 4: o <form> de cadastro deveria submeter para /admin/jornadas-trabalho/novo.');
    $check(strpos($htmlCreate, 'jornadas_trabalho/novo') === false, 'Caso 4: o form de cadastro NUNCA deveria apontar para a URL com underscore.');

    // --- Caso 5: POST de cadastro (direto no model, já que handleStore() termina com exit) -
    $idCriado = CadastroOrganizacional::create('jornadas_trabalho', ['nome' => $nomeJornada, 'slug' => '', 'ativo' => 1]);
    $check($idCriado > 0, 'Caso 5: CadastroOrganizacional::create deveria gravar a Jornada e devolver um id.');
    $criada = CadastroOrganizacional::find('jornadas_trabalho', $idCriado);
    $check($criada !== null && $criada['nome'] === $nomeJornada, 'Caso 5: a Jornada criada deveria ser recuperável por find().');

    // --- Caso 6: tela de edição (GET) também usa o segmento com hífen ----------------------
    $htmlEdit = $renderizar(function () use ($idCriado): void {
        (new AdminJornadasTrabalhoController())->edit((string)$idCriado);
    });
    $check((bool)preg_match('#<form[^>]+action="[^"]*/admin/jornadas-trabalho/editar/' . $idCriado . '"#', $htmlEdit), 'Caso 6: o <form> de edição deveria submeter para /admin/jornadas-trabalho/editar/{id}.');

    // --- Caso 7: a Jornada criada aparece nas dependências da Solicitação de Vaga ----------
    $deps = SolicitacaoVaga::formDependencies();
    $idsDisponiveis = array_column($deps['jornadas_trabalho'] ?? [], 'id');
    $check(in_array($idCriado, $idsDisponiveis, true), 'Caso 7: a Jornada recém-criada deveria aparecer em SolicitacaoVaga::formDependencies().');

    // --- Caso 8: com pelo menos uma Jornada ativa, a view para de avisar "nenhuma cadastrada"
    $jornadasTrabalho = $deps['jornadas_trabalho'];
    $check(count($jornadasTrabalho) > 0, 'Caso 8: deveria haver ao menos uma Jornada ativa disponível (condição que a view usa para esconder o aviso).');

    // --- Caso 9: link da Solicitação de Vaga para "Cadastrar agora" já usa a rota canônica -
    $fonteForm = (string)file_get_contents(BASE_PATH . '/app/views/admin/solicitacoes_vaga/form.php');
    $check(strpos($fonteForm, '/admin/jornadas-trabalho/novo') !== false, 'Caso 9: o link "Cadastrar agora" da Solicitação de Vaga deveria apontar para /admin/jornadas-trabalho/novo.');

    // --- Caso 10 (RH/Admin autorizados, viewer bloqueado) — por reflexão, sem disparar exit()
    // (o mesmo padrão já usado em integration_portal_modulo_cadastros_mensagens_movimentacao.php)
    $corpoIndex = $corpoDe('AdminJornadasTrabalhoController', 'index');
    $corpoCreate = $corpoDe('AdminCatalogosController', 'renderCreate');
    $check(strpos($corpoIndex, "renderIndex('jornadas_trabalho')") !== false, 'Caso 10: index() deveria delegar para renderIndex.');
    $corpoRenderIndex = $corpoDe('AdminCatalogosController', 'renderIndex');
    $check((bool)preg_match('#requireRole\(\[\'admin\',\s*\'rh\',\s*\'viewer\'\]\)#', $corpoRenderIndex), "Caso 10: listagem deveria liberar admin/rh/viewer (RH e Admin autorizados).");
    $check((bool)preg_match('#requireRole\(\[\'admin\',\s*\'rh\'\]\)#', $corpoCreate), 'Caso 10: cadastro (create) deveria exigir admin/rh — viewer fica de fora (bloqueado).');

    // --- Caso 11: o CAMINHO REAL — Nova Solicitação de Vaga, zero Jornada ativa, link
    // "Cadastrar agora" renderizado pelo AdminSolicitacoesVagaController real (não o controller
    // genérico de catálogo). Fiel reprodução do bug relatado em produção. Desativa TEMPORARIAMENTE
    // qualquer Jornada já cadastrada no banco (snapshot para restaurar depois), já que a produção
    // não está vazia.
    $snapshotAtivos = $pdo->query('SELECT id, ativo FROM jornadas_trabalho')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec('UPDATE jornadas_trabalho SET ativo = 0');

    $_SESSION = ['user' => true, 'user_id' => 999002, 'user_role' => 'rh', 'user_name' => 'ZZJT RH2', 'user_is_supervisor' => 0];
    $_GET = [];
    $htmlNovaSolicitacao = $renderizar(static function (): void {
        (new AdminSolicitacoesVagaController())->create();
    });
    $check(
        strpos($htmlNovaSolicitacao, 'Nenhuma Jornada de Trabalho cadastrada') !== false,
        'Caso 11: com zero Jornada ativa, a tela de Nova Solicitação de Vaga deveria mostrar o aviso.'
    );
    $check(
        (bool)preg_match('#<a href="[^"]*/admin/jornadas-trabalho/novo"[^>]*>Cadastrar agora</a>#', $htmlNovaSolicitacao),
        'Caso 11: o link "Cadastrar agora" renderizado de verdade (não só a fonte do arquivo) deveria apontar para /admin/jornadas-trabalho/novo.'
    );
    $check(
        strpos($htmlNovaSolicitacao, 'jornadas_trabalho/novo') === false,
        'Caso 11: a tela de Nova Solicitação de Vaga renderizada NUNCA deveria conter a URL com underscore — esta é a prova direta do bug relatado em produção.'
    );

    echo "OK integration_jornadas_trabalho_rota\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $falhou = true;
} finally {
    if ($idCriado !== null) {
        $pdo->prepare('DELETE FROM jornadas_trabalho WHERE id = ?')->execute([$idCriado]);
    }
    if ($snapshotAtivos !== null) {
        $restaurar = $pdo->prepare('UPDATE jornadas_trabalho SET ativo = ? WHERE id = ?');
        foreach ($snapshotAtivos as $linha) {
            $restaurar->execute([(int)$linha['ativo'], (int)$linha['id']]);
        }
    }
    $_SESSION = $sessaoOriginal;
}

if (!empty($falhou)) {
    exit(1);
}
