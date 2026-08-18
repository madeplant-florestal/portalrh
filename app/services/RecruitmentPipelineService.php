<?php
class RecruitmentPipelineService
{
    private RecruitmentEventDispatcher $dispatcher;

    public function __construct(?RecruitmentEventDispatcher $dispatcher = null)
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $this->dispatcher = $dispatcher ?? new RecruitmentEventDispatcher();
    }

    /**
     * @param array $metadata Campos adicionais da etapa de destino (ver RecruitmentStageMetadataValidator).
     *                        Chave 'observacoes' é tratada como o motivo/nota da transição (ex.: motivo de
     *                        reprovação); chave 'confirm' sinaliza a confirmação explícita exigida por
     *                        algumas etapas (reprovado, banco-de-talentos).
     * @param bool $checkConcurrency Se true, rejeita a movimentação quando a etapa atual da candidatura não
     *                                for exatamente $expectedCurrentStageId (proteção contra corrida entre
     *                                usuários movendo o mesmo candidato ao mesmo tempo).
     * @return array{ok: bool, error?: string, message?: string, missing_fields?: string[]}
     */
    public function moveCandidateToStage(
        int $candidateId,
        int $newStageId,
        ?int $userId,
        array $metadata = [],
        bool $checkConcurrency = false,
        ?int $expectedCurrentStageId = null
    ): array {
        PipelineStage::ensureRecruitmentLifecycle();
        $candidate = Candidatura::find($candidateId);
        if (!$candidate) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Candidatura não encontrada.'];
        }

        $oldStageId = (int)($candidate['stage_id'] ?? 0);

        if ($checkConcurrency && ($expectedCurrentStageId ?? 0) !== $oldStageId) {
            return [
                'ok' => false,
                'error' => 'conflict',
                'message' => 'O candidato já foi movimentado para outra etapa por outro usuário. Recarregue a página.',
            ];
        }

        if ($oldStageId === $newStageId) {
            return ['ok' => true];
        }

        $stmt = Database::conn()->prepare('SELECT id, nome, slug FROM pipeline_stages WHERE id = ?');
        $stmt->execute([$newStageId]);
        $newStageRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$newStageRow) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Etapa de destino não encontrada.'];
        }
        $newStageSlug = (string)($newStageRow['slug'] ?? '');
        $newStageName = (string)($newStageRow['nome'] ?? 'Desconhecido');

        $observacoes = $metadata['observacoes'] ?? null;
        $missingFields = RecruitmentStageMetadataValidator::missingFields($newStageSlug, $metadata, $observacoes);
        if ($missingFields !== []) {
            return [
                'ok' => false,
                'error' => 'validation',
                'missing_fields' => $missingFields,
                'message' => RecruitmentStageMetadataValidator::buildMessage($missingFields),
            ];
        }

        $pdo = Database::conn();
        $queuedEventId = null;

        try {
            $pdo->beginTransaction();

            // Trava a linha e reconfirma a etapa de origem sob lock: fecha a janela de corrida entre a
            // checagem de concorrência acima (fora da transação) e a escrita efetiva abaixo.
            $lockStmt = $pdo->prepare('SELECT stage_id FROM candidaturas WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$candidateId]);
            $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if ($lockedRow === false) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'not_found', 'message' => 'Candidatura não encontrada.'];
            }
            $lockedStageId = $lockedRow['stage_id'] !== null ? (int)$lockedRow['stage_id'] : 0;
            if ($lockedStageId !== $oldStageId) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => 'conflict',
                    'message' => 'O candidato já foi movimentado para outra etapa por outro usuário. Recarregue a página.',
                ];
            }

            if ($metadata !== []) {
                Candidatura::upsertStageMetadata($candidateId, $metadata, $pdo);
            }

            $stmt = $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?');
            $stmt->execute([$newStageId, $candidateId]);

            $stmt = $pdo->prepare(
                'INSERT INTO pipeline_movements (candidatura_id, stage_anterior_id, stage_novo_id, usuario_id)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$candidateId, $oldStageId > 0 ? $oldStageId : null, $newStageId, $userId]);
            $movementId = (int)$pdo->lastInsertId();

            $oldStageName = (string)($candidate['stage_nome'] ?? 'Desconhecido');
            $oldStageSlug = $candidate['stage_slug'] ?? null;

            if ($this->isAdmissionStageName($newStageName) && (int)($candidate['indicacao_colaborador'] ?? 0) === 1) {
                $stmt = $pdo->prepare(
                    'UPDATE candidaturas
                     SET indicacao_data_contratacao = COALESCE(indicacao_data_contratacao, NOW()),
                         indicacao_data_fim_experiencia = COALESCE(indicacao_data_fim_experiencia, DATE_ADD(CURDATE(), INTERVAL 90 DAY))
                     WHERE id = ?'
                );
                $stmt->execute([$candidateId]);
            }

            $observacoesTrimmed = trim((string)$observacoes);
            $historicoNota = $observacoesTrimmed !== '' ? $observacoesTrimmed : 'Mudança de etapa via Pipeline';
            if ($newStageSlug === 'reprovado' && $observacoesTrimmed !== '') {
                // 'observacoes' é reaproveitado como motivo interno da reprovação nesta transição
                // específica — nunca sai no payload do webhook (ver RecruitmentEventDispatcher).
                $historicoNota = 'Motivo da reprovação: ' . $observacoesTrimmed;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO candidatura_historico (candidatura_id, status_anterior, status_novo, observacoes, usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$candidateId, $oldStageName, $newStageName, $historicoNota, $userId]);

            if ($observacoesTrimmed !== '') {
                // Atualiza o "retrato atual" de observações da candidatura (mesma convenção já usada em
                // updateStatusNotes()). O texto anterior não se perde: cada transição fica preservada
                // integralmente em candidatura_historico.observacoes, consultável via getHistorico().
                $stmt = $pdo->prepare('UPDATE candidaturas SET observacoes = ? WHERE id = ?');
                $stmt->execute([$observacoesTrimmed, $candidateId]);
            }

            $updatedCandidate = Candidatura::find($candidateId) ?? $candidate;
            $actor = $userId ? User::findById((int)$userId) : null;
            $queuedEventId = $this->dispatcher->dispatchCandidateStageChanged([
                'candidate' => $updatedCandidate,
                'movement_id' => $movementId,
                'stage_from' => $oldStageId > 0 ? [
                    'id' => $oldStageId,
                    'slug' => $oldStageSlug,
                    'name' => $oldStageName,
                ] : null,
                'stage_to' => [
                    'id' => $newStageId,
                    'slug' => $newStageSlug,
                    'name' => $newStageName,
                ],
                'actor' => [
                    'id' => $userId,
                    'name' => $actor?->nome ?? 'Sistema',
                ],
                'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
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
            return ['ok' => false, 'error' => 'exception', 'message' => 'Falha ao mover candidato.'];
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

        return ['ok' => true];
    }

    private function isAdmissionStageName(string $value): bool
    {
        $normalized = PipelineStage::normalizeName($value);
        return in_array($normalized, ['contratado', 'admissao'], true);
    }
}
