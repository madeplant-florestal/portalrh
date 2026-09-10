<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_catalogo_metadados_sync (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
$tabelaPorDimensao = ['setores' => 'setores', 'cargos' => 'cargos'];
$colPorDimensao = ['setores' => 'codigo_setor', 'cargos' => 'codigo_cargo'];

foreach ($tabelaPorDimensao as $dim => $tab) {
    $tem = (int)$pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tab}' AND COLUMN_NAME = '{$colPorDimensao[$dim]}'"
    )->fetchColumn();
    if ($tem === 0) {
        echo "SKIP integration_catalogo_metadados_sync (migration 2026-09-08-setores-cargos-metadados.sql nao aplicada)\n";
        exit(0);
    }
}

// ===== normalizeSourceRow: codigo string opaca, situacao bruta =====
$n = CatalogoMetadadosSyncService::normalizeSourceRow(['codigo' => '0120', 'descricao_oficial' => '  AUX ADMINISTRATIVO ', 'situacao_oficial' => 'A']);
$assert($n['codigo'] === '0120', 'normalize: codigo 0120 deve permanecer string "0120" (nao 120, nao int).');
$assert($n['codigo'] !== '120' && $n['codigo'] !== 120, 'normalize: nenhuma conversao numerica no codigo.');
$assert($n['descricao_oficial'] === 'AUX ADMINISTRATIVO', 'normalize: descricao trimada.');
$assert($n['situacao_oficial'] === 'A', 'normalize: situacao bruta preservada.');
$n2 = CatalogoMetadadosSyncService::normalizeSourceRow(['codigo' => 'X1', 'descricao_oficial' => 'Y', 'situacao_oficial' => '  ']);
$assert($n2['situacao_oficial'] === null, 'normalize: situacao so-espacos vira null.');

foreach (['setores', 'cargos'] as $dim) {
    $tab = $tabelaPorDimensao[$dim];
    $col = $colPorDimensao[$dim];
    $sfx = substr((string)time(), -5) . random_int(100, 999) . substr($dim, 0, 3);
    $repo = new CatalogoMetadadosRepository($dim, $pdo);
    $svc = new CatalogoMetadadosSyncService($dim, $repo);

    // Todo registro inserido por este teste tem nome 'ZZ CAT ...' e slug 'zz-cat-...'.
    $limpar = static function () use ($pdo, $tab): void {
        $pdo->exec("DELETE FROM {$tab} WHERE nome LIKE 'ZZ CAT %' OR slug LIKE 'zz-cat-%'");
    };
    $limpar();

    $itemPorCodigo = static function (array $plano, string $codigo): ?array {
        foreach ($plano['itens'] as $i) {
            if ($i['codigo'] === $codigo) {
                return $i;
            }
        }
        return null;
    };

    try {
        $codA = 'ZT1' . $sfx;   // <=8? nao — VARCHAR(8). Usar codigos curtos:
        $codA = 'Z' . substr($sfx, -6);          // 7 chars
        $codB = 'Y' . substr($sfx, -6);
        $codOpaco = '0' . substr($sfx, -3);      // ex.: 0123 — testa zero a esquerda
        $codAmb = 'W' . substr($sfx, -6);

        // fixture: um registro local que casa exato pelo nome, dois ambiguos, um "solto"
        $nomeMatch = 'ZZ CAT MATCH ' . $sfx;
        $extra = $dim === 'setores' ? ', empresa_id' : '';
        $extraVal = $dim === 'setores' ? ', NULL' : '';
        $pdo->exec("INSERT INTO {$tab} (nome, slug, ativo{$extra}) VALUES (" . $pdo->quote($nomeMatch) . ", " . $pdo->quote('zz-cat-match-' . $sfx) . ", 1{$extraVal})");
        $idMatch = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO {$tab} (nome, slug, ativo{$extra}) VALUES (" . $pdo->quote('ZZ CAT AMBIG ' . $sfx) . ", " . $pdo->quote('zz-cat-ambig-a-' . $sfx) . ", 1{$extraVal})");
        $pdo->exec("INSERT INTO {$tab} (nome, slug, ativo{$extra}) VALUES (" . $pdo->quote('zz cat ambig ' . $sfx . '!!') . ", " . $pdo->quote('zz-cat-ambig-b-' . $sfx) . ", 1{$extraVal})");
        $pdo->exec("INSERT INTO {$tab} (nome, slug, ativo{$extra}) VALUES (" . $pdo->quote('ZZ CAT SOLTO ' . $sfx) . ", " . $pdo->quote('zz-cat-solto-' . $sfx) . ", 0{$extraVal})");
        $idSolto = (int)$pdo->query("SELECT id FROM {$tab} WHERE slug = " . $pdo->quote('zz-cat-solto-' . $sfx))->fetchColumn();
        // registro legado que colide de NOME com a descricao oficial de um insert
        $nomeColisao = 'ZZ CAT COLISAO ' . $sfx;
        $pdo->exec("INSERT INTO {$tab} (" . $col . ", nome, slug, ativo{$extra}) VALUES (" . $pdo->quote('EXIST' . substr($sfx, -3)) . ", " . $pdo->quote($nomeColisao) . ", " . $pdo->quote('zz-cat-colisao-' . $sfx) . ", 1{$extraVal})");

        $fonte = [
            ['codigo' => $codA, 'descricao_oficial' => $nomeMatch, 'situacao_oficial' => 'A'],
            ['codigo' => $codB, 'descricao_oficial' => 'ZZ CAT SEM MATCH ' . $sfx, 'situacao_oficial' => 'A'],
            ['codigo' => $codOpaco, 'descricao_oficial' => 'ZZ CAT OPACO ' . $sfx, 'situacao_oficial' => 'D'],
            ['codigo' => $codAmb, 'descricao_oficial' => 'ZZ CAT AMBIG ' . $sfx, 'situacao_oficial' => 'A'],
            ['codigo' => $codA . 'C', 'descricao_oficial' => $nomeColisao, 'situacao_oficial' => 'A'],
        ];

        // --- 1) planejar() nao escreve ---
        $antes = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE({$col},'~'),':',nome ORDER BY id)) FROM {$tab}")->fetchColumn();
        $plano = $svc->planejar($fonte, 'RHMADEPLANT');
        $depois = $pdo->query("SELECT MD5(GROUP_CONCAT(id,':',COALESCE({$col},'~'),':',nome ORDER BY id)) FROM {$tab}")->fetchColumn();
        $assert($antes === $depois, "[{$dim}] planejar() nao pode alterar a tabela.");

        $assert($itemPorCodigo($plano, $codA)['acao'] === 'ADOTAR_EXISTENTE', "[{$dim}] match exato -> ADOTAR_EXISTENTE.");
        $assert($itemPorCodigo($plano, $codA)['local_id'] === $idMatch, "[{$dim}] adocao aponta o id local certo.");
        $assert($itemPorCodigo($plano, $codB)['acao'] === 'INSERIR_NOVA', "[{$dim}] sem match -> INSERIR_NOVA.");
        $assert($itemPorCodigo($plano, $codAmb)['acao'] === 'INSERIR_NOVA' && $itemPorCodigo($plano, $codAmb)['candidatos_ambiguos'] === 2, "[{$dim}] ambiguidade nunca adota.");
        $idsSemCorr = array_column($plano['locais_sem_correspondencia'], 'id');
        $assert(in_array($idSolto, $idsSemCorr, true), "[{$dim}] registro solto aparece como LOCAL_SEM_CORRESPONDENCIA_OFICIAL.");

        // --- 2) applyRows executa o plano ---
        $sum = $svc->applyRows($fonte, 'RHMADEPLANT');
        $assert($sum['adopted'] === 1, "[{$dim}] 1 adocao aplicada.");
        $assert($sum['inserted'] === 4, "[{$dim}] 4 insercoes (sem match + opaco + ambiguo + colisao de nome).");
        $assert($sum['errors'] === 0, "[{$dim}] sem erros.");

        // adocao preservou id/nome/slug/ativo
        $adotado = $repo->findByCodigo($codA);
        $assert((int)$adotado['id'] === $idMatch, "[{$dim}] adocao manteve o id.");
        $assert($adotado['nome'] === $nomeMatch, "[{$dim}] adocao nao alterou nome legado.");
        $assert($adotado['descricao_oficial'] === $nomeMatch && $adotado['situacao_metadados'] === 'A', "[{$dim}] descricao/situacao oficiais carimbadas.");

        // codigo opaco preservado literalmente
        $opaco = $repo->findByCodigo($codOpaco);
        $assert($opaco[$col] === $codOpaco, "[{$dim}] codigo opaco '{$codOpaco}' preservado exatamente (com zero a esquerda).");
        $assert($opaco['situacao_metadados'] === 'D', "[{$dim}] situacao 'D' bruta persistida.");

        // colisao de nome: novo registro recebe nome sufixado, descricao_oficial intacta
        $colisao = $repo->findByCodigo($codA . 'C');
        $assert($colisao['descricao_oficial'] === $nomeColisao, "[{$dim}] descricao_oficial NUNCA recebe sufixo de colisao.");
        $assert($colisao['nome'] !== $nomeColisao, "[{$dim}] nome do novo registro foi tornado unico (sufixo).");
        $assert(strpos($colisao['nome'], $codA . 'C') !== false, "[{$dim}] sufixo de nome usa o codigo.");

        // --- 3) idempotencia ---
        $sum2 = $svc->applyRows($fonte, 'RHMADEPLANT');
        $assert($sum2['unchanged'] === 5 && $sum2['inserted'] === 0 && $sum2['adopted'] === 0, "[{$dim}] reexecutar = tudo inalterado.");

        // --- 4) atualizacao: descricao muda -> ATUALIZAR_EXISTENTE ---
        $fonteUpd = $fonte;
        $fonteUpd[1]['descricao_oficial'] = 'ZZ CAT SEM MATCH ' . $sfx . ' V2';
        $sum3 = $svc->applyRows($fonteUpd, 'RHMADEPLANT');
        $assert($sum3['updated'] === 1, "[{$dim}] mudanca de descricao -> 1 update.");
        $assert($repo->findByCodigo($codB)['descricao_oficial'] === 'ZZ CAT SEM MATCH ' . $sfx . ' V2', "[{$dim}] nova descricao persistida.");
        // situacao muda -> update
        $fonteSit = $fonteUpd;
        $fonteSit[2]['situacao_oficial'] = 'A';
        $sum4 = $svc->applyRows($fonteSit, 'RHMADEPLANT');
        $assert($sum4['updated'] === 1 && $repo->findByCodigo($codOpaco)['situacao_metadados'] === 'A', "[{$dim}] mudanca de situacao bruta -> update.");

        // --- 5) plano == apply ---
        $planoV = $svc->planejar($fonteSit, 'RHMADEPLANT');
        $cont = [];
        foreach ($planoV['itens'] as $i) {
            $cont[$i['acao']] = ($cont[$i['acao']] ?? 0) + 1;
        }
        $assert(($cont['INALTERADA'] ?? 0) === 5, "[{$dim}] apos convergir, plano ve tudo INALTERADA.");

        // --- 6) linha invalida -> erro, nao aborta ---
        $sum5 = $svc->applyRows([
            ['codigo' => '', 'descricao_oficial' => 'ZZ CAT INVALIDA'],
            $fonteSit[0],
        ], 'RHMADEPLANT');
        $assert($sum5['errors'] === 1 && $sum5['unchanged'] === 1, "[{$dim}] codigo vazio = 1 erro, resto segue.");

        echo "OK integration_catalogo_metadados_sync [{$dim}]\n";
    } finally {
        $limpar();
    }
}

echo "OK integration_catalogo_metadados_sync\n";
