<?php

/**
 * Integração — Frente A (2026-11-13): "Anúncios ativos" x "Posições solicitadas" do Dashboard de R&S.
 * Causa corrigida: o card contava SOLICITAÇÕES aprovadas (sem olhar `vagas`), então rascunho (ativo=0) entrava.
 * Regra nova: anúncio = `vagas.ativo = 1`; ligado a solicitação só vale se ela segue aprovada/aberta; posições =
 * SUM(quantidade_vagas) por solicitação distinta; anúncio manual não tem quantidade (nunca presumida).
 * Mede sempre por Empresa de fixture (isolada), então não depende dos dados do banco.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

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

$fx = new ExclusaoFixtures($pdo);
$S = ColaboradorMetadadosElegibilidadeService::class;
$sessaoOriginal = $_SESSION ?? [];
$cargosCriados = [];

try {
    $_SESSION = ['user_role' => 'admin', 'user_id' => 1];
    $S::reiniciarEstadoDaRequisicao();
    $stage = [];
    foreach ($pdo->query('SELECT id, slug FROM solicitacao_vaga_stages') as $r) {
        $stage[$r['slug']] = (int)$r['id'];
    }

    $empresa = $fx->empresaOficial();
    $outraEmpresa = $fx->reg('emp', $fx->inserirGenerico('empresas', ['nome' => "ZZAP Outra {$fx->emp}", 'slug' => 'zzap-outra-' . strtolower($fx->emp), 'codigo_empresa' => 'O' . $fx->emp]));
    $setor = $fx->reg('setor', $fx->inserirGenerico('setores', ['empresa_id' => $empresa, 'nome' => "ZZAP Setor {$fx->emp}", 'codigo_setor' => 'Z' . random_int(100, 999)]));
    $mkCargo = static function (string $nome) use ($fx, &$cargosCriados): int {
        $id = $fx->inserirGenerico('cargos', ['nome' => $nome, 'slug' => 'zzap-' . strtolower($fx->emp) . '-' . random_int(1000, 9999), 'codigo_cargo' => 'Z' . random_int(10000, 99999)]);
        $cargosCriados[] = $id;
        $GLOBALS['cargosCriados'] = $cargosCriados;
        return $id;
    };
    $cargoA = $mkCargo("ZZAP Motorista de Carreta {$fx->emp}");
    $cargoB = $mkCargo("ZZAP Operador {$fx->emp}");

    $sv = static fn(array $v): int => $fx->solicitacaoVaga($v + ['setor_id' => $setor, 'cargo_id' => $GLOBALS['cargoA'], 'status_fluxo' => 'aprovada', 'situacao_kanban_id' => $GLOBALS['stage']['aprovada'], 'quantidade_vagas' => 1]);
    $GLOBALS['cargoA'] = $cargoA;
    $GLOBALS['stage'] = $stage;
    $anuncio = static function (string $t, ?int $sol, int $ativo, ?int $emp = null) use ($fx, $pdo, $empresa): int {
        $id = $fx->vaga($t, $sol);
        $pdo->prepare('UPDATE vagas SET ativo = ?, empresa_id = ? WHERE id = ?')->execute([$ativo, $emp ?? $empresa, $id]);
        return $id;
    };

    $repo = new RecrutamentoIndicadoresRepository();
    $res = static fn(?int $e = null): array => $repo->resumirAnunciosEPosicoes($e ?? $GLOBALS['empresaMedida']);
    $GLOBALS['empresaMedida'] = $empresa;

    $vazio = $res();
    $check($vazio['anuncios_ativos'] === 0 && $vazio['posicoes_solicitadas'] === 0 && $vazio['por_cargo'] === [], 'empresa de fixture começa zerada');

    // (1)(6) aprovada com 1 posição + anúncio ativo
    $s1 = $sv(['quantidade_vagas' => 1]);
    $v1 = $anuncio('ZZAP v1', $s1, 1);
    $r = $res();
    $check($r['anuncios_ativos'] === 1 && $r['posicoes_solicitadas'] === 1, '(1)(6) aprovada + anúncio ativo + 1 posição → 1 anúncio, 1 posição');

    // (7) aprovada com 5 posições (Motorista de Carreta) → 1 anúncio, 5 posições
    $s2 = $sv(['quantidade_vagas' => 5, 'situacao_kanban_id' => $stage['em-recrutamento']]);
    $v2 = $anuncio('ZZAP v2', $s2, 1);
    $r = $res();
    $check($r['anuncios_ativos'] === 2 && $r['posicoes_solicitadas'] === 6, '(7) solicitação de 5 posições soma 5 (total 2 anúncios, 6 posições)');

    // (8) outra solicitação do MESMO cargo: soma separada, consolidada só na apresentação
    $s3 = $sv(['quantidade_vagas' => 3]);
    $anuncio('ZZAP v3', $s3, 1);
    $r = $res();
    $grupoA = array_values(array_filter($r['por_cargo'], static fn($g) => $g['cargo'] === "ZZAP Motorista de Carreta {$fx->emp}"));
    $check($r['posicoes_solicitadas'] === 9 && count($grupoA) === 1 && $grupoA[0]['anuncios'] === 3 && $grupoA[0]['posicoes'] === 9, '(8) 3 solicitações do mesmo cargo: demandas somadas e consolidadas numa linha (3 anúncios, 9 posições)');
    $s4 = $sv(['quantidade_vagas' => 2, 'cargo_id' => $cargoB]);
    $anuncio('ZZAP v4', $s4, 1);
    $r = $res();
    $check(count($r['por_cargo']) === 2 && $r['posicoes_solicitadas'] === 11, 'cargo diferente vira outra linha (11 posições no total)');

    // (2)(3) rascunho/inativa: aprovada, anúncio ativo=0 → não conta como anúncio nem posição; aparece como "aguardando publicação"
    $s5 = $sv(['quantidade_vagas' => 4]);
    $v5 = $anuncio('ZZAP rascunho', $s5, 0);
    $r = $res();
    $check($r['anuncios_ativos'] === 4 && $r['posicoes_solicitadas'] === 11, '(2)(3) rascunho/inativa não conta anúncio nem posições');
    $check($r['aguardando_publicacao'] === 1, 'aprovada sem anúncio ativo é contada à parte como "aguardando publicação"');

    // (4) fechada, (5) cancelada, solicitação não aprovada — mesmo com anúncio ativo=1
    $s6 = $sv(['quantidade_vagas' => 7, 'situacao_kanban_id' => $stage['fechada']]);
    $anuncio('ZZAP fechada', $s6, 1);
    $s7 = $sv(['quantidade_vagas' => 7, 'situacao_kanban_id' => $stage['cancelada']]);
    $anuncio('ZZAP cancelada kanban', $s7, 1);
    $s8 = $sv(['quantidade_vagas' => 7, 'cancelada_em' => '2026-11-01 10:00:00']);
    $anuncio('ZZAP cancelada em', $s8, 1);
    $s9 = $sv(['quantidade_vagas' => 7, 'status_fluxo' => 'pendente_rh', 'situacao_kanban_id' => $stage['em-aprovacao']]);
    $anuncio('ZZAP pendente', $s9, 1);
    $s10 = $sv(['quantidade_vagas' => 7, 'status_fluxo' => 'reprovada_rh']);
    $anuncio('ZZAP reprovada', $s10, 1);
    $r = $res();
    $check($r['anuncios_ativos'] === 4 && $r['posicoes_solicitadas'] === 11, '(4)(5) fechada, cancelada (kanban e cancelada_em), pendente e reprovada não contam, mesmo com anúncio ativo');

    // quantidade 0 → conta o anúncio, soma 0 posição (não presume 1)
    $s11 = $sv(['quantidade_vagas' => 0]);
    $anuncio('ZZAP qtd zero', $s11, 1);
    $r = $res();
    $check($r['anuncios_ativos'] === 5 && $r['posicoes_solicitadas'] === 11, 'quantidade 0 conta o anúncio e soma 0 posições');

    // (9) vaga manual (sem solicitação): anúncio ativo, posições NÃO informadas; manual inativa não conta
    $m1 = $anuncio('ZZAP manual', null, 1);
    $anuncio('ZZAP manual inativa', null, 0);
    $anuncio('ZZAP manual outra empresa', null, 1, $outraEmpresa);
    $r = $res();
    $manual = array_values(array_filter($r['por_cargo'], static fn($g) => $g['posicoes'] === null));
    $check($r['anuncios_ativos'] === 6 && $r['anuncios_manuais'] === 1 && $r['posicoes_solicitadas'] === 11, '(9) manual conta como anúncio ativo, sem inventar posições');
    $check(count($manual) === 1 && $manual[0]['anuncios'] === 1, '(9) manual aparece numa linha própria com posições "não informadas" (null)');

    // (10) vários anúncios por solicitação: o schema impede (UNIQUE solicitacao_vaga_id) — nunca duplica posições
    $bloqueou = false;
    try {
        $fx->vaga('ZZAP duplicado', $s1);
    } catch (Throwable) {
        $bloqueou = true;
    }
    $check($bloqueou && $res()['posicoes_solicitadas'] === 11, '(10) segundo anúncio para a mesma solicitação é barrado pelo banco; posições não duplicam');

    // (11) várias candidaturas na mesma vaga não multiplicam
    foreach (['A', 'B', 'C'] as $n) {
        $fx->candidatura($v2, "ZZAP Candidato {$n}");
    }
    $r = $res();
    $check($r['anuncios_ativos'] === 6 && $r['posicoes_solicitadas'] === 11, '(11) candidaturas múltiplas na vaga não alteram anúncios nem posições');

    // (12) mudança de publicação e de status
    $pdo->prepare('UPDATE vagas SET ativo = 1 WHERE id = ?')->execute([$v5]);
    $r = $res();
    $check($r['anuncios_ativos'] === 7 && $r['posicoes_solicitadas'] === 15 && $r['aguardando_publicacao'] === 0, '(12) publicar o rascunho passa a contar anúncio e 4 posições');
    $pdo->prepare('UPDATE vagas SET ativo = 0 WHERE id = ?')->execute([$v1]);
    $check($res()['anuncios_ativos'] === 6 && $res()['posicoes_solicitadas'] === 14, '(12) despublicar remove anúncio e posições');
    $pdo->prepare('UPDATE solicitacoes_vaga SET situacao_kanban_id = ? WHERE id = ?')->execute([$stage['fechada'], $s2]);
    $check($res()['posicoes_solicitadas'] === 9 && $res()['anuncios_ativos'] === 5, '(12) fechar a solicitação tira as 5 posições (situação atual, não histórica)');

    // (13) filtro de empresa
    $check($repo->resumirAnunciosEPosicoes($outraEmpresa)['anuncios_ativos'] === 1 && $repo->resumirAnunciosEPosicoes($outraEmpresa)['posicoes_solicitadas'] === 0, '(13) filtro de Empresa isola: a outra empresa só vê o seu manual');
    $total = $repo->resumirAnunciosEPosicoes(null);
    $check($total['anuncios_ativos'] >= $res()['anuncios_ativos'] + 1, '(13) sem filtro soma todas as empresas (inclui a de fixture)');

    // exclusão analítica preservada: solicitação ligada a colaborador de contrato excluído sai da contagem
    $mid = $fx->contrato('1', 'PZAP1', 'ZZAP Contratado');
    $col = $fx->colaboradorLocal('ZZAP Contratado Local', $mid);
    $sx = $sv(['quantidade_vagas' => 6, 'nome_contratado_colaborador_id' => $col]);
    $anuncio('ZZAP excl', $sx, 1);
    $antes = $res();
    $fx->excluir('1');
    $depois = $res();
    $check($antes['posicoes_solicitadas'] === 15 && $depois['posicoes_solicitadas'] === 9 && $depois['anuncios_ativos'] === $antes['anuncios_ativos'] - 1, 'exclusão analítica: solicitação com colaborador de contrato excluído sai de anúncios e posições');

    // (15) indicadores anteriores preservados: contarSolicitacoesAbertas continua com a regra de Bloco 5/6
    $abertas = (int)$repo->contarSolicitacoesAbertas($empresa)['total'];
    $check($abertas === 5, "(15) contarSolicitacoesAbertas mantém a regra anterior (solicitações aprovadas em aberto): esperado 5, obtido {$abertas}");
    $painel = (new RecrutamentoIndicadoresService())->montarPainel(['empresa_id' => $empresa], new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2020-01-31'));
    $check(isset($painel['vagas']['abertas'], $painel['vagas']['fechadas_no_periodo'], $painel['vagas']['anuncios_ativos'], $painel['vagas']['posicoes_solicitadas'], $painel['vagas']['por_cargo']), '(15) painel mantém chaves anteriores e expõe as novas');
    $check($painel['vagas']['anuncios_ativos'] === $depois['anuncios_ativos'], 'período antigo não transforma vaga fechada em aberta nem altera a situação atual');
} finally {
    $fx->limpar();
    if ($cargosCriados !== []) {
        $pdo->exec('DELETE FROM cargos WHERE id IN (' . implode(',', array_map('intval', $cargosCriados)) . ')');
    }
    $_SESSION = $sessaoOriginal;
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}
echo "\nOK — Frente A (anúncios ativos e posições solicitadas).\n";
