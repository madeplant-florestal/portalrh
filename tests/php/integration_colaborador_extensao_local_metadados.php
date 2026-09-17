<?php

/**
 * Integração — Correção urgente do vínculo entre colaborador oficial (colaboradores_metadados) e
 * extensão local (colaboradores).
 *
 * Prova que:
 *   - colaborador oficial COM extensão local: dados oficiais (matrícula/CPF/salário/datas/motivo
 *     de rescisão/nome/cargo/empresa/setor "de exibição") vencem qualquer valor divergente já
 *     gravado na extensão local (caso real "Fabio");
 *   - "Código" continua vindo exclusivamente da extensão local (único campo sem equivalente
 *     oficial), mesmo quando há contrato oficial vinculado;
 *   - colaborador oficial SEM extensão local (caso real "Fabiane") deixa de ficar bloqueado:
 *     ColaboradorExtensaoLocalService::obterOuCriar() materializa sob demanda, sem usar CPF, sem
 *     alterar colaboradores_metadados;
 *   - a materialização é idempotente (chamadas repetidas nunca duplicam);
 *   - cargo_id é resolvido pelo código oficial (codigo_cargo) — se não resolver, falha
 *     explicitamente em vez de inventar um cargo;
 *   - Colaborador::updateRhData() não deixa sobrescrever campos oficiais quando há contrato
 *     vinculado — só "Código" é aceito;
 *   - o fluxo de Integração e a Pesquisa de Integração continuam funcionando após a
 *     materialização, com pesquisas_integracao.colaborador_id apontando para colaboradores.id
 *     (nunca para o metadados_id);
 *   - nenhuma escrita em colaboradores_metadados em nenhum dos fluxos novos;
 *   - a listagem/telas administrativas continuam exigindo Auth::requireRole(['admin','rh']).
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
$criados = ['cargos' => [], 'setores' => [], 'empresas' => [], 'colaboradores' => [], 'metadados' => [], 'usuarios' => []];

$mkMetadados = static function (
    string $codigoPessoa,
    ?string $codigoCargo,
    ?string $codigoSetor,
    ?string $codigoEmpresa,
    float $salario,
    string $admissao,
    bool $ativo
) use ($pdo, &$criados, $suffix): int {
    $numeroContrato = 'C' . substr(md5($suffix . $codigoPessoa), 0, 10);
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
            nome, cargo, codigo_cargo, setor, codigo_setor, empresa, salario_atual, admissao,
            data_inicio_cargo, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'ZZVINC_' . $suffix . '_' . $codigoPessoa, $codigoEmpresa, 'ZZUNI', $numeroContrato, $codigoPessoa,
        'ZZ Fixture ' . $codigoPessoa, 'ZZ Cargo Fixture', $codigoCargo, 'ZZ Setor Fixture', $codigoSetor,
        'ZZ Empresa Fixture', $salario, $admissao, $admissao, $ativo ? 1 : 0, 'zzvinc-teste',
    ]);
    $id = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $id;
    return $id;
};

try {
    // ---- Fixtures de catálogo oficial (cargo/setor/empresa com código real) --------------------
    $codigoCargo = 'ZC' . substr($suffix, -6);
    $codigoSetor = 'ZS' . substr($suffix, -6);
    $codigoEmpresa = 'ZE' . substr($suffix, -6);

    $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$codigoCargo, 'ZZ Cargo Fixture ' . $suffix, 'zzvinc-cargo-' . $suffix]);
    $cargoLocalId = (int)$pdo->lastInsertId();
    $criados['cargos'][] = $cargoLocalId;

    $pdo->prepare('INSERT INTO empresas (codigo_empresa, nome, slug, ativo) VALUES (?, ?, ?, 1)')
        ->execute([$codigoEmpresa, 'ZZ Empresa Fixture ' . $suffix, 'zzvinc-empresa-' . $suffix]);
    $empresaLocalId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaLocalId;

    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, empresa_id, ativo) VALUES (?, ?, ?, ?, 1)')
        ->execute([$codigoSetor, 'ZZ Setor Fixture ' . $suffix, 'zzvinc-setor-' . $suffix, $empresaLocalId]);
    $setorLocalId = (int)$pdo->lastInsertId();
    $criados['setores'][] = $setorLocalId;

    // ================================================================================================
    // Caso "FABIO": contrato oficial COM extensão local divergente
    // ================================================================================================
    $metaFabio = $mkMetadados('ZZFABIO', $codigoCargo, $codigoSetor, $codigoEmpresa, 10334.00, '2025-01-16', true);

    $pdo->prepare(
        'INSERT INTO colaboradores (nome, slug, cargo_id, empresa_id, setor_id, metadados_id, codigo, matricula, cpf, salario_atual, data_admissao, data_inicio_cargo, ativo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        'Nome Antigo Divergente', 'zzvinc-fabio-local-' . $suffix, $cargoLocalId, $empresaLocalId, $setorLocalId, $metaFabio,
        'MP000008', 'MP000008-LOCAL-ANTIGA', '11111111111', 4500.00, '2025-01-16', '2026-03-16',
    ]);
    $colaboradorFabioId = (int)$pdo->lastInsertId();
    $criados['colaboradores'][] = $colaboradorFabioId;

    $fabio = Colaborador::find($colaboradorFabioId);
    $check($fabio !== null, 'Fixture "Fabio" (extensão local divergente) foi criada e é encontrada');
    $check(($fabio['tem_extensao_oficial'] ?? false) === true, '(1) Colaborador::find() marca tem_extensao_oficial = true quando há metadados_id');
    $check((float)$fabio['salario_atual'] === 10334.00, '(6)(8) Salário oficial (10334.00) vence o valor divergente da extensão local (4500.00)');
    $check((string)$fabio['data_admissao'] === '2025-01-16', 'Data de admissão vem do espelho oficial, não da extensão local');
    $check((string)$fabio['nome'] === 'ZZ Fixture ZZFABIO', 'Nome vem do espelho oficial, não do valor local divergente ("Nome Antigo Divergente")');
    $check((string)$fabio['matricula'] === 'C' . substr(md5($suffix . 'ZZFABIO'), 0, 10), 'Matrícula exibida passa a ser o número de contrato oficial, não o valor local divergente');
    $check((string)$fabio['cargo_nome'] === 'ZZ Cargo Fixture', 'Nome do cargo de exibição vem do texto oficial do espelho');
    $check((string)$fabio['empresa_nome'] === 'ZZ Empresa Fixture', 'Nome da empresa de exibição vem do texto oficial do espelho');
    $check((string)$fabio['setor_nome'] === 'ZZ Setor Fixture', 'Nome do setor de exibição vem do texto oficial do espelho');
    $check((string)$fabio['codigo'] === 'MP000008', '(7) "Código" (campo genuinamente local, sem equivalente oficial) continua vindo da extensão local');

    // ---- updateRhData(): com contrato oficial, só "Código" pode ser alterado -------------------
    $rTentativaOficial = Colaborador::updateRhData($colaboradorFabioId, [
        'codigo' => 'MP000008-NOVO',
        'matricula' => 'TENTATIVA-SOBRESCREVER',
        'salario_atual' => '999,00',
        'data_admissao' => '01/01/2000',
    ]);
    $check(($rTentativaOficial['ok'] ?? false) === true, '(12) updateRhData() aceita a chamada quando só "Código" é alterado, mesmo com outros campos no payload');
    $fabioDepois = Colaborador::find($colaboradorFabioId);
    $check((string)$fabioDepois['codigo'] === 'MP000008-NOVO', '(12b) "Código" foi realmente atualizado');
    $check((float)$fabioDepois['salario_atual'] === 10334.00, '(8) Tentativa de sobrescrever salário via POST é ignorada — salário oficial permanece 10334.00, nunca 999.00');
    $check((string)$fabioDepois['data_admissao'] === '2025-01-16', 'Tentativa de sobrescrever data de admissão via POST é ignorada — permanece a data oficial');

    // ================================================================================================
    // Caso "FABIANE": contrato oficial SEM extensão local (bloqueio "Sem extensão local")
    // ================================================================================================
    $metaFabiane = $mkMetadados('ZZFABIANE', $codigoCargo, $codigoSetor, $codigoEmpresa, 8000.00, '2025-02-01', true);

    $semExtensaoAntes = $pdo->prepare('SELECT id FROM colaboradores WHERE metadados_id = ?');
    $semExtensaoAntes->execute([$metaFabiane]);
    $check($semExtensaoAntes->fetch() === false, 'Fixture "Fabiane" nasce sem nenhuma linha em colaboradores (reproduz "Sem extensão local")');

    $resFabiane1 = ColaboradorExtensaoLocalService::obterOuCriar($metaFabiane);
    $check(($resFabiane1['ok'] ?? false) === true, '(2)(9) Materialização sob demanda é bem-sucedida para contrato oficial sem extensão local');
    $check(($resFabiane1['ja_existia'] ?? true) === false, 'Primeira chamada não é "já existia"');
    $colaboradorFabianeId = (int)$resFabiane1['id'];
    $criados['colaboradores'][] = $colaboradorFabianeId;

    $fabiane = Colaborador::find($colaboradorFabianeId);
    $check($fabiane !== null, 'Colaborador Fabiane materializado é encontrado por Colaborador::find()');
    $check((int)($fabiane['cargo_id'] ?? 0) === $cargoLocalId, 'cargo_id local foi resolvido pelo codigo_cargo oficial (nunca NULL — schema exige NOT NULL)');
    $check((int)($fabiane['empresa_id'] ?? 0) === $empresaLocalId, 'empresa_id local foi resolvido pelo codigo_empresa oficial');
    $check((int)($fabiane['setor_id'] ?? 0) === $setorLocalId, 'setor_id local foi resolvido pelo codigo_setor oficial');
    $check((float)$fabiane['salario_atual'] === 8000.00, 'Dados oficiais da Fabiane aparecem corretamente após materialização');

    // ---- (3)(4)(5) idempotência: chamadas repetidas nunca duplicam ------------------------------
    $resFabiane2 = ColaboradorExtensaoLocalService::obterOuCriar($metaFabiane);
    $check(($resFabiane2['ja_existia'] ?? false) === true, '(3) Segunda chamada retorna "já existia"');
    $check((int)$resFabiane2['id'] === $colaboradorFabianeId, '(4) Segunda chamada retorna o MESMO id local, não cria outro');
    $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM colaboradores WHERE metadados_id = ?');
    $stmtDup->execute([$metaFabiane]);
    $check((int)$stmtDup->fetchColumn() === 1, '(5) Nenhuma linha duplicada foi criada para o mesmo metadados_id');

    // ---- CPF nunca é usado para localizar/criar o vínculo ----------------------------------------
    $check(
        !preg_match('/WHERE\s+cpf\s*=|\bcpf\b.*=.*\?.*colaboradores_metadados|colaboradores_metadados.*cpf.*WHERE/i', (string)file_get_contents(APP_PATH . '/services/ColaboradorExtensaoLocalService.php')),
        'ColaboradorExtensaoLocalService não usa CPF para localizar ou criar o vínculo (só metadados_id)'
    );

    // ---- (10) nenhuma escrita em colaboradores_metadados -----------------------------------------
    $fontesAlteradas = [
        APP_PATH . '/services/ColaboradorExtensaoLocalService.php',
        APP_PATH . '/models/Colaborador.php',
        APP_PATH . '/controllers/AdminColaboradoresController.php',
    ];
    foreach ($fontesAlteradas as $arquivo) {
        $conteudo = (string)file_get_contents($arquivo);
        $check(
            !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\s+colaboradores_metadados\b/i', $conteudo),
            basename($arquivo) . ' (10) não contém nenhuma escrita em colaboradores_metadados'
        );
    }

    // ---- Cargo oficial não resolvido: falha explícita, não inventa cargo ------------------------
    $metaSemCargo = $mkMetadados('ZZSEMCARGO', 'ZINEX' . substr($suffix, -6), null, $codigoEmpresa, 3000.00, '2025-03-01', true);
    $resSemCargo = ColaboradorExtensaoLocalService::obterOuCriar($metaSemCargo);
    $check(($resSemCargo['ok'] ?? true) === false, 'Materialização falha explicitamente quando codigo_cargo não resolve a nenhum cargo local (não inventa cargo_id)');
    $stmtSemCargo = $pdo->prepare('SELECT COUNT(*) FROM colaboradores WHERE metadados_id = ?');
    $stmtSemCargo->execute([$metaSemCargo]);
    $check((int)$stmtSemCargo->fetchColumn() === 0, 'Nenhuma linha foi criada quando a resolução do cargo falhou');

    // ---- Setor sem código oficial: empresa/cargo resolvem, setor fica NULL (nunca inventado) -----
    $metaSemSetor = $mkMetadados('ZZSEMSETOR', $codigoCargo, null, $codigoEmpresa, 3500.00, '2025-03-01', true);
    $resSemSetor = ColaboradorExtensaoLocalService::obterOuCriar($metaSemSetor);
    $check(($resSemSetor['ok'] ?? false) === true, 'Materialização funciona mesmo sem codigo_setor (empresa/cargo resolvidos, setor fica NULL)');
    $colaboradorSemSetorId = (int)$resSemSetor['id'];
    $criados['colaboradores'][] = $colaboradorSemSetorId;
    $semSetorRow = Colaborador::find($colaboradorSemSetorId);
    $check(array_key_exists('setor_id', $semSetorRow) && $semSetorRow['setor_id'] === null, 'setor_id local fica NULL quando o METADADOS não informa codigo_setor — nunca inferido');

    // ================================================================================================
    // (11)(13) Fluxo de Integração + Pesquisa de Integração continuam funcionando após materialização
    // ================================================================================================
    $responsavelSenha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $responsavelId = User::create('ZZVINC Responsavel', 'responsavel.zzvinc.' . $suffix . '@teste.local', $responsavelSenha, 'rh');
    User::setActiveStatus($responsavelId, true);
    $criados['usuarios'][] = $responsavelId;

    $rIntegracao = Colaborador::updateIntegracao($colaboradorFabianeId, [
        'integracao_status' => 'realizada',
        'integracao_data' => '15/02/2025',
        'integracao_responsavel_usuario_id' => (string)$responsavelId,
    ]);
    $check(($rIntegracao['ok'] ?? false) === true, '(11) Integração pode ser registrada normalmente no colaborador recém-materializado (Fabiane)');

    $rPesquisa = PesquisaIntegracaoService::criarParaIntegracao($colaboradorFabianeId);
    $check(($rPesquisa['ok'] ?? false) === true, '(13) Pesquisa de Integração é gerada normalmente após a materialização');
    $check((int)$rPesquisa['pesquisa']['colaborador_id'] === $colaboradorFabianeId, '(13b) pesquisas_integracao.colaborador_id aponta para colaboradores.id (extensão local), nunca para o metadados_id');
    $check((int)$rPesquisa['pesquisa']['colaborador_id'] !== $metaFabiane && (int)$rPesquisa['pesquisa']['colaborador_id'] !== $metaFabiane, 'colaborador_id da pesquisa não é o metadados_id');

    // ---- (13-permissões) telas continuam exigindo Auth::requireRole(admin/rh) --------------------
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
    $check(str_contains($corpoDoMetodo(AdminColaboradoresController::class, 'index'), "Auth::requireRole(['admin', 'rh'])"), 'index() (listagem) continua exigindo admin/rh');
    $check(str_contains($corpoDoMetodo(AdminColaboradoresController::class, 'materializarExtensaoLocal'), "Auth::requireRole(['admin', 'rh'])"), 'materializarExtensaoLocal() exige admin/rh, mesma regra das demais ações do módulo');
    $check(str_contains($corpoDoMetodo(AdminColaboradoresController::class, 'materializarExtensaoLocal'), 'Security::csrfCheck'), 'materializarExtensaoLocal() exige CSRF, mesmo padrão das demais ações POST do módulo');

    // ---- smoke test: listagem e materialização de ponta a ponta renderizam sem erro --------------
    $adminSenha = password_hash('irrelevante', PASSWORD_BCRYPT);
    $adminId = User::create('ZZVINC Admin', 'admin.zzvinc.' . $suffix . '@teste.local', $adminSenha, 'admin');
    User::setActiveStatus($adminId, true);
    $criados['usuarios'][] = $adminId;

    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_is_supervisor'] = 0;
    $_GET = [];

    ob_start();
    $erroIndex = null;
    try {
        (new AdminColaboradoresController())->index();
    } catch (Throwable $e) {
        $erroIndex = $e;
    }
    $htmlIndex = ob_get_clean();
    $check($erroIndex === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlIndex), 'Listagem de colaboradores renderiza sem Warning/Notice/Fatal — ' . ($erroIndex?->getMessage() ?? 'ok'));

    ob_start();
    $erroEditFabio = null;
    try {
        (new AdminColaboradoresController())->editRh((string)$colaboradorFabioId);
    } catch (Throwable $e) {
        $erroEditFabio = $e;
    }
    $htmlEditFabio = ob_get_clean();
    $check($erroEditFabio === null && !preg_match('/Warning:|Notice:|Deprecated:|Fatal error/i', $htmlEditFabio), 'Tela de Dados RH do "Fabio" (com contrato oficial) renderiza sem Warning/Notice/Fatal — ' . ($erroEditFabio?->getMessage() ?? 'ok'));
    $check(str_contains($htmlEditFabio, '10.334,00'), 'Tela de Dados RH exibe o salário oficial (10.334,00), não o valor local divergente (4.500,00)');
    $check(!str_contains($htmlEditFabio, '4.500,00'), 'Tela de Dados RH NÃO exibe mais o salário local divergente (4.500,00)');

    // Nota: AdminColaboradoresController::materializarExtensaoLocal() SEMPRE termina com
    // redirect() (sucesso ou erro), e redirect() chama exit() — não é seguro invocá-lo em
    // processo dentro deste teste CLI (mataria o script antes do finally rodar). A lógica real
    // que ele delega (ColaboradorExtensaoLocalService::obterOuCriar()) já foi exercitada
    // exaustivamente acima (caso "Fabiane"); aqui só confirmamos que o controller REALMENTE
    // delega para o service, e que o service continua idempotente quando chamado de novo com o
    // mesmo metadados_id que o botão da listagem usaria.
    $check(
        str_contains($corpoDoMetodo(AdminColaboradoresController::class, 'materializarExtensaoLocal'), 'ColaboradorExtensaoLocalService::obterOuCriar'),
        'materializarExtensaoLocal() delega para ColaboradorExtensaoLocalService::obterOuCriar() (mesma lógica idempotente já testada acima)'
    );
    $corpoMaterializar = $corpoDoMetodo(AdminColaboradoresController::class, 'materializarExtensaoLocal');
    $check(
        str_contains($corpoMaterializar, '/rh/editar/')
        && str_contains($corpoMaterializar, '/acesso')
        && str_contains($corpoMaterializar, 'avaliacoes?colaborador_id='),
        'materializarExtensaoLocal() sabe prosseguir para os 3 destinos (Acesso/Dados RH/Avaliações) conforme o botão clicado'
    );

    echo "\nCOLABORADOR_EXTENSAO_LOCAL_OK\n";
} finally {
    if (!empty($criados['colaboradores'])) {
        $ids = implode(',', array_map('intval', array_unique($criados['colaboradores'])));
        $pdo->exec("DELETE FROM pesquisas_integracao WHERE colaborador_id IN ($ids)");
        $pdo->exec("DELETE FROM colaboradores WHERE id IN ($ids)");
    }
    if (!empty($criados['metadados'])) {
        $pdo->exec('DELETE FROM colaboradores_metadados WHERE id IN (' . implode(',', array_map('intval', $criados['metadados'])) . ')');
    }
    if (!empty($criados['setores'])) {
        $pdo->exec('DELETE FROM setores WHERE id IN (' . implode(',', array_map('intval', $criados['setores'])) . ')');
    }
    if (!empty($criados['empresas'])) {
        $pdo->exec('DELETE FROM empresas WHERE id IN (' . implode(',', array_map('intval', $criados['empresas'])) . ')');
    }
    if (!empty($criados['cargos'])) {
        $pdo->exec('DELETE FROM cargos WHERE id IN (' . implode(',', array_map('intval', $criados['cargos'])) . ')');
    }
    if (!empty($criados['usuarios'])) {
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(',', array_map('intval', $criados['usuarios'])) . ')')->execute();
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
