<?php

/**
 * Integração — exclusão analítica identificada pela CHAVE NATURAL do vínculo
 * (codigo_empresa + codigo_unidade + numero_contrato), migration
 * 2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql.
 *
 * Roda num BANCO DESCARTÁVEL (`rhm_zzexc_*`, criado e removido aqui): a estrutura de
 * `colaboradores_metadados`/`usuarios` é copiada do banco da aplicação (CREATE TABLE ... LIKE, só
 * leitura nele), a tabela de exclusões nasce pela migration 2026-10-29 e é evoluída pela
 * 2026-11-11 DENTRO do teste — o banco da aplicação nunca é alterado. A conexão de
 * `Database::conn()` é trocada por Reflection só durante o teste e restaurada no `finally`.
 * Só dados fictícios (nada de produção).
 *
 * Cobre: schema ainda NÃO migrado (modo legado), preservação das exclusões antigas após a
 * migration, exclusão pela chave natural (contrato existente/inexistente/duplicada), reconstrução
 * da base com novos IDs internos, ID interno reaproveitado, exclusão mantida após nova
 * sincronização, contrato de outra empresa/unidade/da mesma pessoa/transferência interempresa NÃO
 * afetados, reversão administrativa, dados brutos intactos e compatibilidade com
 * ColaboradorMetadadosConsultaRepository, PeopleAnalyticsRepository, RhIndicadoresRepository e
 * TurnoverDashboardRepository.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$cfg = Config::app()['database'];
$dsnSemBanco = preg_replace('/;?dbname=[^;]+/', '', (string)$cfg['dsn']);
$appPdo = Database::conn();
$bancoApp = (string)$appPdo->query('SELECT DATABASE()')->fetchColumn();

$novoPdo = static function (?string $banco, bool $emulate) use ($cfg, $dsnSemBanco): PDO {
    return new PDO($banco === null ? $dsnSemBanco : $dsnSemBanco . ';dbname=' . $banco, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => $emulate,
    ]);
};

$rodarArquivo = static function (PDO $pdo, string $arquivo): void {
    $linhas = [];
    foreach (preg_split('/\R/', (string)file_get_contents($arquivo)) as $linha) {
        if (!str_starts_with(ltrim($linha), '--')) {
            $linhas[] = $linha;
        }
    }
    foreach (preg_split('/;\s*\n/', implode("\n", $linhas) . "\n") as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $res = $pdo->query($stmt);
        if ($res instanceof PDOStatement) {
            $res->fetchAll();
            $res->closeCursor();
        }
    }
};

$trocarConexao = static function (PDO $pdo): void {
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
};
$resetarCacheServico = static function (): void {
    (new ReflectionProperty(ColaboradorMetadadosElegibilidadeService::class, 'modoCache'))->setValue(null, null);
    (new ReflectionProperty(ColaboradorMetadadosElegibilidadeService::class, 'avisouLegado'))->setValue(null, false);
};

$migrations = __DIR__ . '/../../database/migrations/';
$admin = $novoPdo(null, true);
$banco = 'rhm_zzexc_' . substr((string)time(), -5) . random_int(10, 99);
$admin->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4");

try {
    $db = $novoPdo($banco, false);   // conexão "da aplicação" durante o teste
    $mig = $novoPdo($banco, true);   // conexão para rodar arquivos .sql de migration
    $db->exec("CREATE TABLE usuarios LIKE `{$bancoApp}`.usuarios");
    $db->exec("CREATE TABLE colaboradores_metadados LIKE `{$bancoApp}`.colaboradores_metadados");
    $db->exec("CREATE TABLE colaboradores LIKE `{$bancoApp}`.colaboradores"); // paginate() faz LEFT JOIN (só estrutura, vazia)
    $db->exec("CREATE TABLE setores LIKE `{$bancoApp}`.setores");             // distribuicaoAtivosPorSetor() faz LEFT JOIN (só estrutura, vazia)
    $db->exec("INSERT INTO usuarios (id, nome, email, senha_hash, role) VALUES (1, 'Admin Ficticio', 'zzexc@example.invalid', 'x', 'admin')");
    $rodarArquivo($mig, $migrations . '2026-10-29-colaboradores-metadados-exclusoes-analiticas.sql'); // schema "antes"

    $trocarConexao($db);
    $resetarCacheServico();

    $mk = static function (string $emp, string $uni, string $ctr, string $pessoa, string $nome, bool $semSetor = false, ?string $identificador = null) use ($db): int {
        $db->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                nome, empresa, unidade, cargo, codigo_cargo, setor, codigo_setor, sexo,
                admissao, ativo, origem_metadados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $identificador ?? "{$emp}-{$uni}-{$ctr}", $emp, $uni, $ctr, $pessoa,
            $nome, "ZZNAT Empresa {$emp}", "ZZNAT Unidade {$uni}", 'ZZNAT Cargo', 'ZZNATCG',
            $semSetor ? null : 'ZZNAT Setor', $semSetor ? null : 'ZZNATST',
            'F', date('Y-m-d', strtotime('-400 days')), 1, 'zzexc-teste',
        ]);
        return (int)$db->lastInsertId();
    };

    $S = ColaboradorMetadadosElegibilidadeService::class;
    $consulta = new ColaboradorMetadadosConsultaRepository($db);
    $pa = new PeopleAnalyticsRepository($db);
    $ri = new RhIndicadoresRepository($db);
    $td = new TurnoverDashboardRepository($db);

    // Aparece no(s) ponto(s) de consumo? (nome na listagem; identificador nos demais)
    $naListagem = static fn(string $nome): int => (int)$consulta->paginate(['q' => $nome], 1, 50)['total'];

    // ---- fixtures ---------------------------------------------------------------------------------
    $idA = $mk('E1', 'U1', '100', 'P1', 'ZZNAT Alvo A');                 // será excluído pela chave natural
    $idB = $mk('E1', 'U1', '101', 'P1', 'ZZNAT Mesma Pessoa B');         // MESMA pessoa, outro contrato
    $idC = $mk('E2', 'U1', '100', 'P2', 'ZZNAT Outra Empresa C');        // mesmo nº de contrato, outra EMPRESA
    $idD = $mk('E1', 'U2', '100', 'P3', 'ZZNAT Outra Unidade D');        // mesmo nº de contrato, outra UNIDADE
    $idE = $mk('E2', 'U2', '200', 'P1', 'ZZNAT Transferencia E');        // mesma pessoa, NOVO vínculo em outra empresa
    $idN = $mk('E1', 'U1', '102', 'P4', 'ZZNAT Sem Setor Legitimo N', true);
    $idL1 = $mk('E1', 'U1', '300', 'P5', 'ZZNAT Legado Um L1');
    $idL2 = $mk('E1', 'U1', '301', 'P6', 'ZZNAT Legado Dois L2');

    // ======== FASE 1 — schema ANTES da migration 2026-11-11 (modo legado) =============================
    echo "\n=== Fase 1: schema sem a chave natural (modo legado) ===\n";
    $check($S::modoSchema() === $S::MODO_LEGADO, 'Sem a migration 2026-11-11 o serviço detecta o modo LEGADO');
    $db->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (id, metadados_id, motivo, excluido_por_usuario_id, created_at) VALUES
        (21, {$idL1}, 'motivo legado 1', 1, '2026-10-29 14:13:19'),
        (22, {$idL2}, 'motivo legado 2', 1, '2026-10-29 14:13:19')");
    $check($naListagem('ZZNAT Legado Um L1') === 0, '(legado) Exclusão por metadados_id continua escondendo da listagem (deploy do código antes da migration não derruba nem expõe)');
    $check($naListagem('ZZNAT Alvo A') === 1, '(legado) Contrato não excluído continua aparecendo');
    $check(str_contains($S::clausulaSqlExclusao('m.id'), 'NOT IN'), '(legado) A cláusula usa a forma por metadados_id');
    $r = $S::excluirPorVinculo('E1', 'U1', '100', 'qualquer', 1);
    $check(!($r['ok'] ?? true) && str_contains((string)($r['error'] ?? ''), '2026-11-11'), '(legado) Escrita é RECUSADA com orientação para aplicar a migration');
    $lancou = false;
    try { $S::incluir($idL1); } catch (RuntimeException $e) { $lancou = true; }
    $check($lancou, '(legado) incluir() também é recusado');

    // ======== FASE 2 — migration 2026-11-11 aplicada =================================================
    echo "\n=== Fase 2: após a migration ===\n";
    $rodarArquivo($mig, $migrations . '2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql');
    $resetarCacheServico();
    $check($S::modoSchema() === $S::MODO_NATURAL, 'Após a migration o serviço detecta o modo NATURAL');
    $l1 = $db->query('SELECT * FROM colaboradores_metadados_exclusoes_analiticas WHERE id = 21')->fetch();
    $check($l1 && (int)$l1['metadados_id'] === $idL1 && $l1['codigo_empresa'] === 'E1' && $l1['codigo_unidade'] === 'U1' && $l1['numero_contrato'] === '300'
        && $l1['motivo'] === 'motivo legado 1' && (int)$l1['excluido_por_usuario_id'] === 1 && $l1['created_at'] === '2026-10-29 14:13:19',
        '(migração) Exclusão antiga #21 preservada (id, motivo, usuário, data, metadados_id) e com a chave natural preenchida');
    $check($naListagem('ZZNAT Legado Um L1') === 0 && $naListagem('ZZNAT Legado Dois L2') === 0, '(preservação) Exclusões antigas continuam valendo na listagem após a migration');
    $check(!in_array('E1-U1-300', array_column($pa->buscarContratos(), 'identificador'), true), '(preservação) Exclusão antiga continua valendo em PeopleAnalytics');
    $check(!in_array($idL1, array_column($ri->buscarContratos([]), 'id'), true), '(preservação) Exclusão antiga continua valendo em RhIndicadores');

    // ======== FASE 3 — matriz de requisitos ===========================================================
    echo "\n=== Fase 3: exclusão pela chave natural ===\n";
    $antesTotalCm = (int)$db->query('SELECT COUNT(*) FROM colaboradores_metadados')->fetchColumn();
    $antesSummary = $consulta->summary();
    $snapA = $db->query("SELECT * FROM colaboradores_metadados WHERE id = {$idA}")->fetch();

    // contrato inexistente / entrada inválida
    $check(!($S::excluirPorVinculo('E9', 'U9', '999', 'motivo', 1)['ok'] ?? true), '(inexistente) Chave natural sem contrato é rejeitada');
    $check(!($S::excluir(999999999, 'motivo', 1)['ok'] ?? true), '(inexistente) excluir() por id inexistente é rejeitado');
    $check(!($S::excluirPorVinculo('E1', 'U1', '100', '   ', 1)['ok'] ?? true), 'Motivo vazio é rejeitado');
    $check(!($S::excluirPorVinculo('E1', '', '100', 'motivo', 1)['ok'] ?? true), 'Chave natural incompleta é rejeitada');
    $check(!$S::estaExcluidoPorVinculo('E1', 'U1', '100'), 'Antes de excluir: estaExcluidoPorVinculo() é falso');

    // exclusão
    $r = $S::excluirPorVinculo('E1', 'U1', '100', 'Motivo de teste ZZNAT', 1);
    $check($r['ok'] ?? false, '(chave natural) Exclusão de contrato existente é aceita');
    $linhaA = $db->query("SELECT * FROM colaboradores_metadados_exclusoes_analiticas WHERE codigo_empresa='E1' AND codigo_unidade='U1' AND numero_contrato='100'")->fetch();
    $check($linhaA && $linhaA['metadados_id'] === null && $linhaA['motivo'] === 'Motivo de teste ZZNAT' && (int)$linhaA['excluido_por_usuario_id'] === 1 && $linhaA['created_at'] !== null,
        'A exclusão grava chave natural + motivo + responsável + data, sem depender de metadados_id');
    $check($S::estaExcluidoPorVinculo('E1', 'U1', '100') && $S::estaExcluido($idA), 'estaExcluido()/estaExcluidoPorVinculo() verdadeiros');
    $r2 = $S::excluirPorVinculo('E1', 'U1', '100', 'de novo', 1);
    $check(!($r2['ok'] ?? true), '(duplicada) Segunda exclusão do mesmo vínculo é rejeitada');
    $check((int)$db->query("SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas WHERE codigo_empresa='E1' AND codigo_unidade='U1' AND numero_contrato='100'")->fetchColumn() === 1, '(duplicada) Continua uma única linha para o vínculo');
    $check(!($S::excluir($idA, 'via id', 1)['ok'] ?? true), '(duplicada) excluir() por id do mesmo vínculo também é rejeitado');

    // quem some / quem fica
    $check($naListagem('ZZNAT Alvo A') === 0, '(listagem) O vínculo excluído some de paginate()');
    $check($naListagem('ZZNAT Mesma Pessoa B') === 1, '(mesma pessoa) Outro contrato da MESMA pessoa NÃO é afetado');
    $check($naListagem('ZZNAT Outra Empresa C') === 1, '(outra empresa) Mesmo nº de contrato em outra EMPRESA NÃO é afetado');
    $check($naListagem('ZZNAT Outra Unidade D') === 1, '(outra unidade) Mesmo nº de contrato em outra UNIDADE NÃO é afetado');
    $check($naListagem('ZZNAT Transferencia E') === 1, '(transferência interempresa) Novo vínculo da mesma pessoa em outra empresa não herda a exclusão');
    $check($naListagem('ZZNAT Sem Setor Legitimo N') === 1, '(regra rejeitada) Contrato sem setor, nunca marcado, continua aparecendo');
    $depoisSummary = $consulta->summary();
    $check($depoisSummary['contratos'] === $antesSummary['contratos'] - 1, '(summary) A exclusão reduz a contagem em exatamente 1');

    $idsPA = array_column($pa->buscarContratos(), 'identificador');
    $check(!in_array('E1-U1-100', $idsPA, true), '(Headcount/PeopleAnalytics) Vínculo excluído fora de buscarContratos()');
    $check(in_array('E1-U1-101', $idsPA, true) && in_array('E2-U1-100', $idsPA, true) && in_array('E1-U2-100', $idsPA, true) && in_array('E2-U2-200', $idsPA, true),
        '(PeopleAnalytics) Mesma pessoa / outra empresa / outra unidade / transferência permanecem');
    $distSetor = array_sum(array_column($pa->distribuicaoAtivosPorSetor([]), 'contratos'));
    $check($distSetor === 5, '(PeopleAnalytics) distribuicaoAtivosPorSetor() conta 5 (8 fixtures − 2 excluídos legados − 1 excluído agora) — veio ' . $distSetor);
    $idsRI = array_column($ri->buscarContratos([]), 'id');
    $check(!in_array($idA, $idsRI, true) && in_array($idB, $idsRI, true) && in_array($idC, $idsRI, true) && in_array($idD, $idsRI, true) && in_array($idE, $idsRI, true) && in_array($idN, $idsRI, true),
        '(RhIndicadores) Excluído some; os demais permanecem');
    $check(count($td->buscarContratos([])) === 5, '(Turnover) buscarContratos() traz 5 (só os elegíveis)');

    // dados brutos intactos
    $check((int)$db->query('SELECT COUNT(*) FROM colaboradores_metadados')->fetchColumn() === $antesTotalCm, '(dados brutos) Nenhum contrato foi apagado de colaboradores_metadados');
    $check($snapA === $db->query("SELECT * FROM colaboradores_metadados WHERE id = {$idA}")->fetch(), '(dados brutos) O registro do contrato excluído está byte a byte igual ao original');

    // auditoria
    $lista = $S::listarExcluidos();
    $achouA = array_values(array_filter($lista, static fn($l) => $l['identificador'] === 'E1-U1-100'));
    $check(count($achouA) === 1 && (int)$achouA[0]['metadados_id'] === $idA && $achouA[0]['nome'] === 'ZZNAT Alvo A', '(auditoria) listarExcluidos() casa o contrato atual pela chave natural');
    $achouL1 = array_values(array_filter($lista, static fn($l) => $l['identificador'] === 'E1-U1-300'));
    $check(count($achouL1) === 1 && (int)$achouL1[0]['metadados_id_original'] === $idL1, '(auditoria) Exclusão migrada mantém metadados_id_original');

    // ---- resolverIdentificador ----------------------------------------------------------------------
    echo "\n=== Identificador oficial ===\n";
    $ri1 = $S::resolverIdentificador('E1-U1-100');
    $check(($ri1['ok'] ?? false) && $ri1['vinculo']['numero_contrato'] === '100' && $ri1['vinculo']['id'] === $idA, 'resolverIdentificador() acha o vínculo e confere a identidade');
    $check(!($S::resolverIdentificador('E9-U9-999')['ok'] ?? true), 'Identificador inexistente é rejeitado');
    $check(!($S::resolverIdentificador('E1;DROP-U1-1')['ok'] ?? true) && !($S::resolverIdentificador('')['ok'] ?? true), 'Identificador malformado é rejeitado');
    $mk('E3', 'U3', '1', 'P7', 'ZZNAT Identificador Inconsistente', false, 'E3-U3-999');
    $check(!($S::resolverIdentificador('E3-U3-999')['ok'] ?? true), 'Identificador incoerente com a chave natural da linha é rejeitado');

    // ---- reconstrução da base / ID interno reaproveitado --------------------------------------------
    echo "\n=== Reconstrução da base e IDs internos ===\n";
    $idAAntigo = $idA;
    $db->exec("DELETE FROM colaboradores_metadados WHERE id = {$idA}");           // "reconstrução": o contrato some...
    $idAReimportado = $mk('E1', 'U1', '100', 'P1', 'ZZNAT Alvo A');               // ...e volta com OUTRO id interno
    $check($idAReimportado !== $idAAntigo, '(reconstrução) O contrato reimportado recebeu um id interno diferente (' . $idAAntigo . ' → ' . $idAReimportado . ')');
    $check($naListagem('ZZNAT Alvo A') === 0 && !in_array('E1-U1-100', array_column($pa->buscarContratos(), 'identificador'), true),
        '(reconstrução) A exclusão continua valendo mesmo com o id interno novo');
    $idA = $idAReimportado;

    // ID reaproveitado: a exclusão migrada L1 ficou com metadados_id DEFASADO apontando para OUTRO contrato (N)
    $db->exec("UPDATE colaboradores_metadados_exclusoes_analiticas SET metadados_id = {$idN} WHERE id = 21");
    $check($naListagem('ZZNAT Sem Setor Legitimo N') === 1, '(id reaproveitado) Um metadados_id defasado que aponta para outro contrato NÃO exclui esse contrato');
    $check($naListagem('ZZNAT Legado Um L1') === 0, '(id reaproveitado) O contrato realmente excluído (L1) continua excluído pela chave natural');
    $db->exec("DELETE FROM colaboradores_metadados WHERE id = {$idL1}");
    $mk('E1', 'U1', '300', 'P5', 'ZZNAT Legado Um L1');
    $check($naListagem('ZZNAT Legado Um L1') === 0, '(reconstrução) Exclusão migrada continua valendo após o contrato ser reimportado com novo id');

    // ---- nova sincronização --------------------------------------------------------------------------
    echo "\n=== Nova sincronização ===\n";
    $repoCm = new ColaboradorMetadadosRepository($db);
    $linhaSync = static fn(string $emp, string $uni, string $ctr, string $pessoa, string $nome, string $cargo) => [
        'identificador' => "{$emp}-{$uni}-{$ctr}", 'codigo_empresa' => $emp, 'codigo_unidade' => $uni, 'numero_contrato' => $ctr,
        'codigo_pessoa' => $pessoa, 'nome' => $nome, 'empresa' => "ZZNAT Empresa {$emp}", 'unidade' => "ZZNAT Unidade {$uni}",
        'cargo' => $cargo, 'codigo_cargo' => 'ZZNATCG', 'setor' => 'ZZNAT Setor', 'codigo_setor' => 'ZZNATST',
        'admissao' => date('Y-m-d', strtotime('-400 days')), 'ativo' => 1,
    ];
    $totalExclusoesAntes = (int)$db->query('SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas')->fetchColumn();
    $check($repoCm->upsert($linhaSync('E1', 'U1', '100', 'P1', 'ZZNAT Alvo A', 'CARGO ALTERADO'), 'zzexc-teste') === 'updated', '(sync) upsert atualiza o contrato excluído (pela chave natural)');
    $check($repoCm->upsert($linhaSync('E1', 'U1', '100', 'P1', 'ZZNAT Alvo A', 'CARGO ALTERADO'), 'zzexc-teste') === 'unchanged', '(sync) segundo upsert idêntico = unchanged');
    $check($repoCm->upsert($linhaSync('E1', 'U1', '400', 'P8', 'ZZNAT Novo Via Sync', 'CARGO'), 'zzexc-teste') === 'inserted', '(sync) contrato novo é inserido');
    $check($naListagem('ZZNAT Alvo A') === 0, '(sync) Após nova sincronização, o contrato excluído continua fora da listagem');
    $check($naListagem('ZZNAT Novo Via Sync') === 1, '(sync) Contrato novo, não excluído, aparece normalmente');
    $check((int)$db->query('SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas')->fetchColumn() === $totalExclusoesAntes, '(sync) A tabela de exclusões não é limpa nem reconstruída pela sincronização');
    $check((string)$db->query("SELECT cargo FROM colaboradores_metadados WHERE id = {$idA}")->fetchColumn() === 'CARGO ALTERADO', '(sync) O dado bruto recebido do Metadados é preservado/atualizado no contrato excluído (auditoria)');

    // ---- reversão administrativa ---------------------------------------------------------------------
    echo "\n=== Reversão ===\n";
    $snapX = $db->query("SELECT * FROM colaboradores_metadados WHERE id = {$idB}")->fetch();
    $check($S::incluirPorVinculo('E1', 'U1', '100') === true, '(reversão) incluirPorVinculo() remove a marca');
    $check($naListagem('ZZNAT Alvo A') === 1, '(reversão) O contrato volta a aparecer na listagem');
    $check($S::incluirPorVinculo('E1', 'U1', '100') === false, '(reversão) Reverter de novo é no-op (false)');
    $check($S::excluir($idA, 'reexcluido por id', 1)['ok'] ?? false, '(compat) excluir() por id interno atual registra pela chave natural');
    $S::incluir($idA);
    $check(!$S::estaExcluido($idA), '(compat) incluir() por id interno atual reverte');
    $check($snapX === $db->query("SELECT * FROM colaboradores_metadados WHERE id = {$idB}")->fetch(), '(dados brutos) Excluir/incluir outro contrato não altera contratos vizinhos');

    // ---- cláusula: validação de entrada -------------------------------------------------------------
    $rejeitou = false;
    try { $S::clausulaSqlExclusao('m.id; DROP TABLE x'); } catch (InvalidArgumentException $e) { $rejeitou = true; }
    $check($rejeitou, 'clausulaSqlExclusao() rejeita coluna que não seja [alias.]id (nada é concatenado em SQL sem validação)');
    $check(str_contains($S::clausulaSqlExclusao('id'), 'colaboradores_metadados.codigo_empresa') && str_contains($S::clausulaSqlExclusao('cm.id'), 'cm.codigo_empresa'),
        'clausulaSqlExclusao() usa o alias informado (ou o nome da tabela quando não há alias)');

    echo "\nEXCLUSAO_ANALITICA_CHAVE_NATURAL_OK\n";
} finally {
    $trocarConexao($appPdo);
    $resetarCacheServico();
    $admin->exec("DROP DATABASE IF EXISTS `{$banco}`");
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_CHAVE_NATURAL_OK\n";
