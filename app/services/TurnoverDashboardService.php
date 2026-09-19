<?php

/**
 * Dashboard de Turnover — seis análises sobre os CONTRATOS oficiais do espelho
 * `colaboradores_metadados` (unidade = contrato, nunca pessoa distinta):
 *   1. Evolução mensal do turnover — ano selecionado × ano anterior;
 *   2. Admissões × Desligamentos por mês (ano selecionado);
 *   3. Desligamentos por Motivo (categorias mutuamente exclusivas);
 *   4. Turnover por Cargo (`codigo_cargo`);
 *   5. Turnover por Empresa (`codigo_empresa`);
 *   6. Tempo de Empresa dos desligados (demissao - admissao, em dias corridos).
 *
 * NENHUMA fórmula nova: toda taxa vem de RhIndicadoresService::taxaTurnover() sobre
 * headcountEm(início - 1 dia) e headcountEm(fim) — mesma metodologia de Indicadores de RH/People
 * Analytics. Única diferença deliberada, só neste dashboard: quando a média dos headcounts é <= 0 a
 * taxa é `null` ("Sem base"), nunca 0% — RhIndicadoresService::taxaTurnover() não é alterado.
 *
 * Datas de referência sempre de admissao/demissao (nunca o flag `ativo`), então o histórico é
 * reconstruído mês a mês. Todo método de cálculo é estático e opera sobre arrays já carregados
 * (testável sem banco); só montarPainel()/opcoesFiltro()/anosDisponiveis() tocam o repository.
 */
class TurnoverDashboardService
{
    public const CATEGORIA_OUTROS = 'Outros';

    /**
     * ÚNICO ponto do código com a classificação de motivo de rescisão do Dashboard de Turnover
     * (categorias MUTUAMENTE EXCLUSIVAS). Classificação aprovada para ESTE dashboard, pendente de
     * validação final do RH — ajustes futuros acontecem só aqui. Diferente do People Analytics de
     * propósito (lá 001 é Involuntário; aqui é "Justa Causa"): não uniformizar silenciosamente.
     * Qualquer código vazio, nulo ou ainda não mapeado cai em "Outros" — nunca é classificado por
     * interpretação da descrição.
     *
     *   001 Demissão com Justa Causa | 002 Demissão sem Justa Causa | 003 Pedido de Demissão sem Justa Causa
     *   005 Rescisão por Término do Contrato de Exp. | 006 Resc. Ant. do Contr. Determ. p/Empregado
     *   007 Resc. Ant. do Contr. Determ. p/Empresa | 008 Rescisão p/Término de Contr. Temporário
     *   016 Rescisão por Acordo entre as Partes | 020 Falecimento | 046 Exoneração a pedido de Diretor Não Empr.
     */
    public const MAPA_MOTIVOS = [
        'Voluntário' => ['003', '006'],
        'Involuntário' => ['002', '007'],
        'Justa Causa' => ['001'],
        'Término de Contrato' => ['005', '008'],
        'Acordo' => ['016'],
    ];

    /** Ordem fixa de exibição das categorias ("Outros" sempre por último). */
    public const ORDEM_CATEGORIAS = ['Voluntário', 'Involuntário', 'Justa Causa', 'Término de Contrato', 'Acordo', self::CATEGORIA_OUTROS];

    /** Tempo de Empresa dos desligados — dias corridos, fronteiras inclusivas e sem sobreposição. */
    public const FAIXAS_TEMPO_EMPRESA = [
        ['label' => 'Até 90 dias', 'min' => 0, 'max' => 90],
        ['label' => '3 a 6 meses', 'min' => 91, 'max' => 180],
        ['label' => '6 a 12 meses', 'min' => 181, 'max' => 365],
        ['label' => '1 a 2 anos', 'min' => 366, 'max' => 730],
        ['label' => 'Acima de 2 anos', 'min' => 731, 'max' => null],
    ];

    public const LIMITE_CARGOS = 15;

    public const MESES = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];

    private TurnoverDashboardRepository $repository;

    public function __construct(?TurnoverDashboardRepository $repository = null)
    {
        $this->repository = $repository ?? new TurnoverDashboardRepository();
    }

    // ---------------------------------------------------------------------
    // Orquestração (única parte que toca banco)
    // ---------------------------------------------------------------------

    public function opcoesFiltro(): array
    {
        return $this->repository->opcoesFiltro();
    }

    /**
     * Anos selecionáveis: do primeiro ano com desligamento no espelho + 1 até o ano atual — assim o
     * ano anterior (comparativo Y-1 × Y) sempre tem histórico. Sem nenhum desligamento, só o atual.
     *
     * @return int[] crescente
     */
    public function anosDisponiveis(DateTimeImmutable $hoje): array
    {
        $atual = (int)$hoje->format('Y');
        $primeiro = $this->repository->primeiroAnoComDesligamento();
        $inicio = $primeiro === null ? $atual : min($atual, $primeiro + 1);
        return range($inicio, $atual);
    }

    /** @param array $filtros Chaves: codigo_empresa, codigo_cargo. */
    public function montarPainel(array $filtros, int $ano, ?DateTimeImmutable $hoje = null): array
    {
        $hoje = $hoje ?? new DateTimeImmutable('today');
        $contratos = $this->repository->buscarContratos($filtros);
        $codigosCargo = [];
        foreach ($contratos as $contrato) {
            $codigo = trim((string)($contrato['codigo_cargo'] ?? ''));
            if ($codigo !== '') {
                $codigosCargo[$codigo] = true;
            }
        }
        $nomesCargos = $this->repository->nomesOficiaisDeCargos(array_keys($codigosCargo));
        return self::montarPainelComContratos($contratos, $ano, $hoje, $nomesCargos);
    }

    // ---------------------------------------------------------------------
    // Núcleo puro — testável com arrays sintéticos, sem banco
    // ---------------------------------------------------------------------

    /**
     * @param array $contratos   Cada item: admissao, demissao, motivo_rescisao_codigo, codigo_empresa,
     *                           empresa, codigo_cargo, cargo.
     * @param array $nomesCargos codigo_cargo => descrição oficial do catálogo `cargos`.
     */
    public static function montarPainelComContratos(array $contratos, int $ano, DateTimeImmutable $hoje, array $nomesCargos = []): array
    {
        [$inicio, $fim] = self::periodoDoAno($ano, $hoje);

        return [
            'ano' => $ano,
            'ano_anterior' => $ano - 1,
            'periodo' => [
                'inicio' => $inicio,
                'fim' => $fim,
                'parcial' => $fim < new DateTimeImmutable(sprintf('%04d-12-31', $ano)),
            ],
            'comparativo' => self::comparativoMensal($contratos, $ano, $hoje),
            'admissoes_desligamentos' => self::admissoesDesligamentosMensal($contratos, $ano, $hoje),
            'motivos' => self::desligamentosPorMotivo($contratos, $inicio, $fim),
            'cargos' => self::turnoverPorCargo($contratos, $inicio, $fim, $nomesCargos),
            'empresas' => self::turnoverPorEmpresa($contratos, $inicio, $fim),
            'tempo_empresa' => self::tempoDeEmpresaDosDesligados($contratos, $inicio, $fim),
            'total_contratos' => count($contratos),
        ];
    }

    /**
     * Período dos gráficos 3 a 6: 01/01 até 31/12 do ano selecionado; se for o ano atual, até hoje
     * (acumulado no ano).
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public static function periodoDoAno(int $ano, DateTimeImmutable $hoje): array
    {
        $inicio = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $fimAno = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
        return [$inicio, $fimAno > $hoje ? $hoje : $fimAno];
    }

    /**
     * Taxa oficial, ou `null` quando não há base (média dos headcounts <= 0). Só neste dashboard:
     * nunca converte ausência de base em 0%.
     */
    public static function taxaOuNull(int $desligamentos, int $headcountInicio, int $headcountFim): ?float
    {
        if (($headcountInicio + $headcountFim) / 2 <= 0) {
            return null;
        }
        return RhIndicadoresService::taxaTurnover($desligamentos, $headcountInicio, $headcountFim);
    }

    /**
     * Estado de um mês do ano: 'completo', 'parcial' (mês corrente — só até hoje) ou 'futuro'.
     *
     * @return array{0:string,1:DateTimeImmutable,2:DateTimeImmutable} [status, início, fim efetivo]
     */
    private static function janelaDoMes(int $ano, int $mes, DateTimeImmutable $hoje): array
    {
        $inicio = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $ultimoDia = $inicio->modify('last day of this month');
        if ($inicio > $hoje) {
            return ['futuro', $inicio, $ultimoDia];
        }
        if ($ultimoDia > $hoje) {
            return ['parcial', $inicio, $hoje];
        }
        return ['completo', $inicio, $ultimoDia];
    }

    /**
     * Turnover mensal de Y-1 e Y, mês a mês, com a fórmula oficial: início do mês = headcountEm(dia
     * anterior), fim = headcountEm(último dia do mês) e desligamentos DAQUELE mês. Mês corrente:
     * fim = hoje (parcial). Meses futuros: `null` (nunca 0%). Não anualiza.
     *
     * @return array{ano:int,ano_anterior:int,labels:string[],anterior:array,atual:array}
     */
    public static function comparativoMensal(array $contratos, int $ano, DateTimeImmutable $hoje): array
    {
        return [
            'ano' => $ano,
            'ano_anterior' => $ano - 1,
            'labels' => array_values(self::MESES),
            'anterior' => self::serieMensalDoAno($contratos, $ano - 1, $hoje),
            'atual' => self::serieMensalDoAno($contratos, $ano, $hoje),
        ];
    }

    /** @return array<int,array{mes:int,status:string,taxa:?float,desligamentos:?int,headcount_medio:?float,parcial_ate:?string}> 12 itens */
    private static function serieMensalDoAno(array $contratos, int $ano, DateTimeImmutable $hoje): array
    {
        $serie = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            [$status, $inicio, $fim] = self::janelaDoMes($ano, $mes, $hoje);
            if ($status === 'futuro') {
                $serie[] = ['mes' => $mes, 'status' => 'futuro', 'taxa' => null, 'desligamentos' => null, 'headcount_medio' => null, 'parcial_ate' => null];
                continue;
            }
            $headcountInicio = RhIndicadoresService::headcountEm($contratos, $inicio->modify('-1 day'));
            $headcountFim = RhIndicadoresService::headcountEm($contratos, $fim);
            $desligamentos = count(RhIndicadoresService::desligamentosNoPeriodo($contratos, $inicio, $fim));
            $taxa = self::taxaOuNull($desligamentos, $headcountInicio, $headcountFim);
            $serie[] = [
                'mes' => $mes,
                'status' => $status,
                'taxa' => $taxa,
                'desligamentos' => $desligamentos,
                'headcount_medio' => round(($headcountInicio + $headcountFim) / 2, 1),
                'parcial_ate' => $status === 'parcial' ? $fim->format('d/m') : null,
            ];
        }
        return $serie;
    }

    /**
     * Admissões × Desligamentos por mês do ano selecionado (contratos por admissao/demissao, nunca
     * `ativo`). Mês corrente até hoje (parcial); meses futuros `null`.
     *
     * @return array{ano:int,labels:string[],meses:array,parcial_ate:?string}
     */
    public static function admissoesDesligamentosMensal(array $contratos, int $ano, DateTimeImmutable $hoje): array
    {
        $meses = [];
        $parcialAte = null;
        for ($mes = 1; $mes <= 12; $mes++) {
            [$status, $inicio, $fim] = self::janelaDoMes($ano, $mes, $hoje);
            if ($status === 'futuro') {
                $meses[] = ['mes' => $mes, 'status' => 'futuro', 'admissoes' => null, 'desligamentos' => null];
                continue;
            }
            if ($status === 'parcial') {
                $parcialAte = $fim->format('d/m');
            }
            $meses[] = [
                'mes' => $mes,
                'status' => $status,
                'admissoes' => count(RhIndicadoresService::admissoesNoPeriodo($contratos, $inicio, $fim)),
                'desligamentos' => count(RhIndicadoresService::desligamentosNoPeriodo($contratos, $inicio, $fim)),
            ];
        }
        return ['ano' => $ano, 'labels' => array_values(self::MESES), 'meses' => $meses, 'parcial_ate' => $parcialAte];
    }

    /** Categoria do dashboard para um código de motivo — desconhecido/vazio/nulo => "Outros". */
    public static function categoriaDoMotivo($codigo): string
    {
        $codigo = trim((string)$codigo);
        foreach (self::MAPA_MOTIVOS as $categoria => $codigos) {
            if (in_array($codigo, $codigos, true)) {
                return $categoria;
            }
        }
        return self::CATEGORIA_OUTROS;
    }

    /**
     * Desligamentos do período por categoria de motivo. "Outros" traz o detalhe dos códigos que caíram
     * nele (transparência do mapeamento provisório).
     *
     * @return array{total:int,categorias:array,detalhe_outros:array}
     */
    public static function desligamentosPorMotivo(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $desligamentos = RhIndicadoresService::desligamentosNoPeriodo($contratos, $inicio, $fim);
        $contagem = array_fill_keys(self::ORDEM_CATEGORIAS, 0);
        $codigosOutros = [];
        foreach ($desligamentos as $contrato) {
            $categoria = self::categoriaDoMotivo($contrato['motivo_rescisao_codigo'] ?? null);
            $contagem[$categoria]++;
            if ($categoria === self::CATEGORIA_OUTROS) {
                $codigo = trim((string)($contrato['motivo_rescisao_codigo'] ?? ''));
                $chave = $codigo !== '' ? $codigo : 'sem código';
                $codigosOutros[$chave] = ($codigosOutros[$chave] ?? 0) + 1;
            }
        }

        $total = count($desligamentos);
        $categorias = [];
        foreach (self::ORDEM_CATEGORIAS as $categoria) {
            $categorias[] = [
                'categoria' => $categoria,
                'quantidade' => $contagem[$categoria],
                'percentual' => $total > 0 ? round(($contagem[$categoria] / $total) * 100, 1) : null,
            ];
        }
        ksort($codigosOutros);
        $detalhe = [];
        foreach ($codigosOutros as $codigo => $quantidade) {
            $detalhe[] = ['codigo' => (string)$codigo, 'quantidade' => $quantidade];
        }
        return ['total' => $total, 'categorias' => $categorias, 'detalhe_outros' => $detalhe];
    }

    /**
     * Turnover por Cargo — identidade `codigo_cargo` (nunca o texto); nome oficial vem do catálogo
     * (`$nomesCargos`), com fallback para o texto do espelho e depois para o código. Só cargos com
     * pelo menos 1 desligamento no período entram (o ranking é de quem teve desligamentos); base
     * pequena NUNCA é escondida. Top LIMITE_CARGOS; sem base fica no fim.
     *
     * @return array{total_cargos:int,exibidos:int,itens:array}
     */
    public static function turnoverPorCargo(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim, array $nomesCargos = []): array
    {
        $textoPorCodigo = [];
        foreach ($contratos as $contrato) {
            $codigo = trim((string)($contrato['codigo_cargo'] ?? ''));
            $texto = trim((string)($contrato['cargo'] ?? ''));
            if ($codigo !== '' && $texto !== '' && (!isset($textoPorCodigo[$codigo]) || $texto > $textoPorCodigo[$codigo])) {
                $textoPorCodigo[$codigo] = $texto;
            }
        }

        $itens = [];
        foreach (RhIndicadoresService::turnoverPorDimensao($contratos, 'codigo_cargo', $inicio, $fim) as $linha) {
            if ($linha['desligamentos'] <= 0) {
                continue;
            }
            $codigo = (string)$linha['label'];
            $semCodigo = $codigo === RhIndicadoresService::NAO_INFORMADO;
            $itens[] = [
                'codigo' => $semCodigo ? '' : $codigo,
                'nome' => $semCodigo ? RhIndicadoresService::NAO_INFORMADO : ($nomesCargos[$codigo] ?? $textoPorCodigo[$codigo] ?? $codigo),
                'desligamentos' => (int)$linha['desligamentos'],
                'base' => (float)$linha['headcount_medio'],
                'taxa' => (float)$linha['headcount_medio'] > 0 ? (float)$linha['taxa'] : null,
            ];
        }

        $itens = self::ordenarPorTaxa($itens);
        return [
            'total_cargos' => count($itens),
            'exibidos' => min(self::LIMITE_CARGOS, count($itens)),
            'itens' => array_slice($itens, 0, self::LIMITE_CARGOS),
        ];
    }

    /**
     * Turnover por Empresa — identidade `codigo_empresa`, nome do espelho (mesma metodologia de
     * People Analytics). Entram empresas com desligamento no período OU com base de headcount.
     *
     * @return array{itens:array}
     */
    public static function turnoverPorEmpresa(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $nomes = [];
        foreach ($contratos as $contrato) {
            $codigo = trim((string)($contrato['codigo_empresa'] ?? ''));
            $nome = trim((string)($contrato['empresa'] ?? ''));
            if ($codigo !== '' && $nome !== '' && (!isset($nomes[$codigo]) || $nome > $nomes[$codigo])) {
                $nomes[$codigo] = $nome;
            }
        }

        $itens = [];
        foreach (RhIndicadoresService::turnoverPorDimensao($contratos, 'codigo_empresa', $inicio, $fim) as $linha) {
            $base = (float)$linha['headcount_medio'];
            if ($linha['desligamentos'] <= 0 && $base <= 0) {
                continue;
            }
            $codigo = (string)$linha['label'];
            $semCodigo = $codigo === RhIndicadoresService::NAO_INFORMADO;
            $itens[] = [
                'codigo' => $semCodigo ? '' : $codigo,
                'nome' => $semCodigo ? RhIndicadoresService::NAO_INFORMADO : ($nomes[$codigo] ?? $codigo),
                'desligamentos' => (int)$linha['desligamentos'],
                'base' => $base,
                'taxa' => $base > 0 ? (float)$linha['taxa'] : null,
            ];
        }
        return ['itens' => self::ordenarPorTaxa($itens)];
    }

    /**
     * Ordenação: maior taxa primeiro; sem base ao final; empate por mais desligamentos; desempate
     * final estável por nome e código.
     */
    private static function ordenarPorTaxa(array $itens): array
    {
        usort($itens, static function (array $a, array $b): int {
            $semBaseA = $a['taxa'] === null;
            $semBaseB = $b['taxa'] === null;
            if ($semBaseA !== $semBaseB) {
                return $semBaseA ? 1 : -1;
            }
            if (!$semBaseA && $a['taxa'] !== $b['taxa']) {
                return $b['taxa'] <=> $a['taxa'];
            }
            if ($a['desligamentos'] !== $b['desligamentos']) {
                return $b['desligamentos'] <=> $a['desligamentos'];
            }
            return [mb_strtolower($a['nome']), $a['codigo']] <=> [mb_strtolower($b['nome']), $b['codigo']];
        });
        return $itens;
    }

    /**
     * Tempo de Empresa dos desligados do período: dias corridos entre admissao e demissao do PRÓPRIO
     * contrato (nunca a data atual). Contrato sem admissão/demissão válidas ou com demissão anterior
     * à admissão não entra nas faixas (é contado em `nao_classificados`).
     *
     * @return array{total_classificados:int,nao_classificados:int,faixas:array}
     */
    public static function tempoDeEmpresaDosDesligados(array $contratos, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $contagem = [];
        foreach (self::FAIXAS_TEMPO_EMPRESA as $faixa) {
            $contagem[$faixa['label']] = 0;
        }
        $classificados = 0;
        $naoClassificados = 0;
        foreach (RhIndicadoresService::desligamentosNoPeriodo($contratos, $inicio, $fim) as $contrato) {
            $dias = self::diasEntre($contrato['admissao'] ?? null, $contrato['demissao'] ?? null);
            if ($dias === null) {
                $naoClassificados++;
                continue;
            }
            foreach (self::FAIXAS_TEMPO_EMPRESA as $faixa) {
                if ($dias >= $faixa['min'] && ($faixa['max'] === null || $dias <= $faixa['max'])) {
                    $contagem[$faixa['label']]++;
                    $classificados++;
                    break;
                }
            }
        }

        $faixas = [];
        foreach (self::FAIXAS_TEMPO_EMPRESA as $faixa) {
            $faixas[] = [
                'label' => $faixa['label'],
                'quantidade' => $contagem[$faixa['label']],
                'percentual' => $classificados > 0 ? round(($contagem[$faixa['label']] / $classificados) * 100, 1) : null,
            ];
        }
        return ['total_classificados' => $classificados, 'nao_classificados' => $naoClassificados, 'faixas' => $faixas];
    }

    /** Dias corridos demissao - admissao; null se qualquer data for inválida ou demissao < admissao. */
    public static function diasEntre($admissao, $demissao): ?int
    {
        $a = self::normalizarData($admissao);
        $d = self::normalizarData($demissao);
        if ($a === null || $d === null || $d < $a) {
            return null;
        }
        return $d->diff($a)->days;
    }

    private static function normalizarData($valor): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable((string)$valor);
        } catch (Exception $e) {
            return null;
        }
    }
}
