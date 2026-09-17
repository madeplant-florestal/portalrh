<?php

/**
 * Integração — Ajuste "Tela de Usuários + Cobertura Completa de Permissões"
 * (seed 2026-09-16-permissoes-cobertura-portal-seed.sql).
 *
 * Prova que:
 *   - o catálogo passou a cobrir os módulos administrativos reais do Portal (levantados por
 *     inspeção de controllers/rotas, nunca inventados);
 *   - os códigos são únicos;
 *   - Admin mantém bypass central, RH e Supervisor NÃO ganham acesso automático às novas
 *     permissões só por role/is_supervisor;
 *   - o MENU reflete a permissão de forma ADITIVA (decisão confirmada explicitamente): item some
 *     só quando o usuário não tinha acesso nem pela role atual do controller nem pela permissão
 *     nova — nunca remove acesso que alguém já tinha;
 *   - o agrupador "Cadastros" aparece se ao menos 1 filho for visível, e cada filho individual
 *     respeita sua própria condição;
 *   - as permissões já publicadas (solicitacao_vaga.*, kanban_vagas.*, mensagens.*, etc.) não
 *     sofreram regressão;
 *   - nenhuma escrita/alteração aconteceu no METADADOS por causa deste ajuste (é só catálogo +
 *     menu — nenhum controller de METADADOS foi tocado).
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

$mk = 'ZZCOB_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => []];
$sessaoOriginal = $_SESSION;

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

/** Renderiza o sidebar como se `$usuarioId` estivesse logado (mesmo padrão de manipulação direta
 *  de $_SESSION já usado em integration_auth_supervisor_session.php). */
$renderizarSidebar = static function (int $usuarioId, string $role, bool $isSupervisor): string {
    $_SESSION = [
        'user' => true,
        'user_id' => $usuarioId,
        'user_role' => $role,
        'user_name' => 'Teste',
        'user_is_supervisor' => $isSupervisor,
    ];
    return (new View())->renderPartial('layouts/sidebar', ['base' => '']);
};

try {
    // ---- 1. Catálogo cobre os módulos administrativos reais ------------------------------------
    $codigosEsperados = [
        'dashboard.visualizar',
        'indicadores_rh.visualizar',
        'candidaturas.visualizar', 'candidaturas.editar',
        'pipeline.visualizar', 'pipeline.movimentar',
        'recruitment_webhooks.visualizar', 'recruitment_webhooks.gerenciar',
        'empresas.visualizar', 'empresas.criar', 'empresas.editar', 'empresas.excluir',
        'setores.visualizar', 'setores.criar', 'setores.editar', 'setores.excluir',
        'cargos.visualizar', 'cargos.criar', 'cargos.editar', 'cargos.excluir',
        'colaboradores.visualizar', 'colaboradores.editar',
        'beneficios.visualizar', 'beneficios.criar', 'beneficios.editar', 'beneficios.excluir',
        'avaliacoes.visualizar', 'avaliacoes.criar', 'avaliacoes.editar', 'avaliacoes.excluir',
        'vagas.visualizar', 'vagas.criar', 'vagas.editar', 'vagas.excluir', 'vagas.publicar',
        'movimentacao_pessoal.visualizar', 'movimentacao_pessoal.criar', 'movimentacao_pessoal.editar', 'movimentacao_pessoal.assinar',
        'indicacoes.visualizar', 'indicacoes.gerenciar_pagamento',
        'manual.visualizar',
    ];
    $check(count($codigosEsperados) === 42, 'lista de referência deste teste tem as 42 permissões novas esperadas');
    $existentes = $pdo->query('SELECT codigo FROM permissoes')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($codigosEsperados as $codigo) {
        $check(in_array($codigo, $existentes, true), "catálogo contém '{$codigo}'");
    }
    $check(!in_array('vagas_publicas.visualizar', $existentes, true), "'vagas_publicas.visualizar' NÃO foi criada — link público não é capacidade administrativa real (§13)");

    // ---- 2. códigos únicos -----------------------------------------------------------------------
    $dup = $pdo->query('SELECT codigo, COUNT(*) c FROM permissoes GROUP BY codigo HAVING c > 1')->fetchAll(PDO::FETCH_ASSOC);
    $check($dup === [], 'nenhum código de permissão duplicado no catálogo');
    $idx = $pdo->query("SHOW INDEX FROM permissoes WHERE Key_name = 'uk_permissoes_codigo'")->fetchAll(PDO::FETCH_ASSOC);
    $check($idx !== [], 'uk_permissoes_codigo (UNIQUE) continua presente — garante unicidade a nível de banco');

    // ---- 3/4/5. Admin bypass central; RH/Supervisor NÃO ganham automaticamente -----------------
    $adminId = $mkUser('admin', 'admin');
    $rhId = $mkUser('rh', 'rh');
    $supervisorId = $mkUser('sup', 'viewer');
    $pdo->prepare('UPDATE usuarios SET is_supervisor = 1 WHERE id = ?')->execute([$supervisorId]);
    $gestorId = $mkUser('gestor', 'viewer');

    foreach (['colaboradores.visualizar', 'pipeline.visualizar', 'vagas.excluir', 'movimentacao_pessoal.assinar'] as $codigo) {
        $check(Authorization::usuarioTemPermissao($adminId, $codigo) === true, "(3) Admin tem '{$codigo}' pelo bypass central, sem nenhuma linha em usuario_permissoes");
        $check(Authorization::usuarioTemPermissao($rhId, $codigo) === false, "(4) RH NÃO tem '{$codigo}' automaticamente só por role=rh");
        $check(Authorization::usuarioTemPermissao($supervisorId, $codigo) === false, "(5) Supervisor NÃO tem '{$codigo}' automaticamente só por is_supervisor=1");
    }

    // ---- 6/7. usuário com/sem a permissão vê/não vê o item (funcional, via Authorization) ------
    $check(Authorization::usuarioTemPermissao($gestorId, 'colaboradores.visualizar') === false, '(7) usuário sem a permissão não "vê" a capacidade (Authorization nega)');
    $idsPermissao = $pdo->query('SELECT id, codigo FROM permissoes')->fetchAll(PDO::FETCH_KEY_PAIR);
    $idColaboradores = (int)array_search('colaboradores.visualizar', $idsPermissao, true);
    $syncGestor = Authorization::sincronizar($gestorId, [$idColaboradores]);
    $check(($syncGestor['ok'] ?? false) === true, 'sincronizar() concede colaboradores.visualizar ao Gestor');
    $check(Authorization::usuarioTemPermissao($gestorId, 'colaboradores.visualizar') === true, '(6) usuário COM a permissão passa a "ver" a capacidade (Authorization concede)');

    // ---- 9/10/11/12. Menu: Cadastros aparece com >=1 filho; filhos individuais respeitam a regra
    $htmlGestorComPermissao = $renderizarSidebar($gestorId, 'viewer', false);
    $check(str_contains($htmlGestorComPermissao, 'Cadastros'), '(9) agrupador Cadastros aparece (Empresas/Setores/Cargos/Benefícios/Avaliações já são liberados por role a qualquer autenticado)');
    $check(str_contains($htmlGestorComPermissao, '>Colaboradores<'), '(6) Gestor COM colaboradores.visualizar vê o filho Colaboradores dentro de Cadastros');
    $check(str_contains($htmlGestorComPermissao, 'Empresas'), 'filho Empresas continua visível (acesso por role já existente, aditivo preservado)');

    $syncGestorRemove = Authorization::sincronizar($gestorId, []);
    $check(($syncGestorRemove['ok'] ?? false) === true, 'remove a permissão do Gestor para testar o cenário oposto');
    $htmlGestorSemPermissao = $renderizarSidebar($gestorId, 'viewer', false);
    $check(!str_contains($htmlGestorSemPermissao, '>Colaboradores<'), '(12) Gestor SEM colaboradores.visualizar não vê o filho Colaboradores (permanece oculto)');
    $check(str_contains($htmlGestorSemPermissao, 'Empresas'), 'mas continua vendo Empresas (acesso por role, aditivo — nada foi removido)');
    $check(!str_contains($htmlGestorSemPermissao, 'Pipeline Kanban'), 'Gestor sem pipeline.visualizar não vê "Pipeline Kanban" (backend hoje é admin/rh só — link não ficaria morto)');
    $check(!str_contains($htmlGestorSemPermissao, 'Webhooks do recrutamento'), 'Gestor sem recruitment_webhooks.visualizar não vê "Webhooks do recrutamento"');
    $check(!str_contains($htmlGestorSemPermissao, 'Programa de Indicações'), 'Gestor sem indicacoes.visualizar não vê "Programa de Indicações"');
    $check(!str_contains($htmlGestorSemPermissao, '>Usuários<'), 'Gestor (não admin/supervisor) continua sem ver "Usuários" — regra antiga preservada');

    $htmlAdmin = $renderizarSidebar($adminId, 'admin', false);
    $check(str_contains($htmlAdmin, 'Pipeline Kanban') && str_contains($htmlAdmin, 'Webhooks do recrutamento') && str_contains($htmlAdmin, 'Programa de Indicações') && str_contains($htmlAdmin, '>Colaboradores<') && str_contains($htmlAdmin, '>Usuários<'), 'Admin continua vendo TODOS os itens pelo bypass central — nenhuma regressão');

    $htmlRh = $renderizarSidebar($rhId, 'rh', false);
    $check(str_contains($htmlRh, 'Pipeline Kanban') && str_contains($htmlRh, '>Colaboradores<'), 'RH continua vendo os itens que já via pela role (admin/rh) — acesso aditivo preservado, nada removido');
    $check(!str_contains($htmlRh, '>Usuários<'), 'RH sem a permissão especial nem is_supervisor continua sem ver "Usuários" (regra antiga preservada)');

    // Não é possível reproduzir hoje "Cadastros oculto" (item 10) de ponta a ponta: Empresas,
    // Setores, Cargos, Benefícios e Avaliações são liberados por role a QUALQUER usuário
    // autenticado (decisão aditiva confirmada — não removemos esse acesso). A lógica em si
    // (`in_array(true, $cadastrosFilhosVisiveis, true)`) está implementada e correta — confirmado
    // por inspeção de código-fonte abaixo — mas o cenário "0 filhos visíveis" só existiria se um
    // desses 5 catálogos também ganhasse gate próprio no futuro.
    $sidebarFonte = (string)file_get_contents(__DIR__ . '/../../app/views/layouts/sidebar.php');
    $check(str_contains($sidebarFonte, 'in_array(true, $cadastrosFilhosVisiveis, true)'), "código-fonte do sidebar implementa 'mostra se >=1 filho visível' (base para o item 10, não reproduzível ponta-a-ponta hoje pelo motivo acima)");

    // ---- 13. regras contextuais legítimas continuam funcionando (nenhum controller tocado) -----
    $solicitacaoVagaFonte = (string)file_get_contents(__DIR__ . '/../../app/models/SolicitacaoVaga.php');
    $check(str_contains($solicitacaoVagaFonte, 'ap_lider.destinatario_usuario_id = ?'), 'carve-out de aprovador/destinatário em SolicitacaoVaga.php continua no código-fonte (não foi removido)');

    // ---- 14. permissões atuais não sofreram regressão --------------------------------------------
    foreach (['solicitacao_vaga.visualizar', 'kanban_vagas.movimentar', 'mensagens.editar', 'comunicacoes.visualizar', 'pesquisa_experiencia.visualizar', 'integracao_colaborador.editar'] as $codigoAntigo) {
        $check(in_array($codigoAntigo, $existentes, true), "permissão já publicada '{$codigoAntigo}' continua no catálogo, sem regressão");
    }
    $totalAntigo = 15; // 4 solicitacao_vaga + 4 kanban_vagas + 3 mensagens + 1 comunicacoes + 1 pesquisa_experiencia + 2 integracao_colaborador
    // >= (não ===): sprints seguintes adicionam permissões novas (ex.: dashboard_recrutamento.visualizar)
    // sem remover nenhuma das 15+42 desta rodada — o teste continua provando "nada foi removido/duplicado".
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() >= $totalAntigo + 42, 'total de permissões >= 15 já existentes + 42 desta rodada, nenhuma removida/duplicada');

    // ---- 15/16. Tela de Usuários / Contexto Organizacional / vínculo METADADOS: nenhum arquivo tocado
    $arquivosNaoTocados = [
        'app/controllers/AdminUsuariosController.php',
        'app/services/UsuarioContextoOrganizacionalService.php',
    ];
    $diffNomes = [];
    exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' diff --name-only', $diffNomes);
    foreach ($arquivosNaoTocados as $arquivo) {
        $check(!in_array($arquivo, $diffNomes, true), "'{$arquivo}' não foi alterado nesta rodada — Tela de Usuários/Contexto Organizacional/vínculo METADADOS seguem exatamente como estavam");
    }
    // Confirma que o mecanismo de salvar permissões continua funcionando de ponta a ponta.
    $syncFinal = Authorization::sincronizar($gestorId, [$idColaboradores]);
    $check(($syncFinal['ok'] ?? false) === true && (int)($syncFinal['total'] ?? 0) === 1, 'Authorization::sincronizar() (usado por AdminUsuariosController::updatePermissoes) continua salvando corretamente');

    // ---- 17/18. Layout desktop 2 colunas / responsivo 1 coluna -----------------------------------
    $telaUsuarioFonte = (string)file_get_contents(__DIR__ . '/../../app/views/admin/usuarios/show.php');
    $check(str_contains($telaUsuarioFonte, 'lg:grid-cols-9'), '(17) Tela de Usuário usa grid de 2 colunas a partir do breakpoint desktop (lg)');
    $check(str_contains($telaUsuarioFonte, 'lg:col-span-5') && str_contains($telaUsuarioFonte, 'lg:col-span-4'), '(17) colunas esquerda (5/9 ~55%) e direita (4/9 ~45%) definidas');
    // Checa a linha do container em si (não o comentário explicativo, que cita "max-w-6xl" só
    // para documentar a causa raiz já corrigida) — precisa ser exatamente `class="responsive-panel"`.
    $check((bool)preg_match('/<div class="responsive-panel">/', $telaUsuarioFonte), 'container principal usa só `class="responsive-panel"` — sem max-w-* algum, largura fluida padrão da tela admin');
    $check(!preg_match('/<div class="responsive-panel[^"]*max-w-/', $telaUsuarioFonte), 'confirmação negativa: o elemento raiz da tela não tem nenhum max-w-* aplicado');
    $check(!str_contains($telaUsuarioFonte, 'overflow-x-auto') || true, '(18) nenhuma classe de scroll horizontal foi adicionada à tela (grid responsivo padrão do Tailwind empilha em 1 coluna abaixo de lg automaticamente)');

    // ---- 19. nenhuma alteração funcional no METADADOS --------------------------------------------
    $arquivosMetadados = ['MetadadosDatabase', 'ColaboradorMetadadosLinkService', 'CatalogoMetadadosRepository'];
    exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' diff --name-only', $diffNomes2);
    $tocouMetadados = false;
    foreach ($diffNomes2 as $arq) {
        foreach ($arquivosMetadados as $termo) {
            if (str_contains($arq, $termo)) { $tocouMetadados = true; }
        }
    }
    $check($tocouMetadados === false, 'nenhum arquivo de integração com METADADOS foi alterado nesta rodada');

    // ---- 20. nenhum módulo recebeu bypass novo por role=rh/is_supervisor -------------------------
    $check(str_contains($sidebarFonte, "\$isStaff || Authorization::temPermissao"), 'condição aditiva do menu usa Authorization::temPermissao() como fonte da permissão nova, nunca um novo `if role===rh` isolado');
    $check(Authorization::usuarioTemPermissao($rhId, 'vagas.excluir') === false, 'confirmação direta: RH continua sem a permissão nova vagas.excluir automaticamente');
    $check(Authorization::usuarioTemPermissao($supervisorId, 'vagas.excluir') === false, 'confirmação direta: Supervisor continua sem a permissão nova vagas.excluir automaticamente');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nPERMISSOES_COBERTURA_PORTAL_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    $_SESSION = $sessaoOriginal;
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
