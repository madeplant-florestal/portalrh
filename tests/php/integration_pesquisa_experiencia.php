<?php

/**
 * Integração — Sprint "Experiência do Candidato" (migration 2026-09-16-pesquisa-experiencia.sql
 * + PesquisaExperiencia + PesquisaExperienciaService + página pública + permissão
 * pesquisa_experiencia.visualizar).
 *
 * Prova que:
 *   - o token público é seguro (aleatório, longo, não sequencial, não derivado de CPF/ID/telefone)
 *     e só o HASH fica gravado no banco;
 *   - `token_hash` é UNIQUE;
 *   - criação é idempotente por participação (1 pesquisa por `candidatura_id`, nunca duplica);
 *   - notas fora de 1–5 são rejeitadas (0, 6, etc.);
 *   - segunda resposta ao mesmo token é rejeitada;
 *   - o template `pesquisa_experiencia_candidato` existe e `[Link da Pesquisa]` é reconhecida;
 *   - o catálogo passa a ter exatamente 10 variáveis;
 *   - a página pública não expõe dados internos do candidato;
 *   - `pesquisa_experiencia.visualizar` segue o mesmo mecanismo central de `Authorization`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

PesquisaExperiencia::ensureSchema();
Mensagem::ensureSchema();
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

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$mk = 'ZZPESQ_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => [], 'pesquisas' => [], 'candidaturas' => [], 'vagas' => []];

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

try {
    // ---- fixture: Vaga + Candidatura ------------------------------------------------------------
    $cpf = str_pad((string)random_int(10000000000, 99999999999), 11, '0', STR_PAD_LEFT);
    $cpf2 = str_pad((string)random_int(10000000000, 99999999999), 11, '0', STR_PAD_LEFT);
    $vagaId = Vaga::create([
        'titulo' => 'Vaga Pesquisa ' . $suffix,
        'descricao' => 'Fixture de teste de pesquisa de experiência.',
        'requisitos' => 'Requisito de fixture.',
        'area' => 'RH',
        'local' => 'Remoto',
        'ativo' => 1,
    ]);
    $criados['vagas'][] = $vagaId;

    $candidaturaId = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Maria Teste Pesquisa',
        'email' => 'pesq_' . str_replace('-', '_', $suffix) . '@rhmadeplant.local',
        'telefone' => '67999999999',
        'cpf' => $cpf,
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Fixture de pesquisa.',
        'pdf_path' => 'fixture-pesquisa.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $criados['candidaturas'][] = $candidaturaId;

    $candidaturaId2 = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Outro Candidato Pesquisa',
        'email' => 'pesq2_' . str_replace('-', '_', $suffix) . '@rhmadeplant.local',
        'telefone' => '67988888888',
        'cpf' => $cpf2,
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Fixture de pesquisa 2.',
        'pdf_path' => 'fixture-pesquisa-2.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $criados['candidaturas'][] = $candidaturaId2;

    // ---- 1/2. token seguro, não sequencial, UNIQUE ------------------------------------------------
    $r1 = PesquisaExperienciaService::criarParaParticipacao($candidaturaId);
    $check($r1['ja_existia'] === false, 'primeira chamada cria a pesquisa');
    $check(is_string($r1['token']) && strlen($r1['token']) === 64, 'token bruto tem 64 caracteres (32 bytes em hex) — suficientemente longo');
    $check(ctype_xdigit($r1['token']), 'token é hexadecimal (aleatório via random_bytes, criptograficamente seguro)');
    $check(!str_contains($r1['token'], (string)$candidaturaId) || strlen((string)$candidaturaId) <= 2, 'token não é derivado trivialmente do ID da candidatura (checagem best-effort)');
    $check(!str_contains($r1['token'], $cpf), 'token não contém o CPF do candidato');
    $criados['pesquisas'][] = (int)$r1['pesquisa']['id'];

    // Só o HASH fica no banco — nunca o token bruto.
    $rowNoBanco = PesquisaExperiencia::find((int)$r1['pesquisa']['id']);
    $check(!str_contains(json_encode($rowNoBanco), $r1['token']), 'o token BRUTO nunca é persistido no banco — só o hash (mesmo padrão de PasswordReset)');
    $check(strlen($rowNoBanco['token_hash']) === 64, 'token_hash gravado é um sha256 em hex (64 chars)');

    $idxToken = $pdo->query("SHOW INDEX FROM pesquisas_experiencia WHERE Key_name = 'uk_pesquisas_experiencia_token'")->fetchAll(PDO::FETCH_ASSOC);
    $check($idxToken !== [], 'token_hash é UNIQUE a nível de banco');

    // ---- 3/4. criação idempotente por participação — nunca duplica -----------------------------
    $r2 = PesquisaExperienciaService::criarParaParticipacao($candidaturaId);
    $check($r2['ja_existia'] === true, 'segunda chamada para a MESMA participação retorna a pesquisa existente (idempotente)');
    $check((int)$r2['pesquisa']['id'] === (int)$r1['pesquisa']['id'], 'nenhuma pesquisa nova foi criada — mesmo ID retornado');
    $totalParaCandidatura = (int)$pdo->query("SELECT COUNT(*) FROM pesquisas_experiencia WHERE candidatura_id = {$candidaturaId}")->fetchColumn();
    $check($totalParaCandidatura === 1, 'exatamente 1 pesquisa por participação, mesmo após chamadas repetidas');

    // Outra participação (outro processo) pode ter sua PRÓPRIA pesquisa.
    $r3 = PesquisaExperienciaService::criarParaParticipacao($candidaturaId2);
    $check($r3['ja_existia'] === false, 'outra participação (outro processo) cria sua própria pesquisa, sem conflito');
    $criados['pesquisas'][] = (int)$r3['pesquisa']['id'];
    $check((int)$r3['pesquisa']['id'] !== (int)$r1['pesquisa']['id'], 'pesquisas de participações diferentes têm IDs diferentes');

    // ---- 5/6/7. notas 1–5; zero e seis são rejeitados ---------------------------------------------
    $pesquisaId = (int)$r1['pesquisa']['id'];
    $zero = PesquisaExperiencia::responder($pesquisaId, 0, 3, 3, null);
    $check(($zero['ok'] ?? true) === false, 'nota 0 é rejeitada');
    $seis = PesquisaExperiencia::responder($pesquisaId, 6, 3, 3, null);
    $check(($seis['ok'] ?? true) === false, 'nota 6 é rejeitada');
    $check(PesquisaExperiencia::find($pesquisaId)['respondida_em'] === null, 'tentativas inválidas não marcam a pesquisa como respondida');

    // ---- 8/9. comentário opcional; resposta válida é gravada --------------------------------------
    $semComentario = PesquisaExperiencia::responder($pesquisaId, 5, 4, 5, null);
    $check(($semComentario['ok'] ?? false) === true, 'resposta válida sem comentário (opcional) é aceita');
    $gravada = PesquisaExperiencia::find($pesquisaId);
    $check((int)$gravada['nota_clareza'] === 5 && (int)$gravada['nota_tempo_retorno'] === 4 && (int)$gravada['nota_atendimento'] === 5, 'as 3 notas são gravadas separadamente, sem virar uma nota única/global');
    $check($gravada['respondida_em'] !== null, 'respondida_em é preenchido ao gravar a resposta');

    // ---- 10. segunda resposta é rejeitada ---------------------------------------------------------
    $segunda = PesquisaExperiencia::responder($pesquisaId, 1, 1, 1, 'tentativa de responder de novo');
    $check(($segunda['ok'] ?? true) === false, 'segunda resposta ao mesmo token/pesquisa é rejeitada');
    $check((int)PesquisaExperiencia::find($pesquisaId)['nota_clareza'] === 5, 'a nota original permanece intacta após a tentativa de resposta duplicada');

    // ---- 11/12. página pública não exige login, não expõe dados internos -----------------------
    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $reflexao = new ReflectionMethod($classe, $metodo);
        $arquivo = new SplFileObject($reflexao->getFileName());
        $arquivo->seek($reflexao->getStartLine() - 1);
        $corpo = '';
        while ($arquivo->key() < $reflexao->getEndLine()) {
            $corpo .= $arquivo->current();
            $arquivo->next();
        }
        return $corpo;
    };
    $corpoShow = $corpoDoMetodo(PesquisaExperienciaController::class, 'show');
    $check(!str_contains($corpoShow, 'Auth::requireRole'), 'PesquisaExperienciaController::show() não exige login (rota pública)');
    $check(!str_contains($corpoShow, "'nome'") && !str_contains($corpoShow, "'cpf'") && !str_contains($corpoShow, "'telefone'") && !str_contains($corpoShow, "'email'"), 'show() não repassa nome/CPF/telefone/e-mail do candidato para a view pública');

    $conteudoViewPublica = (string)file_get_contents(__DIR__ . '/../../app/views/pesquisa_experiencia/show.php');
    foreach (['cpf', 'telefone', 'e-mail', 'email', "\$c['nome']", 'vaga_titulo'] as $termoSensivel) {
        $check(!str_contains($conteudoViewPublica, $termoSensivel), "view pública não referencia '{$termoSensivel}' (nenhum dado interno do candidato/vaga exposto)");
    }

    // ---- 13/14/15. template + variável + catálogo com 10 variáveis --------------------------------
    $templatePesquisa = Mensagem::findByCodigo('pesquisa_experiencia_candidato');
    $check($templatePesquisa !== null, "template 'pesquisa_experiencia_candidato' existe");
    $check(MensagemService::isPlaceholderReconhecido('Link da Pesquisa') === true, "'[Link da Pesquisa]' é uma variável reconhecida pelo catálogo oficial");
    $check(count(MensagemService::catalogoVariaveis()) === 10, 'catálogo de variáveis passou de 9 para exatamente 10');

    // ---- 16. mensagem ativa aceita [Link da Pesquisa] sem ser rejeitada por placeholder desconhecido
    if ($templatePesquisa !== null) {
        $check((int)$templatePesquisa['ativo'] === 1, "template 'pesquisa_experiencia_candidato' está ativo");
        $reativar = Mensagem::update((int)$templatePesquisa['id'], [
            'titulo' => $templatePesquisa['titulo'],
            'descricao' => $templatePesquisa['descricao'],
            'conteudo' => $templatePesquisa['conteudo'],
            'ativo' => 1,
        ]);
        $check(($reativar['ok'] ?? false) === true, 'salvar o template pesquisa_experiencia_candidato ATIVO com [Link da Pesquisa] não é rejeitado (variável reconhecida)');
    }

    // ---- 17. usuário sem pesquisa_experiencia.visualizar não acessa o resultado administrativo ---
    $adminId = $mkUser('admin', 'admin');
    $rhId = $mkUser('rh', 'rh');
    $gestorId = $mkUser('gestor', 'viewer');
    $check(Authorization::usuarioTemPermissao($adminId, 'pesquisa_experiencia.visualizar') === true, 'Admin acessa o resultado da pesquisa pelo bypass central');
    $check(Authorization::usuarioTemPermissao($rhId, 'pesquisa_experiencia.visualizar') === false, 'RH sem permissão individual NÃO acessa o resultado só por role=rh');
    $check(Authorization::usuarioTemPermissao($gestorId, 'pesquisa_experiencia.visualizar') === false, 'usuário sem a permissão não acessa o resultado administrativo da pesquisa');

    $corpoShowCandidatura = $corpoDoMetodo(AdminCandidaturasController::class, 'show');
    $check(str_contains($corpoShowCandidatura, "Authorization::temPermissao('pesquisa_experiencia.visualizar')"), 'AdminCandidaturasController::show() checa pesquisa_experiencia.visualizar no backend antes de buscar o resultado');

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nPESQUISA_EXPERIENCIA_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['pesquisas'] as $id) {
        $pdo->prepare('DELETE FROM pesquisas_experiencia WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['candidaturas'] as $id) {
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['vagas'] as $id) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
