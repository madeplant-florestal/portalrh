<?php

/**
 * Integração — Sprint "Módulo de Mensagens do Processo Seletivo"
 * (migration 2026-09-16-mensagens.sql + Mensagem + MensagemService + permissões mensagens.*).
 *
 * Prova que:
 *   - `codigo` é único (UNIQUE + rejeição na aplicação);
 *   - o seed dos 7 templates + 3 permissões é idempotente;
 *   - os 7 templates iniciais existem com o texto fornecido preservado (emoji, multiline);
 *   - `MensagemService` detecta e renderiza placeholders `[Nome]`, reporta pendências sem
 *     silenciá-las, e só resolve mensagens ATIVAS;
 *   - as 3 permissões (`mensagens.visualizar/criar/editar`) seguem o mesmo mecanismo central de
 *     `Authorization` da sprint anterior — Admin por bypass, RH sem acesso automático por role,
 *     sincronização na Tela de Usuários funcionando.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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

$mk = 'ZZMSG_' . substr(md5(uniqid('', true)), 0, 8);
$emailMk = strtolower($mk) . '@teste.local';
$criados = ['usuarios' => [], 'mensagens' => []];

$senha = password_hash('irrelevante', PASSWORD_BCRYPT);
$mkUser = static function (string $sufixo, string $role) use ($emailMk, $senha, &$criados): int {
    $id = User::create('USR ' . $sufixo, str_replace('@', "+{$sufixo}@", $emailMk), $senha, $role);
    User::setActiveStatus($id, true);
    $criados['usuarios'][] = $id;
    return $id;
};

$aplicarSql = static function (string $caminho) use ($pdo): void {
    $sql = file_get_contents($caminho);
    $semComentarios = implode("\n", array_filter(
        explode("\n", (string)$sql),
        static fn (string $linha): bool => !str_starts_with(trim($linha), '--')
    ));
    foreach (array_filter(array_map('trim', explode(';', $semComentarios))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
};

try {
    $caminhoMigration = __DIR__ . '/../../database/migrations/2026-09-16-mensagens.sql';
    $caminhoSeed = __DIR__ . '/../../database/migrations/2026-09-16-mensagens-seed.sql';
    $check(is_file($caminhoMigration), 'migration 2026-09-16-mensagens.sql existe');
    $check(is_file($caminhoSeed), 'seed 2026-09-16-mensagens-seed.sql existe');
    $aplicarSql($caminhoMigration);
    $aplicarSql($caminhoSeed);

    // ---- 1. Seed idempotente: reexecutar não duplica -------------------------------------------
    $totalAntes = (int)$pdo->query('SELECT COUNT(*) FROM mensagens')->fetchColumn();
    $aplicarSql($caminhoSeed);
    $totalDepois = (int)$pdo->query('SELECT COUNT(*) FROM mensagens')->fetchColumn();
    $check($totalAntes === $totalDepois, 'seed de mensagens é idempotente (reexecutar não duplica linhas)');

    $totalPermAntes = (int)$pdo->query("SELECT COUNT(*) FROM permissoes WHERE modulo = 'mensagens'")->fetchColumn();
    $aplicarSql($caminhoSeed);
    $totalPermDepois = (int)$pdo->query("SELECT COUNT(*) FROM permissoes WHERE modulo = 'mensagens'")->fetchColumn();
    $check($totalPermAntes === 3 && $totalPermDepois === 3, 'seed das 3 permissões de mensagens é idempotente');

    // ---- 2. Os 7 templates iniciais existem ------------------------------------------------------
    $codigosEsperados = [
        'reprovacao_triagem', 'reprovacao_apos_entrevista', 'banco_talentos',
        'convocacao_entrevista_rh', 'convocacao_entrevista_gestor',
        'aprovacao_carta_proposta', 'agendamento_exame_admissional',
    ];
    foreach ($codigosEsperados as $codigo) {
        $check(Mensagem::findByCodigo($codigo) !== null, "template inicial '{$codigo}' existe");
    }
    $check(count(Mensagem::all()) >= 7, 'ao menos os 7 templates iniciais estão cadastrados');

    // Conteúdo com emoji e multiline preservado exatamente (checagem literal, não aproximada).
    $rh = Mensagem::findByCodigo('convocacao_entrevista_rh');
    $check(str_contains((string)$rh['conteudo'], "📅 Data: [Data]\n🕒 Horário: [Horário]"), 'emoji + quebra de linha do template convocacao_entrevista_rh preservados literalmente');
    $bancoTalentos = Mensagem::findByCodigo('banco_talentos');
    $check(str_contains((string)$bancoTalentos['conteudo'], "Olá, [Nome].\n\nAgradecemos"), 'linha em branco interna do template banco_talentos preservada (multiline exato)');

    // ---- 3. codigo é único (UNIQUE + validação na aplicação) -------------------------------------
    $dup = Mensagem::create(['codigo' => 'reprovacao_triagem', 'titulo' => 'Duplicata', 'conteudo' => 'x']);
    $check(($dup['ok'] ?? true) === false, 'Mensagem::create() rejeita código técnico já existente');

    $mkMk = 'zz_teste_' . strtolower(substr($mk, -8));
    $criado = Mensagem::create(['codigo' => $mkMk, 'titulo' => 'Teste [Nome]', 'conteudo' => 'Olá [Nome], hoje é [Data].', 'ativo' => 1]);
    $check(($criado['ok'] ?? false) === true, 'Mensagem::create() aceita código técnico novo e válido');
    $novoId = (int)($criado['id'] ?? 0);
    $criados['mensagens'][] = $novoId;

    $dupNaBase = Mensagem::create(['codigo' => $mkMk, 'titulo' => 'Outra', 'conteudo' => 'x']);
    $check(($dupNaBase['ok'] ?? true) === false, 'segunda tentativa com o mesmo código (agora já persistido) também é rejeitada');

    $codigoInvalido = Mensagem::create(['codigo' => 'Código Com Espaço', 'titulo' => 'x', 'conteudo' => 'x']);
    $check(($codigoInvalido['ok'] ?? true) === false, 'código técnico com formato inválido (maiúscula/espaço) é rejeitado');

    // update() nunca aceita alterar o código — nem recebe o campo.
    $antesCodigo = Mensagem::find($novoId)['codigo'];
    Mensagem::update($novoId, ['codigo' => 'tentativa_de_mudar', 'titulo' => 'Teste atualizado', 'conteudo' => 'Novo conteúdo [Nome]', 'ativo' => 1]);
    $depoisCodigo = Mensagem::find($novoId)['codigo'];
    $check($antesCodigo === $depoisCodigo, 'update() nunca altera o código técnico, mesmo se um chamador tentasse enviá-lo');

    // ---- 4/5. Detecção de placeholder(s) ----------------------------------------------------------
    $check(MensagemService::detectarPlaceholders('Olá, [Nome].') === ['Nome'], "'[Nome]' é detectado corretamente");
    $multiplos = MensagemService::detectarPlaceholders('[Nome] tem entrevista em [Data] às [Horário] com [Responsável].');
    $check($multiplos === ['Nome', 'Data', 'Horário', 'Responsável'], 'múltiplos placeholders são detectados, na ordem de aparição, sem duplicar repetições');
    $check(MensagemService::detectarPlaceholders('Texto sem nenhum placeholder.') === [], 'texto sem placeholder retorna lista vazia');

    // ---- 6. Renderização substitui corretamente as variáveis --------------------------------------
    $render = MensagemService::renderizarConteudo('Olá, [Nome]. Sua entrevista é [Data].', ['Nome' => 'Ana', 'Data' => '20/09/2026']);
    $check($render['texto'] === 'Olá, Ana. Sua entrevista é 20/09/2026.', 'renderizarConteudo() substitui corretamente múltiplas variáveis');
    $check($render['placeholders_pendentes'] === [], 'nenhum placeholder pendente quando todos os valores são informados');

    // ---- 7. Placeholder ausente é reportado, NUNCA silenciado -------------------------------------
    $renderIncompleto = MensagemService::renderizarConteudo('Olá, [Nome]. Local: [Local ou Link].', ['Nome' => 'Ana']);
    $check($renderIncompleto['placeholders_pendentes'] === ['Local ou Link'], "'[Local ou Link]' sem valor é reportado em placeholders_pendentes");
    $check(str_contains($renderIncompleto['texto'], '[Local ou Link]'), 'placeholder sem valor permanece visível no texto final — nunca é apagado/silenciado');

    // ---- 8/9. Emoji e multiline preservados na renderização (não só no armazenamento) ------------
    $renderEmoji = MensagemService::renderizarConteudo("📅 Data: [Data]\n🕒 Horário: [Horário]", ['Data' => '20/09', 'Horário' => '14h']);
    $check($renderEmoji['texto'] === "📅 Data: 20/09\n🕒 Horário: 14h", 'renderização preserva emoji e quebra de linha do template');

    // ---- 10. Mensagem inativa não é usada para renderização operacional --------------------------
    Mensagem::update($novoId, ['titulo' => 'Inativa de teste', 'conteudo' => 'Olá [Nome].', 'ativo' => 0]);
    $check(Mensagem::findAtivaByCodigo($mkMk) === null, 'findAtivaByCodigo() não retorna mensagem inativa');
    $renderInativa = MensagemService::renderizar($mkMk, ['Nome' => 'Ana']);
    $check(($renderInativa['ok'] ?? true) === false, 'MensagemService::renderizar() recusa renderizar operacionalmente uma mensagem inativa');
    $check($renderInativa['texto'] === null, 'renderizar() de mensagem inativa não retorna texto nenhum (nada é enviado)');

    // ---- 11-15. Permissões individuais (mesmo mecanismo Authorization da sprint anterior) -------
    $adminId = $mkUser('admin', 'admin');
    $rhId = $mkUser('rh', 'rh');
    $gestorId = $mkUser('gestor', 'viewer');

    $check(Authorization::usuarioTemPermissao($adminId, 'mensagens.visualizar') === true, '(14) Admin acessa Mensagens pelo bypass central');
    $check(Authorization::usuarioTemPermissao($adminId, 'mensagens.criar') === true, 'Admin também tem mensagens.criar pelo bypass central');
    $check(Authorization::usuarioTemPermissao($adminId, 'mensagens.editar') === true, 'Admin também tem mensagens.editar pelo bypass central');

    $check(Authorization::usuarioTemPermissao($rhId, 'mensagens.visualizar') === false, '(15) RH sem permissão individual NÃO acessa a listagem só por role=rh');
    $check(Authorization::usuarioTemPermissao($rhId, 'mensagens.criar') === false, 'RH sem permissão individual não cadastra');
    $check(Authorization::usuarioTemPermissao($rhId, 'mensagens.editar') === false, 'RH sem permissão individual não edita');

    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.visualizar') === false, '(11) usuário sem mensagens.visualizar não acessa a listagem');
    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.criar') === false, '(12) usuário sem mensagens.criar não cadastra');
    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.editar') === false, '(13) usuário sem mensagens.editar não altera');

    // Fonte da verdade do gate real do controller: index()/create()/edit() chamam
    // Authorization::requirePermissao() com o código certo — mesmo padrão de inspeção de
    // código-fonte usado em integration_permissoes_individuais.php.
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
    $check(str_contains($corpoDoMetodo(AdminMensagensController::class, 'index'), "Authorization::requirePermissao('mensagens.visualizar')"), "index() do controller de Mensagens exige mensagens.visualizar no backend");
    $check(str_contains($corpoDoMetodo(AdminMensagensController::class, 'create'), "Authorization::requirePermissao('mensagens.criar')"), "create() do controller de Mensagens exige mensagens.criar no backend");
    $check(str_contains($corpoDoMetodo(AdminMensagensController::class, 'store'), "Authorization::requirePermissao('mensagens.criar')"), "store() do controller de Mensagens exige mensagens.criar no backend");
    $check(str_contains($corpoDoMetodo(AdminMensagensController::class, 'edit'), "Authorization::requirePermissao('mensagens.editar')"), "edit() do controller de Mensagens exige mensagens.editar no backend");
    $check(str_contains($corpoDoMetodo(AdminMensagensController::class, 'update'), "Authorization::requirePermissao('mensagens.editar')"), "update() do controller de Mensagens exige mensagens.editar no backend");

    // ---- 16. Sincronização das novas permissões na Tela de Usuários funciona normalmente --------
    $idsPermissao = $pdo->query('SELECT id, codigo FROM permissoes')->fetchAll(PDO::FETCH_KEY_PAIR);
    $idVisualizar = (int)array_search('mensagens.visualizar', $idsPermissao, true);
    $idCriar = (int)array_search('mensagens.criar', $idsPermissao, true);
    $check($idVisualizar > 0 && $idCriar > 0, 'IDs das permissões de mensagens resolvidos no catálogo');

    $sync = Authorization::sincronizar($gestorId, [$idVisualizar, $idCriar]);
    $check(($sync['ok'] ?? false) === true && (int)($sync['total'] ?? 0) === 2, 'Authorization::sincronizar() concede mensagens.visualizar + mensagens.criar ao Gestor');
    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.visualizar') === true, 'após sincronizar(), Gestor passa a acessar a listagem');
    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.criar') === true, 'após sincronizar(), Gestor passa a poder cadastrar');
    $check(Authorization::usuarioTemPermissao($gestorId, 'mensagens.editar') === false, 'sincronizar() não concede mensagens.editar (não estava na lista enviada) — sem contaminação entre permissões');

    $catalogo = Authorization::catalogoPorModulo();
    $check(isset($catalogo['mensagens']) && count($catalogo['mensagens']) === 3, "catalogoPorModulo() inclui o módulo 'mensagens' com as 3 permissões");

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nMENSAGENS_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['mensagens'] as $id) {
        $pdo->prepare('DELETE FROM mensagens WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
