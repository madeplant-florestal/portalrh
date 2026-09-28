<?php

/**
 * Integração — People Analytics interativo (Etapa 2: click-to-filter, filtros acumulativos e
 * listagem contextual), correção de 2026-09. Prova, via PeopleAnalyticsService real (nunca query
 * isolada):
 *   - filtro `sexo` restringe TODA a população (Headcount/Turnover/Admissões/Desligamentos), como
 *     Empresa/Setor;
 *   - filtro `motivo_categoria` restringe SÓ Desligamentos/Turnover (numerador) — nunca
 *     Headcount/Admissões, que continuam olhando a população inteira (§15 da correção: "não
 *     aplicar silenciosamente um filtro impossível");
 *   - listagem contextual (`contexto_lista`): 'desligados'/'admitidos' usam o EVENTO
 *     (demissao/admissao) dentro do período — ou de `mes_evento` quando informado — nunca o status
 *     atual do contrato;
 *   - transferência contínua nunca aparece como "admitido" na listagem contextual — mesma
 *     exclusão já usada nos KPIs;
 *   - exportarColaboradoresCsv() entrega exatamente a mesma população filtrada da listagem
 *     paginada, sem paginação.
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
        cpf, nome, admissao, demissao, motivo_rescisao_codigo, ativo, ausente_na_origem,
        origem_metadados, sexo, codigo_setor
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$mk = function (
    string $sufixo,
    string $codigoEmpresa,
    ?string $admissao,
    ?string $demissao = null,
    ?string $motivoRescisao = null,
    bool $ausenteNaOrigem = false,
    ?string $sexo = null,
    ?string $cpfOverride = null
) use ($pdo, $insert, &$criados, $suffix): array {
    $identificador = 'ZZINT_' . $suffix . '_' . $sufixo;
    $codigoPessoa = 'ZZP' . substr(md5($suffix . $sufixo), 0, 14);
    $numeroContrato = 'ZZC' . substr(md5($sufixo), 0, 14);
    // CPF: por padrão, um valor distinto por fixture (deriva de $sufixo) — CENÁRIO A exige o
    // MESMO CPF entre origem/destino para a classificação de transferência contínua reconhecer o
    // par (MetadadosMovimentacaoService::identificarTransferenciasContinuas() agrupa por CPF);
    // por isso o teste D passa $cpfOverride explícito para esse par especificamente.
    $cpf = $cpfOverride ?? substr('9' . $suffix . substr(md5($sufixo), 0, 5), 0, 11);
    $insert->execute([
        $identificador, $codigoEmpresa, 'ZZU' . $suffix, $numeroContrato, $codigoPessoa,
        $cpf, 'ZZINT Fixture ' . $sufixo, $admissao, $demissao, $motivoRescisao,
        $demissao === null ? 1 : 0, $ausenteNaOrigem ? 1 : 0, 'zzint-teste', $sexo, null,
    ]);
    $criados['metadados_identificadores'][] = $identificador;
    return ['identificador' => $identificador, 'nome' => 'ZZINT Fixture ' . $sufixo];
};

try {
    $hoje = new DateTimeImmutable('today');
    $inicio = $hoje->modify('-90 days');
    $fim = $hoje;
    $service = new PeopleAnalyticsService();

    // ---- A: filtro `sexo` restringe a população inteira ------------------------------------------
    $empSexo = 'ZZSX' . $suffix;
    $mk('SEXO-M', $empSexo, $hoje->modify('-400 days')->format('Y-m-d'), null, null, false, 'M');
    $mk('SEXO-F', $empSexo, $hoje->modify('-400 days')->format('Y-m-d'), null, null, false, 'F');

    $painelTodos = $service->montarPainel(['codigo_empresa' => $empSexo], $inicio, $fim);
    $painelM = $service->montarPainel(['codigo_empresa' => $empSexo, 'sexo' => 'M'], $inicio, $fim);
    $check($painelTodos['turnover']['ativos_periodo'] === 2, '(A-1) Sem filtro de sexo: 2 ativos no período (M+F).');
    $check($painelM['turnover']['ativos_periodo'] === 1, '(A-2) Filtro sexo=M: só 1 ativo no período — restringe a população inteira, igual Empresa/Setor.');
    $check($painelM['headcount']['atual'] === 1, '(A-3) Headcount Atual também respeita o filtro de sexo.');

    // ---- B: filtro `motivo_categoria` só afeta Desligamentos/Turnover, nunca Headcount/Admissões --
    $empMotivo = 'ZZMT' . $suffix;
    // Voluntário = códigos 003/006 (TurnoverDashboardService::MAPA_MOTIVOS).
    $mk('MOTIVO-VOLUNTARIO', $empMotivo, $hoje->modify('-200 days')->format('Y-m-d'), $hoje->modify('-10 days')->format('Y-m-d'), '003');
    // Justa Causa = código 001.
    $mk('MOTIVO-JUSTACAUSA', $empMotivo, $hoje->modify('-200 days')->format('Y-m-d'), $hoje->modify('-15 days')->format('Y-m-d'), '001');
    // Admissão pura no período, sem nenhuma relação com desligamento — prova que o filtro de
    // motivo nunca contamina Admissões/Headcount.
    $admissaoPura = $mk('MOTIVO-ADMISSAO-PURA', $empMotivo, $hoje->modify('-5 days')->format('Y-m-d'));

    $painelSemMotivo = $service->montarPainel(['codigo_empresa' => $empMotivo], $inicio, $fim);
    $painelVoluntario = $service->montarPainel(['codigo_empresa' => $empMotivo, 'motivo_categoria' => 'Voluntário'], $inicio, $fim);
    $check($painelSemMotivo['desligamentos']['periodo'] === 2, '(B-1) Sem filtro de motivo: 2 desligamentos no período.');
    $check($painelVoluntario['desligamentos']['periodo'] === 1, '(B-2) Filtro motivo_categoria=Voluntário: só 1 desligamento (código 003) — o de Justa Causa (001) fica de fora.');
    $check($painelVoluntario['admissoes']['periodo'] === $painelSemMotivo['admissoes']['periodo'], '(B-3) Admissões IDÊNTICAS com ou sem filtro de motivo — motivo nunca filtra admissão (§15).');
    $check($painelVoluntario['headcount']['atual'] === $painelSemMotivo['headcount']['atual'], '(B-4) Headcount Atual IDÊNTICO com ou sem filtro de motivo.');

    // ---- C: listagem contextual 'desligados' usa o EVENTO (demissao), com mes_evento -------------
    $empLista = 'ZZLI' . $suffix;
    $mesDemissaoRecente = $hoje->modify('-10 days');
    $mesDemissaoAntiga = $hoje->modify('-70 days');
    $desligadoRecente = $mk('LISTA-DESL-RECENTE', $empLista, $hoje->modify('-300 days')->format('Y-m-d'), $mesDemissaoRecente->format('Y-m-d'), '003');
    $desligadoAntigo = $mk('LISTA-DESL-ANTIGO', $empLista, $hoje->modify('-300 days')->format('Y-m-d'), $mesDemissaoAntiga->format('Y-m-d'), '003');
    $aindaAtivo = $mk('LISTA-AINDA-ATIVO', $empLista, $hoje->modify('-300 days')->format('Y-m-d'));

    $listaDesligadosPeriodoTodo = $service->listarColaboradores(['codigo_empresa' => $empLista, 'contexto_lista' => 'desligados'], $inicio, $fim, 1, 20);
    $check($listaDesligadosPeriodoTodo['total'] === 2, "(C-1) contexto_lista=desligados, sem mes_evento: os 2 desligados do período aparecem (nunca o ainda ativo).");
    $nomesListaDesligados = array_column($listaDesligadosPeriodoTodo['items'], 'nome');
    $check(!in_array($aindaAtivo['nome'], $nomesListaDesligados, true), '(C-2) Quem continua ativo NUNCA aparece na listagem "Desligados no período".');
    $check(array_column($listaDesligadosPeriodoTodo['items'], 'situacao')[0] === 'Desligado', '(C-3) Situação do item mostra "Desligado" (nunca inventada) — vem do ativo=0 real do contrato.');

    $listaDesligadosMesRecente = $service->listarColaboradores(
        ['codigo_empresa' => $empLista, 'contexto_lista' => 'desligados', 'mes_evento' => $mesDemissaoRecente->format('Y-m')],
        $inicio, $fim, 1, 20
    );
    $check($listaDesligadosMesRecente['total'] === 1, '(C-4) mes_evento restringe a listagem a SÓ aquele mês, sem alterar o período global do painel.');
    $check(($listaDesligadosMesRecente['items'][0]['nome'] ?? null) === $desligadoRecente['nome'], '(C-5) O único item retornado é exatamente o desligado daquele mês específico.');

    // ---- D: contexto_lista='admitidos' exclui transferência contínua (mesma classificação dos KPIs)
    $empTransfOrigem = 'ZZTO' . $suffix;
    $empTransfDestino = 'ZZTD' . $suffix;
    $admissaoTransf = $hoje->modify('-30 days')->format('Y-m-d');
    $cpfTransf = substr('9' . $suffix . '00001', 0, 11);
    $mk('ADM-TRANSF-ORIGEM', $empTransfOrigem, $admissaoTransf, null, null, true, null, $cpfTransf);
    $destinoTransf = $mk('ADM-TRANSF-DESTINO', $empTransfDestino, $admissaoTransf, null, null, false, null, $cpfTransf);

    $listaAdmitidosDestino = $service->listarColaboradores(['codigo_empresa' => $empTransfDestino, 'contexto_lista' => 'admitidos'], $inicio, $fim, 1, 20);
    $check($listaAdmitidosDestino['total'] === 1, '(D-1) Empresa de destino da transferência contínua: aparece 1 vez como admitido (o vigente).');
    $listaAdmitidosOrigem = $service->listarColaboradores(['codigo_empresa' => $empTransfOrigem, 'contexto_lista' => 'admitidos'], $inicio, $fim, 1, 20);
    $check($listaAdmitidosOrigem['total'] === 0, '(D-2) Empresa de origem (órfã, ausente_na_origem=1): NUNCA aparece como "admitido" — mesma exclusão de transferência dos KPIs.');

    // ---- E: exportarColaboradoresCsv() entrega a MESMA população da listagem paginada -------------
    $exportDesligados = $service->exportarColaboradoresCsv(['codigo_empresa' => $empLista, 'contexto_lista' => 'desligados'], $inicio, $fim);
    $check(count($exportDesligados) === $listaDesligadosPeriodoTodo['total'], '(E-1) CSV de "Desligados no período" tem exatamente o mesmo total da listagem paginada.');
    $check(!isset($exportDesligados[0]['salario_atual']) && !isset($exportDesligados[0]['cpf']), '(E-2) Exportação contextual nunca inclui salário/CPF — mesma disciplina de privacidade.');

    echo "\nPEOPLE_ANALYTICS_INTERATIVO_OK\n";
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
