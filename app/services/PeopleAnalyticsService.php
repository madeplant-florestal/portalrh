<?php

/**
 * People Analytics — Tela Inicial/Dashboard principal do Portal RH.
 *
 * Unidade oficial dos indicadores corporativos de Headcount/movimentação (Headcount Atual,
 * Admissões, Desligamentos, Turnover Geral/Voluntário/Involuntário, Colaboradores por Setor,
 * Headcount/Turnover/Desligamentos por Empresa): o CONTRATO do METADADOS — nunca pessoa distinta.
 * Uma mesma pessoa pode ter múltiplos contratos oficiais válidos simultaneamente; eles não são
 * deduplicados por `codigo_pessoa`. Reaproveita diretamente `RhIndicadoresService::headcountEm()`/
 * `admissoesNoPeriodo()`/`taxaTurnover()`/`turnoverPorDimensao()` — a mesma semântica já
 * consolidada em Indicadores de RH, nunca uma interpretação paralela. Headcount por Empresa usa
 * headcountEm() agrupado com a MESMA data de referência ($fim) do card Headcount Atual — nunca
 * `RhIndicadoresService::distribuicao()`, que avalia sempre em "hoje" e divergiria do card sempre
 * que o período selecionado não terminar hoje. Quando um indicador futuro precisar ser baseado em
 * PESSOA em vez de contrato, isso deve ser uma decisão explícita do negócio, documentada aqui —
 * nunca inferida.
 *
 * Nome de Empresa (Headcount/Turnover/Desligamentos por Empresa): resolvido a partir da própria
 * coluna de texto `empresa` do METADADOS (mapeada por `codigo_empresa`), mesma fonte de
 * `PeopleAnalyticsRepository::opcoesFiltro()` — nunca a tabela local `empresas` (catálogo do
 * Recrutamento, id-based, não é garantidamente completo em relação ao universo do METADADOS).
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
        $outros = count($desligamentos) - $voluntarios - $involuntarios;
        $turnoverVoluntario = RhIndicadoresService::taxaTurnover($voluntarios, $headcountInicio, $headcountFim);
        $turnoverInvoluntario = RhIndicadoresService::taxaTurnover($involuntarios, $headcountInicio, $headcountFim);

        // Card "Headcount Atual" (fotografia de AGORA, distinta do $headcountFim usado acima como
        // base do Turnover — ver migration 2026-09-24-colaboradores-metadados-reconciliacao-
        // ausencia.sql): exclui `ausente_na_origem = 1` só quando $fim é realmente hoje (período
        // "Ano anterior", por exemplo, usa $fim = 31/12 do ano passado — nesse caso o card
        // representa uma fotografia histórica, e ausência detectada HOJE não pode reescrever o
        // passado). Turnover Geral/Voluntário/Involuntário acima continuam intocados, sempre
        // sobre $headcountFim/$contratos completos — nenhuma fórmula de Turnover muda aqui.
        $hoje = new DateTimeImmutable('today');
        $fimEhHoje = $fim->format('Y-m-d') === $hoje->format('Y-m-d');
        $contratosParaHeadcountAtual = $contratos;
        $headcountAtualVigente = $headcountFim;
        if ($fimEhHoje) {
            $contratosParaHeadcountAtual = array_values(array_filter(
                $contratos,
                static fn(array $c): bool => (int)($c['ausente_na_origem'] ?? 0) === 0
            ));
            $headcountAtualVigente = RhIndicadoresService::headcountEm($contratosParaHeadcountAtual, $fim);
        }

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

        $nomesEmpresa = $this->mapaNomesEmpresa($contratos);
        // Mesma base de contratos do card Headcount Atual (vigente quando $fim é hoje) — garante
        // que a soma das barras deste gráfico continue exatamente igual ao card acima.
        $headcountPorEmpresa = $this->montarHeadcountPorEmpresa($contratosParaHeadcountAtual, $fim, $nomesEmpresa);
        [$turnoverPorEmpresa, $desligamentosPorEmpresa] = $this->montarTurnoverEDesligamentosPorEmpresa(
            $contratos,
            $inicio,
            $fim,
            $nomesEmpresa,
            $headcountPorEmpresa
        );

        return [
            'headcount' => [
                'atual' => $headcountAtualVigente,
            ],
            'headcount_por_empresa' => $headcountPorEmpresa,
            'vagas' => $vagas,
            'admissoes' => ['periodo' => count($admissoesContratos)],
            'desligamentos' => ['periodo' => count($desligamentos)],
            'desligamentos_por_empresa' => $desligamentosPorEmpresa,
            'turnover' => [
                'geral_percentual' => $turnoverGeral,
                'headcount_inicio' => $headcountInicio,
                'headcount_fim' => $headcountFim,
                'voluntario' => [
                    'percentual' => $turnoverVoluntario,
                    'eventos' => $voluntarios,
                    'participacao_desligamentos' => $this->participacaoDesligamentos($voluntarios, count($desligamentos)),
                ],
                'involuntario' => [
                    'percentual' => $turnoverInvoluntario,
                    'eventos' => $involuntarios,
                    'participacao_desligamentos' => $this->participacaoDesligamentos($involuntarios, count($desligamentos)),
                ],
                'outros' => [
                    'eventos' => $outros,
                    'participacao_desligamentos' => $this->participacaoDesligamentos($outros, count($desligamentos)),
                ],
                'genero' => ['disponivel' => false],
                'faixa_etaria' => $this->faixaEtariaDesligamentos($desligamentos),
                'por_empresa' => $turnoverPorEmpresa,
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

    /** Participação (%) de uma contagem sobre o total de desligamentos do período — só para a
     *  composição visual da rosca Voluntário×Involuntário×Outros (nunca confundir com a taxa de
     *  Turnover, que usa headcount como base). Sem desligamentos no período, 0.0 (a view decide
     *  omitir a rosca inteira nesse caso, nunca mostrar fatias falsas). */
    private function participacaoDesligamentos(int $eventos, int $totalDesligamentos): float
    {
        if ($totalDesligamentos <= 0) {
            return 0.0;
        }
        return round(($eventos / $totalDesligamentos) * 100, 1);
    }

    /**
     * codigo_empresa -> nome de exibição, a partir dos próprios contratos já carregados (mesma
     * fonte/convenção de PeopleAnalyticsRepository::opcoesFiltro(): coluna de texto `empresa` do
     * METADADOS — nunca a tabela local `empresas` do Recrutamento). Quando mais de um nome
     * aparece para o mesmo código (razão social alterada ao longo do histórico), fica com o maior
     * valor (string), mesmo critério de desempate do `MAX(empresa)` já usado em opcoesFiltro().
     * Código sem nenhum nome associado fica de fora do mapa — quem consome cai no fallback para o
     * próprio código, nunca um nome inventado.
     */
    private function mapaNomesEmpresa(array $contratos): array
    {
        $nomes = [];
        foreach ($contratos as $contrato) {
            $codigo = (string)($contrato['codigo_empresa'] ?? '');
            $nome = trim((string)($contrato['empresa'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }
            if (!isset($nomes[$codigo]) || $nome > $nomes[$codigo]) {
                $nomes[$codigo] = $nome;
            }
        }
        return $nomes;
    }

    /**
     * Headcount por Empresa: mesma unidade (CONTRATO) e MESMA data de referência ($fim, o fim do
     * período selecionado) do card "Headcount Atual" — nunca `RhIndicadoresService::distribuicao()`
     * aqui, porque ela avalia sempre em "hoje" (hardcoded), o que divergiria do card superior
     * sempre que o período selecionado não terminar hoje (ex.: filtro "Ano anterior"). A soma das
     * barras deste gráfico é sempre idêntica ao valor do card Headcount Atual.
     *
     * @return array Lista ordenada por quantidade desc: [['codigo' , 'label', 'quantidade'], ...]
     */
    private function montarHeadcountPorEmpresa(array $contratos, DateTimeImmutable $fim, array $nomesEmpresa): array
    {
        $porEmpresa = [];
        foreach ($contratos as $contrato) {
            $codigo = (string)($contrato['codigo_empresa'] ?? '');
            $chave = $codigo !== '' ? $codigo : RhIndicadoresService::NAO_INFORMADO;
            $porEmpresa[$chave][] = $contrato;
        }

        $resultado = [];
        foreach ($porEmpresa as $codigo => $contratosDaEmpresa) {
            $resultado[] = [
                'codigo' => $codigo,
                'label' => $nomesEmpresa[$codigo] ?? $codigo,
                'quantidade' => RhIndicadoresService::headcountEm($contratosDaEmpresa, $fim),
            ];
        }

        usort($resultado, static fn(array $a, array $b): int => $b['quantidade'] <=> $a['quantidade']);
        return $resultado;
    }

    /**
     * Turnover por Empresa e Desligamentos por Empresa a partir de UMA ÚNICA chamada a
     * RhIndicadoresService::turnoverPorDimensao() (headcount início/fim/turnover já consolidados,
     * mesma âncora "início do período - 1 dia" do Turnover Geral) — nenhuma fórmula reimplementada
     * aqui. O código de Empresa (label bruto de turnoverPorDimensao) é traduzido para o nome de
     * exibição pelo mesmo mapa usado em Headcount por Empresa.
     *
     * Ordem: Turnover por Empresa segue a MESMA ordem (por quantidade de Headcount, maior primeiro)
     * do gráfico de Headcount por Empresa — permite comparar as duas barras lado a lado sem
     * reordenar mentalmente. Desligamentos por Empresa tem ranking próprio, por número de eventos.
     *
     * @return array{0: array, 1: array} [turnoverPorEmpresa, desligamentosPorEmpresa]
     */
    private function montarTurnoverEDesligamentosPorEmpresa(
        array $contratos,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        array $nomesEmpresa,
        array $headcountPorEmpresa
    ): array {
        $porDimensao = RhIndicadoresService::turnoverPorDimensao($contratos, 'codigo_empresa', $inicio, $fim);

        $porCodigo = [];
        foreach ($porDimensao as $linha) {
            $codigo = $linha['label'];
            $porCodigo[$codigo] = [
                'codigo' => $codigo,
                'label' => $nomesEmpresa[$codigo] ?? $codigo,
                'taxa' => $linha['taxa'],
                'desligamentos' => $linha['desligamentos'],
            ];
        }

        // Segue a ordem de Headcount por Empresa quando o código existe nos dois (caso normal);
        // qualquer código que só aparece em turnoverPorDimensao (nunca deveria acontecer, mesma
        // base de $contratos) entra no final, sem perder o dado.
        $turnoverPorEmpresa = [];
        foreach ($headcountPorEmpresa as $item) {
            if (isset($porCodigo[$item['codigo']])) {
                $turnoverPorEmpresa[] = $porCodigo[$item['codigo']];
                unset($porCodigo[$item['codigo']]);
            }
        }
        foreach ($porCodigo as $restante) {
            $turnoverPorEmpresa[] = $restante;
        }

        $desligamentosPorEmpresa = $turnoverPorEmpresa;
        usort($desligamentosPorEmpresa, static fn(array $a, array $b): int => $b['desligamentos'] <=> $a['desligamentos']);

        return [$turnoverPorEmpresa, $desligamentosPorEmpresa];
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
