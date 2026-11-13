<?php

/**
 * Integração — Frente C (2026-11-13): Headcount do card e listagem "Ativos" usam o MESMO predicado de
 * VIGÊNCIA CONTRATUAL (ColaboradorMetadadosElegibilidadeService::clausulaSqlVigente): admissão <= data,
 * demissão nula ou >= data, não ausente na origem, não excluído analiticamente. A flag `ativo` não exclui
 * antecipadamente um desligamento programado (caso real: contrato 333, ativo=0, demissão futura).
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

$fx = new ExclusaoFixtures($pdo);
$sessaoOriginal = $_SESSION ?? [];
$hoje = new DateTimeImmutable('today');
$d = static fn(string $mod): string => $hoje->modify($mod)->format('Y-m-d');

try {
    $_SESSION = ['user_role' => 'admin', 'user_id' => 1];
    $S::reiniciarEstadoDaRequisicao();
    $setor = 'ZS' . substr($fx->emp, 2);
    $o = static fn(array $x) => $x + ['codigo_setor' => $setor];

    $fx->contrato('1', 'P1', 'ZZHC antigo sem demissao', $o(['admissao' => $d('-400 days')]));                       // vigente
    $fx->contrato('2', 'P2', 'ZZHC desligamento futuro ativo0', $o(['admissao' => $d('-300 days'), 'demissao' => $d('+5 days'), 'ativo' => 0])); // vigente (caso 333)
    $fx->contrato('3', 'P3', 'ZZHC demissao hoje ativo0', $o(['admissao' => $d('-300 days'), 'demissao' => $d('0 days'), 'ativo' => 0]));      // vigente (último dia)
    $fx->contrato('4', 'P4', 'ZZHC demissao ontem', $o(['admissao' => $d('-300 days'), 'demissao' => $d('-1 day'), 'ativo' => 0]));              // fora
    $fx->contrato('5', 'P5', 'ZZHC admissao futura', $o(['admissao' => $d('+3 days'), 'ativo' => 1]));                                           // fora
    $fx->contrato('6', 'P6', 'ZZHC ausente na origem', $o(['admissao' => $d('-100 days')]));                                                      // fora
    $fx->contrato('7', 'P7', 'ZZHC excluido analiticamente', $o(['admissao' => $d('-100 days')]));                                                // fora
    $fx->contrato('8', 'P8', 'ZZHC multiplos A', $o(['admissao' => $d('-500 days')]));                                                            // vigente
    $fx->contrato('9', 'P8', 'ZZHC multiplos B', $o(['admissao' => $d('-50 days')]));                                                             // vigente (mesma pessoa)
    $fx->contrato('10', 'P10', 'ZZHC sem admissao', $o(['admissao' => null, 'ativo' => 1]));                                                      // fora
    $pdo->prepare("UPDATE colaboradores_metadados SET ausente_na_origem = 1 WHERE codigo_empresa = ? AND numero_contrato = '6'")->execute([$fx->emp]);
    $fx->contrato('11', 'P11', 'ZZHC ativo1 demissao passada', $o(['admissao' => $d('-300 days'), 'demissao' => $d('-3 days'), 'ativo' => 1]));  // inconsistência: fora de ativos, desligado
    $fx->contrato('12', 'P12', 'ZZHC ativo0 sem demissao', $o(['admissao' => $d('-100 days'), 'ativo' => 0]));                              // vigente por data; NÃO é desligado
    $fx->contrato('13', 'P13', 'ZZHC admissao futura ativo0', $o(['admissao' => $d('+10 days'), 'ativo' => 0]));                            // nem ativo nem desligado
    $pdo->prepare("UPDATE colaboradores_metadados SET admissao = NULL WHERE codigo_empresa = ? AND numero_contrato = '10'")->execute([$fx->emp]); // o helper troca null por default
    $fx->excluir('7');
    $esperadoContratos = 6; // contratos 1, 2, 3, 8, 9, 12

    $repo = new ColaboradorMetadadosConsultaRepository();
    $resumo = $repo->summary([$setor]);
    $pagina = $repo->paginateExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos'], 1, 25);
    $todos = $repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos']);
    $nomes = array_column($pagina['items'], 'nome');

    // card (mesma regra de PeopleAnalyticsService): contratos do repositório de analytics + headcountEm
    $contratos = (new PeopleAnalyticsRepository())->buscarContratos(['codigo_empresa' => $fx->emp]);
    $contratosCard = array_values(array_filter($contratos, static fn(array $c): bool => (int)($c['ausente_na_origem'] ?? 0) === 0));
    $card = RhIndicadoresService::headcountEm($contratosCard, $hoje);

    $check($pagina['total'] === $esperadoContratos, "listagem ativos = {$esperadoContratos} contratos (obtido {$pagina['total']})");
    $check(in_array('ZZHC desligamento futuro ativo0', $nomes, true), 'desligamento futuro com ativo=0 continua na listagem de ativos');
    $check(in_array('ZZHC demissao hoje ativo0', $nomes, true), 'demissão exatamente na data de referência ainda é vigente');
    $check(!in_array('ZZHC demissao ontem', $nomes, true), 'demissão anterior à data sai da listagem');
    $check(!in_array('ZZHC admissao futura', $nomes, true), 'admissão futura não conta, mesmo com ativo=1');
    $check(!in_array('ZZHC ausente na origem', $nomes, true), 'ausente na origem não conta');
    $check(!in_array('ZZHC excluido analiticamente', $nomes, true), 'excluído analiticamente não conta');
    $check(!in_array('ZZHC sem admissao', $nomes, true), 'sem admissão preenchida não conta');
    $check($card === $esperadoContratos, "card Headcount = {$esperadoContratos} (obtido {$card})");
    $check($card === $pagina['total'], 'card e listagem concordam');
    $check($resumo['ativos'] === $pagina['total'], 'summary()[ativos] concorda com a listagem');
    $check(count($todos) === $pagina['total'], 'exportação (listarExecutivo) concorda com contador e paginação');
    $pagina2 = $repo->paginateExecutivo(['empresa' => $fx->emp, 'situacao' => 'ativos'], 1, 25);
    $check(count($pagina2['items']) === $pagina2['total'], 'itens da página somam o total (sem divergência de paginação)');
    $check(count(array_unique(array_column($todos, 'codigo_pessoa'))) === 5, 'múltiplos contratos: 6 contratos, 5 pessoas distintas (unidade = contrato)');

    // desligados é complemento de ativos: desligamento futuro NÃO aparece como desligado
    $deslig = array_column($repo->listarExecutivo(['empresa' => $fx->emp, 'situacao' => 'desligados']), 'nome');
    $check(!in_array('ZZHC desligamento futuro ativo0', $deslig, true), 'desligamento futuro não aparece em "desligados"');
    $check(in_array('ZZHC demissao ontem', $deslig, true), 'demissão passada aparece em "desligados"');
    // Revisão pontual (2026-11-13): "desligado" = desligamento EFETIVO por data, nunca pela flag nem por ausência de dado
    $check(in_array('ZZHC ativo1 demissao passada', $deslig, true), 'ativo=1 com demissão passada é desligado (a data manda) e não é ativo');
    $check(!in_array('ZZHC ativo1 demissao passada', $nomes, true), 'ativo=1 com demissão passada sai de "ativos"');
    $check(!in_array('ZZHC ativo0 sem demissao', $deslig, true), 'ativo=0 sem demissão não é desligado (sem desligamento efetivo)');
    $check(!in_array('ZZHC admissao futura ativo0', $deslig, true) && !in_array('ZZHC admissao futura ativo0', $nomes, true), 'admissão futura não é ativa nem desligada');
    $check(!in_array('ZZHC sem admissao', $deslig, true), 'sem admissão (sem demissão) não é desligado');
    $check(!in_array('ZZHC ausente na origem', $deslig, true), 'ausente na origem sem demissão não é desligado');
    $check(!in_array('ZZHC demissao hoje ativo0', $deslig, true), 'demissão hoje ainda é vigente, não desligado');
    $resumo2 = $repo->summary([$setor]);
    $check($resumo2['desligados'] === count($deslig), 'summary()[desligados] concorda com a listagem de desligados');
    $check($resumo2['ativos'] + $resumo2['desligados'] <= $resumo2['contratos'], 'ativos e desligados não se sobrepõem');

    // distribuição por setor (gráfico) soma o mesmo que o card
    $dist = (new PeopleAnalyticsRepository())->distribuicaoAtivosPorSetor(['codigo_empresa' => $fx->emp]);
    $check((int)array_sum(array_column($dist, 'contratos')) === $esperadoContratos, 'distribuição de ativos por setor soma o mesmo valor do card');

    // headcount histórico preservado: no passado a regra por data continua valendo (demissão de ontem estava vigente há 2 dias)
    $hist = RhIndicadoresService::headcountEm($contratosCard, $hoje->modify('-2 days'));
    $check($hist === 7, "histórico -2 dias: contratos 1,2,3,4,8,9,12 vigentes (admissão futura e excluído fora); obtido {$hist}");
} finally {
    $fx->limpar();
    $_SESSION = $sessaoOriginal;
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — Frente C (vigência contratual).\n";
