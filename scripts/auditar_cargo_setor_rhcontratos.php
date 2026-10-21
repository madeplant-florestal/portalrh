<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Auditoria SOMENTE LEITURA — correção 2026-10 (MANUTENÇÃO/setor 12): cruza o extrato de
 * RHCONTRATOS (scripts/extrair_cargo_setor_rhcontratos.php) contra o catálogo LOCAL de
 * `cargos`/`setores` (CatalogoMetadadosRepository, mesma comparação EXATA por código já usada em
 * todo o Portal — `findByCodigo()` nunca normaliza nada) para decidir, ANTES de materializar, se
 * `CargoSetorMetadadosRepository::materializarPorCodigos()` resolveria corretamente os 76 pares.
 *
 * Nenhum INSERT/UPDATE/DELETE em lugar nenhum. Nunca conecta ao SQL Server (lê só o JSON já
 * extraído). Nunca normaliza silenciosamente um código "047" para "0047" — só REPORTA se existe
 * (ou não) uma correspondência exata e, separadamente, se existiria uma correspondência caso o
 * código fosse completado com zeros à esquerda até 4 dígitos (comparação, nunca substituição).
 *
 * Uso:  php scripts/auditar_cargo_setor_rhcontratos.php [caminho/para/pares.json]
 *       (sem argumento: usa o arquivo mais recente em storage/imports/cargo-setor-rhcontratos-*.json)
 */

function localizarJsonMaisRecente(): ?string
{
    $arquivos = glob(STORAGE_PATH . '/imports/cargo-setor-rhcontratos-*.json') ?: [];
    if ($arquivos === []) {
        return null;
    }
    usort($arquivos, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $arquivos[0];
}

function normalizarQuatroDigitos(string $codigo): ?string
{
    // Comparação EXPLÍCITA só para fins de relatório — nunca usada para gravar nada. Só se aplica
    // a códigos puramente numéricos com 1 a 3 dígitos (é exatamente o padrão dos "suspeitos" desta
    // auditoria: 047/048/049/053 vs. o padrão de 4 dígitos do resto do extrato).
    if (!ctype_digit($codigo) || strlen($codigo) >= 4) {
        return null;
    }
    return str_pad($codigo, 4, '0', STR_PAD_LEFT);
}

$caminho = $argv[1] ?? localizarJsonMaisRecente();
if ($caminho === null || !is_file($caminho)) {
    fwrite(STDERR, 'Uso: php scripts/auditar_cargo_setor_rhcontratos.php [arquivo.json]' . PHP_EOL);
    fwrite(STDERR, 'Nenhum storage/imports/cargo-setor-rhcontratos-*.json encontrado e nenhum caminho informado.' . PHP_EOL);
    exit(1);
}

$conteudo = file_get_contents($caminho);
$pares = $conteudo !== false ? json_decode($conteudo, true) : null;
if (!is_array($pares)) {
    fwrite(STDERR, "Arquivo inválido: {$caminho}" . PHP_EOL);
    exit(1);
}

$pdo = Database::conn();
$cargosRepo = new CatalogoMetadadosRepository('cargos', $pdo);
$setoresRepo = new CatalogoMetadadosRepository('setores', $pdo);

// ---- 1) duplicidades exatas dentro do próprio extrato -------------------------------------------
$contagemPares = [];
foreach ($pares as $p) {
    $chave = ($p['codigo_cargo'] ?? '') . '|' . ($p['codigo_setor'] ?? '');
    $contagemPares[$chave] = ($contagemPares[$chave] ?? 0) + 1;
}
$duplicados = array_filter($contagemPares, static fn (int $n): bool => $n > 1);

// ---- 2) cargos em múltiplos setores (puramente a partir do próprio extrato, sem catálogo) ------
$setoresPorCargo = [];
foreach ($pares as $p) {
    $cc = (string)($p['codigo_cargo'] ?? '');
    $cs = (string)($p['codigo_setor'] ?? '');
    if ($cc === '' || $cs === '') {
        continue;
    }
    $setoresPorCargo[$cc][$cs] = true;
}
$cargosMultiSetor = [];
foreach ($setoresPorCargo as $cc => $setoresSet) {
    if (count($setoresSet) > 1) {
        $cargosMultiSetor[$cc] = array_keys($setoresSet);
    }
}

// ---- 3) cruzamento contra o catálogo local (correspondência EXATA, nunca normalizada) -----------
$auditoriaPorPar = [];
$semCorrespondenciaCargo = [];
$semCorrespondenciaSetor = [];
$resolvidosSoComZero = 0;
$resolvidosExato = 0;
$naoResolvidos = 0;

$cacheCargo = [];
$buscarCargo = static function (string $codigo) use ($cargosRepo, &$cacheCargo): ?array {
    if (!array_key_exists($codigo, $cacheCargo)) {
        $cacheCargo[$codigo] = $cargosRepo->findByCodigo($codigo);
    }
    return $cacheCargo[$codigo];
};
$cacheSetor = [];
$buscarSetor = static function (string $codigo) use ($setoresRepo, &$cacheSetor): ?array {
    if (!array_key_exists($codigo, $cacheSetor)) {
        $cacheSetor[$codigo] = $setoresRepo->findByCodigo($codigo);
    }
    return $cacheSetor[$codigo];
};

foreach ($pares as $p) {
    $cc = trim((string)($p['codigo_cargo'] ?? ''));
    $cs = trim((string)($p['codigo_setor'] ?? ''));
    if ($cc === '' || $cs === '') {
        continue;
    }

    $cargoExato = $buscarCargo($cc);
    $setorExato = $buscarSetor($cs);

    $cargoViaZero = null;
    $codigoCargoNormalizado = normalizarQuatroDigitos($cc);
    if ($cargoExato === null && $codigoCargoNormalizado !== null) {
        $cargoViaZero = $buscarCargo($codigoCargoNormalizado);
    }

    $situacao = 'SEM_CORRESPONDENCIA';
    if ($cargoExato !== null && $setorExato !== null) {
        $situacao = 'OK';
        $resolvidosExato++;
    } elseif ($cargoViaZero !== null && $setorExato !== null) {
        $situacao = 'OK_SOMENTE_COM_ZERO_A_ESQUERDA';
        $resolvidosSoComZero++;
    } else {
        $naoResolvidos++;
        if ($cargoExato === null && $cargoViaZero === null) {
            $semCorrespondenciaCargo[$cc] = true;
        }
        if ($setorExato === null) {
            $semCorrespondenciaSetor[$cs] = true;
        }
    }

    $auditoriaPorPar[] = [
        'codigo_cargo_rhcontratos' => $cc,
        'codigo_setor_rhcontratos' => $cs,
        'cargo_nome' => $cargoExato['descricao_oficial'] ?? $cargoExato['nome'] ?? ($cargoViaZero['descricao_oficial'] ?? $cargoViaZero['nome'] ?? null),
        'cargo_codigo_catalogo_exato' => $cargoExato['codigo_cargo'] ?? null,
        'cargo_codigo_catalogo_via_zero' => $cargoViaZero['codigo_cargo'] ?? null,
        'setor_nome' => $setorExato['descricao_oficial'] ?? $setorExato['nome'] ?? null,
        'setor_codigo_catalogo' => $setorExato['codigo_setor'] ?? null,
        'situacao' => $situacao,
    ];
}

// ---- 4) foco: setor 12 (MANUTENÇÃO) --------------------------------------------------------------
$linhasSetor12 = array_values(array_filter($auditoriaPorPar, static fn (array $l): bool => $l['codigo_setor_rhcontratos'] === '12'));
$setor12Info = $buscarSetor('12');

// ---- 5) foco: códigos suspeitos (fora do padrão de 4 dígitos) ------------------------------------
$codigosSuspeitos = ['047', '048', '049', '053'];
$auditoriaSuspeitos = [];
foreach ($codigosSuspeitos as $codigoSuspeito) {
    $exato = $buscarCargo($codigoSuspeito);
    $normalizado = normalizarQuatroDigitos($codigoSuspeito);
    $viaZero = $normalizado !== null ? $buscarCargo($normalizado) : null;
    $setoresOndeAparece = array_keys($setoresPorCargo[$codigoSuspeito] ?? []);
    $auditoriaSuspeitos[] = [
        'codigo_rhcontratos' => $codigoSuspeito,
        'aparece_no_extrato' => $setoresOndeAparece !== [],
        'setores_onde_aparece' => $setoresOndeAparece,
        'correspondencia_exata' => $exato !== null ? ['codigo' => $exato['codigo_cargo'], 'nome' => $exato['descricao_oficial'] ?: $exato['nome']] : null,
        'codigo_normalizado_4_digitos' => $normalizado,
        'correspondencia_via_zero_a_esquerda' => $viaZero !== null ? ['codigo' => $viaZero['codigo_cargo'], 'nome' => $viaZero['descricao_oficial'] ?: $viaZero['nome']] : null,
    ];
}

// ---- resumo geral ---------------------------------------------------------------------------------
$resumo = [
    'arquivo_auditado' => $caminho,
    'combinacoes_no_extrato' => count($pares),
    'codigos_cargo_distintos' => count($setoresPorCargo),
    'setores_distintos' => count(array_unique(array_column($pares, 'codigo_setor'))),
    'resolvidas_exato' => $resolvidosExato,
    'resolvidas_somente_com_zero_a_esquerda' => $resolvidosSoComZero,
    'nao_resolvidas' => $naoResolvidos,
    'duplicidades_exatas_no_extrato' => count($duplicados),
    'cargos_em_multiplos_setores' => count($cargosMultiSetor),
    'codigos_cargo_sem_correspondencia' => array_keys($semCorrespondenciaCargo),
    'codigos_setor_sem_correspondencia' => array_keys($semCorrespondenciaSetor),
];

$veredito = ($naoResolvidos === 0) ? 'SEGURO PARA MATERIALIZAR' : 'MATERIALIZAÇÃO BLOQUEADA';
$motivosBloqueio = [];
if ($naoResolvidos > 0) {
    $motivosBloqueio[] = "{$naoResolvidos} par(es) de {$resumo['combinacoes_no_extrato']} não encontram correspondência exata (nem mesmo com zero à esquerda) no catálogo local — materializarPorCodigos() os contaria como 'sem_correspondencia_local', nenhum erro, mas NENHUM desses vínculos entraria na matriz.";
}
if ($resolvidosSoComZero > 0) {
    $motivosBloqueio[] = "{$resolvidosSoComZero} par(es) só resolvem SE o código do RHCONTRATOS for completado com zero à esquerda (047->0047 etc.) — materializarPorCodigos() hoje faz comparação EXATA e não aplicaria esse ajuste; esses pares também ficariam como 'sem_correspondencia_local' enquanto a normalização não for decidida e implementada.";
}

$saida = [
    'resumo' => $resumo,
    'setor_12_manutencao' => [
        'encontrado_no_catalogo' => $setor12Info !== null,
        'nome_catalogo' => $setor12Info['descricao_oficial'] ?? $setor12Info['nome'] ?? null,
        'total_cargos_no_extrato' => count($linhasSetor12),
        'cargos' => $linhasSetor12,
    ],
    'codigos_suspeitos' => $auditoriaSuspeitos,
    'duplicidades_exatas' => $duplicados,
    'cargos_em_multiplos_setores' => $cargosMultiSetor,
    'todas_as_linhas' => $auditoriaPorPar,
    'veredito' => $veredito,
    'motivos_bloqueio' => $motivosBloqueio,
];

$destino = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
    . 'auditoria-cargo-setor-' . date('Ymd-His') . '.json';
@file_put_contents($destino, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
fwrite(STDERR, 'Relatório salvo em: ' . $destino . PHP_EOL);
fwrite(STDERR, 'VEREDITO: ' . $veredito . PHP_EOL);
