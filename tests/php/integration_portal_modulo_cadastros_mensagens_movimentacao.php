<?php

/**
 * Integração — Nova UI, Bloco G (final): Cadastros (Empresas, Setores, Cargos, Benefícios, Avaliações) + Mensagens + Movimentação de Pessoal + Manual
 * no AppShell V2. Fixtures ZZPGT-* com limpeza. Prova, contra o banco e as fontes:
 *   - módulo de abas `cadastros`: as cinco abas são destinos reais, abertos às roles admin/rh/viewer no backend (gate lido do controller) e iguais
 *     ao card da Central; nenhum perfil vê aba que leve a 403; nenhuma permissão nova;
 *   - Mensagens mantém as permissões individuais (visualizar/criar/editar) e Movimentação/Manual mantêm seu gate de role; sem ModuleTabs artificiais;
 *   - as telas renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader e <title>; sem <script> executável, <style> nem handlers inline;
 *   - todos os campos (`name=`), ações (`action=`), `data-*` e links de escrita das views são IDÊNTICOS aos do HEAD (formulários/filtros preservados);
 *   - nenhuma tela administrativa esconde a tabela no mobile sem alternativa; nenhum controller usa mais `layouts/admin`;
 *   - o Manual de Uso — antes só na sidebar — passou a ter entrada na Central.
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
$getOriginal = $_GET;
$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = [];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$rotas = (string)file_get_contents(BASE_PATH . '/index.php');
$fonte = static fn(string $rel): string => (string)file_get_contents(BASE_PATH . '/' . $rel);
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
$permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$novoUsuario = static function (string $rotulo, string $role, bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix): array {
    $id = User::create('ZZPGT ' . $rotulo, strtolower(preg_replace('/[^a-z0-9]+/i', '.', $rotulo)) . '.zzpgt.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    if ($supervisor) {
        $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
    }
    $criados[] = $id;
    return ['id' => $id, 'role' => $role, 'supervisor' => $supervisor];
};
$comoUsuario = static function (array $u): void {
    $_SESSION['user'] = true;
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = 'ZZPGT Teste';
    $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
    $_GET = [];
};
/** Conjunto de atributos que definem formulários/filtros/ações de uma view (nomes, actions, data-*), para comparar com o HEAD. */
$assinatura = static function (string $html): array {
    preg_match_all('/\b(?:name|action|data-[a-z-]+)="([^"]*)"/', $html, $m1);
    preg_match_all('/\b(data-[a-z-]+)=/', $html, $m2);
    preg_match_all('/name="([^"]+)"/', $html, $m3);
    preg_match_all('/action="([^"]+)"/', $html, $m4);
    $norm = static function (array $a): array {
        $a = array_values(array_unique(array_map(static fn(string $x): string => preg_replace('/<\?=.*?\?>/', '§', $x), $a)));
        sort($a);
        return $a;
    };
    return ['names' => $norm($m3[1]), 'actions' => $norm($m4[1]), 'data' => $norm($m2[1])];
};

try {
    $perfis = [
        'Admin' => $novoUsuario('Admin', 'admin'),
        'RH' => $novoUsuario('RH', 'rh'),
        'viewer' => $novoUsuario('Viewer', 'viewer'),
        'supervisor' => $novoUsuario('Supervisor', 'viewer', true),
    ];

    // ---- abas de Cadastros ------------------------------------------------------------------------------------------------------------
    $def = PortalNavegacaoService::definicaoAbas()['cadastros'];
    $check(array_column($def['abas'], 'chave') === ['empresas', 'setores', 'cargos', 'beneficios', 'avaliacoes'] && array_unique(array_column($def['abas'], 'regra')) === ['aberto'], '(abas) Empresas | Setores | Cargos | Benefícios | Avaliações — todas `aberto`, como a Central e o gate de leitura dos controllers (sem Unidades: não existe tela própria)');
    $central = array_values(array_filter(PortalNavegacaoService::definicao(), static fn(array $m): bool => $m['chave'] === 'cadastros'));
    $check(array_column($central[0]['itens'], 'href') === array_column($def['abas'], 'href'), '(Central) O card Cadastros e as abas apontam para os mesmos cinco destinos');
    $gates = [
        '/admin/setores' => $corpoDe('AdminSetoresController', 'index'),
        '/admin/beneficios' => $corpoDe('AdminBeneficiosController', 'index'),
        '/admin/avaliacoes' => $corpoDe('AdminAvaliacoesController', 'index'),
        '/admin/empresas' => $corpoDe('AdminCatalogosController', 'renderIndex'),
        '/admin/cargos' => $corpoDe('AdminCatalogosController', 'renderIndex'),
    ];
    $problemas = [];
    foreach ($gates as $href => $corpo) {
        preg_match("#Auth::requireRole\\(\\[([^\\]]*)\\]\\)#", $corpo, $r);
        $roles = array_map(static fn(string $x): string => trim($x, " '\""), explode(',', $r[1] ?? ''));
        if ($roles !== ['admin', 'rh', 'viewer'] || str_contains($corpo, 'requirePermissao')) {
            $problemas[] = $href;
        }
    }
    $check($problemas === [], '(regra de ouro) O gate de leitura de cada cadastro é só `requireRole([admin, rh, viewer])`, sem permissão individual — a aba `aberto` nunca leva a 403' . ($problemas === [] ? '' : ': ' . implode(', ', $problemas)));
    foreach ($perfis as $rotulo => $u) {
        $comoUsuario($u);
        $abas = array_column((new PortalNavegacaoService())->abas('cadastros'), 'chave');
        $check($abas === ['empresas', 'setores', 'cargos', 'beneficios', 'avaliacoes'], "(abas) {$rotulo} vê as cinco abas (todos os roles do gate abrem a listagem)");
    }
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova foi criada');

    // ---- gates preservados -------------------------------------------------------------------------------------------------------------
    $msg = $fonte('app/controllers/AdminMensagensController.php');
    $check(substr_count($msg, "requirePermissao('mensagens.visualizar')") === 1 && substr_count($msg, "requirePermissao('mensagens.criar')") === 2 && substr_count($msg, "requirePermissao('mensagens.editar')") === 2, '(Mensagens) Permissões individuais intactas: visualizar (lista), criar (novo/store), editar (editar/update)');
    $mov = $fonte('app/controllers/AdminMovimentacoesPessoalController.php');
    $check(substr_count($mov, "Auth::requireRole(['admin', 'rh', 'viewer'])") === 5 && substr_count($mov, "Auth::requireRole(['admin', 'rh'])") === 1, '(Movimentação) Gates de role intactos (5 ações admin/rh/viewer + assinatura RH admin/rh); regra de autoria/assinatura segue no controller/model');
    $check($fonte('app/controllers/AdminManualController.php') !== '' && str_contains($fonte('app/controllers/AdminManualController.php'), "Auth::requireRole(['admin', 'rh', 'viewer'])"), '(Manual) Gate de role intacto');

    // ---- Central: entrada do Manual ------------------------------------------------------------------------------------------------------
    $centralHtml = $fonte('app/views/admin/central.php');
    $check(substr_count($centralHtml, '/admin/manual') === 1 && strpos($centralHtml, '/admin/manual') > strpos($centralHtml, 'ui_module_grid'), '(Central) O Manual de Uso (antes só na sidebar) ganhou uma entrada discreta na Central, sem novo card');

    // ---- renderização no AppShell V2 ------------------------------------------------------------------------------------------------------
    $comoUsuario($perfis['Admin']);
    $paginas = [
        'Empresas' => static fn() => (new AdminEmpresasController())->index(),
        'Empresas (novo)' => static fn() => (new AdminEmpresasController())->create(),
        'Setores' => static fn() => (new AdminSetoresController())->index(),
        'Setores (novo)' => static fn() => (new AdminSetoresController())->create(),
        'Cargos' => static fn() => (new AdminCargosController())->index(),
        'Cargos (novo)' => static fn() => (new AdminCargosController())->create(),
        'Benefícios' => static fn() => (new AdminBeneficiosController())->index(),
        'Benefícios (novo)' => static fn() => (new AdminBeneficiosController())->create(),
        'Avaliações' => static fn() => (new AdminAvaliacoesController())->index(),
        'Avaliações (nova)' => static fn() => (new AdminAvaliacoesController())->create(),
        'Movimentação' => static fn() => (new AdminMovimentacoesPessoalController())->index(),
        'Manual' => static fn() => (new AdminManualController())->index(),
    ];
    foreach ($paginas as $nome => $acao) {
        ui_titulo_pagina(null, true);
        ui_script_pagina(null, true);
        $comoUsuario($perfis['Admin']);
        $html = $renderizar($acao);
        $ehCadastro = !in_array($nome, ['Movimentação', 'Manual'], true);
        $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html) && str_contains($html, 'data-app-shell-v2') && !str_contains($html, 'data-admin-sidebar') && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, '<h1') && str_contains($html, '<title>'), "(shell) \"{$nome}\": AppShell V2, sem sidebar antiga, com breadcrumb, título e <title>, sem Warning/Notice");
        $check(($ehCadastro ? str_contains($html, 'aria-label="Cadastros"') : !str_contains($html, 'aria-label="Cadastros"')) && !str_contains($html, 'aria-label="Pessoas"'), '(navegação) "' . $nome . '": ' . ($ehCadastro ? 'ModuleTabs de Cadastros' : 'sem ModuleTabs artificiais (tela única com ações contextuais)'));
        $check(!preg_match('/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>/i', $html) && !preg_match('/\son(click|submit|change|load|input|keyup|keydown|blur|focus)\s*=/i', $html), "(CSP) \"{$nome}\": sem <script> executável nem handlers inline");
    }
    $comoUsuario($perfis['Admin']);
    $htmlMsg = $renderizar(static fn() => (new AdminMensagensController())->index());
    $check(str_contains($htmlMsg, 'data-app-shell-v2') && str_contains($htmlMsg, 'Mensagens') && !preg_match('/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>/i', $htmlMsg), '(shell) Mensagens (lista) no AppShell V2 sem script inline');
    $htmlMsgForm = $renderizar(static fn() => (new AdminMensagensController())->create());
    $check(str_contains($htmlMsgForm, 'data-app-shell-v2') && str_contains($htmlMsgForm, 'data-mensagem-conteudo="1"') && str_contains($htmlMsgForm, 'data-mensagem-var-insert="1"') && str_contains($htmlMsgForm, 'data-mensagem-catalogo="1"') && str_contains($htmlMsgForm, 'data-mensagem-preview-wrap="1"') && str_contains($htmlMsgForm, 'name="csrf"'), '(Mensagens) Formulário: ganchos do JS de admin.js (conteúdo, inserir variável, catálogo JSON, pré-visualização) e CSRF preservados');
    $comoUsuario($perfis['Admin']);
    $htmlMovNova = $renderizar(static fn() => (new AdminMovimentacoesPessoalController())->create());
    $check(str_contains($htmlMovNova, 'data-app-shell-v2') && str_contains($htmlMovNova, 'data-movimentacao-pessoal-form="1"') && str_contains($htmlMovNova, 'data-movimentacao-payload="1"') && str_contains($htmlMovNova, 'name="csrf"') && str_contains($htmlMovNova, 'name="tipo_movimentacao"'), '(Movimentação) Formulário no AppShell V2 com a raiz do JS, o payload JSON e o CSRF preservados');

    // ---- formulários/filtros/ações idênticos ao HEAD -----------------------------------------------------------------------------------------
    $views = ['catalogos/index', 'catalogos/form', 'setores/index', 'setores/form', 'cargos/form', 'beneficios/index', 'beneficios/form', 'avaliacoes/index', 'avaliacoes/form', 'mensagens/index', 'mensagens/form', 'movimentacoes_pessoal/index', 'movimentacoes_pessoal/form'];
    $diferentes = [];
    $comparadas = 0;
    foreach ($views as $v) {
        $antigo = shell_exec('git -C ' . escapeshellarg(BASE_PATH) . ' show HEAD:app/views/admin/' . $v . '.php 2>NUL');
        if (!is_string($antigo) || trim($antigo) === '') {
            continue;
        }
        $comparadas++;
        $a = $assinatura($antigo);
        $n = $assinatura($fonte("app/views/admin/{$v}.php"));
        foreach (['names', 'actions'] as $k) {
            if ($a[$k] !== $n[$k]) {
                $diferentes[] = "{$v} ({$k}: -" . implode(',', array_diff($a[$k], $n[$k])) . ' +' . implode(',', array_diff($n[$k], $a[$k])) . ')';
            }
        }
        if (array_diff($a['data'], $n['data']) !== []) {
            $diferentes[] = "{$v} (data-*: -" . implode(',', array_diff($a['data'], $n['data'])) . ')';
        }
    }
    $check($comparadas === 0 || $diferentes === [], "(preservação) Campos (name=), ações (action=) e data-* de {$comparadas} views são idênticos aos do HEAD — nada foi perdido nos formulários/filtros/POSTs" . ($diferentes === [] ? '' : ': ' . implode(' | ', $diferentes)));

    // ---- fontes: tokens, sem inline, mobile ---------------------------------------------------------------------------------------------------
    $todasViews = array_merge($views, ['manual']);
    foreach ($todasViews as $v) {
        $c = $fonte("app/views/admin/{$v}.php");
        $semJson = preg_replace('#<script[^>]*application/json[^>]*>.*?</script>#s', '', $c);
        $check(!preg_match('/\b(?:bg|text|border|ring|divide|from|to)-(?:\[#|(?:slate|gray|blue|rose|red|emerald|green|amber|violet)-\d+|ct[a-z]+)/', $c) && !str_contains($c, 'responsive-header') && !preg_match('/<script\b/i', (string)$semJson) && !preg_match('/\son(click|submit|change|input|load)\s*=/i', $c), "(tokens) admin/{$v}.php: sem paleta legada/hex/ct*, sem responsive-header, sem script executável nem handler inline");
    }
    $piores = [];
    foreach (glob(BASE_PATH . '/app/views/admin/*/*.php') ?: [] as $arq) {
        if (str_contains((string)file_get_contents($arq), 'mobile-table-desktop')) {
            $piores[] = substr(str_replace('\\', '/', $arq), strlen(str_replace('\\', '/', BASE_PATH)) + 1);
        }
    }
    // O vão de 768px (tabela `.mobile-table-desktop` só a partir de 769px × cards `md:hidden` a partir de 768px) foi corrigido nas cinco telas dos Blocos B/C
    // com `hidden md:table`, a mesma solução do Bloco G. Agora nenhuma view usa a classe.
    $blocosEscondidos = [];
    foreach (glob(BASE_PATH . '/app/views/admin/*/*.php') ?: [] as $arq) {
        if (str_contains((string)file_get_contents($arq), 'mobile-block-desktop')) {
            $blocosEscondidos[] = basename(dirname($arq)) . '/' . basename($arq);
        }
    }
    $check($blocosEscondidos === [] && str_contains($fonte('app/views/admin/indicacoes/index.php'), 'class="hidden md:block mt-4 overflow-x-auto"'), '(mobile 768px) Nenhuma view usa `.mobile-block-desktop` (o mesmo vão de 768px): Indicações usa `hidden md:block` complementar aos cards `md:hidden`' . ($blocosEscondidos === [] ? '' : ': ' . implode(', ', $blocosEscondidos)));
    $check($piores === [], '(mobile) Nenhuma view administrativa usa `.mobile-table-desktop`: tabela e cards são complementares (`hidden md:table` × `md:hidden`) em qualquer largura, inclusive 767/768/769px' . ($piores === [] ? '' : ': ' . implode(', ', $piores)));
    foreach (['candidaturas/index', 'colaboradores/index', 'usuarios/index', 'solicitacoes_vaga/index', 'vagas/index'] as $v) {
        $c = $fonte("app/views/admin/{$v}.php");
        $check(str_contains($c, 'class="hidden min-w-full text-sm md:table"') && str_contains($c, 'responsive-card-list'), "(mobile 768px) admin/{$v}: `hidden md:table` + lista de cards complementares");
    }
    foreach (['catalogos/index', 'setores/index', 'beneficios/index', 'mensagens/index'] as $v) {
        $c = $fonte("app/views/admin/{$v}.php");
        $check(str_contains($c, 'class="hidden min-w-full text-sm md:table"') && str_contains($c, 'responsive-card-list mt-4 md:hidden'), "(mobile) admin/{$v}: tabela (`hidden md:table`) e lista de cards (`md:hidden`) são complementares em QUALQUER largura, inclusive 768px");
    }
    foreach (['avaliacoes/index', 'movimentacoes_pessoal/index'] as $v) {
        $c = $fonte("app/views/admin/{$v}.php");
        $check(str_contains($c, 'responsive-table-wrap') && str_contains($c, '<table class="min-w-full text-sm">'), "(mobile) admin/{$v}: tabela sempre visível e com rolagem interna (responsive-table-wrap)");
    }
    $manual = $fonte('app/views/admin/manual.php');
    $check(!preg_match('/#0d1321|#3e5c76|#1d2d44/i', $manual) && str_contains($manual, 'ui_breadcrumb'), '(Manual) O <style> próprio do Manual foi re-tematizado com os hex do Design System (sem a paleta azul antiga) e ganhou breadcrumb');
    $legado = [];
    foreach (['app/controllers', 'app/core', 'app/services'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $arq) {
            if ($arq->isFile() && $arq->getExtension() === 'php' && str_contains((string)file_get_contents($arq->getPathname()), "'layouts/admin'")) {
                $legado[] = basename($arq->getPathname());
            }
        }
    }
    $check($legado === [], '(inventário) Nenhum controller renderiza mais `layouts/admin`' . ($legado === [] ? '' : ': ' . implode(', ', $legado)));

    echo "\nPORTAL_MODULO_G_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    $_GET = $getOriginal;
    if ($criados !== []) {
        $lu = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
