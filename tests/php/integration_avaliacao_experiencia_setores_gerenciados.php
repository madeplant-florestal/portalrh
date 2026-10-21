<?php

/**
 * Integração — Avaliação de Experiência: escopo de gestor por SETOR GERENCIADO (correção 2026-10,
 * caso real: Diego de Moura, Supervisor de Operação, perfil Viewer, com `avaliacao_experiencia.
 * visualizar` marcado mas sem nenhum colaborador vinculado por `usuarios.gestor_usuario_id` — a
 * hierarquia 1:1 por usuário nunca foi o modelo operacional correto aqui, porque colaboradores
 * operacionais (motorista, operador, auxiliar) nunca são cadastrados como usuário do Portal).
 *
 * Nova regra: `UsuarioSetoresGerenciadosService` (Bloco 7, 2026-10, "usuario_setores_gerenciados" —
 * já existia para o filtro por Gestor do Dashboard de Integração/People Analytics, agora também usado
 * aqui) resolve os Setores Gerenciados do ator; `AvaliacaoExperienciaRepository::candidatosPendencia()`
 * filtra `colaboradores_metadados.codigo_setor` diretamente — nunca mais `usuarios.gestor_usuario_id`.
 *
 * Cobre especificamente os 12 cenários pedidos na correção: Admin, RH, gestor com 1 setor, gestor com
 * 2 setores, dois gestores no mesmo setor, colaborador SEM usuário própria (o caso central), mudança
 * de setor do colaborador, acesso direto por URL fora do escopo, sem permissão nenhuma, sem permissão
 * de avaliar, e deduplicação de setores.
 *
 * Complementa (não substitui) `integration_avaliacao_experiencia.php`, que já cobre a máquina de
 * estados (derivação de status, salvar/concluir/ciência/reabrir) e foi adaptada à mesma correção.
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

$suffix = (string)time() . (string)random_int(100, 999);
$criados = ['usuarios' => [], 'metadados' => [], 'setores' => []];
$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$hoje = new DateTimeImmutable('2026-06-15');
$dia = static fn(int $offset): string => $hoje->modify($offset . ' days')->format('Y-m-d');

$criarSetor = static function (string $rotulo) use ($pdo, &$criados, $suffix): array {
    static $seq = 0;
    $seq++;
    // codigo_setor é VARCHAR(8) — precisa caber.
    $codigo = 'Z' . substr($suffix, -6) . $seq;
    $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, ?)')
        ->execute([$codigo, 'ZZAESG ' . $rotulo . ' ' . $suffix, 'zzaesg-' . strtolower($rotulo) . '-' . strtolower($suffix), 'RHMADEPLANT']);
    $id = (int)$pdo->lastInsertId();
    $criados['setores'][] = $id;
    return ['id' => $id, 'codigo' => $codigo];
};

$criarContrato = static function (string $nome, string $codigoSetor, ?string $demissao = null) use ($pdo, &$criados, $suffix, $hoje): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZAESG' . $suffix . $seq;
    $admissao = $hoje->modify('-75 days')->format('Y-m-d');
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            codigo_setor, setor, cargo, codigo_cargo, admissao, data_inicio_cargo, demissao, motivo_rescisao_codigo, ativo, origem_metadados, ausente_na_origem
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, 'EMPA', 'UNI', 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, null, $nome, 'ZZAESG Empresa', 'ZZAESG Unidade',
        $codigoSetor, 'ZZAESG Setor', 'ZZAESG Cargo', 'CG1', $admissao, $admissao, $demissao, $demissao !== null ? '003' : null,
        $demissao === null ? 1 : 0, 'zzaesg-teste', 0,
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

$criarUsuario = static function (string $rotulo, string $role) use ($pdo, &$criados, $suffix, $senha): int {
    $id = User::create('ZZAESG ' . $rotulo, strtolower(str_replace(' ', '.', $rotulo)) . '.zzaesg.' . $suffix . '@teste.local', $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    $permVis = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.visualizar'")->fetchColumn();
    $permAva = (int)$pdo->query("SELECT id FROM permissoes WHERE codigo = 'avaliacao_experiencia.avaliar'")->fetchColumn();
    $check($permVis > 0 && $permAva > 0, '(0) Permissões avaliacao_experiencia.visualizar/avaliar existem no catálogo');

    // ---- fixtures: 2 setores oficiais, nenhum colaborador terá usuario próprio -------------------------
    $setorX = $criarSetor('SETOR X');
    $setorY = $criarSetor('SETOR Y');

    $cX1 = $criarContrato('ZZAESG Colaborador X1', $setorX['codigo']);
    $cX2Desligado = $criarContrato('ZZAESG Colaborador X2 Desligado', $setorX['codigo'], $dia(-10));
    $cY1 = $criarContrato('ZZAESG Colaborador Y1', $setorY['codigo']);

    $setoresSvc = new UsuarioSetoresGerenciadosService($pdo);

    $adminId = $criarUsuario('Admin', 'admin');
    $rhId = $criarUsuario('RH', 'rh');
    $gestorXId = $criarUsuario('Gestor X', 'viewer');
    $gestorXYId = $criarUsuario('Gestor XY', 'viewer');
    $gestorOutroXId = $criarUsuario('Gestor Outro X', 'viewer'); // (5) segundo gestor do MESMO Setor X
    $gestorSemSetorId = $criarUsuario('Gestor Sem Setor', 'viewer');
    $semPermissaoId = $criarUsuario('Sem Permissao', 'viewer'); // tem Setor X, mas nenhuma permissão
    $soVisualizaId = $criarUsuario('So Visualiza', 'viewer'); // tem Setor X + visualizar, sem avaliar

    Authorization::sincronizar($adminId, [$permVis, $permAva]);
    Authorization::sincronizar($rhId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorXId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorXYId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorOutroXId, [$permVis, $permAva]);
    Authorization::sincronizar($gestorSemSetorId, [$permVis, $permAva]);
    Authorization::sincronizar($semPermissaoId, []); // (9) propositalmente SEM nenhuma permissão
    Authorization::sincronizar($soVisualizaId, [$permVis]); // (10) só visualizar

    $setoresSvc->definirSetoresGerenciados($gestorXId, [$setorX['id']]);
    $setoresSvc->definirSetoresGerenciados($gestorXYId, [$setorX['id'], $setorY['id']]);
    $setoresSvc->definirSetoresGerenciados($gestorOutroXId, [$setorX['id']]);
    // $gestorSemSetorId: propositalmente sem nenhum setor gerenciado.
    $setoresSvc->definirSetoresGerenciados($semPermissaoId, [$setorX['id']]);
    $setoresSvc->definirSetoresGerenciados($soVisualizaId, [$setorX['id']]);

    $atorAdmin = ['id' => $adminId, 'role' => 'admin'];
    $atorRh = ['id' => $rhId, 'role' => 'rh'];
    $atorGestorX = ['id' => $gestorXId, 'role' => 'viewer'];
    $atorGestorXY = ['id' => $gestorXYId, 'role' => 'viewer'];
    $atorGestorOutroX = ['id' => $gestorOutroXId, 'role' => 'viewer'];
    $atorGestorSemSetor = ['id' => $gestorSemSetorId, 'role' => 'viewer'];
    $atorSemPermissao = ['id' => $semPermissaoId, 'role' => 'viewer'];
    $atorSoVisualiza = ['id' => $soVisualizaId, 'role' => 'viewer'];

    $svc = new AvaliacaoExperienciaService(new AvaliacaoExperienciaRepository($pdo), new AvaliacoesDesenvolvimentoAuditoriaService($pdo));

    // ---- Cenário 1 — Admin vê todos, independente de setor ----------------------------------------------
    $idsAdmin = array_column($svc->listarPendencias([], $atorAdmin, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX1, $idsAdmin, true) && in_array($cY1, $idsAdmin, true), '(1) Admin vê colaboradores do Setor X E do Setor Y — escopo total, sem filtro de setor');

    // ---- Cenário 2 — RH vê todos ------------------------------------------------------------------------
    $idsRh = array_column($svc->listarPendencias([], $atorRh, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX1, $idsRh, true) && in_array($cY1, $idsRh, true), '(2) RH vê colaboradores do Setor X E do Setor Y — escopo total');

    // ---- Cenário 3 — Gestor só com Setor X: vê X, não vê Y -----------------------------------------------
    $idsGestorX = array_column($svc->listarPendencias([], $atorGestorX, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX1, $idsGestorX, true), '(3) Gestor com Setor X gerenciado vê colaborador do Setor X');
    $check(!in_array($cY1, $idsGestorX, true), '(3) Gestor com Setor X gerenciado NÃO vê colaborador do Setor Y');

    // ---- Cenário 4 — Gestor com Setor X + Setor Y: vê os dois --------------------------------------------
    $idsGestorXY = array_column($svc->listarPendencias([], $atorGestorXY, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX1, $idsGestorXY, true) && in_array($cY1, $idsGestorXY, true), '(4) Gestor com Setor X + Setor Y gerenciados vê colaboradores dos dois setores');

    // ---- Cenário 5 — Dois gestores no mesmo Setor X: ambos veem (N gestores <-> N setores) ---------------
    $idsGestorOutroX = array_column($svc->listarPendencias([], $atorGestorOutroX, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX1, $idsGestorOutroX, true), '(5) Um SEGUNDO gestor do mesmo Setor X também vê o colaborador — não existe exclusividade 1 setor = 1 gestor');

    // ---- Cenário 6 — Colaborador SEM usuário própria no Portal aparece normalmente (O CASO CENTRAL) -----
    $temUsuarioProprio = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE colaborador_metadados_id = ' . (int)$cX1)->fetchColumn();
    $check($temUsuarioProprio === 0, '(6) Pré-condição: colaborador X1 NÃO tem nenhuma conta usuarios vinculada (colaborador_metadados_id)');
    $check(in_array($cX1, $idsGestorX, true), '(6) Mesmo sem conta própria em usuarios, o colaborador aparece normalmente para o gestor do seu Setor — não exige colaborador_metadados_id/gestor_usuario_id/vínculo prévio');

    // ---- Cenário 11 — Deduplicação: setor repetido não gera erro nem duplica o escopo -------------------
    $rDuplicado = $setoresSvc->definirSetoresGerenciados($gestorXId, [$setorX['id'], $setorX['id'], $setorX['id']]);
    $check($rDuplicado['ok'] ?? false, '(11) definirSetoresGerenciados() aceita ids duplicados sem erro');
    $check($setoresSvc->codigosSetorGerenciadosPor($gestorXId) === [$setorX['codigo']], '(11) Resultado final é único (1 código), mesmo enviando o mesmo setor 3x');
    // Restaura o escopo original do Gestor X para os cenários seguintes.
    $setoresSvc->definirSetoresGerenciados($gestorXId, [$setorX['id']]);

    // ---- Cenário 7 — Colaborador muda de setor: escopo segue o setor ATUAL, nunca um vínculo estático ---
    $cMovel = $criarContrato('ZZAESG Colaborador Movel', $setorX['codigo']);
    $idsGestorXAntes = array_column($svc->listarPendencias([], $atorGestorX, $hoje)['itens'], 'metadados_id');
    $idsGestorXYAntes = array_column($svc->listarPendencias([], $atorGestorXY, $hoje)['itens'], 'metadados_id');
    $check(in_array($cMovel, $idsGestorXAntes, true), '(7) Antes da mudança: colaborador no Setor X aparece para o Gestor X');

    $pdo->prepare('UPDATE colaboradores_metadados SET codigo_setor = ? WHERE id = ?')->execute([$setorY['codigo'], $cMovel]);

    $idsGestorXDepois = array_column($svc->listarPendencias([], $atorGestorX, $hoje)['itens'], 'metadados_id');
    $idsGestorXYDepois = array_column($svc->listarPendencias([], $atorGestorXY, $hoje)['itens'], 'metadados_id');
    $check(!in_array($cMovel, $idsGestorXDepois, true), '(7) Depois da mudança para o Setor Y: Gestor X (só gerencia X) deixa de ver o colaborador automaticamente');
    $check(in_array($cMovel, $idsGestorXYDepois, true), '(7) Gestor XY (gerencia X e Y) continua vendo o colaborador, agora "pelo lado" do Setor Y');

    // ---- Cenário 8 — Acesso direto por URL (contratoParaAvaliar) fora do escopo: NEGADO no backend ------
    $ctxForaDoEscopo = $svc->contratoParaAvaliar($cY1, '45', $atorGestorX);
    $check(($ctxForaDoEscopo['ok'] ?? true) === false, '(8) Gestor X tentando abrir diretamente (por id) uma avaliação de colaborador do Setor Y é NEGADO pelo backend — não é só a listagem que filtra');
    $ctxDentroDoEscopo = $svc->contratoParaAvaliar($cX1, '45', $atorGestorX);
    $check(($ctxDentroDoEscopo['ok'] ?? false) === true, '(8b) Gestor X consegue abrir normalmente um colaborador do seu próprio Setor X');

    $ctxSemSetorNenhum = $svc->contratoParaAvaliar($cX1, '45', $atorGestorSemSetor);
    $check(($ctxSemSetorNenhum['ok'] ?? true) === false, '(8c) Gestor sem NENHUM Setor Gerenciado é negado para qualquer colaborador, mesmo com a permissão');

    // ---- Cenário 9 — Sem NENHUMA permissão: o controller (não o service) bloqueia o módulo inteiro ------
    $check(!Authorization::usuarioTemPermissao($semPermissaoId, 'avaliacao_experiencia.visualizar'), '(9) Usuário sem avaliacao_experiencia.visualizar de fato não tem a permissão, mesmo tendo o Setor X gerenciado');
    $corpoIndex = (static function (): string {
        $ref = new ReflectionMethod('AdminAvaliacoesExperienciaController', 'index');
        $arquivo = new SplFileObject($ref->getFileName());
        $arquivo->seek($ref->getStartLine() - 1);
        $linhas = '';
        for ($i = $ref->getStartLine(); $i <= $ref->getEndLine(); $i++) {
            $linhas .= $arquivo->current();
            $arquivo->next();
        }
        return $linhas;
    })();
    $check(str_contains($corpoIndex, "requirePermissao('avaliacao_experiencia.visualizar')"), "(9) AdminAvaliacoesExperienciaController::index() continua exigindo avaliacao_experiencia.visualizar — Setor Gerenciado NUNCA substitui a permissão");

    // ---- Cenário 10 — Tem visualizar, mas NÃO tem avaliar: consulta sim, preenche/conclui não -----------
    $ctxSoVisualiza = $svc->contratoParaAvaliar($cX1, '45', $atorSoVisualiza);
    $check(($ctxSoVisualiza['ok'] ?? false) === true, '(10) Usuário só com visualizar (+ Setor X) consegue ABRIR/consultar o contrato normalmente');
    $rSalvarSoVisualiza = $svc->salvarRascunho($cX1, '45', ['tecnica_capacidade' => 'sim'], $atorSoVisualiza, $hoje, null);
    $check(($rSalvarSoVisualiza['ok'] ?? true) === false, '(10) Usuário só com visualizar (sem avaliar) é BLOQUEADO ao tentar salvarRascunho() — permissão de ação é checada separadamente do escopo de setor');

    // ---- Cenário 12 — situação do colaborador: sem filtro novo por ativo/afastado/desligado ---------------
    $idsGestorXComDesligado = array_column($svc->listarPendencias([], $atorGestorX, $hoje)['itens'], 'metadados_id');
    $check(in_array($cX2Desligado, $idsGestorXComDesligado, true), '(12) Colaborador desligado do Setor X continua aparecendo para o Gestor X — nenhum filtro novo de situação foi introduzido (regra de elegibilidade 45/90 já existente decide, não o escopo de setor)');

    echo $falhas === [] ? "\nAVALIACAO_EXPERIENCIA_SETORES_GERENCIADOS_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $ids->execute($criados['metadados']);
        $metadadosIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($metadadosIds !== []) {
            $in = implode(',', $metadadosIds);
            $avIds = array_map('intval', $pdo->query("SELECT id FROM avaliacoes_experiencia WHERE metadados_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($avIds !== []) {
                $inAv = implode(',', $avIds);
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = 'avaliacao_experiencia' AND documento_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_experiencia_criterios WHERE avaliacao_id IN ($inAv)");
                $pdo->exec("DELETE FROM avaliacoes_experiencia WHERE id IN ($inAv)");
            }
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    foreach ($criados['setores'] as $setorId) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE setor_id = ?')->execute([$setorId]);
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
    }
}

if ($falhas !== []) {
    exit(1);
}
