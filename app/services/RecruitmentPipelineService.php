<?php
class RecruitmentPipelineService
{
    private RecruitmentEventDispatcher $dispatcher;

    public function __construct(?RecruitmentEventDispatcher $dispatcher = null)
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $this->dispatcher = $dispatcher ?? new RecruitmentEventDispatcher();
    }

    public function moveCandidateToStage(int $candidateId, int $newStageId, ?int $userId): bool
    {
        PipelineStage::ensureRecruitmentLifecycle();
        $candidate = Candidatura::find($candidateId);
        if (!$candidate) {
            return false;
        }

        $oldStageId = (int)($candidate['stage_id'] ?? 0);
        if ($oldStageId === $newStageId) {
            return true;
        }

        $pdo = Database::conn();
        $queuedEventId = null;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?');
            $stmt->execute([$newStageId, $candidateId]);

            $stmt = $pdo->prepare(
                'INSERT INTO pipeline_movements (candidatura_id, stage_anterior_id, stage_novo_id, usuario_id)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$candidateId, $oldStageId > 0 ? $oldStageId : null, $newStageId, $userId]);

            $stmt = $pdo->prepare('SELECT nome FROM pipeline_stages WHERE id = ?');
            $stmt->execute([$newStageId]);
            $newStageName = (string)($stmt->fetchColumn() ?: 'Desconhecido');
            $oldStageName = (string)($candidate['stage_nome'] ?? 'Desconhecido');

            if ($this->isAdmissionStageName($newStageName) && (int)($candidate['indicacao_colaborador'] ?? 0) === 1) {
                $stmt = $pdo->prepare(
                    'UPDATE candidaturas
                     SET indicacao_data_contratacao = COALESCE(indicacao_data_contratacao, NOW()),
                         indicacao_data_fim_experiencia = COALESCE(indicacao_data_fim_experiencia, DATE_ADD(CURDATE(), INTERVAL 90 DAY))
                     WHERE id = ?'
                );
                $stmt->execute([$candidateId]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO candidatura_historico (candidatura_id, status_anterior, status_novo, observacoes, usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$candidateId, $oldStageName, $newStageName, 'Mudança de etapa via Pipeline', $userId]);

            $updatedCandidate = Candidatura::find($candidateId) ?? $candidate;
            $actor = $userId ? User::findById((int)$userId) : null;
            $queuedEventId = $this->dispatcher->dispatchCandidateStageChanged([
                'candidate' => $updatedCandidate,
                'metadata' => $updatedCandidate,
                'previous_stage' => $oldStageName,
                'new_stage' => $newStageName,
                'changed_by' => $actor?->nome ?? 'Sistema',
                'changed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ], $pdo, false);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Falha ao mover candidatura no pipeline', [
                'candidate_id' => $candidateId,
                'new_stage_id' => $newStageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if (($queuedEventId ?? 0) > 0) {
            try {
                $this->dispatcher->deliverQueuedEvent((int)$queuedEventId);
            } catch (Throwable $e) {
                Logger::warning('Evento de recrutamento enfileirado, mas a entrega imediata falhou.', [
                    'webhook_event_id' => $queuedEventId,
                    'candidate_id' => $candidateId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    private function isAdmissionStageName(string $value): bool
    {
        $normalized = PipelineStage::normalizeName($value);
        return in_array($normalized, ['contratado', 'admissao'], true);
    }
}
