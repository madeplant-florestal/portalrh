<?php

/**
 * Integração — FASE C: a exclusão analítica também vale para o cadastro LOCAL (`colaboradores`, ligado
 * por `metadados_id`) e para os PROCESSOS que o referenciam (Solicitação de Vaga, Movimentação de
 * Pessoal, avaliação legada). Método: fixtures ZZEXF → observa ANTES e DEPOIS de excluir o contrato.
 *
 * Prova: seletores/listas/contagens sem o colaborador local excluído; processos anteriores PRESERVADOS
 * (não apagados, estado inalterado) porém fora de listas e estatísticas; acesso individual só Admin em
 * leitura; novo processo/edição negado a qualquer perfil; vínculos legítimos e colaboradores sem
 * contrato não são afetados; nada é excluído por nome/CPF.
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
$metodoOriginal = $_SERVER['REQUEST_METHOD'] ?? null;
$como = static function (?string $role, string $metodo = 'GET') use ($S): void {
    $_SESSION = $role === null ? [] : ['user_role' => $role, 'user_id' => 1];
    $_SERVER['REQUEST_METHOD'] = $metodo;
    $S::reiniciarEstadoDaRequisicao();
};

try {
    $como('admin');
    $idExc = $fx->contrato('1', 'PZC1', 'ZZEXF Cadastro Excluido');
    $idOk = $fx->contrato('2', 'PZC2', 'ZZEXF Cadastro Legitimo');

    // colaboradores LOCAIS: ligado ao excluído / ao legítimo / sem contrato / sem contrato com MESMO NOME do excluído
    $colExc = $fx->colaboradorLocal('ZZEXF Local Excluido', $idExc);
    $colOk = $fx->colaboradorLocal('ZZEXF Local Legitimo', $idOk);
    $colSem = $fx->colaboradorLocal('ZZEXF Local Sem Contrato', null);
    $colMesmoNome = $fx->colaboradorLocal('ZZEXF Local Excluido', null); // mesmo nome, sem contrato → NÃO pode ser afetado

    // usuários com acesso (seletores de líder / gestor)
    foreach ([$colExc, $colOk, $colSem] as $c) {
        $uid = $fx->usuario("ZZEXF Usuario {$c}", 'rh');
        $fx->usuarioColaborador($uid, $c);
    }

    // processos: um ligado ao excluído, um ao legítimo
    $stageFechada = (int)$pdo->query("SELECT id FROM solicitacao_vaga_stages WHERE slug = 'fechada'")->fetchColumn();
    $svBase = ['situacao_kanban_id' => $stageFechada ?: null, 'status_fluxo' => 'aprovada', 'fechada_em' => '2025-06-10 10:00:00', 'jornada_trabalho' => 'ZZEXF'];
    $svExc = $fx->solicitacaoVaga($svBase + ['gestor_solicitante_colaborador_id' => $colSem, 'colaborador_substituido_id' => $colExc]);
    $svOk = $fx->solicitacaoVaga($svBase + ['gestor_solicitante_colaborador_id' => $colSem, 'colaborador_substituido_id' => $colOk]);
    $mpExc = $fx->movimentacao(['colaborador_id' => $colExc, 'status_fluxo' => 'rascunho']);
    $mpOk = $fx->movimentacao(['colaborador_id' => $colOk, 'status_fluxo' => 'rascunho']);
    $avExc = $fx->avaliacaoLegada($colExc, 'ZZEXF Avaliacao Excluido');
    $avOk = $fx->avaliacaoLegada($colOk, 'ZZEXF Avaliacao Legitimo');

    $estadoProcessos = static fn(): array => [
        'sv' => $pdo->query("SELECT id, status_fluxo, colaborador_substituido_id FROM solicitacoes_vaga WHERE id IN ({$svExc}, {$svOk}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'mp' => $pdo->query("SELECT id, status_fluxo, colaborador_id FROM movimentacoes_pessoal WHERE id IN ({$mpExc}, {$mpOk}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    ];
    $processosAntes = $estadoProcessos();

    $recrut = new RecrutamentoIndicadoresRepository($pdo);
    $empresaId = $fx->empresaOficial();
    $cargoUsoId = (int)$pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
    $nomes = static fn(array $linhas, string $chave): array => array_column($linhas, $chave);
    $ids = static fn(array $linhas): array => array_map('intval', array_column($linhas, 'id'));

    $observar = static function () use ($recrut, $nomes, $ids, $svExc, $svOk, $empresaId, $cargoUsoId): array {
        $o = [];
        $o['col_total'] = Colaborador::paginateAdmin(['q' => 'ZZEXF'], 1, 100)['total'];
        $o['col_summary_total'] = Colaborador::summary()['total'];
        $o['col_countAll'] = Colaborador::countAll();
        $o['sv_form_colaboradores'] = $nomes(SolicitacaoVaga::formDependencies(1)['colaboradores'], 'nome');
        $o['sv_form_gestores'] = $nomes(SolicitacaoVaga::formDependencies(1)['gestores'], 'colaborador_nome');
        $mpForm = MovimentacaoPessoal::formDependencies(1);
        $o['mp_form_colaboradores'] = $nomes($mpForm['colaboradores'], 'nome');
        $o['mp_form_gestores'] = $nomes($mpForm['gestores'], 'colaborador_nome');
        $o['sv_lista'] = $ids(SolicitacaoVaga::allForUser(1, 'admin', true));
        $o['sv_kanban'] = $ids(SolicitacaoVaga::allForKanban(1, 'admin', true));
        $o['mp_lista'] = $ids(MovimentacaoPessoal::listForUser(1, 'admin', true));
        $o['lideres'] = array_column((new UsuarioColaboradorRepository())->colaboradoresComAcessoAtivo(), 'nome');
        $o['avleg_lista'] = array_column(AvaliacaoDesempenho::all(['q' => 'ZZEXF']), 'titulo');
        $o['avleg_opcoes'] = array_column(AvaliacaoDesempenho::colaboradorOptions(), 'nome');
        $o['recrut_fechadas'] = (int)($recrut->contarSolicitacoesFechadas(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'), null)['total'] ?? 0);
        $o['org_empresa_pessoas'] = CadastroOrganizacional::usageCount('empresas', $empresaId);
        $o['org_cargo_colaboradores'] = CadastroOrganizacional::usageCount('cargos', $cargoUsoId);
        return $o;
    };

    // ------------------------------------------------------------ ANTES
    $antes = $observar();
    $check(in_array('ZZEXF Local Excluido', $antes['sv_form_colaboradores'], true) && in_array($svExc, $antes['sv_lista'], true)
        && in_array($mpExc, $antes['mp_lista'], true) && in_array('ZZEXF Avaliacao Excluido', $antes['avleg_lista'], true),
        '(controle) Antes de excluir, o colaborador local e seus processos aparecem em seletores e listas');

    $S::excluirPorVinculo($fx->emp, $fx->uni, '1', 'ZZEXF teste cadastro local', 1);
    $depois = $observar();

    // ------------------------------------------------------------ cadastro local: listas, seletores, contagens
    $check($depois['col_total'] === $antes['col_total'] - 1 && $depois['col_summary_total'] === $antes['col_summary_total'] - 1 && $depois['col_countAll'] === $antes['col_countAll'] - 1,
        'Cadastro local: listagem, resumo e contagem total excluem o colaborador ligado ao contrato excluído (−1)');
    $check(count(array_keys($depois['sv_form_colaboradores'], 'ZZEXF Local Excluido', true)) === 1 && count(array_keys($antes['sv_form_colaboradores'], 'ZZEXF Local Excluido', true)) === 2,
        'Solicitação de Vaga: o seletor de colaboradores perde o ligado ao excluído e MANTÉM o homônimo sem contrato (nada por nome)');
    $check(!in_array('ZZEXF Local Excluido', $depois['mp_form_colaboradores'], true) || count(array_keys($depois['mp_form_colaboradores'], 'ZZEXF Local Excluido', true)) === 1,
        'Movimentação de Pessoal: seletor de colaboradores sem o ligado ao excluído');
    $check(count(array_keys($depois['lideres'], 'ZZEXF Local Excluido', true)) === count(array_keys($antes['lideres'], 'ZZEXF Local Excluido', true)) - 1,
        'Seletor de líder imediato (usuários com acesso) perde o colaborador ligado ao excluído');
    $check(in_array('ZZEXF Local Legitimo', $depois['sv_form_colaboradores'], true) && in_array('ZZEXF Local Sem Contrato', $depois['sv_form_colaboradores'], true)
        && in_array('ZZEXF Avaliacao Legitimo', $depois['avleg_lista'], true), 'Colaborador legítimo e colaborador sem contrato permanecem nos seletores e listas');
    $check(!in_array('ZZEXF Local Excluido', array_diff($depois['avleg_opcoes'], ['ZZEXF Local Excluido']), true)
        && count(array_keys($depois['avleg_opcoes'], 'ZZEXF Local Excluido', true)) === count(array_keys($antes['avleg_opcoes'], 'ZZEXF Local Excluido', true)) - 1,
        'Avaliação (modelo legado): opções de colaborador sem o ligado ao excluído');

    $check($depois['org_empresa_pessoas'] === $antes['org_empresa_pessoas'] - 1 && $antes['org_empresa_pessoas'] === 2,
        'Cadastro Organizacional: pessoas ativas da Empresa (contratos do METADADOS) caem de 2 para 1 ao excluir o contrato');
    $check($depois['org_cargo_colaboradores'] === $antes['org_cargo_colaboradores'] - 1,
        'Cadastro Organizacional: colaboradores locais vinculados ao Cargo excluem o ligado ao contrato excluído (−1)');

    // ------------------------------------------------------------ processos: fora de listas e estatísticas
    $check(!in_array($svExc, $depois['sv_lista'], true) && !in_array($svExc, $depois['sv_kanban'], true) && in_array($svOk, $depois['sv_lista'], true) && in_array($svOk, $depois['sv_kanban'], true),
        'Solicitação de Vaga ligada ao colaborador do contrato excluído sai da lista e do Kanban; a legítima permanece');
    $check(!in_array($mpExc, $depois['mp_lista'], true) && in_array($mpOk, $depois['mp_lista'], true), 'Movimentação de Pessoal do colaborador excluído sai da lista; a legítima permanece');
    $check(!in_array('ZZEXF Avaliacao Excluido', $depois['avleg_lista'], true), 'Avaliação legada do colaborador excluído sai da lista');
    if ($stageFechada > 0) {
        $check($depois['recrut_fechadas'] === $antes['recrut_fechadas'] - 1, 'Estatística de recrutamento (vagas fechadas no período) exclui a vaga ligada ao colaborador excluído (−1)');
    }

    // ------------------------------------------------------------ processos PRESERVADOS (não apagados, estado intacto)
    $check($estadoProcessos() === $processosAntes, 'Processos existentes preservados: nenhum apagado e nenhum estado alterado');
    $check((int)$pdo->query("SELECT COUNT(*) FROM colaboradores WHERE id = {$colExc}")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM colaborador_avaliacoes WHERE id = {$avExc}")->fetchColumn() === 1,
        'Colaborador local e avaliação legada do excluído continuam fisicamente no banco (rastreabilidade)');

    // ------------------------------------------------------------ acesso individual por perfil
    $como('admin', 'GET');
    $check(Colaborador::find($colExc) !== null && $S::avisoAuditoria() !== null, 'Admin (GET) consulta o colaborador local excluído, com aviso de auditoria');
    $check(SolicitacaoVaga::findAccessible($svExc, 1, 'admin', true) !== null, 'Admin (GET) consulta a solicitação de vaga ligada ao excluído (auditoria)');
    $check(MovimentacaoPessoal::findAccessible($mpExc, 1, 'admin', true) !== null, 'Admin (GET) consulta a movimentação do colaborador excluído (auditoria)');
    $check(AvaliacaoDesempenho::find($avExc) !== null, 'Admin (GET) consulta a avaliação legada do excluído (auditoria)');
    foreach (['rh', 'viewer', null] as $role) {
        $rotulo = $role ?? 'visitante';
        $como($role, 'GET');
        $check(Colaborador::find($colExc) === null && SolicitacaoVaga::findAccessible($svExc, 1, 'rh', true) === null
            && MovimentacaoPessoal::findAccessible($mpExc, 1, 'rh', true) === null && AvaliacaoDesempenho::find($avExc) === null,
            "{$rotulo} (GET): colaborador local e processos do excluído → não encontrado (404)");
    }
    $como('admin', 'POST');
    $check(Colaborador::find($colExc) === null && SolicitacaoVaga::findAccessible($svExc, 1, 'admin', true) === null, 'Admin (POST/escrita): negado — a consulta de auditoria é só leitura');
    $como('rh', 'GET');
    $check(Colaborador::find($colOk) !== null && Colaborador::find($colSem) !== null && SolicitacaoVaga::findAccessible($svOk, 1, 'rh', true) !== null,
        'RH continua acessando colaborador/processos legítimos e colaborador sem contrato');

    // ------------------------------------------------------------ NOVOS processos: negados a qualquer perfil
    $como('admin', 'GET');
    $reqEntity = new ReflectionMethod(SolicitacaoVaga::class, 'requiredEntityId');
    $reqEntity->setAccessible(true);
    $barrou = false;
    try {
        $reqEntity->invoke(null, $colExc, 'colaboradores', 'Colaborador substituído');
    } catch (InvalidArgumentException $e) {
        $barrou = true;
    }
    $check($barrou, 'Nova Solicitação de Vaga: colaborador ligado a contrato excluído é rejeitado como inválido (mesmo para Admin)');
    $check($reqEntity->invoke(null, $colOk, 'colaboradores', 'Colaborador substituído') === $colOk && $reqEntity->invoke(null, $colMesmoNome, 'colaboradores', 'Colaborador') === $colMesmoNome,
        'Nova Solicitação de Vaga: colaborador legítimo e homônimo sem contrato são aceitos');
    $como('admin', 'POST');
    $findCol = new ReflectionMethod(MovimentacaoPessoal::class, 'findColaborador');
    $findCol->setAccessible(true);
    $check($findCol->invoke(null, $colExc) === null && $findCol->invoke(null, $colOk) !== null, 'Nova Movimentação de Pessoal: colaborador ligado ao excluído não é carregado; o legítimo sim');

    // ------------------------------------------------------------ ações sobre processos existentes (Kanban, publicação, carta): negadas
    $como('admin', 'POST');
    $fn = static function (object|string $alvo, string $metodo): ReflectionMethod {
        $m = new ReflectionMethod($alvo, $metodo);
        $m->setAccessible(true);
        return $m;
    };
    $pipeline = new SolicitacaoVagaPipelineService();
    $check($fn($pipeline, 'fetchRow')->invoke($pipeline, $svExc) === null && $fn($pipeline, 'fetchRow')->invoke($pipeline, $svOk) !== null,
        'Kanban: o processo ligado ao excluído não é carregado para mover de etapa (nem por Admin); o legítimo é');
    $check($fn(SolicitacaoVaga::class, 'findRaw')->invoke(null, $svExc) === null && $fn(SolicitacaoVaga::class, 'findRaw')->invoke(null, $svOk) !== null,
        'Solicitação de Vaga: ações por id (aprovar, editar, RH…) sobre processo do excluído são negadas; o legítimo segue');
    $check($fn(MovimentacaoPessoal::class, 'findRaw')->invoke(null, $mpExc) === null && $fn(MovimentacaoPessoal::class, 'findRaw')->invoke(null, $mpOk) !== null,
        'Movimentação de Pessoal: ações por id (assinar, editar…) sobre processo do excluído são negadas; o legítimo segue');
    $publicacao = new SolicitacaoVagaPublicacaoService($pdo);
    $check($fn($publicacao, 'carregarSolicitacao')->invoke($publicacao, $svExc) === null && $fn($publicacao, 'carregarSolicitacao')->invoke($publicacao, $svOk) !== null,
        'Publicação de vaga: solicitação do excluído não é carregada para publicar; a legítima é');

    // ------------------------------------------------------------ reversão
    $S::incluirPorVinculo($fx->emp, $fx->uni, '1');
    $como('rh', 'GET');
    $revertido = $observar();
    $check(in_array($svExc, $revertido['sv_lista'], true) && in_array($mpExc, $revertido['mp_lista'], true) && Colaborador::find($colExc) !== null
        && $revertido['col_total'] === $antes['col_total'], 'Reversão administrativa: tudo volta a aparecer e a ser acessível');

    echo "\nEXCLUSAO_ANALITICA_CADASTRO_LOCAL_OK\n";
} finally {
    $_SESSION = $sessaoOriginal;
    if ($metodoOriginal === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $metodoOriginal;
    }
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_CADASTRO_LOCAL_OK\n";
