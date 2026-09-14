<?php

/**
 * Integração — investigação "BUG 1: Fabiane recebe os Setores do Fabio" + "BUG 2: vínculo
 * METADADOS da Fabiane não persiste" (relato de produção, 2026-09-14).
 *
 * Resultado da investigação: não foi possível reproduzir nenhum dos dois bugs em código —
 * `SolicitacaoVaga::contextoOrganizacionalSolicitante($usuarioId)` sempre lê exclusivamente pelo
 * `$usuarioId` recebido (nunca pelo ator/sessão autenticada), confirmado tanto no nível do model
 * quanto via requisição HTTP real (login + sessão) contra o endpoint
 * `/admin/solicitacoes-vaga/solicitante-contexto/{usuarioId}`. `User::vincularMetadados()` também
 * persiste corretamente em releitura fresca, inclusive quando o usuário já tem um contexto MANUAL
 * extenso pré-existente (o cenário real da Fabiane: Setor principal + vários adicionais definidos
 * ANTES do vínculo).
 *
 * Os cenários abaixo travam esse comportamento correto como regressão — para nunca mais depender
 * só de inspeção manual caso o sintoma reapareça (nesse caso, a causa está fora deste código:
 * página/asset desatualizado no navegador, ou anomalia específica do dado de produção).
 *
 *   A: ator = solicitante (Fabio) -> contexto = exatamente o do Fabio.
 *   B: ator (Fabio) escolhe outro solicitante (Fabiane) -> contexto = exatamente o da Fabiane,
 *      nunca o do Fabio (mesmo Fabio permanecendo o ator/sessão autenticada).
 *   C: alternância na mesma "sessão lógica" Fabio -> Fabiane -> Fabio: cada chamada devolve o
 *      contexto de quem foi pedido, sem resíduo do usuário anterior (função pura, sem estado
 *      compartilhado entre chamadas).
 *   D: vínculo METADADOS aplicado a um usuário que JÁ tinha contexto MANUAL rico (Setor principal
 *      + 5 adicionais, como a Fabiane) — após o vínculo, releitura fresca confirma
 *      `colaborador_metadados_id` persistido, Cargo/Setor principal herdados e TRAVADOS, e os
 *      adicionais MANUAIS preservados.
 *
 * Sem rollback de transação (create()/vincularMetadados() têm transação própria) — fixtures
 * marcadas e removidas no finally, como os demais testes de Solicitação de Vaga/Usuários.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_solicitante_contexto_isolamento (MySQL indisponivel)\n";
    exit(0);
}

SolicitacaoVaga::ensureSchema();
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

$mk = 'ZZISO_' . substr(md5(uniqid('', true)), 0, 6);
$sfx = strtolower($mk);
$criados = ['usuarios' => [], 'setores' => [], 'cargos' => [], 'metadados' => []];

try {
    $svc = new UsuarioContextoOrganizacionalService();
    $senha = password_hash('irrelevante', PASSWORD_BCRYPT);

    $novoSetor = static function (string $tag) use ($pdo, $sfx, &$criados): int {
        $codigo = 'Z' . strtoupper(substr(md5($tag . $sfx), 0, 6));
        $pdo->prepare('INSERT INTO setores (codigo_setor, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, 'RHMADEPLANT']);
        $id = (int)$pdo->lastInsertId();
        $criados['setores'][] = $id;
        return $id;
    };
    $novoCargo = static function (string $tag) use ($pdo, $sfx, &$criados): int {
        $codigo = 'Z' . strtoupper(substr(md5('cargo-' . $tag . $sfx), 0, 6));
        $pdo->prepare('INSERT INTO cargos (codigo_cargo, nome, slug, ativo, origem_metadados) VALUES (?,?,?,1,?)')
            ->execute([$codigo, strtoupper($tag) . ' ' . $sfx, $tag . '-' . $sfx, 'RHMADEPLANT']);
        $id = (int)$pdo->lastInsertId();
        $criados['cargos'][] = $id;
        return $id;
    };
    $novoUsuario = static function (string $tag, string $role) use ($sfx, $senha, &$criados): int {
        $id = User::create('USR ' . $tag . ' ' . strtoupper($sfx), "{$sfx}+{$tag}@teste.local", $senha, $role);
        User::setActiveStatus($id, true);
        $criados['usuarios'][] = $id;
        return $id;
    };

    $setorTI = $novoSetor('ti');
    $setorContab = $novoSetor('contab');
    $setorControladoria = $novoSetor('controladoria');
    $setorFaturamento = $novoSetor('faturamento');
    $setorRH = $novoSetor('rh');
    $setoresExtras = [$novoSetor('facilities'), $novoSetor('financeiro'), $novoSetor('fiscal'), $novoSetor('logistica'), $novoSetor('manutencao'), $novoSetor('operacao'), $novoSetor('suprimentos'), $setorTI];

    $fabio = $novoUsuario('fabio', 'admin');
    $svc->definirContextoManual($fabio, null, $setorTI, [$setorContab, $setorControladoria, $setorFaturamento]);

    $fabiane = $novoUsuario('fabiane', 'rh');
    $svc->definirContextoManual($fabiane, null, $setorRH, $setoresExtras);

    // ---- A: ator = solicitante -> contexto do próprio ator -----------------
    $ctxFabio = SolicitacaoVaga::contextoOrganizacionalSolicitante($fabio);
    $idsFabio = array_column($ctxFabio['setores'], 'id');
    sort($idsFabio);
    $esperadoFabio = [$setorTI, $setorContab, $setorControladoria, $setorFaturamento];
    sort($esperadoFabio);
    $check($idsFabio === $esperadoFabio, 'A contexto do Fabio contém exatamente TI(principal)+CONTABILIDADE+CONTROLADORIA+FATURAMENTO');
    $check($ctxFabio['setores'][0]['id'] === $setorTI && $ctxFabio['setores'][0]['principal'] === true, 'A Setor principal do Fabio é TI');

    // ---- B: ator (Fabio) escolhe Fabiane como solicitante -------------------
    $ctxFabiane = SolicitacaoVaga::contextoOrganizacionalSolicitante($fabiane);
    $idsFabiane = array_column($ctxFabiane['setores'], 'id');
    sort($idsFabiane);
    $esperadoFabiane = array_merge([$setorRH], $setoresExtras);
    sort($esperadoFabiane);
    $check($idsFabiane === $esperadoFabiane, 'B contexto da Fabiane contém o principal RH + todos os adicionais dela');
    $check(
        !in_array($setorContab, $idsFabiane, true) && !in_array($setorControladoria, $idsFabiane, true) && !in_array($setorFaturamento, $idsFabiane, true),
        'B contexto da Fabiane NÃO contém CONTABILIDADE/CONTROLADORIA/FATURAMENTO — Setores exclusivos do Fabio'
    );
    $check($idsFabio !== $idsFabiane, 'B os dois conjuntos são diferentes entre si — nenhuma mistura');

    // formDependencies() com actor=Fabio e solicitante=Fabiane -> contexto deve ser o da Fabiane.
    $deps = SolicitacaoVaga::formDependencies($fabio, $fabiane);
    $idsDeps = array_column($deps['solicitante_contexto']['setores'], 'id');
    sort($idsDeps);
    $check($idsDeps === $esperadoFabiane, 'B formDependencies(ator=Fabio, solicitante=Fabiane) devolve o contexto da Fabiane, não o do ator');

    // ---- C: alternância Fabio -> Fabiane -> Fabio, sem resíduo --------------
    $volta1 = SolicitacaoVaga::contextoOrganizacionalSolicitante($fabio);
    $idsVolta1 = array_column($volta1['setores'], 'id');
    sort($idsVolta1);
    $check($idsVolta1 === $esperadoFabio, 'C ao voltar para Fabio depois de consultar Fabiane, contexto volta a ser exatamente o do Fabio (sem resíduo)');

    // ---- D: vínculo METADADOS sobre contexto MANUAL rico pré-existente ------
    $cargoRH = $novoCargo('coord-rh');
    $mkContrato = substr($sfx, -6);
    $pdo->prepare(
        'INSERT INTO colaboradores_metadados
            (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
             nome, empresa, unidade, setor, cargo, codigo_setor, codigo_cargo, ativo, origem_metadados)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,\'RHMADEPLANT\')'
    )->execute([
        'ID' . $mk, 'E' . $mkContrato, 'U1', 'K' . $mkContrato, 'P' . $mk,
        'CONTRATO FABIANE ' . $mk, 'EMP ' . $mk, 'UNID ' . $mk, 'SET ' . $mk, 'CRG ' . $mk,
        $pdo->query("SELECT codigo_setor FROM setores WHERE id = {$setorRH}")->fetchColumn(),
        $pdo->query("SELECT codigo_cargo FROM cargos WHERE id = {$cargoRH}")->fetchColumn(),
    ]);
    $contratoFabiane = (int)$pdo->lastInsertId();
    $criados['metadados'][] = $contratoFabiane;

    $antesLinhas = count($svc->setoresDoUsuario($fabiane));
    $resultado = User::vincularMetadados($fabiane, $contratoFabiane);
    $check(($resultado['ok'] ?? false) === true, 'D vínculo METADADOS da Fabiane (com contexto manual rico pré-existente) é aceito');

    // Releitura FRESCA — nova instância de User/Service, como um novo request faria.
    $fabianeFresca = User::findById($fabiane);
    $check($fabianeFresca !== null && $fabianeFresca->colaborador_metadados_id === $contratoFabiane, 'D colaborador_metadados_id persiste em releitura fresca (não volta a NULL)');

    $ctxDepois = (new UsuarioContextoOrganizacionalService())->contextoDoUsuario($fabiane);
    $check($ctxDepois['vinculado'] === true, 'D contextoDoUsuario() confirma vinculado=true após releitura fresca');
    $check($ctxDepois['cargo_id'] === $cargoRH && $ctxDepois['cargo_travado'] === true, 'D Cargo principal herdado do contrato e travado');
    $check($ctxDepois['setor_principal']['setor_id'] === $setorRH && $ctxDepois['setor_principal']['origem'] === 'METADADOS' && $ctxDepois['setor_principal_travado'] === true, 'D Setor principal promovido para origem METADADOS e travado');
    $check(count($ctxDepois['setores_adicionais']) === $antesLinhas - 1, 'D todos os setores adicionais MANUAIS pré-existentes foram preservados (só o principal mudou de origem)');
    foreach ($ctxDepois['setores_adicionais'] as $adicional) {
        $check($adicional['origem'] === 'MANUAL', 'D adicional setor_id=' . $adicional['setor_id'] . ' continua origem MANUAL');
    }

    if ($falhas !== []) {
        throw new RuntimeException(count($falhas) . ' verificação(ões) falharam.');
    }
    echo "\nSOLICITANTE_CONTEXTO_ISOLAMENTO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ($criados['metadados'] as $id) {
        $pdo->prepare('DELETE FROM colaboradores_metadados WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['usuarios'] as $id) {
        $pdo->prepare('DELETE FROM usuario_setores WHERE usuario_id = ?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['cargos'] as $id) {
        $pdo->prepare('DELETE FROM cargos WHERE id = ?')->execute([(int)$id]);
    }
    foreach ($criados['setores'] as $id) {
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([(int)$id]);
    }
}

exit($exit ?? 0);
