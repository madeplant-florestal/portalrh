<?php

/**
 * Integração — acesso INDIVIDUAL (por id/token) a registros ligados a contrato excluído analiticamente.
 *
 * Regra: só ADMIN consulta, e só em leitura (GET/HEAD), como auditoria técnica sinalizada; RH, Gestor,
 * Viewer, visitante e qualquer escrita (inclusive de Admin) recebem "não encontrado" (em requisição web
 * a barreira responde 404 e encerra; em CLI devolve null/[] e marca ocultadoNaRequisicao()). Contratos
 * não excluídos — inclusive outro contrato da MESMA pessoa/CPF/nome — continuam acessíveis a todos.
 *
 * Carregadores cobertos: Avaliação de Experiência/Desempenho, Feedback, PDI, Entrevista de Desligamento
 * (inclusive o fluxo público por token) e Pesquisa de Integração (direta ou via colaborador local).
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

/** Simula perfil + método HTTP (CLI) e zera o estado por requisição. */
$como = static function (?string $role, string $metodo = 'GET') use ($S): void {
    $_SESSION = $role === null ? [] : ['user_role' => $role, 'user_id' => 1];
    $_SERVER['REQUEST_METHOD'] = $metodo;
    $S::reiniciarEstadoDaRequisicao();
};

try {
    // Contrato EXCLUÍDO e contrato LEGÍTIMO da MESMA pessoa/CPF/nome (a exclusão é só por chave natural).
    $cpf = '8' . substr($fx->suf, 0, 10);
    $idExc = $fx->contrato('1', 'PZX1', 'ZZEXF Mesmo Nome', ['cpf' => $cpf, 'nascimento' => '1990-01-01']);
    $idOk = $fx->contrato('2', 'PZX1', 'ZZEXF Mesmo Nome', ['cpf' => $cpf, 'nascimento' => '1990-01-01']);

    $reg = [];
    foreach (['exc' => $idExc, 'ok' => $idOk] as $k => $mid) {
        $reg[$k] = [
            'avexp' => $fx->avaliacaoExperiencia($mid, "ZZEXF {$k}"),
            'avdes' => $fx->avaliacaoDesempenho($mid, "ZZEXF {$k}"),
            'fb' => $fx->feedback($mid, "ZZEXF {$k}"),
            'pdi' => $fx->pdi($mid, "ZZEXF {$k}"),
            'ent' => $fx->entrevista($mid, "ZZEXF {$k}", "tok-ent-{$k}-{$fx->suf}"),
            'pesq' => $fx->pesquisa($mid, null, "tok-pesq-{$k}-{$fx->suf}"),
        ];
    }
    // Pesquisa antiga: só colaborador_id; o contrato vem de colaboradores.metadados_id.
    $colExc = $fx->colaboradorLocal('ZZEXF Local Excluido', $idExc);
    $colOk = $fx->colaboradorLocal('ZZEXF Local Legitimo', $idOk);
    $reg['exc']['pesq_col'] = $fx->pesquisa(null, $colExc, "tok-pcol-exc-{$fx->suf}");
    $reg['ok']['pesq_col'] = $fx->pesquisa(null, $colOk, "tok-pcol-ok-{$fx->suf}");

    $avExp = new AvaliacaoExperienciaRepository($pdo);
    $avDes = new AvaliacaoDesempenhoRepository($pdo);
    $fb = new FeedbackRepository($pdo);
    $pdiRepo = new PdiRepository($pdo);
    $ent = new EntrevistaDesligamentoRepository($pdo);

    /** Todos os carregadores individuais para um conjunto (exc|ok): nome => valor carregado (null/[] = oculto). */
    $carregar = static function (string $k, int $mid) use ($reg, $avExp, $avDes, $fb, $pdiRepo, $ent, $fx, $colExc, $colOk): array {
        $r = $reg[$k];
        return [
            'avexp.contrato' => $avExp->buscarContrato($mid),
            'avexp.avaliacao' => $avExp->buscarAvaliacao($mid, '45'),
            'avexp.porId' => $avExp->buscarPorId($r['avexp']),
            'avdes.contrato' => $avDes->buscarContrato($mid),
            'avdes.porId' => $avDes->buscarPorId($r['avdes']),
            'avdes.porContrato' => $avDes->buscarPorContrato($mid) ?: null,
            'fb.contrato' => $fb->buscarContrato($mid),
            'fb.porId' => $fb->buscarPorId($r['fb']),
            'pdi.contrato' => $pdiRepo->buscarContrato($mid),
            'pdi.porId' => $pdiRepo->buscarPdi($r['pdi']),
            'pdi.doContrato' => $pdiRepo->pdisDoContrato($mid, null) ?: null,
            'ent.contrato' => $ent->buscarContrato($mid),
            'ent.porId' => $ent->buscarPorId($r['ent']),
            'ent.porContrato' => $ent->buscarPorMetadadosId($mid),
            'ent.porToken' => $ent->buscarPorHash(hash('sha256', "tok-ent-{$k}-{$fx->suf}")),
            'pesq.porId' => PesquisaIntegracao::find($r['pesq']),
            'pesq.porToken' => PesquisaIntegracao::findByRawToken("tok-pesq-{$k}-{$fx->suf}"),
            'pesq.viaColaborador' => PesquisaIntegracao::findByColaboradorEData($k === 'exc' ? $colExc : $colOk, '2025-06-01'),
        ];
    };
    $visiveis = static fn(array $c): array => array_keys(array_filter($c, static fn($v) => $v !== null));
    $todos = array_keys($carregar('ok', $idOk));

    // ---------------------------------------------------------------- controle: ninguém perde acesso ao legítimo
    foreach ([['admin', 'GET'], ['rh', 'GET'], ['viewer', 'GET'], [null, 'GET'], ['admin', 'POST'], ['rh', 'POST']] as [$role, $metodo]) {
        $como($role, $metodo);
        $v = $visiveis($carregar('ok', $idOk));
        $check($v === $todos && !$S::ocultadoNaRequisicao() && $S::avisoAuditoria() === null,
            '(controle) Contrato legítimo (mesma pessoa/CPF/nome do excluído) segue 100% acessível — perfil ' . ($role ?? 'visitante') . ", {$metodo}");
    }

    // ---------------------------------------------------------------- excluir pela chave natural
    $S::excluirPorVinculo($fx->emp, $fx->uni, '1', 'ZZEXF teste acesso individual', 1);

    // ---------------------------------------------------------------- ADMIN + leitura: auditoria técnica, sinalizada
    $como('admin', 'GET');
    $v = $visiveis($carregar('exc', $idExc));
    $check($v === $todos, 'Admin (GET) consulta TODOS os registros individuais do contrato excluído (auditoria técnica)');
    $check($S::avisoAuditoria() !== null && str_contains((string)$S::avisoAuditoria(), 'EXCLUÍDO ANALITICAMENTE'), 'Admin (GET): o registro é sinalizado como excluído analiticamente (aviso no shell)');
    $como('admin', 'HEAD');
    $check($visiveis($carregar('exc', $idExc)) === $todos, 'Admin (HEAD) também consulta (leitura)');

    // ---------------------------------------------------------------- Admin + escrita: bloqueado
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $metodo) {
        $como('admin', $metodo);
        $v = $visiveis($carregar('exc', $idExc));
        $check($v === [] && $S::ocultadoNaRequisicao() && $S::avisoAuditoria() === null, "Admin ({$metodo}): escrita sobre contrato excluído é negada (a consulta é só leitura)");
    }

    // ---------------------------------------------------------------- RH / Gestor / Viewer / visitante: 404 em tudo
    foreach (['rh' => 'RH', 'viewer' => 'Viewer', 'gestor' => 'Gestor (setor gerenciado)', 'user' => 'usuário comum', null => 'visitante sem sessão'] as $role => $rotulo) {
        $role = $role === '' ? null : $role;
        foreach (['GET', 'POST'] as $metodo) {
            $como($role === 'null' ? null : $role, $metodo);
            $v = $visiveis($carregar('exc', $idExc));
            $check($v === [] && $S::ocultadoNaRequisicao() && $S::avisoAuditoria() === null,
                "{$rotulo} ({$metodo}): NENHUM registro individual do contrato excluído é entregue (404)");
        }
    }

    // ---------------------------------------------------------------- legítimo continua acessível DEPOIS de excluir o irmão
    foreach (['admin', 'rh', 'viewer', null] as $role) {
        $como($role, 'GET');
        $check($visiveis($carregar('ok', $idOk)) === $todos, 'Depois da exclusão: o outro contrato da mesma pessoa/CPF/nome continua acessível — ' . ($role ?? 'visitante'));
    }
    $check(!$S::estaExcluidoPorVinculo($fx->emp, $fx->uni, '2'), 'A exclusão é só pela chave natural: nada excluído por CPF, nome ou pessoa');

    // ---------------------------------------------------------------- o acesso de auditoria NÃO vira origem de cálculo
    $como('admin', 'GET');
    $consulta = new ColaboradorMetadadosConsultaRepository($pdo);
    $check($consulta->paginate(['q' => 'ZZEXF Mesmo Nome'], 1, 50)['total'] === 1, 'Mesmo para Admin, a LISTAGEM continua sem o contrato excluído (a regra prevalece)');
    $pa = new PeopleAnalyticsRepository($pdo);
    $check(!in_array("{$fx->emp}-{$fx->uni}-1", array_column($pa->buscarContratos(), 'identificador'), true), 'Mesmo para Admin, os indicadores continuam sem o contrato excluído');

    // ---------------------------------------------------------------- reversão: tudo volta a ser acessível para todos
    $S::incluirPorVinculo($fx->emp, $fx->uni, '1');
    foreach (['rh', 'viewer', null] as $role) {
        $como($role, 'GET');
        $check($visiveis($carregar('exc', $idExc)) === $todos && $S::avisoAuditoria() === null, 'Após reverter a exclusão, ' . ($role ?? 'visitante') . ' volta a acessar normalmente');
    }

    echo "\nEXCLUSAO_ANALITICA_ACESSO_INDIVIDUAL_OK\n";
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
echo "\nEXCLUSAO_ANALITICA_ACESSO_INDIVIDUAL_OK\n";
