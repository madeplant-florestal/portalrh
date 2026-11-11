<?php

/**
 * Integração — a consulta de diagnóstico de PRODUÇÃO
 * (database/diagnosticos/2026-11-11-dependencias-exclusao-analitica.sql) é SOMENTE LEITURA, encontra
 * dependentes reais (diretos e indiretos via colaborador local) pela chave natural e TOLERA tabelas e
 * colunas inexistentes (um banco menor/mais antigo não gera erro).
 *
 * Parte A: no banco da aplicação, com fixtures ZZEXF ligadas ao contrato excluído em CADA tabela mapeada.
 * Parte B: num banco descartável só com as tabelas-base + uma tabela de módulo — o resto "não existe".
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

$pdo = Database::conn();
$S = ColaboradorMetadadosElegibilidadeService::class;
if ($S::modoSchema() !== $S::MODO_NATURAL) {
    echo "PULADO: migration 2026-11-11 (chave natural) não aplicada neste banco.\n";
    exit(0);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$arquivo = __DIR__ . '/../../database/diagnosticos/2026-11-11-dependencias-exclusao-analitica.sql';
$check(is_file($arquivo), 'O arquivo de diagnóstico existe');
$sqlTexto = (string)file_get_contents($arquivo);

// Somente leitura: nenhuma instrução de escrita/DDL (ignora comentários e literais gerados).
$semComentarios = preg_replace('/^\s*--.*$/m', '', $sqlTexto);
$check(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|GRANT|RENAME)\b/i', (string)$semComentarios), 'O diagnóstico não contém nenhuma instrução de escrita/DDL (somente leitura)');
$check(!preg_match('/CREATE\s+TEMPORARY/i', (string)$semComentarios), 'Não usa tabela temporária (funciona com usuário só de leitura)');

$cfg = Config::app()['database'];
$dsnSemBanco = preg_replace('/;?dbname=[^;]+/', '', (string)$cfg['dsn']);
$novoPdo = static fn(?string $banco): PDO => new PDO($banco === null ? $dsnSemBanco : $dsnSemBanco . ';dbname=' . $banco, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true,
]);

/** Executa o arquivo e devolve os conjuntos de resultado (só os que têm linhas/colunas), na ordem. */
$executar = static function (PDO $p) use ($sqlTexto): array {
    $linhas = [];
    foreach (preg_split('/\R/', $sqlTexto) as $l) {
        if (!str_starts_with(ltrim($l), '--')) {
            $linhas[] = $l;
        }
    }
    $conjuntos = [];
    foreach (preg_split('/;\s*\n/', implode("\n", $linhas) . "\n") as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $r = $p->query($stmt);
        if ($r instanceof PDOStatement) {
            if ($r->columnCount() > 0) {
                $conjuntos[] = $r->fetchAll();
            }
            $r->closeCursor();
        }
    }
    return $conjuntos;
};
$porTabela = static function (array $conjunto): array {
    $m = [];
    foreach ($conjunto as $l) {
        $m[$l['tabela'] . '.' . $l['coluna']] = (int)$l['registros'];
    }
    return $m;
};

$fx = new ExclusaoFixtures($pdo);
$banco = null;
$admin = $novoPdo(null);

try {
    // ================================================================ PARTE A — banco da aplicação
    echo "\n=== Parte A: dependentes reais em cada tabela mapeada ===\n";
    $antes = $porTabela($executar($pdo)[3] ?? []);

    $idExc = $fx->contrato('1', 'PZD1', 'ZZEXF Dep Excluido');
    $idOk = $fx->contrato('2', 'PZD2', 'ZZEXF Dep Legitimo');
    foreach ([$idExc => 'exc', $idOk => 'ok'] as $mid => $k) {
        $fx->avaliacaoExperiencia($mid, "ZZEXF {$k}");
        $fx->avaliacaoDesempenho($mid, "ZZEXF {$k}");
        $fx->feedback($mid, "ZZEXF {$k}");
        $fx->pdi($mid, "ZZEXF {$k}");
        $fx->entrevista($mid, "ZZEXF {$k}", "tok-{$k}-{$fx->suf}");
        $fx->liberacaoEntrevista($mid);
        $fx->pesquisa($mid, null, "tokp-{$k}-{$fx->suf}");
        $fx->usuario("ZZEXF Usuario {$k}", 'rh', $mid);
        $col = $fx->colaboradorLocal("ZZEXF Local {$k}", $mid);
        $outro = $fx->colaboradorLocal("ZZEXF Lider {$k}", null);
        $fx->pesquisa(null, $col, "tokpc-{$k}-{$fx->suf}");
        $fx->solicitacaoVaga(['colaborador_substituido_id' => $col, 'gestor_solicitante_colaborador_id' => $outro, 'lider_imediato_colaborador_id' => $col, 'nome_contratado_colaborador_id' => null]);
        $fx->movimentacao(['colaborador_id' => $col, 'gestor_solicitante_colaborador_id' => $outro]);
        $uid = $fx->usuario("ZZEXF Usuario Local {$k}", 'rh');
        $fx->usuarioColaborador($uid, $col, $outro);
    }
    $fx->excluir('1');

    $conjuntos = $executar($pdo);
    $check(count($conjuntos) === 5, 'O diagnóstico devolve os 5 conjuntos de resultado esperados (bases, exclusões, existência, contagens, locais)');
    $depois = $porTabela($conjuntos[3]);
    $delta = static fn(string $k): int => ($depois[$k] ?? -999) - ($antes[$k] ?? 0);

    foreach (['colaboradores.metadados_id', 'avaliacoes_experiencia.metadados_id', 'avaliacoes_desempenho.metadados_id', 'entrevistas_desligamento.metadados_id',
              'entrevista_desligamento_liberacoes.metadados_id', 'feedbacks.metadados_id', 'pdis.metadados_id', 'pesquisas_integracao.metadados_id',
              'usuarios.colaborador_metadados_id'] as $alvo) {
        $check($delta($alvo) === 1, "DIRETO {$alvo}: conta exatamente 1 dependente do contrato excluído (o do contrato legítimo não entra)");
    }
    $check($delta('pesquisas_integracao.colaborador_id') === 1, 'INDIRETO pesquisas_integracao.colaborador_id: 1 (via colaborador local ligado ao excluído)');
    $check($delta('solicitacoes_vaga.colaborador_substituido_id') === 1 && $delta('solicitacoes_vaga.lider_imediato_colaborador_id') === 1, 'INDIRETO solicitacoes_vaga (substituído e líder): 1 cada');
    $check($delta('solicitacoes_vaga.gestor_solicitante_colaborador_id') === 0 && $delta('solicitacoes_vaga.nome_contratado_colaborador_id') === 0, 'INDIRETO solicitacoes_vaga: colunas que NÃO apontam para o excluído contam 0');
    $check($delta('movimentacoes_pessoal.colaborador_id') === 1 && $delta('movimentacoes_pessoal.gestor_solicitante_colaborador_id') === 0, 'INDIRETO movimentacoes_pessoal.colaborador_id: 1; gestor (outro colaborador): 0');
    $check($delta('usuario_colaboradores.colaborador_id') === 1 && $delta('usuario_colaboradores.lider_colaborador_id') === 0, 'INDIRETO usuario_colaboradores.colaborador_id: 1');
    $check($delta('colaborador_avaliacoes.colaborador_id') === 0, 'INDIRETO colaborador_avaliacoes: 0 (sem fixture — controle)');
    $locais = array_column($conjuntos[4], 'colaborador_local');
    $check(in_array('ZZEXF Local exc', $locais, true) && !in_array('ZZEXF Local ok', $locais, true), 'Detalhe: lista o colaborador LOCAL ligado ao contrato excluído e não o do legítimo');
    $contratosExcl = array_column($conjuntos[1], 'identificador');
    $check(in_array("{$fx->emp}-{$fx->uni}-1", $contratosExcl, true), 'A exclusão aparece identificada pela chave natural (empresa-unidade-contrato)');

    // ================================================================ PARTE B — tabelas/colunas ausentes
    echo "\n=== Parte B: banco menor — o que não existe não gera erro ===\n";
    $banco = 'rhm_zzdiag_' . substr((string)time(), -5) . random_int(10, 99);
    $admin->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4");
    $b = $novoPdo($banco);
    $bancoApp = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    // Só as tabelas-base + pdis; `colaboradores` SEM metadados_id não existe aqui (cria uma versão mínima sem a coluna).
    $b->exec("CREATE TABLE colaboradores_metadados LIKE `{$bancoApp}`.colaboradores_metadados");
    $b->exec("CREATE TABLE colaboradores_metadados_exclusoes_analiticas LIKE `{$bancoApp}`.colaboradores_metadados_exclusoes_analiticas");
    $b->exec("CREATE TABLE pdis LIKE `{$bancoApp}`.pdis");
    $b->exec('CREATE TABLE colaboradores (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100)) ENGINE=InnoDB');
    $b->exec("INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, nome) VALUES ('E-U-1','E','U','1','P','X')");
    $b->exec("INSERT INTO colaboradores_metadados_exclusoes_analiticas (codigo_empresa, codigo_unidade, numero_contrato, motivo) VALUES ('E','U','1','teste')");
    $mid = (int)$b->query('SELECT id FROM colaboradores_metadados')->fetchColumn();
    $b->exec("SET FOREIGN_KEY_CHECKS = 0");
    $b->exec("INSERT INTO pdis (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, gestor_usuario_id, gestor_nome_snapshot, origem_tipo, data_abertura, data_prevista_conclusao, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES ({$mid}, 'em_andamento', 'x', 'E', 'U', 1, 'g', 'desenvolvimento_carreira', '2025-06-01', '2027-06-01', 1, NOW(), NOW())");
    $erro = null;
    try {
        $conjB = $executar($b);
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        $conjB = [];
    }
    $check($erro === null, 'Banco sem a maioria das tabelas/colunas: o diagnóstico executa SEM erro' . ($erro ? " ({$erro})" : ''));
    $tabelasB = $erro === null ? $porTabela($conjB[3] ?? []) : [];
    $check(($tabelasB['pdis.metadados_id'] ?? null) === 1, 'Conta o que existe (pdis.metadados_id = 1)');
    $check(!isset($tabelasB['feedbacks.metadados_id']) && !isset($tabelasB['solicitacoes_vaga.colaborador_substituido_id']) && !isset($tabelasB['usuarios.colaborador_metadados_id']), 'Não referencia tabelas/colunas inexistentes (feedbacks, solicitacoes_vaga, usuarios… ficam de fora)');
    $existencia = [];
    foreach ($conjB[2] ?? [] as $l) {
        $existencia[$l['tabela'] . '.' . $l['coluna']] = (int)$l['existe'];
    }
    $check(($existencia['pdis.metadados_id'] ?? null) === 1 && ($existencia['feedbacks.metadados_id'] ?? null) === 0, 'O relatório de existência mostra 1 para o que existe e 0 para o que está ausente');
    $check(($existencia['colaboradores.metadados_id'] ?? null) === 0 && !isset($tabelasB['pesquisas_integracao.colaborador_id']), 'Sem colaboradores.metadados_id, os vínculos INDIRETOS são omitidos (não geram erro)');

    echo "\nEXCLUSAO_ANALITICA_DIAGNOSTICO_DEPENDENCIAS_OK\n";
} finally {
    if ($banco !== null) {
        $admin->exec("DROP DATABASE IF EXISTS `{$banco}`");
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_DIAGNOSTICO_DEPENDENCIAS_OK\n";
