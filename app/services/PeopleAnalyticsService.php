<?php

/**
 * People Analytics — Tela Inicial/Dashboard principal do Portal RH.
 *
 * Unidade oficial dos indicadores corporativos de Headcount/movimentação (Headcount Atual,
 * Admissões, Desligamentos, Turnover Geral/Voluntário/Involuntário, Colaboradores por Setor,
 * Headcount/Turnover/Desligamentos por Empresa): o CONTRATO do METADADOS — nunca pessoa distinta.
 * Uma mesma pessoa pode ter múltiplos contratos oficiais válidos simultaneamente; eles não são
 * deduplicados por `codigo_pessoa`. Reaproveita diretamente `RhIndicadoresService::headcountEm()`/
 * `admissoesNoPeriodo()`/`desligamentosNoPeriodo()` para Headcount/Admissões/Desligamentos — a
 * mesma semântica já consolidada em Indicadores de RH, nunca uma interpretação paralela. Turnover
 * (Geral/Voluntário/Involuntário/por Empresa/por Setor/por Sexo/Evolução Mensal) usa a NOVA
 * fórmula oficial (2026-09): `RhIndicadoresService::ativosNoPeriodo()`/`taxaTurnoverPeriodo()`/
 * `turnoverPorDimensaoPeriodo()`/`serieMensalPeriodo()` — desligados do período / ativos do
 * período (nunca mais a média de headcount de `taxaTurnover()`/`turnoverPorDimensao()`, que
 * continuam intocados e seguem servindo só Indicadores de RH). Headcount por Empresa usa
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
 * Transferência interempresa (regra de negócio congelada em 2026-09): é MOVIMENTAÇÃO INTERNA,
 * nunca Admissão/Desligamento/Turnover real — classificada por
 * `MetadadosMovimentacaoService::classificarMovimentacoes()` (ponto único; Cenário A "contínua",
 * sem rescisão real; Cenário B, rescisão + novo contrato) e excluída via os parâmetros de
 * exclusão de `RhIndicadoresService` (nunca reimplementada aqui). Sem `DATAULTTRANSFERENCIA`
 * sincronizado para o espelho MySQL ainda (só existe no RHCONTRATOS ao vivo — ver
 * docs/claude/roadmap-tecnico.md), a correção fica limitada à população CONSOLIDADA e aos
 * eventos de Admissão/Desligamento; quebras por Empresa/Setor continuam vendo a vigência real de
 * cada lado sem um corte de data preciso — limitação conhecida, não um bug.
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
    private ColaboradorMetadadosConsultaRepository $consultaRepository;

    public function __construct(
        ?PeopleAnalyticsRepository $repository = null,
        ?RecrutamentoIndicadoresRepository $recrutamentoRepository = null,
        ?ColaboradorMetadadosConsultaRepository $consultaRepository = null
    ) {
        $this->repository = $repository ?? new PeopleAnalyticsRepository();
        $this->recrutamentoRepository = $recrutamentoRepository ?? new RecrutamentoIndicadoresRepository();
        $this->consultaRepository = $consultaRepository ?? new ColaboradorMetadadosConsultaRepository();
    }

    public function opcoesFiltro(): array
    {
        return $this->repository->opcoesFiltro();
    }

    public function montarPainel(
        array $filtros,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        ?DateTimeImmutable $compInicio = null,
        ?DateTimeImmutable $compFim = null
    ): array {
        $codigoEmpresa = $filtros['codigo_empresa'] ?? null;
        $codigoSetor = $filtros['codigo_setor'] ?? null;

        if ($compInicio === null || $compFim === null) {
            [$compInicio, $compFim] = RhIndicadoresService::periodoMesmoIntervaloAnoAnterior($inicio, $fim);
        }

        $contratos = $this->repository->buscarContratos([
            'codigo_empresa' => $codigoEmpresa,
            'codigo_setor' => $codigoSetor,
        ]);

        // ---- Transferência interempresa (regra de negócio congelada em 2026-09): movimentação
        // interna, nunca Turnover/Admissão/Desligamento real — ver MetadadosMovimentacaoService::
        // classificarMovimentacoes() (ponto único de classificação, Cenário A "contínua"/Cenário B
        // "rescisão + recontratação"). Fonte GLOBAL (nunca filtrada por Empresa/Setor: as duas
        // pontas de uma transferência estão, por definição, em empresas diferentes).
        $paresMovimentacao = $this->repository->buscarContratosParaMovimentacao();
        $classificacao = MetadadosMovimentacaoService::classificarMovimentacoes($paresMovimentacao);
        $excluirAdmissaoIds = $classificacao['excluir_admissao_ids'];
        $excluirDemissaoIds = $classificacao['excluir_demissao_ids'];

        // Vigência analítica (2026-09, RHCONTRATOS.DATAULTTRANSFERENCIA agora sincronizado em
        // `data_ultima_transferencia`): para os pares do Cenário A com data conhecida, deriva uma
        // CÓPIA com admissao/demissao ajustadas (origem até o dia anterior à transferência,
        // destino a partir do dia da transferência) — nunca muta $contratos/o espelho. Usada SÓ
        // para contar população (ativos do período/headcount), NUNCA para detectar eventos de
        // Admissão/Desligamento (que continuam usando $contratos original — a data de corte
        // analítica não pode virar um admissão/desligamento fantasma).
        $contratosVigenciaAnalitica = MetadadosMovimentacaoService::aplicarVigenciaAnalitica($contratos, $classificacao['continuas']);

        // Cenário A (contínua): o registro de origem (órfão, `ausente_na_origem=1`, nunca
        // demitido) sai da POPULAÇÃO CONSOLIDADA — o sucessor já carrega a admissão original
        // preservada, contar os dois somaria a mesma pessoa duas vezes (ver §13 da correção). Só
        // exclui quando o SUCESSOR também está no escopo atual de $contratos (sem filtro de
        // Empresa, ou um filtro que inclua as duas pontas) — filtrado só pela empresa de origem
        // (ex.: olhando só a Transportes), o registro precisa continuar contando NAQUELA visão,
        // porque não há sucessor "ali" para cobrir a população removida. Continua necessário
        // mesmo com a vigência analítica: um período que atravesse a data da transferência ainda
        // sobrepõe os dois lados (origem até o dia anterior, destino a partir do dia seguinte).
        // Cada quebra por dimensão (Turnover/Headcount por Empresa ou Setor) usa
        // `$contratosVigenciaAnalitica` diretamente (sem este dedup extra): mostrar a pessoa nos
        // dois lados quando o período atravessa a transferência é o comportamento correto ali.
        $contratosConsolidado = $this->filtrarPorIdentificadoresExcluidos(
            $contratosVigenciaAnalitica,
            $this->origemContinuaParaExcluir($contratosVigenciaAnalitica, $classificacao['continuas'])
        );

        // Unidade oficial dos indicadores corporativos de Headcount/movimentação: CONTRATO do
        // METADADOS (não pessoa distinta) — reaproveita a MESMA semântica consolidada em
        // RhIndicadoresService (headcountEm/admissoesNoPeriodo/desligamentosNoPeriodo), nunca uma
        // interpretação paralela. Uma mesma pessoa pode ter múltiplos contratos oficiais válidos —
        // eles não são deduplicados por codigo_pessoa aqui (só o par de transferência acima).
        $headcountFim = RhIndicadoresService::headcountEm($contratos, $fim);
        $headcountInicio = RhIndicadoresService::headcountEm($contratos, $inicio->modify('-1 day'));
        // Admissões/Desligamentos SEMPRE a partir de $contratos ORIGINAL (nunca de
        // $contratosConsolidado/vigência analítica) — a data de corte da transferência é uma
        // fronteira de POPULAÇÃO, não um evento; usá-la aqui fabricaria uma admissão/desligamento
        // que nunca aconteceu de verdade. Cenário A nunca precisa de exclusão de evento (origem
        // nunca tem demissao real, destino preserva a admissao original) — só o Cenário B usa
        // $excluirAdmissaoIds/$excluirDemissaoIds.
        $admissoesContratos = RhIndicadoresService::admissoesNoPeriodo($contratos, $inicio, $fim, $excluirAdmissaoIds);
        $desligamentos = $this->desligamentosNoPeriodo(
            $this->filtrarPorIdentificadoresExcluidos($contratos, $excluirDemissaoIds),
            $inicio,
            $fim
        );

        // Nova fórmula oficial de Turnover (2026-09): desligados do período / ATIVOS DO PERÍODO
        // (RhIndicadoresService::ativosNoPeriodo/taxaTurnoverPeriodo) — substitui a média de
        // headcount só nesta tela; RhIndicadoresService::taxaTurnover() não muda (continua
        // servindo Indicadores de RH). $headcountInicio/$headcountFim acima seguem calculados só
        // para o card Headcount Atual/Headcount por Empresa (já protegidos de duplicidade pelo
        // filtro `ausente_na_origem` existente, nunca mais como base do Turnover.
        $ativosPeriodo = count(RhIndicadoresService::ativosNoPeriodo($contratosConsolidado, $inicio, $fim));
        $turnoverGeral = RhIndicadoresService::taxaTurnoverPeriodo(count($desligamentos), $ativosPeriodo);
        $voluntarios = $this->contarPorCodigosMotivo($desligamentos, self::CODIGOS_VOLUNTARIO);
        $involuntarios = $this->contarPorCodigosMotivo($desligamentos, self::CODIGOS_INVOLUNTARIO);
        $outros = count($desligamentos) - $voluntarios - $involuntarios;
        $turnoverVoluntario = RhIndicadoresService::taxaTurnoverPeriodo($voluntarios, $ativosPeriodo);
        $turnoverInvoluntario = RhIndicadoresService::taxaTurnoverPeriodo($involuntarios, $ativosPeriodo);

        // Card "Headcount Atual" (fotografia de AGORA, distinta do $headcountFim usado acima como
        // referência de data — ver migration 2026-09-24-colaboradores-metadados-reconciliacao-
        // ausencia.sql): exclui `ausente_na_origem = 1` só quando $fim é realmente hoje (período
        // "Ano anterior", por exemplo, usa $fim = 31/12 do ano passado — nesse caso o card
        // representa uma fotografia histórica, e ausência detectada HOJE não pode reescrever o
        // passado). Registro de origem de uma transferência contínua já é `ausente_na_origem=1`,
        // então já sai daqui pelo filtro existente — nenhuma duplicidade possível neste card.
        // Turnover Geral/Voluntário/Involuntário acima continuam intocados.
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
        $vagas = $this->montarVagas($codigoEmpresa, $inicio, $fim);

        $integracoesRealizadas = $this->repository->contarIntegracoesRealizadas($inicio, $fim);
        $notasNps = $this->repository->buscarNotasNpsIntegracao($inicio, $fim);
        $avaliacaoExperiencia = $this->repository->avaliacaoExperiencia($inicio, $fim, RhIndicadoresService::LIMITE_TURNOVER_PRECOCE_DIAS);

        $nomesEmpresa = $this->mapaNomesEmpresa($contratos);
        $nomesSetor = $this->mapaNomesSetor();
        // Mesma base de contratos do card Headcount Atual (vigente quando $fim é hoje) — garante
        // que a soma das barras deste gráfico continue exatamente igual ao card acima. Quebra por
        // Empresa: cada empresa já vê só o seu próprio registro (origem/destino de uma
        // transferência caem em grupos diferentes), nenhuma exclusão de população necessária.
        $headcountPorEmpresa = $this->montarHeadcountPorEmpresa($contratosParaHeadcountAtual, $fim, $nomesEmpresa);
        [$turnoverPorEmpresa, $desligamentosPorEmpresa] = $this->montarTurnoverEDesligamentosPorEmpresa(
            $contratos,
            $inicio,
            $fim,
            $nomesEmpresa,
            $headcountPorEmpresa,
            $excluirDemissaoIds,
            $contratosVigenciaAnalitica
        );
        $turnoverPorSetor = $this->montarTurnoverPorSetor($contratos, $inicio, $fim, $nomesSetor, $excluirDemissaoIds, $contratosVigenciaAnalitica);

        // ---- Comparativo (mesmo intervalo ano anterior OU período imediatamente anterior, ver
        // AdminController) — reaproveita o MESMO array $contratos/$contratosConsolidado
        // (histórico completo, sem filtro de data), nenhuma query adicional por indicador.
        // Admissões/Desligamentos sempre de $contratos original (mesma razão do bloco acima).
        $desligamentosComp = $this->desligamentosNoPeriodo(
            $this->filtrarPorIdentificadoresExcluidos($contratos, $excluirDemissaoIds),
            $compInicio,
            $compFim
        );
        $ativosPeriodoComp = count(RhIndicadoresService::ativosNoPeriodo($contratosConsolidado, $compInicio, $compFim));
        $turnoverGeralComp = RhIndicadoresService::taxaTurnoverPeriodo(count($desligamentosComp), $ativosPeriodoComp);
        $admissoesComp = RhIndicadoresService::admissoesNoPeriodo($contratos, $compInicio, $compFim, $excluirAdmissaoIds);
        $headcountFimComp = RhIndicadoresService::headcountEm($contratosConsolidado, $compFim);

        // ---- Evolução mensal + Admissões×Desligamentos: um único dataset por período, SEMPRE com
        // o mesmo número de pontos nas duas séries (alinhamento por índice de mês, nunca por
        // padding de tamanhos diferentes — ver RhIndicadoresService::serieMensalComparativa()).
        // $contratos original alimenta admissões/desligamentos; $contratosConsolidado (vigência +
        // dedup) alimenta só ativos_periodo de cada mês — nunca fabrica pico de admissão/
        // desligamento na virada da transferência (ver §21 da correção).
        $serieMensal = RhIndicadoresService::serieMensalComparativa(
            $contratos,
            $inicio,
            $fim,
            $compInicio,
            $excluirAdmissaoIds,
            $excluirDemissaoIds,
            $contratosConsolidado
        );

        // ---- Desligamentos por Motivo: classificação JÁ existente do Dashboard de Turnover
        // (TurnoverDashboardService::MAPA_MOTIVOS) — deliberadamente DIFERENTE da classificação
        // Voluntário/Involuntário/Outros usada acima na Composição do Turnover Geral (mesma nota
        // já documentada em TurnoverDashboardService). Não uniformizar silenciosamente. Rescisões
        // técnicas usadas administrativamente para transferência (Cenário B) ficam de fora deste
        // gráfico de motivos reais — nunca apagadas, só reclassificadas para fora do Turnover.
        $desligamentosPorMotivo = TurnoverDashboardService::desligamentosPorMotivo(
            $this->filtrarPorIdentificadoresExcluidos($contratos, $excluirDemissaoIds),
            $inicio,
            $fim
        );

        $colaboradoresPorSetorComparativo = $this->montarColaboradoresPorSetorComparativo(
            $contratosVigenciaAnalitica,
            $inicio,
            $fim,
            $compInicio,
            $compFim,
            $nomesSetor
        );

        return [
            'periodo' => ['inicio' => $inicio, 'fim' => $fim],
            'headcount' => [
                'atual' => $headcountAtualVigente,
                'comparativo' => $headcountFimComp,
                'variacao_absoluta' => $headcountAtualVigente - $headcountFimComp,
                'variacao_percentual' => $this->variacaoPercentual($headcountAtualVigente, $headcountFimComp),
            ],
            'headcount_por_empresa' => $headcountPorEmpresa,
            'vagas' => $vagas,
            'admissoes' => [
                'periodo' => count($admissoesContratos),
                'comparativo' => count($admissoesComp),
                'variacao_absoluta' => count($admissoesContratos) - count($admissoesComp),
                'variacao_percentual' => $this->variacaoPercentual(count($admissoesContratos), count($admissoesComp)),
            ],
            'desligamentos' => [
                'periodo' => count($desligamentos),
                'comparativo' => count($desligamentosComp),
                'variacao_absoluta' => count($desligamentos) - count($desligamentosComp),
                'variacao_percentual' => $this->variacaoPercentual(count($desligamentos), count($desligamentosComp)),
            ],
            'desligamentos_por_empresa' => $desligamentosPorEmpresa,
            'desligamentos_por_motivo' => $desligamentosPorMotivo,
            'turnover' => [
                'geral_percentual' => $turnoverGeral,
                'ativos_periodo' => $ativosPeriodo,
                'headcount_inicio' => $headcountInicio,
                'headcount_fim' => $headcountFim,
                'comparativo' => [
                    'percentual' => $turnoverGeralComp,
                    'ativos_periodo' => $ativosPeriodoComp,
                    'desligamentos' => count($desligamentosComp),
                    'variacao_pontos_percentuais' => round($turnoverGeral - $turnoverGeralComp, 1),
                ],
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
                'genero' => $this->montarTurnoverPorSexo($contratos, $inicio, $fim, $compInicio, $compFim, $excluirDemissaoIds, $contratosConsolidado),
                'faixa_etaria' => $this->faixaEtariaDesligamentos($desligamentos),
                'por_empresa' => $turnoverPorEmpresa,
                'por_setor' => $turnoverPorSetor,
            ],
            'evolucao_mensal' => [
                'atual' => $serieMensal['atual'],
                'comparativo' => $serieMensal['comparativo'],
            ],
            'comparativo' => [
                'periodo' => ['inicio' => $compInicio, 'fim' => $compFim],
                'headcount' => $headcountFimComp,
                'admissoes' => count($admissoesComp),
                'desligamentos' => count($desligamentosComp),
                'ativos_periodo' => $ativosPeriodoComp,
                'turnover_percentual' => $turnoverGeralComp,
            ],
            'integracao' => [
                'realizadas_periodo' => $integracoesRealizadas,
            ],
            'nps_integracao' => $this->calcularNps($notasNps),
            'avaliacao_experiencia' => $avaliacaoExperiencia,
            'colaboradores_por_setor' => $this->montarDistribuicaoPorSetor($codigoEmpresa),
            'colaboradores_por_setor_comparativo' => $colaboradoresPorSetorComparativo,
            'transferencias' => [
                'continuas' => count($classificacao['continuas']),
                'recontratacoes' => count($classificacao['recontratacoes']),
            ],
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

    private function montarTurnoverPorSexo(
        array $contratos,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        DateTimeImmutable $compInicio,
        DateTimeImmutable $compFim,
        ?array $excluirDemissaoIds = null,
        ?array $contratosParaAtivos = null
    ): array {
        $porSexo = $this->indexarPorLabel(RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'sexo', $inicio, $fim, $excluirDemissaoIds, $contratosParaAtivos));
        $porSexoComp = $this->indexarPorLabel(RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'sexo', $compInicio, $compFim, $excluirDemissaoIds, $contratosParaAtivos));

        $grupoVazio = static fn(string $label): array => [
            'label' => $label, 'desligamentos' => 0, 'ativos_periodo' => 0, 'taxa' => 0.0,
        ];
        $montarGrupo = static function (string $chave) use ($porSexo, $porSexoComp, $grupoVazio): array {
            $atual = $porSexo[$chave] ?? $grupoVazio($chave);
            $atual['comparativo_taxa'] = $porSexoComp[$chave]['taxa'] ?? null;
            return $atual;
        };

        return [
            'disponivel' => true,
            'masculino' => $montarGrupo('M'),
            'feminino' => $montarGrupo('F'),
            'nao_informado' => isset($porSexo[RhIndicadoresService::NAO_INFORMADO]) ? $montarGrupo(RhIndicadoresService::NAO_INFORMADO) : null,
        ];
    }

    /** @return array<string,array> Grupos de turnoverPorDimensaoPeriodo() indexados por label. */
    private function indexarPorLabel(array $grupos): array
    {
        $porLabel = [];
        foreach ($grupos as $grupo) {
            $porLabel[$grupo['label']] = $grupo;
        }
        return $porLabel;
    }

    /** Variação percentual relativa entre um valor atual e um valor comparativo; null sem base (evita divisão por zero). */
    private function variacaoPercentual(float $atual, float $comparativo): ?float
    {
        if ($comparativo <= 0) {
            return null;
        }
        return round((($atual - $comparativo) / $comparativo) * 100, 1);
    }


    /**
     * Remove de `$contratos` qualquer registro cujo `identificador` esteja em `$idsExcluir` — usa
     * o mesmo `identificador` que MetadadosMovimentacaoService devolve em seus pares
     * classificados. `$idsExcluir` vazio devolve `$contratos` sem nenhuma cópia/alocação extra.
     */
    private function filtrarPorIdentificadoresExcluidos(array $contratos, array $idsExcluir): array
    {
        if ($idsExcluir === []) {
            return $contratos;
        }
        return array_values(array_filter(
            $contratos,
            static fn(array $c): bool => !in_array((string)($c['identificador'] ?? ''), $idsExcluir, true)
        ));
    }


    /**
     * Dos pares do Cenário A (transferência contínua), só devolve a origem para exclusão quando o
     * SUCESSOR (destino) também está presente no MESMO escopo de `$contratos` — a classificação
     * em si é sempre global (buscarContratosParaMovimentacao()), mas a decisão de excluir depende
     * do que está realmente em jogo na consulta atual (ver comentário em montarPainel()).
     */
    private function origemContinuaParaExcluir(array $contratos, array $paresContinuas): array
    {
        $presentes = array_flip(array_column($contratos, 'identificador'));
        $excluir = [];
        foreach ($paresContinuas as $par) {
            $origemId = $par['contrato_origem'] ?? null;
            $destinoId = $par['contrato_destino'] ?? null;
            if ($origemId !== null && $destinoId !== null && isset($presentes[$origemId]) && isset($presentes[$destinoId])) {
                $excluir[] = $origemId;
            }
        }
        return $excluir;
    }

    /**
     * codigo_setor -> nome oficial, a partir do catálogo local `setores` (mesma fonte de
     * PeopleAnalyticsRepository::opcoesFiltro(), reaproveitada aqui — nenhuma query nova).
     */
    private function mapaNomesSetor(): array
    {
        $nomes = [];
        foreach ($this->repository->opcoesFiltro()['setores'] as $setor) {
            $codigo = (string)($setor['codigo_setor'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $nomes[$codigo] = (string)($setor['nome'] ?? $codigo);
        }
        return $nomes;
    }

    /**
     * Turnover por Setor — nova fórmula (RhIndicadoresService::turnoverPorDimensaoPeriodo()), nome
     * oficial resolvido pelo catálogo local `setores`. Sem codigo_setor cai em "Setor não
     * informado" — nunca inferido de outra fonte (mesma convenção de Colaboradores por Setor).
     *
     * @param string[]|null $excluirDemissaoIds "Saída por transferência" (Cenário B) nunca entra
     *        no numerador, em nenhum Setor.
     * @param array|null $contratosParaAtivos Vigência analítica (Cenário A) para ativos_periodo —
     *        ver MetadadosMovimentacaoService::aplicarVigenciaAnalitica().
     */
    private function montarTurnoverPorSetor(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim, array $nomesSetor, ?array $excluirDemissaoIds = null, ?array $contratosParaAtivos = null): array
    {
        $resultado = [];
        foreach (RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'codigo_setor', $inicio, $fim, $excluirDemissaoIds, $contratosParaAtivos) as $linha) {
            $codigo = (string)$linha['label'];
            $semCodigo = $codigo === RhIndicadoresService::NAO_INFORMADO;
            $resultado[] = [
                'codigo' => $semCodigo ? '' : $codigo,
                'label' => $semCodigo ? 'Setor não informado' : ($nomesSetor[$codigo] ?? $codigo),
                'desligamentos' => $linha['desligamentos'],
                'ativos_periodo' => $linha['ativos_periodo'],
                'taxa' => $linha['taxa'],
            ];
        }
        return $resultado;
    }

    /**
     * Colaboradores por Setor comparativo (barras duplas): para cada Setor, quantos contratos
     * estiveram ATIVOS EM ALGUM MOMENTO do período selecionado × do período comparativo — nunca a
     * fotografia de "agora" usada por montarDistribuicaoPorSetor()/colaboradores_por_setor (esse
     * card permanece intocado). "Setor não informado" nunca é omitido.
     */
    private function montarColaboradoresPorSetorComparativo(
        array $contratos,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        DateTimeImmutable $compInicio,
        DateTimeImmutable $compFim,
        array $nomesSetor
    ): array {
        $porAtual = $this->indexarPorLabel(RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'codigo_setor', $inicio, $fim));
        $porComp = $this->indexarPorLabel(RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'codigo_setor', $compInicio, $compFim));

        $codigos = array_unique(array_merge(array_keys($porAtual), array_keys($porComp)));
        $resultado = [];
        foreach ($codigos as $codigo) {
            $semCodigo = $codigo === RhIndicadoresService::NAO_INFORMADO;
            $atual = (int)($porAtual[$codigo]['ativos_periodo'] ?? 0);
            $comparativo = (int)($porComp[$codigo]['ativos_periodo'] ?? 0);
            if ($atual === 0 && $comparativo === 0) {
                continue;
            }
            $resultado[] = [
                'codigo' => $semCodigo ? '' : $codigo,
                'label' => $semCodigo ? 'Setor não informado' : ($nomesSetor[$codigo] ?? $codigo),
                'atual' => $atual,
                'comparativo' => $comparativo,
            ];
        }

        usort($resultado, static fn(array $a, array $b): int => $b['atual'] <=> $a['atual']);
        return $resultado;
    }

    /**
     * Turnover por Empresa e Desligamentos por Empresa a partir de UMA ÚNICA chamada a
     * RhIndicadoresService::turnoverPorDimensaoPeriodo() (nova fórmula oficial: desligados do
     * período / ativos do período, cada Empresa com sua própria população) — nenhuma fórmula
     * reimplementada aqui. O código de Empresa (label bruto de turnoverPorDimensaoPeriodo) é
     * traduzido para o nome de exibição pelo mesmo mapa usado em Headcount por Empresa.
     *
     * Ordem: Turnover por Empresa segue a MESMA ordem (por quantidade de Headcount, maior primeiro)
     * do gráfico de Headcount por Empresa — permite comparar as duas barras lado a lado sem
     * reordenar mentalmente. Desligamentos por Empresa tem ranking próprio, por número de eventos.
     *
     * @param string[]|null $excluirDemissaoIds "Saída por transferência" (Cenário B) nunca entra
     *        no numerador nem no ranking de Desligamentos por Empresa.
     * @param array|null $contratosParaAtivos Vigência analítica (Cenário A) para ativos_periodo —
     *        ver MetadadosMovimentacaoService::aplicarVigenciaAnalitica().
     * @return array{0: array, 1: array} [turnoverPorEmpresa, desligamentosPorEmpresa]
     */
    private function montarTurnoverEDesligamentosPorEmpresa(
        array $contratos,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        array $nomesEmpresa,
        array $headcountPorEmpresa,
        ?array $excluirDemissaoIds = null,
        ?array $contratosParaAtivos = null
    ): array {
        $porDimensao = RhIndicadoresService::turnoverPorDimensaoPeriodo($contratos, 'codigo_empresa', $inicio, $fim, $excluirDemissaoIds, $contratosParaAtivos);

        $porCodigo = [];
        foreach ($porDimensao as $linha) {
            $codigo = $linha['label'];
            $porCodigo[$codigo] = [
                'codigo' => $codigo,
                'label' => $nomesEmpresa[$codigo] ?? $codigo,
                'taxa' => $linha['taxa'],
                'desligamentos' => $linha['desligamentos'],
                'ativos_periodo' => $linha['ativos_periodo'],
            ];
        }

        // Segue a ordem de Headcount por Empresa quando o código existe nos dois (caso normal);
        // qualquer código que só aparece em turnoverPorDimensaoPeriodo (nunca deveria acontecer,
        // mesma base de $contratos) entra no final, sem perder o dado.
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


    // -----------------------------------------------------------------------------------------
    // Listagem de Colaboradores (correção de 2026-09) — operacional, não analítica: traz nome e
    // outros dados identificáveis (nunca salário/CPF/dados bancários — ver
    // ColaboradorMetadadosConsultaRepository::paginateExecutivo()/listarExecutivo()). Responde
    // aos MESMOS filtros do topo do dashboard (empresa/setor); população padrão = vigentes
    // (ativo=1, não ausente). Sem interatividade por clique nesta rodada.
    // -----------------------------------------------------------------------------------------

    /**
     * @param array $filtros Chaves aceitas por ColaboradorMetadadosConsultaRepository::
     *              paginateExecutivo(): codigo_empresa (mapeado para `empresa`), codigo_setor,
     *              situacao ('ativos'|'desligados'|''), situacao_metadados.
     * @return array{items:array,total:int,page:int,per_page:int,pages:int}
     */
    public function listarColaboradores(array $filtros, int $page, int $perPage): array
    {
        $pagina = $this->consultaRepository->paginateExecutivo($this->mapearFiltrosListagem($filtros), $page, $perPage);
        $pagina['items'] = $this->enriquecerListagemColaboradores($pagina['items']);
        return $pagina;
    }

    /** Mesma população de listarColaboradores(), sem paginação — só para exportação CSV. */
    public function exportarColaboradoresCsv(array $filtros): array
    {
        $linhas = $this->consultaRepository->listarExecutivo($this->mapearFiltrosListagem($filtros));
        return $this->enriquecerListagemColaboradores($linhas);
    }

    /**
     * Traduz os filtros do topo do People Analytics (codigo_empresa/codigo_setor) para as
     * chaves que ColaboradorMetadadosConsultaRepository entende — `empresa` dela é `codigo_empresa`
     * aqui (mesmo nome de coluna, evita ambiguidade com o texto `empresa` do METADADOS).
     */
    private function mapearFiltrosListagem(array $filtros): array
    {
        $mapeado = ['situacao' => 'ativos'];
        if (!empty($filtros['codigo_empresa'])) {
            $mapeado['empresa'] = $filtros['codigo_empresa'];
        }
        if (!empty($filtros['codigo_setor'])) {
            $mapeado['codigo_setor'] = $filtros['codigo_setor'];
        }
        return $mapeado;
    }

    /**
     * Enriquece cada linha da listagem com Situação (Ativo/Desligado/Transferido — nunca
     * inventada, vem da MESMA classificação central de MetadadosMovimentacaoService usada no
     * painel) e Tempo de Empresa (a partir da admissão real, já preservada pelo METADADOS nas
     * transferências contínuas — nunca recalculada por vigência analítica, que é só para
     * população agregada, não para o registro individual).
     */
    private function enriquecerListagemColaboradores(array $linhas): array
    {
        if ($linhas === []) {
            return [];
        }

        $paresMovimentacao = $this->repository->buscarContratosParaMovimentacao();
        $classificacao = MetadadosMovimentacaoService::classificarMovimentacoes($paresMovimentacao);
        $origensTransferencia = [];
        foreach ($classificacao['continuas'] as $par) {
            if ($par['contrato_origem'] !== null) {
                $origensTransferencia[$par['contrato_origem']] = true;
            }
        }
        foreach ($classificacao['recontratacoes'] as $par) {
            if ($par['contrato_origem'] !== null) {
                $origensTransferencia[$par['contrato_origem']] = true;
            }
        }

        $hoje = new DateTimeImmutable('today');

        return array_map(function (array $linha) use ($origensTransferencia, $hoje): array {
            $ativo = (int)($linha['ativo'] ?? 0) === 1;
            $identificador = (string)($linha['identificador'] ?? '');
            if ($ativo) {
                $situacao = 'Ativo';
            } elseif (isset($origensTransferencia[$identificador])) {
                $situacao = 'Transferido';
            } else {
                $situacao = 'Desligado';
            }

            $admissao = $this->parseData($linha['admissao'] ?? null);
            $demissao = $this->parseData($linha['demissao'] ?? null);
            $tempoEmpresa = $admissao !== null ? $this->formatarTempoEmpresa($admissao, $demissao ?? $hoje) : '—';

            $sexo = trim((string)($linha['sexo'] ?? ''));
            $setor = trim((string)($linha['setor'] ?? ''));
            $centroCusto = trim((string)($linha['centro_custo'] ?? ''));
            $gestor = trim((string)($linha['gestor_nome'] ?? ''));

            return [
                'nome' => (string)($linha['nome'] ?? ''),
                'empresa' => trim((string)($linha['empresa'] ?? '')) !== '' ? $linha['empresa'] : (string)($linha['codigo_empresa'] ?? ''),
                'unidade' => (string)($linha['unidade'] ?? ''),
                'setor' => $setor !== '' ? $setor : 'Não informado',
                'cargo' => (string)($linha['cargo'] ?? ''),
                'sexo' => $sexo === 'M' ? 'Masculino' : ($sexo === 'F' ? 'Feminino' : 'Não informado'),
                'admissao' => $admissao?->format('d/m/Y') ?? '—',
                'situacao' => $situacao,
                'tempo_empresa' => $tempoEmpresa,
                'centro_custo' => $centroCusto !== '' ? $centroCusto : 'Não informado',
                'gestor_imediato' => $gestor !== '' ? $gestor : 'Não informado',
            ];
        }, $linhas);
    }

    /** "3 anos e 4 meses" · sem anos/meses completos, cai para dias · nunca negativo. */
    private function formatarTempoEmpresa(DateTimeImmutable $admissao, DateTimeImmutable $referencia): string
    {
        if ($referencia < $admissao) {
            return '—';
        }
        $diff = $admissao->diff($referencia);
        $partes = [];
        if ($diff->y > 0) {
            $partes[] = $diff->y . ' ano' . ($diff->y > 1 ? 's' : '');
        }
        if ($diff->m > 0) {
            $partes[] = $diff->m . ' ' . ($diff->m > 1 ? 'meses' : 'mês');
        }
        if ($partes === []) {
            return $diff->d . ' dia' . ($diff->d !== 1 ? 's' : '');
        }
        return implode(' e ', $partes);
    }
}
