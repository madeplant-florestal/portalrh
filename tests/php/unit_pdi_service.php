<?php

/**
 * Unitário — PDI (PdiService: regras puras, sem banco). Prova que:
 *   - listas fechadas: origem (4), status de negócio + técnicos (rascunho/cancelado), avaliação final (4), ação (3);
 *   - validação: datas (prevista ≥ abertura), origem, referência opcional sem FK, textos limitados/sanitizados,
 *     competências em texto (máx. 10, sem duplicadas), ações (máx. 3 por slot, prazo dentro do PDI);
 *   - transições de status e regras de conclusão/reabertura; atraso e progresso só como SINALIZAÇÃO;
 *   - divergência com o METADADOS (cargo/unidade/desligamento) só sinaliza;
 *   - escopo por linha (Admin/RH total; demais só o próprio) e papel do ator;
 *   - fontes: sem Área, sem anexos/upload, sem notificações, sem assinatura, sem cifragem, sem CPF/codigo_pessoa/
 *     `colaboradores` legado, sem rota do colaborador, acompanhamentos/eventos append-only.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$S = PdiService::class;
$hoje = new DateTimeImmutable('2026-09-19 10:00:00');

// ---- listas fechadas ------------------------------------------------------------------------------------------------
$check(array_keys($S::ORIGENS) === ['avaliacao_experiencia', 'feedback', 'avaliacao_desempenho', 'desenvolvimento_carreira'], '(origem) Quatro origens fechadas do RH');
$check(array_keys($S::STATUS) === ['rascunho', 'nao_iniciado', 'em_andamento', 'concluido', 'cancelado'] && $S::STATUS['nao_iniciado'] === 'Não iniciado' && $S::STATUS['em_andamento'] === 'Em andamento' && $S::STATUS['concluido'] === 'Concluído', '(status) Três status de negócio do RH preservados + estados técnicos rascunho e cancelado');
$check(array_keys($S::AVALIACOES_FINAIS) === ['nao_evoluiu', 'evoluiu_parcialmente', 'objetivo_atingido', 'superou_expectativa'], '(avaliação final) Quatro valores do RH');
$check(array_keys($S::STATUS_ACAO) === ['nao_iniciada', 'em_andamento', 'concluida'] && array_keys($S::RESPONSAVEIS) === ['colaborador', 'gestor', 'rh', 'outro'] && $S::MAX_ACOES === 3, '(ação) Status da ação, tipos de responsável (colaborador/gestor/rh/outro) e máximo de 3 ações');

// ---- validação da estrutura -----------------------------------------------------------------------------------------------
$base = ['origem_tipo' => 'feedback', 'data_abertura' => '2026-09-01', 'data_prevista_conclusao' => '2026-12-01', 'pontos_fortes' => 'Ótima comunicação', 'oportunidades_desenvolvimento' => 'Delegar mais', 'objetivo_esperado' => 'Liderar a equipe', 'competencias' => "Comunicação\nLiderança"];
$ok = $S::validarEstrutura($base);
$check($ok['erros'] === [] && $ok['dados']['origem_tipo'] === 'feedback' && $ok['competencias'] === ['Comunicação', 'Liderança'] && $ok['acoes'] === [], '(validação) Estrutura válida (sem ações é aceito na estrutura; o início é que exige ≥1)');
foreach ([['origem_tipo' => ''], ['origem_tipo' => 'xyz'], ['origem_tipo' => ['feedback']], ['origem_tipo' => 'Feedback']] as $ruim) {
    $check($S::validarEstrutura($ruim + $base)['erros'] !== [], '(origem) Fora da lista fechada é rejeitada: ' . json_encode($ruim['origem_tipo']));
}
$check($S::validarEstrutura(['origem_ref_tipo' => 'avaliacao_experiencia', 'origem_ref_id' => '15'] + $base)['dados']['origem_ref_id'] === 15, '(origem) Referência opcional futura (tipo + id) é aceita, sem FK');
foreach ([['origem_ref_tipo' => 'avaliacao_experiencia'], ['origem_ref_id' => '3'], ['origem_ref_tipo' => 'X y', 'origem_ref_id' => '3'], ['origem_ref_tipo' => 'ok_tipo', 'origem_ref_id' => 'abc'], ['origem_ref_tipo' => 'ok_tipo', 'origem_ref_id' => '0']] as $ruim) {
    $check($S::validarEstrutura($ruim + $base)['erros'] !== [], '(origem) Referência incompleta/inválida é rejeitada: ' . json_encode($ruim));
}
$check($S::validarEstrutura(['data_prevista_conclusao' => '2026-08-31'] + $base)['erros'] !== [] && $S::validarEstrutura(['data_prevista_conclusao' => '2026-09-01'] + $base)['erros'] === [], '(datas) Prevista não pode ser anterior à abertura; igual é aceita');
foreach (['2026-13-01', '2026-02-30', 'abc', '', '01/09/2026', ['2026-09-01']] as $ruim) {
    $check($S::validarEstrutura(['data_abertura' => $ruim] + $base)['erros'] !== [], '(datas) Data inválida rejeitada: ' . json_encode($ruim));
}
$check($S::validarEstrutura(['pontos_fortes' => '', 'oportunidades_desenvolvimento' => '', 'objetivo_esperado' => ''] + $base)['erros'] === [], '(opcionais) Pontos fortes, oportunidades e objetivo são OPCIONAIS — nenhuma obrigatoriedade de negócio não aprovada pelo RH');
$check($S::validarEstrutura(['competencias' => ''] + $base)['erros'] === [] && $S::validarEstrutura(['competencias' => ''] + $base)['competencias'] === [], '(opcionais) Competência também é opcional (sem competência não há bloqueio)');
$check($S::validarEstrutura(['pontos_fortes' => str_repeat('a', 4000)] + $base)['erros'] === [] && $S::validarEstrutura(['pontos_fortes' => str_repeat('ã', 4001)] + $base)['erros'] !== [], '(limite) Textos até 4000 caracteres (multibyte inclusive); acima é rejeitado sem truncar');
$san = $S::validarEstrutura(['pontos_fortes' => "<b>oi</b><script>alert(1)</script>\r\nlinha\x00\x07"] + $base);
$check(!str_contains((string)$san['dados']['pontos_fortes'], '<') && str_contains((string)$san['dados']['pontos_fortes'], "oi") && !str_contains((string)$san['dados']['pontos_fortes'], "\x00") && !str_contains((string)$san['dados']['pontos_fortes'], "\r"), '(sanitização) Tags, caracteres de controle e CRLF tratados');

// competências
$c = $S::parseCompetencias("  Comunicação \n\ncomunicação\nLiderança\n<b>Foco</b>");
$check($c['itens'] === ['Comunicação', 'Liderança', 'Foco'] && $c['erros'] === [], '(competências) Uma por linha, sem vazias, sem duplicadas (ignora caixa), sem tags');
$muitas = implode("\n", array_map(static fn(int $i): string => "Competência $i", range(1, 11)));
$check($S::parseCompetencias($muitas)['erros'] !== [] && $S::parseCompetencias(implode("\n", array_map(static fn(int $i): string => "Competência $i", range(1, 10))))['erros'] === [], '(competências) Máximo de 10');
$check($S::parseCompetencias(str_repeat('x', 181))['erros'] !== [], '(competências) Até 180 caracteres cada');

// ações
$acao = static fn(string $d, string $prazo = '2026-10-01', string $tipo = 'colaborador'): array => ['descricao' => $d, 'responsavel_tipo' => $tipo, 'prazo' => $prazo];
$a1 = $S::parseAcoes([1 => $acao('Curso'), 2 => $acao('Mentoria', '2026-11-01', 'gestor'), 3 => $acao('Projeto', '2026-12-01', 'rh')], '2026-09-01', '2026-12-01');
$check($a1['erros'] === [] && array_keys($a1['acoes']) === [1, 2, 3], '(ações) Três ações (slots 1–3)');
$a4 = $S::parseAcoes([1 => $acao('A'), 2 => $acao('B'), 3 => $acao('C'), 4 => $acao('D')], '2026-09-01', '2026-12-01');
$check($a4['erros'] !== [] && count($a4['acoes']) === 3 && str_contains($a4['erros'][0], 'no máximo 3'), '(ações) Uma quarta ação é rejeitada — máximo 3 por PDI');
$check($S::parseAcoes([0 => $acao('A')], null, null)['erros'] !== [] && $S::parseAcoes(['x' => $acao('A')], null, null)['erros'] !== [], '(ações) Slot fora de 1–3 é rejeitado');
$check($S::parseAcoes([2 => ['descricao' => '', 'prazo' => '', 'responsavel_tipo' => 'colaborador']], null, null)['acoes'] === [], '(ações) Slot em branco = sem ação');
$check(count($S::parseAcoes([1 => $acao('')], null, null)['erros']) >= 1 && $S::parseAcoes([1 => ['descricao' => 'A', 'prazo' => '', 'responsavel_tipo' => 'colaborador']], null, null)['erros'] !== [], '(ações) Descrição e prazo obrigatórios quando o slot é usado');
$check($S::parseAcoes([1 => $acao('A', '2026-08-31')], '2026-09-01', '2026-12-01')['erros'] !== [] && $S::parseAcoes([1 => $acao('A', '2026-12-02')], '2026-09-01', '2026-12-01')['erros'] !== [] && $S::parseAcoes([1 => $acao('A', '2026-12-01')], '2026-09-01', '2026-12-01')['erros'] === [], '(ações) Prazo da ação dentro de [abertura, prevista] do PDI');
$check($S::parseAcoes([1 => $acao('A', '2026-10-01', 'presidente')], null, null)['erros'] !== [], '(ações) Tipo de responsável fora da lista é rejeitado');
$check($S::parseAcoes([1 => ['responsavel_usuario_id' => '7'] + $acao('A')], null, null)['acoes'][1]['responsavel_usuario_id'] === 7 && $S::parseAcoes([1 => ['responsavel_usuario_id' => '7x'] + $acao('A')], null, null)['acoes'][1]['responsavel_usuario_id'] === null, '(ações) Usuário responsável só aceita inteiro positivo');
$check($S::parseAcoes([1 => $acao(str_repeat('a', 500))], null, null)['erros'] === [] && $S::parseAcoes([1 => $acao(str_repeat('a', 501))], null, null)['erros'] !== [], '(ações) Descrição até 500 caracteres');

// ---- transições --------------------------------------------------------------------------------------------------------------
$check($S::transicaoPermitida('rascunho', 'nao_iniciado') && $S::transicaoPermitida('rascunho', 'em_andamento') && $S::transicaoPermitida('nao_iniciado', 'em_andamento') && $S::transicaoPermitida('em_andamento', 'concluido') && $S::transicaoPermitida('concluido', 'em_andamento'), '(transição) Caminho feliz: rascunho → não iniciado → em andamento → concluído; reabertura concluído → em andamento');
$check(!$S::transicaoPermitida('nao_iniciado', 'concluido') && !$S::transicaoPermitida('rascunho', 'concluido') && !$S::transicaoPermitida('concluido', 'cancelado') && !$S::transicaoPermitida('cancelado', 'em_andamento') && !$S::transicaoPermitida('em_andamento', 'rascunho') && !$S::transicaoPermitida('em_andamento', 'nao_iniciado'), '(transição) Só conclui de "em andamento"; concluído não cancela; cancelado é final; sem volta a rascunho');
$check($S::TRANSICOES['cancelado'] === [] && in_array('cancelado', $S::TRANSICOES['rascunho'], true) && in_array('cancelado', $S::TRANSICOES['em_andamento'], true), '(transição) Cancelamento parte de rascunho, não iniciado ou em andamento');

// ---- atraso e progresso (sinalização) -----------------------------------------------------------------------------------------
$pdiAtrasado = ['status' => 'em_andamento', 'data_prevista_conclusao' => '2026-09-10'];
$check($S::situacaoPrazo($pdiAtrasado, $hoje) === ['atrasado' => true, 'dias_atraso' => 9], '(atraso) Em andamento após a data prevista: atrasado (9 dias) — só sinalização');
$check($S::situacaoPrazo(['status' => 'em_andamento', 'data_prevista_conclusao' => '2026-09-19'], $hoje)['atrasado'] === false && $S::situacaoPrazo(['status' => 'nao_iniciado', 'data_prevista_conclusao' => '2026-09-18'], $hoje)['atrasado'] === true, '(atraso) No dia da previsão ainda não é atraso; não iniciado também sinaliza');
foreach (['rascunho', 'concluido', 'cancelado'] as $s) {
    $check($S::situacaoPrazo(['status' => $s, 'data_prevista_conclusao' => '2020-01-01'], $hoje)['atrasado'] === false, "(atraso) Status \"{$s}\" nunca é sinalizado como atrasado");
}
$check($S::progressoAcoes([]) === ['total' => 0, 'concluidas' => 0, 'percentual' => null], '(progresso) Sem ações: sem percentual (não é 0%)');
$check($S::progressoAcoes([['status' => 'concluida'], ['status' => 'em_andamento'], ['status' => 'nao_iniciada']]) === ['total' => 3, 'concluidas' => 1, 'percentual' => 33], '(progresso) 1 de 3 ações concluídas = 33%');
$check($S::acaoAtrasada(['status' => 'nao_iniciada', 'prazo' => '2026-09-18'], ['status' => 'em_andamento'], $hoje) === true && $S::acaoAtrasada(['status' => 'concluida', 'prazo' => '2026-09-18'], ['status' => 'em_andamento'], $hoje) === false && $S::acaoAtrasada(['status' => 'nao_iniciada', 'prazo' => '2026-09-18'], ['status' => 'concluido'], $hoje) === false, '(atraso) Ação atrasada: prazo vencido e não concluída, com PDI ativo');

// ---- divergências com o METADADOS ------------------------------------------------------------------------------------------------
$snap = ['snap_codigo_cargo' => '10', 'snap_data_inicio_cargo' => '2025-01-01', 'snap_codigo_unidade' => '01', 'snap_codigo_empresa' => '1', 'atual_codigo_cargo' => '10', 'atual_data_inicio_cargo' => '2025-01-01', 'atual_codigo_unidade' => '01', 'atual_codigo_empresa' => '1', 'atual_demissao' => null];
$check($S::divergencias($snap, $hoje) === ['itens' => [], 'desligado' => false, 'desligamento' => null], '(divergência) Contrato igual à abertura: nada a sinalizar');
$dv = $S::divergencias(['atual_codigo_cargo' => '20'] + $snap, $hoje);
$check(count($dv['itens']) === 1 && $dv['itens'][0]['tipo'] === 'cargo' && $dv['desligado'] === false, '(divergência) Mudança de cargo só sinaliza');
$check($S::divergencias(['atual_data_inicio_cargo' => '2026-05-01'] + $snap, $hoje)['itens'][0]['tipo'] === 'cargo', '(divergência) Nova data de início de cargo também sinaliza');
$check($S::divergencias(['atual_codigo_unidade' => '02'] + $snap, $hoje)['itens'][0]['tipo'] === 'unidade' && $S::divergencias(['atual_codigo_empresa' => '2'] + $snap, $hoje)['itens'][0]['tipo'] === 'unidade', '(divergência) Mudança de unidade ou empresa sinaliza');
$dd = $S::divergencias(['atual_demissao' => '2026-09-10'] + $snap, $hoje);
$check($dd['desligado'] === true && $dd['desligamento'] === '2026-09-10' && $dd['itens'][0]['tipo'] === 'desligado' && str_contains($dd['itens'][0]['mensagem'], 'RH decide'), '(desligamento) Contrato desligado: aviso claro; o PDI é mantido e o RH decide');
$check($S::divergencias(['atual_demissao' => '2026-09-25'] + $snap, $hoje)['desligado'] === false && $S::divergencias(['atual_demissao' => '2026-09-25'] + $snap, $hoje)['itens'][0]['tipo'] === 'desligamento_agendado', '(desligamento) Desligamento futuro: só aviso de agendamento');

// ---- escopo, papel -------------------------------------------------------------------------------------------------------------------
$admin = ['id' => 1, 'role' => 'admin', 'supervisor' => false];
$rh = ['id' => 2, 'role' => 'rh', 'supervisor' => false];
$viewer = ['id' => 3, 'role' => 'viewer', 'supervisor' => false];
$super = ['id' => 4, 'role' => 'viewer', 'supervisor' => true]; // o flag é IGNORADO no PDI
$check($S::escopoTotal($admin) && $S::escopoTotal($rh) && !$S::escopoTotal($super) && !$S::escopoTotal($viewer), '(escopo) Só Admin e RH têm escopo total; supervisor (sinalização) e viewer/gestor só o próprio');
$check($S::escopoGestor($rh) === null && $S::escopoGestor($viewer) === 3 && $S::escopoGestor($super) === 4, '(escopo) Filtro por linha: null para Admin/RH, id do usuário para os demais');
$check($S::papelDoAtor($admin) === 'admin' && $S::papelDoAtor($super) === 'gestor' && $S::papelDoAtor($rh) === 'rh' && $S::papelDoAtor($viewer) === 'gestor', '(papel) admin / rh / gestor');
$pdiDoGestor3 = ['gestor_usuario_id' => 3];
$check($S::podeAcessar($pdiDoGestor3, $viewer) && !$S::podeAcessar(['gestor_usuario_id' => 9], $viewer) && $S::podeAcessar(['gestor_usuario_id' => 9], $rh) && $S::podeAcessar(['gestor_usuario_id' => 9], $admin) && !$S::podeAcessar($pdiDoGestor3, ['id' => 0, 'role' => 'admin']) && !$S::podeAcessar(['gestor_usuario_id' => 9], $super) && $S::podeAcessar(['gestor_usuario_id' => 4], $super), '(escopo) Gestor (e supervisor sem ser Admin/RH) só acessa o PDI em que é o responsável; sem usuário nunca acessa');

// ---- formatação do histórico -------------------------------------------------------------------------------------------------------------
$check($S::formatarValorEvento('data_prevista_conclusao', '2026-10-20') === '20/10/2026' && $S::formatarValorEvento('status', 'em_andamento') === 'Em andamento' && $S::formatarValorEvento('avaliacao_final', 'superou_expectativa') === 'Superou expectativa' && $S::formatarValorEvento('acao_1.prazo', '2026-11-05') === '05/11/2026' && $S::formatarValorEvento('acao_2.status', 'concluida') === 'Concluída' && $S::formatarValorEvento('x', null) === '—', '(histórico) Datas, status e avaliação formatados nos eventos');
$check($S::rotuloEvento('alteracao_prazo') === 'Prazo alterado' && $S::rotuloEvento('inexistente') === 'inexistente', '(histórico) Rótulos de evento (desconhecido devolve o próprio código)');

// ---- fontes -----------------------------------------------------------------------------------------------------------------------------------
$semComentarios = static function (string $arquivo): string {
    $saida = '';
    foreach (token_get_all((string)file_get_contents($arquivo)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $saida .= is_array($t) ? $t[1] : $t;
    }
    return $saida;
};
$repo = $semComentarios(APP_PATH . '/repositories/PdiRepository.php');
$servico = $semComentarios(APP_PATH . '/services/PdiService.php');
$ctrl = $semComentarios(APP_PATH . '/controllers/AdminPdisController.php');
$views = '';
foreach (glob(APP_PATH . '/views/admin/pdis/*.php') as $arq) {
    $views .= $semComentarios($arq);
}
$codigo = $repo . $servico . $ctrl;
$migracao = preg_replace('/^--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-23-pdi.sql'));

$check(!preg_match('/\b(area|área)\b/iu', $codigo . $migracao) && !preg_match('/name="area"|Área/u', $views) && !preg_match('/\b(setor|centro_custo|codigo_setor)\b/i', $codigo . $migracao . $views), '(escopo) Sem Área — e sem Setor/Centro de Custo fazendo as vezes de Área');
$check(!preg_match('/\b(cpf|nascimento|salario|codigo_pessoa)\b/i', $codigo . $migracao . $views), '(fonte) Nunca CPF, nascimento, salário ou codigo_pessoa');
$check(!preg_match('/\b(FROM|JOIN|UPDATE|INTO)\s+colaboradores\b(?!_)/i', $repo) && str_contains($repo, 'colaboradores_metadados'), '(fonte) Só o espelho oficial (metadados_id) — nunca `colaboradores` legado');
$check(!preg_match('/supervisor/i', $servico . $repo) && !str_contains($ctrl, 'requireRole('), '(escopo) Supervisor não aparece na regra do PDI e o controller não usa Auth::requireRole (que libera supervisor): só permissão individual + escopo por linha');
$check(!str_contains($servico, 'faltaParaAbrir') && !preg_match('/preencha antes de liberar|obrigat[óo]rio para um PDI em andamento|ao menos uma compet[eê]ncia|mantenha? ao menos 1 a[çc][ãa]o/iu', $servico . $views), '(transição) Sem pré-requisitos de conteúdo não aprovados para sair de rascunho (textos/competência) nem regra de "manter ≥1 ação"');
$check(str_contains($servico, "Para iniciar, o PDI precisa ter ao menos 1 ação") && str_contains($servico, "Selecione a avaliação final para concluir o PDI"), '(transição) Permanecem as exigências aprovadas: ≥1 ação para iniciar e avaliação final para concluir');
$check(!preg_match('/usuario_colaboradores|lider_colaborador_id|is_gestor|aprovador_usuario_id|colaborador_metadados_id/i', $codigo), '(gestor) Nenhuma inferência de gestor por legado/aprovador e nenhum vínculo usuário↔colaborador (colaborador sem login)');
$check(!preg_match('/Upload::|move_uploaded_file|\$_FILES|type="file"|enctype/i', $codigo . $views) && !preg_match('/anexo|upload/i', $migracao), '(V1) Sem anexos/upload');
$check(!preg_match('/Mailer|mail\(|webhook|Evolution|n8n|WebhookEvent|comunicac/i', $codigo), '(V1) Sem notificações (e-mail, WhatsApp, N8N, Evolution)');
$check(!preg_match('/assinatura|signatureHash|rubrica|ci[eê]ncia_em|assinado/i', $codigo . $views . $migracao), '(V1) Sem assinatura/ciência eletrônica');
$check(!preg_match('/Cipher::|encrypt|_encrypted/i', $codigo . $migracao), '(V1) Sem cifragem (Cipher) nos textos do PDI');
$check(!preg_match('/ensureSchema|CREATE\s+TABLE|ALTER\s+TABLE/i', $codigo), '(banco) Sem ensureSchema/DDL em runtime');
$check(!preg_match('/UPDATE\s+pdi_acompanhamentos|DELETE\s+FROM\s+pdi_acompanhamentos|UPDATE\s+pdi_eventos|DELETE\s+FROM\s+pdi_eventos/i', $repo) && !preg_match('/function\s+(atualizar|editar|remover|excluir|apagar)(Acompanhamento|Evento)/i', $repo), '(append-only) O repository não tem UPDATE/DELETE de acompanhamentos nem de eventos');
$check(!preg_match('/UNIQUE[^,]*\(\s*metadados_id/i', $migracao) && preg_match('/UNIQUE KEY uk_pdi_acoes_ordem \(pdi_id, ordem\)/', $migracao) === 1 && str_contains($migracao, 'CHECK (ordem BETWEEN 1 AND 3)'), '(banco) Sem UNIQUE por contrato (vários PDIs); UNIQUE (pdi_id, ordem) e CHECK 1–3 nas ações');
$check(str_contains($migracao, 'chk_pdis_conclusao') && str_contains($migracao, 'chk_pdis_prazo') && str_contains($migracao, 'chk_pdis_status') && str_contains($migracao, 'chk_pdis_origem'), '(banco) CHECKs de status, origem, prazo e coerência conclusão × avaliação final × data real');
$check(preg_match('/competencia_id\s+INT\s+NULL/i', $migracao) === 1 && !preg_match('/FOREIGN KEY \(competencia_id\)|FOREIGN KEY \(origem_ref_id\)/i', $migracao), '(banco) `competencia_id` e `origem_ref_id` nullable e SEM FK (integração futura)');
foreach (['acao_1', 'acao_2', 'acao_3'] as $col) {
    $check(!str_contains($migracao, $col . ' '), "(banco) Sem coluna fixa {$col}");
}
$check(!preg_match('/\son[a-z]+\s*=|<script|https?:\/\/|googleapis|cdn\./i', $views), '(CSP) Views sem handlers inline, sem <script>, sem CDN');
$check(!preg_match('/name="(colaborador_id|metadados_id)"\s+value="<\?= Security::e\(\$_/', $views), '(views) Nenhum identificador vindo cru de $_GET/$_POST');

$rotasFonte = (string)file_get_contents(BASE_PATH . '/index.php');
preg_match_all("#\\\$router->(get|post)\\('(/[^']*pdi[^']*)'#i", $rotasFonte, $rotas);
$check(count($rotas[2]) === 16 && count(array_filter($rotas[2], static fn(string $r): bool => !str_starts_with($r, '/admin/pdis'))) === 0, '(colaborador) As 16 rotas do PDI ficam todas sob /admin/pdis (login global) — não existe rota/portal do colaborador');
$seed = preg_replace('/^--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-23-pdi-permissoes-seed.sql'));
$check(str_contains($seed, 'INSERT IGNORE INTO permissoes') && !preg_match('/usuario_permissoes|\b(CREATE|ALTER|DROP)\s+(TABLE|DATABASE)/i', $seed) && str_contains($seed, "'pdi.visualizar'") && str_contains($seed, "'pdi.gerenciar'") && str_contains($seed, "'pdi.acompanhar'") && str_contains($seed, ', 660, 1)') && str_contains($seed, ', 670, 1)') && str_contains($seed, ', 680, 1);'), '(seed) Só cadastra as 3 permissões (660/670/680), sem conceder');
$check(is_file(BASE_PATH . '/database/migrations/2026-09-23-pdi-rollback.sql') && substr_count((string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-23-pdi-rollback.sql'), 'DROP TABLE IF EXISTS') === 5, '(migration) Rollback remove as 5 tabelas');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_PDI_OK\n";
