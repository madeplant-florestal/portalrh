<?php

/**
 * Manutenção (CLI) — registra ou reverte uma exclusão analítica de um contrato específico em
 * `colaboradores_metadados_exclusoes_analiticas` (ver ColaboradorMetadadosElegibilidadeService).
 * Não é uma tela administrativa — é a interface mínima para esta operação até que uma tela
 * própria seja justificada. Nunca apaga o registro original em `colaboradores_metadados`.
 *
 * O contrato é identificado pelo IDENTIFICADOR OFICIAL `empresa-unidade-contrato`
 * (colaboradores_metadados.identificador, ex.: 0002-0002-347) — a chave natural do vínculo, igual
 * em qualquer ambiente. O script valida que o identificador existe, é único e coerente com a chave
 * natural antes de gravar, e mostra o contrato (nome/empresa) para conferência. Nunca decide por
 * CPF ou nome. Exige a migration 2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql.
 *
 * Uso:
 *   php scripts/excluir_colaborador_analitico.php excluir <empresa-unidade-contrato> <usuario_id> "<motivo>"
 *   php scripts/excluir_colaborador_analitico.php incluir <empresa-unidade-contrato>
 *   php scripts/excluir_colaborador_analitico.php listar
 *
 * `incluir` apenas remove a marca de exclusão (o contrato volta a participar das estatísticas).
 */

require __DIR__ . '/../app/core/bootstrap.php';

$acao = $argv[1] ?? '';

/** Resolve o identificador e devolve o vínculo, ou encerra com erro claro. */
$resolverOuSair = static function (string $identificador): array {
    $r = ColaboradorMetadadosElegibilidadeService::resolverIdentificador($identificador);
    if (!($r['ok'] ?? false)) {
        fwrite(STDERR, 'ERRO: ' . ($r['error'] ?? 'identificador inválido') . "\n");
        exit(1);
    }
    return $r['vinculo'];
};

try {
    if ($acao === 'listar') {
        foreach (ColaboradorMetadadosElegibilidadeService::listarExcluidos() as $linha) {
            echo sprintf(
                "#%d %s (contrato %s, %s) — motivo: %s — por: %s em %s\n",
                $linha['id'],
                $linha['identificador'] ?? '(sem identificador)',
                $linha['nome'] ?? '(contrato ausente em colaboradores_metadados)',
                $linha['empresa'] ?? '-',
                $linha['motivo'],
                $linha['excluido_por_nome'] ?? '(desconhecido)',
                $linha['created_at']
            );
        }
        exit(0);
    }

    if ($acao === 'incluir') {
        $identificador = trim((string)($argv[2] ?? ''));
        if ($identificador === '') {
            fwrite(STDERR, "Uso: php scripts/excluir_colaborador_analitico.php incluir <empresa-unidade-contrato>\n");
            exit(1);
        }
        $v = $resolverOuSair($identificador);
        $removeu = ColaboradorMetadadosElegibilidadeService::incluirPorVinculo($v['codigo_empresa'], $v['codigo_unidade'], $v['numero_contrato']);
        echo $removeu
            ? "Contrato {$v['identificador']} ({$v['nome']}) voltou a participar normalmente das estatísticas.\n"
            : "Contrato {$v['identificador']} não estava excluído — nada foi alterado.\n";
        exit(0);
    }

    if ($acao === 'excluir') {
        $identificador = trim((string)($argv[2] ?? ''));
        $usuarioId = isset($argv[3]) && $argv[3] !== '' ? (int)$argv[3] : null;
        $motivo = (string)($argv[4] ?? '');
        if ($identificador === '' || $motivo === '') {
            fwrite(STDERR, "Uso: php scripts/excluir_colaborador_analitico.php excluir <empresa-unidade-contrato> <usuario_id> \"<motivo>\"\n");
            exit(1);
        }
        $v = $resolverOuSair($identificador);
        $resultado = ColaboradorMetadadosElegibilidadeService::excluirPorVinculo(
            $v['codigo_empresa'],
            $v['codigo_unidade'],
            $v['numero_contrato'],
            $motivo,
            $usuarioId
        );
        if (!($resultado['ok'] ?? false)) {
            fwrite(STDERR, 'ERRO: ' . ($resultado['error'] ?? 'falha desconhecida') . "\n");
            exit(1);
        }
        echo "Contrato {$v['identificador']} ({$v['nome']}) excluído das estatísticas.\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDERR, "Ação desconhecida. Use: excluir | incluir | listar\n");
exit(1);
