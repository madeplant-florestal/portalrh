<?php
/**
 * Movimentação do Kanban de Solicitações de Vaga (situação operacional da vaga).
 *
 * Análogo, em padrão, a RecruitmentPipelineService (Kanban de candidatos) — mesma ideia de
 * transação + lock otimista/pessimista + validação de campos obrigatórios por etapa + registro
 * de histórico — mas sem nenhuma dependência de código daquele serviço: são domínios e tabelas
 * totalmente separados (solicitacao_vaga_stages / solicitacao_vaga_kanban_historico), e esta
 * classe nunca dispara webhook (fora de escopo desta sprint).
 *
 * status_fluxo (aprovação líder/RH) nunca é lido ou alterado aqui — as duas máquinas de estado
 * são propositalmente desacopladas.
 */
class SolicitacaoVagaPipelineService
{
    /**
     * @param array $metadata Chave 'motivo_cancelamento' é obrigatória quando o destino é a
     *                        etapa 'cancelada' (ver SolicitacaoVagaStageValidator). Chave
     *                        'observacao' é opcional e vira a nota exibida no histórico.
     * @return array{ok: bool, error?: string, message?: string, missing_fields?: string[]}
     */
    public function moveToStage(
        int $solicitacaoId,
        int $newStageId,
        ?int $userId,
        array $metadata = [],
        bool $checkConcurrency = false,
        ?int $expectedCurrentStageId = null
    ): array {
        $pdo = Database::conn();

        $current = $this->fetchRow($solicitacaoId);
        if (!$current) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Solicitação de vaga não encontrada.'];
        }

        $oldStageId = (int)($current['situacao_kanban_id'] ?? 0);

        if ($checkConcurrency && ($expectedCurrentStageId ?? 0) !== $oldStageId) {
            return [
                'ok' => false,
                'error' => 'conflict',
                'message' => 'Esta solicitação já foi movimentada por outro usuário. Recarregue a página.',
            ];
        }

        if ($oldStageId === $newStageId) {
            return ['ok' => true];
        }

        $newStage = SolicitacaoVagaStage::find($newStageId);
        if (!$newStage) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Etapa de destino não encontrada.'];
        }
        $newSlug = (string)$newStage['slug'];
        $newNome = (string)$newStage['nome'];

        $missingFields = SolicitacaoVagaStageValidator::missingFields($newSlug, $metadata);
        if ($missingFields !== []) {
            return [
                'ok' => false,
                'error' => 'validation',
                'missing_fields' => $missingFields,
                'message' => SolicitacaoVagaStageValidator::buildMessage($missingFields),
            ];
        }

        $oldStage = $oldStageId > 0 ? SolicitacaoVagaStage::find($oldStageId) : null;
        $oldNome = $oldStage['nome'] ?? null;

        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare('SELECT situacao_kanban_id FROM solicitacoes_vaga WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$solicitacaoId]);
            $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if ($lockedRow === false) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'not_found', 'message' => 'Solicitação de vaga não encontrada.'];
            }
            $lockedStageId = $lockedRow['situacao_kanban_id'] !== null ? (int)$lockedRow['situacao_kanban_id'] : 0;
            if ($lockedStageId !== $oldStageId) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => 'conflict',
                    'message' => 'Esta solicitação já foi movimentada por outro usuário. Recarregue a página.',
                ];
            }

            $sets = ['situacao_kanban_id = ?'];
            $params = [$newStageId];

            if ($newSlug === 'cancelada') {
                $sets[] = 'motivo_cancelamento_encrypted = ?';
                $sets[] = 'cancelada_em = NOW()';
                $sets[] = 'cancelada_por_usuario_id = ?';
                $params[] = Cipher::encrypt(trim((string)$metadata['motivo_cancelamento']));
                $params[] = $userId;
            }

            if ($newSlug === 'fechada') {
                $sets[] = 'fechada_em = NOW()';
                $sets[] = 'fechada_por_usuario_id = ?';
                $params[] = $userId;
            }

            $params[] = $solicitacaoId;
            $stmt = $pdo->prepare('UPDATE solicitacoes_vaga SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);

            $observacao = trim((string)($metadata['observacao'] ?? ''));
            if ($newSlug === 'cancelada') {
                // O motivo do cancelamento fica registrado de forma legível no histórico (texto plano,
                // mesmo padrão de candidatura_historico); o dado sensível/oficial vive só na coluna
                // *_encrypted acima.
                $observacao = trim('Motivo do cancelamento: ' . (string)$metadata['motivo_cancelamento'] . ($observacao !== '' ? ' — ' . $observacao : ''));
            }

            $stmt = $pdo->prepare(
                'INSERT INTO solicitacao_vaga_kanban_historico (solicitacao_id, situacao_anterior, situacao_nova, observacao, usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$solicitacaoId, $oldNome, $newNome, $observacao !== '' ? $observacao : null, $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao mover solicitação de vaga no Kanban', [
                'solicitacao_id' => $solicitacaoId,
                'new_stage_id' => $newStageId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'exception', 'message' => 'Falha ao mover a solicitação.'];
        }

        return ['ok' => true];
    }

    public function addNota(int $solicitacaoId, string $texto, ?int $userId): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['ok' => false, 'error' => 'validation', 'message' => 'Informe o texto da anotação.'];
        }

        $current = $this->fetchRow($solicitacaoId);
        if (!$current) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Solicitação de vaga não encontrada.'];
        }

        $stageId = (int)($current['situacao_kanban_id'] ?? 0);
        $stage = $stageId > 0 ? SolicitacaoVagaStage::find($stageId) : null;
        $stageNome = $stage['nome'] ?? null;

        try {
            $stmt = Database::conn()->prepare(
                'INSERT INTO solicitacao_vaga_kanban_historico (solicitacao_id, situacao_anterior, situacao_nova, observacao, usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            // situacao_anterior = situacao_nova sinaliza "anotação avulsa" (sem mudança de etapa) —
            // mesma convenção já usada em candidatura_historico.
            $stmt->execute([$solicitacaoId, $stageNome, $stageNome ?? 'Sem etapa', $texto, $userId]);
        } catch (Throwable $e) {
            Logger::error('Falha ao salvar anotação da solicitação de vaga', [
                'solicitacao_id' => $solicitacaoId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'exception', 'message' => 'Falha ao salvar a anotação.'];
        }

        return ['ok' => true];
    }

    private function fetchRow(int $solicitacaoId): ?array
    {
        $stmt = Database::conn()->prepare('SELECT id, situacao_kanban_id FROM solicitacoes_vaga WHERE id = ? LIMIT 1');
        $stmt->execute([$solicitacaoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
