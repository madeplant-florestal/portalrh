<?php

/**
 * Integração — Segurança 3.5A: instalação a partir de database/schema.sql (dumps legados removidos), em bancos TEMPORÁRIOS isolados.
 *   - dumps removidos; Installer e setup_full usam SOMENTE database/schema.sql (nunca o dump, mesmo se reaparecer);
 *   - instalação real (Installer::importSchema + SchemaManager::ensure + garantias de runtime de candidaturas): tabelas obrigatórias,
 *     chaves estrangeiras válidas (inserções encadeadas), 6 etapas iniciais do pipeline, nenhum dado de pessoa;
 *   - EQUIVALÊNCIA com a base antiga: o mesmo fluxo a partir do dump legado SANEADO (lido do Git, commit da 3.4) e depois TODAS as migrations
 *     aplicadas aos dois bancos pelo cliente mysql — o conjunto de migrations aceitas e a estrutura final (tabelas, colunas, FKs) são iguais.
 * Bancos zz35* criados e APAGADOS no fim; nada toca o banco configurado do Portal.
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
$raiz = dirname(__DIR__, 2);
$cfg = Config::app()['database'];
preg_match('/host=([^;]+)/', (string)$cfg['dsn'], $mh);
$host = $mh[1] ?? '127.0.0.1';
$servidor = new PDO(preg_replace('/dbname=[^;]*;?/', '', (string)$cfg['dsn']), (string)$cfg['user'], (string)$cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mysqlBin = null;
foreach (array_merge(glob('C:/laragon/bin/mysql/*/bin/mysql.exe') ?: [], ['/usr/bin/mysql', '/usr/local/bin/mysql']) as $c) {
    if (is_file($c)) {
        $mysqlBin = $c;
        break;
    }
}
$sufixo = bin2hex(random_bytes(3));
$bancoNovo = 'zz35b_novo_' . $sufixo;
$bancoRef = 'zz35a_ref_' . $sufixo;
$dumpRef = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz35-dump-ref-' . $sufixo . '.sql';

$instalar = static function (string $banco, array $args) use ($servidor): array {
    $servidor->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4");
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/fixture_instalacao_isolada.php', $banco], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), (string)$out];
};
$estrutura = static function (string $banco) use ($servidor): array {
    $t = $servidor->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$banco}' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $c = $servidor->query("SELECT CONCAT(table_name,'.',column_name,' ',column_type,' ',is_nullable) FROM information_schema.columns WHERE table_schema = '{$banco}' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $f = $servidor->query("SELECT CONCAT(table_name,'.',column_name,'->',referenced_table_name,'.',referenced_column_name) FROM information_schema.key_column_usage WHERE table_schema = '{$banco}' AND referenced_table_name IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    return ['tabelas' => $t, 'colunas' => $c, 'fks' => $f];
};
$aplicarMigrations = static function (string $banco) use ($mysqlBin, $host, $cfg, $raiz): array {
    $ok = [];
    $arquivos = array_values(array_filter(glob($raiz . '/database/migrations/*.sql') ?: [], static fn($a) => !str_contains($a, 'rollback')));
    sort($arquivos);
    foreach ($arquivos as $arq) {
        $cmd = [$mysqlBin, '-h', $host, '-u', (string)$cfg['user'], '--default-character-set=utf8mb4', $banco];
        if ((string)$cfg['pass'] !== '') {
            $cmd[] = '-p' . $cfg['pass'];
        }
        $p = proc_open($cmd, [0 => ['file', $arq, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        if (proc_close($p) === 0) {
            $ok[] = basename($arq);
        }
    }
    return [$ok, count($arquivos)];
};

try {
    // ---- repositório e dependências ------------------------------------------------------------------------------------------------
    $check(!is_file($raiz . '/recrutamento.sql') && !is_file($raiz . '/database/recrutamento.sql'), '(dumps) recrutamento.sql e database/recrutamento.sql removidos');
    $inst = (string)file_get_contents($raiz . '/app/core/Installer.php');
    $setup = (string)file_get_contents($raiz . '/scripts/setup_full.php');
    $check(!str_contains($inst, "'/recrutamento.sql'") && str_contains($inst, "'/database/schema.sql'"), '(Installer) usa somente database/schema.sql');
    $check(!str_contains($setup, "recrutamento.sql'") && str_contains($setup, "'/../database/schema.sql'"), '(setup_full) usa somente database/schema.sql');
    $req = array_column(Installer::requirements(), 'ok', 'label');
    $check(($req['Schema de instalação disponível (database/schema.sql)'] ?? false) === true, '(Installer) requisito aponta para o schema.sql');
    $schema = (string)file_get_contents($raiz . '/database/schema.sql');
    $check(!preg_match('/INSERT\s+(IGNORE\s+)?INTO\s+`?(usuarios|colaboradores|candidaturas|candidatura_historico|auditoria_usuarios|pipeline_movements|notas_recrutador)`?/i', $schema), '(schema) nenhuma carga de dados de pessoas (só etapas de referência)');
    $check(!preg_match('/\d{3}\.\d{3}\.\d{3}-\d{2}|\$2[aby]\$\d\d\$|@[a-z0-9-]+\.[a-z]{2,}/i', $schema), '(schema) sem CPF, hash de senha ou e-mail');

    // ---- instalação nova a partir do schema.sql --------------------------------------------------------------------------------------
    [$rc, $out] = $instalar($bancoNovo, ['schema']);
    $check($rc === 0 && str_contains($out, 'INSTALACAO_OK'), '(instalação) Installer::importSchema + SchemaManager::ensure + garantias de runtime em banco isolado');
    $nova = $estrutura($bancoNovo);
    foreach (['usuarios', 'vagas', 'candidaturas', 'candidatura_historico', 'pipeline_stages', 'pipeline_movements', 'notas_recrutador', 'requisitos', 'beneficios', 'empresas', 'setores', 'cargos', 'colaboradores', 'password_resets', 'auditoria_usuarios', 'permissoes', 'usuario_permissoes'] as $t) {
        $check(in_array($t, $nova['tabelas'], true), "(tabela) {$t}");
    }
    $check(in_array('candidaturas.stage_id->pipeline_stages.id', $nova['fks'], true) && in_array('pipeline_movements.stage_novo_id->pipeline_stages.id', $nova['fks'], true), '(FK) candidaturas.stage_id e pipeline_movements apontam para pipeline_stages');
    $etapas = $servidor->query("SELECT GROUP_CONCAT(nome ORDER BY ordem SEPARATOR ',') FROM `{$bancoNovo}`.pipeline_stages")->fetchColumn();
    $check($etapas === 'Novo,Triagem,Entrevista,Proposta,Contratado,Rejeitado', '(dados de referência) 6 etapas iniciais do pipeline');
    $pessoas = 0;
    foreach (['usuarios', 'colaboradores', 'candidaturas', 'candidatura_historico', 'auditoria_usuarios', 'pipeline_movements', 'notas_recrutador'] as $t) {
        $pessoas += (int)$servidor->query("SELECT COUNT(*) FROM `{$bancoNovo}`.`{$t}`")->fetchColumn();
    }
    $check($pessoas === 0, '(dados) nenhuma linha de pessoa, candidato, usuário ou histórico');
    // integridade referencial ponta a ponta (inserções encadeadas, desfeitas no fim)
    $servidor->exec("USE `{$bancoNovo}`");
    $servidor->beginTransaction();
    try {
        $servidor->exec("INSERT INTO usuarios (nome, email, senha_hash, role) VALUES ('ZZ35', 'zz35@teste.local', 'x', 'rh')");
        $u = (int)$servidor->lastInsertId();
        $servidor->exec("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo) VALUES ('ZZ35', 'd', 'r', 'a', 'l', 1)");
        $v = (int)$servidor->lastInsertId();
        $servidor->exec("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, stage_id) VALUES ({$v}, 'ZZ35', 'c@teste.local', '0', '00000000000', 'x', 'x', 'x.pdf', 1)");
        $c = (int)$servidor->lastInsertId();
        $servidor->exec("INSERT INTO pipeline_movements (candidatura_id, stage_anterior_id, stage_novo_id, usuario_id) VALUES ({$c}, 1, 2, {$u})");
        $servidor->exec("INSERT INTO notas_recrutador (candidatura_id, usuario_id, nota) VALUES ({$c}, {$u}, 'n')");
        $servidor->exec("INSERT INTO requisitos (vaga_id, descricao) VALUES ({$v}, 'r')");
        $orfao = false;
        try {
            $servidor->exec("INSERT INTO pipeline_movements (candidatura_id, stage_novo_id) VALUES ({$c}, 999)");
        } catch (PDOException) {
            $orfao = true;
        }
        $check($orfao, '(FK) integridade: inserções encadeadas aceitas e etapa inexistente recusada');
    } finally {
        $servidor->rollBack();
    }

    // ---- equivalência com a base antiga + migrations -----------------------------------------------------------------------------------
    $p = proc_open(['git', '-C', $raiz, 'show', 'f4614a4:recrutamento.sql'], [1 => ['file', $dumpRef, 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[2]);
    $temRef = proc_close($p) === 0 && filesize($dumpRef) > 0;
    if (!$temRef || $mysqlBin === null) {
        $check(false, '(equivalência) dump de referência (f4614a4) ou cliente mysql indisponível');
    } else {
        [$rc, $out] = $instalar($bancoRef, ['arquivo', $dumpRef]);
        $check($rc === 0 && str_contains($out, 'INSTALACAO_OK'), '(referência) mesmo fluxo a partir do dump legado saneado');
        $antesRef = $estrutura($bancoRef);
        $antesNova = $estrutura($bancoNovo);
        // antes das migrations o schema.sql pode estar À FRENTE do dump antigo (já trazia webhooks, role gestor, vagas.empresa_id): exige-se
        // que contenha TODA a base antiga — a igualdade é exigida depois das migrations, que é o estado real de uso.
        $faltaTab = array_diff($antesRef['tabelas'], $antesNova['tabelas']);
        $check($faltaTab === [], '(base) antes das migrations: o schema.sql contém todas as tabelas da base antiga' . ($faltaTab ? ' — faltam: ' . implode(', ', $faltaTab) : ''));
        $nomesNova = array_map(static fn($x) => strtok($x, ' '), $antesNova['colunas']);
        $faltaCol = array_diff(array_map(static fn($x) => strtok($x, ' '), $antesRef['colunas']), $nomesNova);
        $check($faltaCol === [], '(base) antes das migrations: o schema.sql contém todas as colunas da base antiga' . ($faltaCol ? ' — faltam: ' . implode(', ', $faltaCol) : ''));
        [$okRef, $total] = $aplicarMigrations($bancoRef);
        [$okNova] = $aplicarMigrations($bancoNovo);
        $check($okNova === $okRef, "(migrations) mesmas migrations aceitas nos dois bancos: " . count($okNova) . " de {$total}" . ($okNova !== $okRef ? ' — divergentes: ' . implode(', ', array_merge(array_diff($okRef, $okNova), array_diff($okNova, $okRef))) : ''));
        $depoisRef = $estrutura($bancoRef);
        $depoisNova = $estrutura($bancoNovo);
        $check($depoisRef['tabelas'] === $depoisNova['tabelas'], '(equivalência) depois das migrations: mesmas tabelas (' . count($depoisNova['tabelas']) . ')');
        $dc = array_merge(array_diff($depoisRef['colunas'], $depoisNova['colunas']), array_diff($depoisNova['colunas'], $depoisRef['colunas']));
        $check($dc === [], '(equivalência) depois das migrations: mesmas colunas' . ($dc ? ' — diferenças: ' . implode('; ', array_slice($dc, 0, 8)) : ''));
        $df = array_merge(array_diff($depoisRef['fks'], $depoisNova['fks']), array_diff($depoisNova['fks'], $depoisRef['fks']));
        $check($df === [], '(equivalência) depois das migrations: mesmas chaves estrangeiras' . ($df ? ' — diferenças: ' . implode('; ', $df) : ''));
    }
} finally {
    foreach ([$bancoNovo, $bancoRef] as $b) {
        $servidor->exec("DROP DATABASE IF EXISTS `{$b}`");
    }
    @unlink($dumpRef);
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nSCHEMA_INSTALACAO_OK\n";
