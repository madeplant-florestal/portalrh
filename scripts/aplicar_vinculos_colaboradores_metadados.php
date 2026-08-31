<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Aplicação controlada dos vínculos CORRESPONDENCIA_SEGURA em colaboradores.metadados_id.
 *
 * Modo padrão (sem flag) é SEMPRE simulação: recalcula a reconciliação, gera o plano, valida
 * tudo, mostra o resumo e NÃO escreve. Só grava com --aplicar, explícito, sem prompt interativo.
 *
 * Nunca acessa o SQL Server do METADADOS — opera inteiramente sobre colaboradores/
 * colaboradores_metadados já sincronizados localmente.
 */
function contarClassificacoes(array $resultados): array
{
    $contagens = [
        'total_local' => count($resultados),
        'ja_vinculado' => 0,
        'correspondencia_segura' => 0,
        'correspondencia_provavel' => 0,
        'ambigua' => 0,
        'sem_correspondencia' => 0,
        'conflito' => 0,
    ];
    $mapa = [
        ColaboradorMetadadosReconciliationService::JA_VINCULADO => 'ja_vinculado',
        ColaboradorMetadadosReconciliationService::SEGURA => 'correspondencia_segura',
        ColaboradorMetadadosReconciliationService::PROVAVEL => 'correspondencia_provavel',
        ColaboradorMetadadosReconciliationService::AMBIGUA => 'ambigua',
        ColaboradorMetadadosReconciliationService::SEM_CORRESPONDENCIA => 'sem_correspondencia',
        ColaboradorMetadadosReconciliationService::CONFLITO => 'conflito',
    ];
    foreach ($resultados as $r) {
        $chave = $mapa[$r['classificacao']] ?? null;
        if ($chave !== null) {
            $contagens[$chave]++;
        }
    }
    return $contagens;
}

function salvarPlano(string $prefixo, array $plano): string
{
    $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'reconciliation';
    @mkdir($dir, 0775, true);
    $path = $dir . DIRECTORY_SEPARATOR . $prefixo . '-' . date('Ymd-His') . '.json';
    $itens = array_map(static function (array $item): array {
        return [
            'colaborador_id' => $item['colaborador_id'],
            'metadados_id' => $item['metadados_id'],
            'metadados_id_anterior' => null, // primeira aplicação — todos partem de NULL
        ];
    }, $plano);
    $conteudo = [
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'hash_plano' => ColaboradorMetadadosLinkService::planHash($plano),
        'quantidade' => count($plano),
        'itens' => $itens,
    ];
    file_put_contents($path, json_encode($conteudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}

try {
    $options = getopt('', ['aplicar']);
    $aplicar = array_key_exists('aplicar', $options);

    $reconciliationService = new ColaboradorMetadadosReconciliationService();
    $linkService = new ColaboradorMetadadosLinkService();

    $resultados = $reconciliationService->run();
    $contagens = contarClassificacoes($resultados);

    $plano = $linkService->buildPlanFromReconciliation($resultados);
    $validacaoEstrutural = $linkService->validatePlan($plano, $resultados);
    $validacaoBanco = $linkService->validateAgainstDatabase($plano);
    $hash = ColaboradorMetadadosLinkService::planHash($plano);
    $apto = $validacaoEstrutural['ok'] && $validacaoBanco['ok'];

    $resumo = [
        'modo' => $aplicar ? 'aplicacao' : 'simulacao',
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'contagens_reconciliacao' => $contagens,
        'tamanho_plano' => count($plano),
        'hash_plano' => $hash,
        'erros_validacao_estrutural' => $validacaoEstrutural['errors'],
        'erros_validacao_banco' => $validacaoBanco['errors'],
        'status' => $apto ? 'APTO_PARA_APLICACAO' : 'BLOQUEADO',
    ];

    if (!$aplicar) {
        $resumo['plano_path'] = salvarPlano('plano-vinculos-seguros', $plano);
        echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($apto ? 0 : 1);
    }

    if (!$apto) {
        $resumo['plano_path'] = salvarPlano('plano-vinculos-seguros-bloqueado', $plano);
        echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        fwrite(STDERR, "Plano bloqueado por validação — aplicação abortada, nenhuma escrita realizada.\n");
        exit(1);
    }

    // Snapshot pré-aplicação, gerado imediatamente antes do UPDATE real — nunca reaproveita um
    // plano de simulação anterior. Serve como evidência e como entrada da reversão.
    $snapshotPath = salvarPlano('snapshot-pre-aplicacao', $plano);
    $resultadoAplicacao = $linkService->apply($plano);

    $resumo['snapshot_path'] = $snapshotPath;
    $resumo['aplicacao'] = $resultadoAplicacao;

    echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($resultadoAplicacao['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'aplicar_vinculos_colaboradores_metadados.php']);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
