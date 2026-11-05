<?php

/**
 * Integração — comentário por dimensão na Entrevista de Desligamento (correção de 2026-11, pedido
 * do RH, migration 2026-11-05-entrevista-desligamento-comentarios-secao.sql). Cada uma das 5
 * dimensões (Experiência na empresa, Liderança, Cultura e Valores, Integração e Desenvolvimento,
 * Experiência Geral) tem seu próprio comentário em texto livre, opcional.
 *
 * Fixtures ZZEDC-* no espelho `colaboradores_metadados`, com limpeza em `finally`. Este módulo é
 * deliberadamente "migration only" (sem ensureSchema/DDL em runtime, ver
 * unit_entrevista_desligamento_service.php) — por isso este teste exige que a migration
 * 2026-11-05-entrevista-desligamento-comentarios-secao.sql já tenha sido aplicada ao banco antes
 * de rodar (mesma exigência de qualquer outro teste deste módulo).
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
$criados = ['metadados' => []];
$agora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
$D = static fn(int $dias): string => (new DateTimeImmutable('today'))->modify('-' . $dias . ' days')->format('Y-m-d');

$mk = static function (string $nome) use ($pdo, &$criados, $suffix, $D): int {
    static $seq = 0;
    $seq++;
    $identificador = 'ZZEDC_' . $suffix . '_' . $seq;
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados (
            identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf, nome, empresa, unidade,
            cargo, codigo_cargo, admissao, demissao, motivo_rescisao_codigo, motivo_rescisao_descricao, ativo, origem_metadados
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $identificador, 'ZE' . $suffix, 'ZU' . $suffix, 'ZC' . $suffix . $seq, 'ZP' . $suffix . $seq, '12345678900',
        'ZZEDC ' . $nome . ' ' . $suffix, 'ZZEDC Empresa ' . $suffix, 'ZZEDC Unidade', 'ZZEDC Cargo', 'ZZEDCCG' . $suffix,
        $D(1000), $D(9), '003', 'ZZEDC motivo', 0, 'zzedc-teste',
    ]);
    $criados['metadados'][] = $identificador;
    return (int)$pdo->lastInsertId();
};

try {
    $gerId = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $check($gerId > 0, '(pré-condição) Existe ao menos um usuário admin local para gerar o link');

    $repo = new EntrevistaDesligamentoRepository();
    $service = new EntrevistaDesligamentoService($repo);

    $postBase = static function (array $extra = []): array {
        $post = ['motivo_principal' => 'lideranca', 'experiencia_geral' => '7', 'enps' => '9', 'fatores' => [],
            'motivo_descricao' => '', 'aberta_continuar' => '', 'aberta_melhorar' => '', 'aberta_mensagem' => ''];
        foreach (EntrevistaDesligamentoService::SECOES_ESCALA as $secao) {
            foreach (array_keys($secao['itens']) as $campo) {
                $post[$campo] = '4';
            }
        }
        return $extra + $post;
    };

    // ---- caminho feliz: os 5 comentários são gravados e associados à dimensão correta ----------
    $c1 = $mk('Preenchido');
    $g1 = $service->gerar($c1, $gerId, $agora);
    $check($g1['ok'] === true, '(gerar) Contrato elegível gera o link');

    $comentarios = [
        'exp_comentario' => 'Comentário sobre experiência na empresa',
        'lid_comentario' => 'Comentário sobre liderança',
        'cul_comentario' => 'Comentário sobre cultura e valores',
        'int_comentario' => 'Comentário sobre integração e desenvolvimento',
        'experiencia_geral_comentario' => 'Comentário sobre experiência geral',
    ];
    $r1 = $service->registrarResposta((string)$g1['token'], $postBase($comentarios), $agora);
    $check(($r1['ok'] ?? false) === true, '(registrar) Submissão com os 5 comentários preenchidos é aceita');

    $stmt = $pdo->prepare('SELECT exp_comentario, lid_comentario, cul_comentario, int_comentario, experiencia_geral_comentario FROM entrevistas_desligamento WHERE metadados_id = ?');
    $stmt->execute([$c1]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    foreach ($comentarios as $coluna => $texto) {
        $check(($linha[$coluna] ?? null) === $texto, "(persistência) Coluna {$coluna} grava exatamente o texto da sua própria dimensão (nunca misturado com outra seção)");
    }

    // ---- opcional: sem nenhum comentário, a submissão continua válida e as colunas ficam NULL ---
    $c2 = $mk('Vazio');
    $g2 = $service->gerar($c2, $gerId, $agora);
    $r2 = $service->registrarResposta((string)$g2['token'], $postBase([
        'exp_comentario' => '', 'lid_comentario' => '', 'cul_comentario' => '', 'int_comentario' => '', 'experiencia_geral_comentario' => '',
    ]), $agora);
    $check(($r2['ok'] ?? false) === true, '(opcional) Submissão sem nenhum comentário preenchido continua válida (campo opcional, não bloqueia)');
    $stmt->execute([$c2]);
    $linhaVazia = $stmt->fetch(PDO::FETCH_ASSOC);
    foreach (array_keys($comentarios) as $coluna) {
        $check($linhaVazia[$coluna] === null, "(opcional) {$coluna} vazio é gravado como NULL, nunca string vazia");
    }

    // ---- rejeição: a seção de escala continua obrigatória mesmo com os comentários preenchidos --
    $c3 = $mk('Incompleto');
    $g3 = $service->gerar($c3, $gerId, $agora);
    $postIncompleto = $postBase($comentarios);
    unset($postIncompleto['lid_respeito']); // falta uma nota obrigatória da escala
    $r3 = $service->registrarResposta((string)$g3['token'], $postIncompleto, $agora);
    $check(($r3['ok'] ?? true) === false, '(rejeição) Comentário preenchido não substitui a nota de escala obrigatória — continua rejeitando');

    // ---- whitelist: as 5 colunas fazem parte de COLUNAS_RESPOSTA (nunca gravadas por acidente) --
    foreach (array_keys($comentarios) as $coluna) {
        $check(in_array($coluna, EntrevistaDesligamentoRepository::COLUNAS_RESPOSTA, true), "(whitelist) {$coluna} está em COLUNAS_RESPOSTA");
    }

    echo "\nENTREVISTA_DESLIGAMENTO_COMENTARIOS_SECAO_OK\n";
} finally {
    if (!empty($criados['metadados'])) {
        $placeholders = implode(',', array_fill(0, count($criados['metadados']), '?'));
        $ids = $pdo->prepare("SELECT id FROM colaboradores_metadados WHERE identificador IN ($placeholders)");
        $ids->execute($criados['metadados']);
        $metadadosIds = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($metadadosIds !== []) {
            $ph2 = implode(',', array_fill(0, count($metadadosIds), '?'));
            $pdo->prepare("DELETE FROM entrevistas_desligamento WHERE metadados_id IN ($ph2)")->execute($metadadosIds);
        }
        $pdo->prepare("DELETE FROM colaboradores_metadados WHERE identificador IN ($placeholders)")->execute($criados['metadados']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nENTREVISTA_DESLIGAMENTO_COMENTARIOS_SECAO_OK\n";
