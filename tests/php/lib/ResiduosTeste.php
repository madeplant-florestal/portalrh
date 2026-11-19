<?php

/**
 * Isolamento dos dados de teste (Etapa de confiabilidade da suíte, 2026-11).
 *
 * Os testes criam registros sintéticos e os removem em `finally`. Um `exit` no meio do teste (ex.: um controller que responde 403 com
 * `exit;` dentro do próprio processo) NÃO executa o `finally` e deixa lixo no banco de DESENVOLVIMENTO — que depois quebra outros testes
 * (ex.: o sincronizador recusa um espelho com "origem diferente"). Este módulo identifica e remove SÓ o que é comprovadamente de teste.
 *
 * PROVA DE PROPRIEDADE (todas as condições, nunca só uma):
 *   usuarios                 nome começa com "ZZ" E e-mail termina em "@teste.local" (convenção de TODOS os testes; usuário real nunca tem esse domínio);
 *   colaboradores_metadados  origem_metadados = "zz<letras/números>-teste" (a origem real do sincronizador é "RHMADEPLANT");
 *   setores                  origem_metadados no mesmo padrão "zz...-teste".
 *   pesquisas_integracao     ÓRFÃS (o contrato referenciado não existe mais) E (data de integração em 2001 — ano exclusivo dos testes — OU contrato com id
 *                            acima do MAIOR id de contrato real: ids reais foram todos gerados antes; o que veio depois só pode ter sido criado por teste).
 *                            Essa tabela não tem FK para o contrato, então um teste interrompido deixa linhas órfãs que ninguém removia.
 * Linhas dependentes (FK) de um registro de teste são removidas junto, recursivamente — por definição pertencem ao teste. A remoção roda
 * numa transação: o modo simulação faz ROLLBACK e só relata as contagens. NUNCA roda fora de ambiente de desenvolvimento local.
 */
final class ResiduosTeste
{
    private const LOTE = 400;

    /** Tabelas que guardam `metadados_id` SEM chave estrangeira. */
    private const DEPENDENTES_LOGICOS_DO_CONTRATO = [
        ['pesquisas_integracao', 'metadados_id'], ['pdis', 'metadados_id'], ['feedbacks', 'metadados_id'],
        ['avaliacoes_desempenho', 'metadados_id'], ['avaliacoes_experiencia', 'metadados_id'],
    ];

    /** Aborta fora do ambiente de desenvolvimento local (nunca toca produção). */
    public static function garantirAmbienteDeTeste(PDO $pdo): void
    {
        $app = Config::app();
        $env = strtolower((string)($app['env'] ?? 'production'));
        $dsn = (string)($app['database']['dsn'] ?? '');
        $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $hostLocal = (bool)preg_match('/host=(localhost|127\.0\.0\.1)(;|$)/i', $dsn);
        if ($env === 'production' || !$hostLocal || stripos($banco, 'portalrh') !== false || stripos($banco, 'u1727') !== false) {
            throw new RuntimeException("Recusado: ambiente não é o de desenvolvimento local (env={$env}, banco={$banco}).");
        }
    }

    /** @return array{usuarios:int[],metadados:int[],setores:int[],pesquisas_orfas:int[]} ids com a prova de propriedade acima. */
    public static function levantar(PDO $pdo): array
    {
        $ids = static fn(string $sql): array => array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        return [
            'usuarios' => $ids("SELECT id FROM usuarios WHERE nome LIKE 'ZZ%' AND email LIKE '%@teste.local'"),
            'metadados' => $ids("SELECT id FROM colaboradores_metadados WHERE origem_metadados REGEXP '^zz[a-z0-9_]*-teste$'"),
            'setores' => $ids("SELECT id FROM setores WHERE origem_metadados REGEXP '^zz[a-z0-9_]*-teste$'"),
            'pesquisas_orfas' => $ids(
                "SELECT p.id FROM pesquisas_integracao p
                 WHERE p.metadados_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM colaboradores_metadados cm WHERE cm.id = p.metadados_id)
                   AND (p.integracao_data_relacionada BETWEEN '2001-01-01' AND '2001-12-31'
                        OR p.metadados_id > COALESCE((SELECT MAX(id) FROM colaboradores_metadados WHERE origem_metadados NOT REGEXP '^zz[a-z0-9_]*-teste$'), 0))"
            ),
        ];
    }

    /** Ids presentes em $depois e ausentes de $antes (o que UM teste deixou para trás). */
    public static function diferenca(array $antes, array $depois): array
    {
        $r = [];
        foreach ($depois as $tabela => $ids) {
            $r[$tabela] = array_values(array_diff($ids, $antes[$tabela] ?? []));
        }
        return $r;
    }

    public static function total(array $levantamento): int
    {
        return array_sum(array_map('count', $levantamento));
    }

    /**
     * Remove os registros e tudo que depende deles. $executar=false: tudo dentro de transação com ROLLBACK (simulação).
     *
     * @return array<string,int> linhas removidas por tabela
     */
    public static function remover(PDO $pdo, array $levantamento, bool $executar): array
    {
        self::garantirAmbienteDeTeste($pdo);
        $contagem = [];
        $pdo->beginTransaction();
        try {
            // Dependentes LÓGICOS (sem FK) dos contratos de teste: a linha pertence ao teste por definição.
            foreach (self::DEPENDENTES_LOGICOS_DO_CONTRATO as [$tabelaLogica, $colunaLogica]) {
                if ($levantamento['metadados'] ?? []) {
                    $in = implode(',', array_fill(0, count($levantamento['metadados']), '?'));
                    $st = $pdo->prepare("SELECT id FROM `{$tabelaLogica}` WHERE `{$colunaLogica}` IN ({$in})");
                    $st->execute(array_values($levantamento['metadados']));
                    self::apagar($pdo, $tabelaLogica, 'id', array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $contagem, 1);
                }
            }
            foreach (['usuarios' => 'usuarios', 'metadados' => 'colaboradores_metadados', 'setores' => 'setores', 'pesquisas_orfas' => 'pesquisas_integracao'] as $chave => $tabela) {
                self::apagar($pdo, $tabela, 'id', $levantamento[$chave] ?? [], $contagem, 0);
            }
            $executar ? $pdo->commit() : $pdo->rollBack();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $contagem;
    }

    /** @param int[] $ids */
    private static function apagar(PDO $pdo, string $tabela, string $coluna, array $ids, array &$contagem, int $nivel): void
    {
        if ($ids === [] || $nivel > 6) {
            return;
        }
        foreach (array_chunk($ids, self::LOTE) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            foreach (self::referencias($pdo, $tabela, $coluna) as [$filha, $colFilha, $regra]) {
                if ($regra === 'SET NULL') {
                    continue; // o próprio banco anula a referência
                }
                $pk = self::chaveSimples($pdo, $filha);
                if ($pk !== null) {
                    $st = $pdo->prepare("SELECT `{$pk}` FROM `{$filha}` WHERE `{$colFilha}` IN ({$in})");
                    $st->execute($lote);
                    self::apagar($pdo, $filha, $pk, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $contagem, $nivel + 1);
                } else {
                    $st = $pdo->prepare("DELETE FROM `{$filha}` WHERE `{$colFilha}` IN ({$in})");
                    $st->execute($lote);
                    $contagem[$filha] = ($contagem[$filha] ?? 0) + $st->rowCount();
                }
            }
            $st = $pdo->prepare("DELETE FROM `{$tabela}` WHERE `{$coluna}` IN ({$in})");
            $st->execute($lote);
            $contagem[$tabela] = ($contagem[$tabela] ?? 0) + $st->rowCount();
        }
    }

    /** @return array<int,array{0:string,1:string,2:string}> [tabela filha, coluna filha, regra de DELETE] */
    private static function referencias(PDO $pdo, string $tabela, string $coluna): array
    {
        static $cache = [];
        $chave = $tabela . '.' . $coluna;
        if (!isset($cache[$chave])) {
            $st = $pdo->prepare(
                'SELECT k.TABLE_NAME, k.COLUMN_NAME, r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ?'
            );
            $st->execute([$tabela, $coluna]);
            $cache[$chave] = array_map(static fn(array $r): array => [(string)$r['TABLE_NAME'], (string)$r['COLUMN_NAME'], (string)$r['DELETE_RULE']], $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return $cache[$chave];
    }

    /** Nome da PK quando ela é UMA coluna; null para chaves compostas (tabela folha: apaga pela coluna da FK). */
    private static function chaveSimples(PDO $pdo, string $tabela): ?string
    {
        static $cache = [];
        if (!array_key_exists($tabela, $cache)) {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'");
            $st->execute([$tabela]);
            $cols = $st->fetchAll(PDO::FETCH_COLUMN);
            $cache[$tabela] = count($cols) === 1 ? (string)$cols[0] : null;
        }
        return $cache[$tabela];
    }
}
