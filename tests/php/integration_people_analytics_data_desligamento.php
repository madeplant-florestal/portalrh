<?php

/**
 * Integração — coluna "Data de Desligamento" da listagem de Colaboradores do People Analytics (grid + CSV).
 *
 * Prova, pelo serviço real (PeopleAnalyticsService) e pelos endpoints reais (AdminController::index/exportarColaboradores, cada
 * requisição em processo próprio via fixture_requisicao_http.php):
 *   - a data vem exclusivamente de colaboradores_metadados.demissao do PRÓPRIO contrato, em DD/MM/AAAA;
 *   - sem demissão = "—" (nunca inferida pela Situação, nunca a data de hoje); null/vazio nunca viram data inválida;
 *   - desligamento futuro (contrato ainda vigente) mostra a data oficial sem mudar a Situação nem o Tempo de Empresa;
 *   - readmissão (mesmo CPF, dois contratos) não mistura as datas dos contratos;
 *   - funciona nos três contextos (ativos, admitidos, desligados);
 *   - posição Admissão | Data de Desligamento | Situação | Tempo de Empresa no HTML e no CSV, e o CSV traz exatamente o que o grid
 *     mostra (BOM UTF-8, separador ";");
 *   - paginação/filtros intactos; autorização da lista nominal preservada (viewer não recebe nomes nem CSV).
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

/** @return array{0:int,1:string} [status HTTP, corpo] */
$requisitar = static function (int $userId, string $role, string $metodo, array $get): array {
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/fixture_requisicao_http.php', (string)$userId, $role, '0', 'AdminController', $metodo, json_encode($get), '[]'],
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

/** Cabeçalho e linhas (células de texto) da tabela da listagem de Colaboradores no HTML. */
$tabelaListagem = static function (string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('table') as $tabela) {
        $ths = array_map(static fn(DOMElement $th): string => trim($th->textContent), iterator_to_array($tabela->getElementsByTagName('th')));
        if (!in_array('Tempo de Empresa', $ths, true) || !in_array('Gestor Imediato', $ths, true)) {
            continue;
        }
        $linhas = [];
        foreach ($tabela->getElementsByTagName('tbody')->item(0)?->getElementsByTagName('tr') ?? [] as $tr) {
            $linhas[] = array_map(static fn(DOMElement $td): string => trim($td->textContent), iterator_to_array($tr->getElementsByTagName('td')));
        }
        return ['cabecalho' => $ths, 'linhas' => $linhas];
    }
    return ['cabecalho' => [], 'linhas' => []];
};

/** CSV exportado -> [cabeçalho, linhas], validando BOM. */
$lerCsv = static function (string $corpo): array {
    $bom = str_starts_with($corpo, "\xEF\xBB\xBF");
    $corpo = $bom ? substr($corpo, 3) : $corpo;
    $linhas = array_values(array_filter(preg_split('/\r?\n/', $corpo), static fn(string $l): bool => trim($l) !== ''));
    $tab = array_map(static fn(string $l): array => str_getcsv($l, ';', '"', '\\'), $linhas);
    return ['bom' => $bom, 'cabecalho' => $tab[0] ?? [], 'linhas' => array_slice($tab, 1)];
};

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados' => [], 'usuarios' => []];
$empresa = 'ZZDD' . $suffix;
$prefixoNome = 'ZZDD ' . $suffix . ' ';

$insert = $pdo->prepare(
    'INSERT INTO colaboradores_metadados (
        identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
        cpf, nome, empresa, admissao, demissao, ativo, ausente_na_origem, origem_metadados,
        setor, codigo_setor, cargo, sexo, centro_custo, unidade
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
);
/** `ativo` segue a regra da sincronização METADADOS: DATARESCISAO preenchida => ativo = 0. */
$mk = function (string $sufixo, string $admissao, ?string $demissao, ?string $cpf = null) use ($insert, &$criados, $suffix, $empresa, $prefixoNome): string {
    $identificador = 'ZZDD_' . $suffix . '_' . $sufixo;
    $insert->execute([
        $identificador, $empresa, 'ZZU' . $suffix, 'ZZC' . substr(md5($suffix . $sufixo), 0, 14), 'ZZP' . substr(md5($suffix . $sufixo), 0, 14),
        $cpf ?? (string)random_int(10000000000, 99999999999), $prefixoNome . $sufixo, 'Empresa ZZDD', $admissao, $demissao, $demissao === null ? 1 : 0, 'zzdd-teste',
        'Setor ZZDD', 'STDD', 'Cargo ZZDD', 'M', 'CC ZZDD', 'Unidade ZZDD',
    ]);
    $criados['metadados'][] = $identificador;
    return $prefixoNome . $sufixo;
};

try {
    $hoje = DateHelper::hojeOperacional();
    $d = static fn(string $mod): string => $hoje->modify($mod)->format('Y-m-d');
    $br = static fn(string $ymd): string => (new DateTimeImmutable($ymd))->format('d/m/Y');

    $admAtivo = $d('-400 days');
    $nAtivo = $mk('ATIVO', $admAtivo, null);                                     // vigente, sem desligamento
    $demDeslig = $d('-90 days');
    $nDeslig = $mk('DESLIGADO', $d('-800 days'), $demDeslig);                    // desligado no período
    $demFutura = $d('+20 days');
    $nFuturo = $mk('FUTURO', $d('-500 days'), $demFutura);                        // desligamento programado: ainda vigente
    $cpfReadm = (string)random_int(10000000000, 99999999999);
    $demReadmAntigo = $d('-200 days');
    $nReadmAntigo = $mk('READM-A', $d('-1000 days'), $demReadmAntigo, $cpfReadm); // 1º contrato (encerrado)
    $admReadmNovo = $d('-60 days');
    $nReadmNovo = $mk('READM-B', $admReadmNovo, null, $cpfReadm);               // readmissão (vigente, admitido no período)

    $service = new PeopleAnalyticsService();
    $inicio = $hoje->modify('-12 months');
    $porNome = static function (array $itens): array {
        $m = [];
        foreach ($itens as $i) {
            $m[$i['nome']] = $i;
        }
        return $m;
    };

    // ---- 1. Serviço: três contextos ---------------------------------------------------------------------------------------------
    $ativos = $porNome($service->listarColaboradores(['codigo_empresa' => $empresa], $inicio, $hoje, 1, 20)['items']);
    $check(isset($ativos[$nAtivo], $ativos[$nFuturo], $ativos[$nReadmNovo]) && !isset($ativos[$nDeslig]) && !isset($ativos[$nReadmAntigo]), '(ativos) população vigente inalterada: ativo, desligamento futuro e readmissão; sem os encerrados');
    $check(($ativos[$nAtivo]['data_desligamento'] ?? null) === '—', '(ativos) colaborador ativo sem desligamento: "—" (nunca a data de hoje)');
    $check(($ativos[$nAtivo]['situacao'] ?? null) === 'Ativo', '(ativos) situação do ativo preservada: "Ativo"');
    $check(($ativos[$nFuturo]['data_desligamento'] ?? null) === $br($demFutura), '(ativos) desligamento futuro: mostra a data oficial do contrato (' . $br($demFutura) . ')');
    $check(($ativos[$nFuturo]['situacao'] ?? null) === 'Desligado', '(ativos) desligamento futuro: Situação segue a regra existente (flag ativo=0 do METADADOS) — não alterada por esta tarefa');
    $formatarTempo = new ReflectionMethod(PeopleAnalyticsService::class, 'formatarTempoEmpresa');
    $formatarTempo->setAccessible(true);
    $tempoEsperado = $formatarTempo->invoke($service, new DateTimeImmutable($d('-500 days')), new DateTimeImmutable($demFutura));
    $check(($ativos[$nFuturo]['tempo_empresa'] ?? null) === $tempoEsperado, '(ativos) Tempo de Empresa inalterado: admissão→demissão do contrato, como antes ("' . $tempoEsperado . '")');
    $check(($ativos[$nReadmNovo]['data_desligamento'] ?? null) === '—', '(readmissão) contrato novo NÃO herda a demissão do contrato anterior');

    $deslig = $porNome($service->listarColaboradores(['codigo_empresa' => $empresa, 'contexto_lista' => 'desligados'], $inicio, $hoje, 1, 20)['items']);
    $check(isset($deslig[$nDeslig], $deslig[$nReadmAntigo]) && !isset($deslig[$nFuturo]) && !isset($deslig[$nAtivo]), '(desligados) população por evento inalterada (desligamento futuro fora)');
    $check(($deslig[$nDeslig]['data_desligamento'] ?? null) === $br($demDeslig), '(desligados) data válida em DD/MM/AAAA: ' . $br($demDeslig));
    $check(preg_match('#^\d{2}/\d{2}/\d{4}$#', (string)($deslig[$nDeslig]['data_desligamento'] ?? '')) === 1, '(formato) DD/MM/AAAA');
    $check(($deslig[$nReadmAntigo]['data_desligamento'] ?? null) === $br($demReadmAntigo), '(readmissão) contrato antigo mantém a própria demissão (' . $br($demReadmAntigo) . ')');
    $check(($deslig[$nDeslig]['situacao'] ?? null) === 'Desligado', '(desligados) Situação preservada');

    $admit = $porNome($service->listarColaboradores(['codigo_empresa' => $empresa, 'contexto_lista' => 'admitidos'], $inicio, $hoje, 1, 20)['items']);
    $check(isset($admit[$nReadmNovo]) && ($admit[$nReadmNovo]['data_desligamento'] ?? null) === '—', '(admitidos) readmitido no período aparece com "—"');

    // null / vazio no enriquecimento (o banco não aceita '' em DATE; cobre a defesa do formatador)
    $enriquecer = new ReflectionMethod(PeopleAnalyticsService::class, 'enriquecerListagemColaboradores');
    $enriquecer->setAccessible(true);
    $linhasNulas = $enriquecer->invoke($service, [
        ['identificador' => 'X1', 'nome' => 'n1', 'admissao' => '2020-01-01', 'demissao' => null, 'ativo' => 1],
        ['identificador' => 'X2', 'nome' => 'n2', 'admissao' => '2020-01-01', 'demissao' => '', 'ativo' => 0],
    ]);
    $check(($linhasNulas[0]['data_desligamento'] ?? null) === '—' && ($linhasNulas[1]['data_desligamento'] ?? null) === '—', '(nulo/vazio) demissão null ou "" => "—", nunca data inválida');
    $check(array_keys($linhasNulas[0]) === ['nome', 'empresa', 'unidade', 'setor', 'cargo', 'sexo', 'admissao', 'data_desligamento', 'situacao', 'tempo_empresa', 'centro_custo', 'gestor_imediato'], '(contrato) chaves do registro enriquecido, com data_desligamento logo após admissao');

    // ---- 2. Endpoints: HTML do grid e CSV ---------------------------------------------------------------------------------------
    $admin = User::create('ZZDD Admin', 'admin.zzdd.' . $suffix . '@teste.local', password_hash('irrelevante', PASSWORD_BCRYPT), 'admin');
    User::setActiveStatus($admin, true);
    $criados['usuarios'][] = $admin;
    $viewer = User::create('ZZDD Viewer', 'viewer.zzdd.' . $suffix . '@teste.local', password_hash('irrelevante', PASSWORD_BCRYPT), 'viewer');
    User::setActiveStatus($viewer, true);
    $criados['usuarios'][] = $viewer;
    $permDash = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'dashboard.visualizar'")->fetchColumn();
    Authorization::sincronizar($viewer, [$permDash]);

    $ordemEsperada = ['Admissão', 'Data de Desligamento', 'Situação', 'Tempo de Empresa'];
    foreach (['ativos' => [$nAtivo, $nFuturo, $nReadmNovo], 'desligados' => [$nDeslig, $nReadmAntigo], 'admitidos' => [$nReadmNovo]] as $contexto => $nomes) {
        $get = ['empresa' => $empresa, 'periodo' => '12m', 'contexto_lista' => $contexto, 'por_pagina' => '50'];
        [$st, $html] = $requisitar($admin, 'admin', 'index', $get);
        $tab = $tabelaListagem($html);
        $iAdm = array_search('Admissão', $tab['cabecalho'], true);
        $check($st === 200 && $iAdm !== false && array_slice($tab['cabecalho'], (int)$iAdm, 4) === $ordemEsperada, "(HTML {$contexto}) cabeçalho: Admissão | Data de Desligamento | Situação | Tempo de Empresa");
        $check(count($tab['cabecalho']) === 12 && count($tab['linhas'][0] ?? []) === 12, "(HTML {$contexto}) 12 colunas no cabeçalho e nas linhas (nenhuma desalinhada)");

        [$stCsv, $csvCorpo] = $requisitar($admin, 'admin', 'exportarColaboradores', $get);
        $csv = $lerCsv($csvCorpo);
        $check($stCsv === 200 && $csv['bom'], "(CSV {$contexto}) 200 com BOM UTF-8");
        $check($csv['cabecalho'] === ['Nome', 'Empresa', 'Unidade', 'Setor', 'Cargo', 'Sexo', 'Admissão', 'Data de Desligamento', 'Situação', 'Tempo de Empresa', 'Centro de Custo', 'Gestor Imediato'], "(CSV {$contexto}) cabeçalho com Data de Desligamento logo após Admissão; demais colunas na ordem anterior");

        // grid × CSV: mesma população e as mesmas células, linha a linha
        $gridPorNome = [];
        foreach ($tab['linhas'] as $l) {
            $gridPorNome[$l[0]] = $l;
        }
        $csvPorNome = [];
        foreach ($csv['linhas'] as $l) {
            $csvPorNome[$l[0]] = $l;
        }
        $check(count($csvPorNome) === count($gridPorNome) && array_keys($csvPorNome) == array_keys($gridPorNome), "(grid×CSV {$contexto}) mesma população");
        $iguais = true;
        foreach ($nomes as $n) {
            $iguais = $iguais && isset($gridPorNome[$n], $csvPorNome[$n]) && $gridPorNome[$n] === $csvPorNome[$n];
        }
        $check($iguais, "(grid×CSV {$contexto}) cada linha do CSV é idêntica à do grid, inclusive Data de Desligamento");
        if ($contexto === 'ativos') {
            $check(($gridPorNome[$nFuturo][7] ?? null) === $br($demFutura) && ($gridPorNome[$nAtivo][7] ?? null) === '—', '(HTML ativos) célula da coluna: data oficial / "—"');
        }
        if ($contexto === 'desligados') {
            $check(($gridPorNome[$nDeslig][7] ?? null) === $br($demDeslig) && ($csvPorNome[$nDeslig][7] ?? null) === $br($demDeslig), '(desligados) mesma data no grid e no CSV');
        }
    }

    // ---- 3. Paginação e autorização ----------------------------------------------------------------------------------------------
    $pag = $service->listarColaboradores(['codigo_empresa' => $empresa], $inicio, $hoje, 1, 20);
    $check($pag['total'] === 3 && $pag['pages'] === 1 && $pag['per_page'] === 20, '(paginação) total/páginas inalterados');
    [$st, $corpo] = $requisitar($viewer, 'viewer', 'index', ['empresa' => $empresa, 'periodo' => '12m']);
    $check($st === 200 && !str_contains($corpo, $prefixoNome) && !str_contains($corpo, 'Data de Desligamento'), '(autorização) viewer com dashboard.visualizar não recebe a lista nominal (nem a nova coluna)');
    [$st, $corpo] = $requisitar($viewer, 'viewer', 'exportarColaboradores', ['empresa' => $empresa, 'periodo' => '12m']);
    $check($st === 403 && !str_contains($corpo, $prefixoNome), '(autorização) viewer: CSV nominal 403 sem dados');

    echo "\nPEOPLE_ANALYTICS_DATA_DESLIGAMENTO_OK\n";
} finally {
    if ($criados['usuarios'] !== []) {
        $ph = implode(',', array_fill(0, count($criados['usuarios']), '?'));
        $pdo->prepare("DELETE FROM usuario_permissoes WHERE usuario_id IN ($ph)")->execute($criados['usuarios']);
        $pdo->prepare("DELETE FROM auditoria_usuarios WHERE target_usuario_id IN ($ph) OR actor_usuario_id IN ($ph)")->execute(array_merge($criados['usuarios'], $criados['usuarios']));
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($criados['usuarios']);
    }
    if ($criados['metadados'] !== []) {
        $ph = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($ph)")->execute($criados['metadados']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
