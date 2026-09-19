<?php

/**
 * Dashboard da Entrevista de Desligamento — composição dos indicadores gerenciais (agregados).
 *
 * DUAS POPULAÇÕES, nunca misturadas (cada indicador declara a sua base):
 *   A — desligamentos oficiais (contratos de `colaboradores_metadados`, competência `demissao`): total de
 *       desligamentos, cobertura e tempo médio de permanência;
 *   B — entrevistas RESPONDIDAS (competência `snap_demissao`, nunca `respondida_em`): motivos declarados, fatores,
 *       satisfação, eNPS, liderança, cultura, integração. O denominador é sempre o número de respostas válidas.
 *
 * "Sem base" = `null` (nunca 0): taxa sem entrevistas geradas, médias/eNPS sem respostas, permanência sem contratos
 * válidos. Zero real (ex.: 0 respondidas) continua 0.
 *
 * Fora da V1 por falta de fonte/semântica aprovada: Voluntário/Involuntário, Área, Gestor.
 *
 * Tempo médio de permanência (meses) = Σ dias(demissão − admissão) ÷ nº de contratos válidos ÷ 30,4375
 * (365,25 ÷ 12), 1 casa decimal; válido = admissão preenchida e ≤ demissão (o restante é contado à parte).
 */
class DashboardEntrevistaDesligamentoService
{
    public const MESES_MAXIMO = 60;
    public const MESES_PADRAO = 12;
    public const TOP_MOTIVOS = 5;
    public const LIMITE_CARGOS = 15;
    public const DIAS_POR_MES = 30.4375;

    private DashboardEntrevistaDesligamentoRepository $repository;

    public function __construct(?DashboardEntrevistaDesligamentoRepository $repository = null)
    {
        $this->repository = $repository ?? new DashboardEntrevistaDesligamentoRepository();
    }

    // ================================================================== filtros

    /** Opções dos filtros: unidade pela identidade oficial (empresa + unidade) e cargo por `codigo_cargo`. */
    public function opcoesFiltro(): array
    {
        $unidades = [];
        foreach ($this->repository->opcoesUnidade() as $u) {
            $unidades[] = [
                'chave' => $u['codigo_empresa'] . '|' . $u['codigo_unidade'],
                'codigo_empresa' => (string)$u['codigo_empresa'],
                'codigo_unidade' => (string)$u['codigo_unidade'],
                'nome' => self::nomeUnidade($u),
            ];
        }
        $cargos = [];
        foreach ($this->repository->opcoesCargo() as $c) {
            $cargos[] = ['codigo' => (string)$c['codigo_cargo'], 'nome' => (string)($c['nome'] ?? $c['codigo_cargo'])];
        }
        return ['unidades' => $unidades, 'cargos' => $cargos];
    }

    /**
     * Valida e normaliza os filtros do GET no servidor. Datas em Y-m-d; fim nunca no futuro (desligamento só
     * conta quando efetivado); janela máxima de 60 meses; unidade/cargo só se existirem nas opções oficiais.
     *
     * @return array{inicio:DateTimeImmutable,fim:DateTimeImmutable,unidade:?array,unidade_chave:string,codigo_cargo:string,avisos:string[]}
     */
    public static function normalizarFiltros(array $get, DateTimeImmutable $hoje, array $opcoes): array
    {
        $hoje = $hoje->setTime(0, 0);
        $avisos = [];

        $inicio = self::dataValida($get['inicio'] ?? null);
        $fim = self::dataValida($get['fim'] ?? null);
        if ((($get['inicio'] ?? '') !== '' && $inicio === null) || (($get['fim'] ?? '') !== '' && $fim === null)) {
            $avisos[] = 'Data inválida ignorada — usando o padrão.';
        }
        $fim = $fim ?? $hoje;
        if ($fim > $hoje) {
            $fim = $hoje;
            $avisos[] = 'A data final foi ajustada para hoje: desligamentos futuros ainda não foram efetivados.';
        }
        $inicio = $inicio ?? $fim->modify('first day of this month')->modify('-' . (self::MESES_PADRAO - 1) . ' months');
        if ($inicio > $fim) {
            $inicio = $fim->modify('first day of this month')->modify('-' . (self::MESES_PADRAO - 1) . ' months');
            $avisos[] = 'A data inicial era posterior à final — usando os últimos ' . self::MESES_PADRAO . ' meses até a data final.';
        }
        $limite = $fim->modify('first day of this month')->modify('-' . (self::MESES_MAXIMO - 1) . ' months');
        if ($inicio < $limite) {
            $inicio = $limite;
            $avisos[] = 'O período foi limitado a ' . self::MESES_MAXIMO . ' meses.';
        }

        $unidade = null;
        $chave = is_string($get['unidade'] ?? null) ? $get['unidade'] : '';
        foreach ($opcoes['unidades'] ?? [] as $u) {
            if ($u['chave'] === $chave) {
                $unidade = ['codigo_empresa' => $u['codigo_empresa'], 'codigo_unidade' => $u['codigo_unidade']];
                break;
            }
        }
        $cargo = '';
        $codigoCargo = is_string($get['cargo'] ?? null) ? $get['cargo'] : '';
        foreach ($opcoes['cargos'] ?? [] as $c) {
            if ($c['codigo'] === $codigoCargo) {
                $cargo = $codigoCargo;
                break;
            }
        }

        return [
            'inicio' => $inicio, 'fim' => $fim, 'unidade' => $unidade,
            'unidade_chave' => $unidade !== null ? $chave : '', 'codigo_cargo' => $cargo, 'avisos' => $avisos,
        ];
    }

    /** Atalhos de período (links GET, sem JS). */
    public static function atalhos(DateTimeImmutable $hoje): array
    {
        $hoje = $hoje->setTime(0, 0);
        $primeiroDoMes = $hoje->modify('first day of this month');
        $atalho = static fn(string $rotulo, DateTimeImmutable $i, DateTimeImmutable $f): array => ['rotulo' => $rotulo, 'inicio' => $i->format('Y-m-d'), 'fim' => $f->format('Y-m-d')];
        $anoAnterior = (int)$hoje->format('Y') - 1;
        return [
            $atalho('Últimos 3 meses', $primeiroDoMes->modify('-2 months'), $hoje),
            $atalho('Últimos 6 meses', $primeiroDoMes->modify('-5 months'), $hoje),
            $atalho('Últimos 12 meses', $primeiroDoMes->modify('-11 months'), $hoje),
            $atalho('Ano atual', $hoje->setDate((int)$hoje->format('Y'), 1, 1), $hoje),
            $atalho('Ano anterior', $hoje->setDate($anoAnterior, 1, 1), $hoje->setDate($anoAnterior, 12, 31)),
        ];
    }

    private static function dataValida(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return new DateTimeImmutable($valor);
    }

    // ================================================================== painel

    /** @param array $filtros saída de normalizarFiltros() */
    public function montarPainel(array $filtros): array
    {
        $f = [
            'inicio' => $filtros['inicio']->format('Y-m-d'),
            'fim' => $filtros['fim']->format('Y-m-d'),
            'unidade' => $filtros['unidade'],
            'codigo_cargo' => $filtros['codigo_cargo'],
        ];
        $dados = [
            'desligamentos_mes' => $this->repository->desligamentosPorMes($f, EntrevistaDesligamentoService::MOTIVO_OFICIAL_INELEGIVEL),
            'entrevistas_mes' => $this->repository->entrevistasPorMes($f),
            'motivos' => $this->repository->motivosDeclarados($f),
            'fatores' => $this->repository->fatoresContribuintes($f),
            'desligamentos_unidade' => $this->repository->desligamentosPorUnidade($f),
            'entrevistas_unidade' => $this->repository->entrevistasPorUnidade($f),
            'desligamentos_cargo' => $this->repository->desligamentosPorCargo($f),
            'entrevistas_cargo' => $this->repository->entrevistasPorCargo($f),
        ];
        return self::montarPainelComDados($dados, $filtros['inicio'], $filtros['fim']);
    }

    /**
     * Composição pura (sem banco) — testável com agregados sintéticos.
     *
     * @param array $dados chaves: desligamentos_mes, entrevistas_mes, motivos, fatores, desligamentos_unidade,
     *                     entrevistas_unidade, desligamentos_cargo, entrevistas_cargo (linhas do repository)
     */
    public static function montarPainelComDados(array $dados, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $desl = [];
        foreach ($dados['desligamentos_mes'] ?? [] as $r) {
            $desl[(string)$r['mes']] = $r;
        }
        $entr = [];
        foreach ($dados['entrevistas_mes'] ?? [] as $r) {
            $entr[(string)$r['mes']] = $r;
        }

        // ---- série mensal (competência = mês da demissão)
        $meses = [];
        $cursor = $inicio->modify('first day of this month');
        $ultimo = $fim->modify('first day of this month');
        while ($cursor <= $ultimo) {
            $chave = $cursor->format('Y-m');
            $d = $desl[$chave] ?? [];
            $e = $entr[$chave] ?? [];
            $geradas = (int)($e['geradas'] ?? 0);
            $respondidas = (int)($e['respondidas'] ?? 0);
            $meses[] = [
                'mes' => $chave,
                'rotulo' => $cursor->format('m/y'),
                'desligamentos' => (int)($d['desligamentos'] ?? 0),
                'geradas' => $geradas,
                'respondidas' => $respondidas,
                'taxa_resposta' => self::percentual($respondidas, $geradas),
                'satisfacao' => self::media((float)($e['soma_sat'] ?? 0), (int)($e['n_sat'] ?? 0)),
                'enps' => self::enps($e),
                'lideranca' => self::mediaBloco($e, 'lideranca')['media'],
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        // ---- totais (somando os meses — uma única consulta de cada população)
        $somaDesl = self::somar($dados['desligamentos_mes'] ?? [], ['desligamentos', 'elegiveis', 'sem_entrevista', 'perm_dias', 'perm_validos']);
        $colunasEntr = array_merge(['geradas', 'respondidas', 'n_sat', 'soma_sat', 'n_enps', 'promotores', 'neutros', 'detratores'], self::colunasDimensoes());
        $somaEntr = self::somar($dados['entrevistas_mes'] ?? [], $colunasEntr);

        $desligamentos = (int)$somaDesl['desligamentos'];
        $geradas = (int)$somaEntr['geradas'];
        $respondidas = (int)$somaEntr['respondidas'];
        $elegiveis = (int)$somaDesl['elegiveis'];
        $semEntrevista = (int)$somaDesl['sem_entrevista'];
        $validos = (int)$somaDesl['perm_validos'];

        $executivo = [
            'desligamentos' => $desligamentos,
            'geradas' => $geradas,
            'respondidas' => $respondidas,
            'taxa_resposta' => self::percentual($respondidas, $geradas),
            'satisfacao' => ['media' => self::media((float)$somaEntr['soma_sat'], (int)$somaEntr['n_sat']), 'n' => (int)$somaEntr['n_sat']],
            'enps' => [
                'valor' => self::enps($somaEntr),
                'n' => (int)$somaEntr['n_enps'],
                'promotores' => (int)$somaEntr['promotores'],
                'neutros' => (int)$somaEntr['neutros'],
                'detratores' => (int)$somaEntr['detratores'],
            ],
            'permanencia' => [
                'meses' => $validos > 0 ? round(((float)$somaDesl['perm_dias'] / $validos) / self::DIAS_POR_MES, 1) : null,
                'n' => $validos,
                'invalidos' => $desligamentos - $validos,
            ],
            'cobertura' => [
                'elegiveis' => $elegiveis,
                'nao_elegiveis' => $desligamentos - $elegiveis,
                'sem_entrevista' => $semEntrevista,
                'com_entrevista' => $elegiveis - $semEntrevista,
                'percentual' => self::percentual($elegiveis - $semEntrevista, $elegiveis),
            ],
        ];

        return [
            'periodo' => ['inicio' => $inicio, 'fim' => $fim, 'bordas_parciais' => $inicio->format('d') !== '01' || $fim->format('Y-m-d') !== $fim->modify('last day of this month')->format('Y-m-d')],
            'executivo' => $executivo,
            'motivos' => self::motivos($dados['motivos'] ?? []),
            'fatores' => self::fatores($dados['fatores'] ?? [], $respondidas),
            'blocos' => [
                'lideranca' => self::mediaBloco($somaEntr, 'lideranca'),
                'cultura' => self::mediaBloco($somaEntr, 'cultura'),
                'integracao' => self::mediaBloco($somaEntr, 'integracao'),
            ],
            'mensal' => $meses,
            'unidades' => self::porUnidade($dados['desligamentos_unidade'] ?? [], $dados['entrevistas_unidade'] ?? []),
            'cargos' => self::porCargo($dados['desligamentos_cargo'] ?? [], $dados['entrevistas_cargo'] ?? []),
        ];
    }

    // ================================================================== cálculos puros

    public static function media(float $soma, int $n): ?float
    {
        return $n > 0 ? round($soma / $n, 1) : null;
    }

    public static function percentual(int $parte, int $base): ?float
    {
        return $base > 0 ? round(($parte / $base) * 100, 1) : null;
    }

    /** eNPS (mesma fórmula da Entrevista): %Promotores − %Detratores sobre respostas válidas; null sem respostas. */
    public static function enps(array $linha): ?float
    {
        return EntrevistaDesligamentoService::calcularEnps([
            'total' => (int)($linha['n_enps'] ?? 0),
            'promotores' => (int)($linha['promotores'] ?? 0),
            'neutros' => (int)($linha['neutros'] ?? 0),
            'detratores' => (int)($linha['detratores'] ?? 0),
        ]);
    }

    /** @return string[] colunas n_/s_ das 14 dimensões 1–5 */
    private static function colunasDimensoes(): array
    {
        $cols = [];
        foreach (DashboardEntrevistaDesligamentoRepository::dimensoes() as $c) {
            $cols[] = 'n_' . $c;
            $cols[] = 's_' . $c;
        }
        return $cols;
    }

    private static function somar(array $linhas, array $colunas): array
    {
        $soma = array_fill_keys($colunas, 0);
        foreach ($linhas as $l) {
            foreach ($colunas as $c) {
                $soma[$c] += (float)($l[$c] ?? 0);
            }
        }
        return $soma;
    }

    /**
     * Média do bloco (Liderança/Cultura/Integração) e de cada dimensão, sobre as respondidas. `n` do bloco =
     * menor contagem entre as dimensões (só respostas completas dão base).
     *
     * @return array{titulo:string,media:?float,n:int,dimensoes:array}
     */
    public static function mediaBloco(array $linha, string $secao): array
    {
        $def = EntrevistaDesligamentoService::SECOES_ESCALA[$secao];
        $dimensoes = [];
        $somaTotal = 0.0;
        $nTotal = 0;
        $nMin = null;
        foreach ($def['itens'] as $campo => $rotulo) {
            $n = (int)($linha['n_' . $campo] ?? 0);
            $s = (float)($linha['s_' . $campo] ?? 0);
            $dimensoes[] = ['campo' => $campo, 'rotulo' => $rotulo, 'media' => self::media($s, $n), 'n' => $n];
            $somaTotal += $s;
            $nTotal += $n;
            $nMin = $nMin === null ? $n : min($nMin, $n);
        }
        return ['titulo' => $def['titulo'], 'media' => self::media($somaTotal, $nTotal), 'n' => (int)$nMin, 'dimensoes' => $dimensoes];
    }

    /** Top 5 motivos DECLARADOS: quantidade desc, rótulo asc (desempate estável); % sobre respostas com motivo válido. */
    private static function motivos(array $linhas): array
    {
        $itens = [];
        $total = 0;
        foreach ($linhas as $r) {
            $q = (int)$r['quantidade'];
            $total += $q;
            $itens[] = ['codigo' => (string)$r['codigo'], 'rotulo' => EntrevistaDesligamentoService::MOTIVOS_DECLARADOS[$r['codigo']] ?? (string)$r['codigo'], 'quantidade' => $q];
        }
        usort($itens, static fn(array $a, array $b): int => [$b['quantidade'], $a['rotulo']] <=> [$a['quantidade'], $b['rotulo']]);
        $itens = array_slice($itens, 0, self::TOP_MOTIVOS);
        foreach ($itens as &$i) {
            $i['percentual'] = self::percentual($i['quantidade'], $total);
        }
        unset($i);
        return ['total' => $total, 'itens' => $itens];
    }

    /** Fatores (seleção múltipla): marcações por alternativa; % sobre as ENTREVISTAS respondidas (soma pode passar de 100%). */
    private static function fatores(array $linhas, int $respondidas): array
    {
        $itens = [];
        foreach ($linhas as $r) {
            $q = (int)$r['quantidade'];
            $itens[] = [
                'codigo' => (string)$r['codigo'],
                'rotulo' => EntrevistaDesligamentoService::FATORES_CONTRIBUINTES[$r['codigo']] ?? (string)$r['codigo'],
                'quantidade' => $q,
                'percentual' => self::percentual($q, $respondidas),
            ];
        }
        usort($itens, static fn(array $a, array $b): int => [$b['quantidade'], $a['rotulo']] <=> [$a['quantidade'], $b['rotulo']]);
        return ['base' => $respondidas, 'itens' => $itens];
    }

    // ------------------------------------------------------------------ resumos por unidade / cargo

    public static function nomeUnidade(array $u): string
    {
        $unidade = trim((string)($u['unidade'] ?? '')) !== '' ? trim((string)$u['unidade']) : (string)($u['codigo_unidade'] ?? '');
        $empresa = trim((string)($u['empresa'] ?? ''));
        // Quando a descrição oficial da unidade repete o nome da empresa, mostra uma vez só.
        return ($empresa !== '' && mb_strtolower($empresa) !== mb_strtolower($unidade)) ? $unidade . ' — ' . $empresa : $unidade;
    }

    private static function linhaResumo(string $nome, int $desligamentos, array $e): array
    {
        $geradas = (int)($e['geradas'] ?? 0);
        $respondidas = (int)($e['respondidas'] ?? 0);
        return [
            'nome' => $nome,
            'desligamentos' => $desligamentos,
            'geradas' => $geradas,
            'respondidas' => $respondidas,
            'taxa_resposta' => self::percentual($respondidas, $geradas),
            'satisfacao' => self::media((float)($e['soma_sat'] ?? 0), (int)($e['n_sat'] ?? 0)),
            'enps' => self::enps($e),
        ];
    }

    /** Ordenação estável: desligamentos desc, geradas desc, nome asc. */
    private static function ordenarResumo(array &$linhas): void
    {
        usort($linhas, static fn(array $a, array $b): int => [$b['desligamentos'], $b['geradas'], $a['nome']] <=> [$a['desligamentos'], $a['geradas'], $b['nome']]);
    }

    private static function porUnidade(array $desl, array $entr): array
    {
        $grupos = [];
        foreach ($desl as $r) {
            $k = $r['codigo_empresa'] . '|' . $r['codigo_unidade'];
            $grupos[$k] = ['nome' => self::nomeUnidade($r), 'desligamentos' => (int)$r['desligamentos'], 'e' => []];
        }
        foreach ($entr as $r) {
            $k = $r['codigo_empresa'] . '|' . $r['codigo_unidade'];
            $grupos[$k] ??= ['nome' => self::nomeUnidade($r), 'desligamentos' => 0, 'e' => []];
            $grupos[$k]['e'] = $r;
        }
        $linhas = array_map(static fn(array $g): array => self::linhaResumo($g['nome'], $g['desligamentos'], $g['e']), array_values($grupos));
        self::ordenarResumo($linhas);
        return $linhas;
    }

    /** Cargo por `codigo_cargo` (vazio/nulo = "Não informado"); top 15 por desligamentos. */
    private static function porCargo(array $desl, array $entr): array
    {
        $grupos = [];
        $nome = static fn(?string $codigo, ?string $texto): string => $codigo === '' ? 'Não informado' : (trim((string)$texto) !== '' ? trim((string)$texto) : $codigo);
        foreach ($desl as $r) {
            $codigo = trim((string)($r['codigo_cargo'] ?? ''));
            $grupos[$codigo] ??= ['nome' => $nome($codigo, $r['cargo'] ?? null), 'desligamentos' => 0, 'e' => []];
            $grupos[$codigo]['desligamentos'] += (int)$r['desligamentos'];
        }
        foreach ($entr as $r) {
            $codigo = trim((string)($r['codigo_cargo'] ?? ''));
            $grupos[$codigo] ??= ['nome' => $nome($codigo, $r['cargo'] ?? null), 'desligamentos' => 0, 'e' => []];
            if ($grupos[$codigo]['e'] === []) {
                $grupos[$codigo]['e'] = $r;
            } else {
                foreach (['geradas', 'respondidas', 'n_sat', 'soma_sat', 'n_enps', 'promotores', 'neutros', 'detratores'] as $c) {
                    $grupos[$codigo]['e'][$c] = (float)($grupos[$codigo]['e'][$c] ?? 0) + (float)($r[$c] ?? 0);
                }
            }
        }
        $linhas = array_map(static fn(array $g): array => self::linhaResumo($g['nome'], $g['desligamentos'], $g['e']), array_values($grupos));
        self::ordenarResumo($linhas);
        return ['total_grupos' => count($linhas), 'itens' => array_slice($linhas, 0, self::LIMITE_CARGOS)];
    }
}
