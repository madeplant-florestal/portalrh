<?php

/**
 * Dashboard AGREGADO de Integração para o Gestor (Etapa 2C.2C, 2026-11) — indicadores dos Setores Gerenciados, com PRIVACIDADE no backend.
 *
 * É um serviço PRÓPRIO (o painel operacional de Admin/RH, DashboardIntegracaoService, não muda). Escopo = EscopoSetorial (`usuario_setores_gerenciados`),
 * aplicado como AND obrigatório no repositório; nenhum filtro o amplia (um filtro fora do escopo nem existe nas opções e é descartado).
 *
 * REGRAS (mínimo K = 5 respondentes DISTINTOS, após TODOS os filtros — "regra inicial de proteção, não garantia absoluta de anonimato"):
 *  1. Nunca sai comentário livre, resposta individual, nome, id, cargo, data de resposta ou qualquer campo do respondente (lista fechada de saída).
 *  2. Respondente distinto = pessoa (`codigo_pessoa`; senão contrato; senão colaborador); várias respostas da mesma pessoa contam UMA (a mais recente).
 *  3. Conjunto filtrado com < K respondentes => SOMENTE "dados insuficientes" (nem o total).
 *  4. Grupos (por setor, por mês) com < K são suprimidos; SUPRESSÃO COMPLEMENTAR: se algum grupo foi suprimido e o resíduo (total − grupos exibidos)
 *     tiver < K respondentes, suprime-se também o MENOR grupo exibido, repetindo até o resíduo ter >= K (ou nenhum grupo restar) — assim o total
 *     menos os grupos exibidos nunca isola menos de K pessoas.
 *  5. DIFERENÇA ENTRE FILTROS: para o conjunto pedido R, cada visão MAIS AMPLA S que o usuário consegue obter relaxando filtros (período mês→ano→todo,
 *     empresa/unidade, setor — todas as combinações) é comparada: se S for exibível (>= K) e 0 < |S \ R| < K, a combinação é recusada ("dados
 *     insuficientes"), pois S − R isolaria poucas pessoas.
 *  6. Fluxo individual (geradas/respondidas) e "integrações realizadas" só aparecem com >= K (e respondidas >= K); senão são omitidos.
 * Limite conhecido: ataques por várias consultas combinadas (trackers) não são totalmente evitáveis por nenhuma regra local — ver riscos-conhecidos.md.
 */
final class DashboardIntegracaoAgregadoService
{
    public const MINIMO = 5;

    private DashboardIntegracaoRepository $repository;

    public function __construct(?DashboardIntegracaoRepository $repository = null)
    {
        $this->repository = $repository ?? new DashboardIntegracaoRepository();
    }

    /** Opções de filtro SÓ do escopo (empresas/unidades/setores que têm pesquisa dentro dos Setores Gerenciados). */
    public function opcoesFiltro(EscopoSetorial $escopo): array
    {
        $empresas = [];
        $unidades = [];
        $setores = [];
        foreach ($this->repository->opcoesEmpresaUnidadeSetor($this->codigosDoEscopo($escopo)) as $linha) {
            $ce = (string)$linha['codigo_empresa'];
            if ($ce !== '' && !isset($empresas[$ce])) {
                $empresas[$ce] = ['codigo' => $ce, 'nome' => (string)($linha['empresa'] ?: $ce)];
            }
            $cu = (string)$linha['codigo_unidade'];
            if ($cu !== '' && !isset($unidades[$ce . '|' . $cu])) {
                $unidades[$ce . '|' . $cu] = ['chave' => $ce . '|' . $cu, 'codigo_empresa' => $ce, 'codigo_unidade' => $cu, 'nome' => (string)($linha['unidade'] ?: $cu)];
            }
            $cs = (string)$linha['codigo_setor'];
            if ($cs !== '' && !isset($setores[$cs])) {
                $setores[$cs] = ['codigo' => $cs, 'nome' => (string)($linha['setor'] ?: $cs)];
            }
        }
        $ordena = static fn(array $a, array $b): int => $a['nome'] <=> $b['nome'];
        usort($empresas, $ordena);
        usort($unidades, $ordena);
        usort($setores, $ordena);
        return ['empresas' => array_values($empresas), 'unidades' => array_values($unidades), 'setores' => array_values($setores), 'gestores' => []];
    }

    /**
     * @param array<string,mixed> $get filtros brutos (ano, mes, empresa, unidade, setor) — o `gestor` do painel operacional é IGNORADO aqui
     * @return array<string,mixed> estrutura FECHADA (ver topo): nunca contém dado individual
     */
    public function montar(array $get, DateTimeImmutable $hoje, EscopoSetorial $escopo): array
    {
        $opcoes = $this->opcoesFiltro($escopo);
        unset($get['gestor']);
        $filtros = DashboardIntegracaoService::normalizarFiltros($get, $hoje, $opcoes);
        $saida = ['minimo' => self::MINIMO, 'suficiente' => false, 'filtros' => self::filtrosPublicos($filtros), 'opcoes' => $opcoes];

        if (!$escopo->enxergaAlgo()) {
            return $saida + ['motivo' => 'sem_escopo'];
        }
        $codigos = $this->codigosDoEscopo($escopo);

        // UMA consulta com o período mais amplo e SEM empresa/unidade/setor (mas COM o escopo): todas as visões (a pedida e as mais amplas) saem daqui.
        $amplo = DashboardIntegracaoService::normalizarFiltros([], $hoje, $opcoes);
        $amplo['escopo_codigos'] = $codigos;
        $todas = $this->repository->respostas($amplo);

        $pedidas = self::filtrarEDeduplicar($todas, $filtros);
        if (count($pedidas) < self::MINIMO) {
            return $saida + ['motivo' => 'poucos_respondentes'];
        }
        $chavesPedidas = array_keys($pedidas);
        foreach (self::visoesMaisAmplas($get, $hoje, $opcoes, $filtros) as $visao) {
            $maior = self::filtrarEDeduplicar($todas, $visao);
            if (count($maior) >= self::MINIMO) {
                $extras = count(array_diff(array_keys($maior), $chavesPedidas));
                if ($extras > 0 && $extras < self::MINIMO) {
                    return $saida + ['motivo' => 'combinacao_de_filtros'];
                }
            }
        }

        $linhas = array_values($pedidas);
        $notas = static fn(array $rs, string $campo): array => array_values(array_map(static fn(array $r): int => (int)$r[$campo], array_filter($rs, static fn(array $r): bool => $r[$campo] !== null)));
        $media = static function (array $n): ?float {
            return $n === [] ? null : round(array_sum($n) / count($n), 1);
        };

        $perguntas = [];
        foreach (PesquisaIntegracaoQrService::criteriosSatisfacao() as $campo => $rotulo) {
            $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            foreach ($notas($linhas, $campo) as $nota) {
                if (isset($dist[$nota])) {
                    $dist[$nota]++;
                }
            }
            $perguntas[] = ['rotulo' => $rotulo, 'media' => $media($notas($linhas, $campo)), 'distribuicao' => $dist];
        }

        // Grupos: por setor e por mês (cada um com supressão primária + complementar)
        $porSetor = [];
        $porMes = [];
        foreach ($linhas as $r) {
            $s = (string)($r['codigo_setor'] ?? '');
            $porSetor[$s]['rotulo'] = (string)($r['setor'] ?: $s);
            $porSetor[$s]['linhas'][] = $r;
            $m = substr((string)$r['integracao_data_relacionada'], 0, 7);
            $porMes[$m]['rotulo'] = $m;
            $porMes[$m]['linhas'][] = $r;
        }
        ksort($porMes);
        $resumo = static function (array $g) use ($notas, $media): array {
            return ['n' => count($g['linhas']), 'rotulo' => $g['rotulo'], 'nps' => PesquisaIntegracaoResultadosService::calcularNps($notas($g['linhas'], 'nota_nps'))['nps'], 'satisfacao' => $media($notas($g['linhas'], 'nota_satisfacao_geral'))];
        };
        $gruposSetor = self::suprimirGrupos(array_map($resumo, $porSetor));
        $gruposMes = self::suprimirGrupos(array_map($resumo, $porMes));
        $formata = static fn(array $g): array => ['rotulo' => $g['rotulo'], 'respondentes' => $g['n'], 'nps' => $g['nps'], 'satisfacao' => $g['satisfacao']];
        $mensal = [];
        foreach ($porMes as $chave => $g) {
            $mensal[] = isset($gruposMes['visiveis'][$chave]) ? ['suprimido' => false] + $formata($gruposMes['visiveis'][$chave]) : ['suprimido' => true, 'rotulo' => $chave];
        }

        // Fluxo individual e integrações realizadas (contagens operacionais): só com >= K
        $comEscopo = $filtros + ['escopo_codigos' => $codigos];
        $individuais = $this->repository->pesquisasIndividuais($comEscopo);
        $geradas = count($individuais);
        $respondidas = count(array_filter($individuais, static fn(array $r): bool => $r['respondida_em'] !== null));
        $fluxo = ($geradas >= self::MINIMO && $respondidas >= self::MINIMO)
            ? ['geradas' => $geradas, 'respondidas' => $respondidas, 'taxa_resposta' => round($respondidas / $geradas * 100, 1)] : null;
        $realizadas = $this->repository->integracoesRealizadas($comEscopo);

        return array_merge($saida, [
            'suficiente' => true,
            'respondentes' => count($linhas),
            'nps' => PesquisaIntegracaoResultadosService::calcularNps($notas($linhas, 'nota_nps')),
            'satisfacao_geral' => $media($notas($linhas, 'nota_satisfacao_geral')),
            'perguntas' => $perguntas,
            'por_setor' => ['grupos' => array_map($formata, array_values($gruposSetor['visiveis'])), 'suprimidos' => $gruposSetor['suprimidos']],
            'mensal' => $mensal,
            'fluxo_individual' => $fluxo,
            'integracoes_realizadas' => $realizadas >= self::MINIMO ? $realizadas : null,
        ]);
    }

    // ------------------------------------------------------------------ privacidade (puro, testável)

    /**
     * Supressão primária (< MINIMO) + COMPLEMENTAR (o resíduo total − exibidos nunca isola < MINIMO).
     *
     * @param array<string|int,array{n:int}> $grupos
     * @return array{visiveis:array<string|int,array>,suprimidos:int}
     */
    public static function suprimirGrupos(array $grupos): array
    {
        $visiveis = [];
        $suprimidos = [];
        foreach ($grupos as $chave => $g) {
            if ((int)$g['n'] < self::MINIMO) {
                $suprimidos[$chave] = $g;
            } else {
                $visiveis[$chave] = $g;
            }
        }
        if ($suprimidos !== []) {
            $residuo = array_sum(array_map(static fn(array $g): int => (int)$g['n'], $suprimidos));
            while ($residuo < self::MINIMO && $visiveis !== []) {
                $menor = array_key_first($visiveis);
                foreach ($visiveis as $k => $g) {
                    if ((int)$g['n'] < (int)$visiveis[$menor]['n']) {
                        $menor = $k;
                    }
                }
                $residuo += (int)$visiveis[$menor]['n'];
                $suprimidos[$menor] = $visiveis[$menor];
                unset($visiveis[$menor]);
            }
        }
        return ['visiveis' => $visiveis, 'suprimidos' => count($suprimidos)];
    }

    /** Respondente distinto: pessoa > contrato > colaborador > a própria linha. */
    public static function chaveRespondente(array $r): string
    {
        if (trim((string)($r['codigo_pessoa'] ?? '')) !== '') {
            return 'P' . $r['codigo_pessoa'];
        }
        if (!empty($r['metadados_id'])) {
            return 'M' . $r['metadados_id'];
        }
        if (!empty($r['colaborador_id'])) {
            return 'C' . $r['colaborador_id'];
        }
        return 'R' . ($r['id'] ?? spl_object_id((object)$r));
    }

    /** Aplica período/empresa/unidade/setor em memória e mantém UMA linha (a mais recente) por respondente distinto. @return array<string,array> chave => linha */
    private static function filtrarEDeduplicar(array $linhas, array $f): array
    {
        $r = [];
        foreach ($linhas as $l) {
            $data = (string)$l['integracao_data_relacionada'];
            if ($data < (string)$f['inicio'] || $data > (string)$f['fim']) {
                continue;
            }
            if (($f['codigo_empresa'] ?? '') !== '' && (string)$l['codigo_empresa'] !== $f['codigo_empresa']) {
                continue;
            }
            if (($f['codigo_unidade'] ?? '') !== '' && (string)$l['codigo_unidade'] !== $f['codigo_unidade']) {
                continue;
            }
            if (($f['codigo_setor'] ?? '') !== '' && (string)$l['codigo_setor'] !== $f['codigo_setor']) {
                continue;
            }
            $k = self::chaveRespondente($l);
            if (!isset($r[$k]) || (string)$l['respondida_em'] > (string)$r[$k]['respondida_em']) {
                $r[$k] = $l;
            }
        }
        return $r;
    }

    /**
     * Todas as visões MAIS AMPLAS alcançáveis relaxando período (mês→ano→todo), empresa/unidade e setor, em qualquer combinação (menos a própria).
     *
     * @return array<int,array<string,string>> filtros normalizados
     */
    private static function visoesMaisAmplas(array $get, DateTimeImmutable $hoje, array $opcoes, array $f): array
    {
        $periodos = [[$f['ano'], $f['mes']]];
        if ($f['mes'] !== '') {
            $periodos[] = [$f['ano'], ''];
        }
        if ($f['ano'] !== '') {
            $periodos[] = ['', ''];
        }
        $empresas = [[$f['codigo_empresa'], $f['codigo_unidade']]];
        if ($f['codigo_unidade'] !== '') {
            $empresas[] = [$f['codigo_empresa'], ''];
        }
        if ($f['codigo_empresa'] !== '') {
            $empresas[] = ['', ''];
        }
        $setores = [$f['codigo_setor']];
        if ($f['codigo_setor'] !== '') {
            $setores[] = '';
        }
        $visoes = [];
        foreach ($periodos as $pi => [$ano, $mes]) {
            foreach ($empresas as $ei => [$ce, $cu]) {
                foreach ($setores as $si => $cs) {
                    if ($pi === 0 && $ei === 0 && $si === 0) {
                        continue;
                    }
                    $p = DashboardIntegracaoService::normalizarFiltros(['ano' => $ano, 'mes' => $mes], $hoje, $opcoes);
                    $p['codigo_empresa'] = $ce;
                    $p['codigo_unidade'] = $cu;
                    $p['codigo_setor'] = $cs;
                    $visoes[] = $p;
                }
            }
        }
        return $visoes;
    }

    /** @return string[]|null códigos do escopo para o repositório (`null` só para escopo global; `[]` = nenhum) */
    private function codigosDoEscopo(EscopoSetorial $escopo): ?array
    {
        return $escopo->semAutorizacao() ? [] : $escopo->codigosOuNull();
    }

    private static function filtrosPublicos(array $f): array
    {
        return ['ano' => $f['ano'], 'mes' => $f['mes'], 'empresa' => $f['codigo_empresa'], 'unidade' => $f['codigo_unidade'], 'setor' => $f['codigo_setor'], 'avisos' => $f['avisos']];
    }
}
