<?php

/**
 * Regras/cálculos do Dashboard de Recrutamento e Seleção.
 *
 * Convenção de "indisponibilidade" usada em todo o painel: um indicador que não pode ser
 * calculado (amostra zero, fonte estruturalmente inexistente) SEMPRE retorna `null` no campo de
 * valor — nunca `0` — e vem acompanhado de um campo irmão (`*_amostra`/`disponivel`) que explica o
 * motivo. A view decide a mensagem ("Dados insuficientes" vs "Dados ainda não disponíveis")
 * conforme o caso, nunca inventa número.
 */
class RecrutamentoIndicadoresService
{
    /** Estágios do funil, na ordem pedida — mapeados aos slugs reais de `pipeline_stages`. */
    private const FUNIL_ETAPAS = [
        ['slug' => 'nova-inscricao', 'label' => 'Candidatos Inscritos'],
        ['slug' => 'triagem-rh', 'label' => 'Triagem RH'],
        ['slug' => 'entrevista-rh', 'label' => 'Entrevista RH'],
        ['slug' => 'entrevista-gestor', 'label' => 'Entrevista Gestor'],
        ['slug' => 'admissao', 'label' => 'Contratados'],
    ];

    /** Estágios com tempo médio calculado (concluído) — Nova Inscrição fica fora por decisão da sprint. */
    private const TEMPO_ETAPAS = [
        ['slug' => 'triagem-rh', 'label' => 'Triagem RH'],
        ['slug' => 'entrevista-rh', 'label' => 'Entrevista RH'],
        ['slug' => 'entrevista-gestor', 'label' => 'Entrevista Gestor'],
        ['slug' => 'admissao', 'label' => 'Admissão'],
    ];

    private RecrutamentoIndicadoresRepository $repository;
    private RhIndicadoresRepository $rhRepository;

    public function __construct(
        ?RecrutamentoIndicadoresRepository $repository = null,
        ?RhIndicadoresRepository $rhRepository = null
    ) {
        $this->repository = $repository ?? new RecrutamentoIndicadoresRepository();
        $this->rhRepository = $rhRepository ?? new RhIndicadoresRepository();
    }

    public function opcoesFiltro(): array
    {
        return [
            'empresas' => $this->repository->opcoesEmpresas(),
            'vagas' => $this->repository->opcoesVagas(),
        ];
    }

    public function montarPainel(array $filtros, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $empresaId = isset($filtros['empresa_id']) ? (int)$filtros['empresa_id'] : null;
        $vagaId = isset($filtros['vaga_id']) ? (int)$filtros['vaga_id'] : null;

        $cohort = $this->repository->buscarCandidaturasCohort($inicio, $fim, $empresaId, $vagaId);
        $candidaturaIds = array_map(static fn(array $c): int => (int)$c['id'], $cohort);
        $movimentosPorCandidatura = $this->agruparMovimentosPorCandidatura(
            $this->repository->buscarMovimentacoes($candidaturaIds)
        );

        $stagesBySlug = [];
        foreach (PipelineStage::all() as $stage) {
            $stagesBySlug[(string)$stage['slug']] = (int)$stage['id'];
        }

        return [
            'total_candidaturas_cohort' => count($cohort),
            'vagas' => $this->montarVagas($inicio, $fim, $empresaId),
            'tempo_contratacao' => $this->montarTempoContratacao($cohort, $movimentosPorCandidatura, $stagesBySlug),
            'funil' => $this->montarFunil($cohort, $movimentosPorCandidatura, $stagesBySlug),
            'tempo_por_etapa' => $this->montarTempoPorEtapa($cohort, $movimentosPorCandidatura, $stagesBySlug),
            'proposta_aceite' => ['disponivel' => false],
            'desistencia' => ['disponivel' => false],
            'qualidade' => $this->montarQualidade($inicio, $fim, $empresaId, $vagaId),
        ];
    }

    /** @return array<int, array<int, array{stage_novo_id:int, created_at:string}>> */
    private function agruparMovimentosPorCandidatura(array $movimentos): array
    {
        $porCandidatura = [];
        foreach ($movimentos as $mov) {
            $porCandidatura[(int)$mov['candidatura_id']][] = [
                'stage_novo_id' => (int)$mov['stage_novo_id'],
                'created_at' => (string)$mov['created_at'],
            ];
        }
        return $porCandidatura;
    }

    private function montarVagas(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?int $empresaId): array
    {
        $abertas = $this->repository->contarSolicitacoesAbertas($empresaId);
        $fechadas = $this->repository->contarSolicitacoesFechadas($inicio, $fim, $empresaId);
        return [
            'abertas' => (int)($abertas['total'] ?? 0),
            'fechadas_no_periodo' => (int)($fechadas['total'] ?? 0),
        ];
    }

    /**
     * Tempo Médio de Contratação: jornada do candidato, inscrição -> primeira entrada em
     * Admissão. Só entram candidatos da coorte que efetivamente chegaram a Admissão.
     */
    private function montarTempoContratacao(array $cohort, array $movimentosPorCandidatura, array $stagesBySlug): array
    {
        $admissaoId = $stagesBySlug['admissao'] ?? 0;
        $duracoes = [];

        foreach ($cohort as $candidatura) {
            $id = (int)$candidatura['id'];
            $movimentos = $movimentosPorCandidatura[$id] ?? [];
            $primeiraAdmissao = null;
            foreach ($movimentos as $mov) {
                if ($mov['stage_novo_id'] === $admissaoId) {
                    $primeiraAdmissao = $mov['created_at'];
                    break; // já ordenado por created_at ASC — o primeiro encontrado é o mais antigo.
                }
            }
            if ($primeiraAdmissao === null) {
                continue;
            }
            $dias = $this->diasEntre((string)$candidatura['created_at'], $primeiraAdmissao);
            if ($dias !== null) {
                $duracoes[] = $dias;
            }
        }

        $amostra = count($duracoes);
        return [
            'media_dias' => $amostra > 0 ? round(array_sum($duracoes) / $amostra, 1) : null,
            'amostra' => $amostra,
        ];
    }

    private function montarFunil(array $cohort, array $movimentosPorCandidatura, array $stagesBySlug): array
    {
        $totalCohort = count($cohort);
        $stageIdParaCandidatura = [];
        foreach ($cohort as $candidatura) {
            $stageIdParaCandidatura[(int)$candidatura['id']] = (int)$candidatura['stage_id'];
        }

        $resultado = [];
        foreach (self::FUNIL_ETAPAS as $etapa) {
            if ($etapa['slug'] === 'nova-inscricao') {
                $resultado[] = ['label' => $etapa['label'], 'quantidade' => $totalCohort];
                continue;
            }
            $stageId = $stagesBySlug[$etapa['slug']] ?? 0;
            $quantidade = 0;
            foreach ($cohort as $candidatura) {
                $id = (int)$candidatura['id'];
                foreach (($movimentosPorCandidatura[$id] ?? []) as $mov) {
                    if ($mov['stage_novo_id'] === $stageId) {
                        $quantidade++;
                        break;
                    }
                }
            }
            $resultado[] = ['label' => $etapa['label'], 'quantidade' => $quantidade];
        }
        return $resultado;
    }

    /**
     * Duração concluída = entrada na etapa -> próxima movimentação da mesma candidatura (qualquer
     * etapa de destino). Candidatos sem movimentação seguinte (ainda na etapa) não entram na
     * média, mas são contados em `atualmente_na_etapa` a partir do stage_id corrente.
     */
    private function montarTempoPorEtapa(array $cohort, array $movimentosPorCandidatura, array $stagesBySlug): array
    {
        $atualPorStage = [];
        foreach ($cohort as $candidatura) {
            $stageId = (int)$candidatura['stage_id'];
            $atualPorStage[$stageId] = ($atualPorStage[$stageId] ?? 0) + 1;
        }

        $resultado = [];
        foreach (self::TEMPO_ETAPAS as $etapa) {
            $stageId = $stagesBySlug[$etapa['slug']] ?? 0;
            $duracoes = [];

            foreach ($movimentosPorCandidatura as $movimentos) {
                $total = count($movimentos);
                for ($i = 0; $i < $total; $i++) {
                    if ($movimentos[$i]['stage_novo_id'] !== $stageId) {
                        continue;
                    }
                    if (!isset($movimentos[$i + 1])) {
                        continue; // ainda na etapa — não concluída, não entra na média.
                    }
                    $dias = $this->diasEntre($movimentos[$i]['created_at'], $movimentos[$i + 1]['created_at']);
                    if ($dias !== null) {
                        $duracoes[] = $dias;
                    }
                }
            }

            $amostra = count($duracoes);
            $resultado[$etapa['slug']] = [
                'label' => $etapa['label'],
                'media_dias' => $amostra > 0 ? round(array_sum($duracoes) / $amostra, 1) : null,
                'amostra_concluida' => $amostra,
                'atualmente_na_etapa' => $atualPorStage[$stageId] ?? 0,
            ];
        }
        return $resultado;
    }

    private function montarQualidade(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?int $empresaId, ?int $vagaId): array
    {
        return [
            'efetivacao' => $this->montarEfetivacaoEDesligamento($inicio, $fim),
            'pesquisa_experiencia' => $this->montarPesquisaExperiencia($inicio, $fim, $empresaId, $vagaId),
        ];
    }

    /**
     * Efetivação e Desligamento na Experiência, a partir de `colaboradores_metadados`
     * (RhIndicadoresRepository::buscarContratos — nunca CPF/salário/nome). Reaproveita a regra
     * oficial de 90 dias já definida em RhIndicadoresService — não duplica o número mágico.
     *
     * Elegível = admissão dentro do período selecionado E já se passaram >= 90 dias da admissão
     * até HOJE (não faz sentido classificar quem ainda está no meio da experiência).
     * Efetivado = elegível sem demissão OU com demissão ocorrida DEPOIS dos 90 dias.
     * Desligado na experiência = elegível com demissão dentro dos primeiros 90 dias.
     * Turnover até 90 dias = RhIndicadoresService::turnoverPrecoce() sobre os desligamentos do
     * período — mesma fonte, mesma regra, sem segunda definição.
     */
    private function montarEfetivacaoEDesligamento(DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $contratos = $this->rhRepository->buscarContratos([]);
        $hoje = new DateTimeImmutable('today');
        $limiteDias = RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS;

        $elegiveis = 0;
        $efetivados = 0;
        $desligadosExperiencia = 0;
        $desligamentosNoPeriodo = [];

        foreach ($contratos as $contrato) {
            $admissao = $this->parseData($contrato['admissao'] ?? null);
            $demissao = $this->parseData($contrato['demissao'] ?? null);

            if ($demissao !== null && $demissao >= $inicio && $demissao <= $fim) {
                $desligamentosNoPeriodo[] = ['admissao' => $contrato['admissao'], 'demissao' => $contrato['demissao']];
            }

            if ($admissao === null || $admissao < $inicio || $admissao > $fim) {
                continue;
            }
            $marco90Dias = $admissao->modify("+{$limiteDias} days");
            if ($marco90Dias > $hoje) {
                continue; // ainda não elegível — não completou o marco de 90 dias até hoje.
            }

            $elegiveis++;
            if ($demissao === null || $demissao > $marco90Dias) {
                $efetivados++;
            } else {
                $desligadosExperiencia++;
            }
        }

        return [
            'efetivacao' => [
                'elegiveis' => $elegiveis,
                'efetivados' => $efetivados,
                'percentual' => $elegiveis > 0 ? round(($efetivados / $elegiveis) * 100, 1) : null,
            ],
            'desligamento_experiencia' => [
                'elegiveis' => $elegiveis,
                'desligados' => $desligadosExperiencia,
                'percentual' => $elegiveis > 0 ? round(($desligadosExperiencia / $elegiveis) * 100, 1) : null,
            ],
            'turnover_precoce' => RhIndicadoresService::turnoverPrecoce($desligamentosNoPeriodo),
        ];
    }

    /**
     * Nota média da Pesquisa de Experiência. A média consolidada é CALCULADA em memória a partir
     * das três notas de cada resposta — nunca persistida (não existe coluna de nota geral).
     */
    private function montarPesquisaExperiencia(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        ?int $empresaId,
        ?int $vagaId
    ): array {
        $respostas = $this->repository->buscarPesquisasRespondidas($inicio, $fim, $empresaId, $vagaId);
        $total = count($respostas);

        if ($total === 0) {
            return [
                'respostas' => 0,
                'clareza' => null,
                'tempo_retorno' => null,
                'atendimento' => null,
                'consolidada' => null,
            ];
        }

        $somaClareza = 0;
        $somaTempoRetorno = 0;
        $somaAtendimento = 0;
        $somaConsolidada = 0.0;
        foreach ($respostas as $resposta) {
            $clareza = (int)$resposta['nota_clareza'];
            $tempoRetorno = (int)$resposta['nota_tempo_retorno'];
            $atendimento = (int)$resposta['nota_atendimento'];
            $somaClareza += $clareza;
            $somaTempoRetorno += $tempoRetorno;
            $somaAtendimento += $atendimento;
            $somaConsolidada += ($clareza + $tempoRetorno + $atendimento) / 3;
        }

        return [
            'respostas' => $total,
            'clareza' => round($somaClareza / $total, 1),
            'tempo_retorno' => round($somaTempoRetorno / $total, 1),
            'atendimento' => round($somaAtendimento / $total, 1),
            'consolidada' => round($somaConsolidada / $total, 1),
        ];
    }

    private function diasEntre(string $inicio, string $fim): ?float
    {
        try {
            $dataInicio = new DateTimeImmutable($inicio);
            $dataFim = new DateTimeImmutable($fim);
        } catch (Throwable) {
            return null;
        }
        $segundos = $dataFim->getTimestamp() - $dataInicio->getTimestamp();
        return $segundos >= 0 ? $segundos / 86400 : null;
    }

    private function parseData(?string $valor): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable) {
            return null;
        }
    }
}
