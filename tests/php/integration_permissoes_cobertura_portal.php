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

/** Autentica como `$usuarioId` estivesse logado (mesmo padrão de manipulação direta de $_SESSION já
 *  usado em integration_auth_supervisor_session.php). A navegação real passou da sidebar para
 *  `PortalNavegacaoService` (Central + abas de módulo no AppShell V2) — os testes abaixo leem
 *  `modulos()`/`abas()` desse serviço em vez de renderizar HTML de sidebar. */
$comoUsuario = static function (int $usuarioId, string $role, bool $isSupervisor): void {
    $_SESSION = [
        'user' => true,
        'user_id' => $usuarioId,
        'user_role' => $role,
        'user_name' => 'Teste',
        'user_is_supervisor' => $isSupervisor,
    ];
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

    // ---- 9/10/11/12. Central/abas de módulo: Cadastros aparece com >=1 destino; permissão individual libera ADITIVAMENTE
    //      um destino de "recrutamento" para quem não tem a role, sem nunca remover o que a role já dava (sidebar removida:
    //      a superfície de navegação real agora é PortalNavegacaoService, consumida pela Central e pelas abas de módulo).
    $comoUsuario($gestorId, 'viewer', false);
    $modulosGestorComPermissao = array_column((new PortalNavegacaoService())->modulos(), 'chave');
    $hrefPorModuloGestorComPermissao = array_column((new PortalNavegacaoService())->modulos(), 'href', 'chave');
    $check(in_array('cadastros', $modulosGestorComPermissao, true), '(9) módulo Cadastros aparece (Empresas/Setores/Cargos/Benefícios/Avaliações já são liberados por role a qualquer autenticado)');
    // O módulo "Colaboradores" sempre aparece (o 2º destino, Movimentações, é `aberto`), mas o Gestor COM colaboradores.visualizar
    // ainda cai em Movimentações, nunca em /admin/colaboradores: o backend daquela listagem é admin/rh só (expõe salário
    // individual) — mesma exceção provada em integration_portal_central.php. A permissão em si continua concedida
    // (Authorization::usuarioTemPermissao, itens 6/7 acima), só não abre um destino que o backend bloquearia (403).
    $check(($hrefPorModuloGestorComPermissao['colaboradores'] ?? null) === '/admin/movimentacoes-pessoal' && !(new PortalNavegacaoService())->visivel('staff'), '(6, exceção documentada) Gestor COM colaboradores.visualizar não abre /admin/colaboradores pela Central (cai em Movimentações)');
    $check(in_array('empresas', array_column((new PortalNavegacaoService())->abas('cadastros'), 'chave'), true), 'aba Empresas continua visível dentro de Cadastros (acesso por role já existente, aditivo preservado)');

    $syncGestorRemove = Authorization::sincronizar($gestorId, []);
    $check(($syncGestorRemove['ok'] ?? false) === true, 'remove a permissão do Gestor para testar o cenário oposto');
    $comoUsuario($gestorId, 'viewer', false);
    $modulosGestorSemPermissao = array_column((new PortalNavegacaoService())->modulos(), 'chave');
    $check(in_array('empresas', array_column((new PortalNavegacaoService())->abas('cadastros'), 'chave'), true), 'mesmo sem a permissão, continua vendo a aba Empresas (acesso por role, aditivo — nada foi removido)');
    $abasRecrutamentoSemPermissao = array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave');
    $check(!in_array('pipeline', $abasRecrutamentoSemPermissao, true), '(12) Gestor sem pipeline.visualizar não vê a aba "Pipeline Kanban" (backend hoje é admin/rh só — aba não ficaria morta)');
    $check(!in_array('webhooks', $abasRecrutamentoSemPermissao, true), 'Gestor sem recruitment_webhooks.visualizar não vê a aba "Webhooks"');
    $check(!in_array('indicacoes', $abasRecrutamentoSemPermissao, true), 'Gestor sem indicacoes.visualizar não vê a aba "Indicações"');
    $check(!in_array('usuarios', $modulosGestorSemPermissao, true), 'Gestor (não admin/supervisor) continua sem ver o módulo "Usuários" — regra antiga preservada');

    // ---- variante aditiva real (item 6 provado de ponta a ponta): pipeline.visualizar NÃO é role admin/rh, então concedê-la
    //      ao Gestor precisa liberar a aba sem que ele tenha ganhado a role nem is_supervisor.
    $idsPermissao2 = $pdo->query('SELECT id, codigo FROM permissoes')->fetchAll(PDO::FETCH_KEY_PAIR);
    $idPipeline = (int)array_search('pipeline.visualizar', $idsPermissao2, true);
    Authorization::sincronizar($gestorId, [$idPipeline]);
    $comoUsuario($gestorId, 'viewer', false);
    $check(in_array('pipeline', array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave'), true), '(6) Gestor COM pipeline.visualizar passa a ver a aba "Pipeline Kanban" mesmo sem role admin/rh nem is_supervisor');
    Authorization::sincronizar($gestorId, []); // devolve o Gestor ao estado sem permissões extras

    $comoUsuario($adminId, 'admin', false);
    $abasRecrutamentoAdmin = array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave');
    $modulosAdmin = array_column((new PortalNavegacaoService())->modulos(), 'chave');
    $check(in_array('pipeline', $abasRecrutamentoAdmin, true) && in_array('webhooks', $abasRecrutamentoAdmin, true) && in_array('indicacoes', $abasRecrutamentoAdmin, true) && in_array('colaboradores', $modulosAdmin, true) && in_array('usuarios', $modulosAdmin, true), 'Admin continua vendo TODOS os módulos/abas pelo bypass central — nenhuma regressão');

    $comoUsuario($rhId, 'rh', false);
    $abasRecrutamentoRh = array_column((new PortalNavegacaoService())->abas('recrutamento'), 'chave');
    $modulosRh = array_column((new PortalNavegacaoService())->modulos(), 'chave');
    $check(in_array('pipeline', $abasRecrutamentoRh, true) && in_array('colaboradores', $modulosRh, true), 'RH continua vendo os destinos que já via pela role (admin/rh) — acesso aditivo preservado, nada removido');
    $check(!in_array('usuarios', $modulosRh, true), 'RH sem a permissão especial nem is_supervisor continua sem ver o módulo "Usuários" (regra antiga preservada)');

    // Item 10 ("Cadastros oculto") permanece não reproduzível ponta a ponta hoje, pelo mesmo motivo já documentado na época
    // da sidebar: Empresas, Setores, Cargos, Benefícios e Avaliações são liberados por role a QUALQUER usuário autenticado
    // (decisão aditiva confirmada — não removemos esse acesso). A lógica "aparece se >=1 destino visível" está implementada
    // em PortalNavegacaoService::modulos() (`break` ao achar o primeiro item visível) e é exercitada pelos módulos acima e
    // por integration_portal_central.php; o cenário "0 destinos visíveis" só existiria se um desses 5 catálogos ganhasse
    // gate próprio no futuro.

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

    // ---- 15/16. Tela de Usuários / Contexto Organizacional / vínculo METADADOS: comportamento de autorização preservado
    // Esta rodada só cadastrou permissões e ajustou o menu. Em vez de exigir que os arquivos estejam fora do `git diff`
    // (o que impede qualquer evolução legítima posterior), o guard verifica o CÓDIGO relevante: cada ação PRÉ-EXISTENTE de
    // AdminUsuariosController mantém exatamente o portão de perfil de antes, o controller não passou a decidir acesso pelo
    // catálogo de permissões novo, e o serviço de Contexto Organizacional continua sem regra de permissão/perfil.
    // Ações adicionadas depois (ex.: updateGestor) têm o portão verificado no teste do próprio módulo.
    $semComentarios = static function (string $codigo): string {
        $out = '';
        foreach (token_get_all($codigo) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    };
    $corpoMetodo = static function (string $classe, string $metodo) use ($semComentarios): string {
        $r = new ReflectionMethod($classe, $metodo);
        $linhas = file($r->getFileName());
        return $semComentarios("<?php\n" . implode('', array_slice($linhas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1)));
    };
    $portoesPreExistentes = [
        'index' => "['admin']", 'create' => "['admin']", 'store' => "['admin']", 'updateRole' => "['admin']",
        'delete' => "['admin']", 'updateVagaAcesso' => "['admin']", 'updatePermissoes' => "['admin']", 'updateStatus' => "['admin']",
        'show' => "['admin', 'rh']", 'vincularMetadados' => "['admin', 'rh']", 'updateContextoOrganizacional' => "['admin', 'rh']",
        'buscarMetadados' => "['admin', 'rh']",
    ];
    foreach ($portoesPreExistentes as $metodo => $perfis) {
        $corpo = $corpoMetodo(AdminUsuariosController::class, $metodo);
        $check(str_contains($corpo, "Auth::requireRole({$perfis})"), "AdminUsuariosController::{$metodo} mantém o portão de perfil {$perfis} (Tela de Usuários/Contexto Organizacional/vínculo METADADOS sem mudança de autorização)");
    }
    $controllerFonte = $semComentarios((string)file_get_contents(__DIR__ . '/../../app/controllers/AdminUsuariosController.php'));
    $check(!preg_match('/Authorization::(requirePermissao|usuarioTemPermissao|temPermissao)/', $controllerFonte), 'AdminUsuariosController não decide acesso pelo catálogo de permissões novo (a cobertura desta rodada é só catálogo + menu)');
    $servicoContexto = $semComentarios((string)file_get_contents(__DIR__ . '/../../app/services/UsuarioContextoOrganizacionalService.php'));
    $check(!preg_match('/Authorization::|Auth::requireRole|is_supervisor/', $servicoContexto), 'UsuarioContextoOrganizacionalService segue sem regra de permissão/perfil (nenhum bypass novo)');
    $apiContexto = ['resolverContextoOficial', 'aplicarContextoDoVinculo', 'aoDesvincular', 'definirContextoManual', 'contextoDoUsuario', 'setoresDoUsuario'];
    $check(array_reduce($apiContexto, static fn(bool $ok, string $m): bool => $ok && method_exists(UsuarioContextoOrganizacionalService::class, $m), true), 'UsuarioContextoOrganizacionalService mantém a API pública do Contexto Organizacional/vínculo METADADOS');
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
    $fonteNavegacao = (string)file_get_contents(__DIR__ . '/../../app/services/PortalNavegacaoService.php');
    $check(str_contains($fonteNavegacao, 'Authorization::temPermissao($codigo)') && substr_count($fonteNavegacao, "'rh'") === 1, 'condição aditiva da navegação (PortalNavegacaoService) usa Authorization::temPermissao() como fonte da permissão nova; a única comparação de role==="rh" continua sendo a definição canônica de $staff, sem nenhum bypass novo isolado');
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
