<?php

/**
 * Acesso a dados do PDI (Plano de Desenvolvimento Individual — V1 assistida). Sem regra de negócio: autorização,
 * transições, prazos e validação vivem em PdiService. Contratos vêm EXCLUSIVAMENTE do espelho oficial
 * `colaboradores_metadados` (vínculo por `metadados_id`; nunca CPF, `codigo_pessoa` nem `colaboradores` legado) e o
 * nome do Cargo pelo catálogo `cargos` (fallback: texto do espelho). Tempo entra sempre por parâmetro (nunca NOW()).
 *
 * `pdi_acompanhamentos` e `pdi_eventos` são APPEND-ONLY: este repository só oferece INSERT para elas — não há
 * método de UPDATE/DELETE por desenho.
 */
class PdiRepository
{
    /** Colunas de `pdis` que atualizarPdi() aceita — whitelist, nunca vem crua do request. */
    public const COLUNAS_ATUALIZAVEIS = [
        'gestor_usuario_id', 'gestor_nome_snapshot', 'origem_tipo', 'origem_ref_tipo', 'origem_ref_id', 'status',
        'data_abertura', 'data_prevista_conclusao', 'data_real_conclusao', 'pontos_fortes', 'oportunidades_desenvolvimento',
        'objetivo_esperado', 'momento_profissional', 'pontos_desenvolver_colaborador', 'evidencias_evolucao',
        'avaliacao_final', 'comentarios_finais',
    ];

    private const COLUNAS_INSERIVEIS = [
        'metadados_id', 'snap_nome', 'snap_codigo_empresa', 'snap_empresa', 'snap_codigo_unidade', 'snap_unidade',
        'snap_codigo_cargo', 'snap_cargo', 'snap_admissao', 'snap_data_inicio_cargo', 'gestor_usuario_id',
        'gestor_nome_snapshot', 'origem_tipo', 'origem_ref_tipo', 'origem_ref_id', 'status', 'data_abertura',
        'data_prevista_conclusao', 'pontos_fortes', 'oportunidades_desenvolvimento', 'objetivo_esperado',
        'criado_por_usuario_id',
    ];

    private const SQL_CARGO_CATALOGO = "(SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci LIMIT 1)";

    private const SELECT_CONTRATO = "SELECT cm.id AS metadados_id, cm.nome, cm.codigo_empresa, cm.empresa, cm.codigo_unidade, cm.unidade,
            cm.codigo_cargo, COALESCE(%s, NULLIF(cm.cargo, '')) AS cargo, cm.admissao, cm.data_inicio_cargo, cm.demissao
        FROM colaboradores_metadados cm";

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

    /** Executa `$acao` em transação (uma alteração + seus eventos de auditoria são atômicos). */
    public function transacao(callable $acao): mixed
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $resultado = $acao();
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function todas(string $sql, array $params = []): array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function uma(string $sql, array $params = []): ?array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ------------------------------------------------------------------ contratos oficiais e usuários

    public function buscarContrato(int $metadadosId): ?array
    {
        return $this->uma(sprintf(self::SELECT_CONTRATO, self::SQL_CARGO_CATALOGO) . ' WHERE cm.id = ? LIMIT 1', [$metadadosId]);
    }

    /** Contratos sem desligamento efetivado (nome/empresa/unidade contendo o texto), para escolher o colaborador. */
    public function buscarContratos(string $busca, string $hoje, int $limite = 20): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
        return $this->todas(
            sprintf(self::SELECT_CONTRATO, self::SQL_CARGO_CATALOGO)
            . " WHERE (cm.demissao IS NULL OR cm.demissao > ?)
                 AND (cm.nome LIKE ? ESCAPE '\\\\' OR cm.empresa LIKE ? ESCAPE '\\\\' OR cm.unidade LIKE ? ESCAPE '\\\\')
               ORDER BY cm.nome LIMIT " . max(1, $limite),
            [$hoje, $like, $like, $like]
        );
    }

    /** Usuário do Portal ativo (e-mail verificado). Só id, nome e role — nunca e-mail nem hash. */
    public function usuarioAtivo(int $id): ?array
    {
        return $this->uma('SELECT id, nome, role FROM usuarios WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1', [$id]);
    }

    public function usuariosAtivos(): array
    {
        return $this->todas('SELECT id, nome, role FROM usuarios WHERE email_verified_at IS NOT NULL ORDER BY nome LIMIT 500');
    }

    // ------------------------------------------------------------------ PDI

    public function inserirPdi(array $dados, string $agora): int
    {
        $colunas = [];
        $valores = [];
        foreach (self::COLUNAS_INSERIVEIS as $coluna) {
            $colunas[] = $coluna;
            $valores[] = $dados[$coluna] ?? null;
        }
        $colunas[] = 'criado_em';
        $valores[] = $agora;
        $colunas[] = 'atualizado_em';
        $valores[] = $agora;
        $stmt = $this->connection()->prepare(
            'INSERT INTO pdis (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')'
        );
        $stmt->execute($valores);
        return (int)$this->connection()->lastInsertId();
    }

    /** @param array $campos coluna => valor (só COLUNAS_ATUALIZAVEIS) */
    public function atualizarPdi(int $id, array $campos, string $agora): void
    {
        $sets = [];
        $params = [];
        foreach ($campos as $coluna => $valor) {
            if (!in_array($coluna, self::COLUNAS_ATUALIZAVEIS, true)) {
                throw new InvalidArgumentException('Coluna não atualizável: ' . $coluna);
            }
            $sets[] = $coluna . ' = ?';
            $params[] = $valor;
        }
        $sets[] = 'atualizado_em = ?';
        $params[] = $agora;
        $params[] = $id;
        $this->connection()->prepare('UPDATE pdis SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** PDI + contexto ATUAL do contrato oficial (para sinalizar divergência com o snapshot) + nome atual do gestor. */
    public function buscarPdi(int $id): ?array
    {
        return $this->uma(
            'SELECT p.*, cm.codigo_empresa AS atual_codigo_empresa, cm.codigo_unidade AS atual_codigo_unidade,
                    cm.codigo_cargo AS atual_codigo_cargo, cm.data_inicio_cargo AS atual_data_inicio_cargo,
                    cm.demissao AS atual_demissao, u.nome AS gestor_nome_atual, cr.nome AS criado_por_nome
             FROM pdis p
             LEFT JOIN colaboradores_metadados cm ON cm.id = p.metadados_id
             LEFT JOIN usuarios u ON u.id = p.gestor_usuario_id
             LEFT JOIN usuarios cr ON cr.id = p.criado_por_usuario_id
             WHERE p.id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Lista com contagem de ações (total/concluídas/atrasadas) — uma consulta, sem N+1.
     *
     * @param array   $f             status, busca, empresa, unidade ("EMP|UNI"), cargo, gestor, origem, prazo (atrasado|no_prazo)
     * @param ?int    $escopoGestor  não nulo = só PDIs desse gestor (escopo por linha de quem não é Admin/RH)
     */
    public function listar(array $f, ?int $escopoGestor, string $hoje, int $limite): array
    {
        $where = ['1 = 1'];
        $params = [$hoje];
        if ($escopoGestor !== null) {
            $where[] = 'p.gestor_usuario_id = ?';
            $params[] = $escopoGestor;
        }
        if (!empty($f['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string)$f['status'];
        }
        if (!empty($f['busca'])) {
            $where[] = "p.snap_nome LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$f['busca']) . '%';
        }
        if (!empty($f['empresa'])) {
            $where[] = 'p.snap_codigo_empresa = ?';
            $params[] = (string)$f['empresa'];
        }
        if (!empty($f['unidade']) && is_array($f['unidade'])) {
            $where[] = 'p.snap_codigo_empresa = ? AND p.snap_codigo_unidade = ?';
            $params[] = (string)$f['unidade']['codigo_empresa'];
            $params[] = (string)$f['unidade']['codigo_unidade'];
        }
        if (!empty($f['cargo'])) {
            $where[] = 'p.snap_codigo_cargo = ?';
            $params[] = (string)$f['cargo'];
        }
        if (!empty($f['gestor'])) {
            $where[] = 'p.gestor_usuario_id = ?';
            $params[] = (int)$f['gestor'];
        }
        if (!empty($f['origem'])) {
            $where[] = 'p.origem_tipo = ?';
            $params[] = (string)$f['origem'];
        }
        if (($f['prazo'] ?? '') === 'atrasado') {
            $where[] = "p.status IN ('nao_iniciado','em_andamento') AND p.data_prevista_conclusao < ?";
            $params[] = $hoje;
        } elseif (($f['prazo'] ?? '') === 'no_prazo') {
            $where[] = "p.status IN ('nao_iniciado','em_andamento') AND p.data_prevista_conclusao >= ?";
            $params[] = $hoje;
        }
        $sql = "SELECT p.id, p.metadados_id, p.snap_nome, p.snap_empresa, p.snap_unidade, p.snap_cargo, p.gestor_usuario_id,
                       p.gestor_nome_snapshot, p.origem_tipo, p.status, p.data_abertura, p.data_prevista_conclusao,
                       p.data_real_conclusao, p.avaliacao_final,
                       (SELECT COUNT(*) FROM pdi_acoes a WHERE a.pdi_id = p.id) AS total_acoes,
                       (SELECT COUNT(*) FROM pdi_acoes a WHERE a.pdi_id = p.id AND a.status = 'concluida') AS acoes_concluidas,
                       (SELECT COUNT(*) FROM pdi_acoes a WHERE a.pdi_id = p.id AND a.status <> 'concluida' AND a.prazo < ?) AS acoes_atrasadas
                FROM pdis p
                WHERE " . implode(' AND ', array_slice($where, 0)) . "
                ORDER BY CASE p.status WHEN 'em_andamento' THEN 1 WHEN 'nao_iniciado' THEN 2 WHEN 'rascunho' THEN 3 WHEN 'concluido' THEN 4 ELSE 5 END,
                         p.data_prevista_conclusao ASC, p.id DESC
                LIMIT " . max(1, $limite);
        // O primeiro `?` (acoes_atrasadas) vem antes dos filtros na ordem dos parâmetros.
        return $this->todas($sql, $params);
    }

    /** Opções dos filtros da lista, dentro do escopo (só valores que existem em PDIs acessíveis). */
    public function opcoesFiltro(?int $escopoGestor): array
    {
        $escopo = $escopoGestor !== null ? ' WHERE gestor_usuario_id = ?' : '';
        $params = $escopoGestor !== null ? [$escopoGestor] : [];
        return [
            'empresas' => $this->todas("SELECT snap_codigo_empresa AS codigo, MAX(snap_empresa) AS nome FROM pdis{$escopo} GROUP BY snap_codigo_empresa ORDER BY nome", $params),
            'unidades' => $this->todas("SELECT snap_codigo_empresa AS codigo_empresa, snap_codigo_unidade AS codigo_unidade, MAX(snap_unidade) AS nome, MAX(snap_empresa) AS empresa FROM pdis{$escopo} GROUP BY snap_codigo_empresa, snap_codigo_unidade ORDER BY nome", $params),
            'cargos' => $this->todas("SELECT snap_codigo_cargo AS codigo, MAX(snap_cargo) AS nome FROM pdis{$escopo}" . ($escopo === '' ? ' WHERE' : ' AND') . " snap_codigo_cargo IS NOT NULL AND snap_codigo_cargo <> '' GROUP BY snap_codigo_cargo ORDER BY nome", $params),
            'gestores' => $this->todas("SELECT gestor_usuario_id AS id, MAX(gestor_nome_snapshot) AS nome FROM pdis{$escopo} GROUP BY gestor_usuario_id ORDER BY nome", $params),
        ];
    }

    // ------------------------------------------------------------------ competências

    public function competencias(int $pdiId): array
    {
        return $this->todas('SELECT id, competencia_texto, competencia_id, criado_em FROM pdi_competencias WHERE pdi_id = ? ORDER BY id', [$pdiId]);
    }

    public function inserirCompetencia(int $pdiId, string $texto, string $agora): void
    {
        $this->connection()->prepare('INSERT INTO pdi_competencias (pdi_id, competencia_texto, criado_em) VALUES (?, ?, ?)')->execute([$pdiId, $texto, $agora]);
    }

    public function removerCompetencia(int $id, int $pdiId): void
    {
        $this->connection()->prepare('DELETE FROM pdi_competencias WHERE id = ? AND pdi_id = ?')->execute([$id, $pdiId]);
    }

    // ------------------------------------------------------------------ ações (máx. 3, `ordem` 1–3)

    public function acoes(int $pdiId): array
    {
        return $this->todas('SELECT * FROM pdi_acoes WHERE pdi_id = ? ORDER BY ordem', [$pdiId]);
    }

    public function inserirAcao(int $pdiId, array $a, string $agora): void
    {
        $this->connection()->prepare(
            'INSERT INTO pdi_acoes (pdi_id, ordem, descricao, responsavel_tipo, responsavel_usuario_id, responsavel_nome_snapshot, prazo, status, criado_em, atualizado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $pdiId, (int)$a['ordem'], $a['descricao'], $a['responsavel_tipo'], $a['responsavel_usuario_id'] ?? null,
            $a['responsavel_nome_snapshot'] ?? null, $a['prazo'], $a['status'] ?? 'nao_iniciada', $agora, $agora,
        ]);
    }

    public function atualizarAcao(int $pdiId, int $ordem, array $a, string $agora): void
    {
        $this->connection()->prepare(
            'UPDATE pdi_acoes SET descricao = ?, responsavel_tipo = ?, responsavel_usuario_id = ?, responsavel_nome_snapshot = ?, prazo = ?, atualizado_em = ?
             WHERE pdi_id = ? AND ordem = ?'
        )->execute([
            $a['descricao'], $a['responsavel_tipo'], $a['responsavel_usuario_id'] ?? null, $a['responsavel_nome_snapshot'] ?? null,
            $a['prazo'], $agora, $pdiId, $ordem,
        ]);
    }

    public function atualizarStatusAcao(int $pdiId, int $ordem, string $status, string $agora): void
    {
        $this->connection()->prepare('UPDATE pdi_acoes SET status = ?, atualizado_em = ? WHERE pdi_id = ? AND ordem = ?')->execute([$status, $agora, $pdiId, $ordem]);
    }

    public function removerAcao(int $pdiId, int $ordem): void
    {
        $this->connection()->prepare('DELETE FROM pdi_acoes WHERE pdi_id = ? AND ordem = ?')->execute([$pdiId, $ordem]);
    }

    // ------------------------------------------------------------------ acompanhamentos e eventos (APPEND-ONLY)

    public function acompanhamentos(int $pdiId): array
    {
        return $this->todas(
            'SELECT a.id, a.autor_usuario_id, u.nome AS autor_nome, a.autor_papel, a.registrado_em_nome_do_colaborador, a.comentario, a.criado_em
             FROM pdi_acompanhamentos a LEFT JOIN usuarios u ON u.id = a.autor_usuario_id
             WHERE a.pdi_id = ? ORDER BY a.criado_em DESC, a.id DESC',
            [$pdiId]
        );
    }

    public function inserirAcompanhamento(int $pdiId, int $autorUsuarioId, string $autorPapel, bool $emNomeDoColaborador, string $comentario, string $agora): int
    {
        $this->connection()->prepare(
            'INSERT INTO pdi_acompanhamentos (pdi_id, autor_usuario_id, autor_papel, registrado_em_nome_do_colaborador, comentario, criado_em) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pdiId, $autorUsuarioId, $autorPapel, $emNomeDoColaborador ? 1 : 0, $comentario, $agora]);
        return (int)$this->connection()->lastInsertId();
    }

    public function eventos(int $pdiId): array
    {
        return $this->todas(
            'SELECT e.id, e.tipo_evento, e.campo, e.valor_anterior, e.valor_novo, e.ator_usuario_id, u.nome AS ator_nome, e.ator_papel,
                    e.registrado_em_nome_do_colaborador, e.criado_em
             FROM pdi_eventos e LEFT JOIN usuarios u ON u.id = e.ator_usuario_id
             WHERE e.pdi_id = ? ORDER BY e.criado_em DESC, e.id DESC',
            [$pdiId]
        );
    }

    public function inserirEvento(int $pdiId, string $tipo, ?string $campo, ?string $anterior, ?string $novo, int $atorUsuarioId, string $atorPapel, bool $emNomeDoColaborador, ?string $ip, string $agora): void
    {
        $this->connection()->prepare(
            'INSERT INTO pdi_eventos (pdi_id, tipo_evento, campo, valor_anterior, valor_novo, ator_usuario_id, ator_papel, registrado_em_nome_do_colaborador, ip, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$pdiId, $tipo, $campo, $anterior, $novo, $atorUsuarioId, $atorPapel, $emNomeDoColaborador ? 1 : 0, $ip, $agora]);
    }
}
