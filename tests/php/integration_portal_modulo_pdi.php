<?php

/**
 * Integração — Nova UI, Bloco D: PDI no AppShell V2. Fixtures ZZPDU-* com limpeza em `finally`. Prova, contra o banco:
 *   - lista, seleção de contrato, formulário (criar/editar) e detalhe renderizam no AppShell V2 (sem sidebar), com breadcrumb, PageHeader e
 *     <title>; sem ModuleTabs artificiais; o card da Central continua apontando para /admin/pdis;
 *   - PDI grande (3 ações, competências, vários acompanhamentos/eventos, concluído) e PDI pequeno (rascunho, sem itens) e PDI cancelado renderizam
 *     sem Warning/Notice e com as seções, badges (tom por status), aviso de concluído/cancelado e linha do tempo do histórico;
 *   - todos os campos/POST/ações sensíveis do detalhe continuam presentes conforme a permissão (liberar/iniciar/editar/concluir/cancelar/reabrir);
 *   - escopo e autorização inalterados: Admin e RH com permissão veem tudo; gestor só o próprio; Supervisor sem permissão e RH sem permissão
 *     individual não abrem nada; nenhuma permissão nova;
 *   - nenhum <script> inline nem onclick=/onsubmit=/onchange= nas telas do PDI (CSP `script-src 'self'`) e nenhum hex solto nas views.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/ui-shell.php';

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
$criados = ['usuarios' => [], 'metadados' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$ip = '127.0.0.1';
$agora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
$hoje = new DateTimeImmutable('today');
$dia = static fn(int $n): string => (new DateTimeImmutable('today'))->modify(($n >= 0 ? '+' : '-') . abs($n) . ' days')->format('Y-m-d');
$renderizar = static function (callable $acao): string {
    ob_start();
    try {
        $acao();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
};
$fonte = static fn(string $rel): string => (string)file_get_contents(BASE_PATH . '/' . $rel);

try {
    $permId = [];
    $stmt = $pdo->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    foreach (['visualizar', 'gerenciar', 'acompanhar'] as $a) {
        $stmt->execute(['pdi.' . $a]);
        $permId[$a] = (int)$stmt->fetchColumn();
    }
    $permissoesAntes = (int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
    $novoUsuario = static function (string $rotulo, string $role, array $permissoes, bool $supervisor = false) use ($pdo, &$criados, $senha, $suffix, $permId): array {
        $id = User::create('ZZPDU ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzpdu.' . $suffix . '@teste.local', $senha, $role);
        User::setActiveStatus($id, true);
        if ($supervisor) {
            $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
        }
        $criados['usuarios'][] = $id;
        if ($permissoes !== []) {
            Authorization::sincronizar($id, array_map(static fn(string $p): int => $permId[$p], $permissoes));
        }
        return ['id' => $id, 'role' => $role, 'supervisor' => $supervisor];
    };
    $comoUsuario = static function (array $u): void {
        $_SESSION['user'] = true;
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user_role'] = $u['role'];
        $_SESSION['user_name'] = 'ZZPDU Teste';
        $_SESSION['user_is_supervisor'] = $u['supervisor'] ? 1 : 0;
        $_GET = [];
    };
    $todas = ['visualizar', 'gerenciar', 'acompanhar'];
    $adm = $novoUsuario('Admin', 'admin', []);
    $rh = $novoUsuario('RH', 'rh', $todas);
    $gestorA = $novoUsuario('Gestor A', 'viewer', $todas);
    $gestorB = $novoUsuario('Gestor B', 'viewer', $todas);
    $semPerm = $novoUsuario('RH Sem Permissao', 'rh', []);
    $supSem = $novoUsuario('Supervisor Sem Permissao', 'viewer', [], true);
    $soVis = $novoUsuario('So Visualiza', 'viewer', ['visualizar']);

    $seq = 0;
    $mk = static function (string $nome) use ($pdo, &$criados, $suffix, $dia, &$seq): int {
        $seq++;
        $identificador = 'ZZPDU_' . $suffix . '_' . $seq;
        $pdo->prepare(
            'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
                cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 1, ?)'
        )->execute([$identificador, 'ZPU' . $suffix, 'U1', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, '52998224725', $nome, 'ZZPDU Empresa', 'ZZPDU Unidade', 'ZZPDU Cargo', 'ZPC' . $suffix, $dia(-800), $dia(-200), 'zzpdu-teste']);
        $criados['metadados'][] = $identificador;
        return (int)$pdo->lastInsertId();
    };
    $svc = new PdiService(new PdiRepository());
    $post = static function (int $contrato, int $gestor, array $extra = []) use ($dia): array {
        return $extra + [
            'metadados_id' => (string)$contrato, 'gestor_usuario_id' => (string)$gestor, 'origem_tipo' => 'feedback',
            'data_abertura' => $dia(0), 'data_prevista_conclusao' => $dia(90),
            'pontos_fortes' => 'Comunicação clara', 'oportunidades_desenvolvimento' => 'Delegar tarefas', 'objetivo_esperado' => 'Liderar uma equipe',
            'competencias' => "Comunicação\nLiderança\nGestão do tempo",
            'acoes' => [
                1 => ['descricao' => 'Curso de liderança', 'responsavel_tipo' => 'colaborador', 'prazo' => $dia(30)],
                2 => ['descricao' => 'Mentoria mensal', 'responsavel_tipo' => 'gestor', 'prazo' => $dia(60)],
                3 => ['descricao' => 'Projeto piloto', 'responsavel_tipo' => 'gestor', 'prazo' => $dia(80)],
            ],
        ];
    };

    // ---- fixtures: grande (concluído), pequeno (rascunho), cancelado -----------------------------------------------------------
    $cGrande = $mk('ZZPDU Grande Concluida');
    $cPequeno = $mk('ZZPDU Pequeno Rascunho');
    $cCancel = $mk('ZZPDU Cancelado Exemplo');
    $cAlheio = $mk('ZZPDU Alheio Do B');
    $grande = (int)$svc->criar($post($cGrande, $gestorA['id']), $rh, $agora, $ip)['id'];
    $pequeno = (int)$svc->criar($post($cPequeno, $gestorA['id'], ['pontos_fortes' => '', 'oportunidades_desenvolvimento' => '', 'objetivo_esperado' => '', 'competencias' => '', 'acoes' => []]), $rh, $agora, $ip)['id'];
    $cancelado = (int)$svc->criar($post($cCancel, $gestorA['id']), $rh, $agora, $ip)['id'];
    $alheio = (int)$svc->criar($post($cAlheio, $gestorB['id']), $rh, $agora, $ip)['id'];
    $check($grande > 0 && $pequeno > 0 && $cancelado > 0 && $alheio > 0, '(fixture) PDIs grande, pequeno, a cancelar e de outro gestor criados');
    $r = $svc->iniciar($grande, $rh, $agora, $ip);
    $check($r['ok'] === true, '(fixture) PDI grande iniciado');
    for ($i = 1; $i <= 5; $i++) {
        $svc->adicionarAcompanhamento($grande, "Acompanhamento {$i} — evolução registrada", $i % 2 === 0, $rh, $agora, $ip);
    }
    $svc->registrarEspacoColaborador($grande, 'Momento do colaborador', 'Pontos apontados', $rh, $agora, $ip);
    $svc->registrarEvidencias($grande, 'Certificado do curso', $rh, $agora, $ip);
    foreach ([1, 2, 3] as $ordem) {
        $svc->alterarStatusAcao($grande, $ordem, 'concluida', $rh, $agora, $ip);
    }
    $rc = $svc->concluir($grande, 'objetivo_atingido', 'Comentários finais de teste', $rh, $agora, $ip);
    $check($rc['ok'] === true, '(fixture) PDI grande concluído' . ($rc['ok'] ? '' : ' — ' . json_encode($rc)));
    $svc->iniciar($cancelado, $rh, $agora, $ip);
    $rx = $svc->cancelar($cancelado, 'Motivo de teste do cancelamento', $rh, $agora, $ip);
    $check($rx['ok'] === true, '(fixture) PDI cancelado');
    $eventosGrande = (int)$pdo->query('SELECT COUNT(*) FROM pdi_eventos WHERE pdi_id = ' . $grande)->fetchColumn();
    $check($eventosGrande >= 10, "(fixture) PDI grande acumula histórico ({$eventosGrande} eventos)");

    // ---- páginas no AppShell V2 -----------------------------------------------------------------------------------------------
    $comoUsuario($rh);
    ui_titulo_pagina(null, true);
    $_GET = ['busca' => 'ZZPDU'];
    $htmlLista = $renderizar(static fn() => (new AdminPdisController())->index());
    ui_titulo_pagina(null, true);
    $htmlGrande = $renderizar(static fn() => (new AdminPdisController())->show((string)$grande));
    ui_titulo_pagina(null, true);
    $htmlPequeno = $renderizar(static fn() => (new AdminPdisController())->show((string)$pequeno));
    ui_titulo_pagina(null, true);
    $htmlCancel = $renderizar(static fn() => (new AdminPdisController())->show((string)$cancelado));
    ui_titulo_pagina(null, true);
    $_GET = ['busca' => 'ZZPDU Pequeno', 'contrato' => (string)$cPequeno];
    $htmlNovo = $renderizar(static fn() => (new AdminPdisController())->novo());
    $_GET = ['busca' => 'ZZPDU Pequeno'];
    ui_titulo_pagina(null, true);
    $htmlSelecao = $renderizar(static fn() => (new AdminPdisController())->novo());
    $_GET = [];
    ui_titulo_pagina(null, true);
    $htmlEditar = $renderizar(static fn() => (new AdminPdisController())->editar((string)$pequeno));
    $paginas = ['lista' => $htmlLista, 'grande' => $htmlGrande, 'pequeno' => $htmlPequeno, 'cancelado' => $htmlCancel, 'novo' => $htmlNovo, 'seleção' => $htmlSelecao, 'editar' => $htmlEditar];
    foreach ($paginas as $nome => $html) {
        $check(!preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $html) && str_contains($html, 'data-app-shell-v2') && !str_contains($html, 'data-admin-sidebar') && str_contains($html, 'aria-label="Trilha de navegação"') && str_contains($html, '<h1'), "(shell) Página \"{$nome}\": AppShell V2, sem sidebar antiga, com breadcrumb e PageHeader, sem Warning/Notice");
        $check(!str_contains($html, 'aria-label="Pessoas"') && !str_contains($html, 'aria-label="Recrutamento e Seleção"'), "(navegação) Página \"{$nome}\": sem ModuleTabs artificiais");
        $check(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html) && !preg_match('/\son(click|submit|change|load|input|keyup|keydown)\s*=/i', $html), "(CSP) Página \"{$nome}\": sem <script> inline nem handlers inline");
    }
    $check(str_contains($htmlLista, '<title>') && str_contains($htmlLista, 'PDI') && preg_match('#href="[^"]*/admin/pdis/novo"#', $htmlLista) === 1 && str_contains($htmlLista, 'ZZPDU Grande Concluida'), '(lista) Título, "Novo PDI" (tem pdi.gerenciar) e os PDIs aparecem');
    $check(str_contains($htmlLista, 'name="status"') && str_contains($htmlLista, 'name="prazo"') && str_contains($htmlLista, 'name="gestor"') && !preg_match('/name="(area|setor)"/i', $htmlLista), '(lista) Filtros preservados, sem Área');

    // ---- detalhe grande ---------------------------------------------------------------------------------------------------------
    foreach (['identificacao', 'desenvolvimento', 'competencias', 'plano-de-acao', 'espaco-colaborador', 'acompanhamentos', 'evidencias', 'avaliacao-final', 'historico'] as $secao) {
        $check(str_contains($htmlGrande, 'id="' . $secao . '"'), "(detalhe grande) Seção #{$secao} preservada");
    }
    $check(str_contains($htmlGrande, 'PDI concluído.') && str_contains($htmlGrande, 'bg-success/10') && !str_contains($htmlGrande, 'PDI cancelado.'), '(detalhe grande) Concluído: aviso e tom de sucesso');
    $check(substr_count($htmlGrande, 'Acompanhamento ') >= 5 && str_contains($htmlGrande, 'Objetivo atingido') && str_contains($htmlGrande, 'Certificado do curso'), '(detalhe grande) Acompanhamentos, avaliação final e evidências exibidos');
    $check(str_contains($htmlGrande, 'divide-y divide-border') && substr_count($htmlGrande, 'rounded-ds-lg border border-border') <= 3, '(detalhe grande) Painel único com divisores — não dezenas de cards');
    $check(str_contains($htmlGrande, 'border-l border-border') && str_contains($htmlGrande, 'rounded-full bg-primary-700'), '(detalhe grande) Histórico como linha do tempo');
    $check(!str_contains($htmlGrande, '/admin/pdis/' . $grande . '/iniciar') && !str_contains($htmlGrande, '/admin/pdis/' . $grande . '/editar') && str_contains($htmlGrande, '/admin/pdis/' . $grande . '/reabrir'), '(detalhe grande) Concluído: sem iniciar/editar; reabrir (auditado) disponível para RH');
    // ---- detalhe pequeno / cancelado ------------------------------------------------------------------------------------------------
    $check(str_contains($htmlPequeno, '/admin/pdis/' . $pequeno . '/editar') && str_contains($htmlPequeno, '/admin/pdis/' . $pequeno . '/liberar') && str_contains($htmlPequeno, 'Nenhuma competência informada') && str_contains($htmlPequeno, 'Nenhuma ação cadastrada') && str_contains($htmlPequeno, 'Nenhum acompanhamento registrado'), '(detalhe pequeno) Rascunho: editar/liberar disponíveis e estados vazios claros');
    $check(str_contains($htmlCancel, 'PDI cancelado.') && str_contains($htmlCancel, 'bg-danger/10') && str_contains($htmlCancel, 'Motivo de teste do cancelamento') && !str_contains($htmlCancel, '/admin/pdis/' . $cancelado . '/iniciar'), '(detalhe cancelado) Aviso, tom de perigo, motivo preservado e sem ações de fluxo');
    $check(str_contains($htmlEditar, 'name="csrf"') && str_contains($htmlEditar, 'name="gestor_usuario_id"') && str_contains($htmlEditar, 'name="acoes[3][descricao]"') && !str_contains($htmlEditar, 'acoes[4]'), '(edição) Campos, CSRF e limite de 3 ações preservados');
    $check(str_contains($htmlNovo, 'name="metadados_id"') && str_contains($htmlNovo, 'name="gestor_usuario_id"') && str_contains($htmlNovo, 'name="csrf"'), '(criação) Formulário com contrato, gestor e CSRF');
    $check(str_contains($htmlSelecao, 'ZZPDU Pequeno Rascunho') && !str_contains($htmlSelecao, '52998224725'), '(criação) Seleção de contrato sem CPF');
    $check(str_contains(ui_badge('X', 'success'), 'bg-success') || str_contains(ui_badge('X', 'success'), 'success'), '(badge) ui_badge produz tom de sucesso');
    $check(str_contains($htmlLista . $htmlGrande . $htmlCancel, 'ui-badge') || preg_match('/text-ds-badge/', $htmlLista . $htmlGrande) === 1, '(badge) Status renderizados pelo componente de badge');

    // ---- autorização/escopo ---------------------------------------------------------------------------------------------------------
    $comoUsuario($gestorA);
    $htmlGestorLista = $renderizar(static fn() => (new AdminPdisController())->index());
    $htmlGestorAlheio = $renderizar(static fn() => (new AdminPdisController())->show((string)$alheio));
    $htmlGestorProprio = $renderizar(static fn() => (new AdminPdisController())->show((string)$pequeno));
    $check(str_contains($htmlGestorLista, 'ZZPDU Grande Concluida') && !str_contains($htmlGestorLista, 'ZZPDU Alheio Do B'), '(escopo) Gestor vê só os próprios PDIs na lista');
    $check(str_contains($htmlGestorAlheio, 'PDI não encontrado') && !str_contains($htmlGestorAlheio, 'ZZPDU Alheio'), '(escopo) Gestor recebe "não encontrado" no PDI de outro gestor');
    $check(str_contains($htmlGestorProprio, 'data-app-shell-v2'), '(escopo) Gestor abre o próprio PDI no AppShell V2');
    $comoUsuario($adm);
    $htmlAdminLista = $renderizar(static fn() => (new AdminPdisController())->index());
    $check(str_contains($htmlAdminLista, 'ZZPDU Alheio Do B') && str_contains($htmlAdminLista, 'ZZPDU Grande Concluida'), '(escopo) Admin vê tudo (bypass central)');
    $comoUsuario($rh);
    $htmlRhLista = $renderizar(static fn() => (new AdminPdisController())->index());
    $check(str_contains($htmlRhLista, 'ZZPDU Alheio Do B'), '(escopo) RH com permissão vê todos');

    // Bloqueados: o gate `requirePermissao` responde 403 com exit (não dá para renderizar); prova-se pela permissão e pelo serviço.
    foreach ([['RH sem permissão individual', $semPerm], ['Supervisor sem permissão', $supSem]] as [$rotulo, $u]) {
        $ator = ['id' => $u['id'], 'role' => $u['role'], 'supervisor' => $u['supervisor']];
        $check(!Authorization::usuarioTemPermissao($u['id'], 'pdi.visualizar') && $svc->detalhe($grande, $ator, $hoje) === null && $svc->listar([], $ator, $hoje)['ok'] === false, "(autorização) {$rotulo} não tem pdi.visualizar, não abre o detalhe e não lista");
    }
    $regrasNav = (string)file_get_contents(BASE_PATH . '/app/services/PortalNavegacaoService.php');
    $check(str_contains($regrasNav, 'perm:pdi.visualizar'), '(autorização) A Central só mostra o card de PDI a quem tem pdi.visualizar');
    $comoUsuario($soVis);
    $check(!str_contains($renderizar(static fn() => (new AdminPdisController())->index()), 'href="/admin/pdis/novo"'), '(autorização) Só visualizar não recebe "Novo PDI"');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() === $permissoesAntes, '(permissões) Nenhuma permissão nova foi criada');

    // ---- Central, controller e fontes -------------------------------------------------------------------------------------------------
    $svcNav = (string)$fonte('app/services/PortalNavegacaoService.php');
    $check(preg_match("#'/admin/pdis'[^\\n]*perm:pdi\\.visualizar#", $svcNav) === 1 || (str_contains($svcNav, '/admin/pdis') && str_contains($svcNav, 'perm:pdi.visualizar')), '(Central) O card de PDI continua na Central com a regra perm:pdi.visualizar');
    $controller = $fonte('app/controllers/AdminPdisController.php');
    $check(!str_contains($controller, "'layouts/admin'") && substr_count($controller, "'layouts/app-shell'") === 4, '(controller) As quatro renderizações usam o AppShell V2; nada mais mudou de layout');
    $check(substr_count($controller, 'requirePermissao(') >= 4, '(controller) Gates por permissão preservados');
    foreach (['_helpers', 'form', 'index', 'selecionar-contrato', 'show'] as $v) {
        $c = $fonte("app/views/admin/pdis/{$v}.php");
        $semComentarios = preg_replace('#/\*.*?\*/#s', '', $c);
        $check(!preg_match('/bg-\[#|text-\[#|border-\[#|#[0-9A-Fa-f]{6}\b/', (string)$semComentarios) && !str_contains($c, 'responsive-panel') && !preg_match('/<script\b/i', $c), "(tokens) views/admin/pdis/{$v}.php sem hex solto, sem responsive-panel e sem <script>");
    }
    $check(!preg_match('/Avaliacao|Feedback|avaliacao_experiencia_|colaboradores\.visualizar/', preg_replace('#origem_tipo|avaliacao_experiencia#', '', $fonte('app/views/admin/pdis/show.php'))), '(escopo) O detalhe não ganhou dependência de Avaliação de Experiência/Feedback nem de colaboradores.visualizar');

    echo "\nPORTAL_MODULO_PDI_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    $_GET = $getOriginal;
    if (!empty($criados['metadados'])) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM pdis WHERE metadados_id IN (SELECT id FROM colaboradores_metadados WHERE identificador IN ($ph))");
        $ids->execute($criados['metadados']);
        $lista = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($lista !== []) {
            $in = implode(',', $lista);
            $pdo->exec("DELETE FROM pdi_eventos WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_acoes WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdi_competencias WHERE pdi_id IN ($in)");
            $pdo->exec("DELETE FROM pdis WHERE id IN ($in)");
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
    if (!empty($criados['usuarios'])) {
        $lu = implode(',', array_map('intval', $criados['usuarios']));
        $pdo->exec("DELETE FROM pdi_eventos WHERE ator_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM pdi_acompanhamentos WHERE autor_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM pdis WHERE gestor_usuario_id IN ($lu) OR criado_por_usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuario_permissoes WHERE usuario_id IN ($lu)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)");
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
