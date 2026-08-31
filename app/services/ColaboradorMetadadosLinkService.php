<?php
/**
 * Aplicação controlada de `colaboradores.metadados_id` a partir do plano gerado pela
 * reconciliação (ColaboradorMetadadosReconciliationService). Responsabilidade separada de
 * propósito: o reconciliador só carrega/compara/classifica, nunca escreve; este serviço só
 * valida e escreve, nunca reclassifica.
 *
 * Nunca acessa o SQL Server do METADADOS — trabalha exclusivamente sobre o MySQL local
 * (colaboradores / colaboradores_metadados), já espelhado por uma sincronização anterior.
 *
 * Fluxo esperado (ver scripts/aplicar_vinculos_colaboradores_metadados.php):
 *   reconciliação -> buildPlanFromReconciliation() -> validatePlan() + validateAgainstDatabase()
 *   -> (se apto) apply()
 */
class ColaboradorMetadadosLinkService
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        return $this->pdo ??= Database::conn();
    }

    /**
     * Filtra os resultados da reconciliação para incluir só CORRESPONDENCIA_SEGURA — nunca
     * PROVAVEL/AMBIGUA/SEM_CORRESPONDENCIA/CONFLITO/JA_VINCULADO.
     *
     * @param array $reconciliationResults Saída de ColaboradorMetadadosReconciliationService::analyze()/run().
     * @return array Cada item: colaborador_id, metadados_id, classificacao, motivo_classificacao.
     *               Nunca inclui CPF, nome ou qualquer outro dado pessoal.
     */
    public function buildPlanFromReconciliation(array $reconciliationResults): array
    {
        $plano = [];
        foreach ($reconciliationResults as $r) {
            if ($r['classificacao'] !== ColaboradorMetadadosReconciliationService::SEGURA) {
                continue;
            }
            $plano[] = [
                'colaborador_id' => (int)$r['colaborador_id'],
                'metadados_id' => (int)$r['metadados_id_candidato'],
                'classificacao' => $r['classificacao'],
                'motivo_classificacao' => $r['motivo_classificacao'],
            ];
        }
        return $plano;
    }

    /**
     * Validação estrutural do plano — não toca banco. Verifica as invariantes que dependem só
     * do próprio plano e do cenário de reconciliação completo.
     *
     * Suporta tanto a primeira aplicação (nenhum JA_VINCULADO ainda) quanto aplicações
     * complementares/incrementais (JA_VINCULADO já existem e devem permanecer intocados — nunca
     * bloqueia só pela presença deles). A invariante real que protege vínculos já existentes é:
     * nenhum item do plano pode reivindicar um `metadados_id` que já pertence a um JA_VINCULADO.
     * Na prática isso já é impedido antes de chegar aqui, em
     * ColaboradorMetadadosReconciliationService::flagDuplicateLinks() (que promove a CONFLITO
     * qualquer SEGURA/PROVAVEL/JA_VINCULADO disputando o mesmo metadados_id) — esta checagem é
     * uma segunda barreira, não a única.
     *
     * @return array{ok:bool, errors:string[]}
     */
    public function validatePlan(array $plano, array $reconciliationResults): array
    {
        $errors = [];

        foreach ($plano as $item) {
            if ($item['classificacao'] !== ColaboradorMetadadosReconciliationService::SEGURA) {
                $errors[] = "colaborador_id={$item['colaborador_id']}: item no plano não é CORRESPONDENCIA_SEGURA.";
            }
            if ($item['colaborador_id'] === null || $item['metadados_id'] === null || $item['metadados_id'] === 0) {
                $errors[] = "Item do plano com colaborador_id/metadados_id ausente ou inválido.";
            }
        }

        $colaboradorIds = array_column($plano, 'colaborador_id');
        if (count($colaboradorIds) !== count(array_unique($colaboradorIds))) {
            $errors[] = 'Existe colaborador_id duplicado no plano.';
        }

        $metadadosIds = array_column($plano, 'metadados_id');
        if (count($metadadosIds) !== count(array_unique($metadadosIds))) {
            $errors[] = 'Existe metadados_id duplicado no plano (dois colaboradores para o mesmo vínculo oficial).';
        }

        $metadadosIdsJaVinculados = array_column(
            array_filter(
                $reconciliationResults,
                static fn (array $r) => $r['classificacao'] === ColaboradorMetadadosReconciliationService::JA_VINCULADO
                    && $r['metadados_id_candidato'] !== null
            ),
            'metadados_id_candidato'
        );
        $colisaoComExistente = array_intersect($metadadosIds, $metadadosIdsJaVinculados);
        if ($colisaoComExistente !== []) {
            $errors[] = 'O plano tenta vincular metadados_id que já pertence a um colaborador JA_VINCULADO: '
                . implode(', ', array_unique($colisaoComExistente)) . '.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Validação contra o estado real do banco — cada colaborador_id/metadados_id do plano
     * precisa existir, o colaborador precisa estar com metadados_id ainda NULL, e o metadados_id
     * não pode já estar em uso por outro colaborador (proteção explícita para aplicação
     * complementar — sem isso, só a UNIQUE do banco pegaria o caso, no meio da transação de
     * apply(), em vez de aqui, antes de qualquer escrita).
     *
     * @return array{ok:bool, errors:string[]}
     */
    public function validateAgainstDatabase(array $plano): array
    {
        $pdo = $this->connection();
        $errors = [];

        foreach ($plano as $item) {
            $stmt = $pdo->prepare('SELECT metadados_id FROM colaboradores WHERE id = ?');
            $stmt->execute([$item['colaborador_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $errors[] = "colaborador_id={$item['colaborador_id']} não existe em colaboradores.";
                continue;
            }
            if ($row['metadados_id'] !== null) {
                $errors[] = "colaborador_id={$item['colaborador_id']} já possui metadados_id preenchido — não deveria estar no plano.";
            }

            $stmtMetadados = $pdo->prepare('SELECT id FROM colaboradores_metadados WHERE id = ?');
            $stmtMetadados->execute([$item['metadados_id']]);
            if ($stmtMetadados->fetchColumn() === false) {
                $errors[] = "metadados_id={$item['metadados_id']} não existe em colaboradores_metadados.";
            }

            $stmtEmUso = $pdo->prepare('SELECT id FROM colaboradores WHERE metadados_id = ?');
            $stmtEmUso->execute([$item['metadados_id']]);
            $colaboradorEmUso = $stmtEmUso->fetchColumn();
            if ($colaboradorEmUso !== false && (int)$colaboradorEmUso !== (int)$item['colaborador_id']) {
                $errors[] = "metadados_id={$item['metadados_id']} já está vinculado a outro colaborador (colaborador_id={$colaboradorEmUso}).";
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Hash determinístico do plano (ordenado por colaborador_id), para provar que o plano
     * validado é exatamente o plano aplicado — independe da ordem original dos itens.
     */
    public static function planHash(array $plano): string
    {
        $ordenado = $plano;
        usort($ordenado, static fn (array $a, array $b) => $a['colaborador_id'] <=> $b['colaborador_id']);
        $texto = implode("\n", array_map(
            static fn (array $i) => $i['colaborador_id'] . ':' . $i['metadados_id'],
            $ordenado
        ));
        return hash('sha256', $texto);
    }

    /**
     * Aplica o plano em uma única transação. Cada vínculo é escrito individualmente com
     * `WHERE id = ? AND metadados_id IS NULL`, e o rowCount precisa ser exatamente 1 — qualquer
     * divergência (vínculo já preenchido por outra via entre a validação e a aplicação, erro de
     * FK, etc.) aborta a transação inteira. Nenhuma aplicação parcial pode persistir.
     *
     * A validação pós-aplicação é ESCOPADA AOS ITENS DESTE PLANO — nunca ao total global da
     * tabela. O banco pode (e deve, em aplicações incrementais futuras) já conter vínculos de
     * planos anteriores; esta checagem prova só que ESTE plano foi aplicado corretamente,
     * coexistindo com qualquer vínculo pré-existente fora dele.
     *
     * Só altera `colaboradores.metadados_id` — nenhum outro campo de `colaboradores`, e nenhuma
     * linha de `colaboradores_metadados` é tocada.
     *
     * @return array{ok:bool, aplicados:int, total_vinculado_global?:int, error?:string}
     */
    public function apply(array $plano): array
    {
        if ($plano === []) {
            return ['ok' => false, 'aplicados' => 0, 'error' => 'Plano vazio — nada a aplicar.'];
        }

        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $aplicados = 0;
            foreach ($plano as $item) {
                $stmt = $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ? AND metadados_id IS NULL');
                $stmt->execute([$item['metadados_id'], $item['colaborador_id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Falha ao aplicar vínculo colaborador_id={$item['colaborador_id']}: linhas afetadas=" . $stmt->rowCount()
                        . ' (esperado 1 — o vínculo pode já ter sido preenchido por outra via).'
                    );
                }
                $aplicados++;
            }

            $colaboradorIds = array_column($plano, 'colaborador_id');
            $placeholders = implode(',', array_fill(0, count($colaboradorIds), '?'));
            $stmtVerifica = $pdo->prepare("SELECT id, metadados_id FROM colaboradores WHERE id IN ($placeholders)");
            $stmtVerifica->execute($colaboradorIds);
            $metadadosPorColaborador = [];
            foreach ($stmtVerifica->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $metadadosPorColaborador[(int)$row['id']] = $row['metadados_id'] !== null ? (int)$row['metadados_id'] : null;
            }

            // 1/2/3/4 — cada colaborador_id do plano ficou com exatamente o metadados_id esperado.
            foreach ($plano as $item) {
                $colaboradorId = (int)$item['colaborador_id'];
                $esperado = (int)$item['metadados_id'];
                $atual = $metadadosPorColaborador[$colaboradorId] ?? null;
                if ($atual === null) {
                    throw new RuntimeException("Integridade pós-aplicação falhou: colaborador_id={$colaboradorId} ficou sem metadados_id.");
                }
                if ($atual !== $esperado) {
                    throw new RuntimeException("Integridade pós-aplicação falhou: colaborador_id={$colaboradorId} está com metadados_id={$atual}, esperado {$esperado}.");
                }
            }

            // 5 — os metadados_id aplicados por ESTE plano continuam únicos entre si (a UNIQUE do
            // banco já impediria reuso de um metadados_id de fora do plano na própria UPDATE acima).
            $metadadosIdsAplicados = array_values($metadadosPorColaborador);
            if (count($metadadosIdsAplicados) !== count(array_unique($metadadosIdsAplicados))) {
                throw new RuntimeException('Integridade pós-aplicação falhou: duplicidade de metadados_id entre os itens deste plano.');
            }

            // 6 — nenhum item do plano aponta para um metadados_id inexistente em colaboradores_metadados.
            $metadadosIdsPlano = array_map('intval', array_column($plano, 'metadados_id'));
            $placeholdersMeta = implode(',', array_fill(0, count($metadadosIdsPlano), '?'));
            $stmtOrfaos = $pdo->prepare("SELECT COUNT(*) FROM colaboradores_metadados WHERE id IN ($placeholdersMeta)");
            $stmtOrfaos->execute($metadadosIdsPlano);
            $existentes = (int)$stmtOrfaos->fetchColumn();
            if ($existentes !== count(array_unique($metadadosIdsPlano))) {
                throw new RuntimeException('Integridade pós-aplicação falhou: algum metadados_id do plano não existe em colaboradores_metadados (vínculo órfão).');
            }

            // Contagem global — só telemetria/diagnóstico no relatório, nunca critério de sucesso.
            $totalVinculadoGlobal = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores WHERE metadados_id IS NOT NULL')->fetchColumn();

            $pdo->commit();
            return ['ok' => true, 'aplicados' => $aplicados, 'total_vinculado_global' => $totalVinculadoGlobal];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao aplicar plano de vínculos colaboradores_metadados', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'aplicados' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reverte exclusivamente os vínculos deste snapshot — nunca um NULL genérico em todos os
     * colaboradores. Para cada item, só reverte se o valor atual em `metadados_id` ainda for
     * exatamente o que este plano aplicou (`WHERE metadados_id = ?`) — se alguém alterou o
     * vínculo depois da aplicação, a reversão bloqueia esse item em vez de sobrescrever.
     *
     * @param array $snapshot Cada item: colaborador_id, metadados_id (valor aplicado por este
     *                        plano), metadados_id_anterior (valor a restaurar).
     * @return array{ok:bool, revertidos:int, error?:string}
     */
    public function revert(array $snapshot): array
    {
        if ($snapshot === []) {
            return ['ok' => false, 'revertidos' => 0, 'error' => 'Snapshot vazio — nada a reverter.'];
        }

        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $revertidos = 0;
            foreach ($snapshot as $item) {
                $stmt = $pdo->prepare('UPDATE colaboradores SET metadados_id = ? WHERE id = ? AND metadados_id = ?');
                $stmt->execute([$item['metadados_id_anterior'], $item['colaborador_id'], $item['metadados_id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Reversão bloqueada para colaborador_id={$item['colaborador_id']}: o vínculo atual não corresponde "
                        . 'mais ao aplicado por este plano — pode ter sido alterado depois. Nenhuma sobrescrita foi feita.'
                    );
                }
                $revertidos++;
            }
            $pdo->commit();
            return ['ok' => true, 'revertidos' => $revertidos];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao reverter plano de vínculos colaboradores_metadados', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'revertidos' => 0, 'error' => $e->getMessage()];
        }
    }
}
