<?php

/**
 * Unitário — Entrevista de Desligamento (EntrevistaDesligamentoService: regras puras). Sem banco.
 * Prova que:
 *   - elegibilidade: demissão efetivada (hoje inclusive), futura/ausente inelegível, Falecimento (020) inelegível,
 *     demais motivos elegíveis (não há outra exclusão);
 *   - situação derivada de timestamps (respondida > cancelada > expirada > pendente), com a fronteira da expiração;
 *   - eNPS: 0–6 Detrator, 7–8 Neutro, 9–10 Promotor, calculado pelo sistema (o navegador não define a classe);
 *   - validação server-side: escalas 1–5, 0–10, motivo/fatores por lista fechada, textos sanitizados e limitados;
 *   - contexto público mínimo (primeiro nome), tempo de empresa e divergência snapshot × METADADOS;
 *   - formulário/constantes conforme o desenho aprovado e SEM Voluntário/Involuntário;
 *   - fontes: só espelho oficial (sem `colaboradores` legado, sem CPF), sem ensureSchema, layout público sem
 *     recursos de terceiros, sem handlers inline.
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

$S = EntrevistaDesligamentoService::class;
$hoje = new DateTimeImmutable('2026-09-19 10:00:00');

// ---- desenho aprovado -------------------------------------------------------------------------------------------------
$check($S::PRAZO_DIAS === 30 && $S::MOTIVO_OFICIAL_INELEGIVEL === '020' && $S::LIMITE_TEXTO === 2000, '(regra) Prazo de 30 dias, Falecimento = 020, textos até 2000 caracteres');
$check(count($S::MOTIVOS_DECLARADOS) === 14 && array_values($S::MOTIVOS_DECLARADOS)[13] === 'Outro' && $S::MOTIVOS_DECLARADOS['nova_oportunidade'] === 'Nova oportunidade profissional', '(form) 14 opções de motivo principal, na ordem aprovada');
$check(count($S::FATORES_CONTRIBUINTES) === 11 && $S::FATORES_CONTRIBUINTES['crescimento'] === 'Crescimento profissional', '(form) 11 fatores contribuintes');
$contagens = array_map(static fn(array $s): int => count($s['itens']), $S::SECOES_ESCALA);
$check(array_values($contagens) === [7, 5, 6, 3] && array_keys($contagens) === ['experiencia', 'lideranca', 'cultura', 'integracao'], '(form) Experiência (7), Liderança (5), Cultura e valores (6), Integração (3)');
$check($S::ESCALA_1_5 === [1 => 'Muito insatisfeito', 2 => 'Insatisfeito', 3 => 'Neutro', 4 => 'Satisfeito', 5 => 'Muito satisfeito'], '(form) Escala 1–5 com os rótulos aprovados');
$check(count($S::PERGUNTAS_ABERTAS) === 3, '(form) Três perguntas abertas');

// ---- elegibilidade ---------------------------------------------------------------------------------------------------
$el = static fn(?string $dem, ?string $motivo) => $S::elegibilidade(['demissao' => $dem, 'motivo_rescisao_codigo' => $motivo], $hoje);
$check($el(null, null)['motivo'] === 'sem_demissao' && $el('', '003')['elegivel'] === false && $el('0000-00-00', '003')['motivo'] === 'sem_demissao', '(elegibilidade) Sem demissão registrada é inelegível');
$check($el('2026-09-20', '003')['motivo'] === 'demissao_futura' && $el('2099-01-01', '002')['elegivel'] === false, '(elegibilidade) Desligamento futuro é inelegível');
$check($el('2026-09-19', '003')['elegivel'] === true && $el('2026-09-18', '003')['elegivel'] === true, '(elegibilidade) Demissão de hoje e passadas são elegíveis (demissao <= hoje)');
$check($el('2026-09-01', '020')['motivo'] === 'falecimento' && $el('2026-09-01', ' 020 ')['elegivel'] === false, '(elegibilidade) Falecimento (020) fica fora, mesmo com espaços');
$todosElegiveis = true;
foreach (['001', '002', '003', '005', '006', '007', '008', '016', '046', '', null, '999'] as $motivo) {
    $todosElegiveis = $todosElegiveis && $el('2026-09-01', $motivo)['elegivel'] === true;
}
$check($todosElegiveis, '(elegibilidade) Justa causa, acordo, término, pedido, demissão e demais motivos (inclusive vazio/desconhecido) são elegíveis — só 020 é excluído');
$check(str_contains((string)$el('2026-09-01', '020')['mensagem'], 'Falecimento') && $S::criteriosElegibilidade($hoje) === ['hoje' => '2026-09-19', 'motivo_excluido' => '020'], '(elegibilidade) Mensagem clara e critérios repassados ao repository (mesma regra em SQL)');

// ---- situação ----------------------------------------------------------------------------------------------------------
$base = ['respondida_em' => null, 'cancelada_em' => null, 'expira_em' => '2026-10-19 10:00:00'];
$check($S::situacao($base, $hoje) === 'pendente', '(situação) Sem resposta/cancelamento e no prazo = pendente');
$check($S::situacao(['expira_em' => '2026-09-19 10:00:00'] + $base, $hoje) === 'expirada' && $S::situacao(['expira_em' => '2026-09-19 10:00:01'] + $base, $hoje) === 'pendente', '(situação) Fronteira: expira_em <= agora é expirada; 1s antes ainda pendente');
$check($S::situacao(['cancelada_em' => '2026-09-01 08:00:00'] + $base, $hoje) === 'cancelada', '(situação) Cancelada');
$check($S::situacao(['cancelada_em' => '2026-09-01 08:00:00', 'expira_em' => '2026-01-01 00:00:00'] + $base, $hoje) === 'cancelada', '(situação) Cancelada tem precedência sobre expirada');
$check($S::situacao(['respondida_em' => '2026-09-05 09:00:00', 'cancelada_em' => '2026-09-06 09:00:00', 'expira_em' => '2026-01-01 00:00:00'] + $base, $hoje) === 'respondida', '(situação) Respondida tem precedência sobre tudo');
$check($S::situacao(['expira_em' => null] + $base, $hoje) === 'expirada', '(situação) Sem expiração legível = expirada (falha segura)');

// ---- eNPS ---------------------------------------------------------------------------------------------------------------
$cls = array_map([$S, 'classificarEnps'], range(0, 10));
$check($cls === ['Detrator', 'Detrator', 'Detrator', 'Detrator', 'Detrator', 'Detrator', 'Detrator', 'Neutro', 'Neutro', 'Promotor', 'Promotor'], '(eNPS) 0–6 Detrator, 7–8 Neutro, 9–10 Promotor');
$check($S::calcularEnps(['total' => 0, 'promotores' => 0, 'neutros' => 0, 'detratores' => 0]) === null, '(eNPS) Sem respostas: nulo (nunca 0)');
$check($S::calcularEnps(['total' => 4, 'promotores' => 2, 'neutros' => 1, 'detratores' => 1]) === 25.0 && $S::calcularEnps(['total' => 3, 'promotores' => 0, 'neutros' => 0, 'detratores' => 3]) === -100.0 && $S::calcularEnps(['total' => 3, 'promotores' => 1, 'neutros' => 1, 'detratores' => 1]) === 0.0, '(eNPS) %Promotores − %Detratores');

// ---- contexto/tempo/divergência ------------------------------------------------------------------------------------------
$check($S::primeiroNome('JOAO DA SILVA') === 'Joao' && $S::primeiroNome('  maria  souza') === 'Maria' && $S::primeiroNome('ÉRICA LIMA') === 'Érica' && $S::primeiroNome('') === '' && $S::primeiroNome(null) === '', '(contexto) Só o primeiro nome, capitalizado');
$check($S::tempoDeEmpresa('2020-01-15', '2022-04-20') === '2 anos e 3 meses' && $S::tempoDeEmpresa('2021-01-01', '2022-01-01') === '1 ano' && $S::tempoDeEmpresa('2022-02-01', '2022-03-05') === '1 mês', '(tempo) Anos e meses entre admissão e desligamento');
$check($S::tempoDeEmpresa('2022-01-01', '2022-01-16') === '15 dias' && $S::tempoDeEmpresa('2022-01-01', '2022-01-02') === '1 dia' && $S::tempoDeEmpresa('2022-01-01', '2022-01-01') === 'menos de 1 dia', '(tempo) Menos de um mês em dias');
$check($S::tempoDeEmpresa('2022-05-01', '2022-01-01') === null && $S::tempoDeEmpresa(null, '2022-01-01') === null && $S::tempoDeEmpresa('2022-01-01', null) === null, '(tempo) Datas ausentes ou incoerentes: não calcula');
$snap = ['snap_demissao' => '2026-09-10', 'snap_motivo_codigo' => '003', 'atual_demissao' => '2026-09-10', 'atual_motivo_codigo' => '003'];
$check($S::divergencias($snap) === [], '(divergência) Snapshot igual ao oficial atual: sem sinalização');
$check(count($S::divergencias(['atual_demissao' => '2026-09-12'] + $snap)) === 1 && str_contains($S::divergencias(['atual_demissao' => '2026-09-12'] + $snap)[0], '12/09/2026'), '(divergência) Demissão oficial alterada é sinalizada com as duas datas');
$check(count($S::divergencias(['atual_motivo_codigo' => '002'] + $snap)) === 1 && count($S::divergencias(['atual_motivo_codigo' => null] + $snap)) === 1, '(divergência) Motivo oficial alterado é sinalizado');
$semDemissao = $S::divergencias(['atual_demissao' => null] + $snap);
$check(count($semDemissao) === 1 && str_contains($semDemissao[0], 'não consta mais'), '(divergência) Demissão que sumiu da origem é sinalizada (a entrevista nunca é apagada)');

// ---- validação da resposta -------------------------------------------------------------------------------------------------
$postValido = ['motivo_principal' => 'nova_oportunidade', 'experiencia_geral' => '8', 'enps' => '9', 'fatores' => ['lideranca', 'clima']];
foreach ($S::SECOES_ESCALA as $secao) {
    foreach (array_keys($secao['itens']) as $campo) {
        $postValido[$campo] = '4';
    }
}
$v = $S::validarResposta($postValido);
$check($v['ok'] === true && $v['erros'] === [] && $v['dados']['enps'] === 9 && $v['dados']['exp_remuneracao'] === 4 && $v['dados']['experiencia_geral'] === 8 && $v['fatores'] === ['lideranca', 'clima'], '(validação) Resposta válida: inteiros tipados e fatores');
$check(!array_key_exists('enps_classificacao', $v['dados']), '(validação) A classificação do eNPS não é dado de entrada (calculada pelo sistema)');
$vInjetado = $S::validarResposta($postValido + ['enps_classificacao' => 'Promotor', 'metadados_id' => '1', 'respondida_em' => '2000-01-01']);
$check($vInjetado['ok'] === true && array_keys($vInjetado['dados']) === array_values(array_intersect(array_keys($vInjetado['dados']), EntrevistaDesligamentoRepository::COLUNAS_RESPOSTA)) && count(array_diff(array_keys($vInjetado['dados']), EntrevistaDesligamentoRepository::COLUNAS_RESPOSTA)) === 0, '(validação) Campos extras do POST (classificação, metadados_id, respondida_em) não entram nos dados');
$check($S::validarResposta(['experiencia_geral' => '0', 'enps' => '0'] + $postValido)['ok'] === true && $S::validarResposta(['experiencia_geral' => '10', 'enps' => '10'] + $postValido)['ok'] === true, '(validação) 0 e 10 são aceitos nas escalas 0–10');
foreach (['11', '-1', '', 'a', '5.5', '05', ' 7', '7 ', '1e1'] as $ruim) {
    $check($S::validarResposta(['enps' => $ruim] + $postValido)['ok'] === false && $S::validarResposta(['experiencia_geral' => $ruim] + $postValido)['ok'] === false, "(validação) 0–10 rejeita \"{$ruim}\"");
}
foreach (['0', '6', '', 'a', '3.5', '03', ' 3', '-1'] as $ruim) {
    $check($S::validarResposta(['lid_respeito' => $ruim] + $postValido)['ok'] === false, "(validação) 1–5 rejeita \"{$ruim}\"");
}
$check($S::validarResposta(['lid_respeito' => ['3']] + $postValido)['ok'] === false && $S::validarResposta(['enps' => ['9']] + $postValido)['ok'] === false, '(validação) Valor em formato de array é rejeitado');
$incompleto = $postValido;
unset($incompleto['cul_ousadia']);
$vInc = $S::validarResposta($incompleto);
$check($vInc['ok'] === false && $vInc['valores']['cul_ousadia'] === '' && $vInc['valores']['lid_respeito'] === '4', '(validação) Falta de uma nota 1–5 bloqueia e preserva as demais');
foreach ([null, '', 'xyz', 'Voluntário', ['nova_oportunidade']] as $ruim) {
    $check($S::validarResposta(['motivo_principal' => $ruim] + $postValido)['ok'] === false, '(validação) Motivo principal fora da lista fechada é rejeitado: ' . json_encode($ruim));
}
$check($S::validarResposta(['fatores' => ['xyz']] + $postValido)['ok'] === false && $S::validarResposta(['fatores' => [['a']]] + $postValido)['ok'] === false, '(validação) Fator contribuinte inválido é rejeitado');
$check($S::validarResposta(['fatores' => 'lideranca'] + $postValido)['fatores'] === [] && $S::validarResposta(['fatores' => ['clima', 'clima', 'equipe']] + $postValido)['fatores'] === ['clima', 'equipe'], '(validação) Fatores são opcionais, em lista e sem duplicidade');
$check($S::validarResposta(['fatores' => []] + $postValido)['ok'] === true, '(validação) Nenhum fator marcado é permitido');

$vTexto = $S::validarResposta(['motivo_descricao' => '<b>oi</b> <script>alert(1)</script>', 'aberta_melhorar' => "linha1\r\nlinha2\x00\x07fim", 'aberta_mensagem' => ['x']] + $postValido);
$check($vTexto['ok'] === true && !str_contains((string)$vTexto['dados']['motivo_descricao'], '<') && str_contains((string)$vTexto['dados']['motivo_descricao'], 'oi'), '(sanitização) Tags HTML removidas dos textos');
$check($vTexto['dados']['aberta_melhorar'] === "linha1\nlinha2fim" && $vTexto['dados']['aberta_mensagem'] === null, '(sanitização) Quebra de linha normalizada, caracteres de controle removidos, valor não textual ignorado');
$check($S::validarResposta(['aberta_continuar' => str_repeat('a', 2000)] + $postValido)['ok'] === true, '(limite) 2000 caracteres são aceitos');
$check($S::validarResposta(['aberta_continuar' => str_repeat('a', 2001)] + $postValido)['ok'] === false && $S::validarResposta(['motivo_descricao' => str_repeat('ã', 2001)] + $postValido)['ok'] === false, '(limite) 2001 caracteres (multibyte inclusive) são rejeitados — sem truncar em silêncio');
$vVazio = $S::validarResposta(['aberta_continuar' => '   ', 'motivo_descricao' => ''] + $postValido);
$check($vVazio['ok'] === true && $vVazio['dados']['aberta_continuar'] === null && $vVazio['dados']['motivo_descricao'] === null, '(textos) Perguntas abertas são opcionais (vazio vira NULL)');
$vCultura = $S::validarResposta(['cultura_pratica_valores' => 'texto que não deve existir'] + $postValido);
$check(!array_key_exists('cultura_pratica_valores', $vCultura['dados']) && !array_key_exists('cultura_pratica_valores', $vCultura['valores']) && !in_array('cultura_pratica_valores', EntrevistaDesligamentoRepository::COLUNAS_RESPOSTA, true), '(cultura) Não existe resposta textual própria para "a empresa pratica seus valores?" — campo do POST ignorado e fora das colunas gravadas');
$check(array_column($S::SECOES_ESCALA, 'escala') === ['satisfacao', 'neutra', 'neutra', 'neutra'] && array_keys($S::SECOES_ESCALA) === ['experiencia', 'lideranca', 'cultura', 'integracao'], '(escala) Só Experiência usa a legenda de satisfação; Liderança, Cultura e Integração são neutras');
$check($S::LEGENDA_ESCALA_NEUTRA === 'Escala de 1 a 5: 1 é a avaliação mais baixa e 5 é a mais alta.' && !preg_match('/discordo|concordo|sempre|excelente|nunca|satisf/i', $S::LEGENDA_ESCALA_NEUTRA), '(escala) Legenda neutra: só indica que 1 é a mais baixa e 5 a mais alta, sem rótulos inventados');
$vErro = $S::validarResposta(['motivo_principal' => 'remuneracao', 'aberta_mensagem' => 'meu texto', 'fatores' => ['equipe'], 'enps' => '99']);
$check($vErro['ok'] === false && $vErro['valores']['motivo_principal'] === 'remuneracao' && $vErro['valores']['aberta_mensagem'] === 'meu texto' && $vErro['valores']['fatores'] === ['equipe'], '(validação) Em caso de erro, os valores válidos já preenchidos são devolvidos para repovoar o formulário');

// ---- fontes ---------------------------------------------------------------------------------------------------------------
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
$servico = $semComentarios(APP_PATH . '/services/EntrevistaDesligamentoService.php');
$repo = $semComentarios(APP_PATH . '/repositories/EntrevistaDesligamentoRepository.php');
$ctrlPublico = $semComentarios(APP_PATH . '/controllers/EntrevistaDesligamentoController.php');
$ctrlAdmin = $semComentarios(APP_PATH . '/controllers/AdminEntrevistaDesligamentoController.php');

$check(!preg_match('/PeopleAnalytics|TurnoverDashboard|MAPA_MOTIVOS|CODIGOS_VOLUNTARIO|CODIGOS_INVOLUNTARIO|RhIndicadores/', $servico . $repo . $ctrlPublico . $ctrlAdmin), '(escopo) Não reutiliza os mapas/serviços de People Analytics, Turnover ou Indicadores');
$check(!preg_match('/volunt[aá]ri|involunt[aá]ri/iu', $servico . $repo . $ctrlPublico . $ctrlAdmin . (string)file_get_contents(APP_PATH . '/views/entrevista_desligamento/publica.php') . (string)file_get_contents(APP_PATH . '/views/admin/entrevista_desligamento/index.php') . (string)file_get_contents(APP_PATH . '/views/admin/entrevista_desligamento/resultado.php')), '(escopo) Sem classificação Voluntário/Involuntário no código nem nas telas');
$check(!preg_match('/\b(FROM|JOIN|UPDATE|INTO)\s+colaboradores\b(?!_)/i', $repo . $servico) && !preg_match('/codigo_pessoa/i', $repo . $servico . $ctrlAdmin . $ctrlPublico), '(fonte) Só o espelho oficial: nunca `colaboradores` legado nem codigo_pessoa');
$check(!preg_match('/\b(cpf|nascimento|salario)\b/i', $repo . $servico . $ctrlAdmin . $ctrlPublico), '(privacidade) Nenhuma leitura/cópia de CPF, nascimento ou salário');
$check(!preg_match('/COUNT\s*\(\s*DISTINCT/i', $repo) && !preg_match('/\bNOW\s*\(\)/i', $repo), '(fonte) Sem COUNT(DISTINCT) e sem NOW() — o tempo entra por parâmetro');
$check(!preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(colaboradores_metadados|empresas|unidades|cargos|setores)\b/i', $repo), '(fonte) Nenhuma escrita no METADADOS nem nos catálogos oficiais');
$check(!preg_match('/ensureSchema|CREATE\s+TABLE|ALTER\s+TABLE/i', $repo . $servico . $ctrlPublico . $ctrlAdmin), '(banco) Sem ensureSchema/DDL em runtime — só migration');
$check(str_contains($servico, "hash('sha256', \$token)") && str_contains($servico, 'bin2hex(random_bytes(32))'), '(token) bin2hex(random_bytes(32)) + SHA-256; o bruto só existe no retorno');
$check(str_contains($repo, 'respondida_em IS NULL') && str_contains($repo, 'rowCount()') && str_contains($repo, 'beginTransaction'), '(reenvio) UPDATE condicional (respondida_em IS NULL) com rowCount() em transação');

$layout = (string)file_get_contents(APP_PATH . '/views/layouts/publico-seguro.php');
$viewPublica = (string)file_get_contents(APP_PATH . '/views/entrevista_desligamento/publica.php');
$check(str_contains($layout, '<meta name="referrer" content="no-referrer">') && str_contains($layout, 'noindex') && str_contains($ctrlPublico, "'Referrer-Policy: no-referrer'") && str_contains($ctrlPublico, 'no-store'), '(segurança) Referrer-Policy no-referrer (cabeçalho + <meta>), noindex e Cache-Control: no-store');
$check(!preg_match('#https?://|googleapis|gstatic|cdn\.|<script|@import#i', $layout . $viewPublica), '(segurança) Layout e página públicos sem Google Fonts, CDN, script nem URL de terceiros');
$check(!preg_match('/\son[a-z]+\s*=/i', $layout . $viewPublica . (string)file_get_contents(APP_PATH . '/views/admin/entrevista_desligamento/index.php') . (string)file_get_contents(APP_PATH . '/views/admin/entrevista_desligamento/resultado.php')), '(CSP) Sem handlers inline (onclick/onsubmit…) nas novas telas');
$check(!preg_match('/style\s*=\s*"/i', $layout . $viewPublica), '(CSP) Página pública sem estilo inline (compatível com style-src \'self\')');
$check(str_contains($ctrlPublico, 'Security::rateLimitCheck') && str_contains($ctrlPublico, 'RL_MAX = 10') && str_contains($ctrlPublico, 'RL_JANELA = 600') && str_contains($ctrlPublico, 'RL_BLOQUEIO = 900'), '(rate limit) 10 tentativas com token inexistente por IP em 10 min; bloqueio de 15 min');

$check(substr_count($viewPublica, 'Muito insatisfeito') === 1 && preg_match("/escala'\] === 'satisfacao'\): \?>\s*<p[^>]*>1 = Muito insatisfeito/", $viewPublica) === 1, '(escala) A legenda de satisfação aparece uma única vez na view pública, dentro do ramo de Experiência');
$check(!str_contains($viewPublica, 'cultura_pratica_valores') && !str_contains((string)file_get_contents(APP_PATH . '/views/admin/entrevista_desligamento/resultado.php'), 'cultura_pratica_valores') && !str_contains($servico . $repo, 'cultura_pratica_valores'), '(cultura) Nenhuma referência ao campo textual removido no código nem nas views');
$migracao = (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-21-entrevista-desligamento.sql');
$check(!str_contains($migracao, 'cultura_pratica_valores'), '(migration) Sem coluna para a pergunta contextual de valores');
$migracaoSql = preg_replace('/^--.*$/m', '', $migracao);
$check(str_contains($migracaoSql, 'UNIQUE KEY uk_entrevistas_desligamento_contrato (metadados_id)') && str_contains($migracaoSql, 'UNIQUE KEY uk_entrevistas_desligamento_token (token_hash)') && str_contains($migracaoSql, 'CREATE TABLE IF NOT EXISTS entrevistas_desligamento_fatores'), '(migration) UNIQUE por contrato e por token_hash; fatores em tabela filha');
$check(!preg_match('/\b(cpf|nascimento|salario|token\b(?!_hash))/i', $migracaoSql) && !preg_match('/\bJSON\b/i', $migracaoSql), '(migration) Sem CPF/nascimento/salário, sem token bruto e sem JSON para dados centrais');
$check(is_file(BASE_PATH . '/database/migrations/2026-09-21-entrevista-desligamento-rollback.sql') && str_contains((string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-21-entrevista-desligamento-rollback.sql'), 'DROP TABLE IF EXISTS entrevistas_desligamento_fatores'), '(migration) Rollback correspondente existe');
$seed = preg_replace('/^--.*$/m', '', (string)file_get_contents(BASE_PATH . '/database/migrations/2026-09-21-entrevista-desligamento-permissoes-seed.sql'));
$check(str_contains($seed, 'INSERT IGNORE INTO permissoes') && !preg_match('/usuario_permissoes|CREATE|ALTER|DROP/i', $seed) && str_contains($seed, "'entrevista_desligamento.visualizar'") && str_contains($seed, ", 620, 1)") && str_contains($seed, ", 630, 1)") && str_contains($seed, ", 640, 1);"), '(seed) Só cadastra as 3 permissões (620/630/640) — não concede a ninguém');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_ENTREVISTA_DESLIGAMENTO_OK\n";
