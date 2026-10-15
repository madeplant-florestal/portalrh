<?php

/**
 * Integração — Benefícios por Vaga (2026-10, correção: a página pública exibia TODO o catálogo
 * ativo de `beneficios` via HomeController::vaga() -> Beneficio::allActive(), sem relação alguma
 * com a vaga sendo vista — ex. real: "TECNICO EM SEGURANCA DO TRABALHO J I" mostrava PPR/
 * Produtividade/Bonificação Semestral mesmo sem nenhuma associação).
 *
 * Correção: tabela N:N `vaga_beneficios` (Vaga::syncBeneficios/beneficioIdsSelecionados/
 * beneficiosPublicos), mesmo padrão de `solicitacao_vaga_beneficios`. A página pública passa a
 * usar Vaga::beneficiosPublicos($vagaId) — só o que o RH/Admin selecionou explicitamente na tela
 * de cadastro/edição de Vaga, e só benefícios ainda ativos no catálogo. Vagas existentes nascem
 * SEM nenhuma associação (sem seed, sem dedução por cargo/setor/nome/empresa).
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

$mk = 'ZZVB_' . substr(md5(uniqid('', true)), 0, 8);
$criados = ['vagas' => [], 'beneficios' => []];

$mkBeneficio = static function (string $nome) use (&$criados): int {
    $id = Beneficio::create(['nome' => $nome, 'ativo' => 1]);
    $criados['beneficios'][] = $id;
    return $id;
};

$mkVaga = static function (string $titulo) use (&$criados): int {
    $id = Vaga::create([
        'titulo' => $titulo,
        'descricao' => 'Descrição de teste',
        'requisitos' => 'Requisitos de teste',
        'area' => 'Área de teste',
        'local' => 'Local de teste',
        'empresa_id' => null,
        'ativo' => 1,
    ]);
    $criados['vagas'][] = $id;
    return $id;
};

try {
    // ---- fixtures: catálogo de benefícios (independente de qualquer vaga) --------------------
    $bPPR = $mkBeneficio($mk . '_PPR');
    $bProdutividade = $mkBeneficio($mk . '_PRODUTIVIDADE');
    $bAlimentacao = $mkBeneficio($mk . '_ALIMENTACAO');
    $bNaoAssociado = $mkBeneficio($mk . '_NAO_ASSOCIADO'); // nunca vinculado a nenhuma vaga deste teste

    // ---- 13. vaga nova nasce SEM nenhum benefício associado (sem seed/associação automática) --
    $vagaTecnico = $mkVaga($mk . ' TECNICO EM SEGURANCA DO TRABALHO J I');
    $check(Vaga::beneficioIdsSelecionados($vagaTecnico) === [], '13. Vaga recém-criada nasce sem nenhum benefício associado (sem seed automático)');
    $check(Vaga::beneficiosPublicos($vagaTecnico) === [], '4. Vaga sem benefício -> beneficiosPublicos() retorna vazio (página pública oculta a seção)');

    // ---- 1/2. associar 2 dos 4 benefícios do catálogo ------------------------------------------
    Vaga::syncBeneficios($vagaTecnico, [$bPPR, $bProdutividade]);
    $publicosTecnico = Vaga::beneficiosPublicos($vagaTecnico);
    $idsPublicosTecnico = array_column($publicosTecnico, 'id');
    $check(in_array($bPPR, $idsPublicosTecnico, true), '2. Benefício associado (PPR) aparece em beneficiosPublicos()');
    $check(in_array($bProdutividade, $idsPublicosTecnico, true), '2. Benefício associado (Produtividade) aparece em beneficiosPublicos()');
    $check(!in_array($bNaoAssociado, $idsPublicosTecnico, true), '1. Benefício ativo NÃO associado à vaga não aparece em beneficiosPublicos()');
    $check(count($idsPublicosTecnico) === 2, '2. Vaga com 2 benefícios associados mostra exatamente 2');

    // ---- 3. vaga com 3 benefícios mostra exatamente os 3 ---------------------------------------
    Vaga::syncBeneficios($vagaTecnico, [$bPPR, $bProdutividade, $bAlimentacao]);
    $idsTres = array_column(Vaga::beneficiosPublicos($vagaTecnico), 'id');
    sort($idsTres);
    $esperado3 = [$bPPR, $bProdutividade, $bAlimentacao];
    sort($esperado3);
    $check($idsTres === $esperado3, '3. Vaga com 3 benefícios mostra exatamente os 3 (nenhum a mais, nenhum a menos)');

    // ---- 12. publicação/status não são alterados por sincronizar benefícios -------------------
    $vagaAntes = Vaga::find($vagaTecnico);
    Vaga::syncBeneficios($vagaTecnico, [$bPPR]);
    $vagaDepois = Vaga::find($vagaTecnico);
    $check(
        (int)$vagaAntes['ativo'] === (int)$vagaDepois['ativo'] && $vagaAntes['publicada_em'] === $vagaDepois['publicada_em'],
        '12. Sincronizar benefícios não altera ativo/publicada_em da vaga (Vaga model não tem slug)'
    );

    // ---- 6. remover benefício da vaga remove da página pública ---------------------------------
    $idsAposRemover = array_column(Vaga::beneficiosPublicos($vagaTecnico), 'id');
    $check($idsAposRemover === [$bPPR], '6. Remover benefício da vaga (sync só com PPR) remove os outros da página pública');

    // ---- 7. benefício continua existindo no catálogo após ser removido de uma vaga ------------
    $check(Beneficio::find($bProdutividade) !== null, '7. Benefício removido de uma vaga continua existindo no catálogo (Beneficio::find)');

    // ---- 5. editar vaga (Vaga::update) altera benefícios sem recriar a vaga -------------------
    $idOriginal = $vagaTecnico;
    Vaga::update($vagaTecnico, [
        'titulo' => $mk . ' TECNICO EM SEGURANCA DO TRABALHO J I (editado)',
        'descricao' => 'Descrição editada',
        'requisitos' => 'Requisitos editados',
        'area' => 'Área editada',
        'local' => 'Local editado',
        'empresa_id' => null,
        'ativo' => 1,
    ]);
    Vaga::syncBeneficios($vagaTecnico, [$bAlimentacao]);
    $check($vagaTecnico === $idOriginal, '5. Editar a vaga não gera um novo id (mesma vaga, mesmas candidaturas)');
    $check(array_column(Vaga::beneficiosPublicos($vagaTecnico), 'id') === [$bAlimentacao], '5. Editar vaga altera benefícios sem recriar a vaga');

    // ---- 8/9. duas vagas com conjuntos diferentes; benefício em várias vagas ------------------
    $vagaOutra = $mkVaga($mk . ' OUTRA VAGA');
    Vaga::syncBeneficios($vagaOutra, [$bAlimentacao, $bNaoAssociado]);
    $idsTecnico = array_column(Vaga::beneficiosPublicos($vagaTecnico), 'id');
    $idsOutra = array_column(Vaga::beneficiosPublicos($vagaOutra), 'id');
    $idsOutraOrdenado = $idsOutra;
    sort($idsOutraOrdenado);
    $esperadoOutra = [$bAlimentacao, $bNaoAssociado];
    sort($esperadoOutra);
    $check($idsTecnico === [$bAlimentacao] && $idsOutraOrdenado === $esperadoOutra,
        '8. Duas vagas possuem conjuntos diferentes de benefícios, cada uma com sua própria seleção');
    $check(count($idsOutra) === 2, '8. Conjunto da segunda vaga tem exatamente os 2 benefícios associados a ela');
    $check(in_array($bAlimentacao, $idsTecnico, true) && in_array($bAlimentacao, $idsOutra, true),
        '9. Um mesmo benefício (Alimentação) pertence a várias vagas simultaneamente');

    // ---- integridade estrutural: FK de vaga_beneficios só toca vagas/beneficios --------------
    $stmtFk = $pdo->prepare(
        "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vaga_beneficios' AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $stmtFk->execute();
    $fkTabelas = array_column($stmtFk->fetchAll(PDO::FETCH_ASSOC), 'REFERENCED_TABLE_NAME');
    sort($fkTabelas);
    $check($fkTabelas === ['beneficios', 'vagas'], '11. vaga_beneficios só tem FK para vagas/beneficios (candidaturas não é tocada por esta migration)');

    // ---- 10. Viewer/solicitante não ganha permissão administrativa (gate inalterado) ----------
    $corpoDoMetodo = static function (string $classe, string $metodo): string {
        $ref = new ReflectionMethod($classe, $metodo);
        $arquivo = new SplFileObject($ref->getFileName());
        $arquivo->seek($ref->getStartLine() - 1);
        $linhas = '';
        for ($i = $ref->getStartLine(); $i <= $ref->getEndLine(); $i++) {
            $linhas .= $arquivo->current();
            $arquivo->next();
        }
        return $linhas;
    };
    foreach (['create', 'store', 'edit', 'update'] as $metodo) {
        $corpo = $corpoDoMetodo('AdminVagasController', $metodo);
        $check(
            str_contains($corpo, "Auth::requireRole(['admin', 'rh'])") && !str_contains($corpo, 'viewer'),
            "10. AdminVagasController::{$metodo}() continua exigindo só ['admin','rh'] — Viewer/solicitante nunca ganha acesso a editar benefícios de vaga"
        );
    }
    $corpoStore = $corpoDoMetodo('AdminVagasController', 'store');
    $check(
        strpos($corpoStore, "Auth::requireRole(['admin', 'rh'])") < strpos($corpoStore, 'syncBeneficios'),
        '10. store() checa o role ANTES de sincronizar benefícios (mesmo gate, sem brecha)'
    );
} finally {
    foreach ($criados['vagas'] as $vagaId) {
        $pdo->prepare('DELETE FROM vaga_beneficios WHERE vaga_id = ?')->execute([$vagaId]);
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);
    }
    foreach ($criados['beneficios'] as $beneficioId) {
        $pdo->prepare('DELETE FROM beneficios WHERE id = ?')->execute([$beneficioId]);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}

echo "\nVAGA_BENEFICIOS_OK\n";
