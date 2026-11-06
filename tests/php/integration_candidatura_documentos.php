<?php

/**
 * Integração — Documentos de contratação anexados à candidatura (correção de 2026-11, pedido do
 * RH, migration 2026-11-05-candidatura-documentos.sql). Múltiplos arquivos por candidatura,
 * preservados mesmo em outras etapas, nunca o registro original apagado por engano.
 *
 * Upload::saveDocumento() depende de move_uploaded_file()/is_uploaded_file(), que só funciona em
 * um upload HTTP real — fora do alcance de um teste CLI (mesma limitação, já existente no
 * projeto, de Upload::savePdf()/saveImage(): nenhum dos dois tem teste do caminho de sucesso real
 * de gravação em disco). Por isso este teste prova em profundidade:
 *   - as rejeições de Upload::saveDocumento() que ocorrem ANTES de move_uploaded_file() (erro de
 *     upload, tamanho, mime) — essas são 100% reais e exercitáveis em CLI;
 *   - CandidaturaDocumento::create()/find()/allByCandidatura() (camada de banco, sem Upload);
 *   - por inspeção de código (mesmo padrão de integration_entrevista_desligamento.php/
 *     integration_comunicacoes_candidato.php para `Auth::requireRole`/permissão), que
 *     uploadDocumento()/downloadDocumento() exigem admin/rh + CSRF, e que downloadDocumento()
 *     nunca serve um documento de outra candidatura nem escapa do diretório de storage — mesma
 *     garantia já confiada (sem teste direto, pela mesma limitação de exit()) ao download() de
 *     currículo existente.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

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

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['vagas' => [], 'candidaturas' => [], 'documentos' => []];

$corpoDe = static function (string $classe, string $metodo): string {
    $r = new ReflectionMethod($classe, $metodo);
    $arq = new SplFileObject($r->getFileName());
    $arq->seek($r->getStartLine() - 1);
    $corpo = '';
    while ($arq->key() < $r->getEndLine()) {
        $corpo .= $arq->current();
        $arq->next();
    }
    return $corpo;
};

try {
    // ---- Upload::saveDocumento() — rejeições anteriores a move_uploaded_file() -----------------
    $erroUpload = false;
    try {
        Upload::saveDocumento(['name' => 'x.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
    } catch (\RuntimeException $e) { $erroUpload = true; }
    $check($erroUpload, '(validação) Erro de upload (sem arquivo) é rejeitado antes de qualquer gravação');

    $tmpGrande = tempnam(sys_get_temp_dir(), 'zzdoc');
    file_put_contents($tmpGrande, str_repeat('A', 1024));
    $erroTamanho = false;
    try {
        Upload::saveDocumento(['name' => 'grande.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpGrande, 'error' => UPLOAD_ERR_OK, 'size' => 999999999]);
    } catch (\RuntimeException $e) { $erroTamanho = true; }
    @unlink($tmpGrande);
    $check($erroTamanho, '(validação) Tamanho acima do limite configurado é rejeitado');

    $tmpTexto = tempnam(sys_get_temp_dir(), 'zzdoc');
    file_put_contents($tmpTexto, 'isto não é um PDF nem imagem, é texto puro');
    $erroMime = false;
    try {
        Upload::saveDocumento(['name' => 'arquivo.txt', 'type' => 'text/plain', 'tmp_name' => $tmpTexto, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpTexto)]);
    } catch (\RuntimeException $e) { $erroMime = true; }
    @unlink($tmpTexto);
    $check($erroMime, '(validação) Mimetype real (finfo) fora da whitelist (texto puro) é rejeitado, nunca confiando só na extensão/Content-Type declarado');

    // ---- config dedicada, sem afetar allowed_upload_mime (currículo) ---------------------------
    $cfg = Config::app()['security'];
    $check(($cfg['allowed_document_mime'] ?? []) === ['application/pdf', 'image/png', 'image/jpeg'], '(config) allowed_document_mime é uma chave própria (PDF + imagem), separada de allowed_upload_mime');
    $check(($cfg['allowed_upload_mime'] ?? []) === ['application/pdf'], '(config) allowed_upload_mime (currículo) continua só PDF — não foi alargada por engano');

    // ---- CandidaturaDocumento (camada de banco) -------------------------------------------------
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZDOC Vaga {$suffix}"]);
    $vagaId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaId;

    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'novo')")
        ->execute([$vagaId, 'ZZDOC Candidato', 'zzdoc' . $suffix . '@teste.local', '11999999999', '12345678901', 'Cargo X', 'exp', 'x.pdf']);
    $candidaturaId = (int)$pdo->lastInsertId();
    $criados['candidaturas'][] = $candidaturaId;

    $usuarioId = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $check($usuarioId > 0, '(pré-condição) Existe ao menos um usuário admin local');

    $docId1 = CandidaturaDocumento::create([
        'candidatura_id' => $candidaturaId, 'nome_original' => 'RG.pdf', 'nome_armazenado' => 'zzdoc1.pdf',
        'mime' => 'application/pdf', 'tamanho_bytes' => 12345, 'enviado_por_usuario_id' => $usuarioId,
    ]);
    $criados['documentos'][] = $docId1;
    $docId2 = CandidaturaDocumento::create([
        'candidatura_id' => $candidaturaId, 'nome_original' => 'CPF.jpg', 'nome_armazenado' => 'zzdoc2.jpg',
        'mime' => 'image/jpeg', 'tamanho_bytes' => 54321, 'enviado_por_usuario_id' => $usuarioId,
    ]);
    $criados['documentos'][] = $docId2;

    $lista = CandidaturaDocumento::allByCandidatura($candidaturaId);
    $check(count($lista) === 2, '(listagem) allByCandidatura() traz os 2 documentos anexados');
    $nomesOrdenados = array_column($lista, 'nome_original');
    sort($nomesOrdenados);
    $check($nomesOrdenados === ['CPF.jpg', 'RG.pdf'], '(listagem) Traz exatamente os 2 documentos anexados, pelo nome original (ordem de criado_em não é garantida com timestamps iguais no mesmo segundo)');
    $check($lista[0]['enviado_por_nome'] !== null, '(auditoria) Nome do usuário responsável pelo upload é exposto (JOIN usuarios)');

    $achado = CandidaturaDocumento::find($docId1);
    $check($achado !== null && $achado['nome_original'] === 'RG.pdf' && (int)$achado['candidatura_id'] === $candidaturaId, '(find) Recupera o documento certo pelo id');
    $check(CandidaturaDocumento::find(2147483000) === null, '(find) Id inexistente devolve null, nunca erro');

    // Preservação: nenhuma outra candidatura foi afetada e o registro nunca é sobrescrito por
    // outro upload — cada chamada cria uma linha nova.
    $docId3 = CandidaturaDocumento::create([
        'candidatura_id' => $candidaturaId, 'nome_original' => 'Comprovante.pdf', 'nome_armazenado' => 'zzdoc3.pdf',
        'mime' => 'application/pdf', 'tamanho_bytes' => 111, 'enviado_por_usuario_id' => $usuarioId,
    ]);
    $criados['documentos'][] = $docId3;
    $check(count(CandidaturaDocumento::allByCandidatura($candidaturaId)) === 3, '(preservação) Um terceiro upload soma, nunca substitui os anteriores');

    // ---- controle de acesso e segurança — inspeção de código (Auth::requireRole chama exit();
    // invocar de verdade mataria o processo de teste, mesma limitação já aceita no projeto para
    // o download() de currículo, sem teste direto) --------------------------------------------
    $corpoUpload = $corpoDe(AdminCandidaturasController::class, 'uploadDocumento');
    $corpoDownload = $corpoDe(AdminCandidaturasController::class, 'downloadDocumento');
    $check(str_contains($corpoUpload, "Auth::requireRole(['admin', 'rh'])"), '(acesso) uploadDocumento() exige admin/rh — mesmo padrão do resto do módulo, sem regra de setor nova (decisão registrada na correção)');
    $check(str_contains($corpoUpload, 'Security::csrfCheck'), '(segurança) uploadDocumento() verifica CSRF (é POST)');
    $check(str_contains($corpoDownload, "Auth::requireRole(['admin', 'rh'])"), '(acesso) downloadDocumento() exige admin/rh');
    $check(str_contains($corpoDownload, "(int)\$documento['candidatura_id'] !== (int)\$id"), '(segurança) downloadDocumento() nunca confia só no id do documento — confere que ele pertence à candidatura da URL antes de servir');
    $check(str_contains($corpoDownload, 'realpath') && str_contains($corpoDownload, 'strpos($real, $realDir)'), '(segurança) downloadDocumento() usa o mesmo guard de path traversal (realpath + strpos) já confiado no download() de currículo');
    $check(str_contains($corpoDownload, 'basename('), '(segurança) O nome do arquivo em disco é sempre passado por basename() antes de montar o caminho');

    // ---- layout em mini cards (correção de 2026-11, homologação) --------------------------------
    // downloadDocumento() agora aceita ?inline=1 para visualização (miniatura/abrir no navegador)
    // pelo MESMO endpoint autorizado — nunca um endpoint novo/público, e nunca sem o MIME já
    // validado contra allowed_document_mime no upload (nunca HTML/SVG/script).
    $check(str_contains($corpoDownload, "\$_GET['inline']") && str_contains($corpoDownload, "'inline'") && str_contains($corpoDownload, "'attachment'"), '(visualização) downloadDocumento() decide inline/attachment pelo mesmo endpoint autorizado, sem criar rota nova');

    $corpoShow = file_get_contents(__DIR__ . '/../../app/views/admin/candidaturas/show.php');
    $check(str_contains($corpoShow, 'grid-cols-2') && str_contains($corpoShow, 'sm:grid-cols-3') && str_contains($corpoShow, 'md:grid-cols-4') && str_contains($corpoShow, 'lg:grid-cols-6'), '(layout) Seção de Documentos usa grade responsiva (mais colunas conforme a largura da tela), nunca card de largura total');
    $check(str_contains($corpoShow, 'aspect-square'), '(layout) Mini cards são quadrados');
    $check(str_contains($corpoShow, 'truncate'), '(layout) Nome de arquivo longo é truncado sem quebrar o layout do card');
    $check(str_contains($corpoShow, "\$ehImagem") && str_contains($corpoShow, "'image/png', 'image/jpeg'"), '(layout) PNG/JPG recebem miniatura real (img); os demais tipos (PDF) recebem o ícone');
    $check(str_contains($corpoShow, '?inline=1') && str_contains($corpoShow, 'Visualizar') && str_contains($corpoShow, 'Baixar'), '(layout) Cada card tem ação de Visualizar (inline) e Baixar (attachment), reusando o endpoint de documentos');

    echo "\nCANDIDATURA_DOCUMENTOS_OK\n";
} finally {
    if (!empty($criados['documentos'])) {
        $ph = implode(',', array_fill(0, count($criados['documentos']), '?'));
        $pdo->prepare("DELETE FROM candidatura_documentos WHERE id IN ($ph)")->execute($criados['documentos']);
    }
    if (!empty($criados['candidaturas'])) {
        $ph = implode(',', array_fill(0, count($criados['candidaturas']), '?'));
        $pdo->prepare("DELETE FROM candidaturas WHERE id IN ($ph)")->execute($criados['candidaturas']);
    }
    if (!empty($criados['vagas'])) {
        $ph = implode(',', array_fill(0, count($criados['vagas']), '?'));
        $pdo->prepare("DELETE FROM vagas WHERE id IN ($ph)")->execute($criados['vagas']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nCANDIDATURA_DOCUMENTOS_OK\n";
