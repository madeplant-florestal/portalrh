<?php

/**
 * Integração — Listagem "Colaboradores" do People Analytics (antecipação da Etapa 2, correção de
 * 2026-09). Prova, via PeopleAnalyticsService::listarColaboradores()/exportarColaboradoresCsv()
 * reais (nunca a query isolada):
 *   - população padrão = só vigentes (ativo=1 AND ausente_na_origem=0) — um registro órfão de
 *     transferência contínua nunca aparece na listagem, só o contrato vigente conta;
 *   - Situação nunca é inventada: com a população restrita a vigentes, todo item aparece como
 *     "Ativo" (Desligado/Transferido exigiriam ativo=0, fora da população padrão atual — limitação
 *     documentada, não um bug);
 *   - Tempo de Empresa é calculado a partir da admissão real, com plural correto em português
 *     ("1 mês" vs "5 meses", nunca "mêses") — cobre anos+meses, só meses e só dias;
 *   - Gestor Imediato vem de usuarios.colaborador_metadados_id → usuarios.gestor_usuario_id →
 *     nome do gestor (nunca aprovador_usuario_id); sem vínculo Portal, mostra "Não informado";
 *   - filtro codigo_setor = SETOR_NAO_INFORMADO localiza exatamente quem tem codigo_setor vazio,
 *     nunca escondendo essa categoria (qualidade cadastral, §17 da correção de 2026-09);
 *   - paginação (20/página) respeita o total real e preenche a segunda página corretamente;
 *   - exportarColaboradoresCsv() entrega exatamente a mesma população filtrada, sem paginação.
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
$criados = ['metadados_identificadores' => [], 'usuarios_ids' => []];

$insert = $pdo->prepare(
    'INSERT INTO colaboradores_metadados (
        identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
        cpf, nome, admissao, demissao, ativo, ausente_na_origem, origem_metadados,
        setor, codigo_setor, cargo, sexo, centro_custo, unidade
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$mk = function (
    string $sufixo,
    string $codigoEmpresa,
    ?string $admissao,
    bool $ausenteNaOrigem = false,
    ?string $codigoSetor = null,
    ?string $sexo = null
) use ($pdo, $insert, &$criados, $suffix): array {
    $identificador = 'ZZLST_' . $suffix . '_' . $sufixo;
    $codigoPessoa = 'ZZP' . substr(md5($suffix . $sufixo), 0, 14);
    $numeroContrato = 'ZZC' . substr(md5($sufixo), 0, 14);
    $insert->execute([
        $identificador, $codigoEmpresa, 'ZZU' . $suffix, $numeroContrato, $codigoPessoa,
        substr('9' . $suffix . substr(md5($sufixo), 0, 5), 0, 11), 'ZZLST Fixture ' . $sufixo, $admissao, null, 1, $ausenteNaOrigem ? 1 : 0, 'zzlst-teste',
        $codigoSetor !== null ? 'Setor ' . $codigoSetor : null, $codigoSetor, 'Cargo Teste', $sexo, 'CC Teste', 'Unidade Teste',
    ]);
    $criados['metadados_identificadores'][] = $identificador;
    $id = (int)$pdo->lastInsertId();
    return ['identificador' => $identificador, 'id' => $id];
};

try {
    $hoje = new DateTimeImmutable('today');
    $service = new PeopleAnalyticsService();

    // ---- Tempo de Empresa: anos+meses, só meses (plural correto), só dias -----------------------
    $empTempo = 'ZZT1' . $suffix;
    $rowAnosMeses = $mk('TEMPO-ANOS-MESES', $empTempo, $hoje->modify('-2 years -3 months')->format('Y-m-d'), false, 'ST1');
    $rowMesesPlural = $mk('TEMPO-MESES-PLURAL', $empTempo, $hoje->modify('-5 months')->format('Y-m-d'), false, 'ST1');
    $rowMesSingular = $mk('TEMPO-MES-SINGULAR', $empTempo, $hoje->modify('-1 month')->format('Y-m-d'), false, 'ST1');
    $rowDias = $mk('TEMPO-DIAS', $empTempo, $hoje->modify('-10 days')->format('Y-m-d'), false, 'ST1');

    $listagemTempo = $service->listarColaboradores(['codigo_empresa' => $empTempo], 1, 20);
    $porIdentificadorTempo = [];
    foreach ($listagemTempo['items'] as $item) {
        $porIdentificadorTempo[$item['nome']] = $item;
    }
    $check($listagemTempo['total'] === 4, '(T-0) Listagem filtrada por empresa isolada da fixture: total = 4 registros vigentes inseridos.');
    $check(($porIdentificadorTempo['ZZLST Fixture TEMPO-ANOS-MESES']['tempo_empresa'] ?? null) === '2 anos e 3 meses', '(T-1) Tempo de Empresa com anos e meses: "2 anos e 3 meses".');
    $check(($porIdentificadorTempo['ZZLST Fixture TEMPO-MESES-PLURAL']['tempo_empresa'] ?? null) === '5 meses', '(T-2) Tempo de Empresa só com meses (plural correto, sem o bug "mêses"): "5 meses".');
    $check(($porIdentificadorTempo['ZZLST Fixture TEMPO-MES-SINGULAR']['tempo_empresa'] ?? null) === '1 mês', '(T-3) Tempo de Empresa com 1 mês (singular correto): "1 mês".');
    $check(($porIdentificadorTempo['ZZLST Fixture TEMPO-DIAS']['tempo_empresa'] ?? null) === '10 dias', '(T-4) Tempo de Empresa com menos de um mês, só em dias: "10 dias".');
    $check(($porIdentificadorTempo['ZZLST Fixture TEMPO-DIAS']['situacao'] ?? null) === 'Ativo', '(T-5) Situação de um registro vigente comum: "Ativo".');

    // ---- Gestor Imediato: usuarios.colaborador_metadados_id -> gestor_usuario_id -> nome ----------
    $empGestor = 'ZZG1' . $suffix;
    $colabComGestor = $mk('GESTOR-COM', $empGestor, $hoje->modify('-400 days')->format('Y-m-d'), false, 'ST2');
    $colabSemGestor = $mk('GESTOR-SEM', $empGestor, $hoje->modify('-400 days')->format('Y-m-d'), false, 'ST2');

    $senhaHash = password_hash('fixture-senha-teste', PASSWORD_BCRYPT);
    $insertUsuario = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, role, is_supervisor, email_verified_at, colaborador_metadados_id, gestor_usuario_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)');

    $insertUsuario->execute(['ZZLST Gestor Fixture ' . $suffix, 'zzlst.gestor.' . $suffix . '@fixture.local', $senhaHash, 'rh', 0, null, null]);
    $gestorId = (int)$pdo->lastInsertId();
    $criados['usuarios_ids'][] = $gestorId;

    $insertUsuario->execute(['ZZLST Usuario Fixture ' . $suffix, 'zzlst.usuario.' . $suffix . '@fixture.local', $senhaHash, 'rh', 0, $colabComGestor['id'], $gestorId]);
    $criados['usuarios_ids'][] = (int)$pdo->lastInsertId();

    $listagemGestor = $service->listarColaboradores(['codigo_empresa' => $empGestor], 1, 20);
    $porNomeGestor = [];
    foreach ($listagemGestor['items'] as $item) {
        $porNomeGestor[$item['nome']] = $item;
    }
    $check(($porNomeGestor['ZZLST Fixture GESTOR-COM']['gestor_imediato'] ?? null) === 'ZZLST Gestor Fixture ' . $suffix, '(G-1) Gestor Imediato resolvido via usuarios.colaborador_metadados_id -> gestor_usuario_id -> nome do gestor.');
    $check(($porNomeGestor['ZZLST Fixture GESTOR-SEM']['gestor_imediato'] ?? null) === 'Não informado', '(G-2) Sem usuário Portal vinculado ao contrato: Gestor Imediato = "Não informado" (nunca inventado).');

    // ---- Setor não informado: filtro nunca esconde a categoria ------------------------------------
    $empSetor = 'ZZS1' . $suffix;
    $mk('SETOR-INFORMADO', $empSetor, $hoje->modify('-100 days')->format('Y-m-d'), false, 'ST3');
    $mk('SETOR-VAZIO', $empSetor, $hoje->modify('-100 days')->format('Y-m-d'), false, null);

    $listagemSetorVazio = $service->listarColaboradores(['codigo_empresa' => $empSetor, 'codigo_setor' => ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO], 1, 20);
    $check($listagemSetorVazio['total'] === 1, '(S-1) Filtro codigo_setor=SETOR_NAO_INFORMADO: localiza exatamente 1 registro (o de codigo_setor vazio) na empresa isolada.');
    $check(($listagemSetorVazio['items'][0]['nome'] ?? null) === 'ZZLST Fixture SETOR-VAZIO', '(S-2) O registro retornado é o de setor vazio, não o informado.');
    $check(($listagemSetorVazio['items'][0]['setor'] ?? null) === 'Não informado', '(S-3) Rótulo exibido para setor vazio: "Não informado" (nunca uma string vazia).');

    $listagemSetorInformado = $service->listarColaboradores(['codigo_empresa' => $empSetor, 'codigo_setor' => 'ST3'], 1, 20);
    $check($listagemSetorInformado['total'] === 1, '(S-4) Filtro por codigo_setor=ST3 continua funcionando normalmente (coexiste com a sentinela).');

    // ---- Paginação: 21 registros vigentes -> página 1 com 20, página 2 com 1 ----------------------
    $empPag = 'ZZP1' . $suffix;
    for ($i = 1; $i <= 21; $i++) {
        $mk('PAG-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), $empPag, $hoje->modify('-300 days')->format('Y-m-d'), false, 'ST4');
    }

    $pagina1 = $service->listarColaboradores(['codigo_empresa' => $empPag], 1, 20);
    $pagina2 = $service->listarColaboradores(['codigo_empresa' => $empPag], 2, 20);
    $check($pagina1['total'] === 21, '(P-1) Total de registros vigentes na empresa isolada de paginação: 21.');
    $check($pagina1['pages'] === 2, '(P-2) Com per_page=20 e total=21: 2 páginas.');
    $check(count($pagina1['items']) === 20, '(P-3) Página 1 traz exatamente 20 itens.');
    $check(count($pagina2['items']) === 1, '(P-4) Página 2 traz o item restante (1).');
    $check($pagina2['page'] === 2, '(P-5) page retornado reflete a página pedida.');
    $check($pagina1['per_page'] === 20, '(P-6) per_page retornado é 20.');

    // per_page não permitido (ex.: 30) cai no padrão (20) — mesma regra do repositório.
    $paginaPerPageInvalido = $service->listarColaboradores(['codigo_empresa' => $empPag], 1, 30);
    $check($paginaPerPageInvalido['per_page'] === 20, '(P-7) per_page fora da whitelist (30) cai para o padrão (20).');

    // ---- Exportação CSV: mesma população, sem paginação -------------------------------------------
    $exportacao = $service->exportarColaboradoresCsv(['codigo_empresa' => $empPag]);
    $check(count($exportacao) === 21, '(E-1) exportarColaboradoresCsv() entrega os 21 registros da mesma população filtrada, sem paginação.');
    $check(isset($exportacao[0]['nome'], $exportacao[0]['situacao'], $exportacao[0]['tempo_empresa'], $exportacao[0]['gestor_imediato']), '(E-2) Cada linha exportada tem as mesmas colunas enriquecidas da listagem paginada.');
    $check(!isset($exportacao[0]['salario_atual']) && !isset($exportacao[0]['cpf']), '(E-3) Exportação nunca inclui salário ou CPF — só as colunas operacionais aprovadas.');

    // ---- Transferência contínua: origem órfã nunca aparece na listagem padrão ---------------------
    $empTransfOrigem = 'ZZTO' . $suffix;
    $empTransfDestino = 'ZZTD' . $suffix;
    $mk('TRANSF-ORIGEM', $empTransfOrigem, $hoje->modify('-500 days')->format('Y-m-d'), true, 'ST5');
    $mk('TRANSF-DESTINO', $empTransfDestino, $hoje->modify('-500 days')->format('Y-m-d'), false, 'ST5');
    $listagemOrigemTransferida = $service->listarColaboradores(['codigo_empresa' => $empTransfOrigem], 1, 20);
    $check($listagemOrigemTransferida['total'] === 0, '(X-1) Registro órfão de transferência (ausente_na_origem=1) nunca aparece na listagem padrão de vigentes — só a empresa de destino conta.');
    $listagemDestinoTransferido = $service->listarColaboradores(['codigo_empresa' => $empTransfDestino], 1, 20);
    $check($listagemDestinoTransferido['total'] === 1, '(X-2) Empresa de destino da transferência contínua: o registro vigente aparece normalmente.');

    echo "\nPEOPLE_ANALYTICS_LISTAGEM_OK\n";
} finally {
    if (!empty($criados['usuarios_ids'])) {
        $placeholders = implode(',', array_fill(0, count($criados['usuarios_ids']), '?'));
        // Zera gestor_usuario_id antes de apagar — evita violar fk_usuarios_gestor quando o
        // "gestor" fixture é removido antes do "usuário" fixture que aponta para ele no mesmo lote.
        $pdo->prepare("UPDATE usuarios SET gestor_usuario_id = NULL WHERE id IN ($placeholders)")->execute($criados['usuarios_ids']);
        $pdo->prepare("DELETE FROM usuarios WHERE id IN ($placeholders)")->execute($criados['usuarios_ids']);
    }
    if (!empty($criados['metadados_identificadores'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados_identificadores']), '?'));
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados_identificadores']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
