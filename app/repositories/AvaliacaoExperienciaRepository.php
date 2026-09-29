<?php

/**
 * Acesso a dados da Avaliação do Período de Experiência (45/90 dias — Etapa 4, 2026-09). Sem regra de
 * negócio: derivação de pendências, permissão e validação vivem em AvaliacaoExperienciaService.
 * Contratos vêm EXCLUSIVAMENTE de `colaboradores_metadados` (nunca CPF/codigo_pessoa/`colaboradores`
 * legado); gestor pela relação oficial `usuarios.gestor_usuario_id` (mesmo JOIN de
 * UsuarioGestorRepository::gestorDoUsuarioDoContrato(), aqui em lote — nunca 1 query por linha, §28).
 * `cm.ausente_na_origem = 0` exclui o contrato ÓRFÃO de uma transferência contínua interempresa: o
 * contrato de DESTINO já preserva a `admissao` ORIGINAL (é a própria assinatura de detecção da
 * transferência em MetadadosMovimentacaoService) — nenhum ajuste de data é necessário, só não contar a
 * mesma pessoa duas vezes (§22/§52 da Etapa 4).
 */
class AvaliacaoExperienciaRepository
{
    private const SQL_CARGO_CATALOGO = "(SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci LIMIT 1)";

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

    public function buscarContrato(int $metadadosId): ?array
    {
        $sql = 'SELECT cm.id AS metadados_id, cm.nome, cm.codigo_empresa, cm.empresa, cm.codigo_unidade, cm.unidade,
                       cm.codigo_setor, cm.setor, cm.codigo_cargo,
                       COALESCE(' . self::SQL_CARGO_CATALOGO . ', NULLIF(cm.cargo, \'\')) AS cargo,
                       cm.admissao, cm.demissao, cm.ausente_na_origem
                FROM colaboradores_metadados cm WHERE cm.id = ? LIMIT 1';
        return $this->uma($sql, [$metadadosId]);
    }

    /**
     * Candidatos a pendência 45/90 (um tipo por chamada) — contratos que ainda não foram excluídos por
     * transferência contínua, com o gestor resolvido em LOTE (sem N+1) e a avaliação já existente (se
     * houver) já trazida junto.
     *
     * @param string $tipo '45' ou '90'
     * @param string $cutoffAdmissao contratos com admissao ANTES disso só entram se já tiverem avaliação
     *                                (histórico real preservado; implantação não gera backlog histórico — §54)
     */
    public function candidatosPendencia(string $tipo, string $cutoffAdmissao, array $filtros, ?int $escopoGestor): array
    {
        $where = ['cm.ausente_na_origem = 0', 'cm.admissao IS NOT NULL', '(cm.admissao >= ? OR ae.id IS NOT NULL)'];
        $params = [$tipo, $cutoffAdmissao];

        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'cm.codigo_empresa = ?';
            $params[] = (string)$filtros['codigo_empresa'];
        }
        if (!empty($filtros['codigo_setor'])) {
            $where[] = 'cm.codigo_setor = ?';
            $params[] = (string)$filtros['codigo_setor'];
        }
        if ($escopoGestor !== null) {
            $where[] = 'g.id = ?';
            $params[] = $escopoGestor;
        } elseif (!empty($filtros['gestor_usuario_id'])) {
            $where[] = 'g.id = ?';
            $params[] = (int)$filtros['gestor_usuario_id'];
        }

        $sql = 'SELECT cm.id AS metadados_id, cm.nome, cm.codigo_empresa, cm.empresa, cm.codigo_unidade, cm.unidade,
                       cm.codigo_setor, cm.setor, cm.codigo_cargo,
                       COALESCE(' . self::SQL_CARGO_CATALOGO . ', NULLIF(cm.cargo, \'\')) AS cargo,
                       cm.admissao, cm.demissao,
                       g.id AS gestor_id, g.nome AS gestor_nome,
                       ae.id AS avaliacao_id, ae.status AS avaliacao_status, ae.data_realizacao, ae.parecer
                FROM colaboradores_metadados cm
                LEFT JOIN usuarios u ON u.colaborador_metadados_id = cm.id
                LEFT JOIN usuarios g ON g.id = u.gestor_usuario_id
                LEFT JOIN avaliacoes_experiencia ae ON ae.metadados_id = cm.id AND ae.tipo = ?
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY cm.admissao DESC';
        return $this->todas($sql, $params);
    }

    public function buscarAvaliacao(int $metadadosId, string $tipo): ?array
    {
        return $this->uma('SELECT * FROM avaliacoes_experiencia WHERE metadados_id = ? AND tipo = ? LIMIT 1', [$metadadosId, $tipo]);
    }

    public function buscarPorId(int $id): ?array
    {
        return $this->uma('SELECT * FROM avaliacoes_experiencia WHERE id = ? LIMIT 1', [$id]);
    }

    public function inserir(array $dados): int
    {
        $colunas = array_keys($dados);
        $sql = 'INSERT INTO avaliacoes_experiencia (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')';
        $this->connection()->prepare($sql)->execute(array_values($dados));
        return (int)$this->connection()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = [];
        $params = [];
        foreach ($dados as $coluna => $valor) {
            $sets[] = "{$coluna} = ?";
            $params[] = $valor;
        }
        $params[] = $id;
        $this->connection()->prepare('UPDATE avaliacoes_experiencia SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** Upsert das notas dos 24 critérios (6 valores x 4 critérios) — sempre substitui o conjunto inteiro. */
    public function salvarCriterios(int $avaliacaoId, array $notasPorValorIndice): void
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO avaliacoes_experiencia_criterios (avaliacao_id, valor, criterio_indice, nota) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nota = VALUES(nota)'
        );
        foreach ($notasPorValorIndice as [$valor, $indice, $nota]) {
            $stmt->execute([$avaliacaoId, $valor, $indice, $nota]);
        }
    }

    /** @return array<string,array<int,?int>> [valor => [indice => nota]] */
    public function buscarCriterios(int $avaliacaoId): array
    {
        $stmt = $this->connection()->prepare('SELECT valor, criterio_indice, nota FROM avaliacoes_experiencia_criterios WHERE avaliacao_id = ?');
        $stmt->execute([$avaliacaoId]);
        $porValor = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porValor[(string)$row['valor']][(int)$row['criterio_indice']] = $row['nota'] !== null ? (int)$row['nota'] : null;
        }
        return $porValor;
    }

    public function usuarioAtivo(int $id): ?array
    {
        return $this->uma('SELECT id, nome, role FROM usuarios WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1', [$id]);
    }

    public function opcoesFiltro(): array
    {
        $empresas = $this->todas(
            "SELECT codigo_empresa, MAX(empresa) AS empresa FROM colaboradores_metadados
             WHERE codigo_empresa IS NOT NULL AND codigo_empresa <> '' GROUP BY codigo_empresa ORDER BY empresa"
        );
        $setores = $this->todas(
            "SELECT codigo_setor, MAX(setor) AS setor FROM colaboradores_metadados
             WHERE codigo_setor IS NOT NULL AND codigo_setor <> '' GROUP BY codigo_setor ORDER BY setor"
        );
        return ['empresas' => $empresas, 'setores' => $setores];
    }
}
