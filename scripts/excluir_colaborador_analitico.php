<?php

/**
 * Manutenção (CLI) — registra ou reverte uma exclusão analítica de um contrato específico em
 * `colaboradores_metadados_exclusoes_analiticas` (ver ColaboradorMetadadosElegibilidadeService).
 * Não é uma tela administrativa — é a interface mínima para esta operação até que uma tela
 * própria seja justificada. Nunca apaga o registro original em `colaboradores_metadados`.
 *
 * Uso:
 *   php scripts/excluir_colaborador_analitico.php excluir <metadados_id> <usuario_id> "<motivo>"
 *   php scripts/excluir_colaborador_analitico.php incluir <metadados_id>
 *   php scripts/excluir_colaborador_analitico.php listar
 */

require __DIR__ . '/../app/core/bootstrap.php';

$acao = $argv[1] ?? '';

if ($acao === 'listar') {
    foreach (ColaboradorMetadadosElegibilidadeService::listarExcluidos() as $linha) {
        echo sprintf(
            "#%d metadados_id=%d %s (contrato %s, %s) — motivo: %s — por: %s em %s\n",
            $linha['id'],
            $linha['metadados_id'],
            $linha['nome'],
            $linha['numero_contrato'],
            $linha['empresa'],
            $linha['motivo'],
            $linha['excluido_por_nome'] ?? '(desconhecido)',
            $linha['created_at']
        );
    }
    exit(0);
}

if ($acao === 'incluir') {
    $metadadosId = (int)($argv[2] ?? 0);
    if ($metadadosId <= 0) {
        fwrite(STDERR, "Uso: php scripts/excluir_colaborador_analitico.php incluir <metadados_id>\n");
        exit(1);
    }
    ColaboradorMetadadosElegibilidadeService::incluir($metadadosId);
    echo "Contrato {$metadadosId} voltou a participar normalmente das estatísticas.\n";
    exit(0);
}

if ($acao === 'excluir') {
    $metadadosId = (int)($argv[2] ?? 0);
    $usuarioId = isset($argv[3]) && $argv[3] !== '' ? (int)$argv[3] : null;
    $motivo = (string)($argv[4] ?? '');
    if ($metadadosId <= 0 || $motivo === '') {
        fwrite(STDERR, "Uso: php scripts/excluir_colaborador_analitico.php excluir <metadados_id> <usuario_id> \"<motivo>\"\n");
        exit(1);
    }
    $resultado = ColaboradorMetadadosElegibilidadeService::excluir($metadadosId, $motivo, $usuarioId);
    if (!($resultado['ok'] ?? false)) {
        fwrite(STDERR, 'ERRO: ' . ($resultado['error'] ?? 'falha desconhecida') . "\n");
        exit(1);
    }
    echo "Contrato {$metadadosId} excluído das estatísticas.\n";
    exit(0);
}

fwrite(STDERR, "Ação desconhecida. Use: excluir | incluir | listar\n");
exit(1);
