<?php

/**
 * Fixture (NÃO é teste): cria/limpa, no banco de DESENVOLVIMENTO, os dados ZZRS* do teste de navegador da leitura setorial de Recrutamento
 * (tests/recrutamento-leitura-setorial.spec.js): setores, vagas, candidaturas e usuários (Gestor com/sem permissão/sem setores, RH, Viewer).
 * Uso: php fixture_recrutamento_navegador.php criar <prefixo>   → imprime JSON com e-mails/senha/ids/nomes
 *      php fixture_recrutamento_navegador.php limpar <prefixo>
 * Só roda com ambiente de teste (ResiduosTeste::garantirAmbienteDeTeste) e só toca em registros do prefixo.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once __DIR__ . '/lib/ResiduosTeste.php';

[, $acao, $prefixo] = $argv + ['', '', ''];
if (!preg_match('/^zzrs[a-z0-9]{4,12}$/', $prefixo)) {
    fwrite(STDERR, "prefixo inválido\n");
    exit(2);
}
$pdo = Database::conn();
ResiduosTeste::garantirAmbienteDeTeste($pdo);

if ($acao === 'limpar') {
    $st = $pdo->prepare('SELECT id FROM vagas WHERE titulo LIKE ?');
    $st->execute(["ZZRS %{$prefixo}%"]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM candidaturas WHERE vaga_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$id]);
    }
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email LIKE ?');
    $st->execute([$prefixo . '.%@teste.local']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auditoria_usuarios WHERE target_usuario_id = ? OR actor_usuario_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    }
    $pdo->prepare("DELETE FROM setores WHERE slug LIKE ? AND origem_metadados = 'zz-teste'")->execute([$prefixo . '-%']);
    echo "ok\n";
    exit(0);
}

$senha = 'Nav!Teste#2026x';
$hash = password_hash($senha, PASSWORD_BCRYPT);
$mkUser = static function (string $suf, string $role, array $perms = [], array $setoresIds = []) use ($prefixo, $hash, $pdo): int {
    $id = User::create('ZZRS ' . $suf, "{$prefixo}.{$suf}@teste.local", $hash, $role);
    User::setActiveStatus($id, true);
    $pdo->prepare('UPDATE usuarios SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')->execute([$id]);
    foreach ($perms as $c) {
        $pdo->prepare('INSERT INTO usuario_permissoes (usuario_id, permissao_id) SELECT ?, id FROM permissoes WHERE codigo = ?')->execute([$id, $c]);
    }
    foreach ($setoresIds as $s) {
        $pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$id, $s]);
    }
    return $id;
};
$mkSetor = static function (string $suf) use ($pdo, $prefixo): int {
    $codigo = substr(strtoupper($suf[0]) . strtoupper(substr(md5($prefixo . $suf), 0, 5)), 0, 8);
    $pdo->prepare("INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?, ?, ?, 1, 'zz-teste')")->execute([$codigo, "ZZRS Setor{$suf} {$prefixo}", "{$prefixo}-{$suf}"]);
    return (int)$pdo->lastInsertId();
};
$mkVaga = static function (string $suf, ?int $setor) use ($pdo, $prefixo): int {
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo, setor_id) VALUES (?, 'd', 'r', 'ZZ', 'l', 1, ?)")->execute(["ZZRS Vaga{$suf} {$prefixo}", $setor]);
    return (int)$pdo->lastInsertId();
};
$stage = (int)$pdo->query("SELECT id FROM pipeline_stages WHERE slug = 'triagem-rh'")->fetchColumn();
$mkCand = static function (int $vagaId, string $nome) use ($pdo, $prefixo, $stage): string {
    $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $nome)) . "@{$prefixo}.teste.local";
    $pdo->prepare("INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, stage_id) VALUES (?, ?, ?, '11987654321', ?, 'CargoSecretoZZ', 'ExperienciaSecretaZZ', 'zz.pdf', ?)")
        ->execute([$vagaId, $nome, $email, (string)random_int(10000000000, 99999999999), $stage]);
    return $email;
};

$s1 = $mkSetor('1'); $s2 = $mkSetor('2'); $s3 = $mkSetor('3');
$v1 = $mkVaga('1', $s1); $v2 = $mkVaga('2', $s2); $v3 = $mkVaga('3', $s3); $vSem = $mkVaga('Sem', null);
$emails = [$mkCand($v1, 'Alice Propria'), $mkCand($v2, 'Bruno Propria')];
for ($i = 1; $i <= 22; $i++) { $mkCand($v1, sprintf('Pagina%02d Propria', $i)); }
$emailsExternos = [$mkCand($v3, 'Carla Externa'), $mkCand($vSem, 'Dario SemSetor')];
$leitura = ['vagas.visualizar', 'candidaturas.visualizar'];
$ids = [
    'gestor' => $mkUser('gestor', 'gestor', $leitura, [$s1, $s2]),
    'gestorSemSetor' => $mkUser('gestorsemsetor', 'gestor', $leitura, []),
    'gestorSemPerm' => $mkUser('gestorsemperm', 'gestor', [], [$s1]),
    'rh' => $mkUser('rh', 'rh'),
    'viewer' => $mkUser('viewer', 'viewer', ['candidaturas.visualizar'], [$s1]),
];
echo json_encode([
    'senha' => $senha,
    'emails' => ['gestor' => "{$prefixo}.gestor@teste.local", 'gestorSemSetor' => "{$prefixo}.gestorsemsetor@teste.local", 'gestorSemPerm' => "{$prefixo}.gestorsemperm@teste.local", 'rh' => "{$prefixo}.rh@teste.local", 'viewer' => "{$prefixo}.viewer@teste.local"],
    'candidatoPropriaId' => (int)$pdo->query("SELECT id FROM candidaturas WHERE email = '" . $emails[0] . "'")->fetchColumn(),
    'candidatoExternoEmail' => $emailsExternos[0],
    'candidatoPropriaEmail' => $emails[0],
    'vagas' => ['v1' => "ZZRS Vaga1 {$prefixo}", 'v2' => "ZZRS Vaga2 {$prefixo}", 'v3' => "ZZRS Vaga3 {$prefixo}", 'vSem' => "ZZRS VagaSem {$prefixo}"],
    'vaga1Id' => $v1,
], JSON_UNESCAPED_UNICODE) . "\n";
