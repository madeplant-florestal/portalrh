<?php

/**
 * Dashboard de Integração/Onboarding (Etapa 8, 2026-10) — painel gerencial AGREGADO sobre
 * `pesquisas_integracao` (fluxo coletivo QR + fluxo individual). Reaproveita INTEGRALMENTE
 * `PesquisaIntegracaoResultadosService::calcularNps()`/`classificarNps()` (mesma regra de NPS já
 * usada no detalhamento por data) e `PesquisaIntegracaoQrService::criteriosSatisfacao()` (as 5
 * perguntas REAIS do instrumento — nunca inventa pergunta nova).
 *
 * "Taxa de resposta" só é calculada para o FLUXO INDIVIDUAL (`colaborador_id`/`token_hash`
 * preenchidos): é o único subconjunto onde "gerada" é um evento anterior e distinto de
 * "respondida" — o fluxo QR só grava uma linha quando já respondida (inserirResposta() sempre
 * grava respondida_em = NOW()), então não existe "pendente" nesse fluxo e uma taxa de resposta ali
 * seria sempre 100% por desenho, não um indicador real (ver auditoria da Etapa 8).
 */
class DashboardIntegracaoService
{
    private DashboardIntegracaoRepository $repository;

    public function __construct(?DashboardIntegracaoRepository $repository = null)
    {
        $this->repository = $repository ?? new DashboardIntegracaoRepository();
    }

    // ================================================================== filtros

    public function opcoesFiltro(): array
    {
        $empresas = [];
        $unidades = [];
        $setores = [];
        foreach ($this->repository->opcoesEmpresaUnidadeSetor() as $linha) {
            $codigoEmpresa = (string)$linha['codigo_empresa'];
            if ($codigoEmpresa !== '' && !isset($empresas[$codigoEmpresa])) {
                $empresas[$codigoEmpresa] = ['codigo' => $codigoEmpresa, 'nome' => (string)($linha['empresa'] ?: $codigoEmpresa)];
            }
            $codigoUnidade = (string)$linha['codigo_unidade'];
            if ($codigoUnidade !== '') {
                $chave = $codigoEmpresa . '|' . $codigoUnidade;
                if (!isset($unidades[$chave])) {
                    $unidades[$chave] = ['chave' => $chave, 'codigo_empresa' => $codigoEmpresa, 'codigo_unidade' => $codigoUnidade, 'nome' => (string)($linha['unidade'] ?: $codigoUnidade)];
                }
            }
            $codigoSetor = (string)$linha['codigo_setor'];
            if ($codigoSetor !== '' && !isset($setores[$codigoSetor])) {
                $setores[$codigoSetor] = ['codigo' => $codigoSetor, 'nome' => (string)($linha['setor'] ?: $codigoSetor)];
            }
        }
        usort($empresas, static fn(array $a, array $b): int => $a['nome'] <=> $b['nome']);
        usort($unidades, static fn(array $a, array $b): int => $a['nome'] <=> $b['nome']);
        usort($setores, static fn(array $a, array $b): int => $a['nome'] <=> $b['nome']);
        return ['empresas' => array_values($empresas), 'unidades' => array_values($unidades), 'setores' => array_values($setores)];
    }

    /**
     * `ano` vazio = Todo o período (sem teto de meses — o volume de Integração é naturalmente
     * pequeno, diferente do Turnover/Entrevista de Desligamento, então não há necessidade de
     * limitar a uma janela rolante por padrão). `ano` + `mes` = só aquele mês; `ano` sozinho = o
     * ano inteiro; `mes` sem `ano` é ignorado (mesma convenção de DashboardEntrevistaDesligamentoService).
     *
     * @return array{inicio:string,fim:string,ano:string,mes:string,codigo_empresa:string,codigo_unidade:string,codigo_setor:string,avisos:string[]}
     */
    public static function normalizarFiltros(array $get, DateTimeImmutable $hoje, array $opcoes): array
    {
        $hoje = $hoje->setTime(0, 0);
        $avisos = [];

        $anoRaw = isset($get['ano']) && is_string($get['ano']) ? trim($get['ano']) : '';
        $ano = ($anoRaw !== '' && ctype_digit($anoRaw)) ? (int)$anoRaw : null;
        $mesRaw = isset($get['mes']) && is_string($get['mes']) ? trim($get['mes']) : '';
        $mes = ($ano !== null && ctype_digit($mesRaw) && (int)$mesRaw >= 1 && (int)$mesRaw <= 12) ? (int)$mesRaw : null;

        if ($ano !== null && $mes !== null) {
            $inicio = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
            $fim = $inicio->modify('last day of this month');
        } elseif ($ano !== null) {
            $inicio = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
            $fim = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
        } else {
            $inicio = new DateTimeImmutable('2000-01-01');
            $fim = $hoje;
        }
        if ($fim > $hoje) {
            $fim = $hoje;
            if ($ano !== null) {
                $avisos[] = 'O período foi limitado até hoje.';
            }
        }

        $empresaChave = is_string($get['empresa'] ?? null) ? $get['empresa'] : '';
        $codigoEmpresa = '';
        foreach ($opcoes['empresas'] ?? [] as $e) {
            if ($e['codigo'] === $empresaChave) {
                $codigoEmpresa = $empresaChave;
                break;
            }
        }
        $unidadeChave = is_string($get['unidade'] ?? null) ? $get['unidade'] : '';
        $codigoUnidade = '';
        $unidadeEmpresa = '';
        foreach ($opcoes['unidades'] ?? [] as $u) {
            if ($u['chave'] === $unidadeChave) {
                $codigoUnidade = $u['codigo_unidade'];
                $unidadeEmpresa = $u['codigo_empresa'];
                break;
            }
        }
        if ($codigoUnidade !== '' && $codigoEmpresa === '') {
            $codigoEmpresa = $unidadeEmpresa; // selecionar a unidade já implica a empresa dela
        }
        $setorChave = is_string($get['setor'] ?? null) ? $get['setor'] : '';
        $codigoSetor = '';
        foreach ($opcoes['setores'] ?? [] as $s) {
            if ($s['codigo'] === $setorChave) {
                $codigoSetor = $setorChave;
                break;
            }
        }

        return [
            'inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d'),
            'ano' => $ano !== null ? (string)$ano : '', 'mes' => $mes !== null ? (string)$mes : '',
            'codigo_empresa' => $codigoEmpresa, 'codigo_unidade' => $codigoUnidade, 'codigo_setor' => $codigoSetor,
            'avisos' => $avisos,
        ];
    }

    public static function atalhos(DateTimeImmutable $hoje): array
    {
        $hoje = $hoje->setTime(0, 0);
        return [
            ['rotulo' => 'Todo o período', 'ano' => '', 'mes' => ''],
            ['rotulo' => 'Ano atual', 'ano' => $hoje->format('Y'), 'mes' => ''],
            ['rotulo' => 'Ano anterior', 'ano' => (string)((int)$hoje->format('Y') - 1), 'mes' => ''],
            ['rotulo' => 'Este mês', 'ano' => $hoje->format('Y'), 'mes' => $hoje->format('n')],
        ];
    }

    // ================================================================== painel

    private static function rotuloMes(string $anoMes): string
    {
        $meses = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
        [$ano, $mes] = explode('-', $anoMes);
        return ($meses[$mes] ?? $mes) . '/' . substr($ano, 2);
    }

    public function montarPainel(array $filtros): array
    {
        $respostas = $this->repository->respostas($filtros);

        $notasNps = array_values(array_map(static fn(array $r): int => (int)$r['nota_nps'], array_filter($respostas, static fn(array $r): bool => $r['nota_nps'] !== null)));
        $npsGeral = PesquisaIntegracaoResultadosService::calcularNps($notasNps);

        $notasSatisfacao = array_values(array_map(static fn(array $r): int => (int)$r['nota_satisfacao_geral'], array_filter($respostas, static fn(array $r): bool => $r['nota_satisfacao_geral'] !== null)));
        $satisfacaoGeral = ['media' => $notasSatisfacao === [] ? null : round(array_sum($notasSatisfacao) / count($notasSatisfacao), 1), 'n' => count($notasSatisfacao)];

        // Perguntas REAIS do instrumento (nunca inventadas) — média + distribuição 1-5.
        $perguntas = [];
        foreach (PesquisaIntegracaoQrService::criteriosSatisfacao() as $campo => $rotulo) {
            $distribuicao = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $soma = 0;
            $n = 0;
            foreach ($respostas as $r) {
                if ($r[$campo] === null) {
                    continue;
                }
                $nota = (int)$r[$campo];
                $soma += $nota;
                $n++;
                if (isset($distribuicao[$nota])) {
                    $distribuicao[$nota]++;
                }
            }
            $perguntas[] = ['campo' => $campo, 'rotulo' => $rotulo, 'media' => $n === 0 ? null : round($soma / $n, 1), 'distribuicao' => $distribuicao, 'n' => $n];
        }

        // Evolução mensal — NPS e volume, ancorados em integracao_data_relacionada (nunca respondida_em).
        $porMes = [];
        foreach ($respostas as $r) {
            $chave = substr((string)$r['integracao_data_relacionada'], 0, 7);
            $porMes[$chave]['notas'][] = $r['nota_nps'] !== null ? (int)$r['nota_nps'] : null;
            $porMes[$chave]['total'] = ($porMes[$chave]['total'] ?? 0) + 1;
        }
        ksort($porMes);
        $labelsMes = [];
        $npsSerie = [];
        $volumeSerie = [];
        foreach ($porMes as $chave => $dados) {
            $notasValidas = array_values(array_filter($dados['notas'], static fn(?int $v): bool => $v !== null));
            $calc = PesquisaIntegracaoResultadosService::calcularNps($notasValidas);
            $labelsMes[] = self::rotuloMes($chave);
            $npsSerie[] = $calc['nps'];
            $volumeSerie[] = (float)$dados['total'];
        }

        // Fluxo individual — único subconjunto com um evento real de "gerada" distinto de "respondida".
        $individuais = $this->repository->pesquisasIndividuais($filtros);
        $totalGeradas = count($individuais);
        $totalRespondidas = count(array_filter($individuais, static fn(array $r): bool => $r['respondida_em'] !== null));
        $fluxoIndividual = [
            'geradas' => $totalGeradas, 'respondidas' => $totalRespondidas,
            'taxa_resposta' => $totalGeradas === 0 ? null : round(($totalRespondidas / $totalGeradas) * 100, 1),
        ];

        $comentarios = [];
        foreach ($respostas as $r) {
            $texto = trim((string)($r['comentarios'] ?? ''));
            if ($texto === '') {
                continue;
            }
            // Bloco 3 (2026-10, pedido do RH): nunca o Nome do respondente — só Cargo/Empresa como
            // contexto agregado. O nome continua intocado em colaboradores_metadados.
            $comentarios[] = ['comentario' => $texto, 'respondida_em' => (string)$r['respondida_em'], 'cargo' => $r['cargo'], 'empresa' => $r['empresa']];
            if (count($comentarios) >= 15) {
                break;
            }
        }

        // Integrações REALIZADAS (Bloco 3, 2026-10, pedido do RH) — fonte oficial já existente
        // (colaboradores.integracao_status = 'realizada'), conceito DISTINTO de "respostas
        // recebidas": nunca usar a contagem de pesquisas como substituto.
        $integracoesRealizadas = $this->repository->integracoesRealizadas($filtros);

        return [
            'total_respostas' => count($respostas),
            'integracoes_realizadas' => $integracoesRealizadas,
            'nps' => $npsGeral,
            'satisfacao_geral' => $satisfacaoGeral,
            'perguntas' => $perguntas,
            'mensal' => ['labels' => $labelsMes, 'nps' => $npsSerie, 'volume' => $volumeSerie],
            'fluxo_individual' => $fluxoIndividual,
            'comentarios' => $comentarios,
        ];
    }
}
