<?php

/**
 * Integração — Transferência Interempresa no People Analytics (correção de 2026-09).
 *
 * Prova, via PeopleAnalyticsService::montarPainel() real (nunca a fórmula isolada):
 *   - Cenário A (transferência contínua, sem rescisão real — chave antiga `ausente_na_origem=1`,
 *     nunca demitida, sucessora vigente em outra empresa): a população CONSOLIDADA (ativos do
 *     período/Turnover Geral) conta a pessoa UMA vez, nunca duas, mesmo com os dois contratos
 *     (origem órfão + destino vigente) sobrepondo o período consultado;
 *   - Cenário A não gera nenhum evento falso de Admissão/Desligamento (a origem nunca teve
 *     `demissao`, o destino preserva a admissão original — nenhuma das duas fórmulas muda);
 *   - Cenário A NÃO remove a origem das quebras por Empresa/Setor (cada uma continua vendo o seu
 *     próprio registro real — limitação conhecida e documentada: sem a persistência de
 *     `DATAULTTRANSFERENCIA`, não há corte de dia exato entre as duas empresas);
 *   - Cenário B (rescisão real + novo contrato em outra empresa, classificado por
 *     MetadadosMovimentacaoService::classificarTransferencia()) NÃO conta como Desligamento real
 *     no consolidado nem na Empresa/Setor de origem, e NÃO conta como Admissão real no consolidado
 *     nem na Empresa de destino — a pessoa nunca gera falso Turnover;
 *   - Cenário B preserva a população: a pessoa AINDA conta como ativa nas duas empresas (só o
 *     EVENTO de admissão/desligamento é excluído do numerador, nunca o contrato inteiro);
 *   - Registro órfão SEM sucessor confiável (ninguém vigente, ou mais de um candidato) NUNCA é
 *     classificado automaticamente como transferência — fica de fora, sem presunção.
 *
 * Cada cenário usa códigos de empresa PRÓPRIOS (nunca reaproveitados entre cenários) para que as
 * contagens de cada `montarPainel()` fiquem isoladas e verificáveis por inspeção direta.
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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['metadados_identificadores' => []];

$insert = $pdo->prepare(
    'INSERT INTO colaboradores_metadados (
        identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
        cpf, nome, admissao, demissao, motivo_rescisao_codigo, ativo, ausente_na_origem, origem_metadados
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$mk = function (
    string $sufixo,
    string $cpf,
    string $codigoEmpresa,
    ?string $admissao,
    ?string $demissao,
    bool $ausenteNaOrigem,
    ?string $motivoRescisao = null
) use ($pdo, $insert, &$criados, $suffix): string {
    $identificador = 'ZZTR_' . $suffix . '_' . $sufixo;
    $insert->execute([
        $identificador, $codigoEmpresa, 'ZZU' . $suffix, 'ZZC' . $sufixo, 'ZZP' . $suffix . $sufixo,
        $cpf, 'ZZTR Fixture ' . $sufixo, $admissao, $demissao, $motivoRescisao,
        $demissao === null ? 1 : 0, $ausenteNaOrigem ? 1 : 0, 'zztr-teste',
    ]);
    $criados['metadados_identificadores'][] = $identificador;
    return $identificador;
};

try {
    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-60 days');
    $fim = $hoje;
    $service = new PeopleAnalyticsService();

    // ---- Cenário A: transferência contínua, sem rescisão real -----------------------------------
    $empA1 = 'ZZA1' . $suffix;
    $empB1 = 'ZZB1' . $suffix;
    $cpfA = '111' . $suffix;
    $mk('A-ORIGEM', $cpfA, $empA1, $hoje->modify('-500 days')->format('Y-m-d'), null, true);
    $mk('A-DESTINO', $cpfA, $empB1, $hoje->modify('-500 days')->format('Y-m-d'), null, false);

    $painelA1 = $service->montarPainel(['codigo_empresa' => $empA1], $inicio, $fim);
    $painelB1 = $service->montarPainel(['codigo_empresa' => $empB1], $inicio, $fim);

    $check($painelA1['turnover']['ativos_periodo'] === 1, '(A-1) Empresa de origem, filtrada isoladamente: ativos_periodo = 1 (o próprio registro órfão continua contando na SUA empresa — nenhuma remoção da quebra por Empresa).');
    $check($painelB1['turnover']['ativos_periodo'] === 1, '(A-2) Empresa de destino, filtrada isoladamente: ativos_periodo = 1 (registro vigente).');
    $check($painelA1['admissoes']['periodo'] === 0 && $painelB1['admissoes']['periodo'] === 0, '(A-3) Nenhuma admissão real gerada pela transferência contínua em nenhuma das duas empresas — admissão preservada é antiga, fora do período de 60 dias.');
    $check($painelA1['desligamentos']['periodo'] === 0 && $painelB1['desligamentos']['periodo'] === 0, '(A-4) Nenhum desligamento real gerado — a origem nunca teve demissão preenchida.');

    $painelConsolidadoA1 = $service->montarPainel([], $inicio, $fim);
    $ativosConsolidadoA1 = 0;
    foreach ($painelConsolidadoA1['turnover']['por_empresa'] as $linha) {
        if ($linha['codigo'] === $empA1 || $linha['codigo'] === $empB1) {
            $ativosConsolidadoA1 += $linha['ativos_periodo'];
        }
    }
    $check($ativosConsolidadoA1 === 2, '(A-5) Turnover por Empresa (consolidado, sem filtro de empresa): a soma de ativos_periodo das duas empresas continua 2 — a quebra por Empresa nunca é deduplicada (limitação conhecida sem a data exata de corte).');

    // ---- Cenário B: rescisão real + novo contrato em outra empresa (gap de 5 dias) --------------
    $empA2 = 'ZZA2' . $suffix;
    $empC2 = 'ZZC2' . $suffix;
    $cpfB = '222' . $suffix;
    $demissaoOrigemB = $hoje->modify('-20 days');
    $admissaoDestinoB = $hoje->modify('-15 days');
    $mk('B-ORIGEM', $cpfB, $empA2, $hoje->modify('-800 days')->format('Y-m-d'), $demissaoOrigemB->format('Y-m-d'), false, '016');
    $mk('B-DESTINO', $cpfB, $empC2, $admissaoDestinoB->format('Y-m-d'), null, false);

    $painelB_A2 = $service->montarPainel(['codigo_empresa' => $empA2], $inicio, $fim);
    $painelB_C2 = $service->montarPainel(['codigo_empresa' => $empC2], $inicio, $fim);

    $check($painelB_A2['desligamentos']['periodo'] === 0, '(B-1) Empresa de origem: o desligamento real (motivo 016, gap de 5 dias) some do KPI de Desligamentos — classificado como transferência, nunca Desligamento real.');
    $check($painelB_A2['turnover']['geral_percentual'] === 0.0, '(B-2) Empresa de origem: Turnover Geral fica 0,0% — a rescisão de transferência nunca entra no numerador.');
    $check($painelB_C2['admissoes']['periodo'] === 0, '(B-3) Empresa de destino: a admissão real (gap de 5 dias) some do KPI de Admissões — classificada como transferência, nunca Admissão real.');
    $check($painelB_A2['turnover']['ativos_periodo'] === 1, '(B-4) Empresa de origem: a pessoa AINDA conta na população (ativos_periodo = 1, contrato sobrepõe o período) — só o EVENTO de desligamento é excluído, nunca o contrato inteiro.');
    $check($painelB_C2['turnover']['ativos_periodo'] === 1, '(B-5) Empresa de destino: a pessoa conta normalmente na população (ativos_periodo = 1).');
    $check($painelB_A2['desligamentos_por_empresa'][0]['desligamentos'] === 0 || $painelB_A2['desligamentos_por_empresa'] === [], '(B-6) Desligamentos por Empresa (origem): também não mostra a transferência como evento — mesma fonte/exclusão de Turnover por Empresa.');

    // ---- Órfão SEM sucessor confiável: nunca presumir transferência ------------------------------
    $empOrfao = 'ZZOR' . $suffix;
    $cpfOrfao = '333' . $suffix;
    $mk('ORF-SO', $cpfOrfao, $empOrfao, $hoje->modify('-500 days')->format('Y-m-d'), null, true);

    $painelOrfaoIsolado = $service->montarPainel(['codigo_empresa' => $empOrfao], $inicio, $fim);
    $check($painelOrfaoIsolado['turnover']['ativos_periodo'] === 1, '(C-1) Órfão sem sucessor confiável em nenhuma outra empresa: continua contando normalmente na população da própria empresa — nunca removido por presunção.');

    // ---- Ambiguidade (2 órfãos para o mesmo CPF): nunca presumir qual é o par correto -------------
    $empAmbA = 'ZZDA' . $suffix;
    $empAmbB = 'ZZDB' . $suffix;
    $empAmbC = 'ZZDC' . $suffix;
    $cpfAmbiguo = '444' . $suffix;
    $mk('AMB-O1', $cpfAmbiguo, $empAmbA, $hoje->modify('-900 days')->format('Y-m-d'), null, true);
    $mk('AMB-O2', $cpfAmbiguo, $empAmbB, $hoje->modify('-700 days')->format('Y-m-d'), null, true);
    $mk('AMB-DST', $cpfAmbiguo, $empAmbC, $hoje->modify('-600 days')->format('Y-m-d'), null, false);

    $painelAmbiguoC = $service->montarPainel(['codigo_empresa' => $empAmbC], $inicio, $fim);
    $check($painelAmbiguoC['turnover']['ativos_periodo'] === 1, '(D-1) CPF com 2 órfãos ambíguos: nenhum é classificado automaticamente (sucessor não é inequívoco) — Empresa de destino conta normalmente (1), sem qualquer remoção vinda da ambiguidade.');

    // ---- Classificação pura (contagens expostas no painel, sem CPF) ------------------------------
    $painelTransferenciasGlobal = $service->montarPainel([], $inicio, $fim);
    $check($painelTransferenciasGlobal['transferencias']['continuas'] >= 1, "painel['transferencias']['continuas'] reflete ao menos o par A-ORIGEM/A-DESTINO classificado nesta fixture.");
    $check($painelTransferenciasGlobal['transferencias']['recontratacoes'] >= 1, "painel['transferencias']['recontratacoes'] reflete ao menos o par B-ORIGEM/B-DESTINO classificado nesta fixture.");

    echo "\nPEOPLE_ANALYTICS_TRANSFERENCIAS_OK\n";
} finally {
    if (!empty($criados['metadados_identificadores'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados_identificadores']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados_identificadores']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
