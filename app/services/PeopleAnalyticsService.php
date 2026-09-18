<?php

/**
 * People Analytics — Tela Inicial/Dashboard principal do Portal RH.
 *
 * Unidade oficial dos indicadores corporativos de Headcount/movimentação (Headcount Atual,
 * Admissões, Desligamentos, Turnover Geral/Voluntário/Involuntário, Colaboradores por Setor): o
 * CONTRATO do METADADOS — nunca pessoa distinta. Uma mesma pessoa pode ter múltiplos contratos
 * oficiais válidos simultaneamente; eles não são deduplicados por `codigo_pessoa`. Reaproveita
 * diretamente `RhIndicadoresService::headcountEm()`/`admissoesNoPeriodo()`/`taxaTurnover()` — a
 * mesma semântica já consolidada em Indicadores de RH, nunca uma interpretação paralela. Quando um
 * indicador futuro precisar ser baseado em PESSOA em vez de contrato, isso deve ser uma decisão
 * explícita do negócio, documentada aqui — nunca inferida.
 *
 * Convenção de indisponibilidade (mesma de outros dashboards desta geração): todo indicador que
 * não pode ser calculado com confiança chega aqui como `null` (nunca `0` falso) — a view decide a
 * legenda certa ("Dados insuficientes" para amostra zero, "Fonte ainda não disponível" para
 * estrutura inexistente).
 *
 * Fonte oficial para tudo que é dado cadastral/organizacional: `colaboradores_metadados`
 * (PeopleAnalyticsRepository). `colaboradores` (tabela local legada) só é usada para Integração —
 * processo que pertence ao Portal, nunca para headcount/admissão/desligamento/turnover oficiais.
 */
class PeopleAnalyticsService
{
    /** Mesma convenção de faixas já usada no dashboard legado (CollaboratorDashboardDataService::
     *  buildTurnoverByAge) — reaproveitada aqui, não redefinida. */
    private const FAIXAS_ETARIAS = [
        ['label' => 'Até 25 anos', 'max' => 25],
        ['label' => '26 a 35 anos', 'max' => 35],
        ['label' => '36 a 45 anos', 'max' => 45],
        ['label' => '46 a 55 anos', 'max' => 55],
        ['label' => 'Acima de 55 anos', 'max' => null],
    ];

    /**
     * Classificação de motivo_rescisao_codigo definida pelo negócio (RH) — iniciativa do
     * colaborador. Códigos fora das duas listas (005 Término de Experiência, 008 Término de
     * Contrato Temporário, 016 Acordo entre as Partes, 020 Falecimento) NÃO são forçados em nenhum
     * dos dois lados: continuam em Desligamentos/Turnover Geral, mas ficam de fora do numerador de
     * Voluntário e de Involuntário. Nunca persistida — calculada a cada leitura.
     */
    private const CODIGOS_VOLUNTARIO = ['003', '006'];

    /** Classificação definida pelo negócio — iniciativa da empresa. */
    private const CODIGOS_INVOLUNTARIO = ['001', '002', '007'];

    private PeopleAnalyticsRepository $repository;
    private RecrutamentoIndicadoresRepository $recrutamentoRepository;

    public function __construct(
        ?PeopleAnalyticsRepository $repository = null,
        ?RecrutamentoIndicadoresRepository $recrutamentoRepository = null
    ) {
        $this->repository = $repository ?? new PeopleAnalyticsRepository();
        $this->recrutamentoRepository = $recrutamentoRepository ?? new RecrutamentoIndicadoresRepository();
    }

    public function opcoesFiltro(): array
    {
        return $this->repository->opcoesFiltro();
    }

    public function montarPainel(array $filtros, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $codigoEmpresa = $filtros['codigo_empresa'] ?? null;
        $codigoSetor = $filtros['codigo_setor'] ?? null;

        $contratos = $this->repository->buscarContratos([
            'codigo_empresa' => $codigoEmpresa,
            'codigo_setor' => $codigoSetor,
        ]);

        // Unidade oficial dos indicadores corporativos de Headcount/movimentação: CONTRATO do
        // METADADOS (não pessoa distinta) — reaproveita a MESMA semântica consolidada em
        // RhIndicadoresService (headcountEm/admissoesNoPeriodo/desligamentosNoPeriodo/
        // taxaTurnover), nunca uma interpretação paralela. Uma mesma pessoa pode ter múltiplos
        // contratos oficiais válidos — eles não são deduplicados por codigo_pessoa aqui.
        $headcountFim = RhIndicadoresService::headcountEm($contratos, $fim);
        $headcountInicio = RhIndicadoresService::headcountEm($contratos, $inicio->modify('-1 day'));
        $admissoesContratos = RhIndicadoresService::admissoesNoPeriodo($contratos, $inicio, $fim);
        $desligamentos = $this->desligamentosNoPeriodo($contratos, $inicio, $fim);
        $turnoverGeral = RhIndicadoresService::taxaTurnover(count($desligamentos), $headcountInicio, $headcountFim);
        $voluntarios = $this->contarPorCodigosMotivo($desligamentos, self::CODIGOS_VOLUNTARIO);
        $involuntarios = $this->contarPorCodigosMotivo($desligamentos, self::CODIGOS_INVOLUNTARIO);
        $turnoverVoluntario = RhIndicadoresService::taxaTurnover($voluntarios, $headcountInicio, $headcountFim);
        $turnoverInvoluntario = RhIndicadoresService::taxaTurnover($involuntarios, $headcountInicio, $headcountFim);

        // Vagas: filtro de Empresa traduzido de codigo_empresa (METADADOS) para o id local da
        // Empresa (dimensão do recrutamento) — reaproveita EmpresaMetadadosRepository, já oficial.
        // Setor não é suportado pelo módulo de Recrutamento hoje (vagas/solicitações não têm essa
        // dimensão) — não fingimos respeitar esse filtro aqui.
        //
        // Empresa selecionada mas SEM correspondência em `empresas.codigo_empresa`: nunca cai para
        // "sem filtro" (contarSolicitacoesAbertas/Fechadas(null) mostraria o TOTAL geral, ampliando
        // silenciosamente o universo de um filtro que o usuário pediu explicitamente). Distingue
        // as 3 situações: sem filtro, filtro resolvido, filtro sem correspondência.
        $vagas = $this->montarVagas($codigoEmpresa, $inicio, $fim);

        $integracoesRealizadas = $this->repository->contarIntegracoesRealizadas($inicio, $fim);
        $notasNps = $this->repository->buscarNotasNpsIntegracao($inicio, $fim);
        $avaliacaoExperiencia = $this->repository->avaliacaoExperiencia($inicio, $fim, RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS);

        return [
            'headcount' => [
                'atual' => $headcountFim,
            ],
            'vagas' => $vagas,
            'admissoes' => ['periodo' => count($admissoesContratos)],
            'desligamentos' => ['periodo' => count($desligamentos)],
            'turnover' => [
                'geral_percentual' => $turnoverGeral,
                'headcount_inicio' => $headcountInicio,
                'headcount_fim' => $headcountFim,
                'voluntario' => ['percentual' => $turnoverVoluntario, 'eventos' => $voluntarios],
                'involuntario' => ['percentual' => $turnoverInvoluntario, 'eventos' => $involuntarios],
                'genero' => ['disponivel' => false],
                'faixa_etaria' => $this->faixaEtariaDesligamentos($desligamentos),
            ],
            'integracao' => [
                'realizadas_periodo' => $integracoesRealizadas,
            ],
            'nps_integracao' => $this->calcularNps($notasNps),
            'avaliacao_experiencia' => $avaliacaoExperiencia,
            'colaboradores_por_setor' => $this->montarDistribuicaoPorSetor($codigoEmpresa),
            'banco_horas' => ['disponivel' => false],
            'horas_extras' => ['disponivel' => false],
            'ferias_programadas' => ['disponivel' => false],
            'ferias_a_vencer' => ['disponivel' => false],
        ];
    }

    /** Quantos desligamentos do período têm `motivo_rescisao_codigo` num dos códigos informados. */
    private function contarPorCodigosMotivo(array $desligamentos, array $codigos): int
    {
        $contagem = 0;
        foreach ($desligamentos as $contrato) {
            if (in_array((string)($contrato['motivo_rescisao_codigo'] ?? ''), $codigos, true)) {
                $contagem++;
            }
        }
        return $contagem;
    }

    /**
     * Vagas Abertas/Fechadas com a Empresa (quando filtrada) traduzida de codigo_empresa oficial
     * para o id local via EmpresaMetadadosRepository. Regra: filtro solicitado mas não resolvido
     * (código oficial sem par em `empresas.codigo_empresa`) NUNCA vira "sem filtro" — mostrar o
     * total geral nesse caso ampliaria silenciosamente o universo de um filtro que o usuário pediu
     * explicitamente. `empresa_sem_correspondencia` distingue esse caso de "sem filtro selecionado"
     * (ambos os contadores zerados) e de "filtro resolvido normalmente".
     */
    private function montarVagas(?string $codigoEmpresa, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        if ($codigoEmpresa === null || $codigoEmpresa === '') {
            $vagasAbertas = $this->recrutamentoRepository->contarSolicitacoesAbertas(null);
            $vagasFechadas = $this->recrutamentoRepository->contarSolicitacoesFechadas($inicio, $fim, null);
            return [
                'abertas' => (int)($vagasAbertas['total'] ?? 0),
                'fechadas_no_periodo' => (int)($vagasFechadas['total'] ?? 0),
                'empresa_sem_correspondencia' => false,
            ];
        }

        $empresaLocalId = (new EmpresaMetadadosRepository())->findIdByCodigo($codigoEmpresa);
        if ($empresaLocalId === null) {
            return ['abertas' => 0, 'fechadas_no_periodo' => 0, 'empresa_sem_correspondencia' => true];
        }

        $vagasAbertas = $this->recrutamentoRepository->contarSolicitacoesAbertas($empresaLocalId);
        $vagasFechadas = $this->recrutamentoRepository->contarSolicitacoesFechadas($inicio, $fim, $empresaLocalId);
        return [
            'abertas' => (int)($vagasAbertas['total'] ?? 0),
            'fechadas_no_periodo' => (int)($vagasFechadas['total'] ?? 0),
            'empresa_sem_correspondencia' => false,
        ];
    }

    /**
     * Desligamentos no período: cada CONTRATO encerrado é um evento real e distinto (não
     * deduplicado por pessoa) — uma pessoa com duas rescisões reais no mesmo período (readmissão
     * seguida de novo desligamento) representa dois eventos de desligamento genuínos, não um
     * "double count" a ser evitado. Mesma convenção de RhIndicadoresService::desligamentosNoPeriodo().
     *
     * @return array Contratos desligados no período (usados também na faixa etária).
     */
    private function desligamentosNoPeriodo(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $resultado = [];
        foreach ($contratos as $contrato) {
            $demissao = $this->parseData($contrato['demissao'] ?? null);
            if ($demissao !== null && $demissao >= $inicio && $demissao <= $fim) {
                $resultado[] = $contrato;
            }
        }
        return $resultado;
    }

    /**
     * Distribuição de idade (na data do desligamento) dos desligamentos do período, nas 5 faixas
     * já usadas pelo dashboard legado. Contratos sem `nascimento` ou `demissao` válidos ficam de
     * fora da distribuição (nunca classificados às cegas). `null` quando não há nenhum
     * desligamento classificável — "Dados insuficientes", nunca 0% em todas as faixas.
     */
    private function faixaEtariaDesligamentos(array $desligamentos): ?array
    {
        $contagem = [];
        foreach (self::FAIXAS_ETARIAS as $faixa) {
            $contagem[$faixa['label']] = 0;
        }

        $total = 0;
        foreach ($desligamentos as $contrato) {
            $nascimento = $this->parseData($contrato['nascimento'] ?? null);
            $demissao = $this->parseData($contrato['demissao'] ?? null);
            if ($nascimento === null || $demissao === null) {
                continue;
            }
            $idade = (int)$nascimento->diff($demissao)->y;
            $contagem[$this->rotuloFaixaEtaria($idade)]++;
            $total++;
        }

        if ($total === 0) {
            return null;
        }

        $resultado = [];
        foreach (self::FAIXAS_ETARIAS as $faixa) {
            $qtd = $contagem[$faixa['label']];
            $resultado[] = [
                'label' => $faixa['label'],
                'quantidade' => $qtd,
                'percentual' => round(($qtd / $total) * 100, 1),
            ];
        }
        return ['faixas' => $resultado, 'amostra' => $total];
    }

    private function rotuloFaixaEtaria(int $idade): string
    {
        foreach (self::FAIXAS_ETARIAS as $faixa) {
            if ($faixa['max'] === null || $idade <= $faixa['max']) {
                return $faixa['label'];
            }
        }
        return self::FAIXAS_ETARIAS[count(self::FAIXAS_ETARIAS) - 1]['label'];
    }

    /**
     * NPS a partir das notas 0-10 respondidas — Promotores 9-10, Neutros 7-8, Detratores 0-6.
     * Classificação e NPS SEMPRE calculados aqui, nunca persistidos. Amostra zero -> `nps: null`
     * (nunca 0 falso — ausência de amostra é diferente de "resultado neutro").
     */
    private function calcularNps(array $notas): array
    {
        $amostra = count($notas);
        if ($amostra === 0) {
            return ['amostra' => 0, 'nps' => null, 'promotores' => null, 'neutros' => null, 'detratores' => null];
        }

        $promotores = 0;
        $detratores = 0;
        foreach ($notas as $nota) {
            if ($nota >= 9) {
                $promotores++;
            } elseif ($nota <= 6) {
                $detratores++;
            }
        }
        $neutros = $amostra - $promotores - $detratores;
        $pctPromotores = ($promotores / $amostra) * 100;
        $pctDetratores = ($detratores / $amostra) * 100;

        return [
            'amostra' => $amostra,
            'nps' => round($pctPromotores - $pctDetratores, 1),
            'promotores' => $promotores,
            'neutros' => $neutros,
            'detratores' => $detratores,
        ];
    }

    private function montarDistribuicaoPorSetor(?string $codigoEmpresa): array
    {
        $linhas = $this->repository->distribuicaoAtivosPorSetor([
            'codigo_empresa' => $codigoEmpresa,
        ]);

        $comSetor = [];
        $semSetor = 0;
        foreach ($linhas as $linha) {
            $codigo = $linha['codigo_setor'] ?? null;
            $contratos = (int)($linha['contratos'] ?? 0);
            if ($codigo === null || $codigo === '') {
                $semSetor += $contratos;
                continue;
            }
            $comSetor[] = [
                'label' => (string)($linha['nome_oficial'] ?? $codigo),
                'quantidade' => $contratos,
            ];
        }

        usort($comSetor, static fn(array $a, array $b): int => $b['quantidade'] <=> $a['quantidade']);

        if ($semSetor > 0) {
            $comSetor[] = ['label' => 'Setor não informado', 'quantidade' => $semSetor];
        }

        return $comSetor;
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
