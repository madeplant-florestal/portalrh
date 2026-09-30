<?php

/**
 * Acesso a dados da Avaliação de Desempenho NATIVA (Etapa 6, 2026-09). Sem regra de negócio: escala,
 * permissão e validação vivem em AvaliacaoDesempenhoService. Contratos vêm EXCLUSIVAMENTE de
 * `colaboradores_metadados` (nunca CPF/codigo_pessoa/`colaboradores` legado — o legado
 * `colaborador_avaliacoes`/`AvaliacaoDesempenho` NÃO é usado como base desta funcionalidade);
 * gestor via `usuarios.gestor_usuario_id` (mesmo padrão de AvaliacaoExperienciaRepository).
 */
class AvaliacaoDesempenhoRepository
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
        return $this->uma('SELECT * FROM avaliacoes_desempenho WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<int,array<string,mixed>> avaliações do contrato, mais recente primeiro. */
    public function buscarPorContrato(int $metadadosId): array
    {
        return $this->todas('SELECT * FROM avaliacoes_desempenho WHERE metadados_id = ? ORDER BY criado_em DESC, id DESC', [$metadadosId]);
    }

    public function inserir(array $dados): int
    {
        $colunas = array_keys($dados);
        $sql = 'INSERT INTO avaliacoes_desempenho (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')';
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
        $this->connection()->prepare('UPDATE avaliacoes_desempenho SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** Substitui o conjunto de critérios da avaliação (delete + insert — a lista é reenviada inteira a cada salvamento). */
    public function salvarCriterios(int $avaliacaoId, array $linhas): void
    {
        $this->connection()->prepare('DELETE FROM avaliacoes_desempenho_criterios WHERE avaliacao_id = ?')->execute([$avaliacaoId]);
        if ($linhas === []) {
            return;
        }
        $stmt = $this->connection()->prepare(
            'INSERT INTO avaliacoes_desempenho_criterios (avaliacao_id, competencia_texto, competencia_id, nota_atual, nota_esperada, comentario)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($linhas as $linha) {
            $stmt->execute([
                $avaliacaoId, $linha['competencia_texto'], $linha['competencia_id'] ?? null,
                $linha['nota_atual'] ?? null, $linha['nota_esperada'] ?? null, $linha['comentario'] ?? null,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> na ordem em que foram salvos. */
    public function buscarCriterios(int $avaliacaoId): array
    {
        return $this->todas('SELECT * FROM avaliacoes_desempenho_criterios WHERE avaliacao_id = ? ORDER BY id ASC', [$avaliacaoId]);
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
     * @param array $f status, ciclo, busca, gestor
     * @param ?int  $escopoGestor não nulo = só avaliações desse gestor
     */
    public function listar(array $f, ?int $escopoGestor, int $limite): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($escopoGestor !== null) {
            $where[] = 'ad.gestor_usuario_id = ?';
            $params[] = $escopoGestor;
        }
        if (!empty($f['status'])) {
            $where[] = 'ad.status = ?';
            $params[] = (string)$f['status'];
        }
        if (!empty($f['busca'])) {
            $where[] = "ad.snap_nome LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$f['busca']) . '%';
        }
        $sql = "SELECT ad.id, ad.metadados_id, ad.snap_nome, ad.snap_empresa, ad.snap_unidade, ad.snap_cargo,
                       ad.gestor_usuario_id, ad.gestor_nome_snapshot, ad.ciclo, ad.periodo_inicio, ad.periodo_fim,
                       ad.status, ad.resultado_final, ad.data_realizacao,
                       (SELECT AVG(cr.nota_atual) FROM avaliacoes_desempenho_criterios cr WHERE cr.avaliacao_id = ad.id) AS media_nota_atual,
                       (SELECT COUNT(*) FROM avaliacoes_desempenho_criterios cr WHERE cr.avaliacao_id = ad.id AND cr.nota_esperada IS NOT NULL AND cr.nota_atual IS NOT NULL AND cr.nota_esperada > cr.nota_atual) AS qtd_criterios_gap
                FROM avaliacoes_desempenho ad
                WHERE " . implode(' AND ', $where) . '
                ORDER BY CASE ad.status WHEN \'rascunho\' THEN 1 WHEN \'concluido\' THEN 2 ELSE 3 END, ad.periodo_fim DESC, ad.id DESC
                LIMIT ' . max(1, $limite);
        return $this->todas($sql, $params);
    }
}
