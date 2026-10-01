<?php

/**
 * Acesso a dados AGREGADO (só leitura) para a seção executiva "Avaliações e Desenvolvimento" do
 * People Analytics (Etapa 9, 2026-10) — Avaliação de Experiência, Avaliação de Desempenho, Feedback
 * e PDI. Mesmo padrão de `DashboardEntrevistaDesligamentoRepository`/`DashboardIntegracaoRepository`:
 * poucas queries agregadas (nunca uma por colaborador/card/barra — §30/§31 da Etapa 9), nenhuma
 * regra de negócio (status/enums são lidos como já persistidos pelos Services donos, nunca
 * reclassificados aqui).
 *
 * Empresa/Setor filtram pelo SNAPSHOT já gravado em cada tabela (`snap_codigo_empresa`/
 * `snap_codigo_setor`) — nunca um JOIN novo com `colaboradores_metadados`, EXCETO em `pdis`, que não
 * tem `snap_codigo_setor` no snapshot (só Empresa/Unidade/Cargo): o filtro de Setor do PDI usa
 * `colaboradores_metadados.codigo_setor` via o `metadados_id` do próprio PDI (1 JOIN, não é N+1).
 */
class PeopleAnalyticsAvaliacoesRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }
        return $this->pdo;
    }

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @return array{0:string,1:array} fragmento WHERE (sem o "AND" inicial) + parâmetros, para snap_codigo_empresa/snap_codigo_setor de $alias. */
    private function filtroSnapshot(string $alias, ?string $empresa, ?string $setor): array
    {
        $where = '';
        $params = [];
        if ($empresa !== null && $empresa !== '') {
            $where .= " AND {$alias}.snap_codigo_empresa = ?";
            $params[] = $empresa;
        }
        if ($setor !== null && $setor !== '') {
            $where .= " AND {$alias}.snap_codigo_setor = ?";
            $params[] = $setor;
        }
        return [$where, $params];
    }

    // ============================================================ Avaliação de Experiência

    /** Realizadas no PERÍODO (status=concluido, ancorado em data_realizacao), por tipo 45/90. */
    public function experienciaRealizadasPorTipo(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $sql = "SELECT tipo, COUNT(*) AS n FROM avaliacoes_experiencia a
                WHERE status = 'concluido' AND data_realizacao BETWEEN ? AND ?{$extra}
                GROUP BY tipo";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $out = ['45' => 0, '90' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['tipo']] = (int)$row['n'];
        }
        return $out;
    }

    /** Backlog ATUAL (retrato de agora, como "Vagas Abertas"/"Headcount Atual" — não é período-delta). */
    public function experienciaSituacaoAtual(DateTimeImmutable $hoje, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $hojeStr = $hoje->format('Y-m-d');
        $pendentes = $this->scalar("SELECT COUNT(*) FROM avaliacoes_experiencia a WHERE status = 'rascunho' AND data_prevista >= ?{$extra}", array_merge([$hojeStr], $paramsExtra));
        $vencidas = $this->scalar("SELECT COUNT(*) FROM avaliacoes_experiencia a WHERE status = 'rascunho' AND data_prevista < ?{$extra}", array_merge([$hojeStr], $paramsExtra));
        $aguardandoCiencia = $this->scalar(
            "SELECT COUNT(*) FROM avaliacoes_experiencia a
             WHERE status = 'concluido'{$extra}
               AND NOT EXISTS (SELECT 1 FROM avaliacoes_desenvolvimento_ciencia c WHERE c.documento_tipo = 'avaliacao_experiencia' AND c.documento_id = a.id AND c.papel = 'colaborador')",
            $paramsExtra
        );
        return ['pendentes' => $pendentes, 'vencidas' => $vencidas, 'aguardando_ciencia' => $aguardandoCiencia];
    }

    /** Distribuição de PARECER das concluídas no período — só quando houver ao menos 1, nunca 4 fatias vazias. */
    public function experienciaPareceres(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $sql = "SELECT parecer, COUNT(*) AS n FROM avaliacoes_experiencia a
                WHERE status = 'concluido' AND data_realizacao BETWEEN ? AND ?{$extra}
                GROUP BY parecer";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['parecer']] = (int)$row['n'];
        }
        return $out;
    }

    // ============================================================ Avaliação de Desempenho

    /** @return array{concluidas:int,rascunho_atual:int,media_nota_atual:?float,media_nota_esperada:?float,gap_medio:?float} */
    public function desempenhoResumo(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $concluidas = $this->scalar("SELECT COUNT(*) FROM avaliacoes_desempenho a WHERE status = 'concluido' AND data_realizacao BETWEEN ? AND ?{$extra}", array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $rascunhoAtual = $this->scalar("SELECT COUNT(*) FROM avaliacoes_desempenho a WHERE status = 'rascunho'{$extra}", $paramsExtra);

        $sqlNotas = "SELECT AVG(cr.nota_atual) AS media_atual, AVG(cr.nota_esperada) AS media_esperada,
                            AVG(CASE WHEN cr.nota_esperada > cr.nota_atual THEN cr.nota_esperada - cr.nota_atual END) AS gap_medio
                     FROM avaliacoes_desempenho_criterios cr
                     INNER JOIN avaliacoes_desempenho a ON a.id = cr.avaliacao_id
                     WHERE a.status = 'concluido' AND a.data_realizacao BETWEEN ? AND ?{$extra}
                       AND cr.nota_atual IS NOT NULL AND cr.nota_esperada IS NOT NULL";
        $stmt = $this->connection()->prepare($sqlNotas);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'concluidas' => $concluidas,
            'rascunho_atual' => $rascunhoAtual,
            'media_nota_atual' => $row['media_atual'] !== null ? round((float)$row['media_atual'], 1) : null,
            'media_nota_esperada' => $row['media_esperada'] !== null ? round((float)$row['media_esperada'], 1) : null,
            'gap_medio' => $row['gap_medio'] !== null ? round((float)$row['gap_medio'], 1) : null,
        ];
    }

    /** Distribuição de resultado_final das concluídas no período. */
    public function desempenhoResultados(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $sql = "SELECT resultado_final, COUNT(*) AS n FROM avaliacoes_desempenho a
                WHERE status = 'concluido' AND data_realizacao BETWEEN ? AND ?{$extra}
                GROUP BY resultado_final";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['resultado_final']] = (int)$row['n'];
        }
        return $out;
    }

    // ============================================================ Feedback

    /** @return array{por_tipo:array<string,int>,desenvolvimento_necessario:int,acompanhamento:int} */
    public function feedbackResumo(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('f', $empresa, $setor);
        $sqlTipo = "SELECT tipo, COUNT(*) AS n FROM feedbacks f
                    WHERE status = 'concluido' AND data_feedback BETWEEN ? AND ?{$extra}
                    GROUP BY tipo";
        $stmt = $this->connection()->prepare($sqlTipo);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        $porTipo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porTipo[(string)$row['tipo']] = (int)$row['n'];
        }

        $devNecessario = $this->scalar(
            "SELECT COUNT(DISTINCT f.id) FROM feedbacks f
             INNER JOIN feedback_valores fv ON fv.feedback_id = f.id
             WHERE f.status = 'concluido' AND f.data_feedback BETWEEN ? AND ?{$extra} AND fv.avaliacao = 'desenvolvimento_necessario'",
            array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra)
        );
        $acompanhamento = $this->scalar(
            "SELECT COUNT(*) FROM feedbacks f WHERE f.status = 'concluido' AND f.data_feedback BETWEEN ? AND ?{$extra} AND f.resultado_geral = 'necessita_acompanhamento'",
            array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra)
        );

        return ['por_tipo' => $porTipo, 'desenvolvimento_necessario' => $devNecessario, 'acompanhamento' => $acompanhamento];
    }

    /** Valores culturais com Desenvolvimento Necessário, no período — ordenado do maior para o menor. */
    public function feedbackValoresDesenvolvimentoNecessario(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('f', $empresa, $setor);
        $sql = "SELECT fv.valor, COUNT(*) AS n FROM feedback_valores fv
                INNER JOIN feedbacks f ON f.id = fv.feedback_id
                WHERE f.status = 'concluido' AND f.data_feedback BETWEEN ? AND ?{$extra} AND fv.avaliacao = 'desenvolvimento_necessario'
                GROUP BY fv.valor ORDER BY n DESC";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute(array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================================ PDI

    /** Backlog ATUAL (ativos = não_iniciado/em_andamento; manual = origem_tipo sem documento). */
    public function pdiSituacaoAtual(?string $empresa, ?string $setor): array
    {
        $where = ' WHERE p.status IN (\'nao_iniciado\', \'em_andamento\')';
        $params = [];
        $join = '';
        if ($empresa !== null && $empresa !== '') {
            $where .= ' AND p.snap_codigo_empresa = ?';
            $params[] = $empresa;
        }
        if ($setor !== null && $setor !== '') {
            $join = ' LEFT JOIN colaboradores_metadados cm ON cm.id = p.metadados_id';
            $where .= ' AND cm.codigo_setor = ?';
            $params[] = $setor;
        }
        $ativos = $this->scalar("SELECT COUNT(*) FROM pdis p{$join}{$where}", $params);
        return ['ativos' => $ativos];
    }

    public function pdiConcluidosPeriodo(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): int
    {
        $where = " WHERE p.status = 'concluido' AND p.data_real_conclusao BETWEEN ? AND ?";
        $params = [$inicio->format('Y-m-d'), $fim->format('Y-m-d')];
        $join = '';
        if ($empresa !== null && $empresa !== '') {
            $where .= ' AND p.snap_codigo_empresa = ?';
            $params[] = $empresa;
        }
        if ($setor !== null && $setor !== '') {
            $join = ' LEFT JOIN colaboradores_metadados cm ON cm.id = p.metadados_id';
            $where .= ' AND cm.codigo_setor = ?';
            $params[] = $setor;
        }
        return $this->scalar("SELECT COUNT(*) FROM pdis p{$join}{$where}", $params);
    }

    /**
     * Vínculos de origem ATUAIS (pdi_origens — um PDI com múltiplas origens conta em cada
     * categoria; nunca "PDIs únicos", ver §16 da Etapa 9) + PDIs manuais (origem_tipo =
     * 'desenvolvimento_carreira' no cabeçalho, nunca tem linha em pdi_origens por desenho).
     *
     * @return array<string,int> chaves: avaliacao_experiencia, feedback, avaliacao_desempenho, manual
     */
    public function pdiVinculosOrigem(?string $empresa, ?string $setor): array
    {
        $joinCm = ($setor !== null && $setor !== '') ? ' LEFT JOIN colaboradores_metadados cm ON cm.id = p.metadados_id' : '';
        $extraEmpresa = ($empresa !== null && $empresa !== '') ? ' AND p.snap_codigo_empresa = ?' : '';
        $extraSetor = ($setor !== null && $setor !== '') ? ' AND cm.codigo_setor = ?' : '';
        $paramsBase = [];
        if ($empresa !== null && $empresa !== '') {
            $paramsBase[] = $empresa;
        }
        if ($setor !== null && $setor !== '') {
            $paramsBase[] = $setor;
        }

        $sqlOrigens = "SELECT o.origem_tipo, COUNT(*) AS n FROM pdi_origens o
                       INNER JOIN pdis p ON p.id = o.pdi_id{$joinCm}
                       WHERE 1 = 1{$extraEmpresa}{$extraSetor}
                       GROUP BY o.origem_tipo";
        $stmt = $this->connection()->prepare($sqlOrigens);
        $stmt->execute($paramsBase);
        $out = ['avaliacao_experiencia' => 0, 'feedback' => 0, 'avaliacao_desempenho' => 0, 'manual' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['origem_tipo']] = (int)$row['n'];
        }

        $sqlManual = "SELECT COUNT(*) AS n FROM pdis p{$joinCm} WHERE p.origem_tipo = 'desenvolvimento_carreira'{$extraEmpresa}{$extraSetor}";
        $out['manual'] = $this->scalar($sqlManual, $paramsBase);
        return $out;
    }

    // ============================================================ Cobertura de Desenvolvimento (§9/§10/§11/§12)

    /**
     * Por DOCUMENTO elegível (nunca por GAP/necessidade individual — §9): quantos documentos
     * concluídos com sinal real de necessidade (parecer que indica acompanhamento, valor
     * "Desenvolvimento Necessário", GAP > 0, etc. — mesmos sinais de *Service::candidatosParaPdi())
     * já têm ao menos um vínculo em `pdi_origens`.
     *
     * @return array{elegiveis:int,vinculados:int}
     */
    public function coberturaExperiencia(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $base = "FROM avaliacoes_experiencia a
                 WHERE a.status = 'concluido' AND a.data_realizacao BETWEEN ? AND ?{$extra}
                   AND a.parecer IN ('efetivacao_acompanhamento', 'prorrogacao_experiencia', 'nao_recomendado')";
        $params = array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra);
        $elegiveis = $this->scalar("SELECT COUNT(*) {$base}", $params);
        $vinculados = $this->scalar(
            "SELECT COUNT(*) {$base} AND EXISTS (SELECT 1 FROM pdi_origens o WHERE o.origem_tipo = 'avaliacao_experiencia' AND o.origem_ref_id = a.id)",
            $params
        );
        return ['elegiveis' => $elegiveis, 'vinculados' => $vinculados];
    }

    public function coberturaFeedback(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('f', $empresa, $setor);
        $base = "FROM feedbacks f
                 WHERE f.status = 'concluido' AND f.data_feedback BETWEEN ? AND ?{$extra}
                   AND (
                        f.resultado_geral IN ('em_desenvolvimento', 'necessita_acompanhamento')
                        OR f.pontos_desenvolvimento IS NOT NULL
                        OR EXISTS (SELECT 1 FROM feedback_valores fv WHERE fv.feedback_id = f.id AND fv.avaliacao = 'desenvolvimento_necessario')
                   )";
        $params = array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra);
        $elegiveis = $this->scalar("SELECT COUNT(*) {$base}", $params);
        $vinculados = $this->scalar(
            "SELECT COUNT(*) {$base} AND EXISTS (SELECT 1 FROM pdi_origens o WHERE o.origem_tipo = 'feedback' AND o.origem_ref_id = f.id)",
            $params
        );
        return ['elegiveis' => $elegiveis, 'vinculados' => $vinculados];
    }

    public function coberturaDesempenho(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?string $empresa, ?string $setor): array
    {
        [$extra, $paramsExtra] = $this->filtroSnapshot('a', $empresa, $setor);
        $base = "FROM avaliacoes_desempenho a
                 WHERE a.status = 'concluido' AND a.data_realizacao BETWEEN ? AND ?{$extra}
                   AND (
                        a.gaps_identificados IS NOT NULL
                        OR a.plano_acao_sugerido IS NOT NULL
                        OR EXISTS (SELECT 1 FROM avaliacoes_desempenho_criterios cr WHERE cr.avaliacao_id = a.id AND cr.nota_esperada IS NOT NULL AND cr.nota_atual IS NOT NULL AND cr.nota_esperada > cr.nota_atual)
                   )";
        $params = array_merge([$inicio->format('Y-m-d'), $fim->format('Y-m-d')], $paramsExtra);
        $elegiveis = $this->scalar("SELECT COUNT(*) {$base}", $params);
        $vinculados = $this->scalar(
            "SELECT COUNT(*) {$base} AND EXISTS (SELECT 1 FROM pdi_origens o WHERE o.origem_tipo = 'avaliacao_desempenho' AND o.origem_ref_id = a.id)",
            $params
        );
        return ['elegiveis' => $elegiveis, 'vinculados' => $vinculados];
    }
}
