<?php

/**
 * Integração — Etapa 3: matriz horizontal de permissões e modelos automáticos por perfil.
 * Cobre o serviço (PermissaoMatrizService), o HTML da matriz, o JS (sem regra duplicada), o cadastro (store), a edição
 * (updatePermissoes com troca atômica de perfil), a rejeição de seleções forjadas e a preservação do legado. Requisições reais
 * de controller via fixture_requisicao_http.php (processo próprio). Fixtures ZZMAT*, limpas em `finally`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
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

$mk = 'zzmat' . substr(md5(uniqid('', true)), 0, 8);
$senhaHash = password_hash('irrelevante', PASSWORD_BCRYPT);
$criados = [];
$setoresCriados = [];
$permissaoInativa = null;

$mkUser = static function (string $suf, string $role, bool $supervisor = false) use (&$criados, $pdo, $mk, $senhaHash): int {
    $id = User::create('ZZMAT ' . $suf, "{$mk}.{$suf}@teste.local", $senhaHash, $role);
    User::setActiveStatus($id, true);
    if ($supervisor) {
        $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$id]);
    }
    $criados[] = $id;
    return $id;
};
$http = static function (int $userId, string $role, bool $sup, string $controller, string $metodo, array $post = [], array $args = [], array $get = []): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, $sup ? '1' : '0', $controller, $metodo, json_encode($get), json_encode($args), $post === [] ? '' : json_encode($post)],
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
$idsPorCodigo = [];
foreach ($pdo->query('SELECT id, codigo, ativo FROM permissoes') as $r) {
    $idsPorCodigo[$r['codigo']] = (int)$r['id'];
}
$ids = static fn(array $codigos): array => array_map(static fn(string $c): int => $idsPorCodigo[$c], $codigos);
$gravadas = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT p.codigo FROM usuario_permissoes up INNER JOIN permissoes p ON p.id = up.permissao_id WHERE up.usuario_id = ?');
    $st->execute([$id]);
    $r = $st->fetchAll(PDO::FETCH_COLUMN);
    sort($r);
    return $r;
};
$grava = static function (int $uid, array $codigos) use ($pdo, $idsPorCodigo): void {
    foreach ($codigos as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) VALUES (?, ?)')->execute([$uid, $idsPorCodigo[$c]]);
    }
};
$linhaUsuario = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT role, is_supervisor FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$usuarioPorEmail = static function (string $email) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT id, role, nome FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$ord = static function (array $a): array {
    sort($a);
    return array_values($a);
};
$marcadas = static function (array $matriz): array {
    $r = [];
    foreach ($matriz['areas'] as $a) {
        foreach ($a['linhas'] as $l) {
            foreach ($l['celulas'] as $c) {
                if ($c['marcada']) {
                    $r[] = $c['codigo'];
                }
            }
        }
    }
    sort($r);
    return $r;
};
$celula = static function (array $matriz, string $codigo): ?array {
    foreach ($matriz['areas'] as $a) {
        foreach ($a['linhas'] as $l) {
            foreach ($l['celulas'] as $c) {
                if ($c['codigo'] === $codigo) {
                    return $c;
                }
            }
        }
    }
    return null;
};
$novoUsuarioPost = static fn(string $suf, string $role, array $permissoes = [], array $extra = []): array => $extra + [
    'nome' => 'ZZMAT Novo ' . $suf, 'email' => "{$mk}.{$suf}@teste.local", 'senha' => 'Senha!Forte#2026', 'role' => $role, 'permissao_ids' => $permissoes,
];

try {
    $admin = $mkUser('admin', 'admin');
    $adminB = $mkUser('adminb', 'admin');
    $rh = $mkUser('rh', 'rh');
    $supGeral = $mkUser('sup', 'admin', true);
    $gestorModelo = PermissaoPerfilPolicy::modeloRecomendado('gestor');
    $viewerModelo = PermissaoPerfilPolicy::modeloRecomendado('viewer');
    $gestorAtivo = PermissaoPerfilPolicy::gestorAtivo();

    // ---- 1. Estrutura da matriz (serviço) --------------------------------------------------------------------
    $mViewer = PermissaoMatrizService::construir('viewer', [], false);
    $mGestor = PermissaoMatrizService::construir('gestor', [], false);
    $mRh = PermissaoMatrizService::construir('rh', [], false);
    $mAdmin = PermissaoMatrizService::construir('admin', [], false);

    $catalogo = $pdo->query('SELECT id, codigo, modulo FROM permissoes WHERE ativo = 1')->fetchAll(PDO::FETCH_ASSOC);
    $naMatriz = [];
    foreach ($mViewer['areas'] as $a) {
        foreach ($a['linhas'] as $l) {
            foreach ($l['celulas'] as $c) {
                $naMatriz[] = $c['codigo'];
            }
        }
    }
    $check($ord($naMatriz) === $ord(array_column($catalogo, 'codigo')) && count($naMatriz) === count(array_unique($naMatriz)), 'a matriz é construída do catálogo real: todas as permissões ativas, cada uma uma única vez');
    $check(count($mViewer['areas']) === 5 && array_column($mViewer['areas'], 'rotulo') === ['Indicadores e Dashboards', 'Gestão de Pessoas', 'Avaliações e Desenvolvimento', 'Recrutamento e Seleção', 'Administração e Configurações'], 'cinco áreas na ordem pedida; nenhuma permissão cai em "Outros" hoje');
    $exp = $celula($mViewer, 'avaliacao_experiencia.avaliar');
    $linhaExp = null;
    foreach ($mViewer['areas'] as $a) {
        foreach ($a['linhas'] as $l) {
            if ($l['modulo'] === 'avaliacao_experiencia') {
                $linhaExp = $l;
            }
        }
    }
    $check($exp !== null && $linhaExp !== null && $linhaExp['modulo_catalogo'] === ['avaliacoes_desenvolvimento'] && $exp['acao'] === 'avaliar', 'vínculo real código→módulo→ação: prefixo avaliacao_experiencia ≠ coluna modulo (avaliacoes_desenvolvimento) e a ação vem do código');
    $dag = $celula($mViewer, 'dashboard_integracao_agregado.visualizar');
    $check($dag !== null && isset($mViewer['colunas']['aprovar_lider']) && $celula($mViewer, 'solicitacao_vaga.aprovar_lider') !== null, 'ações reais preservadas (não só CRUD): aprovar_lider, resultados, assinar, publicar, acompanhar…; dashboard agregado de Integração tem linha própria');
    $check(isset($mViewer['colunas']['resultados'], $mViewer['colunas']['assinar'], $mViewer['colunas']['publicar'], $mViewer['colunas']['acompanhar'], $mViewer['colunas']['gerenciar'], $mViewer['colunas']['movimentar']), 'colunas incluem Resultados, Assinar, Publicar, Acompanhar, Gerenciar, Movimentar');

    // ---- 2. Modelos automáticos (vêm só da política) ---------------------------------------------------------
    $check($marcadas($mViewer) === $ord($viewerModelo) && $viewerModelo === ['dashboard_recrutamento.visualizar'], 'Viewer: o modelo marca só o dashboard sem risco de reidentificação (não todos os dashboards)');
    $check($marcadas($mRh) === [] && $marcadas($mAdmin) === [] && $mRh['global'] && $mAdmin['global'], 'Admin/RH: perfil global, nenhuma seleção de dezenas de checkboxes');
    if ($gestorAtivo) {
        $check($marcadas($mGestor) === $ord($gestorModelo) && $gestorModelo !== [] && !in_array('solicitacao_vaga.aprovar_lider', $gestorModelo, true) && !in_array('dashboard_integracao_agregado.visualizar', $gestorModelo, true), 'Gestor: o modelo marca o operacional setorial; aprovação de líder e dashboard agregado seguem permitidos, mas NÃO nascem marcados');
        $permitidasGestor = PermissaoPerfilPolicy::permissoesPermitidas('gestor');
        $check(array_diff($gestorModelo, $permitidasGestor) === [] && PermissaoPerfilPolicy::incompativeis('gestor', $gestorModelo) === [], 'Gestor: o modelo ⊆ permitidas e não contém módulo global');
        $c = $celula($mGestor, 'empresas.criar');
        $check($c !== null && !$c['aplicavel'] && !$c['marcada'], 'Gestor: módulos globais (Empresas) ficam não aplicáveis e desmarcados');
    }

    // ---- 3. Controles em lote (dados que o JS consome; a regra é da política) --------------------------------
    $p = $mViewer['perfis'];
    $permitidasViewer = ['dashboard.visualizar', 'indicadores_rh.visualizar', 'dashboard_turnover.visualizar', 'dashboard_recrutamento.visualizar', 'dashboard_entrevista_desligamento.visualizar'];
    $check($ord($p['viewer']['lote']) === $ord($ids($permitidasViewer)), 'Viewer: "Marcar todas" atua só nos dashboards agregados permitidos');
    $check(array_intersect($p['viewer']['aplicaveis'], $ids(['pdi.gerenciar', 'solicitacao_vaga.criar', 'kanban_vagas.movimentar', 'colaboradores.visualizar'])) === [] && $p['viewer']['transicao'] === [] && $ord($p['viewer']['aplicaveis']) === $ord($ids($permitidasViewer)), 'Viewer (Etapa 3.1): NÃO há novas concessões operacionais — o aplicável é só o dos dashboards (a transição só se mantém onde já gravada)');
    $check(array_intersect($p['viewer']['aplicaveis'], $ids(['empresas.criar', 'candidaturas.editar', 'integracao_colaborador.visualizar'])) === [], 'Viewer: permissões incompatíveis nunca são aplicáveis (nem pelo lote)');
    $check($p['admin']['lote'] === [] && $p['rh']['lote'] === [] && $p['admin']['global'] && $p['admin']['aplicaveis'] === [], 'Admin/RH: lote vazio — "Marcar todas" não concede nada por permissão individual');
    $check(!in_array('is_supervisor', array_keys($p['admin']), true) && array_diff(array_keys($p), ['admin', 'rh', 'viewer', 'gestor']) === [], 'a matriz só conhece perfis da política; não há perfil/flag de Supervisor Geral');
    if ($gestorAtivo) {
        $check(in_array($idsPorCodigo['solicitacao_vaga.aprovar_lider'], $p['gestor']['lote'], true) && $ord($p['gestor']['modelo']) === $ord($ids($gestorModelo)), 'Gestor: lote = permitidas do Gestor; modelo = política');
    }
    $rolesUsuario = PermissaoMatrizService::construir('viewer', [], false, ['viewer', 'rh']);
    $check(array_keys($rolesUsuario['perfis']) === ['viewer', 'rh'], 'os perfis oferecidos à matriz podem ser limitados (edição: só os atribuíveis pelo ator)');

    // ---- 4. HTML da matriz, acessibilidade e JS --------------------------------------------------------------
    require_once APP_PATH . '/views/partials/ui-shell.php';
    require_once APP_PATH . '/views/partials/matriz-permissoes.php';
    $html = ui_matriz_permissoes($mViewer, 'role');
    $nCheck = substr_count($html, 'data-matriz-celula="1"');
    $check($nCheck === count($catalogo) && substr_count($html, 'aria-label="') >= $nCheck, 'HTML: uma checkbox por permissão, cada uma com aria-label (módulo — ação)');
    $check(str_contains($html, 'overflow-x-auto') && str_contains($html, 'scope="col"') && str_contains($html, 'scope="row"') && str_contains($html, 'role="region"') && str_contains($html, 'aria-live="polite"'), 'HTML: rolagem horizontal, cabeçalhos com scope, região rotulada e contador em aria-live');
    $check(str_contains($html, 'Marcar todas') && str_contains($html, 'Desmarcar todas') && str_contains($html, 'Restaurar modelo do perfil') && str_contains($html, 'Marcar grupo') && str_contains($html, 'Legenda'), 'HTML: ações em lote, por grupo e legenda');
    $check(!str_contains($html, '<script') && !str_contains($html, ' onclick='), 'HTML: sem script inline (CSP)');
    $check(preg_match_all('/<input[^>]*disabled[^>]*>/', $html, $mm) > 0 && count(array_filter($mm[0], static fn($t) => str_contains($t, 'name="permissao_ids[]"'))) === 0, 'HTML: célula não aplicável é desabilitada e sem name (não é enviada)');
    $js = (string)file_get_contents(__DIR__ . '/../../assets/matriz-permissoes.js');
    $check(!preg_match('/\b[a-z_]+\.(visualizar|criar|editar|excluir|avaliar|gerenciar|movimentar|aprovar_lider|assinar|publicar)\b/', preg_replace('#/\*.*?\*/#s', '', $js)) && str_contains($js, 'window.confirm') && file_get_contents(__DIR__ . '/../../public/assets/matriz-permissoes.js') === $js, 'JS: nenhuma lista/código de permissão (tudo vem de data-perfis), pede confirmação na troca de perfil, cópia em public/assets igual');
    $check(str_contains($js, "ev.target.closest('[data-matriz-acao]')") && str_contains($js, 'data-matriz-contador'), 'JS: ações em lote e contador ligados por data-attributes');

    // ---- 5. Telas: acesso direto, estado inicial, preservação ------------------------------------------------
    [$st, $body] = $http($admin, 'admin', false, 'AdminUsuariosController', 'create');
    $check($st === 200 && str_contains($body, 'data-matriz="1"') && str_contains($body, 'id="role"') && str_contains($body, 'name="permissao_ids[]"'), 'cadastro (admin): tela com a matriz integrada ao select de perfil');
    $check(preg_match('/dashboard_recrutamento\.visualizar"[^>]*checked/', $body) === 1, 'cadastro: ao abrir, o modelo do perfil inicial (Viewer) já vem marcado');
    [$st] = $http($rh, 'rh', false, 'AdminUsuariosController', 'create');
    $check($st === 403, 'acesso direto: RH não abre o cadastro de usuários');
    $viewerU = $mkUser('viewer1', 'viewer');
    $grava($viewerU, ['pdi.visualizar']);
    [$st, $body] = $http($admin, 'admin', false, 'AdminUsuariosController', 'show', [], [(string)$viewerU]);
    $check($st === 200 && preg_match('/pdi\.visualizar"[^>]*checked/', $body) === 1 && preg_match('/dashboard_recrutamento\.visualizar"[^>]*checked/', $body) === 0, 'edição: abre com o que está GRAVADO (pdi.visualizar), sem aplicar o modelo recomendado');
    $check(str_contains($body, 'id="matriz-perfil"') && str_contains($body, 'Salvar permissões'), 'edição: seletor de perfil na matriz e botão de salvar (nada é salvo ao trocar o perfil)');
    [$st, $body] = $http($rh, 'rh', false, 'AdminUsuariosController', 'show', [], [(string)$viewerU]);
    $check($st === 200 && !str_contains($body, 'data-matriz="1"'), 'acesso direto: RH não vê a matriz de permissões (exclusiva de Admin)');
    $legadoU = $mkUser('legado', 'viewer');
    $grava($legadoU, ['empresas.criar', 'dashboard.visualizar']);
    [$st, $body] = $http($admin, 'admin', false, 'AdminUsuariosController', 'show', [], [(string)$legadoU]);
    $mLegado = PermissaoMatrizService::construir('viewer', Authorization::idsAtribuidos($legadoU), true);
    $cl = $celula($mLegado, 'empresas.criar');
    $check(($cl['legado_incompativel'] && $cl['marcada'] && !$cl['aplicavel'] && $mLegado['legado'] === ['empresas.criar']), 'legado: permissão incompatível gravada é sinalizada (marcada, sem efeito, não aplicável) — não reativada');
    $check(str_contains($body, 'não são reativadas') && str_contains($body, 'empresas.criar') && !PermissaoPerfilPolicy::permissaoEfetiva('viewer', 'empresas.criar') && !in_array('empresas.criar', PermissaoPerfilPolicy::permissoesEfetivas(User::findById($legadoU)), true), 'legado: aviso na tela; segue SEM efeito (política); diferenciação efetiva × armazenada');
    $check($gravadas($legadoU) === ['dashboard.visualizar', 'empresas.criar'], 'legado: abrir a tela não migra nem apaga nada');

    // ---- 5b. Layout: matriz em largura total na edição; cadastro intacto; contador de perfil global ------------------------
    $ancestraisDaMatriz = static function (string $htmlPagina): array {
        $d = new DOMDocument();
        @$d->loadHTML('<?xml encoding="utf-8"?>' . $htmlPagina);
        $x = new DOMXPath($d);
        $no = $x->query('//*[@data-matriz="1"]')->item(0);
        $r = ['achou' => $no !== null, 'dentroGrid' => false, 'dentroMax2xl' => false, 'secao' => false];
        for ($n = $no?->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            $r['dentroGrid'] = $r['dentroGrid'] || $n->hasAttribute('data-usuario-detalhe-colunas');
            $r['dentroMax2xl'] = $r['dentroMax2xl'] || str_contains(' ' . $n->getAttribute('class') . ' ', ' max-w-2xl ');
            $r['secao'] = $r['secao'] || $n->hasAttribute('data-usuario-permissoes-largura-total');
        }
        return $r;
    };
    [$st, $bodyEd] = $http($admin, 'admin', false, 'AdminUsuariosController', 'show', [], [(string)$viewerU]);
    $a = $ancestraisDaMatriz($bodyEd);
    $check($a['achou'] && !$a['dentroGrid'] && $a['secao'] && !$a['dentroMax2xl'] && str_contains($bodyEd, 'data-usuario-detalhe-colunas="1"') && str_contains($bodyEd, 'data-usuario-detalhe-coluna-esquerda="1"'), 'edição: a matriz fica numa seção independente em largura total, FORA do grid de duas colunas (que continua existindo)');
    [$st, $bodyCad] = $http($admin, 'admin', false, 'AdminUsuariosController', 'create');
    $a = $ancestraisDaMatriz($bodyCad);
    $check($a['achou'] && !$a['dentroMax2xl'] && !$a['dentroGrid'], 'cadastro: layout da matriz preservado (largura total, fora do bloco estreito dos campos)');
    $admComPerm = $mkUser('admperm', 'admin');
    $grava($admComPerm, ['pdi.visualizar', 'dashboard.visualizar']);
    [$st, $bodyAdm] = $http($admin, 'admin', false, 'AdminUsuariosController', 'show', [], [(string)$admComPerm]);
    $check(str_contains($bodyAdm, '2 atribuições individuais') && !str_contains($bodyAdm, '2 selecionadas') && str_contains($bodyAdm, 'acesso é global pelo perfil') && $gravadas($admComPerm) === ['dashboard.visualizar', 'pdi.visualizar'], 'perfil global: o contador mostra ATRIBUIÇÕES INDIVIDUAIS gravadas (não "selecionadas") e explica que o acesso vem do perfil; nada é alterado');
    $mAdminEd = PermissaoMatrizService::construir('admin', Authorization::idsAtribuidos($admComPerm), true);
    $check($mAdminEd['selecionadas'] === 2 && $mAdminEd['global'], 'o número do contador = linhas realmente gravadas em usuario_permissoes (não derivado do acesso global)');

    // ---- 6. Cadastro (store) ---------------------------------------------------------------------------------
    $post = $novoUsuarioPost('cv', 'viewer', $ids($viewerModelo));
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $post);
    $u = $usuarioPorEmail("{$mk}.cv@teste.local");
    if ($u) {
        $criados[] = (int)$u['id'];
    }
    $check($u !== null && $u['role'] === 'viewer' && $gravadas((int)$u['id']) === $ord($viewerModelo), 'cadastro de Viewer com o modelo aplicado: perfil + permissões gravados juntos');
    $setoresDoNovo = static fn(int $uid): int => (int)$pdo->query('SELECT COUNT(*) FROM usuario_setores_gerenciados WHERE usuario_id = ' . $uid)->fetchColumn();
    if ($gestorAtivo) {
        [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $novoUsuarioPost('cg', 'gestor', $ids($gestorModelo)));
        $u = $usuarioPorEmail("{$mk}.cg@teste.local");
        if ($u) {
            $criados[] = (int)$u['id'];
        }
        $check($u !== null && $u['role'] === 'gestor' && $gravadas((int)$u['id']) === $ord($gestorModelo), 'cadastro de Gestor com o modelo operacional setorial');
        $check($u !== null && $setoresDoNovo((int)$u['id']) === 0 && !EscopoSetorial::resolver((int)$u['id'])->ehGlobal() && !EscopoSetorial::resolver((int)$u['id'])->enxergaAlgo(), 'Gestor sem setores gerenciados: nenhum setor atribuído automaticamente, escopo restrito e vazio (a ausência de setores não vira acesso global)');
    }
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $novoUsuarioPost('crh', 'rh'));
    $u = $usuarioPorEmail("{$mk}.crh@teste.local");
    if ($u) {
        $criados[] = (int)$u['id'];
    }
    $check($u !== null && $u['role'] === 'rh' && $gravadas((int)$u['id']) === [], 'cadastro de RH: acesso global pelo perfil, nenhuma linha em usuario_permissoes');
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $novoUsuarioPost('cad', 'admin'));
    $u = $usuarioPorEmail("{$mk}.cad@teste.local");
    if ($u) {
        $criados[] = (int)$u['id'];
    }
    $check($u !== null && $u['role'] === 'admin' && $gravadas((int)$u['id']) === [] && (int)$linhaUsuario((int)$u['id'])['is_supervisor'] === 0, 'cadastro de Admin: sem permissões e SEM is_supervisor (a matriz nunca altera o flag)');

    // Rejeições: nada é criado, nada é gravado, mensagem clara, formulário preservado.
    $rejeita = static function (string $suf, array $post, string $msg, ?callable $extra = null) use ($http, $admin, $usuarioPorEmail, $mk, $check, &$criados): void {
        [$st, $body] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $post);
        $u = $usuarioPorEmail("{$mk}.{$suf}@teste.local");
        if ($u) {
            $criados[] = (int)$u['id'];
        }
        $check($u === null, "{$msg}: nenhum usuário criado (sem criação parcial)");
        if ($extra) {
            $extra($st, $body);
        }
    };
    $rejeita('ri', $novoUsuarioPost('ri', 'viewer', $ids(['empresas.criar'])), 'seleção incompatível (Viewer + Empresas)', static function ($st, $body) use ($check): void {
        $check(str_contains($body, 'Incompatível com o perfil VIEWER') && str_contains($body, 'ZZMAT Novo ri') && !str_contains($body, 'Senha!Forte'), 'erro claro, nome/e-mail preservados e a senha NÃO volta ao HTML');
    });
    $rejeita('rx', $novoUsuarioPost('rx', 'viewer', [999999999]), 'permissão inexistente', static function ($st, $body) use ($check): void { $check(str_contains($body, 'Permissão inexistente'), 'mensagem de erro clara: Permissão inexistente'); });
    $permissaoInativa = $pdo->prepare("INSERT INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES (?, 'zzmat', 'Inativa', 'teste', 9999, 0)");
    $permissaoInativa->execute(["zzmat_{$mk}.visualizar"]);
    $idInativa = (int)$pdo->lastInsertId();
    $rejeita('rin', $novoUsuarioPost('rin', 'viewer', [$idInativa]), 'permissão inativa', static function ($st, $body) use ($check): void { $check(str_contains($body, 'Permissão inativa'), 'mensagem de erro clara: Permissão inativa'); });
    $rejeita('rv', $novoUsuarioPost('rv', 'viewer', $ids(['solicitacao_vaga.aprovar_lider'])), 'Viewer tentando obter permissão de outro perfil (aprovar líder)', static function ($st, $body) use ($check): void { $check(str_contains($body, 'Incompatível com o perfil VIEWER'), 'mensagem de erro clara: Incompatível com o perfil VIEWER'); });
    $rejeita('rg', $novoUsuarioPost('rg', 'admin', $ids(['pdi.visualizar'])), 'seleção individual forjada para perfil global', static function ($st, $body) use ($check): void { $check(str_contains($body, 'acesso global pelo próprio perfil'), 'mensagem de erro clara: acesso global pelo próprio perfil'); });
    $rejeita('rp', $novoUsuarioPost('rp', 'superadmin', []), 'perfil inexistente', static function ($st, $body) use ($check): void { $check(str_contains($body, 'Perfil inválido'), 'mensagem de erro clara: Perfil inválido'); });
    $rejeita('rs', $novoUsuarioPost('rs', 'viewer', [], ['is_supervisor' => 1, 'role' => 'supervisor_geral']), 'tentativa de criar Supervisor Geral pela matriz');
    $rejeita('rc', $novoUsuarioPost('rc', 'viewer', $ids($viewerModelo), ['csrf' => 'invalido']), 'CSRF inválido');
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', $novoUsuarioPost('rc', 'viewer', [], ['csrf' => 'invalido']));
    $check($st === 400, 'CSRF inválido responde 400');
    [$st] = $http($rh, 'rh', false, 'AdminUsuariosController', 'store', $novoUsuarioPost('rrh', 'admin'));
    $check($st === 403 && $usuarioPorEmail("{$mk}.rrh@teste.local") === null, 'RH tentando criar um Admin: 403 e nada criado');
    // Falha no meio (e-mail já existente): a transação não deixa permissões nem usuário novo.
    $antes = $gravadas($viewerU);
    [$st, $body] = $http($admin, 'admin', false, 'AdminUsuariosController', 'store', ['email' => "{$mk}.viewer1@teste.local"] + $novoUsuarioPost('dup', 'viewer', $ids($viewerModelo)));
    $check((int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = '{$mk}.viewer1@teste.local'")->fetchColumn() === 1 && $gravadas($viewerU) === $antes && str_contains($body, 'Falha ao criar usuário'), 'falha no meio do cadastro (e-mail duplicado): nada parcial e permissões do usuário existente intactas');

    // ---- 7. Edição (updatePermissoes) ------------------------------------------------------------------------
    $e1 = $mkUser('e1', 'viewer');
    $grava($e1, ['pdi.visualizar']);
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => $ids(['pdi.visualizar', 'dashboard.visualizar'])], [(string)$e1]);
    $check($gravadas($e1) === ['dashboard.visualizar', 'pdi.visualizar'] && $linhaUsuario($e1)['role'] === 'viewer', 'edição: ajustes manuais compatíveis salvos (o modelo não foi reaplicado)');
    // sem o campo role: contrato anterior preservado
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => $ids(['pdi.visualizar'])], [(string)$e1]);
    $check($gravadas($e1) === ['pdi.visualizar'] && $linhaUsuario($e1)['role'] === 'viewer', 'edição sem o campo perfil: comportamento anterior preservado');
    if ($gestorAtivo) {
        [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'gestor', 'permissao_ids' => $ids($gestorModelo)], [(string)$e1]);
        $check($linhaUsuario($e1)['role'] === 'gestor' && $gravadas($e1) === $ord($gestorModelo), 'troca de perfil Viewer→Gestor aplicada junto com o modelo, numa única gravação');
        // troca com seleção incompatível com o NOVO perfil: nada muda (nem o perfil)
        $e2 = $mkUser('e2', 'viewer');
        $grava($e2, ['dashboard.visualizar']);
        [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'gestor', 'permissao_ids' => $ids(['dashboard.visualizar'])], [(string)$e2]);
        $check($linhaUsuario($e2)['role'] === 'viewer' && $gravadas($e2) === ['dashboard.visualizar'], 'troca para Gestor com permissão incompatível com o Gestor: rejeitada por inteiro (perfil e permissões inalterados)');
        // setores gerenciados não são tocados pela matriz (Gestor com múltiplos setores)
        foreach ([1, 2] as $n) {
            $cod = 'ZZ' . substr(md5($mk . $n), 0, 4);
            $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')->execute([$cod, "ZZMAT Setor {$n}", strtolower($cod), 'zz-teste']);
            $setorId = (int)$pdo->lastInsertId();
            $setoresCriados[] = $setorId;
            $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$e1, $setorId]);
        }
        $escopoAntes = EscopoSetorial::resolver($e1)->codigos();
        [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'gestor', 'permissao_ids' => $ids(['pdi.visualizar'])], [(string)$e1]);
        $check($setoresDoNovo($e1) === 2 && count($escopoAntes) === 2 && EscopoSetorial::resolver($e1)->codigos() === $escopoAntes && EscopoSetorial::resolver($e1)->tipo === EscopoSetorial::SETORES, 'Gestor com múltiplos setores: salvar a matriz não altera os setores gerenciados nem o escopo');
    }
    // perfil global: permissões individuais dispensadas
    $e3 = $mkUser('e3', 'viewer');
    $grava($e3, ['dashboard.visualizar']);
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'rh', 'permissao_ids' => []], [(string)$e3]);
    $check($linhaUsuario($e3)['role'] === 'rh' && $gravadas($e3) === [] && (int)$linhaUsuario($e3)['is_supervisor'] === 0, 'troca Viewer→RH com seleção vazia: perfil trocado, permissões removidas por ação explícita, is_supervisor intacto');
    $check($admin !== $e3, 'fixture');

    // Segurança da edição
    $auto = $linhaUsuario($admin);
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => []], [(string)$admin]);
    $check($st === 403 && $linhaUsuario($admin) === $auto, 'uma conta não altera o PRÓPRIO perfil (nem se promove/rebaixa) pela matriz');
    $antesSup = [$linhaUsuario($supGeral), $gravadas($supGeral)];
    [$st] = $http($adminB, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => $ids(['dashboard.visualizar'])], [(string)$supGeral]);
    $check($st === 403 && [$linhaUsuario($supGeral), $gravadas($supGeral)] === $antesSup, 'Admin comum não gerencia permissões nem perfil do Supervisor Geral');
    [$st] = $http($supGeral, 'admin', true, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'viewer', 'permissao_ids' => []], [(string)$supGeral]);
    $check($st === 403 && $linhaUsuario($supGeral) === $antesSup[0], 'o Supervisor Geral também não troca o próprio perfil por esta rota');
    $alvoRh = $mkUser('alvorh', 'viewer');
    [$st] = $http($rh, 'rh', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'admin', 'permissao_ids' => []], [(string)$alvoRh]);
    $check($st === 403 && $linhaUsuario($alvoRh)['role'] === 'viewer', 'RH tentando conceder Admin (ou editar permissões): 403, nada muda');
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['role' => 'admin', 'permissao_ids' => [], 'is_supervisor' => 1], [(string)$alvoRh]);
    $dep = $linhaUsuario($alvoRh);
    $check($dep['role'] === 'admin' && (int)$dep['is_supervisor'] === 0, 'Admin promove a conta a Admin; um is_supervisor forjado no POST é ignorado (flag só pelo fluxo próprio do Supervisor Geral)');
    $alvoF = $mkUser('alvof', 'viewer');
    $grava($alvoF, ['dashboard.visualizar']);
    foreach ([
        'perfil forjado' => ['role' => 'superadmin', 'permissao_ids' => $ids(['dashboard.visualizar'])],
        'id inexistente' => ['permissao_ids' => [999999999]],
        'id inativo' => ['permissao_ids' => [$idInativa]],
        'Viewer pedindo permissão de Gestor' => ['permissao_ids' => $ids(['dashboard.visualizar', 'solicitacao_vaga.aprovar_lider'])],
        'CSRF inválido' => ['csrf' => 'invalido', 'permissao_ids' => $ids(['pdi.visualizar'])],
    ] as $rotulo => $dados) {
        [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', $dados, [(string)$alvoF]);
        $check($gravadas($alvoF) === ['dashboard.visualizar'] && $linhaUsuario($alvoF)['role'] === 'viewer', "edição forjada/inválida ({$rotulo}): nada é alterado, nem parcialmente");
    }
    // legado: manter o incompatível bloqueia o salvamento; regularizar (desmarcar) é ação explícita
    $leg = $mkUser('leg2', 'viewer');
    $grava($leg, ['empresas.criar', 'dashboard.visualizar']);
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => $ids(['empresas.criar', 'dashboard.visualizar', 'pdi.visualizar'])], [(string)$leg]);
    $check($gravadas($leg) === ['dashboard.visualizar', 'empresas.criar'], 'legado incompatível mantido na seleção: salvamento recusado; a matriz não reativa nem apaga em silêncio');
    [$st] = $http($admin, 'admin', false, 'AdminUsuariosController', 'updatePermissoes', ['permissao_ids' => $ids(['dashboard.visualizar'])], [(string)$leg]);
    $check($gravadas($leg) === ['dashboard.visualizar'], 'regularização administrativa explícita (desmarcar o legado e salvar) é aceita');
    // Viewer legado transitório continua sendo política (não desativado nesta etapa)
    $check(PermissaoPerfilPolicy::classificar('viewer', 'pdi.visualizar') === PermissaoPerfilPolicy::TRANSITORIA_GESTOR && EscopoSetorial::VIEWER_LEGADO_TRANSITORIO === true, 'VIEWER_LEGADO_TRANSITORIO e as permissões de transição do Viewer seguem como estavam');

} finally {
    $pdo->prepare('DELETE FROM permissoes WHERE codigo = ?')->execute(["zzmat_{$mk}.visualizar"]);
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email LIKE ?');
    $st->execute([$mk . '.%@teste.local']);
    foreach (array_unique(array_merge($criados, $st->fetchAll(PDO::FETCH_COLUMN))) as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($setoresCriados as $setorId) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
    }
}

// O relatório fica FORA do try: exit dentro dele pularia o finally e deixaria usuários/permissão de teste no banco.
if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — matriz horizontal de permissões e modelos por perfil.\n";
