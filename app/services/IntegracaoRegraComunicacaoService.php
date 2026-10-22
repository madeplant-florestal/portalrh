<?php

/**
 * Validação e persistência das regras evento/etapa → mensagem (Configurações → Integrações,
 * Etapa 3, 2026-10). Reaproveita integralmente o cadastro de `Mensagem` e `PipelineStage` — não
 * duplica nenhum dado deles, só valida e referencia por id.
 */
class IntegracaoRegraComunicacaoService
{
    /** Conjunto controlado e pequeno de propósito — hoje só WhatsApp/n8n (ver §12 da Etapa 3). */
    public const CANAIS_VALIDOS = ['whatsapp'];

    private IntegracaoRegraComunicacaoRepository $repository;

    public function __construct(?IntegracaoRegraComunicacaoRepository $repository = null)
    {
        $this->repository = $repository ?? new IntegracaoRegraComunicacaoRepository();
    }

    /**
     * Catálogo de eventos que podem receber uma regra de comunicação — reaproveita as constantes
     * de `RecruitmentEventDispatcher` (nunca redeclara a string do evento). `exige_etapa` indica
     * se a regra PRECISA de uma etapa do pipeline (ex.: mudança de etapa) ou nunca tem uma (ex.:
     * candidatura criada) — nunca force uma etapa artificial no segundo caso (§11 da Etapa 3).
     *
     * @return array<string,array{label:string,exige_etapa:bool}>
     */
    public static function eventosDisponiveis(): array
    {
        return [
            RecruitmentEventDispatcher::EVENT_CANDIDATE_STAGE_CHANGED => [
                'label' => 'Movimentação de etapa no Kanban',
                'exige_etapa' => true,
            ],
            RecruitmentEventDispatcher::EVENT_CANDIDATURA_CRIADA => [
                'label' => 'Candidatura criada (nova inscrição)',
                'exige_etapa' => false,
            ],
        ];
    }

    public function listar(): array
    {
        return $this->repository->all();
    }

    /**
     * Valida e grava uma regra (criação quando `$id` é null, atualização quando informado).
     * Nunca confia no `<select>` da interface — todo id recebido é revalidado contra o banco.
     *
     * @return array{ok:bool,id?:int,error?:string}
     */
    public function salvar(array $input, ?int $id = null): array
    {
        $eventos = self::eventosDisponiveis();
        $eventType = trim((string)($input['event_type'] ?? ''));
        if (!isset($eventos[$eventType])) {
            return ['ok' => false, 'error' => 'Selecione um evento válido.'];
        }
        $exigeEtapa = $eventos[$eventType]['exige_etapa'];

        $pipelineStageIdBruto = trim((string)($input['pipeline_stage_id'] ?? ''));
        $pipelineStageId = $pipelineStageIdBruto !== '' ? (int)$pipelineStageIdBruto : null;
        if ($exigeEtapa) {
            if ($pipelineStageId === null || $pipelineStageId <= 0) {
                return ['ok' => false, 'error' => 'Este evento exige a seleção de uma etapa do pipeline.'];
            }
            if (PipelineStage::find($pipelineStageId) === null) {
                return ['ok' => false, 'error' => 'A etapa selecionada não existe.'];
            }
        } else {
            // Evento sem etapa: nunca persiste uma etapa artificial, mesmo que o formulário envie algo.
            $pipelineStageId = null;
        }

        $mensagemId = (int)($input['mensagem_id'] ?? 0);
        $mensagem = $mensagemId > 0 ? Mensagem::find($mensagemId) : null;
        if ($mensagem === null) {
            return ['ok' => false, 'error' => 'Selecione um modelo de mensagem válido.'];
        }
        if ((int)($mensagem['ativo'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'O modelo de mensagem selecionado está inativo. Ative-o em Mensagens antes de vinculá-lo a uma regra.'];
        }

        $canal = trim((string)($input['canal'] ?? ''));
        if (!in_array($canal, self::CANAIS_VALIDOS, true)) {
            return ['ok' => false, 'error' => 'Canal inválido.'];
        }

        if ($this->repository->existeConflito($eventType, $pipelineStageId, $canal, $id)) {
            return ['ok' => false, 'error' => 'Já existe uma regra cadastrada para esta combinação de evento, etapa e canal.'];
        }

        $dados = [
            'event_type' => $eventType,
            'pipeline_stage_id' => $pipelineStageId,
            'mensagem_id' => $mensagemId,
            'canal' => $canal,
            'ativo' => !empty($input['ativo']),
        ];

        if ($id !== null) {
            $this->repository->update($id, $dados);
            return ['ok' => true, 'id' => $id];
        }

        return ['ok' => true, 'id' => $this->repository->create($dados)];
    }
}
