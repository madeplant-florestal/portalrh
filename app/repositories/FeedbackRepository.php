<?php

/**
 * Acesso a dados do Formulário de Feedback e Desenvolvimento (Etapa 4, 2026-09). Sem regra de
 * negócio: permissão e validação vivem em FeedbackService. Mesmo padrão de
 * AvaliacaoExperienciaRepository/PdiRepository — contrato oficial via `colaboradores_metadados`,
 * gestor via `usuarios.gestor_usuario_id`.
 */
class FeedbackRepository
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
                       cm.admissao, cm.demissao
                FROM colaboradores_metadados cm WHERE cm.id = ? LIMIT 1';
        return $this->uma($sql, [$metadadosId]);
    }

    /** Contratos sem desligamento efetivado (nome contendo o texto), para escolher o colaborador. */
    public function buscarContratos(string $busca, string $hoje, int $limite = 20): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
        $sql = 'SELECT cm.id AS metadados_id, cm.nome, cm.empresa, cm.unidade,
                       COALESCE(' . self::SQL_CARGO_CATALOGO . ', NULLIF(cm.cargo, \'\')) AS cargo
                FROM colaboradores_metadados cm
                WHERE (cm.demissao IS NULL OR cm.demissao > ?) AND cm.nome LIKE ? ESCAPE \'\\\\\'
                ORDER BY cm.nome LIMIT ' . max(1, $limite);
        return $this->todas($sql, [$hoje, $like]);
    }

    public function buscarPorId(int $id): ?array
    {
        return $this->uma('SELECT * FROM feedbacks WHERE id = ? LIMIT 1', [$id]);
    }

    public function inserir(array $dados): int
    {
        $colunas = array_keys($dados);
        $sql = 'INSERT INTO feedbacks (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')';
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
        $this->connection()->prepare('UPDATE feedbacks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** Upsert das 6 linhas de valor cultural (avaliacao + comentario). */
    public function salvarValores(int $feedbackId, array $linhas): void
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO feedback_valores (feedback_id, valor, avaliacao, comentario) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE avaliacao = VALUES(avaliacao), comentario = VALUES(comentario)'
        );
        foreach ($linhas as [$valor, $avaliacao, $comentario]) {
            $stmt->execute([$feedbackId, $valor, $avaliacao, $comentario]);
        }
    }

    /** @return array<string,array{avaliacao:?string,comentario:?string}> */
    public function buscarValores(int $feedbackId): array
    {
        $stmt = $this->connection()->prepare('SELECT valor, avaliacao, comentario FROM feedback_valores WHERE feedback_id = ?');
        $stmt->execute([$feedbackId]);
        $porValor = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porValor[(string)$row['valor']] = ['avaliacao' => $row['avaliacao'], 'comentario' => $row['comentario']];
        }
        return $porValor;
    }

    public function usuarioAtivo(int $id): ?array
    {
        return $this->uma('SELECT id, nome, role FROM usuarios WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1', [$id]);
    }

    public function usuariosAtivos(): array
    {
        return $this->todas('SELECT id, nome, role FROM usuarios WHERE email_verified_at IS NOT NULL ORDER BY nome LIMIT 500');
    }

    /**
     * @param array $f status, tipo, busca, gestor
     * @param ?int  $escopoGestor não nulo = só feedbacks desse gestor
     */
    public function listar(array $f, ?int $escopoGestor, int $limite): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($escopoGestor !== null) {
            $where[] = 'f.gestor_usuario_id = ?';
            $params[] = $escopoGestor;
        }
        if (!empty($f['status'])) {
            $where[] = 'f.status = ?';
            $params[] = (string)$f['status'];
        }
        if (!empty($f['tipo'])) {
            $where[] = 'f.tipo = ?';
            $params[] = (string)$f['tipo'];
        }
        if (!empty($f['busca'])) {
            $where[] = "f.snap_nome LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$f['busca']) . '%';
        }
        $sql = "SELECT f.id, f.metadados_id, f.snap_nome, f.snap_empresa, f.snap_unidade, f.snap_cargo,
                       f.gestor_usuario_id, f.gestor_nome_snapshot, f.tipo, f.data_feedback, f.status,
                       f.resultado_geral, f.espaco_colaborador_preenchido_em, f.concluido_em
                FROM feedbacks f
                WHERE " . implode(' AND ', $where) . '
                ORDER BY CASE f.status WHEN \'rascunho\' THEN 1 WHEN \'concluido\' THEN 2 ELSE 3 END, f.data_feedback DESC, f.id DESC
                LIMIT ' . max(1, $limite);
        return $this->todas($sql, $params);
    }
}
