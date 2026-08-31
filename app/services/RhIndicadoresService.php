<?php

/**
 * Camada analítica de RH (Fase 4) — transforma os contratos do espelho `colaboradores_metadados`
 * em indicadores gerenciais (headcount, admissões, desligamentos, turnover, turnover precoce,
 * motivos de rescisão, tempo de permanência). Fórmulas e decisões documentadas em
 * docs/claude/indicadores-rh.md — este arquivo implementa, não redefine, o que está lá.
 *
 * Unidade básica de análise é o CONTRATO (uma linha de colaboradores_metadados), nunca a pessoa —
 * readmissões geram múltiplos contratos para o mesmo codigo_pessoa/cpf, e cada um é contado por
 * sua própria vigência (admissão/demissão), nunca deduplicado.
 *
 * Todo método de cálculo é `static` e opera só sobre arrays já carregados — testável sem banco
 * (mesmo padrão de ColaboradorMetadadosReconciliationService::analyze()). Só `montarPainel()`
 * toca banco, delegando a leitura para RhIndicadoresRepository.
 */
class RhIndicadoresService
{
    public const NAO_INFORMADO = 'Não informado';

    /** Faixas de permanência para turnover precoce — ordem crescente, fronteiras inclusivas. */
    public const FAIXAS_PERMANENCIA = [
        ['label' => 'Até 30 dias', 'min' => 0, 'max' => 30],
        ['label' => '31 a 60 dias', 'min' => 31, 'max' => 60],
        ['label' => '61 a 90 dias', 'min' => 61, 'max' => 90],
        ['label' => '91 a 180 dias', 'min' => 91, 'max' => 180],
        ['label' => '181 a 365 dias', 'min' => 181, 'max' => 365],
        ['label' => 'Acima de 365 dias', 'min' => 366, 'max' => null],
    ];

    /** Limite (em dias) do indicador headline "turnover precoce" — ver dicionário para justificativa. */
    public const LIMITE_TURNOVER_PRECOCE_DIAS = 90;

    /** Faixas de tempo de casa para colaboradores ativos. */
    public const FAIXAS_TEMPO_CASA = [
        ['label' => 'Até 1 ano', 'min' => 0, 'max' => 365],
        ['label' => '1 a 3 anos', 'min' => 366, 'max' => 1095],
        ['label' => '3 a 5 anos', 'min' => 1096, 'max' => 1825],
        ['label' => '5 a 10 anos', 'min' => 1826, 'max' => 3650],
        ['label' => 'Acima de 10 anos', 'min' => 3651, 'max' => null],
    ];

    private RhIndicadoresRepository $repository;

    public function __construct(?PDO $pdo = null, ?RhIndicadoresRepository $repository = null)
    {
        $this->repository = $repository ?? new RhIndicadoresRepository($pdo);
    }

    // ---------------------------------------------------------------------
    // Orquestração (única parte que toca banco)
    // ---------------------------------------------------------------------

    public function montarPainel(array $filtrosDimensao, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $contratos = $this->repository->buscarContratos($filtrosDimensao);
        return self::montarPainelComContratos($contratos, $inicio, $fim);
    }

    public function opcoesFiltro(): array
    {
        return $this->repository->opcoesFiltro();
    }

    public function periodoDisponivel(): array
    {
        return $this->repository->periodoDisponivel();
    }

    // ---------------------------------------------------------------------
    // Núcleo puro — testável com arrays sintéticos, sem banco
    // ---------------------------------------------------------------------

    /**
     * @param array $contratos Cada item: admissao, demissao, cargo, setor, centro_custo,
     *                         codigo_empresa, empresa, codigo_unidade, unidade,
     *                         motivo_rescisao_descricao, ativo.
     */
    public static function montarPainelComContratos(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $inicioAnterior = $inicio->modify('-1 day');
        $headcountInicio = self::headcountEm($contratos, $inicioAnterior);
        $headcountFim = self::headcountEm($contratos, $fim);
        $admissoes = self::admissoesNoPeriodo($contratos, $inicio, $fim);
        $desligamentos = self::desligamentosNoPeriodo($contratos, $inicio, $fim);
        $turnover = self::taxaTurnover(count($desligamentos), $headcountInicio, $headcountFim);
        $turnoverMensal = self::turnoverMensal($contratos, $inicio, $fim);
        $turnoverPrecoce = self::turnoverPrecoce($desligamentos);

        return [
            'headcount_atual' => $headcountFim,
            'headcount_inicio_periodo' => $headcountInicio,
            'admissoes_periodo' => count($admissoes),
            'desligamentos_periodo' => count($desligamentos),
            'turnover_periodo' => $turnover,
            'turnover_mensal' => $turnoverMensal,
            'turnover_precoce' => $turnoverPrecoce,
            'motivos_rescisao' => self::motivosRescisao($desligamentos),
            'distribuicao_empresa' => self::distribuicao($contratos, 'empresa'),
            'distribuicao_unidade' => self::distribuicao($contratos, 'unidade'),
            'distribuicao_cargo' => self::distribuicao($contratos, 'cargo'),
            'distribuicao_setor' => self::distribuicao($contratos, 'setor'),
            'distribuicao_centro_custo' => self::distribuicao($contratos, 'centro_custo'),
            'turnover_por_empresa' => self::turnoverPorDimensao($contratos, 'empresa', $inicio, $fim),
            'turnover_por_unidade' => self::turnoverPorDimensao($contratos, 'unidade', $inicio, $fim),
            'turnover_por_cargo' => self::turnoverPorDimensao($contratos, 'cargo', $inicio, $fim),
            'turnover_por_setor' => self::turnoverPorDimensao($contratos, 'setor', $inicio, $fim),
            'turnover_por_centro_custo' => self::turnoverPorDimensao($contratos, 'centro_custo', $inicio, $fim),
            'tempo_permanencia' => self::tempoPermanencia($contratos, $fim),
            'total_contratos' => count($contratos),
        ];
    }

    /**
     * Um contrato está ativo na data D quando admissao <= D e (demissao IS NULL OU demissao >= D)
     * — no próprio dia do desligamento o contrato ainda conta como ativo (última data trabalhada).
     * Nenhum caso de demissão anterior à admissão ou no mesmo dia foi observado na carga real
     * validada (ver docs/claude/indicadores-rh.md §"Qualidade dos dados"), então esta regra nunca
     * precisou de tratamento especial de empate até agora.
     */
    public static function contratoAtivoEm(array $contrato, DateTimeImmutable $data): bool
    {
        $admissao = self::normalizeDate($contrato['admissao'] ?? null);
        if ($admissao === null || $admissao > $data) {
            return false;
        }
        $demissao = self::normalizeDate($contrato['demissao'] ?? null);
        return $demissao === null || $demissao >= $data;
    }

    public static function headcountEm(array $contratos, DateTimeImmutable $data): int
    {
        $total = 0;
        foreach ($contratos as $contrato) {
            if (self::contratoAtivoEm($contrato, $data)) {
                $total++;
            }
        }
        return $total;
    }

    /** @return array Contratos cuja admissão caiu dentro de [inicio, fim] (inclusive). */
    public static function admissoesNoPeriodo(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        return array_values(array_filter($contratos, static function (array $contrato) use ($inicio, $fim): bool {
            $admissao = self::normalizeDate($contrato['admissao'] ?? null);
            return $admissao !== null && $admissao >= $inicio && $admissao <= $fim;
        }));
    }

    /** @return array Contratos cuja demissão caiu dentro de [inicio, fim] (inclusive). */
    public static function desligamentosNoPeriodo(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        return array_values(array_filter($contratos, static function (array $contrato) use ($inicio, $fim): bool {
            $demissao = self::normalizeDate($contrato['demissao'] ?? null);
            return $demissao !== null && $demissao >= $inicio && $demissao <= $fim;
        }));
    }

    /**
     * Turnover(%) = desligamentos_do_período / média(headcount_início, headcount_fim) × 100.
     * Convenção já usada no Portal (CollaboratorDashboardDataService::buildTurnoverSeries, dashboard
     * legado) — generalizada aqui para granularidade de contrato sobre a base oficial do METADADOS.
     * Sem desligamentos e sem headcount, o resultado é 0.0 (nunca divisão por zero).
     */
    public static function taxaTurnover(int $desligamentos, int $headcountInicio, int $headcountFim): float
    {
        $mediaHeadcount = ($headcountInicio + $headcountFim) / 2;
        if ($mediaHeadcount <= 0) {
            return 0.0;
        }
        return round(($desligamentos / $mediaHeadcount) * 100, 1);
    }

    /** Série mensal de turnover entre inicio e fim (inclusive), um ponto por mês calendário. */
    public static function turnoverMensal(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $labels = [];
        $valores = [];
        $cursor = $inicio->modify('first day of this month');
        $limite = $fim->modify('first day of this month');

        while ($cursor <= $limite) {
            $mesInicio = $cursor;
            $mesFim = $cursor->modify('last day of this month');
            $headcountInicio = self::headcountEm($contratos, $mesInicio->modify('-1 day'));
            $headcountFim = self::headcountEm($contratos, $mesFim);
            $desligamentosMes = count(self::desligamentosNoPeriodo($contratos, $mesInicio, $mesFim));

            $labels[] = self::formatarMes($mesInicio);
            $valores[] = self::taxaTurnover($desligamentosMes, $headcountInicio, $headcountFim);

            $cursor = $cursor->modify('+1 month');
        }

        return ['labels' => $labels, 'valores' => $valores];
    }

    /**
     * Turnover precoce: para cada desligamento do período, dias entre admissão e demissão,
     * agrupados nas faixas de FAIXAS_PERMANENCIA. O indicador headline (percentual "precoce")
     * considera precoce um desligamento com permanência <= LIMITE_TURNOVER_PRECOCE_DIAS dias
     * (90 — alinhado ao contrato de experiência da CLT), mas a distribuição completa por faixa
     * fica sempre disponível para granularidade (ver dicionário).
     *
     * @return array{total_desligamentos:int, precoces:int, percentual_precoce:float, faixas:array}
     */
    public static function turnoverPrecoce(array $desligamentos): array
    {
        $totalDesligamentos = count($desligamentos);
        $contagemFaixas = [];
        foreach (self::FAIXAS_PERMANENCIA as $faixa) {
            $contagemFaixas[$faixa['label']] = 0;
        }

        $precoces = 0;
        foreach ($desligamentos as $desligamento) {
            $dias = self::diasEntre($desligamento['admissao'] ?? null, $desligamento['demissao'] ?? null);
            if ($dias === null) {
                continue;
            }
            $contagemFaixas[self::faixaPermanencia($dias)]++;
            if ($dias <= self::LIMITE_TURNOVER_PRECOCE_DIAS) {
                $precoces++;
            }
        }

        $faixas = [];
        foreach (self::FAIXAS_PERMANENCIA as $faixa) {
            $quantidade = $contagemFaixas[$faixa['label']];
            $faixas[] = [
                'label' => $faixa['label'],
                'quantidade' => $quantidade,
                'percentual' => $totalDesligamentos > 0 ? round(($quantidade / $totalDesligamentos) * 100, 1) : 0.0,
            ];
        }

        return [
            'total_desligamentos' => $totalDesligamentos,
            'precoces' => $precoces,
            'percentual_precoce' => $totalDesligamentos > 0 ? round(($precoces / $totalDesligamentos) * 100, 1) : 0.0,
            'faixas' => $faixas,
        ];
    }

    public static function faixaPermanencia(int $dias): string
    {
        foreach (self::FAIXAS_PERMANENCIA as $faixa) {
            if ($dias >= $faixa['min'] && ($faixa['max'] === null || $dias <= $faixa['max'])) {
                return $faixa['label'];
            }
        }
        return self::FAIXAS_PERMANENCIA[count(self::FAIXAS_PERMANENCIA) - 1]['label'];
    }

    /** Ranking de motivos de rescisão — nunca renomeia a descrição oficial do METADADOS. */
    public static function motivosRescisao(array $desligamentos): array
    {
        $total = count($desligamentos);
        $contagem = [];
        foreach ($desligamentos as $desligamento) {
            $motivo = trim((string)($desligamento['motivo_rescisao_descricao'] ?? ''));
            $chave = $motivo !== '' ? $motivo : self::NAO_INFORMADO;
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
        }
        arsort($contagem);

        $ranking = [];
        foreach ($contagem as $motivo => $quantidade) {
            $ranking[] = [
                'motivo' => $motivo,
                'quantidade' => $quantidade,
                'percentual' => $total > 0 ? round(($quantidade / $total) * 100, 1) : 0.0,
            ];
        }
        return $ranking;
    }

    /**
     * Distribuição do headcount ATUAL (último contrato de cada linha ainda ativo, avaliado no
     * momento da chamada) por uma dimensão organizacional. Dimensão vazia/NULL vira "Não
     * informado" — nunca descarta o registro (ver docs/claude/indicadores-rh.md §"Qualidade dos
     * dados").
     */
    public static function distribuicao(array $contratos, string $campo): array
    {
        $hoje = new DateTimeImmutable('today');
        $ativos = array_filter($contratos, static fn(array $c): bool => self::contratoAtivoEm($c, $hoje));

        $contagem = [];
        foreach ($ativos as $contrato) {
            $valor = trim((string)($contrato[$campo] ?? ''));
            $chave = $valor !== '' ? $valor : self::NAO_INFORMADO;
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
        }
        arsort($contagem);

        $total = array_sum($contagem);
        $distribuicao = [];
        foreach ($contagem as $label => $quantidade) {
            $distribuicao[] = [
                'label' => $label,
                'quantidade' => $quantidade,
                'percentual' => $total > 0 ? round(($quantidade / $total) * 100, 1) : 0.0,
            ];
        }
        return $distribuicao;
    }

    /**
     * Turnover por valor de dimensão no período: para cada valor, desligamentos do período com
     * aquele valor / média do headcount (início/fim do período) daquele mesmo valor. Sempre
     * mostra quantidade absoluta E taxa — nunca só volume, para não distorcer comparação entre
     * áreas de tamanhos diferentes.
     */
    public static function turnoverPorDimensao(array $contratos, string $campo, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $inicioAnterior = $inicio->modify('-1 day');
        $grupos = [];
        foreach ($contratos as $contrato) {
            $valor = trim((string)($contrato[$campo] ?? ''));
            $chave = $valor !== '' ? $valor : self::NAO_INFORMADO;
            $grupos[$chave][] = $contrato;
        }

        $resultado = [];
        foreach ($grupos as $label => $contratosGrupo) {
            $headcountInicio = self::headcountEm($contratosGrupo, $inicioAnterior);
            $headcountFim = self::headcountEm($contratosGrupo, $fim);
            $desligamentos = count(self::desligamentosNoPeriodo($contratosGrupo, $inicio, $fim));
            $resultado[] = [
                'label' => $label,
                'desligamentos' => $desligamentos,
                'headcount_medio' => round(($headcountInicio + $headcountFim) / 2, 1),
                'taxa' => self::taxaTurnover($desligamentos, $headcountInicio, $headcountFim),
            ];
        }

        usort($resultado, static fn(array $a, array $b) => $b['taxa'] <=> $a['taxa']);
        return $resultado;
    }

    /**
     * Tempo de permanência dos contratos ATIVOS na data de referência: média, mediana e
     * distribuição por faixa. Mediana é reportada ao lado da média porque outliers (poucos
     * contratos com décadas de casa) distorcem a média sozinha.
     */
    public static function tempoPermanencia(array $contratos, DateTimeImmutable $referencia): array
    {
        $dias = [];
        foreach ($contratos as $contrato) {
            if (!self::contratoAtivoEm($contrato, $referencia)) {
                continue;
            }
            $admissao = self::normalizeDate($contrato['admissao'] ?? null);
            if ($admissao === null) {
                continue;
            }
            $dias[] = $referencia->diff($admissao)->days;
        }

        if ($dias === []) {
            return ['media_dias' => 0, 'mediana_dias' => 0, 'faixas' => []];
        }

        sort($dias);
        $total = count($dias);
        $media = array_sum($dias) / $total;
        $meio = intdiv($total, 2);
        $mediana = $total % 2 === 0 ? ($dias[$meio - 1] + $dias[$meio]) / 2 : $dias[$meio];

        $contagemFaixas = [];
        foreach (self::FAIXAS_TEMPO_CASA as $faixa) {
            $contagemFaixas[$faixa['label']] = 0;
        }
        foreach ($dias as $d) {
            foreach (self::FAIXAS_TEMPO_CASA as $faixa) {
                if ($d >= $faixa['min'] && ($faixa['max'] === null || $d <= $faixa['max'])) {
                    $contagemFaixas[$faixa['label']]++;
                    break;
                }
            }
        }
        $faixas = [];
        foreach (self::FAIXAS_TEMPO_CASA as $faixa) {
            $quantidade = $contagemFaixas[$faixa['label']];
            $faixas[] = [
                'label' => $faixa['label'],
                'quantidade' => $quantidade,
                'percentual' => round(($quantidade / $total) * 100, 1),
            ];
        }

        return [
            'media_dias' => round($media, 0),
            'mediana_dias' => round($mediana, 0),
            'faixas' => $faixas,
        ];
    }

    // ---------------------------------------------------------------------
    // Auxiliares
    // ---------------------------------------------------------------------

    private static function diasEntre($admissao, $demissao): ?int
    {
        $admissaoDate = self::normalizeDate($admissao);
        $demissaoDate = self::normalizeDate($demissao);
        if ($admissaoDate === null || $demissaoDate === null || $demissaoDate < $admissaoDate) {
            return null;
        }
        return $demissaoDate->diff($admissaoDate)->days;
    }

    private static function normalizeDate($value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        try {
            return new DateTimeImmutable((string)$value);
        } catch (Exception $e) {
            return null;
        }
    }

    private static function formatarMes(DateTimeImmutable $data): string
    {
        $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        return $meses[(int)$data->format('n') - 1] . '/' . $data->format('y');
    }
}
